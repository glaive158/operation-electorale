<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\DeclarationPiece;
use App\Models\Logistique\PieceJointe;
use App\Models\Logistique\RestitutionDeclarationPiece;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signatures et photos envoyées par les tablettes. Une pièce est adressée par l'empreinte SHA-256 de ses
 * octets : l'envoi est reprenable et dédoublonné, et le chemin ne dépend d'aucune donnée de l'expéditeur.
 * Le type est lu dans le fichier lui-même (jamais dans son nom ni dans l'en-tête de la requête).
 *
 * Les pièces ne vivent jamais sur un disque public : elles ne sont servies que par les contrôleurs de
 * pièces, après contrôle des droits (voir visiblePar et reponse).
 */
class PieceJointeService
{
    public const TAILLE_MAX = 8 * 1024 * 1024;

    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function __construct(private readonly PerimetreTerrain $perimetre) {}

    /**
     * Range l'image reçue et rattache les pièces de déclaration qui l'attendaient.
     *
     * @return array{piece: PieceJointe, creee: bool} `creee` est faux quand la même image était déjà stockée
     */
    public function enregistrer(UploadedFile $fichier, string $empreinteAnnoncee): array
    {
        $auteur = auth()->user();
        if ($auteur === null) {
            throw new LogicException('Opération sans auteur.');
        }
        if (! Gate::forUser($auteur)->allows('logistique-terrain')) {
            throw new RegleLogistique('Envoi de pièce hors de votre périmètre.');
        }
        if (preg_match('/^[0-9a-f]{64}$/', $empreinteAnnoncee) !== 1) {
            throw new InvalidArgumentException("L'empreinte doit être un SHA-256 hexadécimal en minuscules.");
        }

        $chemin = $fichier->getRealPath();
        if ($chemin === false || ! is_file($chemin)) {
            throw new InvalidArgumentException('Fichier illisible.');
        }
        $taille = (int) filesize($chemin);
        if ($taille === 0) {
            throw new InvalidArgumentException('Le fichier est vide.');
        }
        if ($taille > self::TAILLE_MAX) {
            throw new InvalidArgumentException('Le fichier doit peser 8 Mo au plus.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($chemin);
        if ($mime === false || ! isset(self::EXTENSIONS[$mime])) {
            throw new InvalidArgumentException('Le fichier doit être une image JPEG ou PNG.');
        }
        $empreinte = hash_file('sha256', $chemin);
        if ($empreinte === false || ! hash_equals($empreinte, $empreinteAnnoncee)) {
            throw new InvalidArgumentException("L'empreinte annoncée ne correspond pas au fichier reçu.");
        }

        $existante = PieceJointe::where('empreinte', $empreinte)->first();
        if ($existante !== null) {
            // Les mêmes octets, envoyés par un autre compte : il prouve les posséder, sans les redéposer.
            $this->enregistrerPossession($existante, $auteur);
            $this->rattacherEnAttente([$empreinte]);

            return ['piece' => $existante, 'creee' => false];
        }

        $rangement = 'pieces/'.substr($empreinte, 0, 2).'/'.$empreinte.'.'.self::EXTENSIONS[$mime];
        $flux = fopen($chemin, 'rb');
        if ($flux === false) {
            throw new InvalidArgumentException('Fichier illisible.');
        }
        try {
            // Adressé par son contenu : réécrire un fichier déjà présent (reprise après incident) ne change rien.
            $ecrit = Storage::disk($this->disque())->put($rangement, $flux);
        } finally {
            fclose($flux);
        }
        if ($ecrit === false) {
            throw new LogicException("Écriture de la pièce impossible sur le disque « {$this->disque()} ».");
        }

        try {
            $piece = DB::transaction(function () use ($empreinte, $rangement, $taille, $mime, $auteur) {
                $piece = PieceJointe::create([
                    'empreinte' => $empreinte, 'chemin' => $rangement, 'taille' => $taille, 'type_mime' => $mime, 'user_id' => $auteur->id,
                ]);
                $this->enregistrerPossession($piece, $auteur);
                $this->rattacherEnAttente([$empreinte]);
                Journal::noter('piece_jointe', $piece->id, 'envoi_piece', null, [
                    'empreinte' => $empreinte, 'taille' => $taille, 'type_mime' => $mime,
                ]);

                return $piece;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            // Envoi simultané de la même image : l'autre a gagné, le fichier est identique.
            $piece = PieceJointe::where('empreinte', $empreinte)->firstOrFail();
            $this->enregistrerPossession($piece, $auteur);
            $this->rattacherEnAttente([$empreinte]);

            return ['piece' => $piece, 'creee' => false];
        }

        return ['piece' => $piece, 'creee' => true];
    }

    /**
     * Rattache à leur fichier les pièces de déclaration (de réception ou de restitution) qui l'attendaient (les
     * empreintes données, ou toutes) : seulement si l'auteur de la déclaration a lui-même envoyé ces octets (ou
     * prouvé les posséder).
     *
     * @param  list<string>|null  $empreintes
     */
    public function rattacherEnAttente(?array $empreintes = null): void
    {
        foreach ([DeclarationPiece::class, RestitutionDeclarationPiece::class] as $modele) {
            $attendues = $modele::whereNull('piece_jointe_id')
                ->when($empreintes !== null, fn ($q) => $q->whereIn('empreinte', $empreintes))
                ->with('declaration:id,user_id')->get();
            foreach ($attendues as $attendue) {
                $id = DB::table('pieces_jointes')
                    ->join('piece_possessions', 'piece_possessions.piece_jointe_id', '=', 'pieces_jointes.id')
                    ->where('pieces_jointes.empreinte', $attendue->empreinte)
                    ->where('piece_possessions.user_id', $attendue->declaration->user_id)
                    ->value('pieces_jointes.id');
                if ($id !== null) {
                    $modele::whereKey($attendue->id)->whereNull('piece_jointe_id')->update(['piece_jointe_id' => $id]);
                }
            }
        }
    }

    /** L'utilisateur a envoyé ces octets, ou prouvé les posséder en les renvoyant. */
    public function enregistrerPossession(PieceJointe $piece, User $utilisateur): void
    {
        DB::table('piece_possessions')->insertOrIgnore([
            'piece_jointe_id' => $piece->id, 'user_id' => $utilisateur->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** La pièce de cette empreinte, si l'utilisateur en a envoyé (ou prouvé posséder) les octets. */
    public function pieceDe(User $utilisateur, string $empreinte): ?PieceJointe
    {
        return PieceJointe::where('empreinte', $empreinte)
            ->whereIn('id', DB::table('piece_possessions')->where('user_id', $utilisateur->id)->select('piece_jointe_id'))
            ->first();
    }

    public function possede(User $utilisateur, string $empreinte): bool
    {
        return $this->pieceDe($utilisateur, $empreinte) !== null;
    }

    /** La pièce du gestionnaire central : toute pièce stockée. */
    public function trouver(string $empreinte): ?PieceJointe
    {
        return PieceJointe::where('empreinte', $empreinte)->first();
    }

    /**
     * La pièce d'une déclaration d'une décharge que l'utilisateur de terrain a le droit de voir
     * (son périmètre, ou ses affectations pour un agent), ou d'une déclaration de restitution qu'il peut voir
     * (restitutionVisible) ; null sinon, y compris pour une pièce orpheline.
     */
    public function visiblePar(User $utilisateur, string $empreinte): ?PieceJointe
    {
        $piece = $this->trouver($empreinte);
        if ($piece === null) {
            return null;
        }

        $visible = DeclarationPiece::where('piece_jointe_id', $piece->id)
            ->whereHas('declaration', fn ($q) => $q->whereIn('decharge_id', $this->perimetre->decharges($utilisateur)->select('decharges.id')))
            ->exists();

        return $visible || $this->restitutionVisible($utilisateur, $piece) ? $piece : null;
    }

    /**
     * La pièce porte une déclaration de restitution que l'utilisateur peut voir : celle de son propre retour
     * (destinataire de son périmètre, ou l'agent détenteur lui-même), celle dont il est l'agent de retour désigné,
     * ou n'importe laquelle pour le gestionnaire central.
     */
    private function restitutionVisible(User $utilisateur, PieceJointe $piece): bool
    {
        $pieces = RestitutionDeclarationPiece::where('piece_jointe_id', $piece->id);
        if (Gate::forUser($utilisateur)->allows('logistique-gerer')) {
            return $pieces->exists();
        }
        if (! $utilisateur->actif) {
            return false;
        }

        $destinataires = $this->perimetre->destinataireIds($utilisateur)->all();

        return $pieces->whereHas('declaration.restitution', fn ($q) => $q
            ->where('agent_id', $utilisateur->id)
            ->orWhere(fn ($q) => $q->where('detenteur_type', 'agent')->where('detenteur_id', $utilisateur->id))
            ->orWhere(fn ($q) => $q->where('detenteur_type', 'destinataire')->whereIn('detenteur_id', $destinataires))
        )->exists();
    }

    /** Sert le fichier, avec le type enregistré et sans que le navigateur puisse en deviner un autre. */
    public function reponse(PieceJointe $piece): Response
    {
        $disque = Storage::disk($this->disque());
        abort_unless($disque->exists($piece->chemin), 404);

        return $disque->response($piece->chemin, $piece->empreinte.'.'.self::EXTENSIONS[$piece->type_mime], [
            'Content-Type' => $piece->type_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    private function disque(): string
    {
        return (string) config('logistique.disque_pieces', 'local');
    }
}

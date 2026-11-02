<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\BesoinRemplacement;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\DechargeDocument;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Reclamation;
use App\Models\Logistique\Remise;
use App\Models\NotificationOp;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

/**
 * Avis de réception et contestation. Après une déclaration de tablette, le signataire est averti dans son compte ;
 * si ce n'est pas lui, il conteste : la déclaration repart à l'arbitrage (sauf si sa réception a déjà servi en
 * aval, auquel cas elle garde son statut et seuls le drapeau et l'alerte des responsables sont posés). Les
 * responsables lèvent la contestation par ArbitrageService::leverContestation.
 *
 * Ordre des verrous : cycle, décharge, remise, déclaration.
 */
class AvisReceptionService
{
    private const MAX_TEXTE = 500;

    private const SIGNATAIRE_SEUL = 'Seul le signataire de la réception peut la contester.';

    private const NON_CONTESTABLE = 'Cette réception ne peut plus être contestée.';

    public function __construct(private readonly DeclarationService $declarations) {}

    /**
     * Avis au signataire d'une nouvelle déclaration de tablette. L'appelant n'en envoie qu'un par déclaration
     * créée (jamais sur un rejeu), après validation de la transaction qui l'a enregistrée.
     */
    public function envoyer(Declaration $declaration): void
    {
        if ($declaration->signataire_user_id === null) {
            return;
        }

        $numero = Decharge::whereKey($declaration->decharge_id)->value('numero');
        $instant = $declaration->horodatage_terminal ?? $declaration->created_at;
        $quand = $instant instanceof CarbonInterface
            ? $instant->copy()->setTimezone((string) config('app.timezone'))->format('d/m/Y \à H:i')
            : '';
        $articles = $declaration->lignes()->count();

        NotificationOp::create([
            'user_id' => $declaration->signataire_user_id,
            'titre' => 'Réception confirmée',
            'message' => "Vous avez confirmé la réception de la décharge n° {$numero} le {$quand} ({$articles} articles). Si ce n'est pas vous, contestez.",
            'type' => 'logistique_reception',
        ]);
    }

    /**
     * Le signataire déclare que la réception n'est pas de lui. Possible tant que la déclaration n'est ni
     * contestée, ni écartée, ni remplacée, y compris sur un cycle clos.
     */
    public function contester(Declaration $declaration, string $motif): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Contestation sans auteur.');
        }
        $auteur = auth()->user();
        if (! Gate::allows('logistique-terrain')) {
            throw new RegleLogistique(self::SIGNATAIRE_SEUL);
        }
        $motif = trim($motif);
        if ($motif === '' || mb_strlen($motif) > self::MAX_TEXTE) {
            throw new InvalidArgumentException('Le motif est obligatoire (500 caractères au plus).');
        }

        $cycleId = Decharge::whereKey($declaration->decharge_id)->value('cycle_id');
        if ($cycleId === null) {
            throw new LogicException('Déclaration sans décharge : invariant rompu.');
        }

        DB::transaction(function () use ($declaration, $motif, $cycleId, $auteur) {
            // Pas de refus de cycle clos : la contestation ne touche à aucun stock et arrive souvent après l'élection.
            if (Cycle::whereKey($cycleId)->lockForUpdate()->first() === null) {
                throw new LogicException('Cycle introuvable : invariant rompu.');
            }
            $decharge = Decharge::whereKey($declaration->decharge_id)->lockForUpdate()->firstOrFail();
            $remise = Remise::whereKey($declaration->remise_id)->lockForUpdate()->firstOrFail();
            $actuelle = Declaration::whereKey($declaration->id)->lockForUpdate()->firstOrFail();

            if ($actuelle->signataire_user_id === null || (int) $actuelle->signataire_user_id !== (int) $auteur->id) {
                throw new RegleLogistique(self::SIGNATAIRE_SEUL);
            }
            if ($actuelle->contestation !== 'aucune' || ! $actuelle->estActive()) {
                throw new RegleLogistique(self::NON_CONTESTABLE);
            }

            $avant = ['contestation' => $actuelle->contestation, 'statut' => $actuelle->statut];
            $utilisee = in_array($actuelle->statut, ['retenue', 'concordante'], true) && $this->receptionUtilisee($remise, $decharge);

            $actuelle->forceFill(['contestation' => 'contestee']);
            if (! $utilisee) {
                $actuelle->statut = 'a_verifier';
            }
            $actuelle->save();
            if ($actuelle->statut !== $avant['statut']) {
                Journal::noter('declaration', $actuelle->id, 'statut_declaration', ['statut' => $avant['statut']], [
                    'statut' => $actuelle->statut, 'remise_id' => $remise->id, 'anomalies' => $actuelle->anomalies ?? [],
                ], $motif);
            }

            if (! $utilisee && $remise->statut !== 'a_verifier') {
                $statutAvant = $remise->statut;
                $remise->forceFill(['statut' => 'a_verifier'])->save();
                Journal::noter('remise', $remise->id, 'statut_remise', ['statut' => $statutAvant], ['statut' => 'a_verifier']);
            }

            Journal::noter('declaration', $actuelle->id, 'contestation_reception', $avant, [
                'contestation' => 'contestee', 'statut' => $actuelle->statut,
                'remise_id' => $remise->id, 'decharge_id' => $decharge->id, 'reception_utilisee' => $utilisee,
            ], $motif);

            if (! $utilisee) {
                $this->declarations->apresRetenue($remise->refresh());
            }

            $this->alerterLesResponsables($actuelle, $decharge, $auteur, $motif, $utilisee);
        }, attempts: 3);

        $declaration->refresh();
    }

    /**
     * Une réception est utilisée en aval quand :
     * - une redistribution (ni annulée ni à arbitrer) est partie de la décharge (réception d'une livraison au préfet) ;
     * - l'exemplaire de réception de la décharge a été émis ;
     * - la décharge est un remplacement dont la réception a déjà résolu la réclamation d'origine.
     * Ce qui a été remis plus loin ou clos ne se défait pas d'un geste : le responsable tranche. Seule
     * implémentation de la règle, partagée avec l'arbitrage ; à lire sous les verrous cycle, décharge, remise.
     */
    public function receptionUtilisee(Remise $remise, Decharge $decharge): bool
    {
        // Une redistribution ne se prépare que sur une livraison retenue : toute redistribution non annulée en dépend.
        if ($remise->etape === 'livraison' && Remise::where('decharge_id', $decharge->id)->where('etape', 'redistribution')
            ->whereNotIn('statut', ['annulee', 'a_arbitrer'])->exists()) {
            return true;
        }

        if (DechargeDocument::where('decharge_id', $decharge->id)->where('exemplaire', 'reception')->exists()) {
            return true;
        }

        if ($decharge->besoin_remplacement_id !== null) {
            $reclamationId = BesoinRemplacement::whereKey($decharge->besoin_remplacement_id)->value('reclamation_id');

            return $reclamationId !== null && Reclamation::whereKey($reclamationId)->where('statut', 'resolue')->exists();
        }

        return false;
    }

    private function alerterLesResponsables(Declaration $declaration, Decharge $decharge, User $auteur, string $motif, bool $utilisee): void
    {
        $nom = trim(($auteur->prenom ?? '').' '.$auteur->name);
        $message = "{$nom} conteste la réception de la décharge n° {$decharge->numero} (déclaration n° {$declaration->id}). Motif : {$motif}";
        $message .= $utilisee
            ? ' La réception est déjà utilisée en aval (redistribution, exemplaire de réception ou remplacement résolu) : elle reste '
                .mb_strtolower(Declaration::STATUTS[$declaration->statut]).' en attendant votre décision.'
            : ' La réception est à vérifier.';

        foreach (User::where('role', 'logistique_responsable')->where('actif', true)->get(['id']) as $responsable) {
            NotificationOp::create([
                'user_id' => $responsable->id,
                'titre' => 'Réception contestée',
                'message' => $message,
                'type' => 'logistique_contestation',
            ]);
        }
    }
}

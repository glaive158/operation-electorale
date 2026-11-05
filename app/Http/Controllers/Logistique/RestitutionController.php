<?php

namespace App\Http\Controllers\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Http\Controllers\Controller;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Restitution;
use App\Models\Logistique\RestitutionDeclaration;
use App\Models\Logistique\RestitutionDocument;
use App\Models\Logistique\RestitutionLigne;
use App\Models\User;
use App\Services\Logistique\RestitutionPdfService;
use App\Services\Logistique\RestitutionService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Restitution du matériel réutilisable au central : ouverture par cycle, liste et écarts, page d'une restitution
 * (agent de retour, déclarations et arbitrage, réception, contrôle, décision de réforme, justification) et fiches PDF.
 */
class RestitutionController extends Controller
{
    use ExecuteAction;

    private const PAR_PAGE = 50;

    public function __construct(
        private readonly RestitutionService $restitutions,
        private readonly RestitutionPdfService $pdf,
    ) {}

    public function index(Request $request, Cycle $cycle)
    {
        $statut = $request->query('statut');
        $statut = is_string($statut) && array_key_exists($statut, Restitution::STATUTS) ? $statut : null;
        $detenteur = $request->query('detenteur');
        $detenteur = is_string($detenteur) && array_key_exists($detenteur, Restitution::DETENTEURS) ? $detenteur : null;

        $restitutions = Restitution::where('cycle_id', $cycle->id)
            ->when($statut, fn ($q) => $q->where('statut', $statut))
            ->when($detenteur, fn ($q) => $q->where('detenteur_type', $detenteur))
            ->with('agent')->withCount('lignes')
            ->withExists(['declarations as a_verifier' => fn ($q) => $q->where('statut', 'a_verifier')])
            ->orderBy('id')->paginate(self::PAR_PAGE)->withQueryString();

        // Par défaut, seules les lignes à traiter : un écart non nul, ou une justification déjà écrite (P6-R45).
        $toutesLesLignes = $request->query('ecarts') === 'tous';
        $ecarts = $this->restitutions->ecarts($cycle)
            ->when($detenteur, fn (Collection $c) => $c->where('detenteur_type', $detenteur)->values())
            ->when(! $toutesLesLignes, fn (Collection $c) => $c->filter(fn (array $e) => ($e['non_restitue'] ?? 0) > 0
                || ($e['perdu_en_retour'] ?? 0) > 0 || $e['justification'] !== null)->values());

        return view('logistique.restitutions.index', [
            'cycle' => $cycle,
            'restitutions' => $restitutions,
            'noms' => $this->nomsDetenteurs($restitutions->getCollection()),
            'ecarts' => $ecarts,
            'toutesLesLignes' => $toutesLesLignes,
            'statut' => $statut,
            'detenteur' => $detenteur,
        ]);
    }

    public function ouvrir(Cycle $cycle)
    {
        return $this->executer(
            fn () => $this->restitutions->ouvrir($cycle),
            fn (array $r) => "{$r['creees']} restitution(s) ouverte(s), {$r['inchangees']} inchangée(s).",
        );
    }

    /** Remet les attendus au solde courant du détenteur, tant que rien n'est déclaré (P6-R41). */
    public function recalculer(Restitution $restitution)
    {
        return $this->executer(fn () => $this->restitutions->recalculer($restitution), 'Attendus recalculés.');
    }

    public function show(Restitution $restitution)
    {
        $restitution->load([
            'cycle', 'agent', 'ouvertePar', 'documents',
            'lignes.materiel',
            'declarations' => fn ($q) => $q->orderBy('id'),
            'declarations.lignes.materiel', 'declarations.pieces', 'declarations.user', 'declarations.signataireUser', 'declarations.delegation.autorite',
        ]);
        $lignes = $restitution->lignes->sortBy(fn (RestitutionLigne $l) => [$l->materiel?->ordre, $l->materiel_id])->values();
        $declarations = $restitution->declarations;
        $retenue = $declarations->firstWhere('statut', 'retenue');
        $aVerifier = $declarations->where('statut', 'a_verifier')->values();
        $controlee = $lignes->contains(fn (RestitutionLigne $l) => $l->bon + $l->a_reparer + $l->reforme > 0);

        return view('logistique.restitutions.show', [
            'cycle' => $restitution->cycle,
            'restitution' => $restitution,
            'detenteur' => $this->nomsDetenteurs(collect([$restitution]))[$restitution->id] ?? '',
            'lignes' => $lignes,
            'declarations' => $declarations,
            'retenue' => $retenue,
            'aVerifier' => $aVerifier,
            'administrer' => Gate::allows('logistique-administrer'),
            // Les attendus ne bougent plus sous une déclaration active (même à vérifier).
            'peutRecalculer' => Gate::allows('logistique-administrer') && $restitution->statut === 'a_preparer'
                && ! $declarations->contains(fn (RestitutionDeclaration $d) => ! in_array($d->statut, ['ecartee', 'remplacee'], true)),
            'agents' => $restitution->statut === 'a_preparer'
                ? User::where('role', 'agent_transport')->where('actif', true)->orderBy('name')->orderBy('prenom')->get()
                : collect(),
            // Reçue au central, la réception se corrige tant que le contrôle n'a rien réparti (P6-R42).
            'peutReceptionner' => $restitution->statut === 'en_retour' || ($restitution->statut === 'recue_central' && ! $controlee),
            'correction' => $restitution->statut === 'recue_central',
            'peutControler' => $restitution->statut === 'recue_central' && ! $controlee && $aVerifier->isEmpty(),
            'fiches' => [
                'declaration' => $retenue !== null && in_array($restitution->statut, ['en_retour', 'recue_central', 'controlee'], true),
                'controle' => $retenue !== null && $restitution->statut === 'controlee',
            ],
        ]);
    }

    public function affecterAgent(Request $request, Restitution $restitution)
    {
        $donnees = $request->validate(['agent_id' => 'required|integer|exists:users,id']);

        return $this->executer(
            fn () => $this->restitutions->affecterAgent($restitution, User::findOrFail($donnees['agent_id'])),
            'Agent de retour désigné.',
        );
    }

    public function receptionner(Request $request, Restitution $restitution)
    {
        $donnees = $request->validate([
            'recus' => 'required|array|min:1',
            'recus.*' => 'required|integer|min:0|max:10000000',
        ]);

        return $this->executer(fn () => $this->restitutions->receptionner($restitution, $donnees['recus']), 'Retour réceptionné au central.');
    }

    public function controler(Request $request, Restitution $restitution)
    {
        $donnees = $request->validate([
            'controle' => 'required|array|min:1',
            'controle.*.bon' => 'required|integer|min:0|max:10000000',
            'controle.*.a_reparer' => 'required|integer|min:0|max:10000000',
            'controle.*.reforme' => 'required|integer|min:0|max:10000000',
        ]);

        return $this->executer(fn () => $this->restitutions->controler($restitution, $donnees['controle']), 'Retour contrôlé.');
    }

    public function reforme(Request $request, RestitutionLigne $ligne)
    {
        $donnees = $request->validate(['motif' => 'required|string|max:500']);

        return $this->executer(fn () => $this->restitutions->deciderReforme($ligne, $donnees['motif']), 'Réforme décidée.');
    }

    public function justifier(Request $request, RestitutionLigne $ligne)
    {
        $donnees = $request->validate(['justification' => 'required|string|max:500']);

        return $this->executer(fn () => $this->restitutions->justifier($ligne, $donnees['justification']), 'Écart justifié.');
    }

    /** Arbitrage du responsable : retenir une déclaration à vérifier, ou confirmer la retenue en cours (P6-R28). */
    public function retenir(Request $request, RestitutionDeclaration $declaration)
    {
        $donnees = $request->validate(['motif' => 'required|string|max:500']);

        return $this->executer(fn () => $this->restitutions->retenirDeclaration($declaration, $donnees['motif']), 'Déclaration retenue.');
    }

    /**
     * Fiche de restitution : émise et archivée à la première demande (une fois émissible), puis toujours servie
     * depuis l'archive. Aucun aperçu hors de cette route, réservée au personnel logistique (P6-R34).
     */
    public function pdf(Restitution $restitution, string $exemplaire)
    {
        abort_unless(in_array($exemplaire, RestitutionDocument::EXEMPLAIRES, true), 404);

        try {
            $octets = $this->pdf->contenu($this->pdf->emettre($restitution, $exemplaire));
        } catch (RegleLogistique|InvalidArgumentException $e) {
            return redirect()->route('logistique.restitutions.show', $restitution)->with('error', $e->getMessage());
        }

        return response($octets, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="restitution-'.$restitution->id.'-'.$exemplaire.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Nom du détenteur par restitution, en deux requêtes au plus.
     *
     * @param  Collection<int, Restitution>  $restitutions
     * @return array<int, string>
     */
    private function nomsDetenteurs(Collection $restitutions): array
    {
        $agents = User::whereIn('id', $restitutions->where('detenteur_type', 'agent')->pluck('detenteur_id')->unique()->all())->get()->keyBy('id');
        $destinataires = Destinataire::whereIn('id', $restitutions->where('detenteur_type', 'destinataire')->pluck('detenteur_id')->unique()->all())->pluck('libelle', 'id');

        return $restitutions->mapWithKeys(fn (Restitution $r) => [$r->id => $r->detenteur_type === 'agent'
            ? ($agents->get($r->detenteur_id)?->nom_complet ?: 'Agent')
            : (string) $destinataires->get($r->detenteur_id, 'Destinataire')])->all();
    }
}

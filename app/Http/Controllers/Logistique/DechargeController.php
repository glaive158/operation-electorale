<?php

namespace App\Http\Controllers\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Http\Controllers\Controller;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\DechargeDocument;
use App\Models\Logistique\DechargeLigne;
use App\Models\Logistique\Destinataire;
use App\Services\Logistique\CircuitService;
use App\Services\Logistique\DechargePdfService;
use App\Services\Logistique\DechargeService;
use App\Services\Logistique\EngagementService;
use App\Services\Logistique\LectureRecensement;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

class DechargeController extends Controller
{
    use ExecuteAction;

    public function __construct(
        private readonly DechargeService $decharges,
        private readonly DechargePdfService $pdf,
        private readonly EngagementService $engagements,
        private readonly CircuitService $circuits,
        private readonly LectureRecensement $recensement,
    ) {
    }

    public function index(Request $request, Cycle $cycle)
    {
        $filtre = fn (string $nom, array $valides) => is_string($request->query($nom)) && in_array($request->query($nom), $valides, true)
            ? $request->query($nom) : null;
        $statut = $filtre('statut', ['brouillon', 'validee', 'annulee']);
        $categorie = $filtre('categorie', array_keys(Decharge::CATEGORIES));
        $nature = $filtre('nature', array_keys(Decharge::NATURES));
        $departement = $request->integer('departement');
        $destinataire = $request->integer('destinataire');

        $decharges = Decharge::with('destinataire')->where('cycle_id', $cycle->id)
            ->when($statut, fn ($q) => $q->where('statut_document', $statut))
            ->when($categorie, fn ($q) => $q->where('categorie', $categorie))
            ->when($nature, fn ($q) => $q->where('nature', $nature))
            ->when($destinataire > 0, fn ($q) => $q->where('destinataire_id', $destinataire))
            ->when($departement > 0, fn ($q) => $q->whereIn(
                'destinataire_id',
                $this->decharges->destinatairesDuPerimetre(['departement_id' => $departement], true)->modelKeys(),
            ))
            ->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return view('logistique.decharges.index', [
            'cycle' => $cycle,
            'decharges' => $decharges,
            'statut' => $statut,
            'categorie' => $categorie,
            'nature' => $nature,
            'departement' => $departement,
            'destinataire' => $destinataire,
            'destinataires' => Destinataire::whereIn('id', Decharge::where('cycle_id', $cycle->id)->select('destinataire_id'))
                ->orderBy('libelle')->get(['id', 'libelle']),
            'departements' => $this->circuits->departements($cycle),
            'regions' => $this->recensement->departements()->unique('region_id')->sortBy('region_nom')->values(),
        ]);
    }

    public function show(Decharge $decharge)
    {
        $decharge->load([
            'cycle', 'destinataire', 'remisA', 'plan', 'documents', 'remplacante', 'remplacee',
            'lignes' => fn ($q) => $q->orderBy('materiel_id'),
            'lignes.materiel',
        ]);

        // Un brouillon n'a pas encore figé son engagé : on montre l'état courant du destinataire. Le besoin
        // (dotation, remplacements et clôtures) se lit toujours sur l'état courant.
        $etat = $this->engagements->etat($decharge->cycle, $decharge->destinataire, $decharge->categorie);

        return view('logistique.decharges.show', [
            'decharge' => $decharge,
            'cycle' => $decharge->cycle,
            'etat' => $etat,
        ]);
    }

    /** Cible du QR code imprimé sur le document : retrouve la décharge par son identifiant stable. */
    public function qr(string $identifiant)
    {
        $decharge = Decharge::where('identifiant', $identifiant)->firstOrFail();

        return redirect()->route('logistique.decharges.show', $decharge);
    }

    public function store(Request $request, Cycle $cycle)
    {
        $donnees = $request->validate([
            'destinataire_id' => 'required|integer|exists:destinataires,id',
            'categorie' => ['required', Rule::in(array_keys(Decharge::CATEGORIES))],
        ]);

        return $this->executerVers(
            fn () => $this->decharges->preparer($cycle, Destinataire::findOrFail($donnees['destinataire_id']), $donnees['categorie']),
            fn (Decharge $decharge) => route('logistique.decharges.show', $decharge),
            'Brouillon de décharge préparé.',
        );
    }

    public function lot(Request $request, Cycle $cycle)
    {
        $donnees = $request->validate([
            'categorie' => ['required', Rule::in(array_keys(Decharge::CATEGORIES))],
            'filtre.departement_id' => 'nullable|integer',
            'filtre.region_id' => 'nullable|integer',
            'filtre.etranger' => 'nullable|boolean',
        ]);

        return $this->executer(
            fn () => $this->decharges->preparerLot($cycle, $donnees['categorie'], $this->filtreDe($donnees['filtre'] ?? [])),
            fn (array $r) => "{$r['creees']} brouillon(s) préparé(s), {$r['ignorees']} destinataire(s) ignoré(s) (brouillon existant ou rien à expédier).",
        );
    }

    public function modifierLigne(Request $request, DechargeLigne $ligne)
    {
        $donnees = $request->validate([
            'quantite' => 'required|integer|min:0|max:10000000',
            'observations' => 'nullable|string|max:500',
        ]);

        return $this->executer(
            fn () => $this->decharges->modifierLigne($ligne, (int) $donnees['quantite'], $donnees['observations'] ?? null),
            'Ligne mise à jour.',
        );
    }

    public function actualiser(Decharge $decharge)
    {
        return $this->executer(fn () => $this->decharges->actualiser($decharge), 'Brouillon actualisé d\'après le plan en vigueur.');
    }

    public function destroy(Request $request, Decharge $decharge)
    {
        $donnees = $request->validate(['motif' => 'required|string|max:500']);
        $cycle = $decharge->cycle_id;

        return $this->executerVers(
            fn () => $this->decharges->supprimerBrouillon($decharge, $donnees['motif']),
            fn () => route('logistique.decharges.index', $cycle),
            'Brouillon supprimé.',
        );
    }

    /** La validation fait foi : si l'émission du PDF échoue ensuite, elle reste acquise et se refait depuis la fiche. */
    public function valider(Decharge $decharge)
    {
        return $this->validerEtEmettre(
            fn () => $this->decharges->valider($decharge), $decharge, 'initial', 'Décharge validée.',
        );
    }

    public function annuler(Request $request, Decharge $decharge)
    {
        $donnees = $request->validate([
            'motif' => 'required|string|max:500',
            'vers_stock_commun' => 'nullable|boolean',
        ]);

        return $this->validerEtEmettre(
            fn () => $this->decharges->annuler($decharge, $donnees['motif'], $request->boolean('vers_stock_commun')),
            $decharge, 'annulation', 'Décharge annulée : le matériel réservé est libéré.',
        );
    }

    public function remplacer(Decharge $decharge)
    {
        return $this->executerVers(
            fn () => $this->decharges->remplacer($decharge),
            fn (Decharge $remplacante) => route('logistique.decharges.show', $remplacante),
            'Brouillon de remplacement préparé.',
        );
    }

    public function emettre(Request $request, Decharge $decharge)
    {
        $donnees = $request->validate(['exemplaire' => ['required', Rule::in(DechargeDocument::EXEMPLAIRES)]]);

        return $this->executer(fn () => $this->pdf->emettre($decharge, $donnees['exemplaire']), 'PDF émis et archivé.');
    }

    /** Aperçu du brouillon, en ligne, filigrané : ce n'est pas un exemplaire officiel. */
    public function apercu(Decharge $decharge)
    {
        if (! $decharge->estBrouillon()) {
            return back()->with('error', 'Seul un brouillon a un aperçu : le document officiel se télécharge depuis la fiche.');
        }

        try {
            $octets = $this->pdf->pdf($this->pdf->html($decharge, 'initial', apercu: true));
        } catch (RegleLogistique|InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('logistique.decharges.show', $decharge)
                ->with('error', 'Aperçu impossible : '.$e->getMessage());
        }

        return $this->reponsePdf($octets, "apercu-{$decharge->id}.pdf");
    }

    /** Exemplaire archivé, servi après contrôle de son empreinte. */
    public function pdf(Decharge $decharge, string $exemplaire)
    {
        abort_unless(in_array($exemplaire, DechargeDocument::EXEMPLAIRES, true), 404);
        $document = DechargeDocument::where('decharge_id', $decharge->id)->where('exemplaire', $exemplaire)->firstOrFail();

        try {
            $octets = $this->pdf->contenu($document);
        } catch (RegleLogistique $e) {
            return redirect()->route('logistique.decharges.show', $decharge)->with('error', $e->getMessage());
        }

        return $this->reponsePdf($octets, "{$decharge->numero}-{$exemplaire}.pdf");
    }

    public function lotPdf(Request $request, Cycle $cycle)
    {
        $donnees = $request->validate([
            'filtre.departement_id' => 'nullable|integer',
            'filtre.region_id' => 'nullable|integer',
            'filtre.etranger' => 'nullable|boolean',
        ]);

        try {
            $chemin = $this->pdf->lot($cycle, $this->filtreDe($donnees['filtre'] ?? []));
        } catch (RegleLogistique|InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return response()->download($chemin, "decharges-cycle-{$cycle->id}.zip")->deleteFileAfterSend();
    }

    /** @param  array<string, mixed>  $filtre */
    private function filtreDe(array $filtre): array
    {
        $filtre['etranger'] = ($filtre['etranger'] ?? null) ? true : null;

        return $filtre;
    }

    private function reponsePdf(string $octets, string $nom)
    {
        return response($octets, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nom.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Exécute l'opération, puis émet l'exemplaire correspondant ; l'échec de l'émission n'annule pas l'opération. */
    private function validerEtEmettre(callable $action, Decharge $decharge, string $exemplaire, string $succes)
    {
        try {
            $action();
        } catch (RegleLogistique|InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $retour = redirect()->route('logistique.decharges.show', $decharge);
        try {
            $this->pdf->emettre($decharge, $exemplaire);
        } catch (Throwable $e) {
            report($e);

            return $retour->with('success', $succes)
                ->with('warning', 'Le PDF n\'a pas pu être émis : l\'opération est enregistrée, utilisez « Émettre le PDF » depuis cette page.');
        }

        return $retour->with('success', $succes);
    }
}

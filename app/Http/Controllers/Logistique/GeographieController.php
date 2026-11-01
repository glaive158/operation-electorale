<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\PerimetreDestinataire;
use App\Services\Logistique\DecoupageService;
use App\Services\Logistique\LectureRecensement;
use App\Services\Logistique\NomGeo;
use Illuminate\Http\Request;

/** Destinataires et découpage du cycle (qui sert quelle localité). */
class GeographieController extends Controller
{
    use ExecuteAction;

    public function __construct(
        private readonly DecoupageService $decoupage,
        private readonly LectureRecensement $recensement,
    ) {
    }

    public function destinataires(Request $request)
    {
        $destinataires = Destinataire::with('prefecture')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->query('type')))
            ->when($request->filled('q'), fn ($q) => $q->where('libelle', 'like', '%'.addcslashes((string) $request->query('q'), '%_\\').'%'))
            ->orderBy('type')->orderBy('libelle')
            ->paginate(100)->withQueryString();

        return view('logistique.destinataires.index', compact('destinataires'));
    }

    public function genererDestinataires()
    {
        return $this->executer(
            fn () => $this->decoupage->genererDestinataires(),
            fn (array $r) => "{$r['crees']} destinataire(s) créé(s).",
        );
    }

    public function modifierDestinataire(Request $request, Destinataire $destinataire)
    {
        $donnees = $request->validate([
            'signataire_nom' => 'nullable|string|max:150',
            'signataire_qualite' => 'nullable|string|max:150',
        ]);

        return $this->executer(
            fn () => $this->decoupage->modifierDestinataire($destinataire, $donnees + ['actif' => $request->boolean('actif')]),
            'Destinataire mis à jour.',
        );
    }

    public function decoupage(Request $request, Cycle $cycle)
    {
        $communes = $this->recensement->communes()->keyBy('id');
        $affectees = PerimetreDestinataire::where('cycle_id', $cycle->id)->get(['commune_id', 'pays', 'ville'])
            ->mapWithKeys(fn ($p) => [$p->localite() => true]);

        // Localités connues du recensement mais absentes du périmètre du cycle.
        $sansDestinataire = $communes->reject(fn ($c) => $affectees->has("commune:{$c->id}"))
            ->map(fn ($c) => ['commune_id' => $c->id, 'pays' => null, 'ville' => null, 'libelle' => "{$c->nom} ({$c->departement_nom})"])
            ->merge($this->recensement->villesEtranger()
                ->reject(fn ($v) => $affectees->has(NomGeo::cleEtranger($v->pays, $v->ville)))
                ->map(fn ($v) => ['commune_id' => null, 'pays' => $v->pays, 'ville' => $v->ville, 'libelle' => "{$v->pays} | {$v->ville}"]))
            ->values();

        $perimetre = $request->boolean('sans_destinataire') ? null : PerimetreDestinataire::with('destinataire')
            ->where('cycle_id', $cycle->id)
            ->when($request->filled('destinataire'), fn ($q) => $q->where('destinataire_id', (int) $request->query('destinataire')))
            ->orderBy('id')->paginate(100)->withQueryString();

        return view('logistique.decoupage.show', [
            'cycle' => $cycle,
            'perimetre' => $perimetre,
            'communes' => $communes,
            'sansDestinataire' => $sansDestinataire,
            'destinataires' => Destinataire::actifs()->orderBy('type')->orderBy('libelle')->get(['id', 'libelle']),
            'autresCycles' => Cycle::where('id', '!=', $cycle->id)->orderByDesc('id')->get(),
        ]);
    }

    public function genererPerimetre(Cycle $cycle)
    {
        return $this->executer(
            fn () => $this->decoupage->genererPerimetre($cycle),
            fn (array $r) => "{$r['affectees']} localité(s) affectée(s), {$r['sans_destinataire']} sans destinataire.",
        );
    }

    public function affecter(Request $request, Cycle $cycle)
    {
        $donnees = $request->validate([
            'destinataire_id' => 'required|integer|exists:destinataires,id',
            'commune_id' => 'nullable|integer|min:1',
            'pays' => 'nullable|string|max:100',
            'ville' => 'nullable|string|max:100',
            'motif' => 'required|string|max:500',
        ]);

        return $this->executer(
            fn () => $this->decoupage->affecter(
                $cycle,
                Destinataire::findOrFail($donnees['destinataire_id']),
                isset($donnees['commune_id']) ? (int) $donnees['commune_id'] : null,
                $donnees['pays'] ?? null,
                $donnees['ville'] ?? null,
                $donnees['motif'],
            ),
            'Localité affectée.',
        );
    }

    public function retirer(Request $request, Cycle $cycle)
    {
        $donnees = $request->validate([
            'commune_id' => 'nullable|integer|min:1',
            'pays' => 'nullable|string|max:100',
            'ville' => 'nullable|string|max:100',
            'motif' => 'required|string|max:500',
        ]);

        return $this->executer(
            fn () => $this->decoupage->retirer(
                $cycle,
                isset($donnees['commune_id']) ? (int) $donnees['commune_id'] : null,
                $donnees['pays'] ?? null,
                $donnees['ville'] ?? null,
                $donnees['motif'],
            ),
            'Localité retirée du périmètre.',
        );
    }

    public function copier(Request $request, Cycle $cycle)
    {
        $donnees = $request->validate(['source_id' => 'required|integer|exists:cycles_electoraux,id']);

        return $this->executer(
            fn () => $this->decoupage->copierPerimetre(Cycle::findOrFail($donnees['source_id']), $cycle),
            fn (int $n) => "{$n} localité(s) copiée(s).",
        );
    }
}

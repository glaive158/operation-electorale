<?php

namespace App\Http\Controllers\Api\Terrain;

use App\Http\Controllers\Controller;
use App\Models\Logistique\Appareil;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\ReserveAppareil;
use App\Services\Logistique\AppareilService;
use App\Services\Logistique\PerimetreTerrain;
use App\Services\Logistique\RemiseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * L'appareil désigné d'une préfecture : savoir s'il l'est, réserver une part du solde du préfet avant de passer
 * hors ligne, consulter cette réserve et libérer le reste à la synchronisation. Tout passe par l'en-tête X-Appareil.
 */
class AppareilController extends Controller
{
    use ResoutAppareil;

    public function __construct(
        private readonly AppareilService $appareils,
        private readonly RemiseService $remises,
        private readonly PerimetreTerrain $perimetre,
    ) {}

    /** La requête vient-elle de l'appareil désigné (son jeton épinglé) d'une préfecture dont l'appelant est l'autorité ? */
    public function show(Request $request): JsonResponse
    {
        $identifiant = $this->identifiantAppareil($request);
        $appareil = $this->appareilReconnu($request, $identifiant);
        $prefecture = $appareil?->prefecture;

        if ($appareil === null || $prefecture === null || ! $this->perimetre->estAutoriteDe($request->user(), $prefecture)) {
            return response()->json(['designe' => false, 'appareil' => null]);
        }

        return response()->json(['designe' => true, 'appareil' => [
            'identifiant' => $appareil->identifiant, 'nom' => $appareil->nom,
            'prefecture' => ['id' => $prefecture->id, 'libelle' => $prefecture->libelle],
        ]]);
    }

    public function reserve(Request $request, Decharge $decharge): JsonResponse
    {
        $prefecture = $this->prefectureDeLAppelant($request, $decharge, $this->perimetre, $this->remises);
        $appareil = $this->appareilDesigne($request, $prefecture);

        return response()->json(['reserve' => $this->representation($appareil, $decharge, $this->appareils->reserveActive($appareil, $decharge))]);
    }

    /** Sans `lignes` : tout le mobilisable ; avec : exactement ces quantités. Remplace la réserve active précédente. */
    public function reserver(Request $request, Decharge $decharge): JsonResponse
    {
        $prefecture = $this->prefectureDeLAppelant($request, $decharge, $this->perimetre, $this->remises);
        $appareil = $this->appareilDesigne($request, $prefecture);

        $donnees = $request->validate([
            'lignes' => ['nullable', 'array', 'min:1', 'max:200'],
            'lignes.*.materiel_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lignes.*.quantite' => ['required', 'integer', 'min:0', 'max:10000000'],
        ]);
        $quantites = isset($donnees['lignes']) ? collect($donnees['lignes'])->mapWithKeys(fn (array $l) => [(int) $l['materiel_id'] => (int) $l['quantite']])->all() : null;

        $lignes = $this->appareils->reserver($appareil, $decharge, $quantites);

        return response()->json(['reserve' => $this->representation($appareil, $decharge, $lignes)]);
    }

    /** Libère le reste de la réserve ; renvoie ce qui a été rendu. Sans effet s'il n'y a rien. */
    public function liberer(Request $request, Decharge $decharge): JsonResponse
    {
        $prefecture = $this->prefectureDeLAppelant($request, $decharge, $this->perimetre, $this->remises);
        $appareil = $this->appareilDesigne($request, $prefecture);

        $liberees = $this->appareils->liberer($appareil, $decharge);

        return response()->json([
            'reserve' => $this->representation($appareil, $decharge, collect()),
            'libere' => $this->lignes($liberees),
        ]);
    }

    /** @param  Collection<int, ReserveAppareil>  $lignes */
    private function representation(Appareil $appareil, Decharge $decharge, Collection $lignes): array
    {
        return ['appareil' => $appareil->identifiant, 'decharge_id' => $decharge->id, 'lignes' => $this->lignes($lignes)];
    }

    /**
     * @param  Collection<int, ReserveAppareil>  $lignes
     * @return list<array{materiel_id: int, libelle: string, quantite: int, quantite_initiale: int}>
     */
    private function lignes(Collection $lignes): array
    {
        $libelles = Materiel::whereIn('id', $lignes->pluck('materiel_id')->all())->pluck('libelle', 'id');

        return $lignes->sortBy('materiel_id')->map(fn (ReserveAppareil $l) => [
            'materiel_id' => $l->materiel_id, 'libelle' => (string) $libelles->get($l->materiel_id, ''),
            'quantite' => $l->quantite, 'quantite_initiale' => $l->quantite_initiale,
        ])->values()->all();
    }
}

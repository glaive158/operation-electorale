<?php

namespace App\Http\Controllers\Api\Terrain;

use App\Http\Controllers\Controller;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Remise;
use App\Services\Logistique\PerimetreTerrain;
use App\Services\Logistique\RemiseService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Redistribution du préfet (ou de son adjoint) au sous-préfet, envoyée par un terminal, idempotente par clé.
 * Avec l'en-tête X-Appareil : redistribution hors ligne de l'appareil désigné, dans sa réserve ; tout dépassement ou
 * appareil non désigné, ou dont l'identité n'est pas établie par le jeton, est conservé « à arbitrer » (201, statut
 * a_arbitrer). Sans en-tête : règle en ligne.
 */
class RedistributionController extends Controller
{
    use ResoutAppareil;

    public function __construct(
        private readonly RemiseService $remises,
        private readonly PerimetreTerrain $perimetre,
    ) {}

    public function store(Request $request, Decharge $decharge): JsonResponse
    {
        $identifiant = $this->identifiantAppareil($request);
        $this->prefectureDeLAppelant($request, $decharge, $this->perimetre, $this->remises);

        $donnees = $request->validate([
            'cle' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{16,64}$/'],
            'horodatage_terminal' => ['required', 'string', 'date_format:Y-m-d\TH:i:sP'],
            'lignes' => ['required', 'array', 'min:1', 'max:200'],
            'lignes.*.materiel_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lignes.*.quantite' => ['required', 'integer', 'min:0', 'max:10000000'],
        ]);
        $quantites = collect($donnees['lignes'])->mapWithKeys(fn (array $l) => [(int) $l['materiel_id'] => (int) $l['quantite']])->all();

        [$remise, $creee] = $this->remises->redistribuerSynchronisee(
            $decharge, $quantites, $donnees['cle'], Carbon::createFromFormat('Y-m-d\TH:i:sP', $donnees['horodatage_terminal']), $identifiant,
            $identifiant === null || $this->jetonLieA($request, $identifiant),
        );

        return response()->json(['redistribution' => $this->representation($remise)], $creee ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function representation(Remise $remise): array
    {
        return [
            'id' => $remise->id,
            'decharge_id' => $remise->decharge_id,
            'statut' => $remise->statut,
            'a_arbitrer' => $remise->statut === 'a_arbitrer',
            'raison' => $remise->raison_arbitrage,
            'appareil' => $remise->appareil_declare,
            'cle' => $remise->cle_idempotence,
            'horodatage_terminal' => $remise->horodatage_terminal?->toIso8601String(),
            'lignes' => $remise->lignes->map(fn ($l) => ['materiel_id' => $l->materiel_id, 'quantite' => $l->quantite])->values()->all(),
        ];
    }
}

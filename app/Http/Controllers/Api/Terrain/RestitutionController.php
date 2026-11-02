<?php

namespace App\Http\Controllers\Api\Terrain;

use App\Exceptions\Logistique\DeclarationEnCours;
use App\Http\Controllers\Controller;
use App\Models\Logistique\Restitution;
use App\Models\Logistique\RestitutionDeclaration;
use App\Services\Logistique\RestitutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Restitution depuis la tablette de l'agent de retour : déclaration du détenteur (mode tablette, code personnel
 * chiffré dans le bloc de preuve) puis prise en charge du retour. Pour un autre compte, la restitution n'existe
 * pas (404). L'envoi de la déclaration est idempotent : la même clé avec le même contenu et le même bloc renvoie
 * le résultat enregistré (200) ; la prise en charge rejouée par l'agent qui l'a faite aussi.
 */
class RestitutionController extends Controller
{
    public function __construct(private readonly RestitutionService $restitutions) {}

    public function declarer(Request $request, Restitution $restitution): JsonResponse
    {
        // Hors du périmètre de l'agent, la restitution n'existe pas : avant même de lire l'envoi.
        abort_unless((int) $restitution->agent_id === (int) $request->user()->id, 404);

        $donnees = $request->validate([
            'mode' => ['required', 'in:tablette'],
            'cle' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{16,64}$/'],
            'horodatage_terminal' => ['required', 'string', 'date_format:Y-m-d\TH:i:sP'],
            'signataire' => ['required', 'array'],
            // Repli sans code : sans bloc de preuve, le signataire peut n'avoir aucun compte rattaché.
            'signataire.user_id' => ['nullable', 'required_with:bloc_preuve', 'integer', 'min:1'],
            'signataire.nom' => ['required', 'string', 'max:150'],
            'signataire.qualite' => ['required', 'string', 'max:150'],
            'lignes' => ['required', 'array', 'min:1', 'max:200'],
            'lignes.*.materiel_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lignes.*.restitue' => ['required', 'integer', 'min:0', 'max:10000000'],
            'lignes.*.observation' => ['nullable', 'string', 'max:500'],
            'bloc_preuve' => ['nullable', 'string', 'max:2048'],
            'pieces' => ['nullable', 'array', 'max:6'],
            'pieces.*.empreinte' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/', 'distinct'],
            'pieces.*.role' => ['required', 'in:signature,photo'],
        ]);

        $pieces = [];
        foreach ($donnees['pieces'] ?? [] as $piece) {
            $pieces[$piece['empreinte']] = $piece['role'];
        }
        $lignes = array_map(fn (array $l) => [
            'materiel_id' => (int) $l['materiel_id'], 'restitue' => (int) $l['restitue'], 'observation' => $l['observation'] ?? null,
        ], $donnees['lignes']);

        try {
            $declaration = $this->restitutions->declarer(
                $restitution,
                'tablette',
                ['nom' => $donnees['signataire']['nom'], 'qualite' => $donnees['signataire']['qualite']],
                $lignes,
                $donnees['cle'],
                $donnees['horodatage_terminal'],
                $donnees['bloc_preuve'] ?? null,
                isset($donnees['signataire']['user_id']) ? (int) $donnees['signataire']['user_id'] : null,
                $pieces,
            );
        } catch (DeclarationEnCours $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['declaration' => $this->representation($declaration)], $declaration->wasRecentlyCreated ? 201 : 200);
    }

    public function prendreEnCharge(Request $request, Restitution $restitution): JsonResponse
    {
        $agent = $request->user();
        // Rejeu par l'agent qui l'a déjà prise en charge (réponse perdue en route) : le résultat enregistré.
        if ($restitution->statut !== 'a_preparer' && (int) $restitution->agent_id === (int) $agent->id) {
            return $this->priseEnCharge($restitution);
        }

        $this->restitutions->prendreEnCharge($restitution, $agent);

        return $this->priseEnCharge($restitution);
    }

    private function priseEnCharge(Restitution $restitution): JsonResponse
    {
        return response()->json(['restitution' => [
            'id' => $restitution->id, 'statut' => $restitution->statut, 'agent_id' => $restitution->agent_id,
        ]]);
    }

    /** @return array{id: int, statut: string, verification_identite: string, anomalies: list<string>} */
    private function representation(RestitutionDeclaration $declaration): array
    {
        return [
            'id' => $declaration->id,
            'statut' => $declaration->statut,
            'verification_identite' => $declaration->verification_identite,
            'anomalies' => array_values($declaration->anomalies ?? []),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\Terrain;

use App\Http\Controllers\Controller;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Remise;
use App\Services\Logistique\DeclarationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Déclaration de réception envoyée par la tablette, avec son bloc de preuve chiffré. L'envoi est idempotent :
 * rejouer la même clé avec le même contenu et le même bloc renvoie le résultat enregistré (200).
 */
class DeclarationController extends Controller
{
    public function __construct(private readonly DeclarationService $declarations) {}

    public function store(Request $request, Remise $remise): JsonResponse
    {
        $donnees = $request->validate([
            'mode' => ['required', 'in:tablette'],
            'cle' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{16,64}$/'],
            'horodatage_terminal' => ['required', 'string', 'date_format:Y-m-d\TH:i:sP'],
            'signataire' => ['required', 'array'],
            'signataire.user_id' => ['required', 'integer', 'min:1'],
            'signataire.nom' => ['required', 'string', 'max:150'],
            'signataire.qualite' => ['required', 'string', 'max:150'],
            'lignes' => ['required', 'array', 'min:1', 'max:200'],
            'lignes.*.materiel_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lignes.*.recu' => ['required', 'integer', 'min:0', 'max:10000000'],
            'lignes.*.defectueux' => ['required', 'integer', 'min:0', 'max:10000000'],
            'lignes.*.observation' => ['nullable', 'string', 'max:500'],
            // 344 caractères pour une clé de 2048 bits : le plafond n'est qu'une garde contre les envois démesurés.
            'bloc_preuve' => ['nullable', 'string', 'max:2048'],
            'pieces' => ['nullable', 'array', 'max:6'],
            'pieces.*.empreinte' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/', 'distinct'],
            'pieces.*.role' => ['required', 'in:signature,photo'],
            'gps' => ['nullable', 'array'],
            'gps.lat' => ['required_with:gps', 'numeric', 'between:-90,90'],
            'gps.lng' => ['required_with:gps', 'numeric', 'between:-180,180'],
            'gps.precision' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ]);

        $pieces = [];
        foreach ($donnees['pieces'] ?? [] as $piece) {
            $pieces[$piece['empreinte']] = $piece['role'];
        }

        [$declaration, $creee] = $this->declarations->declarerParTablette(
            $remise,
            ['nom' => $donnees['signataire']['nom'], 'qualite' => $donnees['signataire']['qualite']],
            $donnees['lignes'],
            $donnees['cle'],
            $donnees['horodatage_terminal'],
            (int) $donnees['signataire']['user_id'],
            $donnees['bloc_preuve'] ?? null,
            $pieces,
            $donnees['gps'] ?? null,
        );

        return response()->json(['declaration' => $this->representation($declaration)], $creee ? 201 : 200);
    }

    /** @return array{id: int, statut: string, verification_identite: string, contestation: string, anomalies: list<string>} */
    private function representation(Declaration $declaration): array
    {
        return [
            'id' => $declaration->id,
            'statut' => $declaration->statut,
            'verification_identite' => $declaration->verification_identite,
            'contestation' => $declaration->contestation,
            'anomalies' => array_values($declaration->anomalies ?? []),
        ];
    }
}

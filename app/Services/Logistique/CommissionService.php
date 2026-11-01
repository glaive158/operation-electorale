<?php

namespace App\Services\Logistique;

use App\Models\Logistique\CommissionAdministrative;
use App\Models\Logistique\Cycle;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Création et suppression unitaires des commissions administratives d'un cycle ouvert. */
class CommissionService
{
    public function __construct(private readonly LectureRecensement $recensement)
    {
    }

    /**
     * @param  array{type?:string, commune_id?:int|null, pays?:string|null, ville?:string|null, libelle?:string|null}  $donnees
     */
    public function creer(Cycle $cycle, array $donnees): CommissionAdministrative
    {
        CommissionAdministrative::exigerAuteur();

        $pays = isset($donnees['pays']) ? trim((string) $donnees['pays']) : null;
        $ville = isset($donnees['ville']) ? trim((string) $donnees['ville']) : null;
        $libelle = isset($donnees['libelle']) ? trim((string) $donnees['libelle']) : null;
        $communeId = $donnees['commune_id'] ?? null;

        if (mb_strlen((string) $pays) > 100 || mb_strlen((string) $ville) > 100) {
            throw new InvalidArgumentException('Le pays et la ville ne dépassent pas 100 caractères.');
        }
        if (mb_strlen((string) $libelle) > 200) {
            throw new InvalidArgumentException('Le libellé ne dépasse pas 200 caractères.');
        }
        if ($communeId !== null && ! $this->recensement->communes()->contains('id', $communeId)) {
            throw new InvalidArgumentException("Commune inconnue : {$communeId}.");
        }

        return DB::transaction(function () use ($cycle, $donnees, $communeId, $pays, $ville, $libelle) {
            CommissionAdministrative::verrouillerCycleOuvert($cycle);

            $commission = CommissionAdministrative::create([
                'cycle_id'   => $cycle->id,
                'type'       => $donnees['type'] ?? null,
                'commune_id' => $communeId,
                'pays'       => $pays === '' ? null : $pays,
                'ville'      => $ville === '' ? null : $ville,
                'libelle'    => $libelle === '' ? null : $libelle,
            ]);

            Journal::noter('commission_administrative', $commission->id, 'creation_commission', null, [
                'cycle_id' => $cycle->id, 'type' => $commission->type, 'localite' => $commission->localite(),
            ]);

            return $commission;
        }, attempts: 3);
    }

    public function supprimer(CommissionAdministrative $commission, string $motif): void
    {
        CommissionAdministrative::exigerAuteur();

        $motif = trim($motif);
        if ($motif === '') {
            throw new InvalidArgumentException('Le motif est obligatoire.');
        }

        DB::transaction(function () use ($commission, $motif) {
            CommissionAdministrative::verrouillerCycleOuvert($commission->cycle()->firstOrFail());

            $actuelle = CommissionAdministrative::whereKey($commission->id)->lockForUpdate()->first();
            if ($actuelle === null) {
                return;
            }

            $actuelle->delete();
            Journal::noter('commission_administrative', $actuelle->id, 'suppression_commission', [
                'cycle_id' => $actuelle->cycle_id, 'type' => $actuelle->type, 'localite' => $actuelle->localite(),
            ], null, $motif);
        }, attempts: 3);
    }
}

<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Exceptions\Logistique\StockInsuffisant;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\MouvementStock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Grand livre de stock : le stock n'est jamais une colonne, c'est la somme
 * des mouvements immuables. Toute écriture verrouille la ligne du matériel
 * et vérifie le solde résultant dans la même transaction.
 */
class StockService
{
    public function physique(Materiel $materiel, string $etat = 'bon'): int
    {
        return (int) MouvementStock::where('materiel_id', $materiel->id)
            ->where('etat', $etat)
            ->sum('quantite');
    }

    /**
     * Usage interne des services métier.
     */
    public function enregistrer(
        Materiel $materiel,
        string $type,
        int $quantite,
        ?Model $reference = null,
        ?string $motif = null,
        string $etat = 'bon',
    ): MouvementStock {
        if ($type === 'compensation') {
            throw new InvalidArgumentException('Une compensation se crée avec compenser().');
        }

        return $this->ecrire($materiel, $type, $quantite, $reference, $motif, $etat, null);
    }

    public function inventaireInitial(Materiel $materiel, int $quantite, string $motif): MouvementStock
    {
        if ($quantite <= 0) {
            throw new InvalidArgumentException("La quantité d'un inventaire initial doit être strictement positive.");
        }

        return $this->ecrire($materiel, 'inventaire_initial', $quantite, null, $motif, 'bon', null);
    }

    public function compenser(MouvementStock $mouvement, string $motif): MouvementStock
    {
        if ($mouvement->type === 'compensation') {
            throw new RegleLogistique('Une compensation ne peut pas être elle-même compensée.');
        }

        return DB::transaction(function () use ($mouvement, $motif) {
            $materiel = Materiel::whereKey($mouvement->materiel_id)->lockForUpdate()->firstOrFail();

            if (MouvementStock::where('mouvement_compense_id', $mouvement->id)->exists()) {
                throw new RegleLogistique('Ce mouvement a déjà été compensé.');
            }

            $compensation = $this->ecrire(
                $materiel, 'compensation', -$mouvement->quantite, null, $motif, $mouvement->etat, $mouvement->id,
            );

            Journal::noter('mouvement_stock', $mouvement->id, 'compensation', null, null, $motif);

            return $compensation;
        });
    }

    private function ecrire(
        Materiel $materiel,
        string $type,
        int $quantite,
        ?Model $reference,
        ?string $motif,
        string $etat,
        ?int $compenseId,
    ): MouvementStock {
        if ($quantite === 0) {
            throw new InvalidArgumentException('La quantité d\'un mouvement de stock ne peut pas être nulle.');
        }
        if (! in_array($type, MouvementStock::TYPES, true)) {
            throw new InvalidArgumentException("Type de mouvement inconnu : {$type}.");
        }
        if (! in_array($etat, MouvementStock::ETATS, true)) {
            throw new InvalidArgumentException("État de stock inconnu : {$etat}.");
        }

        return DB::transaction(function () use ($materiel, $type, $quantite, $reference, $motif, $etat, $compenseId) {
            Materiel::whereKey($materiel->id)->lockForUpdate()->firstOrFail();

            $solde = $this->physique($materiel, $etat) + $quantite;
            if ($solde < 0) {
                throw new StockInsuffisant(sprintf(
                    'Stock insuffisant pour « %s » (état %s) : %d en stock, mouvement de %d.',
                    $materiel->libelle, $etat === 'bon' ? 'bon' : 'à réparer', $solde - $quantite, $quantite,
                ));
            }

            return MouvementStock::create([
                'materiel_id'           => $materiel->id,
                'type'                  => $type,
                'etat'                  => $etat,
                'quantite'              => $quantite,
                'reference_type'        => $reference ? $reference->getTable() : null,
                'reference_id'          => $reference?->getKey(),
                'mouvement_compense_id' => $compenseId,
                'motif'                 => $motif,
                'user_id'               => auth()->id(),
            ]);
        });
    }
}

<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\CircuitDepartement;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Destinataire;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

/**
 * Circuit de livraison du matériel : par le préfet (qui redistribue aux sous-préfectures) ou
 * direct, avec dérogation possible par département sur le circuit par défaut du cycle.
 */
class CircuitService
{
    /**
     * Fixe la dérogation d'un département ; null la supprime (retour au circuit du cycle).
     * Le département est celui d'une préfecture destinataire.
     */
    public function definir(Cycle $cycle, int $departementId, ?string $circuit, string $motif): void
    {
        $this->exigerAuteur();
        if (! Gate::allows('logistique-administrer')) {
            throw new RegleLogistique('Seul un responsable logistique peut modifier le circuit de livraison.');
        }

        $motif = trim($motif);
        if ($motif === '' || mb_strlen($motif) > 500) {
            throw new InvalidArgumentException('Le motif est obligatoire (500 caractères au plus).');
        }
        if ($circuit !== null && ! in_array($circuit, CircuitDepartement::CIRCUITS, true)) {
            throw new InvalidArgumentException('Circuit inconnu (via_prefet ou direct).');
        }

        DB::transaction(function () use ($cycle, $departementId, $circuit, $motif) {
            $actuel = Cycle::whereKey($cycle->id)->lockForUpdate()->first();
            if ($actuel === null || ! $actuel->estOuvert()) {
                throw new RegleLogistique('Cycle clôturé : circuit de livraison non modifiable.');
            }

            $prefecture = Destinataire::where('type', 'prefecture')->where('departement_id', $departementId)->first();
            if ($prefecture === null) {
                throw new InvalidArgumentException('Département inconnu.');
            }

            $existante = CircuitDepartement::where('cycle_id', $actuel->id)
                ->where('departement_id', $departementId)->lockForUpdate()->first();
            $avant = ['circuit' => $existante?->circuit ?? $actuel->circuit_defaut, 'derogation' => $existante !== null];

            if ($circuit === null) {
                $existante?->delete();
            } elseif ($existante === null) {
                CircuitDepartement::create([
                    'cycle_id' => $actuel->id, 'departement_id' => $departementId,
                    'circuit' => $circuit, 'user_id' => auth()->id(),
                ]);
            } else {
                $existante->update(['circuit' => $circuit, 'user_id' => auth()->id()]);
            }

            $apres = ['circuit' => $circuit ?? $actuel->circuit_defaut, 'derogation' => $circuit !== null];
            Journal::noter('circuit_departement', $prefecture->id, 'definition_circuit', $avant + [
                'cycle_id' => $actuel->id, 'departement_id' => $departementId,
            ], $apres + [
                'cycle_id' => $actuel->id, 'departement_id' => $departementId,
            ], $motif);
        }, attempts: 3);
    }

    /** 'etranger' pour un poste ; sinon la dérogation du département, à défaut le circuit du cycle. */
    public function circuitPour(Cycle $cycle, Destinataire $destinataire): string
    {
        if ($destinataire->type === 'poste') {
            return 'etranger';
        }

        $departementId = $this->departementDe($destinataire);
        $derogation = $departementId === null ? null : CircuitDepartement::where('cycle_id', $cycle->id)
            ->where('departement_id', $departementId)->value('circuit');

        return $derogation ?? $cycle->circuit_defaut;
    }

    /** Destinataire à qui la remise est faite : le préfet pour une sous-préfecture en circuit via le préfet. */
    public function remisA(Cycle $cycle, Destinataire $destinataire): Destinataire
    {
        if ($destinataire->type !== 'sous_prefecture' || $this->circuitPour($cycle, $destinataire) !== 'via_prefet') {
            return $destinataire;
        }

        $prefecture = $destinataire->prefecture_id === null ? null : Destinataire::find($destinataire->prefecture_id);
        if ($prefecture === null) {
            throw new RegleLogistique('Sous-préfecture sans préfecture parente : circuit via le préfet impossible.');
        }

        return $prefecture;
    }

    /**
     * Une ligne par préfecture destinataire, triée par nom.
     *
     * @return Collection<int, array{departement_id:int, nom:string, circuit:string, derogation:bool}>
     */
    public function departements(Cycle $cycle): Collection
    {
        $derogations = CircuitDepartement::where('cycle_id', $cycle->id)->pluck('circuit', 'departement_id');

        return Destinataire::where('type', 'prefecture')->whereNotNull('departement_id')->get()
            ->map(fn (Destinataire $p) => [
                'departement_id' => (int) $p->departement_id,
                'nom'            => $p->libelle,
                'circuit'        => $derogations[$p->departement_id] ?? $cycle->circuit_defaut,
                'derogation'     => $derogations->has($p->departement_id),
            ])
            ->sortBy('nom', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /** Une sous-préfecture n'a pas de département propre : elle suit celui de sa préfecture. */
    private function departementDe(Destinataire $destinataire): ?int
    {
        if ($destinataire->departement_id !== null) {
            return (int) $destinataire->departement_id;
        }
        if ($destinataire->prefecture_id === null) {
            return null;
        }

        $id = Destinataire::whereKey($destinataire->prefecture_id)->value('departement_id');

        return $id === null ? null : (int) $id;
    }

    private function exigerAuteur(): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Opération sans auteur.');
        }
    }
}

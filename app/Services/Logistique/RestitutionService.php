<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Restitution;
use App\Models\Logistique\RestitutionLigne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * Restitution du matériel réutilisable à l'issue d'un cycle : ouverture d'une restitution par détenteur
 * (agent ou destinataire) d'après son solde (SoldeDetenteurService), puis recalcul tant qu'elle est à préparer.
 *
 * Ordre des verrous : cycle, restitution(s). La restitution se tient aussi sur un cycle clos (spec §9.3) : aucune
 * écriture ici ne refuse un cycle clos.
 */
class RestitutionService
{
    public function __construct(private readonly SoldeDetenteurService $soldes) {}

    /**
     * Ouvre une restitution « à préparer » pour chaque détenteur qui a un solde et aucune restitution ouverte
     * (non contrôlée) sur le cycle ; ses lignes portent l'attendu, égal au solde. Idempotente : un détenteur qui
     * a déjà une restitution ouverte est laissé tel quel. Renvoie le nombre de restitutions créées et le nombre
     * de détenteurs laissés inchangés.
     *
     * Le verrou du cycle est exclusif : l'unicité d'une restitution ouverte par détenteur ne repose sur aucune
     * contrainte de base, deux ouvertures simultanées doivent donc se succéder.
     *
     * @return array{creees: int, inchangees: int}
     */
    public function ouvrir(Cycle $cycle): array
    {
        $this->exigerAuteur();
        $this->exigerResponsable('Seul un responsable logistique peut ouvrir la restitution.');

        return DB::transaction(function () use ($cycle) {
            $actuel = Cycle::whereKey($cycle->id)->lockForUpdate()->firstOrFail();

            $parDetenteur = $this->soldes->soldes($actuel)
                ->groupBy(fn (array $solde) => $solde['detenteur_type'].':'.$solde['detenteur_id']);
            $ouvertes = Restitution::where('cycle_id', $actuel->id)->where('statut', '!=', 'controlee')
                ->get(['detenteur_type', 'detenteur_id'])
                ->mapWithKeys(fn (Restitution $r) => [$r->detenteur_type.':'.$r->detenteur_id => true]);

            $creees = 0;
            $inchangees = 0;
            foreach ($parDetenteur as $cle => $soldes) {
                if ($ouvertes->has($cle)) {
                    $inchangees++;

                    continue;
                }

                $premier = $soldes->first();
                $restitution = new Restitution([
                    'cycle_id' => $actuel->id, 'detenteur_type' => $premier['detenteur_type'], 'detenteur_id' => $premier['detenteur_id'],
                ]);
                $restitution->forceFill(['ouverte_par' => auth()->id()])->save();

                $attendus = [];
                foreach ($soldes->sortBy('materiel_id') as $solde) {
                    RestitutionLigne::create([
                        'restitution_id' => $restitution->id, 'materiel_id' => $solde['materiel_id'], 'attendu' => $solde['solde'],
                    ]);
                    $attendus[$solde['materiel_id']] = $solde['solde'];
                }

                Journal::noter('restitution', $restitution->id, 'ouverture_restitution', null, [
                    'cycle_id' => $actuel->id, 'detenteur_type' => $restitution->detenteur_type,
                    'detenteur_id' => $restitution->detenteur_id, 'attendus' => $attendus,
                ]);
                $creees++;
            }

            // Les détenteurs déjà ouverts sans solde restant sont aussi « inchangés ».
            $inchangees += $ouvertes->keys()->diff($parDetenteur->keys())->count();

            return ['creees' => $creees, 'inchangees' => $inchangees];
        }, attempts: 3);
    }

    /**
     * Remet les attendus d'une restitution « à préparer » au solde courant de son détenteur : une ligne est
     * ajoutée pour un article nouveau, mise à jour pour un article existant, et supprimée lorsque son solde est
     * tombé à zéro, sauf si un retour y a déjà été déclaré (elle reste alors, attendu à zéro). Les retours
     * déclarés par les autres restitutions du détenteur se déduisent, pas les siens.
     */
    public function recalculer(Restitution $restitution): void
    {
        $this->exigerAuteur();
        $this->exigerResponsable('Seul un responsable logistique peut recalculer la restitution.');

        DB::transaction(function () use ($restitution) {
            $cycle = Cycle::whereKey($restitution->cycle_id)->sharedLock()->firstOrFail();
            $actuelle = Restitution::whereKey($restitution->id)->lockForUpdate()->firstOrFail();
            if ($actuelle->statut !== 'a_preparer') {
                throw new RegleLogistique('Seule une restitution à préparer peut être recalculée.');
            }

            $soldes = $this->soldes
                ->soldes($cycle, $actuelle->detenteur_type, $actuelle->detenteur_id, $actuelle->id)
                ->mapWithKeys(fn (array $solde) => [$solde['materiel_id'] => $solde['solde']]);
            $lignes = RestitutionLigne::where('restitution_id', $actuelle->id)->orderBy('materiel_id')->get()->keyBy('materiel_id');

            $avant = $lignes->map(fn (RestitutionLigne $l) => $l->attendu)->all();

            foreach ($lignes as $materielId => $ligne) {
                $solde = (int) ($soldes[$materielId] ?? 0);
                if ($solde === 0 && $ligne->declare_restitue === 0) {
                    $ligne->delete();
                } elseif ($ligne->attendu !== $solde) {
                    $ligne->forceFill(['attendu' => $solde])->save();
                }
            }
            foreach ($soldes as $materielId => $solde) {
                if (! $lignes->has($materielId)) {
                    RestitutionLigne::create(['restitution_id' => $actuelle->id, 'materiel_id' => $materielId, 'attendu' => $solde]);
                }
            }

            $apres = RestitutionLigne::where('restitution_id', $actuelle->id)->orderBy('materiel_id')->pluck('attendu', 'materiel_id')
                ->map(fn ($attendu) => (int) $attendu)->all();
            $actuelle->touch();

            Journal::noter('restitution', $actuelle->id, 'recalcul_restitution', ['attendus' => $avant], ['attendus' => $apres]);
        }, attempts: 3);
    }

    private function exigerAuteur(): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Opération de restitution sans auteur.');
        }
    }

    private function exigerResponsable(string $message): void
    {
        if (! Gate::allows('logistique-administrer')) {
            throw new RegleLogistique($message);
        }
    }
}

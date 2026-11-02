<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\DechargeLigne;
use App\Models\Logistique\Remise;
use App\Models\Logistique\RemiseLigne;
use App\Models\Logistique\ReservationStock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

/**
 * Remises d'une décharge : le chargement au magasin consomme les réservations (le stock sort) et crée la remise
 * de chargement (magasin vers l'agent ou le transporteur) et la remise de livraison (vers le destinataire).
 *
 * Ordre des verrous : cycle, décharge, remise, déclaration, article, réservation.
 */
class RemiseService
{
    private const AFFECTER = 'Affectez un agent (ou, à l\'étranger, un transporteur) avant le chargement.';

    public function __construct(private readonly StockService $stock) {}

    /**
     * Charge une décharge validée qui n'est pas partie. Hors circuit étranger, un agent doit être affecté ;
     * à l'étranger, un transporteur peut le remplacer : nom, qualité, société (150 caractères au plus) et
     * numéro de bordereau (50 au plus), tous requis. Renvoie la remise de chargement.
     *
     * @param  array{nom?: string, qualite?: string, societe?: string, bordereau?: string}  $transporteur
     */
    public function charger(Decharge $decharge, array $transporteur = []): Remise
    {
        $this->exigerAuteur();
        if (! Gate::allows('logistique-gerer')) {
            throw new RegleLogistique('Seul le personnel logistique peut charger une décharge.');
        }

        $remise = DB::transaction(function () use ($decharge, $transporteur) {
            $cycle = Cycle::whereKey($decharge->cycle_id)->lockForUpdate()->first();
            if ($cycle === null || ! $cycle->estOuvert()) {
                throw new RegleLogistique('Cycle clôturé : chargement impossible.');
            }
            $actuelle = Decharge::whereKey($decharge->id)->lockForUpdate()->firstOrFail();
            if ($actuelle->statut_document !== 'validee' || $actuelle->statut_acheminement !== 'a_preparer') {
                throw new RegleLogistique('Seule une décharge validée non partie peut être chargée.');
            }

            $porteur = $this->porteur($actuelle, $transporteur);

            // Mêmes ordres que valider() et annuler() : articles par identifiant croissant.
            $lignes = DechargeLigne::where('decharge_id', $actuelle->id)->orderBy('materiel_id')->get();
            if ($lignes->isEmpty()) {
                throw new LogicException('Décharge validée sans ligne : invariant rompu.');
            }
            foreach ($lignes as $ligne) {
                $reservation = $ligne->reservation_id === null ? null : ReservationStock::find($ligne->reservation_id);
                if ($reservation === null || ! $reservation->estActive()
                    || (int) $reservation->quantite !== (int) $ligne->quantite
                    || (int) $reservation->materiel_id !== (int) $ligne->materiel_id) {
                    throw new LogicException("Ligne validée sans réservation active de même quantité (article {$ligne->materiel_id}) : invariant rompu.");
                }
            }

            $consommees = [];
            foreach ($lignes as $ligne) {
                $mouvement = $this->stock->consommer(ReservationStock::findOrFail($ligne->reservation_id));
                $consommees[] = [
                    'materiel_id' => $ligne->materiel_id, 'quantite' => $ligne->quantite,
                    'reservation_id' => $ligne->reservation_id, 'mouvement_id' => $mouvement->id,
                ];
            }

            $chargement = $this->creer($actuelle, 'chargement', 'magasin', null, $porteur['type'], $porteur['id'], 'recue', $porteur['transporteur'], $lignes);
            $this->creer($actuelle, 'livraison', $porteur['type'], $porteur['id'], 'destinataire', (int) $actuelle->remis_a_id, 'en_attente', $porteur['transporteur'], $lignes);

            $actuelle->forceFill(['statut_acheminement' => 'chargee'])->save();

            Journal::noter('decharge', $actuelle->id, 'chargement_decharge', ['statut_acheminement' => 'a_preparer'], [
                'statut_acheminement' => 'chargee', 'numero' => $actuelle->numero,
                'porteur' => $porteur['type'], 'agent_id' => $porteur['type'] === 'agent' ? $porteur['id'] : null,
                'transporteur' => $porteur['transporteur'], 'remise_chargement_id' => $chargement->id,
                'lignes' => $consommees,
            ]);

            return $chargement;
        }, attempts: 3);

        $decharge->refresh();

        return $remise->load('lignes');
    }

    /**
     * Qui prend le matériel : le transporteur (circuit étranger, s'il est renseigné) ou l'agent affecté.
     *
     * @return array{type: string, id: ?int, transporteur: array<string, string>}
     */
    private function porteur(Decharge $decharge, array $transporteur): array
    {
        $transporteur = array_filter($transporteur, fn ($valeur) => $valeur !== null && $valeur !== '');

        if ($transporteur !== []) {
            if ($decharge->circuit !== 'etranger') {
                throw new InvalidArgumentException('Un transporteur ne se renseigne que pour une décharge du circuit étranger.');
            }

            $champs = ['nom' => 150, 'qualite' => 150, 'societe' => 150, 'bordereau' => 50];
            $retenu = [];
            foreach ($champs as $cle => $max) {
                $valeur = $transporteur[$cle] ?? null;
                $valeur = is_string($valeur) ? trim($valeur) : '';
                if ($valeur === '' || mb_strlen($valeur) > $max) {
                    throw new RegleLogistique(self::AFFECTER);
                }
                $retenu[$cle] = $valeur;
            }

            return ['type' => 'transporteur', 'id' => null, 'transporteur' => $retenu];
        }

        $agent = $decharge->agent_id === null ? null : User::find($decharge->agent_id);
        if ($agent === null || $agent->role !== 'agent_transport' || ! $agent->actif) {
            throw new RegleLogistique(self::AFFECTER);
        }

        return ['type' => 'agent', 'id' => (int) $agent->id, 'transporteur' => []];
    }

    /**
     * @param  array<string, string>  $transporteur
     * @param  iterable<DechargeLigne>  $lignes
     */
    private function creer(Decharge $decharge, string $etape, string $deType, ?int $deId, string $aType, ?int $aId, string $statut, array $transporteur, iterable $lignes): Remise
    {
        $remise = new Remise([
            'decharge_id' => $decharge->id, 'etape' => $etape,
            'de_type' => $deType, 'de_id' => $deId, 'a_type' => $aType, 'a_id' => $aId,
            'transporteur_nom' => $transporteur['nom'] ?? null,
            'transporteur_qualite' => $transporteur['qualite'] ?? null,
            'transporteur_societe' => $transporteur['societe'] ?? null,
            'bordereau_numero' => $transporteur['bordereau'] ?? null,
            'preparee_par' => auth()->id(),
        ]);
        $remise->forceFill(['statut' => $statut])->save();

        foreach ($lignes as $ligne) {
            RemiseLigne::create(['remise_id' => $remise->id, 'materiel_id' => $ligne->materiel_id, 'quantite' => $ligne->quantite]);
        }

        return $remise;
    }

    private function exigerAuteur(): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Opération sans auteur.');
        }
    }
}

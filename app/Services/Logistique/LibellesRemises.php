<?php

namespace App\Services\Logistique;

use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Remise;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Libellés lisibles de ceux qui donnent et reçoivent dans des remises (magasin, agent, transporteur,
 * destinataire), pour les écrans. Deux requêtes quel que soit le nombre de remises. Lecture seule.
 */
class LibellesRemises
{
    /**
     * @param  iterable<Remise>  $remises
     * @return Collection<int, array{de: string, a: string}> par identifiant de remise
     */
    public function pour(iterable $remises): Collection
    {
        $remises = collect($remises);
        $cotes = fn (string $type) => $remises->flatMap(fn (Remise $r) => [
            $r->de_type === $type ? $r->de_id : null, $r->a_type === $type ? $r->a_id : null,
        ])->filter()->unique()->all();

        $agents = User::whereIn('id', $cotes('agent'))->get()->keyBy('id');
        $destinataires = Destinataire::whereIn('id', $cotes('destinataire'))->pluck('libelle', 'id');

        $libelle = function (Remise $remise, string $cote) use ($agents, $destinataires): string {
            $id = $remise->{$cote.'_id'};

            return match ($remise->{$cote.'_type'}) {
                'magasin' => 'Magasin central',
                'agent' => $agents->get($id)?->nom_complet ?: 'Agent',
                'transporteur' => trim(($remise->transporteur_nom ?? 'Transporteur').($remise->transporteur_societe ? ' ('.$remise->transporteur_societe.')' : '')),
                default => (string) $destinataires->get($id, 'Destinataire'),
            };
        };

        return $remises->mapWithKeys(fn (Remise $r) => [$r->id => ['de' => $libelle($r, 'de'), 'a' => $libelle($r, 'a')]]);
    }
}

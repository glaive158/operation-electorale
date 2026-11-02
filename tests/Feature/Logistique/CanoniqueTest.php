<?php

namespace Tests\Feature\Logistique;

use App\Models\Logistique\Declaration;
use App\Support\Canonique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class CanoniqueTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    /** Algorithme du plan 4 (avant mutualisation), conservé ici comme référence. */
    private function ancienneEmpreinte(array $contenu): string
    {
        $canonique = function (mixed $valeur) use (&$canonique): mixed {
            if (! is_array($valeur)) {
                return $valeur;
            }
            $valeur = array_map(fn ($element) => $canonique($element), $valeur);
            if (! array_is_list($valeur)) {
                ksort($valeur);
            }

            return $valeur;
        };

        return hash('sha256', json_encode($canonique($contenu), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function test_empreinte_d_un_contenu_de_declaration_inchangee(): void
    {
        $contenu = [
            'remise_id' => 7, 'mode' => 'compte',
            'signataire' => ['nom' => 'Awa Diop', 'qualite' => 'Préfète'],
            'lignes' => [
                ['materiel_id' => 3, 'recu' => 10, 'defectueux' => 2, 'observation' => 'Caisse écrasée / vérifiée'],
                ['materiel_id' => 5, 'recu' => 4, 'defectueux' => 0, 'observation' => null],
            ],
        ];

        // Valeur calculée une fois avec l'algorithme du plan 4.
        $attendue = '84fe3f40e66519dbc3db78ab7a9a1d98ac4e97d88e9d9eb94a31b68373f631be';

        $this->assertSame($attendue, $this->ancienneEmpreinte($contenu));
        $this->assertSame($attendue, Canonique::empreinte($contenu));
    }

    public function test_declaration_enregistree_garde_l_empreinte_de_l_ancien_algorithme(): void
    {
        $this->creerDecorFictif();
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 20]]);
        $this->stockerFictif($this->urnes, 1000, 300);
        $chargee = $this->deposerChargee($this->prefecture, [$this->urnes->id => 20]);

        $declaration = $this->deposerRecue($chargee, [$this->urnes->id => ['recu' => 19, 'defectueux' => 1, 'observation' => 'Une urne fissurée / remplacée']]);
        $declaration = Declaration::with('lignes')->findOrFail($declaration->id);

        $contenu = [
            'remise_id' => $declaration->remise_id, 'mode' => $declaration->mode,
            'signataire' => ['nom' => $declaration->signataire_nom, 'qualite' => $declaration->signataire_qualite],
            'lignes' => $declaration->lignes->sortBy('materiel_id')->map(fn ($l) => [
                'materiel_id' => $l->materiel_id, 'recu' => $l->recu, 'defectueux' => $l->defectueux, 'observation' => $l->observation,
            ])->values()->all(),
        ];

        $this->assertSame($this->ancienneEmpreinte($contenu), $declaration->empreinte_contenu);
    }

    public function test_cles_triees_comme_des_chaines(): void
    {
        // "10" avant "9" : l'ordre des chaînes, pas l'ordre numérique de PHP.
        $this->assertSame('{"10":"x","9":"y","b":"z"}', Canonique::json([9 => 'y', 10 => 'x', 'b' => 'z']));
    }
}

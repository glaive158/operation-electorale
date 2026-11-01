<?php

namespace Tests\Unit\Logistique;

use App\Services\Logistique\Moteur\LocaliteCalcul;
use App\Services\Logistique\Moteur\MoteurDotation;
use App\Services\Logistique\Moteur\ParametresCle;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoteurDotationTest extends TestCase
{
    private function cle(array $o = []): ParametresCle
    {
        return new ParametresCle(
            base: $o['base'] ?? 'bureau_vote',
            ratio: $o['ratio'] ?? '1',
            majoration: $o['majoration'] ?? '0',
            minimum: $o['minimum'] ?? 0,
            minimumParCommune: $o['minimumParCommune'] ?? false,
            arrondi: $o['arrondi'] ?? 'unite',
            conditionnement: $o['conditionnement'] ?? null,
            parEntite: $o['parEntite'] ?? false,
            bulletin: $o['bulletin'] ?? false,
        );
    }

    private function loc(int $effectif, int $entites = 1, bool $couverte = true): LocaliteCalcul
    {
        return new LocaliteCalcul($effectif, $entites, $couverte);
    }

    public function test_bureau_vote_cle_simple(): void
    {
        $r = (new MoteurDotation)->doter($this->cle(), [$this->loc(31)], 1);

        $this->assertSame(31, $r->unites);
        $this->assertSame(31, $r->dotation);
    }

    public function test_majoration_dix_pour_cent(): void
    {
        $r = (new MoteurDotation)->doter(
            $this->cle(['ratio' => '17', 'majoration' => '10']),
            [$this->loc(255)],
            1,
        );

        $this->assertSame('4335.0000', $r->exact);
        $this->assertSame('4768.5000', $r->majore);
        $this->assertSame(4769, $r->dotation);
    }

    public function test_majoration_calculee_sans_troncature_avant_le_plafond(): void
    {
        // 9 999,0001 × 1,0001 = 10 000,00000001 : huit décimales, tronquées à six on perdrait une unité.
        $r = (new MoteurDotation)->doter(
            $this->cle(['base' => 'electeur', 'ratio' => '9999.0001', 'majoration' => '0.01']),
            [$this->loc(1)],
            1,
        );

        $this->assertSame('9999.0001', $r->exact);
        $this->assertSame('10000.0000', $r->majore);
        $this->assertSame(10001, $r->dotation);

        $parCommune = (new MoteurDotation)->doter(
            $this->cle(['base' => 'electeur', 'ratio' => '9999.0001', 'majoration' => '0.01', 'minimumParCommune' => true, 'minimum' => 1]),
            [$this->loc(1), $this->loc(1)],
            1,
        );
        $this->assertSame(20001, $parCommune->dotation);
    }

    public function test_aucune_erreur_de_flottant(): void
    {
        $moteur = new MoteurDotation;

        $this->assertSame(110, $moteur->doter($this->cle(['base' => 'electeur', 'ratio' => '1.1']), [$this->loc(100)], 1)->dotation);
        $this->assertSame(121, $moteur->doter($this->cle(['base' => 'electeur', 'ratio' => '1.1', 'majoration' => '10']), [$this->loc(100)], 1)->dotation);
        $this->assertSame(110, $moteur->doter($this->cle(['base' => 'electeur', 'ratio' => '1.1000', 'majoration' => '0.00']), [$this->loc(100)], 1)->dotation);
    }

    public function test_forfait_un_par_destinataire(): void
    {
        $r = (new MoteurDotation)->doter(
            $this->cle(['base' => 'forfait', 'ratio' => '10']),
            array_map(fn () => $this->loc(0), range(1, 5)),
            1,
        );

        $this->assertSame(1, $r->unites);
        $this->assertSame(10, $r->dotation);
    }

    public function test_par_entite_multiplie_par_entites_couvrantes(): void
    {
        $r = (new MoteurDotation)->doter(
            $this->cle(['base' => 'lieu_vote', 'parEntite' => true]),
            [$this->loc(3, 5), $this->loc(2, 4)],
            1,
        );

        $this->assertSame(23, $r->unites);
        $this->assertSame(23, $r->dotation);
    }

    public function test_circonscription_par_entite(): void
    {
        $r = (new MoteurDotation)->doter(
            $this->cle(['base' => 'circonscription', 'parEntite' => true]),
            [$this->loc(0)],
            5,
        );

        $this->assertSame(5, $r->dotation);
    }

    public function test_entites_couvrant_d_ignore_sans_par_entite(): void
    {
        $r = (new MoteurDotation)->doter($this->cle(['base' => 'forfait']), [$this->loc(0)], 7);

        $this->assertSame(1, $r->dotation);
    }

    public function test_bulletin_seulement_dans_le_perimetre(): void
    {
        $r = (new MoteurDotation)->doter(
            $this->cle(['base' => 'electeur', 'bulletin' => true]),
            [$this->loc(100, 1, true), $this->loc(50, 1, false)],
            1,
        );

        $this->assertSame(100, $r->unites);
        $this->assertSame(100, $r->dotation);
    }

    public function test_minimum_global(): void
    {
        $r = (new MoteurDotation)->doter($this->cle(['minimum' => 5]), [$this->loc(3)], 1);

        $this->assertSame(5, $r->dotation);
    }

    public function test_minimum_par_commune(): void
    {
        $localites = [$this->loc(1), $this->loc(1)];
        $moteur = new MoteurDotation;

        $this->assertSame(6, $moteur->doter($this->cle(['minimum' => 3, 'minimumParCommune' => true]), $localites, 1)->dotation);
        $this->assertSame(3, $moteur->doter($this->cle(['minimum' => 3]), $localites, 1)->dotation);
    }

    public function test_minimum_par_commune_ignore_les_localites_sans_effectif(): void
    {
        $r = (new MoteurDotation)->doter(
            $this->cle(['minimum' => 3, 'minimumParCommune' => true]),
            [$this->loc(1), $this->loc(0)],
            1,
        );

        $this->assertSame(3, $r->dotation);
    }

    public function test_minimum_par_commune_applique_la_majoration_par_commune(): void
    {
        // 2 localités de 10 : chacune 10 × 1,1 = 11 ; minimum 12 ⇒ 12 + 12.
        $r = (new MoteurDotation)->doter(
            $this->cle(['minimum' => 12, 'minimumParCommune' => true, 'majoration' => '10']),
            [$this->loc(10), $this->loc(10)],
            1,
        );

        $this->assertSame('20.0000', $r->exact);
        $this->assertSame('22.0000', $r->majore);
        $this->assertSame(24, $r->dotation);
    }

    public function test_arrondis_successifs(): void
    {
        $moteur = new MoteurDotation;
        $localites = [$this->loc(1012)]; // 1012 × 0,1 = 101,2

        $this->assertSame(110, $moteur->doter($this->cle(['ratio' => '0.1', 'arrondi' => 'dizaine']), $localites, 1)->dotation);
        $this->assertSame(150, $moteur->doter($this->cle(['ratio' => '0.1', 'arrondi' => 'cinquantaine']), $localites, 1)->dotation);
        $this->assertSame(200, $moteur->doter($this->cle(['ratio' => '0.1', 'arrondi' => 'centaine']), $localites, 1)->dotation);
        $this->assertSame(125, $moteur->doter($this->cle(['ratio' => '0.1', 'arrondi' => 'unite', 'conditionnement' => 25]), $localites, 1)->dotation);
    }

    public function test_une_valeur_deja_multiple_reste_inchangee(): void
    {
        $r = (new MoteurDotation)->doter(
            $this->cle(['arrondi' => 'centaine', 'conditionnement' => 25]),
            [$this->loc(200)],
            1,
        );

        $this->assertSame(200, $r->dotation);
    }

    public function test_effectif_nul_ne_cree_pas_de_besoin(): void
    {
        $moteur = new MoteurDotation;

        $this->assertSame(0, $moteur->doter($this->cle(['minimum' => 5]), [$this->loc(0)], 1)->dotation);
        $this->assertSame(0, $moteur->doter($this->cle(['minimum' => 5, 'minimumParCommune' => true]), [$this->loc(0)], 1)->dotation);
    }

    public function test_parametres_invalides_sont_refuses(): void
    {
        $cas = [
            ['base' => 'inconnue'],
            ['arrondi' => 'millier'],
            ['ratio' => '0'],
            ['ratio' => '-1'],
            ['ratio' => 'abc'],
            ['majoration' => '-1'],
            ['majoration' => '101'],
            ['majoration' => 'x'],
            ['minimum' => -1],
            ['conditionnement' => 0],
            ['conditionnement' => -5],
        ];

        foreach ($cas as $o) {
            try {
                $this->cle($o);
                $this->fail('Paramètres acceptés à tort : '.json_encode($o));
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_localite_invalide_est_refusee(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LocaliteCalcul(-1, 1, true);
    }

    public function test_entites_negatives_refusees(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LocaliteCalcul(1, -1, true);
    }
}

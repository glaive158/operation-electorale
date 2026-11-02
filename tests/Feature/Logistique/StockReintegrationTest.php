<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\MouvementStock;
use App\Models\Logistique\Restitution;
use App\Models\Logistique\RestitutionLigne;
use App\Models\User;
use App\Services\Logistique\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class StockReintegrationTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    private Cycle $cycle;

    private Materiel $urne;

    private RestitutionLigne $ligne;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stock = app(StockService::class);
        $this->actingAs(User::factory()->create(['role' => 'logistique_responsable']));

        $this->cycle = Cycle::create(['libelle' => 'Cycle A', 'type' => 'scrutin']);
        $this->urne = Materiel::create(['libelle' => 'Urne', 'categorie' => 'materiel_lourd']);
        $this->stock->inventaireInitial($this->urne, 100, 'Inventaire magasin');

        $restitution = (new Restitution(['cycle_id' => $this->cycle->id, 'detenteur_type' => 'destinataire', 'detenteur_id' => 1]))
            ->forceFill(['ouverte_par' => auth()->id()]);
        $restitution->save();
        $this->ligne = RestitutionLigne::create(['restitution_id' => $restitution->id, 'materiel_id' => $this->urne->id, 'attendu' => 50]);
    }

    public function test_reintegration_bon_augmente_le_libre(): void
    {
        $m = $this->stock->reintegrer($this->urne, 30, 'bon', $this->ligne, 'Retour contrôlé');

        $this->assertSame('reintegration', $m->type);
        $this->assertSame('bon', $m->etat);
        $this->assertSame(30, $m->quantite);
        $this->assertSame('restitution_lignes', $m->reference_type);
        $this->assertSame($this->ligne->id, $m->reference_id);
        $this->assertSame(130, $this->stock->physique($this->urne));
        $this->assertSame(130, $this->stock->libre($this->urne));
    }

    public function test_reintegration_a_reparer_hors_libre(): void
    {
        $this->stock->reintegrer($this->urne, 12, 'a_reparer', $this->ligne, 'Retour abîmé');

        $this->assertSame(12, $this->stock->physique($this->urne, 'a_reparer'));
        $this->assertSame(100, $this->stock->physique($this->urne));
        $this->assertSame(100, $this->stock->libre($this->urne));
    }

    public function test_reintegration_ne_demande_aucun_cycle_ouvert(): void
    {
        $this->cycle->forceFill(['statut' => 'clos', 'clos_le' => now()])->save();

        $this->stock->reintegrer($this->urne, 5, 'bon', $this->ligne, 'Retour après clôture');

        $this->assertSame(105, $this->stock->physique($this->urne));
    }

    public function test_reintegration_refuse_les_entrees_invalides(): void
    {
        $cas = [
            [0, 'bon', 'Motif'],
            [-3, 'bon', 'Motif'],
            [5, 'inconnu', 'Motif'],
            [5, 'bon', '  '],
        ];
        foreach ($cas as [$q, $etat, $motif]) {
            try {
                $this->stock->reintegrer($this->urne, $q, $etat, $this->ligne, $motif);
                $this->fail('Une InvalidArgumentException était attendue.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(1, MouvementStock::count());
    }

    public function test_reparation_par_reclassement(): void
    {
        $this->stock->reintegrer($this->urne, 20, 'a_reparer', $this->ligne, 'Retour abîmé');

        [$sortie, $entree] = $this->stock->reclasser($this->urne, 8, 'a_reparer', 'bon', 'Réparées');

        $this->assertSame('reclassement', $sortie->type);
        $this->assertSame('a_reparer', $sortie->etat);
        $this->assertSame(-8, $sortie->quantite);
        $this->assertSame('reclassement', $entree->type);
        $this->assertSame('bon', $entree->etat);
        $this->assertSame(8, $entree->quantite);
        $this->assertSame(12, $this->stock->physique($this->urne, 'a_reparer'));
        $this->assertSame(108, $this->stock->physique($this->urne));
        $this->assertSame(108, $this->stock->libre($this->urne));
        $this->assertSame(1, JournalEntree::where('action', 'reclassement')->count());
        $this->assertDatabaseHas('journal_logistique', ['objet_type' => 'mouvement_stock', 'objet_id' => $sortie->id, 'action' => 'reclassement']);
    }

    public function test_reclassement_depuis_a_reparer_limite_au_disponible(): void
    {
        $this->stock->reintegrer($this->urne, 5, 'a_reparer', $this->ligne, 'Retour abîmé');

        try {
            $this->stock->reclasser($this->urne, 6, 'a_reparer', 'bon', 'Réparées');
            $this->fail('Une RegleLogistique était attendue.');
        } catch (RegleLogistique $e) {
            $this->assertStringContainsString('Urne', $e->getMessage());
            $this->assertStringContainsString('5', $e->getMessage());
        }
        $this->assertSame(5, $this->stock->physique($this->urne, 'a_reparer'));
        $this->assertSame(100, $this->stock->physique($this->urne));
    }

    public function test_reclassement_respecte_l_engage(): void
    {
        $this->stock->reserver($this->cycle, $this->urne, 90);

        // libre = 10 : on peut sortir 10 du bon, pas 11.
        try {
            $this->stock->reclasser($this->urne, 11, 'bon', 'a_reparer', 'Casse constatée');
            $this->fail('Une RegleLogistique était attendue.');
        } catch (RegleLogistique $e) {
            $this->assertStringContainsString('Urne', $e->getMessage());
            $this->assertStringContainsString('10', $e->getMessage());
        }
        $this->assertSame(100, $this->stock->physique($this->urne));
        $this->assertSame(0, $this->stock->physique($this->urne, 'a_reparer'));
        $this->assertSame(1, MouvementStock::count());

        $this->stock->reclasser($this->urne, 10, 'bon', 'a_reparer', 'Casse constatée');
        $this->assertSame(90, $this->stock->physique($this->urne));
        $this->assertSame(0, $this->stock->libre($this->urne));
        $this->assertSame(10, $this->stock->physique($this->urne, 'a_reparer'));
    }

    public function test_reclassement_refuse_les_entrees_invalides(): void
    {
        $cas = [
            [0, 'bon', 'a_reparer', 'Motif'],
            [5, 'bon', 'bon', 'Motif'],
            [5, 'bon', 'inconnu', 'Motif'],
            [5, 'inconnu', 'bon', 'Motif'],
            [5, 'bon', 'a_reparer', ''],
        ];
        foreach ($cas as [$q, $de, $vers, $motif]) {
            try {
                $this->stock->reclasser($this->urne, $q, $de, $vers, $motif);
                $this->fail('Une InvalidArgumentException était attendue.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(1, MouvementStock::count());
    }

    public function test_reforme_reservee_au_responsable(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'logistique_gestionnaire']));

        try {
            $this->stock->reformer($this->urne, 5, 'bon', 'Hors d\'usage');
            $this->fail('Une RegleLogistique était attendue.');
        } catch (RegleLogistique $e) {
            $this->assertStringContainsString('responsable', $e->getMessage());
        }
        $this->assertSame(100, $this->stock->physique($this->urne));

        $this->actingAs(User::factory()->create(['role' => 'logistique_responsable']));
        $m = $this->stock->reformer($this->urne, 5, 'bon', 'Hors d\'usage');

        $this->assertSame('reforme', $m->type);
        $this->assertSame(-5, $m->quantite);
        $this->assertSame(95, $this->stock->physique($this->urne));
        $this->assertSame(1, JournalEntree::where('action', 'reforme')->count());
        $this->assertDatabaseHas('journal_logistique', ['objet_type' => 'mouvement_stock', 'objet_id' => $m->id, 'action' => 'reforme']);
    }

    public function test_reforme_a_reparer_et_respect_de_l_engage(): void
    {
        $this->stock->reintegrer($this->urne, 7, 'a_reparer', $this->ligne, 'Retour abîmé');
        $this->stock->reformer($this->urne, 7, 'a_reparer', 'Irréparables');
        $this->assertSame(0, $this->stock->physique($this->urne, 'a_reparer'));

        $this->stock->reserver($this->cycle, $this->urne, 95);
        $this->expectException(RegleLogistique::class);
        $this->stock->reformer($this->urne, 6, 'bon', 'Hors d\'usage');
    }

    public function test_reforme_exige_un_motif_et_une_quantite_positive(): void
    {
        foreach ([[0, 'Motif'], [3, ' ']] as [$q, $motif]) {
            try {
                $this->stock->reformer($this->urne, $q, 'bon', $motif);
                $this->fail('Une InvalidArgumentException était attendue.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(1, MouvementStock::count());
    }

    public function test_reclassement_reserve_a_qui_gere_le_magasin(): void
    {
        $this->stock->reintegrer($this->urne, 10, 'a_reparer', $this->ligne, 'Retour abîmé');
        $this->actingAs(User::factory()->create(['role' => 'prefet']));

        try {
            $this->stock->reclasser($this->urne, 4, 'a_reparer', 'bon', 'Réparées');
            $this->fail('Une RegleLogistique était attendue.');
        } catch (RegleLogistique $e) {
            $this->assertStringContainsString('droit', $e->getMessage());
        }
        $this->assertSame(10, $this->stock->physique($this->urne, 'a_reparer'));

        $this->actingAs(User::factory()->create(['role' => 'logistique_gestionnaire']));
        $this->stock->reclasser($this->urne, 4, 'a_reparer', 'bon', 'Réparées');
        $this->assertSame(104, $this->stock->physique($this->urne));
    }

    public function test_l_attribue_protege_le_bon_de_la_reforme_et_du_reclassement(): void
    {
        $this->stock->attribuer($this->cycle, $this->urne, 95, 'Dotation');
        $this->assertSame(100, $this->stock->libre($this->urne));

        foreach ([
            fn () => $this->stock->reformer($this->urne, 6, 'bon', 'Hors d\'usage'),
            fn () => $this->stock->reclasser($this->urne, 6, 'bon', 'a_reparer', 'Casse constatée'),
        ] as $mouvement) {
            try {
                $mouvement();
                $this->fail('Une RegleLogistique était attendue.');
            } catch (RegleLogistique) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(100, $this->stock->physique($this->urne));
        $this->assertSame(0, $this->stock->physique($this->urne, 'a_reparer'));

        $this->stock->reformer($this->urne, 5, 'bon', 'Hors d\'usage');
        $this->assertSame(95, $this->stock->physique($this->urne));
    }

    public function test_reforme_permise_a_l_administrateur(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->stock->reformer($this->urne, 3, 'bon', 'Hors d\'usage');

        $this->assertSame(97, $this->stock->physique($this->urne));
    }

    public function test_mouvements_sans_auteur_refuses(): void
    {
        auth()->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->assertNull(auth()->id());

        foreach ([
            fn () => $this->stock->reintegrer($this->urne, 3, 'bon', $this->ligne, 'Retour'),
            fn () => $this->stock->reclasser($this->urne, 3, 'bon', 'a_reparer', 'Casse'),
            fn () => $this->stock->reformer($this->urne, 3, 'bon', 'Hors d\'usage'),
        ] as $mouvement) {
            try {
                $mouvement();
                $this->fail('Une LogicException était attendue.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(1, MouvementStock::count());
    }
}

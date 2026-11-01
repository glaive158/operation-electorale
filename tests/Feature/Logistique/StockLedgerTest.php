<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Exceptions\Logistique\StockInsuffisant;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\MouvementStock;
use App\Models\User;
use App\Services\Logistique\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class StockLedgerTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stock = app(StockService::class);
    }

    private function urne(): Materiel
    {
        return Materiel::create(['libelle' => 'Urne', 'categorie' => 'materiel_lourd']);
    }

    public function test_inventaire_initial_augmente_le_physique(): void
    {
        $urne = $this->urne();
        $this->stock->inventaireInitial($urne, 120, 'Inventaire magasin');

        $this->assertSame(120, $this->stock->physique($urne));
    }

    public function test_quantite_nulle_ou_negative_refusee_a_l_inventaire(): void
    {
        $urne = $this->urne();
        foreach ([0, -5] as $q) {
            try {
                $this->stock->inventaireInitial($urne, $q, 'Inventaire');
                $this->fail('Une InvalidArgumentException était attendue.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, MouvementStock::count());
    }

    public function test_mouvement_non_modifiable_ni_supprimable(): void
    {
        $urne = $this->urne();
        $m = $this->stock->inventaireInitial($urne, 10, 'Inventaire');

        try {
            $m->update(['quantite' => 1]);
            $this->fail('Une LogicException était attendue.');
        } catch (LogicException $e) {
            $this->assertSame('Un mouvement de stock ne se modifie pas : passer un mouvement compensatoire.', $e->getMessage());
        }
        $this->expectException(LogicException::class);
        $m->delete();
    }

    public function test_compensation_annule_le_mouvement(): void
    {
        $urne = $this->urne();
        $a = $this->stock->inventaireInitial($urne, 120, 'Inventaire');

        $c = $this->stock->compenser($a, 'Erreur de saisie');

        $this->assertSame(0, $this->stock->physique($urne));
        $this->assertSame('compensation', $c->type);
        $this->assertSame(-120, $c->quantite);
        $this->assertSame($a->id, $c->mouvement_compense_id);
    }

    public function test_double_compensation_refusee(): void
    {
        $urne = $this->urne();
        $a = $this->stock->inventaireInitial($urne, 120, 'Inventaire');
        $c = $this->stock->compenser($a, 'Erreur');

        try {
            $this->stock->compenser($a, 'Encore');
            $this->fail('Une RegleLogistique était attendue.');
        } catch (RegleLogistique) {
            $this->assertTrue(true);
        }
        $this->expectException(RegleLogistique::class);
        $this->stock->compenser($c, 'Compenser la compensation');
    }

    public function test_compensation_qui_rendrait_le_stock_negatif_refusee(): void
    {
        $urne = $this->urne();
        $a = $this->stock->inventaireInitial($urne, 100, 'Inventaire');
        $this->stock->enregistrer($urne, 'sortie_expedition', -80);
        $this->assertSame(20, $this->stock->physique($urne));

        try {
            $this->stock->compenser($a, 'Erreur');
            $this->fail('Un StockInsuffisant était attendu.');
        } catch (StockInsuffisant) {
            $this->assertSame(20, $this->stock->physique($urne));
            $this->assertSame(2, MouvementStock::count());
        }
    }

    public function test_stock_insuffisant_est_une_regle_logistique(): void
    {
        $this->assertInstanceOf(RegleLogistique::class, new StockInsuffisant('x'));
    }

    public function test_sortie_superieure_au_stock_refusee(): void
    {
        $urne = $this->urne();
        $this->stock->inventaireInitial($urne, 10, 'Inventaire');

        $this->expectException(StockInsuffisant::class);
        $this->stock->enregistrer($urne, 'sortie_expedition', -11);
    }

    public function test_quantite_nulle_refusee_a_l_enregistrement(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->stock->enregistrer($this->urne(), 'reintegration', 0);
    }

    public function test_stock_a_reparer_hors_du_physique_bon(): void
    {
        $urne = $this->urne();
        $this->stock->enregistrer($urne, 'reintegration', 5, null, null, 'a_reparer');

        $this->assertSame(0, $this->stock->physique($urne));
        $this->assertSame(5, $this->stock->physique($urne, 'a_reparer'));
    }

    public function test_journal_trace_la_compensation(): void
    {
        $urne = $this->urne();
        $a = $this->stock->inventaireInitial($urne, 120, 'Inventaire');
        $user = User::factory()->create(['role' => 'logistique_responsable']);

        $this->actingAs($user);
        $this->stock->compenser($a, 'Erreur de saisie');

        $this->assertDatabaseHas('journal_logistique', [
            'objet_type' => 'mouvement_stock',
            'objet_id'   => $a->id,
            'action'     => 'compensation',
            'motif'      => 'Erreur de saisie',
            'user_id'    => $user->id,
        ]);
    }

    public function test_l_auteur_du_mouvement_est_l_utilisateur_connecte(): void
    {
        $user = User::factory()->create(['role' => 'logistique_gestionnaire']);
        $this->actingAs($user);

        $m = $this->stock->inventaireInitial($this->urne(), 3, 'Inventaire');

        $this->assertSame($user->id, $m->user_id);
    }

    public function test_les_referentiels_sont_journalises(): void
    {
        $r = User::factory()->create(['role' => 'logistique_responsable']);
        $this->actingAs($r)->post(route('logistique.materiels.store'), [
            'libelle' => 'Isoloir', 'categorie' => 'materiel_lourd',
        ])->assertRedirect();
        $materiel = Materiel::firstWhere('libelle', 'Isoloir');

        $this->actingAs($r)->put(route('logistique.materiels.update', $materiel), [
            'libelle' => 'Isoloir pliant', 'categorie' => 'materiel_lourd',
        ])->assertRedirect();

        $this->assertDatabaseHas('journal_logistique', [
            'objet_type' => 'materiel', 'objet_id' => $materiel->id, 'action' => 'creation', 'user_id' => $r->id,
        ]);
        $entree = JournalEntree::where('objet_type', 'materiel')->where('action', 'modification')->firstOrFail();
        $this->assertSame('Isoloir', $entree->avant['libelle']);
        $this->assertSame('Isoloir pliant', $entree->apres['libelle']);

        $this->actingAs($r)->post(route('logistique.cycles.store'), [
            'libelle' => 'Révision 2027', 'type' => 'revision',
            'majoration_distribuee' => 0, 'reserve_centrale' => 0, 'circuit_defaut' => 'direct',
        ])->assertRedirect();
        $this->assertDatabaseHas('journal_logistique', ['objet_type' => 'cycle', 'action' => 'creation']);
    }
}

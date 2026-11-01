<?php

namespace Tests\Feature\Logistique;

use App\Models\Logistique\Cycle;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\JournalEntree;
use App\Models\User;
use App\Services\Logistique\CommandeService;
use App\Services\Logistique\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferentielsTest extends TestCase
{
    use RefreshDatabase;

    private function donneesCycle(array $surcharge = []): array
    {
        return array_merge([
            'libelle' => 'Présidentielle 2027', 'type' => 'scrutin', 'date_scrutin' => '2027-02-28',
            'majoration_distribuee' => 10, 'reserve_centrale' => 0, 'circuit_defaut' => 'via_prefet',
        ], $surcharge);
    }

    public function test_responsable_cree_un_cycle(): void
    {
        $r = User::factory()->create(['role' => 'logistique_responsable']);
        $this->actingAs($r)->post(route('logistique.cycles.store'), $this->donneesCycle())
            ->assertRedirect(route('logistique.cycles.index'));
        $c = Cycle::firstWhere('libelle', 'Présidentielle 2027');
        $this->assertSame('ouvert', $c->statut);
        $this->assertTrue($c->estOuvert());
        $this->assertEquals(10.00, (float) $c->majoration_distribuee);
        $this->assertSame(1, Cycle::ouverts()->count());
    }

    public function test_gestionnaire_ne_cree_pas_de_cycle(): void
    {
        $g = User::factory()->create(['role' => 'logistique_gestionnaire']);
        $this->actingAs($g)->post(route('logistique.cycles.store'), $this->donneesCycle())->assertForbidden();
        $this->actingAs($g)->get(route('logistique.cycles.create'))->assertForbidden();
        $this->assertSame(0, Cycle::count());
    }

    public function test_gestionnaire_consulte_les_cycles(): void
    {
        $g = User::factory()->create(['role' => 'logistique_gestionnaire']);
        Cycle::create(['libelle' => 'Révision 2026', 'type' => 'revision']);
        $this->actingAs($g)->get(route('logistique.cycles.index'))->assertOk()->assertSee('Révision 2026');
    }

    public function test_responsable_modifie_un_cycle(): void
    {
        $r = User::factory()->create(['role' => 'logistique_responsable']);
        $c = Cycle::create(['libelle' => 'Ancien', 'type' => 'scrutin']);
        $this->actingAs($r)->put(route('logistique.cycles.update', $c), $this->donneesCycle(['libelle' => 'Nouveau', 'reserve_centrale' => 5]))
            ->assertRedirect(route('logistique.cycles.index'));
        $this->assertSame('Nouveau', $c->fresh()->libelle);
        $this->assertEquals(5.0, (float) $c->fresh()->reserve_centrale);
    }

    public function test_majoration_hors_bornes_refusee(): void
    {
        $r = User::factory()->create(['role' => 'logistique_responsable']);
        $this->actingAs($r)->post(route('logistique.cycles.store'), $this->donneesCycle(['majoration_distribuee' => 150]))
            ->assertSessionHasErrors('majoration_distribuee');
        $this->actingAs($r)->post(route('logistique.cycles.store'), $this->donneesCycle(['majoration_distribuee' => -1]))
            ->assertSessionHasErrors('majoration_distribuee');
        $this->assertSame(0, Cycle::count());
    }

    public function test_gestionnaire_cree_un_materiel(): void
    {
        $g = User::factory()->create(['role' => 'logistique_gestionnaire']);
        $this->actingAs($g)->post(route('logistique.materiels.store'), [
            'libelle' => 'Urne', 'categorie' => 'materiel_lourd', 'reutilisable' => 1, 'unite_stock' => 'pièce',
        ])->assertRedirect(route('logistique.materiels.index'));
        $this->assertDatabaseHas('materiels', ['libelle' => 'Urne', 'reutilisable' => true, 'actif' => true, 'indivisible' => true]);
    }

    public function test_unite_distribution_exige_un_facteur(): void
    {
        $g = User::factory()->create(['role' => 'logistique_gestionnaire']);
        $base = ['libelle' => 'Enveloppe de vote', 'categorie' => 'imprime', 'unite_distribution' => 'paquet'];
        $this->actingAs($g)->post(route('logistique.materiels.store'), $base)
            ->assertSessionHasErrors('facteur_conditionnement');
        $this->actingAs($g)->post(route('logistique.materiels.store'), $base + ['facteur_conditionnement' => 1])
            ->assertSessionHasErrors('facteur_conditionnement');
        $this->actingAs($g)->post(route('logistique.materiels.store'), $base + ['facteur_conditionnement' => 100])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('materiels', ['libelle' => 'Enveloppe de vote', 'facteur_conditionnement' => 100]);
    }

    public function test_materiel_modifie_et_desactive(): void
    {
        $g = User::factory()->create(['role' => 'logistique_gestionnaire']);
        $m = Materiel::create(['libelle' => 'Isoloir', 'categorie' => 'materiel_lourd']);
        $this->actingAs($g)->put(route('logistique.materiels.update', $m), [
            'libelle' => 'Isoloir pliant', 'categorie' => 'materiel_lourd', 'unite_stock' => 'pièce', 'actif' => 0,
        ])->assertRedirect(route('logistique.materiels.index'));
        $m->refresh();
        $this->assertSame('Isoloir pliant', $m->libelle);
        $this->assertFalse($m->actif);
    }

    public function test_materiel_desactive_absent_des_actifs(): void
    {
        $g = User::factory()->create(['role' => 'logistique_gestionnaire']);
        Materiel::create(['libelle' => 'Ancien modèle', 'categorie' => 'imprime', 'actif' => false]);
        $this->assertSame(0, Materiel::actifs()->count());
        $this->actingAs($g)->get(route('logistique.materiels.index'))->assertOk()->assertDontSee('Ancien modèle');
        $this->actingAs($g)->get(route('logistique.materiels.index', ['inactifs' => 1]))->assertOk()->assertSee('Ancien modèle');
    }

    public function test_aucune_route_de_suppression(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('logistique.materiels.destroy'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('logistique.cycles.destroy'));
    }

    public function test_acces_refuse_hors_logistique(): void
    {
        $p = User::factory()->create(['role' => 'prefet']);
        $this->actingAs($p)->get(route('logistique.materiels.index'))->assertForbidden();
        $this->actingAs($p)->get(route('logistique.cycles.index'))->assertForbidden();
    }

    public function test_formulaires_s_affichent(): void
    {
        $r = User::factory()->create(['role' => 'logistique_responsable']);
        $c = Cycle::create(['libelle' => 'Cycle A', 'type' => 'scrutin', 'reserve_centrale' => 100]);
        $m = Materiel::create(['libelle' => 'Article A', 'categorie' => 'imprime']);
        $this->actingAs($r);
        $this->get(route('logistique.cycles.create'))->assertOk()->assertSee('Majoration distribuée');
        $this->get(route('logistique.cycles.edit', $c))->assertOk()->assertSee('Cycle A');
        $this->get(route('logistique.cycles.index'))->assertOk()->assertSee('100 %');
        $this->get(route('logistique.materiels.create'))->assertOk()->assertSee('Facteur de conditionnement');
        $this->get(route('logistique.materiels.edit', $m))->assertOk()->assertSee('Article A')->assertSee('Article actif');
    }

    public function test_ordre_et_facteur_bornes(): void
    {
        $g = User::factory()->create(['role' => 'logistique_gestionnaire']);
        $this->actingAs($g);
        $base = ['libelle' => 'Enveloppe', 'categorie' => 'imprime', 'unite_distribution' => 'paquet'];

        $this->post(route('logistique.materiels.store'), $base + ['facteur_conditionnement' => 10, 'ordre' => 65536])
            ->assertSessionHasErrors('ordre');
        $this->post(route('logistique.materiels.store'), $base + ['facteur_conditionnement' => 1000001, 'ordre' => 1])
            ->assertSessionHasErrors('facteur_conditionnement');
        $this->assertSame(0, Materiel::count());

        $this->post(route('logistique.materiels.store'), $base + ['facteur_conditionnement' => 1000000, 'ordre' => 65535])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Materiel::count());

        $m = Materiel::firstOrFail();
        $this->put(route('logistique.materiels.update', $m), $base + ['facteur_conditionnement' => 10, 'ordre' => 65536])
            ->assertSessionHasErrors('ordre');
    }

    public function test_creation_sans_trace_au_journal_ne_laisse_aucune_ecriture(): void
    {
        $r = User::factory()->create(['role' => 'logistique_responsable']);
        $this->actingAs($r)->withoutExceptionHandling();
        JournalEntree::creating(function () {
            throw new \RuntimeException('Journal indisponible');
        });

        try {
            $this->post(route('logistique.cycles.store'), $this->donneesCycle());
            $this->fail('Une exception était attendue.');
        } catch (\RuntimeException) {
            $this->assertSame(0, Cycle::count());
        }

        try {
            $this->post(route('logistique.materiels.store'), ['libelle' => 'Urne', 'categorie' => 'materiel_lourd']);
            $this->fail('Une exception était attendue.');
        } catch (\RuntimeException) {
            $this->assertSame(0, Materiel::count());
        }

        $c = Cycle::create(['libelle' => 'Cycle A', 'type' => 'scrutin']);
        $m = Materiel::create(['libelle' => 'Urne', 'categorie' => 'materiel_lourd']);
        try {
            $this->put(route('logistique.cycles.update', $c), $this->donneesCycle(['libelle' => 'Cycle B']));
            $this->fail('Une exception était attendue.');
        } catch (\RuntimeException) {
            $this->assertSame('Cycle A', $c->fresh()->libelle);
        }
        try {
            $this->put(route('logistique.materiels.update', $m), ['libelle' => 'Urne bis', 'categorie' => 'materiel_lourd']);
            $this->fail('Une exception était attendue.');
        } catch (\RuntimeException) {
            $this->assertSame('Urne', $m->fresh()->libelle);
        }
    }

    public function test_modification_d_un_cycle_clos_refusee(): void
    {
        $r = User::factory()->create(['role' => 'logistique_responsable']);
        $this->actingAs($r);
        $c = Cycle::create(['libelle' => 'Cycle A', 'type' => 'scrutin']);
        app(StockService::class)->cloturerCycle($c, 'Fin');

        $this->put(route('logistique.cycles.update', $c), $this->donneesCycle(['libelle' => 'Cycle B']))
            ->assertRedirect(route('logistique.cycles.index'))
            ->assertSessionHas('error', 'Cycle clôturé : modification impossible.');
        $this->assertSame('Cycle A', $c->fresh()->libelle);
    }

    /**
     * @return array<string, \Closure(Materiel, StockService): void>
     */
    private function engagements(): array
    {
        return [
            'physique bon' => fn (Materiel $m, StockService $s) => $s->inventaireInitial($m, 5, 'Inventaire'),
            'à réparer' => fn (Materiel $m, StockService $s) => $s->enregistrer($m, 'reintegration', 5, null, 'Retour', 'a_reparer'),
            'réservé' => function (Materiel $m, StockService $s) {
                $s->inventaireInitial($m, 5, 'Inventaire');
                $s->reserver(Cycle::create(['libelle' => 'A', 'type' => 'scrutin']), $m, 5);
            },
            'attribué' => function (Materiel $m, StockService $s) {
                $s->inventaireInitial($m, 5, 'Inventaire');
                $s->attribuer(Cycle::create(['libelle' => 'A', 'type' => 'scrutin']), $m, 5, 'Attribution');
            },
            'commandé à recevoir' => function (Materiel $m) {
                $commandes = app(CommandeService::class);
                $commandes->confirmer($commandes->creer(
                    ['reference' => 'CMD-D', 'fournisseur' => 'F', 'date_commande' => '2026-11-01'],
                    [['materiel_id' => $m->id, 'quantite_commandee' => 5]],
                ));
            },
        ];
    }

    public function test_desactivation_refusee_tant_que_l_article_est_en_stock_ou_engage(): void
    {
        $g = User::factory()->create(['role' => 'logistique_gestionnaire']);
        $this->actingAs($g);

        foreach ($this->engagements() as $nom => $engager) {
            $m = Materiel::create(['libelle' => "Article {$nom}", 'categorie' => 'imprime']);
            $engager($m, app(StockService::class));

            $this->put(route('logistique.materiels.update', $m), [
                'libelle' => $m->libelle, 'categorie' => 'imprime', 'actif' => 0,
            ])->assertRedirect()->assertSessionHas('error', 'Article encore en stock ou engagé : désactivation impossible.');

            $this->assertTrue($m->fresh()->actif, "Désactivation acceptée à tort ({$nom}).");
        }
    }

    public function test_desactivation_autorisee_sans_stock_ni_engagement(): void
    {
        $g = User::factory()->create(['role' => 'logistique_gestionnaire']);
        $this->actingAs($g);
        $m = Materiel::create(['libelle' => 'Isoloir', 'categorie' => 'materiel_lourd']);

        $this->put(route('logistique.materiels.update', $m), [
            'libelle' => 'Isoloir', 'categorie' => 'materiel_lourd', 'actif' => 0,
        ])->assertRedirect(route('logistique.materiels.index'));
        $this->assertFalse($m->fresh()->actif);
    }
}

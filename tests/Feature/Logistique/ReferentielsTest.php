<?php

namespace Tests\Feature\Logistique;

use App\Models\Logistique\Cycle;
use App\Models\Logistique\Materiel;
use App\Models\User;
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
}

<?php

namespace Tests\Feature\Logistique;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AccesLogistiqueTest extends TestCase
{
    use RefreshDatabase;

    public function test_connexions_de_test_isolees(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame('sqlite', config('database.connections.recensement.driver'));
        $this->assertSame(':memory:', config('database.connections.recensement.database'));
    }

    public function test_gates_logistique(): void
    {
        $g = User::factory()->create(['role' => 'logistique_gestionnaire']);
        $r = User::factory()->create(['role' => 'logistique_responsable']);
        $p = User::factory()->create(['role' => 'prefet']);
        $this->assertTrue(Gate::forUser($g)->allows('logistique-gerer'));
        $this->assertFalse(Gate::forUser($g)->allows('logistique-administrer'));
        $this->assertTrue(Gate::forUser($r)->allows('logistique-administrer'));
        $this->assertFalse(Gate::forUser($p)->allows('logistique-gerer'));
    }

    public function test_admin_cree_un_compte_logistique(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Diop', 'prenom' => 'Awa', 'email' => 'awa.diop@example.test',
            'password' => 'MotDePasse!2026',
            'role' => 'logistique_responsable',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'awa.diop@example.test', 'role' => 'logistique_responsable']);
    }

    public function test_compte_logistique_tenu_hors_des_pages_existantes(): void
    {
        $g = User::factory()->create(['role' => 'logistique_gestionnaire']);
        $this->actingAs($g)->get('/')->assertRedirect(route('logistique.stock.index'));
        $this->actingAs($g)->get(route('operations.index'))->assertForbidden();
        $this->actingAs($g)->get('/geo/region/1/departements')->assertForbidden();
        $this->actingAs($g)->get(route('fichier-electoral'))->assertForbidden();
    }

    public function test_prefet_et_commission_refuses_sur_logistique(): void
    {
        foreach (['prefet', 'commission', 'sous_prefet'] as $role) {
            $u = User::factory()->create(['role' => $role]);
            $this->actingAs($u)->get(route('logistique.stock.index'))->assertForbidden();
        }
    }

    public function test_roles_logistique_et_admin_accedent_au_stock(): void
    {
        foreach (['logistique_gestionnaire', 'logistique_responsable', 'admin'] as $role) {
            $u = User::factory()->create(['role' => $role]);
            $this->actingAs($u)->get(route('logistique.stock.index'))
                ->assertOk()
                ->assertSee('Stock central');
        }
    }
}

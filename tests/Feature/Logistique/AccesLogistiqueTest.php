<?php

namespace Tests\Feature\Logistique;

use App\Models\Logistique\Cycle;
use App\Models\Logistique\Materiel;
use App\Models\User;
use App\Services\Logistique\CommandeService;
use App\Services\Logistique\Journal;
use App\Services\Logistique\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AccesLogistiqueTest extends TestCase
{
    use RefreshDatabase;

    public function test_connexions_de_test_isolees(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame('sqlite', config('database.connections.sqlite.driver'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
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

    /**
     * Chaque opération logistique laisse une trace imputable à son auteur.
     *
     * @return array<string, \Closure(User): void>
     */
    private function operationsLogistiques(): array
    {
        $stock = fn () => app(StockService::class);
        $materiel = fn () => Materiel::create(['libelle' => 'Urne', 'categorie' => 'materiel_lourd']);

        return [
            'mouvements_stock' => fn () => $stock()->inventaireInitial($materiel(), 10, 'Inventaire'),
            'reservations_stock' => function () use ($stock, $materiel) {
                $m = $materiel();
                $stock()->inventaireInitial($m, 10, 'Inventaire');
                $stock()->reserver(Cycle::create(['libelle' => 'A', 'type' => 'scrutin']), $m, 5);
            },
            'journal_logistique' => fn () => Journal::noter('materiel', 1, 'modification'),
            'commandes' => fn () => app(CommandeService::class)->creer(
                ['reference' => 'CMD-U', 'fournisseur' => 'F', 'date_commande' => '2026-11-01'],
                [['materiel_id' => $materiel()->id, 'quantite_commandee' => 5]],
            ),
            'receptions_fournisseur' => function () use ($materiel) {
                $commandes = app(CommandeService::class);
                $commande = $commandes->creer(
                    ['reference' => 'CMD-R', 'fournisseur' => 'F', 'date_commande' => '2026-11-01'],
                    [['materiel_id' => $materiel()->id, 'quantite_commandee' => 5]],
                );
                $commandes->confirmer($commande);
                $commandes->recevoir($commande, ['date_reception' => '2026-11-10', 'bon_livraison' => 'BL'], [
                    $commande->lignes()->first()->id => ['acceptee' => 0, 'rejetee' => 5],
                ]);
            },
        ];
    }

    public function test_suppression_d_un_utilisateur_lie_a_des_operations_logistiques_refusee(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        foreach ($this->operationsLogistiques() as $table => $operation) {
            $auteur = User::factory()->create(['role' => 'logistique_responsable']);
            $this->actingAs($auteur);
            $operation();

            $reponse = $this->actingAs($admin)->delete(route('users.destroy', $auteur));

            $reponse->assertRedirect()->assertSessionHas(
                'error',
                'Utilisateur lié à des opérations logistiques : désactivez le compte au lieu de le supprimer.',
            );
            $this->assertDatabaseHas('users', ['id' => $auteur->id]);
            $this->assertTrue(
                DB::table($table)->where('user_id', $auteur->id)->exists(),
                "Aucune ligne de {$table} pour l'auteur : le scénario ne teste pas cette table.",
            );
        }
    }

    public function test_utilisateur_sans_operation_logistique_reste_supprimable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $libre = User::factory()->create(['role' => 'logistique_gestionnaire']);

        $this->actingAs($admin)->delete(route('users.destroy', $libre))
            ->assertRedirect(route('users.index'))->assertSessionHas('success');
        $this->assertDatabaseMissing('users', ['id' => $libre->id]);
    }

    public function test_compte_logistique_n_affiche_pas_toutes_zones(): void
    {
        foreach (['logistique_gestionnaire', 'logistique_responsable'] as $role) {
            $this->assertSame('Logistique centrale', User::factory()->make(['role' => $role])->zone_label);
        }
        $this->assertSame('Toutes zones', User::factory()->make(['role' => 'admin'])->zone_label);
    }
}

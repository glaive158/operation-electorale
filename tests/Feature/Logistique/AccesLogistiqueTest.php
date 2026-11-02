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
use Illuminate\Support\Facades\Route;
use RuntimeException;
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

    public function test_retour_arriere_de_la_migration_des_roles_refuse_avec_des_comptes_logistique(): void
    {
        User::factory()->create(['role' => 'logistique_responsable']);
        $migration = require base_path('database/migrations/2026_11_01_100001_add_logistique_roles_to_users.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('des comptes logistique existent');
        $migration->down();
    }

    public function test_compte_logistique_ferme_a_toutes_les_pages_existantes(): void
    {
        $exclues = ['login', 'logout', 'dashboard', 'profile'];
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods(), true))
            ->filter(fn ($r) => $r->getName() !== null)
            // Pages de l'application seulement : pas les routes du framework (sanctum, storage, up...).
            ->filter(fn ($r) => str_starts_with($r->getActionName(), 'App\\Http\\Controllers\\'))
            ->reject(fn ($r) => str_starts_with($r->getName(), 'logistique.'))
            ->reject(fn ($r) => in_array($r->getName(), $exclues, true) || str_starts_with($r->getName(), 'profile.'))
            // Script, manifeste et icône de l'application de terrain : fichiers publics, pas des pages.
            ->reject(fn ($r) => str_starts_with($r->getName(), 'pwa.'))
            ->filter(fn ($r) => $r->parameterNames() === [])
            ->values();

        $this->assertGreaterThan(8, $routes->count(), 'Le parcours des routes existantes est vide : le filtre est faux.');

        foreach (['logistique_gestionnaire', 'logistique_responsable'] as $role) {
            $compte = User::factory()->create(['role' => $role]);
            foreach ($routes as $route) {
                $statut = $this->actingAs($compte)->get($route->uri())->getStatusCode();
                $this->assertTrue(
                    $statut === 403 || ($statut >= 300 && $statut < 400),
                    "{$role} atteint {$route->getName()} (HTTP {$statut}).",
                );
            }
        }
    }

    public function test_tableau_de_bord_dirige_les_comptes_logistique_vers_le_stock(): void
    {
        $g = User::factory()->create(['role' => 'logistique_gestionnaire']);
        $this->actingAs($g)->get(route('dashboard'))->assertRedirect(route('logistique.stock.index'));
        $this->actingAs($g)->get('/dashboard')->assertRedirect(route('logistique.stock.index'));
    }

    public function test_pages_existantes_inchangees_pour_un_prefet(): void
    {
        $p = User::factory()->create(['role' => 'prefet']);
        $this->actingAs($p)->get(route('operations.index'))->assertOk();
        $this->actingAs($p)->get(route('dashboard'))->assertOk();
    }
}

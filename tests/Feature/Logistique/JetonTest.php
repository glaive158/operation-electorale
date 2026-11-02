<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class JetonTest extends TestCase
{
    use RefreshDatabase;

    private function agent(string $role = 'agent_transport', array $extra = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'password' => 'motdepasse123'], $extra));
    }

    private function demander(User $user, string $motDePasse = 'motdepasse123')
    {
        return $this->postJson('/api/terrain/jetons', [
            'email' => $user->email,
            'password' => $motDePasse,
            'appareil' => 'Tablette 1',
        ]);
    }

    public function test_jeton_pour_un_agent_actif(): void
    {
        $agent = $this->agent();

        $reponse = $this->demander($agent)->assertCreated()
            ->assertJsonPath('utilisateur.id', $agent->id)
            ->assertJsonPath('utilisateur.role', 'agent_transport')
            ->assertJsonStructure(['token', 'utilisateur' => ['id', 'nom', 'role'], 'expire_le']);

        $jeton = PersonalAccessToken::findToken($reponse->json('token'));
        $this->assertNotNull($jeton);
        $this->assertSame(['terrain'], $jeton->abilities);
        $this->assertSame('Tablette 1', $jeton->name);
        $this->assertTrue($jeton->expires_at->isSameDay(now()->addDays(30)));
        $this->assertGuest('web');
    }

    public function test_jeton_refuse_au_gestionnaire(): void
    {
        foreach ([
            $this->agent('logistique_gestionnaire'),
            $this->agent('admin'),
            $this->agent('gouverneur'),
            $this->agent('prefet', ['actif' => false]),
        ] as $refuse) {
            $this->demander($refuse)->assertForbidden()->assertExactJson(['message' => 'Accès terrain refusé.']);
        }

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_identifiants_faux_422(): void
    {
        $agent = $this->agent();

        $this->demander($agent, 'mauvais')->assertStatus(422)->assertExactJson(['message' => 'Identifiants invalides.']);
        $this->postJson('/api/terrain/jetons', ['email' => 'inconnu@exemple.sn', 'password' => 'x', 'appareil' => 'T'])
            ->assertStatus(422)->assertExactJson(['message' => 'Identifiants invalides.']);
    }

    public function test_jeton_expire_401(): void
    {
        $agent = $this->agent();
        $jeton = $agent->createToken('Tablette', ['terrain'], now()->subMinute());

        $this->withToken($jeton->plainTextToken)->deleteJson('/api/terrain/jetons/courant')->assertUnauthorized();
        $this->deleteJson('/api/terrain/jetons/courant')->assertUnauthorized();
    }

    public function test_revocation_du_jeton(): void
    {
        $agent = $this->agent();
        $jeton = $agent->createToken('Tablette', ['terrain'], now()->addDay());
        $autre = $agent->createToken('Autre', ['terrain'], now()->addDay());

        $this->withToken($jeton->plainTextToken)->deleteJson('/api/terrain/jetons/courant')->assertNoContent();

        $this->assertNull(PersonalAccessToken::findToken($jeton->plainTextToken));
        $this->assertNotNull(PersonalAccessToken::findToken($autre->plainTextToken));
    }

    public function test_jeton_sans_la_capacite_terrain_refuse(): void
    {
        $agent = $this->agent();
        $jeton = $agent->createToken('Autre usage', ['lecture'], now()->addDay());

        $this->withToken($jeton->plainTextToken)->deleteJson('/api/terrain/jetons/courant')->assertForbidden();
    }

    public function test_regle_metier_rendue_en_422_json(): void
    {
        Route::middleware('api')->get('api/terrain/_test-regle', fn () => throw new RegleLogistique('Cycle clos.'));
        Route::middleware('api')->get('api/terrain/_test-argument', fn () => throw new \InvalidArgumentException('Quantité invalide.'));
        Route::middleware('web')->get('_test-regle-web', fn () => throw new RegleLogistique('Cycle clos.'));

        $this->get('/api/terrain/_test-regle')->assertStatus(422)->assertExactJson(['message' => 'Cycle clos.']);
        $this->get('/api/terrain/_test-argument')->assertStatus(422)->assertExactJson(['message' => 'Quantité invalide.']);
        // Le web n'est pas modifié : l'exception reste une erreur serveur.
        $this->get('/_test-regle-web')->assertStatus(500);
    }

    public function test_limitation_de_debit_sur_la_creation_de_jeton(): void
    {
        $agent = $this->agent();

        for ($i = 0; $i < 5; $i++) {
            $this->demander($agent, 'mauvais')->assertStatus(422);
        }

        $this->demander($agent)->assertStatus(429);
    }
}

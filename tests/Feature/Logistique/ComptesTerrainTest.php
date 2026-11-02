<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\JournalEntree;
use App\Models\User;
use App\Services\Logistique\DecoupageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecensementFictif;
use Tests\TestCase;

class ComptesTerrainTest extends TestCase
{
    use RefreshDatabase;

    private function donnees(string $role, string $email): array
    {
        return ['name' => 'Diallo', 'prenom' => 'Fatou', 'email' => $email, 'password' => 'motdepasse123', 'role' => $role];
    }

    public function test_l_administrateur_cree_et_modifie_un_chef_de_poste_et_un_agent_de_transport(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        foreach (['chef_poste', 'agent_transport'] as $role) {
            $this->post(route('users.store'), $this->donnees($role, "{$role}@exemple.sn"))
                ->assertRedirect(route('users.index'))->assertSessionHasNoErrors();
            $compte = User::where('email', "{$role}@exemple.sn")->firstOrFail();
            $this->assertSame($role, $compte->role);

            $this->put(route('users.update', $compte), ['name' => 'Diallo Bis', 'email' => $compte->email, 'role' => $role])
                ->assertRedirect(route('users.index'))->assertSessionHasNoErrors();
            $this->assertSame('Diallo Bis', $compte->fresh()->name);
        }

        $this->post(route('users.store'), $this->donnees('chef_de_gare', 'x@exemple.sn'))->assertSessionHasErrors('role');
    }

    public function test_le_responsable_assigne_l_autorite_d_un_destinataire_selon_son_type(): void
    {
        RecensementFictif::creerSchema();
        $responsable = User::factory()->create(['role' => 'logistique_responsable']);
        $this->actingAs($responsable);
        $poste = app(DecoupageService::class)->creerPoste(['pays' => 'France', 'ville' => 'Paris', 'type' => 'consulat', 'nom' => 'Consulat de Paris']);
        app(DecoupageService::class)->genererDestinataires();
        $destinataire = Destinataire::where('poste_etranger_id', $poste->id)->firstOrFail();
        $sousPrefecture = Destinataire::create(['type' => 'sous_prefecture', 'libelle' => 'Sous-préfecture de Sindia']);
        $chef = User::factory()->create(['role' => 'chef_poste']);
        $sousPrefet = User::factory()->create(['role' => 'sous_prefet']);
        $inactif = User::factory()->create(['role' => 'chef_poste', 'actif' => false]);

        // Écran : seuls les comptes actifs du rôle correspondant sont proposés.
        $this->get(route('logistique.destinataires.index'))->assertOk()
            ->assertSee('value="'.$chef->id.'"', false)->assertSee('value="'.$sousPrefet->id.'"', false)
            ->assertDontSee('value="'.$inactif->id.'"', false);

        $this->put(route('logistique.destinataires.update', $destinataire), ['actif' => '1', 'autorite_user_id' => (string) $chef->id])
            ->assertSessionHas('success');
        $this->assertSame($chef->id, (int) $destinataire->fresh()->autorite_user_id);
        $entree = JournalEntree::where('objet_type', 'destinataire')->where('action', 'modification_destinataire')->sole();
        $this->assertSame($chef->id, $entree->apres['autorite_user_id']);
        $this->assertNull($entree->avant['autorite_user_id']);

        // Rôle qui ne correspond pas au type, compte inactif, compte inconnu : refus, rien ne change.
        $this->put(route('logistique.destinataires.update', $destinataire), ['actif' => '1', 'autorite_user_id' => (string) $sousPrefet->id])
            ->assertSessionHas('error');
        $this->put(route('logistique.destinataires.update', $destinataire), ['actif' => '1', 'autorite_user_id' => (string) $inactif->id])
            ->assertSessionHas('error');
        $this->put(route('logistique.destinataires.update', $destinataire), ['actif' => '1', 'autorite_user_id' => '99999'])
            ->assertSessionHasErrors('autorite_user_id');
        $this->put(route('logistique.destinataires.update', $sousPrefecture), ['actif' => '1', 'autorite_user_id' => (string) $chef->id])
            ->assertSessionHas('error');
        $this->assertSame($chef->id, (int) $destinataire->fresh()->autorite_user_id);
        $this->assertNull($sousPrefecture->fresh()->autorite_user_id);

        // Champ absent : inchangé ; champ vide : retiré.
        $this->put(route('logistique.destinataires.update', $destinataire), ['actif' => '1', 'signataire_nom' => 'M. Ba'])->assertSessionHas('success');
        $this->assertSame($chef->id, (int) $destinataire->fresh()->autorite_user_id);
        $this->put(route('logistique.destinataires.update', $sousPrefecture), ['actif' => '1', 'autorite_user_id' => (string) $sousPrefet->id])->assertSessionHas('success');
        $this->put(route('logistique.destinataires.update', $destinataire), ['actif' => '1', 'autorite_user_id' => ''])->assertSessionHas('success');
        $this->assertNull($destinataire->fresh()->autorite_user_id);
        $this->assertSame($sousPrefet->id, (int) $sousPrefecture->fresh()->autorite_user_id);
    }

    public function test_service_refuse_un_gestionnaire_et_un_role_incorrect(): void
    {
        $destinataire = Destinataire::create(['type' => 'prefecture', 'libelle' => 'Préfecture de Thiès']);
        $prefet = User::factory()->create(['role' => 'prefet']);
        $service = app(DecoupageService::class);

        $this->actingAs(User::factory()->create(['role' => 'logistique_gestionnaire']));
        try {
            $service->modifierDestinataire($destinataire, ['autorite_user_id' => $prefet->id]);
            $this->fail('Un gestionnaire ne peut pas assigner une autorité.');
        } catch (RegleLogistique) {
            $this->addToAssertionCount(1);
        }

        $this->actingAs(User::factory()->create(['role' => 'logistique_responsable']));
        try {
            $service->modifierDestinataire($destinataire, ['autorite_user_id' => User::factory()->create(['role' => 'sous_prefet'])->id]);
            $this->fail('Le rôle doit correspondre au type.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Le compte de l\'autorité de Préfecture de Thiès doit avoir le rôle préfet.', $e->getMessage());
        }

        $service->modifierDestinataire($destinataire, ['autorite_user_id' => $prefet->id]);
        $this->assertSame($prefet->id, (int) $destinataire->fresh()->autorite_user_id);
    }
}

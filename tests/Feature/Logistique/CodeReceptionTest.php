<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\CodeReception;
use App\Models\Logistique\JournalEntree;
use App\Models\NotificationOp;
use App\Models\User;
use App\Services\Logistique\CodeReceptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class CodeReceptionTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = '482915';

    private function autorite(string $role = 'chef_poste', array $attributs = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'doit_choisir_code' => true], $attributs));
    }

    private function service(): CodeReceptionService
    {
        return app(CodeReceptionService::class);
    }

    private function formulaire(array $surcharge = []): array
    {
        return array_merge([
            'password' => 'password',
            'nouveau_mot_de_passe' => 'NouveauSecret2026',
            'nouveau_mot_de_passe_confirmation' => 'NouveauSecret2026',
            'code' => self::CODE,
            'code_confirmation' => self::CODE,
        ], $surcharge);
    }

    public function test_premier_acces_impose_le_choix_du_code(): void
    {
        $chef = $this->autorite();
        $this->actingAs($chef);

        $this->get(route('terrain.decharges.index'))->assertRedirect(route('terrain.code.edit'));
        $this->get(route('terrain.code.edit'))->assertOk()->assertSee('Choisir mon code de réception');

        $this->post(route('terrain.code.update'), $this->formulaire())
            ->assertRedirect(route('terrain.decharges.index'))->assertSessionHas('success');

        $this->assertFalse($chef->fresh()->doit_choisir_code);
        $this->get(route('terrain.decharges.index'))->assertOk();
        $this->assertTrue(Hash::check('NouveauSecret2026', $chef->fresh()->password));
    }

    public function test_le_choix_du_code_ne_bloque_ni_le_suivi_ni_les_autres_comptes(): void
    {
        // Le suivi du périmètre (hors du groupe terrain) et les comptes sans obligation ne sont pas redirigés.
        $this->actingAs($this->autorite('prefet'));
        $this->get(route('terrain.suivi'))->assertOk();

        $agent = User::factory()->create(['role' => 'agent_transport']);
        $this->assertFalse($agent->fresh()->doit_choisir_code);
        $this->actingAs($agent)->get(route('terrain.decharges.index'))->assertOk();

        // Un compte hors espace terrain n'accède pas à la page du code.
        $this->actingAs(User::factory()->create(['role' => 'commission']))->get(route('terrain.code.edit'))->assertForbidden();
    }

    public function test_choisir_un_code(): void
    {
        $chef = $this->autorite();
        $this->actingAs($chef);
        $avant = now();

        $this->service()->choisir($chef, self::CODE, 'password');

        $chef->refresh();
        $this->assertNotSame(self::CODE, $chef->code_reception_hash);
        $this->assertTrue($this->service()->correspond($chef, self::CODE, $chef->code_reception_hash));
        $this->assertFalse($this->service()->correspond($chef, '730184', $chef->code_reception_hash));
        $this->assertFalse($chef->doit_choisir_code);
        $this->assertSame(0, (int) $chef->code_echecs);
        $this->assertNull($chef->code_verrouille_le);
        $historique = CodeReception::where('user_id', $chef->id)->get();
        $this->assertCount(1, $historique);
        $this->assertSame($chef->code_reception_hash, $historique[0]->hash);
        $this->assertNull($historique[0]->valide_au);
        $this->assertSame($chef->id, (int) $historique[0]->cree_par);
        $this->assertTrue($historique[0]->valide_du->greaterThanOrEqualTo($avant->copy()->startOfSecond()));
        $this->assertSame($chef->code_reception_hash, $this->service()->codeActuel($chef));

        // Un nouveau choix clôt l'entrée précédente et en ouvre une autre.
        $this->service()->choisir($chef, '730184', 'password');
        $historique = CodeReception::where('user_id', $chef->id)->orderBy('id')->get();
        $this->assertCount(2, $historique);
        $this->assertNotNull($historique[0]->valide_au);
        $this->assertNull($historique[1]->valide_au);
        $this->assertTrue($this->service()->correspond($chef, '730184', $chef->fresh()->code_reception_hash));

        $entree = JournalEntree::where('action', 'choix_code_reception')->orderBy('id')->first();
        $this->assertSame('utilisateur', $entree->objet_type);
        $this->assertSame($chef->id, (int) $entree->objet_id);
        $this->assertSame($chef->id, (int) $entree->user_id);
    }

    public function test_code_trop_simple_refuse(): void
    {
        $chef = $this->autorite();
        $this->actingAs($chef);

        foreach (['000000', '111111', '555555', '999999', '123456', '654321'] as $trivial) {
            try {
                $this->service()->choisir($chef, $trivial, 'password');
                $this->fail("Le code {$trivial} aurait dû être refusé.");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Code trop simple.', $e->getMessage());
            }
        }
        // Ce ne sont pas des codes triviaux : 121212, 123123, 234567 passent.
        $this->service()->choisir($chef, '121212', 'password');
        $this->service()->choisir($chef, '234567', 'password');

        foreach (['12345', '1234567', 'abcdef', '12 456', '', '48291a'] as $invalide) {
            try {
                $this->service()->choisir($chef, $invalide, 'password');
                $this->fail("Le code « {$invalide} » aurait dû être refusé.");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Le code doit comporter exactement 6 chiffres.', $e->getMessage());
            }
        }
        $this->assertSame(2, CodeReception::where('user_id', $chef->id)->count());
    }

    public function test_le_code_differe_du_mot_de_passe_actuel_et_du_nouveau(): void
    {
        $chef = $this->autorite('chef_poste', ['password' => Hash::make('482915')]);
        $this->actingAs($chef);

        try {
            $this->service()->choisir($chef, '482915', '482915');
            $this->fail('Le code identique au mot de passe actuel aurait dû être refusé.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Le code doit être différent du mot de passe.', $e->getMessage());
        }
        try {
            $this->service()->choisir($chef, '730184', '482915', '730184');
            $this->fail('Le code identique au nouveau mot de passe aurait dû être refusé.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Le code doit être différent du mot de passe.', $e->getMessage());
        }
        $this->assertNull($chef->fresh()->code_reception_hash);
        $this->assertTrue(Hash::check('482915', $chef->fresh()->password));

        // Par l'écran : même refus, sans rien enregistrer.
        $this->post(route('terrain.code.update'), $this->formulaire([
            'password' => '482915', 'code' => '482915', 'code_confirmation' => '482915',
        ]))->assertSessionHas('error', 'Le code doit être différent du mot de passe.');
        $this->assertNull($chef->fresh()->code_reception_hash);
    }

    public function test_mauvais_mot_de_passe_refuse(): void
    {
        $chef = $this->autorite();
        $this->actingAs($chef);

        try {
            $this->service()->choisir($chef, self::CODE, 'incorrect');
            $this->fail('Le mot de passe incorrect aurait dû être refusé.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Mot de passe incorrect.', $e->getMessage());
        }
        $this->post(route('terrain.code.update'), $this->formulaire(['password' => 'incorrect']))
            ->assertSessionHas('error', 'Mot de passe incorrect.');

        $chef->refresh();
        $this->assertNull($chef->code_reception_hash);
        $this->assertTrue($chef->doit_choisir_code);
        $this->assertSame(0, CodeReception::count());
    }

    public function test_seul_le_titulaire_choisit_son_code(): void
    {
        $chef = $this->autorite();

        try {
            $this->service()->choisir($chef, self::CODE, 'password');
            $this->fail('Une opération sans auteur aurait dû être refusée.');
        } catch (LogicException $e) {
            $this->assertSame('Opération sans auteur.', $e->getMessage());
        }

        $this->actingAs($this->autorite('sous_prefet'));
        $this->expectException(RegleLogistique::class);
        $this->service()->choisir($chef, self::CODE, 'password');
    }

    public function test_les_champs_de_l_ecran_sont_controles(): void
    {
        $chef = $this->autorite();
        $this->actingAs($chef);

        $this->post(route('terrain.code.update'), $this->formulaire(['code_confirmation' => '111222']))->assertSessionHasErrors('code');
        $this->post(route('terrain.code.update'), $this->formulaire(['code' => '12345', 'code_confirmation' => '12345']))->assertSessionHasErrors('code');
        $this->post(route('terrain.code.update'), $this->formulaire(['nouveau_mot_de_passe' => 'court', 'nouveau_mot_de_passe_confirmation' => 'court']))
            ->assertSessionHasErrors('nouveau_mot_de_passe');
        $this->post(route('terrain.code.update'), $this->formulaire(['nouveau_mot_de_passe_confirmation' => 'autre']))->assertSessionHasErrors('nouveau_mot_de_passe');
        // Au premier accès, le nouveau mot de passe est obligatoire.
        $this->post(route('terrain.code.update'), $this->formulaire(['nouveau_mot_de_passe' => '', 'nouveau_mot_de_passe_confirmation' => '']))
            ->assertSessionHasErrors('nouveau_mot_de_passe');
        $this->assertNull($chef->fresh()->code_reception_hash);
    }

    public function test_code_jamais_en_clair(): void
    {
        $chef = $this->autorite();
        $this->actingAs($chef);

        // Refus (mot de passe incorrect) puis succès : le code ne reste ni en session, ni dans les pages rendues.
        $refus = $this->post(route('terrain.code.update'), $this->formulaire(['password' => 'incorrect']));
        $refus->assertRedirect();
        $this->assertStringNotContainsString(self::CODE, json_encode(session()->all()));
        $this->assertStringNotContainsString(self::CODE, $this->get(route('terrain.code.edit'))->getContent());

        $succes = $this->post(route('terrain.code.update'), $this->formulaire());
        $this->assertStringNotContainsString(self::CODE, (string) $succes->getContent());
        $this->assertStringNotContainsString(self::CODE, json_encode(session()->all()));
        $this->assertStringNotContainsString(self::CODE, $this->get(route('terrain.decharges.index'))->getContent());

        // En base : ni sur le compte, ni dans l'historique, ni dans le journal.
        $compte = json_encode(DB::table('users')->where('id', $chef->id)->first());
        $this->assertStringNotContainsString(self::CODE, $compte);
        $this->assertStringNotContainsString(self::CODE, json_encode(DB::table('codes_reception')->get()));
        $this->assertStringNotContainsString(self::CODE, json_encode(DB::table('journal_logistique')->get(['objet_type', 'objet_id', 'action', 'avant', 'apres', 'motif'])));
        $this->assertStringNotContainsString('NouveauSecret2026', json_encode(DB::table('journal_logistique')->get(['avant', 'apres', 'motif'])));

        // Jamais renvoyé par la sérialisation du compte.
        $this->assertArrayNotHasKey('code_reception_hash', $chef->fresh()->toArray());
    }

    public function test_reinitialisation_par_le_responsable(): void
    {
        $chef = $this->autorite();
        $this->actingAs($chef);
        $this->service()->choisir($chef, self::CODE, 'password');
        $chef->forceFill(['code_echecs' => 3, 'code_verrouille_le' => now()])->save();

        $responsable = User::factory()->create(['role' => 'logistique_responsable']);
        $this->actingAs($responsable);
        $this->get(route('logistique.utilisateurs.codes'))->assertOk()->assertSee($chef->name);

        // Le motif est obligatoire.
        $this->post(route('logistique.utilisateurs.code.reinitialiser', $chef), ['motif' => ''])->assertSessionHasErrors('motif');
        $this->assertNotNull($chef->fresh()->code_reception_hash);

        $this->post(route('logistique.utilisateurs.code.reinitialiser', $chef), ['motif' => 'Code oublié par le chef de poste'])
            ->assertSessionHas('success');

        $chef->refresh();
        $this->assertNull($chef->code_reception_hash);
        $this->assertTrue($chef->doit_choisir_code);
        $this->assertSame(0, (int) $chef->code_echecs);
        $this->assertNull($chef->code_verrouille_le);
        $this->assertNotNull(CodeReception::where('user_id', $chef->id)->sole()->valide_au);
        $entree = JournalEntree::where('action', 'reinitialisation_code_reception')->sole();
        $this->assertSame($chef->id, (int) $entree->objet_id);
        $this->assertSame($responsable->id, (int) $entree->user_id);
        $this->assertSame('Code oublié par le chef de poste', $entree->motif);

        // L'autorité est de nouveau tenue de choisir son code.
        $this->actingAs($chef)->get(route('terrain.decharges.index'))->assertRedirect(route('terrain.code.edit'));
    }

    public function test_la_reinitialisation_est_reservee_et_limitee_aux_autorites(): void
    {
        $chef = $this->autorite('chef_poste', ['doit_choisir_code' => false, 'code_reception_hash' => Hash::make(self::CODE)]);

        foreach (['logistique_gestionnaire', 'commission', 'chef_poste'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->post(route('logistique.utilisateurs.code.reinitialiser', $chef), ['motif' => 'Essai'])->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'logistique_gestionnaire']))->get(route('logistique.utilisateurs.codes'))->assertForbidden();
        $this->assertNotNull($chef->fresh()->code_reception_hash);

        // Un agent de transport n'a pas de code de réception à réinitialiser.
        $agent = User::factory()->create(['role' => 'agent_transport']);
        $this->actingAs(User::factory()->create(['role' => 'logistique_responsable']));
        $this->post(route('logistique.utilisateurs.code.reinitialiser', $agent), ['motif' => 'Essai'])->assertSessionHas('error');
        $this->assertFalse($agent->fresh()->doit_choisir_code);

        // Au niveau du service : droit revérifié.
        $this->actingAs(User::factory()->create(['role' => 'logistique_gestionnaire']));
        $this->expectException(RegleLogistique::class);
        $this->service()->reinitialiser($chef, 'Essai');
    }

    public function test_historique_des_codes(): void
    {
        $this->travelTo(now()->startOfSecond());
        $chef = $this->autorite();
        $this->actingAs($chef);
        $this->assertNull($this->service()->codeValideA($chef, now()));

        $this->service()->choisir($chef, self::CODE, 'password');
        $ancien = $chef->fresh()->code_reception_hash;
        $instantAncien = now()->copy();

        $this->travel(2)->hours();
        $this->actingAs(User::factory()->create(['role' => 'logistique_responsable']));
        $this->service()->reinitialiser($chef, 'Changement de titulaire');
        $instantReinit = now()->copy();

        $this->travel(1)->hours();
        $this->actingAs($chef);
        $this->service()->choisir($chef, '730184', 'password');
        $nouveau = $chef->fresh()->code_reception_hash;
        $this->assertNotSame($ancien, $nouveau);

        // Avant le premier choix : rien. Pendant : l'ancien. Après la réinitialisation et avant le nouveau choix : rien.
        $this->assertNull($this->service()->codeValideA($chef, $instantAncien->copy()->subSecond()));
        $this->assertSame($ancien, $this->service()->codeValideA($chef, $instantAncien->copy()->addHour()));
        $this->assertSame($ancien, $this->service()->codeValideA($chef, $instantReinit->copy()->subSecond()));
        $this->assertNull($this->service()->codeValideA($chef, $instantReinit->copy()->addMinutes(30)));
        $this->assertSame($nouveau, $this->service()->codeValideA($chef, now()));
        $this->assertSame($nouveau, $this->service()->codeActuel($chef));
        $this->assertSame(2, CodeReception::where('user_id', $chef->id)->count());
    }

    public function test_trois_echecs_verrouillent_et_alertent(): void
    {
        $chef = $this->autorite('chef_poste', ['name' => 'Ndiaye', 'prenom' => 'Awa']);
        $responsable = User::factory()->create(['role' => 'logistique_responsable']);
        $autreResponsable = User::factory()->create(['role' => 'logistique_responsable']);
        $inactif = User::factory()->create(['role' => 'logistique_responsable', 'actif' => false]);
        $gestionnaire = User::factory()->create(['role' => 'logistique_gestionnaire']);
        $this->actingAs($chef);

        $this->service()->enregistrerEchec($chef);
        $this->service()->enregistrerEchec($chef);
        $chef->refresh();
        $this->assertSame(2, (int) $chef->code_echecs);
        $this->assertNull($chef->code_verrouille_le);
        $this->assertSame(0, NotificationOp::where('type', 'logistique_alerte_code')->count());

        $this->service()->enregistrerEchec($chef);
        $chef->refresh();
        $this->assertSame(3, (int) $chef->code_echecs);
        $this->assertNotNull($chef->code_verrouille_le);

        $alertes = NotificationOp::where('type', 'logistique_alerte_code')->get();
        $this->assertEqualsCanonicalizing([$responsable->id, $autreResponsable->id], $alertes->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertStringContainsString('Awa Ndiaye', $alertes->first()->message);
        $this->assertFalse($alertes->first()->lue);
        $this->assertNull($alertes->first()->operation_id);

        // Un échec de plus ne renvoie pas d'alerte.
        $this->service()->enregistrerEchec($chef);
        $this->assertSame(2, NotificationOp::where('type', 'logistique_alerte_code')->count());
        $this->assertSame(0, NotificationOp::where('user_id', $gestionnaire->id)->count());
        $this->assertSame(0, NotificationOp::where('user_id', $inactif->id)->count());
    }

    public function test_changement_de_role_reserve_au_responsable(): void
    {
        $chef = $this->autorite('chef_poste', ['doit_choisir_code' => false, 'code_reception_hash' => Hash::make(self::CODE)]);

        foreach (['gestionnaire' => 'logistique_gestionnaire', 'prefet' => 'prefet', 'commission' => 'commission'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            try {
                $this->service()->apresChangementDeRole($chef);
                $this->fail('Changement accepté pour '.$role);
            } catch (RegleLogistique $e) {
                $this->assertSame('Seul le responsable logistique peut changer le rôle d\'un compte et annuler son code de réception.', $e->getMessage());
            }
        }
        $this->assertNotNull($chef->fresh()->code_reception_hash);

        $this->actingAs(User::factory()->create(['role' => 'logistique_responsable']));
        $this->service()->apresChangementDeRole($chef);
        $this->assertNull($chef->fresh()->code_reception_hash);
    }

    public function test_doit_choisir_code_pose_par_les_ecrans_d_utilisateurs(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $donnees = fn (string $role, string $email) => [
            'name' => 'Diallo', 'prenom' => 'Fatou', 'email' => $email, 'password' => 'motdepasse123', 'role' => $role,
        ];

        // Création : exigé pour une autorité, pas pour les autres rôles (agent de transport compris).
        foreach (['prefet', 'sous_prefet', 'chef_poste'] as $role) {
            $this->post(route('users.store'), $donnees($role, "{$role}@exemple.sn"))->assertSessionHasNoErrors();
            $this->assertTrue(User::where('email', "{$role}@exemple.sn")->sole()->doit_choisir_code, $role);
        }
        foreach (['agent_transport', 'commission', 'logistique_gestionnaire'] as $role) {
            $this->post(route('users.store'), $donnees($role, "{$role}@exemple.sn"))->assertSessionHasNoErrors();
            $this->assertFalse(User::where('email', "{$role}@exemple.sn")->sole()->doit_choisir_code, $role);
        }

        // Modification : exigé quand le rôle change vers une autorité, pas autrement.
        $commission = User::where('email', 'commission@exemple.sn')->sole();
        $this->put(route('users.update', $commission), ['name' => 'Diallo', 'email' => $commission->email, 'role' => 'sous_prefet'])->assertSessionHasNoErrors();
        $this->assertTrue($commission->fresh()->doit_choisir_code);

        $chef = User::where('email', 'chef_poste@exemple.sn')->sole();
        $chef->forceFill(['doit_choisir_code' => false])->save();
        $this->put(route('users.update', $chef), ['name' => 'Diallo Bis', 'email' => $chef->email, 'role' => 'chef_poste'])->assertSessionHasNoErrors();
        $this->assertFalse($chef->fresh()->doit_choisir_code);

        $this->put(route('users.update', $chef), ['name' => 'Diallo Bis', 'email' => $chef->email, 'role' => 'prefet'])->assertSessionHasNoErrors();
        $this->assertTrue($chef->fresh()->doit_choisir_code);

        $agent = User::where('email', 'agent_transport@exemple.sn')->sole();
        $this->put(route('users.update', $agent), ['name' => 'Diallo', 'email' => $agent->email, 'role' => 'logistique_gestionnaire'])->assertSessionHasNoErrors();
        $this->assertFalse($agent->fresh()->doit_choisir_code);
    }

    public function test_l_agent_de_transport_n_a_pas_de_page_de_code(): void
    {
        $agent = User::factory()->create(['role' => 'agent_transport']);
        $this->actingAs($agent);

        $this->get(route('terrain.code.edit'))->assertForbidden();
        $this->post(route('terrain.code.update'), $this->formulaire())->assertForbidden();
        $this->assertNull($agent->fresh()->code_reception_hash);

        // Au niveau du service aussi.
        try {
            $this->service()->choisir($agent, self::CODE, 'password');
            $this->fail('Un agent de transport ne choisit pas de code.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Ce compte n\'a pas de code de réception.', $e->getMessage());
        }
        $this->expectException(LogicException::class);
        $this->service()->enregistrerEchec($agent);
    }

    public function test_le_nouveau_mot_de_passe_trop_court_est_refuse_par_le_service(): void
    {
        $chef = $this->autorite();
        $this->actingAs($chef);

        try {
            $this->service()->choisir($chef, self::CODE, 'password', 'court');
            $this->fail('Un mot de passe de moins de 10 caractères aurait dû être refusé.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Le nouveau mot de passe doit comporter au moins 10 caractères.', $e->getMessage());
        }
        $this->assertNull($chef->fresh()->code_reception_hash);
        $this->assertTrue(Hash::check('password', $chef->fresh()->password));
    }

    public function test_code_jamais_en_clair_apres_un_echec_de_validation(): void
    {
        $chef = $this->autorite();
        $this->actingAs($chef);

        $this->post(route('terrain.code.update'), $this->formulaire(['code_confirmation' => '111222']))
            ->assertSessionHasErrors('code');

        $this->assertStringNotContainsString(self::CODE, json_encode(session()->all()));
        $this->assertStringNotContainsString('111222', json_encode(session()->all()));
        $this->assertStringNotContainsString('NouveauSecret2026', json_encode(session()->all()));
        $this->assertStringNotContainsString(self::CODE, json_encode(session()->get('errors')?->getBag('default')->toArray() ?? []));
        $this->assertStringNotContainsString(self::CODE, $this->get(route('terrain.code.edit'))->getContent());
    }

    public function test_le_middleware_repond_en_json_a_une_requete_json(): void
    {
        $this->actingAs($this->autorite());

        $this->getJson(route('terrain.decharges.index'))->assertForbidden()
            ->assertExactJson(['message' => 'Choisissez d\'abord votre code personnel de réception.']);
    }

    public function test_un_compte_qui_n_est_plus_autorite_n_est_plus_redirige(): void
    {
        $chef = $this->autorite();
        $this->actingAs($chef);
        $this->service()->choisir($chef, self::CODE, 'password');

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->put(route('users.update', $chef), ['name' => 'Diallo', 'email' => $chef->email, 'role' => 'agent_transport', 'actif' => '1'])
            ->assertSessionHasNoErrors();

        $chef->refresh();
        $this->assertSame('agent_transport', $chef->role);
        $this->assertNull($chef->code_reception_hash);
        $this->assertFalse($chef->doit_choisir_code);
        $this->assertSame(0, (int) $chef->code_echecs);
        $this->assertNotNull(CodeReception::where('user_id', $chef->id)->sole()->valide_au);
        $this->assertSame(1, JournalEntree::where('action', 'changement_role_code_reception')->count());

        $this->actingAs($chef)->get(route('terrain.decharges.index'))->assertOk();

        // Même un indicateur resté à vrai ne bloque pas un compte sans rôle d'autorité.
        $chef->forceFill(['doit_choisir_code' => true])->save();
        $this->get(route('terrain.decharges.index'))->assertOk();
    }

    public function test_un_changement_de_role_annule_l_ancien_code(): void
    {
        $chef = $this->autorite();
        $this->actingAs($chef);
        $this->service()->choisir($chef, self::CODE, 'password');
        $chef->forceFill(['code_echecs' => 2])->save();
        $this->assertNotNull($this->service()->codeActuel($chef));

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->put(route('users.update', $chef), ['name' => 'Diallo', 'email' => $chef->email, 'role' => 'prefet'])->assertSessionHasNoErrors();

        $chef->refresh();
        $this->assertSame('prefet', $chef->role);
        $this->assertNull($this->service()->codeActuel($chef));
        $this->assertTrue($chef->doit_choisir_code);
        $this->assertSame(0, (int) $chef->code_echecs);
        $this->assertNull($chef->code_verrouille_le);
        $this->assertNull(CodeReception::where('user_id', $chef->id)->whereNull('valide_au')->first());

        // L'ancien code reste vérifiable à l'instant où il était valide, jamais après.
        $this->assertNull($this->service()->codeValideA($chef, now()->addMinute()));

        // Une modification sans changement de rôle laisse le code en place.
        $this->actingAs($chef);
        $this->service()->choisir($chef, '730184', 'password');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->put(route('users.update', $chef), ['name' => 'Diallo Bis', 'email' => $chef->email, 'role' => 'prefet'])->assertSessionHasNoErrors();
        $this->assertNotNull($this->service()->codeActuel($chef));
        $this->assertFalse($chef->fresh()->doit_choisir_code);
    }

    public function test_un_compte_verrouille_ne_choisit_pas_de_code_avant_la_reinitialisation(): void
    {
        $chef = $this->autorite();
        $this->actingAs($chef);
        $this->service()->choisir($chef, self::CODE, 'password');
        $chef->forceFill(['code_echecs' => 3, 'code_verrouille_le' => now()])->save();
        $ancien = $chef->fresh()->code_reception_hash;

        try {
            $this->service()->choisir($chef, '730184', 'password');
            $this->fail('Un compte verrouillé ne choisit pas de code.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Code verrouillé : demandez une réinitialisation au responsable logistique.', $e->getMessage());
        }
        $this->assertSame($ancien, $chef->fresh()->code_reception_hash);
        $this->assertNotNull($chef->fresh()->code_verrouille_le);

        $this->actingAs(User::factory()->create(['role' => 'logistique_responsable']));
        $this->service()->reinitialiser($chef, 'Compte verrouillé');

        $this->actingAs($chef);
        $this->service()->choisir($chef, '730184', 'password');
        $chef->refresh();
        $this->assertNull($chef->code_verrouille_le);
        $this->assertTrue($this->service()->correspond($chef, '730184', $chef->code_reception_hash));
    }

    public function test_correspond_lie_le_code_au_compte(): void
    {
        $chef = $this->autorite();
        $autre = $this->autorite('prefet');
        $this->actingAs($chef);
        $this->service()->choisir($chef, self::CODE, 'password');
        $hash = $chef->fresh()->code_reception_hash;

        $this->assertTrue($this->service()->correspond($chef, self::CODE, $hash));
        $this->assertFalse($this->service()->correspond($chef, '730184', $hash));
        // Le haché d'un compte ne vaut pas pour un autre, et le code brut n'est pas ce qui est haché.
        $this->assertFalse($this->service()->correspond($autre, self::CODE, $hash));
        $this->assertFalse(Hash::check(self::CODE, $hash));
    }

    /**
     * Sous MySQL, la première colonne TIMESTAMP NOT NULL d'une table peut recevoir ON UPDATE CURRENT_TIMESTAMP
     * (explicit_defaults_for_timestamp désactivé) : une simple clôture de l'entrée (valide_au) réécrirait alors
     * valide_du. Les dates métier de l'historique des codes sont donc des DATETIME, sans fuseau ni mise à jour
     * automatique. Le SQL est compilé sans être joué (pretend), sans jamais joindre de serveur MySQL.
     */
    public function test_dates_des_codes_en_datetime_sous_mysql(): void
    {
        config(['database.connections.mysql_apercu' => [
            'driver' => 'mysql', 'host' => 'aucun-hote.invalid', 'port' => 1, 'database' => 'apercu',
            'username' => 'aucun_acces', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
        ]]);
        $migration = require database_path('migrations/2026_11_05_100001_add_code_reception_to_users.php');
        $defaut = config('database.default');
        config(['database.default' => 'mysql_apercu']);
        try {
            // Une base SQLite en mémoire sert seulement à échapper les valeurs du journal : aucune requête n'est jouée.
            $apercu = DB::connection('mysql_apercu')->setPdo(new \PDO('sqlite::memory:'))->setReadPdo(new \PDO('sqlite::memory:'));
            $requetes = $apercu->pretend(fn () => $migration->up());
        } finally {
            config(['database.default' => $defaut]);
            DB::purge('mysql_apercu');
        }
        $sql = implode("\n", array_column($requetes, 'query'));

        $this->assertMatchesRegularExpression('/`valide_du` datetime not null/', $sql);
        $this->assertMatchesRegularExpression('/`valide_au` datetime null/', $sql);
        $this->assertMatchesRegularExpression('/`code_verrouille_le` datetime null/', $sql);
        // Seules les colonnes created_at / updated_at restent des TIMESTAMP (nullables : pas de mise à jour automatique).
        preg_match_all('/`(\w+)` timestamp/', $sql, $colonnes);
        $this->assertSame(['created_at', 'updated_at'], array_values(array_unique($colonnes[1])));
    }
}

<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Delegation;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Remise;
use App\Models\User;
use App\Services\Logistique\CodeReceptionService;
use App\Services\Logistique\DelegationService;
use App\Services\Logistique\PreuveService;
use App\Support\Canonique;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class PreuveTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    private const CODE = '482915';

    private const CLE = 'cle-idempotence-0001';

    private static ?string $pem = null;

    private string $chemin;

    private User $prefet;

    protected function setUp(): void
    {
        parent::setUp();

        self::$pem ??= $this->genererCle();
        $this->chemin = storage_path('framework/testing/preuve-'.getmypid().'-'.uniqid().'.pem');
        file_put_contents($this->chemin, self::$pem);
        config(['logistique.cle_preuve_privee' => $this->chemin]);

        $this->creerDecorFictif();
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 300]]);
        $this->stockerFictif($this->urnes, 1000, 400);
        $this->prefet = $this->autoriteFictive($this->prefecture);
        $this->definirCode($this->prefet, self::CODE);
        $this->actingAs($this->responsable);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (isset($this->chemin) && is_file($this->chemin)) {
            unlink($this->chemin);
        }
        parent::tearDown();
    }

    private function genererCle(): string
    {
        $cle = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($cle, $pem);

        return $pem;
    }

    private function service(): PreuveService
    {
        return app(PreuveService::class);
    }

    /** L'autorité choisit son code (comme au premier accès), puis l'utilisateur connecté est rétabli. */
    private function definirCode(User $u, string $code): void
    {
        $this->actingAs($u);
        app(CodeReceptionService::class)->choisir($u, $code, 'password');
        $this->actingAs($this->responsable);
    }

    /** @return array<string, mixed> */
    private function contenu(?User $signataire = null, array $surcharge = []): array
    {
        return array_replace([
            'decharge_id' => 12,
            'decharge_identifiant' => 'DCH-2026-0012',
            'remise_id' => 34,
            'cle' => self::CLE,
            'signataire' => ['user_id' => ($signataire ?? $this->prefet)->id, 'nom' => 'Awa Diop', 'qualite' => 'Préfète'],
            'lignes' => [['materiel_id' => 1, 'recu' => 10, 'defectueux' => 1, 'observation' => 'Caisse écrasée']],
            'horodatage_terminal' => '2026-11-05T10:00:00+00:00',
        ], $surcharge);
    }

    /** Chiffre un bloc comme le fait la tablette : RSA-OAEP, SHA-1, clé publique du serveur. */
    private function bloc(array $contenu, string $code = self::CODE, ?string $cle = null): string
    {
        $clair = json_encode(['code' => $code, 'cle' => $cle ?? self::CLE, 'empreinte' => Canonique::empreinte($contenu)]);
        $this->assertTrue(openssl_public_encrypt($clair, $chiffre, $this->service()->clePublique(), OPENSSL_PKCS1_OAEP_PADDING));

        return base64_encode($chiffre);
    }

    private function verifier(array $contenu, string $bloc, ?User $signataire = null, ?CarbonInterface $quand = null, ?Delegation $delegation = null, string $cle = self::CLE): array
    {
        return $this->service()->verifier($contenu, $cle, $bloc, $signataire ?? $this->prefet, $quand ?? now(), $delegation);
    }

    public function test_bon_code_verifiee_code(): void
    {
        $contenu = $this->contenu();
        $bloc = $this->bloc($contenu);

        $resultat = $this->verifier($contenu, $bloc);

        $this->assertSame('verifiee_code', $resultat['verification']);
        $this->assertSame(hash('sha256', base64_decode($bloc)), $resultat['bloc_empreinte']);
        $this->assertSame(0, $this->prefet->fresh()->code_echecs);
    }

    public function test_bon_code_remet_le_compteur_a_zero(): void
    {
        $contenu = $this->contenu();
        $this->verifier($contenu, $this->bloc($contenu, '135790'));
        $this->assertSame(1, $this->prefet->fresh()->code_echecs);

        $this->assertSame('verifiee_code', $this->verifier($contenu, $this->bloc($contenu))['verification']);
        $this->assertSame(0, $this->prefet->fresh()->code_echecs);
    }

    public function test_code_faux_echec_et_compteur(): void
    {
        $contenu = $this->contenu();

        $resultat = $this->verifier($contenu, $this->bloc($contenu, '135790'));

        $this->assertSame('echec', $resultat['verification']);
        $this->assertSame(1, $this->prefet->fresh()->code_echecs);
        $this->assertNull($this->prefet->fresh()->code_verrouille_le);
    }

    public function test_trois_echecs_verrouillage(): void
    {
        $contenu = $this->contenu();
        foreach (['111222', '333444', '555666'] as $faux) {
            $this->assertSame('echec', $this->verifier($contenu, $this->bloc($contenu, $faux))['verification']);
        }

        $prefet = $this->prefet->fresh();
        $this->assertSame(3, $prefet->code_echecs);
        $this->assertNotNull($prefet->code_verrouille_le);

        // Verrouillé : même le bon code échoue, sans compter davantage.
        $this->assertSame('echec', $this->verifier($contenu, $this->bloc($contenu))['verification']);
        $this->assertSame(3, $this->prefet->fresh()->code_echecs);
    }

    public function test_bloc_reutilise_rejete(): void
    {
        $contenu = $this->contenu();
        $bloc = $this->bloc($contenu);
        $resultat = $this->verifier($contenu, $bloc);

        $this->declarationPortant($resultat['bloc_empreinte']);

        try {
            $this->verifier($contenu, $bloc);
            $this->fail('Le rejeu du bloc devait être rejeté.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Preuve déjà utilisée par une autre déclaration.', $e->getMessage());
        }

        $entree = JournalEntree::where('action', 'anomalie_preuve')->sole();
        $this->assertSame('bloc_reutilise', $entree->apres['nature']);
        $this->assertSame($this->prefet->id, $entree->apres['signataire_user_id']);
    }

    public function test_contenu_modifie_rejete(): void
    {
        $contenu = $this->contenu();
        $bloc = $this->bloc($contenu);
        $modifie = $this->contenu(null, ['lignes' => [['materiel_id' => 1, 'recu' => 99, 'defectueux' => 0, 'observation' => null]]]);

        try {
            $this->verifier($modifie, $bloc);
            $this->fail('Un contenu modifié devait être rejeté.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Preuve ne correspondant pas au contenu de la déclaration.', $e->getMessage());
        }
        $this->assertSame('contenu_different', JournalEntree::where('action', 'anomalie_preuve')->sole()->apres['nature']);
        // Rejet avant toute vérification du code : aucun échec compté.
        $this->assertSame(0, $this->prefet->fresh()->code_echecs);
    }

    public function test_cle_differente_rejetee(): void
    {
        $contenu = $this->contenu();
        $bloc = $this->bloc($contenu, self::CODE, 'une-autre-cle-00001');

        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Preuve ne correspondant pas au contenu de la déclaration.');
        $this->verifier($contenu, $bloc);
    }

    public function test_signataire_different_de_celui_du_contenu_rejete(): void
    {
        $contenu = $this->contenu();
        $autre = User::factory()->create(['role' => 'sous_prefet']);

        $this->expectException(RegleLogistique::class);
        $this->verifier($contenu, $this->bloc($contenu), $autre);
    }

    public function test_code_reinitialise_pendant_le_hors_ligne_a_verifier(): void
    {
        $contenu = $this->contenu();
        $bloc = $this->bloc($contenu);
        $signeA = now()->addMinutes(10);

        // Signature hors ligne avec l'ancien code ; avant le dépôt, le responsable réinitialise et l'autorité en choisit un autre.
        Carbon::setTestNow(now()->addHour());
        app(CodeReceptionService::class)->reinitialiser($this->prefet, 'Code oublié');
        $this->definirCode($this->prefet, '246813');

        $resultat = $this->verifier($contenu, $bloc, null, $signeA);

        $this->assertSame('a_verifier', $resultat['verification']);
        $this->assertSame(0, $this->prefet->fresh()->code_echecs);
    }

    public function test_code_reinitialise_pas_encore_rechoisi_echec_sans_compter(): void
    {
        $contenu = $this->contenu();
        $bloc = $this->bloc($contenu);
        Carbon::setTestNow(now()->addHour());
        app(CodeReceptionService::class)->reinitialiser($this->prefet, 'Code oublié');

        $this->assertSame('echec', $this->verifier($contenu, $bloc, null, now()->subMinutes(30))['verification']);
        $this->assertSame(0, $this->prefet->fresh()->code_echecs);
    }

    public function test_code_faux_apres_changement_reste_un_echec(): void
    {
        $contenu = $this->contenu();
        $signeA = now()->addMinutes(10);
        Carbon::setTestNow(now()->addHour());
        app(CodeReceptionService::class)->reinitialiser($this->prefet, 'Code oublié');
        $this->definirCode($this->prefet, '246813');

        $this->assertSame('echec', $this->verifier($contenu, $this->bloc($contenu, '999000'), null, $signeA)['verification']);
        $this->assertSame(1, $this->prefet->fresh()->code_echecs);
    }

    public function test_signataire_sans_role_d_autorite_echec(): void
    {
        $agent = User::factory()->create(['role' => 'agent_transport']);
        $contenu = $this->contenu($agent);

        $this->assertSame('echec', $this->verifier($contenu, $this->bloc($contenu), $agent)['verification']);
    }

    private function deleguer(Carbon $du, ?Carbon $au = null): array
    {
        $adjoint = User::factory()->create(['role' => 'sous_prefet']);
        $this->definirCode($adjoint, '864209');
        $this->actingAs($this->prefet);
        $delegation = app(DelegationService::class)->deleguer($this->prefecture, $adjoint, $du, $au, 'Mission à Dakar');
        $this->actingAs($this->responsable);

        return [$adjoint, $delegation];
    }

    public function test_delegation_en_vigueur_verifiee_code(): void
    {
        [$adjoint, $delegation] = $this->deleguer(now());
        $contenu = $this->contenu($adjoint);

        $resultat = $this->verifier($contenu, $this->bloc($contenu, '864209'), $adjoint, now()->addMinute(), $delegation);

        $this->assertSame('verifiee_code', $resultat['verification']);
    }

    public function test_delegation_revoquee_pendant_le_hors_ligne_a_verifier(): void
    {
        $debut = now()->startOfSecond();
        [$adjoint, $delegation] = $this->deleguer($debut);
        $contenu = $this->contenu($adjoint);
        $bloc = $this->bloc($contenu, '864209');

        Carbon::setTestNow($debut->copy()->addHours(2));
        $this->actingAs($this->prefet);
        app(DelegationService::class)->revoquer($delegation, 'Retour de mission');
        $this->actingAs($this->responsable);

        $resultat = $this->verifier($contenu, $bloc, $adjoint, $debut->copy()->addHour(), $delegation->fresh());

        $this->assertSame('a_verifier', $resultat['verification']);
    }

    public function test_delegation_jamais_active_a_l_horodatage_echec(): void
    {
        $debut = now()->startOfSecond();
        [$adjoint, $delegation] = $this->deleguer($debut);
        $contenu = $this->contenu($adjoint);

        $resultat = $this->verifier($contenu, $this->bloc($contenu, '864209'), $adjoint, $debut->copy()->subDay(), $delegation);

        $this->assertSame('echec', $resultat['verification']);
        // Le code était bon : pas de compteur.
        $this->assertSame(0, $adjoint->fresh()->code_echecs);
    }

    public function test_delegation_et_mauvais_code_le_pire_l_emporte(): void
    {
        [$adjoint, $delegation] = $this->deleguer(now());
        $contenu = $this->contenu($adjoint);

        $resultat = $this->verifier($contenu, $this->bloc($contenu, '111999'), $adjoint, now()->addMinute(), $delegation);

        $this->assertSame('echec', $resultat['verification']);
        $this->assertSame(1, $adjoint->fresh()->code_echecs);
    }

    public function test_delegation_d_un_autre_delegue_echec(): void
    {
        [, $delegation] = $this->deleguer(now());
        $contenu = $this->contenu();

        $resultat = $this->verifier($contenu, $this->bloc($contenu), $this->prefet, now()->addMinute(), $delegation);

        $this->assertSame('echec', $resultat['verification']);
    }

    public function test_bloc_indechiffrable_echec(): void
    {
        $contenu = $this->contenu();
        $autre = openssl_pkey_new(['private_key_bits' => 2048]);
        $details = openssl_pkey_get_details($autre);
        openssl_public_encrypt(json_encode(['code' => self::CODE, 'cle' => self::CLE, 'empreinte' => Canonique::empreinte($contenu)]), $chiffre, $details['key'], OPENSSL_PKCS1_OAEP_PADDING);

        foreach ([base64_encode($chiffre), 'pas du base64 !!', '', base64_encode('trop court')] as $bloc) {
            $resultat = $this->verifier($contenu, $bloc);
            $this->assertSame('echec', $resultat['verification']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $resultat['bloc_empreinte']);
        }
        $this->assertSame(0, $this->prefet->fresh()->code_echecs);
    }

    public function test_bloc_dechiffre_mais_incomplet_echec(): void
    {
        $contenu = $this->contenu();
        openssl_public_encrypt('{"code":"482915"}', $chiffre, $this->service()->clePublique(), OPENSSL_PKCS1_OAEP_PADDING);

        $this->assertSame('echec', $this->verifier($contenu, base64_encode($chiffre))['verification']);
    }

    public function test_cle_publique_exposee(): void
    {
        $pem = $this->service()->clePublique();
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $pem);
        $this->assertSame(2048, openssl_pkey_get_details(openssl_pkey_get_public($pem))['bits']);

        $der = base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem));
        $this->assertSame(hash('sha256', $der), $this->service()->empreinteClePublique());

        $this->app['auth']->guard('web')->forgetUser();
        $this->getJson('/api/terrain/cle-preuve')->assertUnauthorized();

        $agent = User::factory()->create(['role' => 'agent_transport']);
        Sanctum::actingAs($agent, ['terrain']);
        $this->getJson('/api/terrain/cle-preuve')->assertOk()
            ->assertExactJson(['pem' => $pem, 'empreinte' => $this->service()->empreinteClePublique()]);
        $this->assertStringNotContainsString('PRIVATE', $this->getJson('/api/terrain/cle-preuve')->getContent());
    }

    public function test_cle_publique_sans_cle_configuree_503(): void
    {
        config(['logistique.cle_preuve_privee' => $this->chemin.'.absente']);
        Sanctum::actingAs(User::factory()->create(['role' => 'agent_transport']), ['terrain']);

        $this->getJson('/api/terrain/cle-preuve')->assertStatus(503)
            ->assertExactJson(['message' => 'Clé de preuve non configurée sur le serveur.']);
    }

    public function test_code_absent_des_journaux(): void
    {
        Log::spy();
        $contenu = $this->contenu();

        // Code juste, faux, bloc rejeté (contenu, réutilisation), bloc indéchiffrable, verrouillage.
        $this->verifier($contenu, $this->bloc($contenu, '777888'));
        $this->verifier($contenu, $this->bloc($contenu, '777889'));
        $juste = $this->bloc($contenu);
        $this->verifier($contenu, $juste);
        $this->declarationPortant($this->verifierSansEffet($juste));
        $messages = [];
        foreach ([fn () => $this->verifier($contenu, $juste), fn () => $this->verifier($this->contenu(null, ['remise_id' => 1]), $this->bloc($contenu, '777890'))] as $appel) {
            try {
                $appel();
            } catch (RegleLogistique $e) {
                $messages[] = $e->getMessage();
            }
        }
        $this->verifier($contenu, $this->bloc($contenu, '777891'));

        $tout = json_encode(JournalEntree::all()->toArray()).implode('|', $messages);
        foreach (['777888', '777889', '777890', '777891', self::CODE] as $code) {
            $this->assertStringNotContainsString($code, $tout);
        }
        $this->assertGreaterThan(0, JournalEntree::where('action', 'anomalie_preuve')->count());
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_contenu_preuve_forme_et_tri(): void
    {
        $chargee = $this->deposerChargee($this->prefecture, [$this->urnes->id => 20]);
        $remise = Remise::where('decharge_id', $chargee->id)->where('etape', 'livraison')->firstOrFail();

        $contenu = $this->service()->contenuPreuve(
            $remise,
            ['nom' => 'Awa Diop', 'qualite' => 'Préfète'],
            $this->prefet->id,
            [7 => ['recu' => '3', 'defectueux' => 0, 'observation' => '  '], 2 => ['recu' => 5, 'defectueux' => 1, 'observation' => ' Fissure ']],
            self::CLE,
            '2026-11-05T10:00:00Z',
        );

        $this->assertSame([
            'decharge_id' => $chargee->id,
            'decharge_identifiant' => $chargee->identifiant,
            'remise_id' => $remise->id,
            'cle' => self::CLE,
            'signataire' => ['user_id' => $this->prefet->id, 'nom' => 'Awa Diop', 'qualite' => 'Préfète'],
            'lignes' => [
                ['materiel_id' => 2, 'recu' => 5, 'defectueux' => 1, 'observation' => 'Fissure'],
                ['materiel_id' => 7, 'recu' => 3, 'defectueux' => 0, 'observation' => null],
            ],
            'horodatage_terminal' => '2026-11-05T10:00:00Z',
        ], $contenu);

        // Le bloc produit par ce contenu passe la vérification.
        $this->assertSame('verifiee_code', $this->verifier($contenu, $this->bloc($contenu))['verification']);
    }

    public function test_colonnes_de_preuve_et_valeurs_par_defaut(): void
    {
        foreach (['verification_identite', 'contestation', 'bloc_empreinte', 'gps_lat', 'gps_lng', 'gps_precision', 'signataire_user_id', 'delegation_id'] as $colonne) {
            $this->assertTrue(Schema::hasColumn('declarations', $colonne), $colonne);
        }

        $chargee = $this->deposerChargee($this->prefecture, [$this->urnes->id => 20]);
        $declaration = Declaration::findOrFail($this->deposerRecue($chargee, [])->id);

        $this->assertSame('non_verifiee', Declaration::whereKey($declaration->id)->value('verification_identite'));
        $this->assertSame('aucune', Declaration::whereKey($declaration->id)->value('contestation'));
        $this->assertNull($declaration->horodatage_terminal);
    }

    public function test_commande_cle_preuve(): void
    {
        $cible = storage_path('framework/testing/cle-commande-'.getmypid().'.pem');
        @unlink($cible);
        config(['logistique.cle_preuve_privee' => $cible]);

        try {
            $this->artisan('logistique:cle-preuve')->expectsOutputToContain('Empreinte de la clé publique (SHA-256)')->assertSuccessful();
            $this->assertSame('0600', substr(sprintf('%o', fileperms($cible)), -4));
            $premiere = file_get_contents($cible);
            $this->assertNotFalse(openssl_pkey_get_private($premiere));
            $this->assertSame(2048, openssl_pkey_get_details(openssl_pkey_get_private($premiere))['bits']);

            // Les empreintes affichées et publiées coïncident.
            $this->assertSame(0, Artisan::call('logistique:cle-preuve', ['--force' => true]));
            $this->assertStringContainsString($this->service()->empreinteClePublique(), Artisan::output());
            $this->assertNotSame($premiere, file_get_contents($cible));
        } finally {
            @unlink($cible);
        }
    }

    public function test_commande_cle_preuve_refuse_d_ecraser(): void
    {
        $avant = file_get_contents($this->chemin);

        $this->artisan('logistique:cle-preuve')->assertFailed();

        $this->assertSame($avant, file_get_contents($this->chemin));
    }

    private function verifierSansEffet(string $bloc): string
    {
        return hash('sha256', base64_decode($bloc));
    }

    /** Une déclaration quelconque portant l'empreinte de ce bloc. */
    private function declarationPortant(string $blocEmpreinte): Declaration
    {
        $chargee = $this->deposerChargee($this->prefecture, [$this->urnes->id => 20]);
        $declaration = $this->deposerRecue($chargee, []);
        $declaration->forceFill(['bloc_empreinte' => $blocEmpreinte])->save();

        return $declaration;
    }
}

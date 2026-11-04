<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Delegation;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Remise;
use App\Models\NotificationOp;
use App\Models\User;
use App\Services\Logistique\CodeReceptionService;
use App\Services\Logistique\DelegationService;
use App\Services\Logistique\PreuveService;
use App\Support\Canonique;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use LogicException;
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

    private Remise $remise;

    private Decharge $chargee;

    protected function setUp(): void
    {
        parent::setUp();

        self::$pem ??= $this->genererCle();
        $this->chemin = storage_path('framework/testing/preuve-'.getmypid().'-'.uniqid().'.pem');
        file_put_contents($this->chemin, self::$pem);
        config(['logistique.cle_preuve_privee' => $this->chemin]);

        // La remise date d'il y a deux jours : l'horodatage d'une signature hors ligne peut la suivre de près.
        Carbon::setTestNow(now()->subDays(2));
        $this->creerDecorFictif();
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 300]]);
        $this->stockerFictif($this->urnes, 1000, 400);
        $this->prefet = $this->autoriteFictive($this->prefecture);
        $this->definirCode($this->prefet, self::CODE);
        $this->actingAs($this->responsable);
        $this->chargee = $this->deposerChargee($this->prefecture, [$this->urnes->id => 20]);
        $this->remise = Remise::where('decharge_id', $this->chargee->id)->where('etape', 'livraison')->firstOrFail();
        Carbon::setTestNow();
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
            'remise_id' => $this->remise->id,
            'cle' => self::CLE,
            'signataire' => ['user_id' => ($signataire ?? $this->prefet)->id, 'nom' => 'Awa Diop', 'qualite' => 'Préfète'],
            'lignes' => [['materiel_id' => 1, 'recu' => 10, 'defectueux' => 1, 'observation' => 'Caisse écrasée']],
            'horodatage_terminal' => now()->toIso8601String(),
        ], $surcharge);
    }

    /** Chiffre un bloc comme le fait la tablette : RSA-OAEP, SHA-1, clé publique du serveur. */
    private function bloc(array $contenu, string $code = self::CODE, ?string $cle = null): string
    {
        $clair = json_encode(['code' => $code, 'cle' => $cle ?? self::CLE, 'empreinte' => Canonique::empreinte($contenu)]);
        $this->assertTrue(openssl_public_encrypt($clair, $chiffre, $this->service()->clePublique(), OPENSSL_PKCS1_OAEP_PADDING));

        return base64_encode($chiffre);
    }

    /** L'instant transmis est celui du contenu, comme le fera l'API. */
    private function verifier(array $contenu, string $bloc, ?User $signataire = null, ?CarbonInterface $quand = null, ?Delegation $delegation = null, string $cle = self::CLE): array
    {
        return $this->service()->verifier($contenu, $cle, $bloc, $signataire ?? $this->prefet, $quand ?? Carbon::parse($contenu['horodatage_terminal']), $delegation);
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
        $signeA = now()->addMinutes(10);
        $contenu = $this->contenu(null, ['horodatage_terminal' => $signeA->toIso8601String()]);
        $bloc = $this->bloc($contenu);

        // Signature hors ligne avec l'ancien code ; avant le dépôt, le responsable réinitialise et l'autorité en choisit un autre.
        Carbon::setTestNow(now()->addHour());
        app(CodeReceptionService::class)->reinitialiser($this->prefet, 'Code oublié');
        $this->definirCode($this->prefet, '246813');

        $resultat = $this->verifier($contenu, $bloc);

        $this->assertSame('a_verifier', $resultat['verification']);
        $this->assertSame(0, $this->prefet->fresh()->code_echecs);
    }

    public function test_code_reinitialise_pas_encore_rechoisi_ancien_code_a_verifier(): void
    {
        // Signé hors ligne avec le code alors valide ; réinitialisé ensuite, pas encore rechoisi au dépôt.
        $contenu = $this->contenu(null, ['horodatage_terminal' => now()->addMinutes(30)->toIso8601String()]);
        $bloc = $this->bloc($contenu);
        Carbon::setTestNow(now()->addHour());
        app(CodeReceptionService::class)->reinitialiser($this->prefet, 'Code oublié');
        $this->assertTrue((bool) $this->prefet->fresh()->doit_choisir_code);

        $this->assertSame('a_verifier', $this->verifier($contenu, $bloc)['verification']);
        $this->assertSame(0, $this->prefet->fresh()->code_echecs);
    }

    public function test_code_reinitialise_pas_encore_rechoisi_code_faux_echec_sans_compter(): void
    {
        $contenu = $this->contenu(null, ['horodatage_terminal' => now()->addMinutes(30)->toIso8601String()]);
        $bloc = $this->bloc($contenu, '135790');
        Carbon::setTestNow(now()->addHour());
        app(CodeReceptionService::class)->reinitialiser($this->prefet, 'Code oublié');

        $this->assertSame('echec', $this->verifier($contenu, $bloc)['verification']);
        $this->assertSame(0, $this->prefet->fresh()->code_echecs);
        $this->assertNull($this->prefet->fresh()->code_verrouille_le);
    }

    public function test_aucun_code_a_l_instant_de_la_signature_echec_sans_compter(): void
    {
        $contenu = $this->contenu(null, ['horodatage_terminal' => now()->addMinutes(30)->toIso8601String()]);
        $bloc = $this->bloc($contenu);
        Carbon::setTestNow(now()->addHour());
        app(CodeReceptionService::class)->reinitialiser($this->prefet, 'Code oublié');
        // Aucun code dans l'historique à l'instant de la signature : rien à comparer.
        DB::table('codes_reception')->where('user_id', $this->prefet->id)->delete();

        $this->assertSame('echec', $this->verifier($contenu, $bloc)['verification']);
        $this->assertSame(0, $this->prefet->fresh()->code_echecs);
    }

    public function test_code_reinitialise_puis_compte_verrouille_reste_un_echec(): void
    {
        // Verrouillé : même l'ancien code juste n'est pas comparé, et rien n'est compté (R17/R32).
        $contenu = $this->contenu(null, ['horodatage_terminal' => now()->addMinutes(30)->toIso8601String()]);
        $bloc = $this->bloc($contenu);
        Carbon::setTestNow(now()->addHour());
        app(CodeReceptionService::class)->reinitialiser($this->prefet, 'Code oublié');
        $this->prefet->forceFill(['code_verrouille_le' => now(), 'code_echecs' => 3])->save();

        $this->assertSame('echec', $this->verifier($contenu, $bloc)['verification']);
        $this->assertSame(3, $this->prefet->fresh()->code_echecs);
    }

    public function test_tablette_a_une_heure_pres_d_un_code_reinitialise(): void
    {
        // La tablette signe 40 minutes après l'instant présent, à l'heure de Paris (+01:00), puis le code est réinitialisé.
        $signe = now()->addMinutes(40)->startOfSecond()->setTimezone('+01:00');
        $contenu = $this->contenu(null, ['horodatage_terminal' => $signe->format('Y-m-d\TH:i:sP')]);
        $bloc = $this->bloc($contenu);
        Carbon::setTestNow(now()->addMinutes(50));
        app(CodeReceptionService::class)->reinitialiser($this->prefet, 'Code oublié');
        Carbon::setTestNow(now()->addMinutes(5));

        // Lue comme une heure UTC, la signature tomberait après la réinitialisation : aucun code alors valide.
        $this->assertSame('a_verifier', $this->verifier($contenu, $bloc, null, $signe)['verification']);
        $this->assertSame(0, $this->prefet->fresh()->code_echecs);
    }

    public function test_code_faux_apres_changement_reste_un_echec(): void
    {
        $signeA = now()->addMinutes(10);
        $contenu = $this->contenu(null, ['horodatage_terminal' => $signeA->toIso8601String()]);
        Carbon::setTestNow(now()->addHour());
        app(CodeReceptionService::class)->reinitialiser($this->prefet, 'Code oublié');
        $this->definirCode($this->prefet, '246813');

        $this->assertSame('echec', $this->verifier($contenu, $this->bloc($contenu, '999000'))['verification']);
        $this->assertSame(1, $this->prefet->fresh()->code_echecs);
    }

    public function test_signataire_sans_role_d_autorite_echec(): void
    {
        $agent = User::factory()->create(['role' => 'agent_transport']);
        $contenu = $this->contenu($agent);

        $this->assertSame('echec', $this->verifier($contenu, $this->bloc($contenu), $agent)['verification']);
    }

    private function autreAutorite(string $code = '135792'): User
    {
        $autre = $this->autoriteFictive($this->sousPrefecture1);
        $this->definirCode($autre, $code);

        return $autre;
    }

    public function test_code_juste_d_une_autre_autorite_echec_sans_compter(): void
    {
        $autre = $this->autreAutorite();
        $contenu = $this->contenu($autre);

        // Sans délégation, seul le titulaire du destinataire de la remise peut signer, même avec son bon code.
        $this->assertSame('echec', $this->verifier($contenu, $this->bloc($contenu, '135792'), $autre)['verification']);
        $this->assertSame('echec', $this->verifier($contenu, $this->bloc($contenu, '000111'), $autre)['verification']);
        $this->assertSame(0, $autre->fresh()->code_echecs);
    }

    public function test_delegation_d_un_autre_destinataire_echec(): void
    {
        $autre = $this->autreAutorite();
        $adjoint = User::factory()->create(['role' => 'sous_prefet']);
        $this->definirCode($adjoint, '864209');
        $this->actingAs($autre);
        $delegation = app(DelegationService::class)->deleguer($this->sousPrefecture1, $adjoint, now(), null, 'Mission');
        $this->actingAs($this->responsable);
        $contenu = $this->contenu($adjoint, ['horodatage_terminal' => now()->addMinute()->toIso8601String()]);

        // Une délégation valide, mais sur un autre destinataire que celui de la remise.
        $this->assertSame('echec', $this->verifier($contenu, $this->bloc($contenu, '864209'), $adjoint, null, $delegation)['verification']);
        $this->assertSame('echec', $this->verifier($contenu, $this->bloc($contenu, '000111'), $adjoint, null, $delegation)['verification']);
        $this->assertSame(0, $adjoint->fresh()->code_echecs);
    }

    public function test_remise_inconnue_ou_hors_destinataire_echec(): void
    {
        $contenu = $this->contenu(null, ['remise_id' => 999999]);
        $this->assertSame('echec', $this->verifier($contenu, $this->bloc($contenu))['verification']);

        $autre = Remise::where('a_type', '!=', 'destinataire')->first() ?? tap($this->remise->replicate(), function (Remise $r) {
            $r->a_type = 'agent';
            $r->save();
        });
        $contenu = $this->contenu(null, ['remise_id' => $autre->id]);
        $this->assertSame('echec', $this->verifier($contenu, $this->bloc($contenu))['verification']);
        $this->assertSame(0, $this->prefet->fresh()->code_echecs);
    }

    public function test_horodatage_dans_le_futur_echec_sans_compter(): void
    {
        $contenu = $this->contenu(null, ['horodatage_terminal' => now()->addMinutes(11)->toIso8601String()]);
        $this->assertSame('echec', $this->verifier($contenu, $this->bloc($contenu, '000111'))['verification']);
        $this->assertSame(0, $this->prefet->fresh()->code_echecs);

        $contenu = $this->contenu(null, ['horodatage_terminal' => now()->addMinutes(9)->toIso8601String()]);
        $this->assertSame('verifiee_code', $this->verifier($contenu, $this->bloc($contenu))['verification']);
    }

    public function test_horodatage_avant_la_remise_echec_sans_compter(): void
    {
        $avant = Carbon::instance($this->remise->created_at);
        $contenu = $this->contenu(null, ['horodatage_terminal' => $avant->copy()->subMinutes(11)->toIso8601String()]);
        $this->assertSame('echec', $this->verifier($contenu, $this->bloc($contenu, '000111'))['verification']);
        $this->assertSame(0, $this->prefet->fresh()->code_echecs);

        $contenu = $this->contenu(null, ['horodatage_terminal' => $avant->copy()->subMinutes(9)->toIso8601String()]);
        $this->assertSame('verifiee_code', $this->verifier($contenu, $this->bloc($contenu))['verification']);
    }

    public function test_remise_sans_date_de_creation_echec_sans_compter(): void
    {
        DB::table('remises')->where('id', $this->remise->id)->update(['created_at' => null]);

        $contenu = $this->contenu();
        $this->assertSame('echec', $this->verifier($contenu, $this->bloc($contenu))['verification']);
        $this->assertSame('echec', $this->verifier($contenu, $this->bloc($contenu, '000111'))['verification']);
        $this->assertSame(0, $this->prefet->fresh()->code_echecs);
    }

    public function test_horodatage_different_de_celui_du_contenu_rejete(): void
    {
        $contenu = $this->contenu();
        $bloc = $this->bloc($contenu);

        try {
            $this->verifier($contenu, $bloc, null, now()->subMinutes(3));
            $this->fail('Un horodatage différent de celui du contenu devait être rejeté.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Preuve ne correspondant pas au contenu de la déclaration.', $e->getMessage());
        }
        $this->assertSame(0, $this->prefet->fresh()->code_echecs);
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
        $contenu = $this->contenu($adjoint, ['horodatage_terminal' => now()->addMinute()->toIso8601String()]);

        $resultat = $this->verifier($contenu, $this->bloc($contenu, '864209'), $adjoint, null, $delegation);

        $this->assertSame('verifiee_code', $resultat['verification']);
    }

    public function test_delegation_revoquee_pendant_le_hors_ligne_a_verifier(): void
    {
        $debut = now()->startOfSecond();
        [$adjoint, $delegation] = $this->deleguer($debut);
        $contenu = $this->contenu($adjoint, ['horodatage_terminal' => $debut->copy()->addHour()->toIso8601String()]);
        $bloc = $this->bloc($contenu, '864209');

        Carbon::setTestNow($debut->copy()->addHours(2));
        $this->actingAs($this->prefet);
        app(DelegationService::class)->revoquer($delegation, 'Retour de mission');
        $this->actingAs($this->responsable);

        $resultat = $this->verifier($contenu, $bloc, $adjoint, null, $delegation->fresh());

        $this->assertSame('a_verifier', $resultat['verification']);
    }

    public function test_delegation_jamais_active_a_l_horodatage_echec(): void
    {
        $debut = now()->startOfSecond();
        [$adjoint, $delegation] = $this->deleguer($debut);
        $contenu = $this->contenu($adjoint, ['horodatage_terminal' => $debut->copy()->subDay()->toIso8601String()]);

        $resultat = $this->verifier($contenu, $this->bloc($contenu, '864209'), $adjoint, null, $delegation);

        $this->assertSame('echec', $resultat['verification']);
        // Le code était bon : pas de compteur.
        $this->assertSame(0, $adjoint->fresh()->code_echecs);
    }

    public function test_tablette_a_une_heure_pres_de_la_fin_d_une_delegation(): void
    {
        // La délégation finit dans une heure (UTC) ; la tablette signe 50 minutes après son début, à l'heure de Paris
        // (+01:00) : l'heure murale lue comme une heure UTC tomberait après la fin.
        $debut = now()->startOfSecond();
        [$adjoint, $delegation] = $this->deleguer($debut, $debut->copy()->addHour());
        $signe = $debut->copy()->addMinutes(50)->setTimezone('+01:00');
        $contenu = $this->contenu($adjoint, ['horodatage_terminal' => $signe->format('Y-m-d\TH:i:sP')]);
        Carbon::setTestNow($debut->copy()->addMinutes(55));

        $resultat = $this->verifier($contenu, $this->bloc($contenu, '864209'), $adjoint, $signe, $delegation);

        $this->assertSame('verifiee_code', $resultat['verification']);
    }

    public function test_tablette_a_une_heure_pres_du_debut_d_une_delegation(): void
    {
        // Signé 30 minutes avant le début de la délégation : l'heure murale +01:00 la croirait commencée.
        $debut = now()->addMinutes(20)->startOfSecond();
        [$adjoint, $delegation] = $this->deleguer($debut);
        $signe = $debut->copy()->subMinutes(30)->setTimezone('+01:00');
        $contenu = $this->contenu($adjoint, ['horodatage_terminal' => $signe->format('Y-m-d\TH:i:sP')]);

        $resultat = $this->verifier($contenu, $this->bloc($contenu, '864209'), $adjoint, $signe, $delegation);

        $this->assertSame('echec', $resultat['verification']);
        $this->assertSame(0, $adjoint->fresh()->code_echecs);
    }

    public function test_delegation_et_mauvais_code_le_pire_l_emporte(): void
    {
        [$adjoint, $delegation] = $this->deleguer(now());
        $contenu = $this->contenu($adjoint, ['horodatage_terminal' => now()->addMinute()->toIso8601String()]);

        $resultat = $this->verifier($contenu, $this->bloc($contenu, '111999'), $adjoint, null, $delegation);

        $this->assertSame('echec', $resultat['verification']);
        $this->assertSame(1, $adjoint->fresh()->code_echecs);
    }

    public function test_delegation_d_un_autre_delegue_echec(): void
    {
        [, $delegation] = $this->deleguer(now());
        $contenu = $this->contenu(null, ['horodatage_terminal' => now()->addMinute()->toIso8601String()]);

        $resultat = $this->verifier($contenu, $this->bloc($contenu), $this->prefet, null, $delegation);

        $this->assertSame('echec', $resultat['verification']);
    }

    public function test_bloc_indechiffrable_echec(): void
    {
        $contenu = $this->contenu();
        $autre = openssl_pkey_new(['private_key_bits' => 2048]);
        $details = openssl_pkey_get_details($autre);
        openssl_public_encrypt(json_encode(['code' => self::CODE, 'cle' => self::CLE, 'empreinte' => Canonique::empreinte($contenu)]), $chiffre, $details['key'], OPENSSL_PKCS1_OAEP_PADDING);

        foreach ([base64_encode($chiffre), base64_encode('trop court')] as $bloc) {
            $resultat = $this->verifier($contenu, $bloc);
            $this->assertSame('echec', $resultat['verification']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $resultat['bloc_empreinte']);
        }
        $this->assertSame(0, $this->prefet->fresh()->code_echecs);
    }

    public function test_bloc_vide_ou_pas_du_base64_echec_sans_empreinte(): void
    {
        $contenu = $this->contenu();

        foreach (['', 'pas du base64 !!', 'QUJD===', ' '] as $bloc) {
            $this->assertSame(['verification' => 'echec', 'bloc_empreinte' => null], $this->verifier($contenu, $bloc));
        }
        $this->assertSame(0, $this->prefet->fresh()->code_echecs);
    }

    public function test_longueur_differente_du_module_echec(): void
    {
        $contenu = $this->contenu();
        $chiffre = base64_decode($this->bloc($contenu));
        $this->assertSame(256, strlen($chiffre));

        foreach ([substr($chiffre, 0, 255), $chiffre.'x', substr($chiffre, 0, 128)] as $variante) {
            $resultat = $this->verifier($contenu, base64_encode($variante));
            $this->assertSame('echec', $resultat['verification']);
            $this->assertSame(hash('sha256', $variante), $resultat['bloc_empreinte']);
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

    public function test_code_absent_des_journaux_des_logs_des_notifications_et_des_traces(): void
    {
        Log::spy();
        $contenu = $this->contenu();
        $faux = ['777888', '777889', '777890', '777891'];

        // Code juste, faux (jusqu'au verrouillage et à l'alerte), bloc rejeté (contenu, réutilisation).
        $this->verifier($contenu, $this->bloc($contenu, $faux[0]));
        $this->verifier($contenu, $this->bloc($contenu, $faux[1]));
        $juste = $this->bloc($contenu);
        $this->verifier($contenu, $juste);
        $this->declarationPortant(hash('sha256', base64_decode($juste)));
        $messages = [];
        foreach ([
            fn () => $this->verifier($contenu, $juste),
            fn () => $this->verifier($this->contenu(null, ['remise_id' => 1]), $this->bloc($contenu, $faux[2])),
        ] as $appel) {
            try {
                $appel();
            } catch (RegleLogistique $e) {
                $messages[] = $e->getMessage().$e->getTraceAsString();
            }
        }
        $this->verifier($contenu, $this->bloc($contenu, $faux[3]));
        $this->verifier($contenu, $this->bloc($contenu, '777892'));
        $this->verifier($contenu, $this->bloc($contenu, '777894'));
        $this->assertNotNull($this->prefet->fresh()->code_verrouille_le);

        $tout = json_encode(JournalEntree::all()->toArray()).json_encode(NotificationOp::all()->toArray()).implode('|', $messages);
        $this->assertGreaterThan(0, NotificationOp::where('type', 'logistique_alerte_code')->count());
        foreach ([...$faux, '777892', '777894', self::CODE] as $code) {
            $this->assertStringNotContainsString($code, $tout);
        }
        $this->assertGreaterThan(0, JournalEntree::where('action', 'anomalie_preuve')->count());

        // Aucun message de log, à aucun niveau.
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $niveau) {
            Log::shouldNotHaveReceived($niveau);
        }
    }

    public function test_code_absent_de_la_trace_d_une_exception(): void
    {
        $contenu = $this->contenu();
        $bloc = $this->bloc($contenu, '777893');
        // Sans utilisateur connecté, l'enregistrement de l'échec lève une exception sous verifierCode().
        $this->app['auth']->guard('web')->forgetUser();

        try {
            $this->verifier($contenu, $bloc);
            $this->fail('Une exception était attendue.');
        } catch (LogicException $e) {
            $trace = $e->getTraceAsString();
            $this->assertStringContainsString('verifierCode', $trace);
            $this->assertStringNotContainsString('777893', $trace);
            $this->assertStringNotContainsString('777893', json_encode(array_map(fn ($t) => $t['args'] ?? [], $e->getTrace()), JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
    }

    public function test_choisir_n_expose_ni_code_ni_mot_de_passe_dans_la_trace(): void
    {
        $this->actingAs($this->prefet);

        try {
            app(CodeReceptionService::class)->choisir($this->prefet, '482916', 'SecretTresLong2026');
            $this->fail('Une exception était attendue.');
        } catch (RegleLogistique $e) {
            $this->assertStringNotContainsString('482916', $e->getTraceAsString());
            $this->assertStringNotContainsString('SecretTresLong2026', $e->getTraceAsString());
        }
    }

    public function test_commande_refuse_un_chemin_dans_public(): void
    {
        $cible = public_path('preuve-test-'.getmypid().'.pem');
        config(['logistique.cle_preuve_privee' => $cible]);

        $this->artisan('logistique:cle-preuve')->assertFailed();
        $this->assertFileDoesNotExist($cible);
    }

    public function test_commande_ne_laisse_pas_de_fichier_temporaire(): void
    {
        $dossier = storage_path('framework/testing/cle-'.getmypid());
        mkdir($dossier);
        config(['logistique.cle_preuve_privee' => $dossier.'/preuve.pem']);

        try {
            $this->artisan('logistique:cle-preuve')->assertSuccessful();
            $this->artisan('logistique:cle-preuve', ['--force' => true])->assertSuccessful();
            $this->assertSame(['preuve.pem'], array_values(array_diff(scandir($dossier), ['.', '..'])));
            $this->assertSame('0600', substr(sprintf('%o', fileperms($dossier.'/preuve.pem')), -4));
        } finally {
            array_map('unlink', array_map(fn ($f) => $dossier.'/'.$f, array_diff(scandir($dossier), ['.', '..'])));
            @rmdir($dossier);
        }
    }

    public function test_contenu_preuve_forme_et_tri(): void
    {
        $chargee = $this->chargee;
        $remise = $this->remise;
        $horodatage = now()->toIso8601String();

        $contenu = $this->service()->contenuPreuve(
            $remise,
            ['nom' => 'Awa Diop', 'qualite' => 'Préfète'],
            $this->prefet->id,
            [7 => ['recu' => '3', 'defectueux' => 0, 'observation' => '  '], 2 => ['recu' => 5, 'defectueux' => 1, 'observation' => ' Fissure ']],
            self::CLE,
            $horodatage,
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
            'horodatage_terminal' => $horodatage,
        ], $contenu);

        // Le bloc produit par ce contenu passe la vérification.
        $this->assertSame('verifiee_code', $this->verifier($contenu, $this->bloc($contenu))['verification']);
    }

    /**
     * Vecteurs partagés avec la tablette (tests/js/preuve.test.js) : même entrée, même JSON canonique, même empreinte.
     * Les identifiants de décharge et de remise viennent de la base : ils sont remplacés par ceux du vecteur après
     * avoir vérifié qu'ils sont bien ceux de la remise.
     */
    public function test_vecteurs_partages_du_contenu_de_preuve(): void
    {
        $vecteurs = json_decode(file_get_contents(base_path('tests/fixtures/contenu-preuve.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertGreaterThanOrEqual(2, count($vecteurs));

        foreach ($vecteurs as $i => $vecteur) {
            $e = $vecteur['entree'];
            $contenu = $this->service()->contenuPreuve(
                $this->remise,
                ['nom' => $e['signataire']['nom'], 'qualite' => $e['signataire']['qualite']],
                $e['signataire']['user_id'],
                $e['lignes'],
                $e['cle'],
                $e['horodatage_terminal'],
            );

            $this->assertSame($this->chargee->id, $contenu['decharge_id']);
            $this->assertSame($this->remise->id, $contenu['remise_id']);
            $contenu['decharge_id'] = $e['decharge_id'];
            $contenu['decharge_identifiant'] = $e['decharge_identifiant'];
            $contenu['remise_id'] = $e['remise_id'];

            $this->assertSame($vecteur['json'], Canonique::json($contenu), "JSON du vecteur {$i}");
            $this->assertSame($vecteur['empreinte'], Canonique::empreinte($contenu), "Empreinte du vecteur {$i}");
        }
    }

    public function test_colonnes_de_preuve_et_valeurs_par_defaut(): void
    {
        foreach (['verification_identite', 'contestation', 'bloc_empreinte', 'gps_lat', 'gps_lng', 'gps_precision', 'signataire_user_id', 'delegation_id'] as $colonne) {
            $this->assertTrue(Schema::hasColumn('declarations', $colonne), $colonne);
        }

        $declaration = Declaration::findOrFail($this->deposerRecue($this->chargee, [])->id);

        // Une déclaration au compte tient son identité de la connexion (P5-R2) ; seule la tablette part de « non vérifiée ».
        $this->assertSame('compte', Declaration::whereKey($declaration->id)->value('verification_identite'));
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
        $declaration = $this->deposerRecue($this->chargee, []);
        $declaration->forceFill(['bloc_empreinte' => $blocEmpreinte])->save();

        return $declaration;
    }
}

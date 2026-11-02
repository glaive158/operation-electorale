<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\DechargeDocument;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Remise;
use App\Models\NotificationOp;
use App\Models\User;
use App\Services\Logistique\ArbitrageService;
use App\Services\Logistique\AvisReceptionService;
use App\Services\Logistique\CodeReceptionService;
use App\Services\Logistique\DechargePdfService;
use App\Services\Logistique\DechargeService;
use App\Services\Logistique\DeclarationService;
use App\Services\Logistique\IndicateurService;
use App\Services\Logistique\PreuveService;
use App\Services\Logistique\ReclamationService;
use App\Services\Logistique\RemiseService;
use App\Services\Logistique\StockService;
use App\Support\Canonique;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class AvisContestationTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    private const CODE = '482915';

    private const SIGNATAIRE = ['nom' => 'Awa Diop', 'qualite' => 'Préfète'];

    private static ?string $pem = null;

    private string $chemin;

    private User $prefet;

    private User $agent;

    private Decharge $chargee;

    private Remise $remise;

    protected function setUp(): void
    {
        parent::setUp();

        self::$pem ??= $this->genererCle();
        $this->chemin = storage_path('framework/testing/avis-contestation-'.getmypid().'-'.uniqid().'.pem');
        file_put_contents($this->chemin, self::$pem);
        config(['logistique.cle_preuve_privee' => $this->chemin]);
        Storage::fake(config('logistique.disque_decharges'));

        $this->creerDecorFictif();
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 300], [$this->sousPrefecture1, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 1000, 400);
        $this->prefet = $this->autoriteFictive($this->prefecture);
        $this->actingAs($this->prefet);
        app(CodeReceptionService::class)->choisir($this->prefet, self::CODE, 'password');
        $this->actingAs($this->responsable);
        $this->chargee = $this->deposerChargee($this->prefecture, [$this->urnes->id => 20]);
        $this->agent = $this->chargee->agent;
        $this->remise = $this->livraison($this->chargee);
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

    private function livraison(Decharge $decharge): Remise
    {
        return Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->with('lignes')->firstOrFail();
    }

    /** Déclaration de tablette d'une identité établie, signée par $signataire (le préfet par défaut). */
    private function tablette(?Remise $remise = null, ?User $signataire = null, int $recu = 20): Declaration
    {
        $remise ??= $this->remise;
        $signataire ??= $this->prefet;
        $lignes = [];
        foreach ($remise->lignes as $ligne) {
            $lignes[$ligne->materiel_id] = ['recu' => $recu, 'defectueux' => 0, 'observation' => null];
        }
        $preuve = ['verification' => 'verifiee_code', 'bloc_empreinte' => null, 'signataire_user_id' => $signataire->id, 'delegation_id' => null];

        $this->actingAs(Decharge::findOrFail($remise->decharge_id)->agent);
        try {
            return app(DeclarationService::class)->declarer($remise, 'tablette', self::SIGNATAIRE, $lignes, (string) Str::uuid(), null, $preuve);
        } finally {
            $this->actingAs($this->responsable);
        }
    }

    /** Exécute $action en tant que $auteur puis rétablit le responsable. */
    private function en(User $auteur, callable $action): mixed
    {
        $this->actingAs($auteur);
        try {
            return $action();
        } finally {
            $this->actingAs($this->responsable);
        }
    }

    private function contester(Declaration $declaration, ?User $par = null, string $motif = 'Ce n\'est pas moi.'): void
    {
        $this->en($par ?? $this->prefet, fn () => app(AvisReceptionService::class)->contester($declaration, $motif));
    }

    private function refus(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail("Le refus « {$message} » était attendu.");
        } catch (RegleLogistique $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function avis(?User $u = null)
    {
        return NotificationOp::where('type', 'logistique_reception')->when($u, fn ($q) => $q->where('user_id', $u->id));
    }

    /** Envoi de la tablette avec son bloc chiffré, comme le fait la PWA. */
    private function corpsAvecBloc(string $cle, string $horodatage): array
    {
        $corps = [
            'mode' => 'tablette', 'cle' => $cle, 'horodatage_terminal' => $horodatage,
            'signataire' => ['user_id' => $this->prefet->id, 'nom' => 'Awa Diop', 'qualite' => 'Préfète'],
            'lignes' => [['materiel_id' => $this->urnes->id, 'recu' => 20, 'defectueux' => 1, 'observation' => null]],
        ];
        $contenu = [
            'decharge_id' => (int) $this->remise->decharge_id,
            'decharge_identifiant' => $this->chargee->identifiant,
            'remise_id' => (int) $this->remise->id,
            'cle' => $cle,
            'signataire' => ['user_id' => $this->prefet->id, 'nom' => 'Awa Diop', 'qualite' => 'Préfète'],
            'lignes' => [['materiel_id' => $this->urnes->id, 'recu' => 20, 'defectueux' => 1, 'observation' => null]],
            'horodatage_terminal' => $horodatage,
        ];
        $clair = json_encode(['code' => self::CODE, 'cle' => $cle, 'empreinte' => Canonique::empreinte($contenu)]);
        $this->assertTrue(openssl_public_encrypt($clair, $chiffre, app(PreuveService::class)->clePublique(), OPENSSL_PKCS1_OAEP_PADDING));

        return $corps + ['bloc_preuve' => base64_encode($chiffre)];
    }

    private function envoyerParApi(array $corps): TestResponse
    {
        Sanctum::actingAs($this->agent, ['terrain']);

        return $this->postJson('/api/terrain/remises/'.$this->remise->id.'/declarations', $corps);
    }

    public function test_avis_envoye_au_signataire(): void
    {
        // 10 h 00 à Paris (+02:00) en novembre 2026 : 08 h 00 dans le fuseau de l'application.
        Carbon::setTestNow('2026-11-05 08:05:00');
        $cle = 'cle-avis-reception-1';
        $horodatage = '2026-11-05T10:00:00+02:00';

        $corps = $this->corpsAvecBloc($cle, $horodatage);
        $this->envoyerParApi($corps)->assertCreated();

        $declaration = Declaration::firstOrFail();
        $avis = $this->avis()->get();
        $this->assertCount(1, $avis);
        $this->assertSame($this->prefet->id, $avis[0]->user_id);
        $this->assertNull($avis[0]->operation_id);
        $this->assertSame(
            "Vous avez confirmé la réception de la décharge n° {$this->chargee->numero} le 05/11/2026 à 08:00 (1 articles). Si ce n'est pas vous, contestez.",
            $avis[0]->message,
        );
        $this->assertSame($declaration->signataire_user_id, $this->prefet->id);

        // Un rejeu de la même clé ne renvoie pas d'avis.
        $this->envoyerParApi($corps)->assertOk();
        $this->assertSame(1, $this->avis()->count());
    }

    public function test_avis_dans_le_fuseau_de_l_application(): void
    {
        $declaration = $this->tablette();
        NotificationOp::query()->delete();

        try {
            // L'instant stocké se lit dans le fuseau de PHP (UTC ici) : 22 h 30 UTC le 31 décembre, soit 23 h 30
            // dans le fuseau de l'application (Paris, UTC+1 l'hiver).
            config(['app.timezone' => 'Europe/Paris']);
            $declaration->horodatage_terminal = Carbon::parse('2026-12-31 22:30:00', 'UTC');

            app(AvisReceptionService::class)->envoyer($declaration);
        } finally {
            config(['app.timezone' => 'UTC']);
        }

        $this->assertStringContainsString(' le 31/12/2026 à 23:30 (1 articles).', $this->avis()->firstOrFail()->message);
    }

    public function test_pas_d_avis_sans_signataire_ni_hors_tablette(): void
    {
        // Compte : la déclaration est faite par l'intéressé lui-même.
        $this->en($this->prefet, fn () => app(DeclarationService::class)->declarer(
            $this->remise, 'compte', self::SIGNATAIRE,
            [$this->urnes->id => ['recu' => 20, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(),
        ));
        $this->assertSame(0, $this->avis()->count());

        // Tablette sans signataire identifié.
        $autre = $this->deposerChargee($this->prefecture, [$this->urnes->id => 10]);
        $this->actingAs($autre->agent);
        app(DeclarationService::class)->declarer(
            $this->livraison($autre), 'tablette', self::SIGNATAIRE,
            [$this->urnes->id => ['recu' => 10, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(), null,
            ['verification' => 'non_verifiee', 'bloc_empreinte' => null, 'signataire_user_id' => null, 'delegation_id' => null],
        );
        $this->assertSame(0, $this->avis()->count());
    }

    public function test_avis_envoye_quelle_que_soit_la_verification(): void
    {
        $this->actingAs($this->agent);
        $declaration = app(DeclarationService::class)->declarer(
            $this->remise, 'tablette', self::SIGNATAIRE,
            [$this->urnes->id => ['recu' => 20, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(), null,
            ['verification' => 'echec', 'bloc_empreinte' => null, 'signataire_user_id' => $this->prefet->id, 'delegation_id' => null],
        );

        $this->assertSame('a_verifier', $declaration->statut);
        $this->assertSame(1, $this->avis($this->prefet)->count());
    }

    public function test_avis_envoye_apres_validation_de_la_transaction(): void
    {
        $this->actingAs($this->agent);
        DB::beginTransaction();
        app(DeclarationService::class)->declarer(
            $this->remise, 'tablette', self::SIGNATAIRE,
            [$this->urnes->id => ['recu' => 20, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(), null,
            ['verification' => 'verifiee_code', 'bloc_empreinte' => null, 'signataire_user_id' => $this->prefet->id, 'delegation_id' => null],
        );
        // La transaction englobante n'est pas validée : aucun avis n'est parti.
        $this->assertSame(0, $this->avis()->count());
        DB::rollBack();

        $this->assertSame(0, $this->avis()->count());
        $this->assertSame(0, Declaration::count());
    }

    public function test_contestation_met_a_verifier(): void
    {
        $declaration = $this->tablette();
        $this->assertSame('retenue', $declaration->statut);
        $this->assertSame('recue', $this->remise->refresh()->statut);
        $this->assertSame('recue_destinataire', $this->chargee->refresh()->statut_acheminement);
        $actif = User::factory()->create(['role' => 'logistique_responsable']);
        $inactif = User::factory()->create(['role' => 'logistique_responsable', 'actif' => false]);
        NotificationOp::query()->delete();

        $this->contester($declaration, $this->prefet, 'Je n\'ai rien signé.');

        $declaration->refresh();
        $this->assertSame('contestee', $declaration->contestation);
        $this->assertSame('a_verifier', $declaration->statut);
        $this->assertSame('a_verifier', $this->remise->refresh()->statut);
        $this->assertSame('a_verifier', $this->chargee->refresh()->statut_acheminement);
        // Les quantités ne changent jamais.
        $this->assertSame(20, (int) $declaration->lignes->first()->recu);

        $journal = JournalEntree::where('objet_type', 'declaration')->where('objet_id', $declaration->id)->where('action', 'contestation_reception')->get();
        $this->assertCount(1, $journal);
        $this->assertSame($this->prefet->id, $journal[0]->user_id);
        $this->assertSame('Je n\'ai rien signé.', $journal[0]->motif);
        $this->assertSame('aucune', $journal[0]->avant['contestation']);
        $this->assertSame('contestee', $journal[0]->apres['contestation']);

        $alertes = NotificationOp::where('type', 'logistique_contestation')->get();
        $this->assertEqualsCanonicalizing([$this->responsable->id, $actif->id], $alertes->pluck('user_id')->all());
        $this->assertNotContains($inactif->id, $alertes->pluck('user_id')->all());
        $this->assertStringContainsString($this->chargee->numero, $alertes[0]->message);
        $this->assertStringContainsString('Je n\'ai rien signé.', $alertes[0]->message);
    }

    public function test_contestation_possible_apres_la_cloture_du_cycle(): void
    {
        $declaration = $this->tablette();
        $this->actingAs($this->responsable);
        app(StockService::class)->cloturerCycle($this->cycle->refresh(), 'Fin de cycle');

        $this->contester($declaration);

        $this->assertSame('contestee', $declaration->refresh()->contestation);
        $this->assertSame('a_verifier', $declaration->statut);
    }

    public function test_contestation_conserve_le_statut_si_une_redistribution_a_ete_preparee(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $livraison = $this->livraison($decharge);
        $declaration = $this->tablette($livraison, $this->prefet, 100);
        $this->assertSame('retenue', $declaration->statut);
        $redistribution = $this->en($this->prefet, fn () => app(RemiseService::class)->preparerRedistribution($decharge->refresh(), [$this->urnes->id => 40]));
        NotificationOp::query()->delete();

        $this->contester($declaration);

        $declaration->refresh();
        $this->assertSame('contestee', $declaration->contestation);
        $this->assertSame('retenue', $declaration->statut);
        $this->assertSame('recue', $livraison->refresh()->statut);
        $this->assertSame('recue_intermediaire', $decharge->refresh()->statut_acheminement);
        $this->assertSame('en_attente', $redistribution->refresh()->statut);
        $this->assertSame(1, JournalEntree::where('objet_id', $declaration->id)->where('action', 'contestation_reception')->count());
        $alerte = NotificationOp::where('type', 'logistique_contestation')->firstOrFail();
        $this->assertStringContainsString('déjà utilisée', $alerte->message);
    }

    public function test_contestation_met_a_verifier_si_la_redistribution_est_annulee(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $livraison = $this->livraison($decharge);
        $declaration = $this->tablette($livraison, $this->prefet, 100);
        $redistribution = $this->en($this->prefet, fn () => app(RemiseService::class)->preparerRedistribution($decharge->refresh(), [$this->urnes->id => 40]));
        $this->en($this->prefet, fn () => app(RemiseService::class)->annulerRedistribution($redistribution, 'Erreur de saisie.'));

        $this->contester($declaration);

        $this->assertSame('a_verifier', $declaration->refresh()->statut);
        $this->assertSame('a_verifier', $livraison->refresh()->statut);
        $this->assertSame('a_verifier', $decharge->refresh()->statut_acheminement);
    }

    public function test_contestation_conserve_le_statut_si_l_exemplaire_de_reception_est_emis(): void
    {
        $declaration = $this->tablette();
        $this->actingAs($this->responsable);
        app(DechargePdfService::class)->emettre($this->chargee->refresh(), 'reception');
        $this->assertSame(1, DechargeDocument::where('exemplaire', 'reception')->count());

        $this->contester($declaration);

        $this->assertSame('contestee', $declaration->refresh()->contestation);
        $this->assertSame('retenue', $declaration->statut);
        $this->assertSame('recue', $this->remise->refresh()->statut);
        $this->assertSame('recue_destinataire', $this->chargee->refresh()->statut_acheminement);
    }

    public function test_seul_le_signataire_conteste(): void
    {
        $declaration = $this->tablette();
        $autre = User::factory()->create(['role' => 'prefet']);

        foreach ([$this->agent, $autre, $this->responsable] as $intrus) {
            $this->refus(fn () => $this->contester($declaration, $intrus), 'Seul le signataire de la réception peut la contester.');
        }
        $this->assertSame('aucune', $declaration->refresh()->contestation);
        $this->assertSame('retenue', $declaration->statut);
        $this->assertSame(0, NotificationOp::where('type', 'logistique_contestation')->count());

        // Une déclaration sans signataire identifié ne se conteste pas.
        $declaration->forceFill(['signataire_user_id' => null])->save();
        $this->refus(fn () => $this->contester($declaration), 'Seul le signataire de la réception peut la contester.');
    }

    public function test_contester_exige_un_motif(): void
    {
        $declaration = $this->tablette();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Le motif est obligatoire (500 caractères au plus).');
        $this->contester($declaration, null, '  ');
    }

    public function test_contester_refuse_une_declaration_deja_contestee_ecartee_ou_remplacee(): void
    {
        $declaration = $this->tablette();
        $this->contester($declaration);
        $this->refus(fn () => $this->contester($declaration), 'Cette réception ne peut plus être contestée.');

        foreach (['ecartee', 'remplacee'] as $statut) {
            $declaration->forceFill(['statut' => $statut, 'contestation' => 'aucune'])->save();
            $this->refus(fn () => $this->contester($declaration), 'Cette réception ne peut plus être contestée.');
        }

        $declaration->forceFill(['statut' => 'a_verifier', 'contestation' => 'levee'])->save();
        $this->refus(fn () => $this->contester($declaration), 'Cette réception ne peut plus être contestée.');
    }

    public function test_contester_exige_un_motif_borne_et_un_auteur(): void
    {
        $declaration = $this->tablette();
        try {
            $this->contester($declaration, null, str_repeat('x', 501));
            $this->fail('Un motif de plus de 500 caractères doit être refusé.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Le motif est obligatoire (500 caractères au plus).', $e->getMessage());
        }

        // Sans auteur connecté, la contestation est une erreur de programmation.
        $this->app['auth']->forgetGuards();
        $this->expectException(\LogicException::class);
        app(AvisReceptionService::class)->contester($declaration, 'Ce n\'est pas moi.');
    }

    public function test_lever_la_contestation(): void
    {
        $declaration = $this->tablette();
        $this->contester($declaration);
        $this->actingAs($this->responsable);

        app(ArbitrageService::class)->leverContestation($declaration, 'Le préfet a confirmé par téléphone.');

        $declaration->refresh();
        $this->assertSame('levee', $declaration->contestation);
        // La déclaration reste à arbitrer : levée ne vaut pas retenue.
        $this->assertSame('a_verifier', $declaration->statut);
        $this->assertSame('a_verifier', $this->remise->refresh()->statut);
        $entree = JournalEntree::where('objet_id', $declaration->id)->where('action', 'levee_contestation')->firstOrFail();
        $this->assertSame($this->responsable->id, $entree->user_id);
        $this->assertSame('Le préfet a confirmé par téléphone.', $entree->motif);
        $this->assertSame('contestee', $entree->avant['contestation']);
        $this->assertSame('levee', $entree->apres['contestation']);

        app(ArbitrageService::class)->retenir($declaration, 'Réception confirmée.');
        $this->assertSame('retenue', $declaration->refresh()->statut);
        $this->assertSame('recue', $this->remise->refresh()->statut);
        $this->assertSame('recue_destinataire', $this->chargee->refresh()->statut_acheminement);
    }

    public function test_lever_exige_une_contestation_le_droit_et_un_motif(): void
    {
        $declaration = $this->tablette();
        $this->refus(fn () => app(ArbitrageService::class)->leverContestation($declaration, 'Motif.'), 'Aucune contestation à lever pour cette réception.');

        $this->contester($declaration);
        $this->refus(
            fn () => $this->en($this->prefet, fn () => app(ArbitrageService::class)->leverContestation($declaration, 'Motif.')),
            'Seul un responsable logistique peut lever une contestation.',
        );
        try {
            app(ArbitrageService::class)->leverContestation($declaration, '  ');
            $this->fail('Motif obligatoire.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Le motif est obligatoire (500 caractères au plus).', $e->getMessage());
        }
        $this->assertSame('contestee', $declaration->refresh()->contestation);

        app(ArbitrageService::class)->leverContestation($declaration, 'Motif.');
        $this->refus(fn () => app(ArbitrageService::class)->leverContestation($declaration, 'Encore.'), 'Aucune contestation à lever pour cette réception.');
    }

    public function test_lever_conserve_la_retenue_quand_la_reception_etait_utilisee(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $declaration = $this->tablette($this->livraison($decharge), $this->prefet, 100);
        $this->en($this->prefet, fn () => app(RemiseService::class)->preparerRedistribution($decharge->refresh(), [$this->urnes->id => 40]));
        $this->contester($declaration);

        app(ArbitrageService::class)->leverContestation($declaration, 'Confirmée.');

        $this->assertSame('levee', $declaration->refresh()->contestation);
        $this->assertSame('retenue', $declaration->statut);
    }

    public function test_api_le_signataire_conteste(): void
    {
        $declaration = $this->tablette();
        Sanctum::actingAs($this->prefet, ['terrain']);

        $this->postJson("/api/terrain/declarations/{$declaration->id}/contestation", ['motif' => 'Ce n\'est pas moi.'])
            ->assertOk()
            ->assertExactJson(['declaration' => [
                'id' => $declaration->id, 'statut' => 'a_verifier', 'verification_identite' => 'verifiee_code',
                'contestation' => 'contestee', 'anomalies' => [],
            ]]);
        $this->assertSame('a_verifier', $this->remise->refresh()->statut);
    }

    public function test_api_refus_et_perimetre(): void
    {
        $declaration = $this->tablette();
        $url = "/api/terrain/declarations/{$declaration->id}/contestation";

        $this->app['auth']->forgetGuards();
        $this->postJson($url, ['motif' => 'x'])->assertUnauthorized();

        // Hors du signataire : la déclaration n'existe pas (404), y compris pour l'agent qui l'a envoyée.
        Sanctum::actingAs($this->agent, ['terrain']);
        $this->postJson($url, ['motif' => 'x'])->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => 'prefet']), ['terrain']);
        $this->postJson($url, ['motif' => 'x'])->assertNotFound();
        Sanctum::actingAs($this->prefet, ['terrain']);
        $this->postJson('/api/terrain/declarations/999999/contestation', ['motif' => 'x'])->assertNotFound();

        // Motif absent : 422 ; règle métier : 422 en JSON français.
        $this->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('motif');
        $this->postJson($url, ['motif' => 'Ce n\'est pas moi.'])->assertOk();
        $this->postJson($url, ['motif' => 'Encore.'])->assertStatus(422)
            ->assertExactJson(['message' => 'Cette réception ne peut plus être contestée.']);

        // Un jeton sans la capacité terrain est refusé.
        Sanctum::actingAs($this->prefet, []);
        $this->postJson($url, ['motif' => 'x'])->assertForbidden();
    }

    public function test_web_le_signataire_conteste_depuis_son_compte(): void
    {
        $declaration = $this->tablette();
        $url = route('terrain.declarations.contestation', $declaration);

        $this->actingAs($this->agent)->post($url, ['motif' => 'x'])->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'prefet']))->post($url, ['motif' => 'x'])->assertNotFound();
        $this->assertSame('aucune', $declaration->refresh()->contestation);

        $this->actingAs($this->prefet)->post($url, [])->assertSessionHasErrors('motif');
        $this->actingAs($this->prefet)->from('/retour')->post($url, ['motif' => 'Ce n\'est pas moi.'])
            ->assertRedirect('/retour')->assertSessionHas('success');
        $this->assertSame('contestee', $declaration->refresh()->contestation);

        $this->actingAs($this->prefet)->from('/retour')->post($url, ['motif' => 'Encore.'])
            ->assertRedirect('/retour')->assertSessionHas('error', 'Cette réception ne peut plus être contestée.');
    }

    /** Déclaration « compte » du préfet sur la remise de livraison. */
    private function compte(Remise $remise, int $recu, int $defectueux = 0): Declaration
    {
        $lignes = [];
        foreach ($remise->lignes as $ligne) {
            $lignes[$ligne->materiel_id] = ['recu' => $recu, 'defectueux' => $defectueux, 'observation' => null];
        }

        return $this->en($this->prefet, fn () => app(DeclarationService::class)->declarer($remise, 'compte', self::SIGNATAIRE, $lignes, (string) Str::uuid()));
    }

    public function test_une_declaration_contestee_n_est_jamais_retenue_d_office(): void
    {
        $a = $this->tablette();
        $this->contester($a);
        $b = $this->compte($this->remise, 15);
        $this->assertSame(['a_verifier', 'a_verifier'], [$a->refresh()->statut, $b->statut]);

        // Le responsable rejette B : A, seule restante, reste à vérifier tant que sa contestation n'est pas levée.
        app(ArbitrageService::class)->rejeter($b, 'Quantité erronée.');

        $this->assertSame('a_verifier', $a->refresh()->statut);
        $this->assertSame('contestee', $a->contestation);
        $this->assertSame('a_verifier', $this->remise->refresh()->statut);
        $this->assertSame('a_verifier', $this->chargee->refresh()->statut_acheminement);
        $this->assertSame(0, app(RemiseService::class)->soldePrefet($this->chargee)->get($this->urnes->id)['recu_utilisable']);
        $this->assertSame(0, app(IndicateurService::class)->recuUtilisable($this->cycle, $this->prefecture, $this->urnes));
    }

    public function test_une_seconde_declaration_identique_ne_retient_pas_la_contestee(): void
    {
        $a = $this->tablette();
        $this->contester($a);

        $b = $this->compte($this->remise, 20);

        $this->assertSame(['a_verifier', 'a_verifier'], [$a->refresh()->statut, $b->statut]);
        $this->assertSame('a_verifier', $this->remise->refresh()->statut);
        $this->assertSame(0, app(IndicateurService::class)->recuUtilisable($this->cycle, $this->prefecture, $this->urnes));
    }

    public function test_apres_la_levee_le_responsable_retient_la_declaration(): void
    {
        $a = $this->tablette();
        $this->contester($a);
        $b = $this->compte($this->remise, 15);
        app(ArbitrageService::class)->leverContestation($a, 'Confirmée par téléphone.');

        app(ArbitrageService::class)->retenir($a, 'Réception confirmée.');

        $this->assertSame(['retenue', 'ecartee'], [$a->refresh()->statut, $b->refresh()->statut]);
        $this->assertSame('recue', $this->remise->refresh()->statut);
        $this->assertSame('recue_destinataire', $this->chargee->refresh()->statut_acheminement);
        $this->assertSame(20, app(IndicateurService::class)->recuUtilisable($this->cycle, $this->prefecture, $this->urnes));
    }

    public function test_apres_une_contestation_la_redistribution_est_refusee_et_l_exemplaire_non_emissible(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $declaration = $this->tablette($this->livraison($decharge), $this->prefet, 100);
        $this->contester($declaration);

        $this->refus(
            fn () => $this->en($this->prefet, fn () => app(RemiseService::class)->preparerRedistribution($decharge->refresh(), [$this->urnes->id => 40])),
            'Réception du préfet à vérifier : redistribution impossible.',
        );
        $this->assertFalse(app(DechargePdfService::class)->receptionEmissible($decharge->refresh()));
        $this->assertFalse(app(DechargePdfService::class)->receptionEmissible($this->chargee->refresh()));
    }

    public function test_contester_une_concordante_laisse_la_retenue(): void
    {
        $a = $this->tablette();
        $b = $this->compte($this->remise, 20);
        $this->assertSame(['retenue', 'concordante'], [$a->refresh()->statut, $b->statut]);

        $this->contester($b);

        $this->assertSame('a_verifier', $b->refresh()->statut);
        $this->assertSame('contestee', $b->contestation);
        $this->assertSame('retenue', $a->refresh()->statut);
        $this->assertSame('a_verifier', $this->remise->refresh()->statut);
        $this->assertTrue(JournalEntree::where('objet_id', $b->id)->where('action', 'statut_declaration')
            ->get()->contains(fn ($e) => ($e->avant['statut'] ?? null) === 'concordante' && ($e->apres['statut'] ?? null) === 'a_verifier'));
    }

    public function test_contester_la_retenue_d_une_paire_concordante(): void
    {
        $a = $this->tablette();
        $b = $this->compte($this->remise, 20);

        $this->contester($a);

        $this->assertSame(['a_verifier', 'concordante'], [$a->refresh()->statut, $b->refresh()->statut]);
        $this->assertSame('a_verifier', $this->remise->refresh()->statut);

        // Rejeter la contestée laisse la jumelle seule, saine : elle est retenue.
        app(ArbitrageService::class)->rejeter($a, 'Signataire non reconnu.');
        $this->assertSame(['ecartee', 'retenue'], [$a->refresh()->statut, $b->refresh()->statut]);
        $this->assertSame('recue', $this->remise->refresh()->statut);
    }

    public function test_alerte_nomme_le_statut_conserve(): void
    {
        $a = $this->tablette();
        $b = $this->compte($this->remise, 20);
        $this->actingAs($this->responsable);
        app(DechargePdfService::class)->emettre($this->chargee->refresh(), 'reception');

        NotificationOp::query()->delete();
        $this->contester($b);
        $this->assertSame('concordante', $b->refresh()->statut);
        $this->assertStringContainsString('elle reste concordante en attendant votre décision', NotificationOp::where('type', 'logistique_contestation')->firstOrFail()->message);

        NotificationOp::query()->delete();
        $this->contester($a);
        $this->assertSame('retenue', $a->refresh()->statut);
        $this->assertStringContainsString('elle reste retenue en attendant votre décision', NotificationOp::where('type', 'logistique_contestation')->firstOrFail()->message);
    }

    public function test_contestation_conserve_le_statut_si_le_remplacement_a_resolu_la_reclamation(): void
    {
        $this->deposerRecue($this->chargee, [$this->urnes->id => ['recu' => 20, 'defectueux' => 5]]);
        $reclamations = app(ReclamationService::class);
        $reclamation = $this->en($this->prefet, fn () => $reclamations->ouvrir($this->chargee->refresh(), $this->urnes, 'defectueux', 5, 'Cinq urnes défectueuses.'));
        $reclamations->resoudre($reclamation, 'remplacement_autorise', 'Urnes à remplacer.', 5);
        $remplacement = Decharge::where('nature', 'remplacement')->sole();
        $decharges = app(DechargeService::class);
        $decharges->valider($remplacement);
        $decharges->affecterAgent($remplacement, User::factory()->create(['role' => 'agent_transport']), 'Agent de test');
        app(RemiseService::class)->charger($remplacement->refresh());

        // Avant la réception du remplacement : rien n'est résolu, la contestation défait normalement la réception.
        $declaration = $this->deposerRecue($remplacement->refresh(), [$this->urnes->id => 5]);
        $this->assertSame('retenue', $declaration->statut);
        $this->assertSame('resolue', $reclamation->refresh()->statut);

        $this->contester($declaration);

        $this->assertSame('contestee', $declaration->refresh()->contestation);
        $this->assertSame('retenue', $declaration->statut);
        $this->assertSame('recue', Remise::where('decharge_id', $remplacement->id)->where('etape', 'livraison')->sole()->statut);
        $this->assertSame('resolue', $reclamation->refresh()->statut);
    }

    public function test_contestation_d_un_remplacement_non_resolu_met_a_verifier(): void
    {
        $this->deposerRecue($this->chargee, [$this->urnes->id => ['recu' => 20, 'defectueux' => 5]]);
        $reclamations = app(ReclamationService::class);
        $reclamation = $this->en($this->prefet, fn () => $reclamations->ouvrir($this->chargee->refresh(), $this->urnes, 'defectueux', 5, 'Cinq urnes défectueuses.'));
        $reclamations->resoudre($reclamation, 'remplacement_autorise', 'Urnes à remplacer.', 5);
        $remplacement = Decharge::where('nature', 'remplacement')->sole();
        $decharges = app(DechargeService::class);
        $decharges->valider($remplacement);
        $decharges->affecterAgent($remplacement, User::factory()->create(['role' => 'agent_transport']), 'Agent de test');
        app(RemiseService::class)->charger($remplacement->refresh());
        // Reçu en partie défectueux : la réclamation reste en traitement.
        $declaration = $this->deposerRecue($remplacement->refresh(), [$this->urnes->id => ['recu' => 5, 'defectueux' => 3]]);
        $this->assertNotSame('resolue', $reclamation->refresh()->statut);

        $this->contester($declaration);

        $this->assertSame('a_verifier', $declaration->refresh()->statut);
    }
}

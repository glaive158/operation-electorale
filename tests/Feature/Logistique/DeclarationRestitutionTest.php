<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\DeclarationEnCours;
use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Delegation;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Remise;
use App\Models\Logistique\Restitution;
use App\Models\Logistique\RestitutionDeclaration;
use App\Models\User;
use App\Services\Logistique\CodeReceptionService;
use App\Services\Logistique\ContextePreuve;
use App\Services\Logistique\DeclarationService;
use App\Services\Logistique\PieceJointeService;
use App\Services\Logistique\PreuveService;
use App\Services\Logistique\RemiseService;
use App\Services\Logistique\RestitutionService;
use App\Services\Logistique\StockService;
use App\Support\Canonique;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class DeclarationRestitutionTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    private const CODE = '482915';

    private const CLE = 'cle-restitution-0001';

    private const SIGNATAIRE = ['nom' => 'Ibrahima Ba', 'qualite' => 'Sous-préfet'];

    private static ?string $pem = null;

    private string $chemin;

    private User $sousPrefet;

    private User $agentRetour;

    private Restitution $restitution;

    private Remise $redistribution;

    protected function setUp(): void
    {
        parent::setUp();

        self::$pem ??= $this->genererCle();
        $this->chemin = storage_path('framework/testing/restitution-'.getmypid().'-'.uniqid().'.pem');
        file_put_contents($this->chemin, self::$pem);
        config(['logistique.cle_preuve_privee' => $this->chemin]);
        Storage::fake('pieces');
        config(['logistique.disque_pieces' => 'pieces']);

        $this->creerDecorFictif();
        $this->approuverPlanFictif([[$this->sousPrefecture1, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 1000, 300);
        $this->urnes->forceFill(['reutilisable' => true])->save();

        // Le préfet reçoit 100 urnes et les redistribue toutes au sous-préfet : 100 attendues du sous-préfet.
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => 100]);
        $this->actingAs($this->autoriteFictive($this->prefecture));
        $remise = app(RemiseService::class)->preparerRedistribution($decharge->refresh(), [$this->urnes->id => 100]);
        $this->redistribution = $remise;
        $this->sousPrefet = $this->autoriteFictive($this->sousPrefecture1);
        $this->actingAs($this->sousPrefet);
        app(DeclarationService::class)->declarer(
            $remise, 'compte', self::SIGNATAIRE,
            [$this->urnes->id => ['recu' => 100, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(),
        );
        $this->definirCode($this->sousPrefet, self::CODE);

        $this->actingAs($this->responsable);
        $this->service()->ouvrir($this->cycle);
        $this->restitution = Restitution::where('detenteur_type', 'destinataire')->where('detenteur_id', $this->sousPrefecture1->id)->sole();
        $this->agentRetour = User::factory()->create(['role' => 'agent_transport']);
        $this->service()->affecterAgent($this->restitution, $this->agentRetour);
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

    private function definirCode(User $u, string $code): void
    {
        $auteur = auth()->user();
        $this->actingAs($u);
        app(CodeReceptionService::class)->choisir($u, $code, 'password');
        $this->actingAs($auteur);
    }

    private function service(): RestitutionService
    {
        return app(RestitutionService::class);
    }

    /** @return list<array{materiel_id: int, restitue: int, observation: ?string}> */
    private function lignes(int $restitue, ?string $observation = null): array
    {
        return [['materiel_id' => $this->urnes->id, 'restitue' => $restitue, 'observation' => $observation]];
    }

    /** Le bloc que chiffrerait la tablette pour ce contenu (RSA-OAEP, SHA-1, clé publique du serveur). */
    private function bloc(array $lignes, string $horodatage, string $code = self::CODE, string $cle = self::CLE, ?User $signataire = null): string
    {
        $contenu = app(PreuveService::class)->contenuPreuveRestitution(
            $this->restitution, self::SIGNATAIRE, ($signataire ?? $this->sousPrefet)->id, $lignes, $cle, $horodatage,
        );
        $clair = json_encode(['code' => $code, 'cle' => $cle, 'empreinte' => Canonique::empreinte($contenu)]);
        $this->assertTrue(openssl_public_encrypt($clair, $chiffre, app(PreuveService::class)->clePublique(), OPENSSL_PKCS1_OAEP_PADDING));

        return base64_encode($chiffre);
    }

    /** Envoi de la tablette par l'agent (l'agent de retour par défaut) ; l'utilisateur connecté est rétabli. */
    private function tablette(array $lignes, ?string $bloc, string $horodatage, string $cle = self::CLE, ?User $agent = null, ?int $signataireUserId = null, array $pieces = []): RestitutionDeclaration
    {
        $auteur = auth()->user();
        $this->actingAs($agent ?? $this->agentRetour);
        try {
            return $this->service()->declarer(
                $this->restitution, 'tablette', self::SIGNATAIRE, $lignes, $cle, $horodatage, $bloc,
                $signataireUserId ?? $this->sousPrefet->id, $pieces,
            );
        } finally {
            $this->actingAs($auteur);
        }
    }

    private function compte(array $lignes, ?User $par = null, ?string $cle = null, ?Restitution $restitution = null): RestitutionDeclaration
    {
        $auteur = auth()->user();
        $this->actingAs($par ?? $this->sousPrefet);
        try {
            return $this->service()->declarer($restitution ?? $this->restitution, 'compte', self::SIGNATAIRE, $lignes, $cle ?? (string) Str::uuid());
        } finally {
            $this->actingAs($auteur);
        }
    }

    private function declareRestitue(?Restitution $restitution = null): int
    {
        return (int) ($restitution ?? $this->restitution)->lignes()->sole()->declare_restitue;
    }

    private function echecs(): int
    {
        return (int) $this->sousPrefet->fresh()->code_echecs;
    }

    public function test_sous_prefet_declare_90_sur_100(): void
    {
        $declaration = $this->compte($this->lignes(90, 'Dix urnes cassées restées sur place'));

        $this->assertSame('retenue', $declaration->statut);
        $this->assertSame('compte', $declaration->mode);
        $this->assertSame('compte', $declaration->verification_identite);
        $this->assertSame($this->sousPrefet->id, $declaration->signataire_user_id);
        $this->assertSame($this->sousPrefet->id, $declaration->user_id);
        $this->assertNull($declaration->anomalies);
        $this->assertSame('aucune', $declaration->contestation);
        $this->assertSame([[$this->urnes->id, 90, 'Dix urnes cassées restées sur place']], $declaration->lignes
            ->map(fn ($l) => [$l->materiel_id, $l->restitue, $l->observation])->all());

        // Le retour déclaré est porté sur la ligne ; le non restitué (10) reste visible.
        $ligne = $this->restitution->lignes()->sole();
        $this->assertSame(90, $ligne->declare_restitue);
        $this->assertSame(10, $ligne->attendu - $ligne->declare_restitue);
        $this->assertSame('a_preparer', $this->restitution->refresh()->statut);

        $journal = JournalEntree::where('action', 'declaration_restitution')->sole();
        $this->assertSame('restitution_declaration', $journal->objet_type);
        $this->assertSame($declaration->id, $journal->objet_id);
        $this->assertSame($this->restitution->id, $journal->apres['restitution_id']);
        $this->assertSame('retenue', $journal->apres['statut']);
    }

    public function test_declaration_par_un_delegue_au_compte(): void
    {
        $adjoint = User::factory()->create(['role' => 'sous_prefet']);
        $delegation = Delegation::create([
            'destinataire_id' => $this->sousPrefecture1->id, 'autorite_user_id' => $this->sousPrefet->id, 'delegue_user_id' => $adjoint->id,
            'du' => now()->subDay(), 'au' => now()->addDay(), 'motif' => 'Intérim', 'cree_par' => $this->responsable->id,
        ]);

        $declaration = $this->compte($this->lignes(100), $adjoint);

        $this->assertSame('retenue', $declaration->statut);
        $this->assertSame($adjoint->id, $declaration->signataire_user_id);
        $this->assertSame($delegation->id, $declaration->delegation_id);
    }

    public function test_declaration_tablette_avec_code(): void
    {
        $horodatage = now()->toIso8601String();
        $lignes = $this->lignes(100);

        $declaration = $this->tablette($lignes, $this->bloc($lignes, $horodatage), $horodatage);

        $this->assertSame('retenue', $declaration->statut);
        $this->assertSame('tablette', $declaration->mode);
        $this->assertSame('verifiee_code', $declaration->verification_identite);
        $this->assertSame($this->sousPrefet->id, $declaration->signataire_user_id);
        $this->assertSame($this->agentRetour->id, $declaration->user_id);
        $this->assertNotNull($declaration->bloc_empreinte);
        $this->assertSame($horodatage, Carbon::parse($declaration->horodatage_terminal)->toIso8601String());
        $this->assertSame(100, $this->declareRestitue());
        $this->assertSame(0, $this->echecs());
    }

    public function test_contenu_de_preuve_canonique(): void
    {
        $contenu = app(PreuveService::class)->contenuPreuveRestitution(
            $this->restitution, ['nom' => 'Ibrahima Ba', 'qualite' => 'Sous-préfet'], $this->sousPrefet->id,
            [
                ['materiel_id' => (string) $this->isoloirs->id, 'restitue' => '3', 'observation' => '  '],
                ['materiel_id' => $this->urnes->id, 'restitue' => 90, 'observation' => ' Cassées '],
            ],
            self::CLE, '2026-11-05T10:00:00+01:00',
        );

        $lignes = [
            ['materiel_id' => $this->urnes->id, 'restitue' => 90, 'observation' => 'Cassées'],
            ['materiel_id' => $this->isoloirs->id, 'restitue' => 3, 'observation' => null],
        ];
        $this->assertSame([
            'restitution_id' => $this->restitution->id,
            'cle' => self::CLE,
            'signataire' => ['nom' => 'Ibrahima Ba', 'qualite' => 'Sous-préfet'],
            'signataire_user_id' => $this->sousPrefet->id,
            'lignes' => $lignes,
            'horodatage_terminal' => '2026-11-05T10:00:00+01:00',
        ], $contenu);
    }

    public function test_retransmission_identique_trois_fois(): void
    {
        $horodatage = now()->toIso8601String();
        $lignes = $this->lignes(100);
        $bloc = $this->bloc($lignes, $horodatage);

        $premiere = $this->tablette($lignes, $bloc, $horodatage);
        $seconde = $this->tablette($lignes, $bloc, $horodatage);
        $troisieme = $this->tablette($lignes, $bloc, $horodatage);

        $this->assertSame($premiere->id, $seconde->id);
        $this->assertSame($premiere->id, $troisieme->id);
        $this->assertSame(1, RestitutionDeclaration::count());
        $this->assertSame(1, JournalEntree::where('action', 'declaration_restitution')->count());
        $this->assertSame(100, $this->declareRestitue());
        $this->assertSame(0, $this->echecs());

        // La même clé pour un autre contenu est refusée, et rien ne change.
        try {
            $this->tablette($this->lignes(80), $this->bloc($this->lignes(80), $horodatage), $horodatage);
            $this->fail('Une clé réutilisée pour un autre contenu devait être refusée.');
        } catch (RegleLogistique $e) {
            $this->assertSame("Clé d'idempotence déjà utilisée pour un autre contenu.", $e->getMessage());
        }
        $this->assertSame(1, RestitutionDeclaration::count());
        $this->assertSame(100, $this->declareRestitue());
        $this->assertSame(1, JournalEntree::where('action', 'anomalie_idempotence')->count());
    }

    public function test_retransmission_au_compte_idempotente(): void
    {
        $cle = (string) Str::uuid();
        $premiere = $this->compte($this->lignes(90), null, $cle);
        $seconde = $this->compte($this->lignes(90), null, $cle);

        $this->assertSame($premiere->id, $seconde->id);
        $this->assertSame(1, RestitutionDeclaration::count());
        $this->assertSame(1, JournalEntree::where('action', 'declaration_restitution')->count());

        $this->expectException(RegleLogistique::class);
        $this->compte($this->lignes(91), null, $cle);
    }

    public function test_excedent_a_verifier(): void
    {
        $declaration = $this->compte($this->lignes(120));

        $this->assertSame('a_verifier', $declaration->statut);
        $this->assertSame(['excedent'], $declaration->anomalies);
        // Rien n'est compté tant que le responsable n'a pas retenu la déclaration.
        $this->assertSame(0, $this->declareRestitue());

        $this->service()->retenirDeclaration($declaration, 'Vingt urnes d\'un autre lot rendues en même temps');

        $this->assertSame('retenue', $declaration->refresh()->statut);
        $this->assertSame(120, $this->declareRestitue());
        $arbitrage = JournalEntree::where('action', 'arbitrage_restitution')->sole();
        $this->assertSame($declaration->id, $arbitrage->objet_id);
        $this->assertSame('Vingt urnes d\'un autre lot rendues en même temps', $arbitrage->motif);
    }

    public function test_declarations_concordantes(): void
    {
        $this->compte($this->lignes(90));
        $horodatage = now()->toIso8601String();
        $tablette = $this->tablette($this->lignes(90), $this->bloc($this->lignes(90), $horodatage), $horodatage);

        $this->assertSame('concordante', $tablette->statut);
        $this->assertSame(['retenue', 'concordante'], RestitutionDeclaration::orderBy('id')->pluck('statut')->all());
        $this->assertSame(90, $this->declareRestitue());
    }

    public function test_divergence_puis_arbitrage(): void
    {
        // La tablette sans code n'est pas retenue ; le compte qui diverge la rejoint : toutes deux à vérifier.
        $tablette = $this->tablette($this->lignes(80), null, now()->toIso8601String());
        $compte = $this->compte($this->lignes(90));

        $this->assertSame(['a_verifier', 'a_verifier'], RestitutionDeclaration::orderBy('id')->pluck('statut')->all());
        $this->assertSame(['identite', 'divergence'], $tablette->refresh()->anomalies);
        $this->assertSame(['divergence'], $compte->refresh()->anomalies);
        $this->assertSame(0, $this->declareRestitue());

        // Une troisième déclaration du même mode est refusée.
        try {
            $this->compte($this->lignes(85));
            $this->fail('Une seconde déclaration au compte devait être refusée.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Une déclaration compte existe déjà pour cette restitution.', $e->getMessage());
        }

        // Seul le responsable arbitre.
        $this->actingAs(User::factory()->create(['role' => 'logistique_gestionnaire']));
        try {
            $this->service()->retenirDeclaration($tablette, 'Comptage de l\'agent');
            $this->fail('Un gestionnaire ne devait pas pouvoir arbitrer.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Seul un responsable logistique peut arbitrer une déclaration de restitution.', $e->getMessage());
        }
        $this->actingAs($this->responsable);
        try {
            $this->service()->retenirDeclaration($tablette, '   ');
            $this->fail('Un motif vide devait être refusé.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Le motif est obligatoire (500 caractères au plus).', $e->getMessage());
        }

        $this->service()->retenirDeclaration($tablette, 'Comptage de l\'agent confirmé au déchargement');

        $this->assertSame('retenue', $tablette->refresh()->statut);
        $this->assertSame('ecartee', $compte->refresh()->statut);
        $this->assertSame(80, $this->declareRestitue());
        $arbitrage = JournalEntree::where('action', 'arbitrage_restitution')->sole();
        $this->assertSame([$compte->id], $arbitrage->apres['ecartees']);

        // Plus rien à arbitrer sur une déclaration retenue ou écartée.
        $this->expectException(RegleLogistique::class);
        $this->service()->retenirDeclaration($compte, 'Revirement');
    }

    public function test_repli_sans_code_a_verifier(): void
    {
        $horodatage = now()->toIso8601String();

        $declaration = $this->tablette($this->lignes(100), null, $horodatage);

        $this->assertSame('a_verifier', $declaration->statut);
        $this->assertSame('non_verifiee', $declaration->verification_identite);
        $this->assertSame(['identite'], $declaration->anomalies);
        $this->assertSame($this->sousPrefet->id, $declaration->signataire_user_id);
        $this->assertSame(0, $this->declareRestitue());
    }

    public function test_repli_sans_code_ne_rattache_pas_un_compte_quelconque(): void
    {
        $quelconque = User::factory()->create(['role' => 'sous_prefet']);

        $declaration = $this->tablette($this->lignes(100), null, now()->toIso8601String(), self::CLE, null, $quelconque->id);

        $this->assertNull($declaration->signataire_user_id);
        $this->assertSame('a_verifier', $declaration->statut);
    }

    public function test_mauvais_code_echec_compte_et_a_verifier(): void
    {
        $horodatage = now()->toIso8601String();

        $declaration = $this->tablette($this->lignes(100), $this->bloc($this->lignes(100), $horodatage, '000111'), $horodatage);

        $this->assertSame('echec', $declaration->verification_identite);
        $this->assertSame('a_verifier', $declaration->statut);
        $this->assertSame(['identite'], $declaration->anomalies);
        $this->assertSame(1, $this->echecs());
    }

    public function test_lignes_controlees_avant_le_code(): void
    {
        $horodatage = now()->toIso8601String();
        $cas = [
            'Un article ne peut être déclaré qu\'une fois.' => [...$this->lignes(50), ...$this->lignes(50)],
            'Chaque ligne de la restitution doit être déclarée.' => [],
            'Article hors de cette restitution.' => [...$this->lignes(100), ['materiel_id' => $this->isoloirs->id, 'restitue' => 1, 'observation' => null]],
            'Les quantités restituées doivent être des entiers de 0 à 10 000 000.' => $this->lignes(-1),
        ];
        foreach ($cas as $message => $lignes) {
            try {
                $this->tablette($lignes, $this->bloc($this->lignes(100), $horodatage, '000111'), $horodatage);
                $this->fail("Les lignes devaient être refusées : {$message}");
            } catch (InvalidArgumentException|RegleLogistique $e) {
                $this->assertSame($message, $e->getMessage());
                // Un article hors de la restitution dépend de son état : une règle (422), pas une saisie invalide.
                $this->assertSame($message === 'Article hors de cette restitution.' ? RegleLogistique::class : InvalidArgumentException::class, $e::class);
            }
        }

        $this->assertSame(0, RestitutionDeclaration::count());
        $this->assertSame(0, $this->echecs());
    }

    public function test_retenue_d_office_figee_face_a_une_declaration_divergente(): void
    {
        $compte = $this->compte($this->lignes(90));
        $horodatage = now()->toIso8601String();
        $tablette = $this->tablette($this->lignes(80), $this->bloc($this->lignes(80), $horodatage), $horodatage);

        $this->assertSame('retenue', $compte->refresh()->statut);
        $this->assertNull($compte->anomalies);
        $this->assertSame('a_verifier', $tablette->refresh()->statut);
        $this->assertSame(['divergence'], $tablette->anomalies);
        $this->assertSame(90, $this->declareRestitue());

        // Seul l'arbitrage change la retenue.
        $this->service()->retenirDeclaration($tablette, 'Comptage au déchargement');
        $this->assertSame('ecartee', $compte->refresh()->statut);
        $this->assertSame(80, $this->declareRestitue());
    }

    public function test_redeclaration_des_chiffres_ecartes_apres_arbitrage(): void
    {
        $tablette = $this->tablette($this->lignes(80), null, now()->toIso8601String());
        $compte = $this->compte($this->lignes(90));
        $this->service()->retenirDeclaration($tablette, 'Comptage de l\'agent confirmé');
        $this->assertSame(80, $this->declareRestitue());

        // Le détenteur redéclare les chiffres écartés : à vérifier, la retenue ne bouge pas.
        $nouvelle = $this->compte($this->lignes(90));

        $this->assertSame('a_verifier', $nouvelle->statut);
        $this->assertSame(['divergence'], $nouvelle->anomalies);
        $this->assertSame('retenue', $tablette->refresh()->statut);
        $this->assertSame(['identite', 'divergence'], $tablette->anomalies);
        $this->assertSame('ecartee', $compte->refresh()->statut);
        $this->assertSame(80, $this->declareRestitue());
    }

    public function test_redeclaration_identique_d_une_retenue_confirme(): void
    {
        $tablette = $this->tablette($this->lignes(80), null, now()->toIso8601String());
        $this->service()->retenirDeclaration($tablette, 'Signataire reconnu par l\'agent');

        $compte = $this->compte($this->lignes(80));

        $this->assertSame('concordante', $compte->statut);
        $this->assertSame('retenue', $tablette->refresh()->statut);
        $this->assertSame(80, $this->declareRestitue());
    }

    public function test_excedent_retenu_puis_tablette_identique_a_verifier(): void
    {
        $compte = $this->compte($this->lignes(120));
        $this->service()->retenirDeclaration($compte, 'Vingt urnes d\'un autre lot rendues en même temps');
        $horodatage = now()->toIso8601String();

        $tablette = $this->tablette($this->lignes(120), $this->bloc($this->lignes(120), $horodatage), $horodatage);

        // Identique mais pas saine (excédent) : la nouvelle est à vérifier, la retenue reste telle quelle.
        $this->assertSame('a_verifier', $tablette->statut);
        $this->assertSame(['excedent'], $tablette->anomalies);
        $this->assertSame('retenue', $compte->refresh()->statut);
        $this->assertSame(['excedent'], $compte->anomalies);
        $this->assertSame(120, $this->declareRestitue());
    }

    public function test_recalcul_refuse_une_fois_declaree(): void
    {
        $this->compte($this->lignes(90));

        try {
            $this->service()->recalculer($this->restitution);
            $this->fail('Le recalcul devait être refusé une fois la restitution déclarée.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Cette restitution a déjà une déclaration : ses attendus ne peuvent plus être recalculés.', $e->getMessage());
        }
        $this->assertSame(100, (int) $this->restitution->lignes()->sole()->attendu);
    }

    public function test_verrou_de_la_restitution_avant_toute_autre_lecture(): void
    {
        // SQLite ne montre ni les verrous ni la course : on vérifie l'ordre des requêtes de chaque écriture. Le
        // verrou du cycle est suivi immédiatement de celui de la restitution (rien n'est lu entre les deux, sous
        // REPEATABLE READ l'instantané n'est donc pris qu'après le verrou de la restitution).
        DB::enableQueryLog();
        $horodatage = now()->toIso8601String();
        $this->tablette($this->lignes(80), $this->bloc($this->lignes(80), $horodatage), $horodatage);
        $this->compte($this->lignes(90));
        $this->service()->retenirDeclaration(RestitutionDeclaration::where('mode', 'compte')->sole(), 'Comptage du sous-préfet');
        $this->service()->affecterAgent($this->restitution, $this->agentRetour);
        $this->actingAs($this->agentRetour);
        $this->service()->prendreEnCharge($this->restitution, $this->agentRetour);
        $requetes = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $verrousDeCycle = 0;
        foreach ($requetes as $i => $requete) {
            if (! str_starts_with($requete, 'select * from "cycles_electoraux"')) {
                continue;
            }
            $verrousDeCycle++;
            $this->assertStringStartsWith('select * from "restitutions" where "restitutions"."id" = ?', $requetes[$i + 1] ?? '');
        }
        $this->assertSame(5, $verrousDeCycle);

        // Dans l'écriture d'une déclaration, le rejeu se lit après le verrou de la restitution.
        $cle = array_keys(array_filter($requetes, fn ($q) => str_contains($q, 'from "restitution_declarations" where "cle_idempotence" = ?')));
        $restitution = array_keys(array_filter($requetes, fn ($q) => str_starts_with($q, 'select * from "restitutions" where "restitutions"."id" = ?')));
        $this->assertNotEmpty($cle);
        $this->assertLessThan(max($cle), min($restitution));
    }

    public function test_filet_d_unicite_decide_d_apres_la_base(): void
    {
        $horodatage = now()->toIso8601String();
        $tablette = $this->tablette($this->lignes(100), $this->bloc($this->lignes(100), $horodatage), $horodatage);
        $compte = $this->compte($this->lignes(100), null, 'cle-compte-0000000001');
        // Le message d'une violation cite toutes les colonnes insérées : il ne dit pas laquelle est en double.
        $violation = new UniqueConstraintViolationException('sqlite',
            'insert into "restitution_declarations" ("restitution_id", "cle_idempotence", "bloc_empreinte") values (?, ?, ?)', [],
            new \PDOException('UNIQUE constraint failed: restitution_declarations.cle_idempotence'));
        $service = $this->service();
        $apresViolation = \Closure::bind(fn (...$arguments) => $this->apresViolation(...$arguments), $service, RestitutionService::class);

        // Une déclaration de cette clé, même envoi : rejeu (jamais « preuve déjà utilisée » au compte).
        [$rejouee, $conflit] = $apresViolation($violation, $this->restitution, 'compte', 'cle-compte-0000000001', $compte->empreinte_contenu, null, $this->sousPrefet);
        $this->assertSame($compte->id, $rejouee->id);
        $this->assertFalse($conflit);

        // Même clé, autre contenu : conflit.
        [, $conflit] = $apresViolation($violation, $this->restitution, 'compte', 'cle-compte-0000000001', str_repeat('0', 64), null, $this->sousPrefet);
        $this->assertTrue($conflit);

        // Clé libre, bloc déjà porté : preuve déjà utilisée.
        try {
            $apresViolation($violation, $this->restitution, 'tablette', 'cle-libre-000000000001', str_repeat('0', 64),
                ['bloc_empreinte' => $tablette->bloc_empreinte, 'signataire_user_id' => $this->sousPrefet->id], $this->agentRetour);
            $this->fail('Le bloc déjà porté devait être refusé.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Preuve déjà utilisée par une autre déclaration.', $e->getMessage());
        }

        // Ni l'une ni l'autre : l'erreur d'origine.
        try {
            $apresViolation($violation, $this->restitution, 'compte', 'cle-libre-000000000002', str_repeat('0', 64), null, $this->sousPrefet);
            $this->fail("L'erreur d'origine devait remonter.");
        } catch (UniqueConstraintViolationException $e) {
            $this->assertSame($violation, $e);
        }
    }

    public function test_bloc_d_une_remise_refuse_sur_une_restitution_sans_compter(): void
    {
        $horodatage = now()->toIso8601String();
        $contenuRemise = app(PreuveService::class)->contenuPreuve(
            $this->redistribution, self::SIGNATAIRE, $this->sousPrefet->id,
            [['materiel_id' => $this->urnes->id, 'recu' => 100, 'defectueux' => 0, 'observation' => null]], self::CLE, $horodatage,
        );
        $clair = json_encode(['code' => '000111', 'cle' => self::CLE, 'empreinte' => Canonique::empreinte($contenuRemise)]);
        $this->assertTrue(openssl_public_encrypt($clair, $chiffre, app(PreuveService::class)->clePublique(), OPENSSL_PKCS1_OAEP_PADDING));

        try {
            $this->tablette($this->lignes(100), base64_encode($chiffre), $horodatage);
            $this->fail("Le bloc d'une remise devait être refusé sur une restitution.");
        } catch (RegleLogistique $e) {
            $this->assertSame('Preuve ne correspondant pas au contenu de la déclaration.', $e->getMessage());
        }
        $this->assertSame(0, RestitutionDeclaration::count());
        $this->assertSame(0, $this->echecs());
    }

    public function test_bloc_d_une_restitution_refuse_sur_une_remise_sans_compter(): void
    {
        $horodatage = now()->toIso8601String();
        $bloc = $this->bloc($this->lignes(100), $horodatage, '000111');
        $contenuRemise = app(PreuveService::class)->contenuPreuve(
            $this->redistribution, self::SIGNATAIRE, $this->sousPrefet->id,
            [['materiel_id' => $this->urnes->id, 'recu' => 100, 'defectueux' => 0, 'observation' => null]], self::CLE, $horodatage,
        );

        try {
            app(PreuveService::class)->verifier($contenuRemise, self::CLE, $bloc, $this->sousPrefet, Carbon::parse($horodatage), null);
            $this->fail("Le bloc d'une restitution devait être refusé sur une remise.");
        } catch (RegleLogistique $e) {
            $this->assertSame('Preuve ne correspondant pas au contenu de la déclaration.', $e->getMessage());
        }
        $anomalie = JournalEntree::where('action', 'anomalie_preuve')->sole();
        $this->assertSame('remise', $anomalie->objet_type);
        $this->assertSame($this->redistribution->id, $anomalie->objet_id);
        $this->assertSame(0, $this->echecs());
    }

    public function test_delegue_signe_a_la_tablette_avec_son_propre_code(): void
    {
        $adjoint = User::factory()->create(['role' => 'sous_prefet']);
        $delegation = Delegation::create([
            'destinataire_id' => $this->sousPrefecture1->id, 'autorite_user_id' => $this->sousPrefet->id, 'delegue_user_id' => $adjoint->id,
            'du' => now()->subDay(), 'au' => now()->addDay(), 'motif' => 'Intérim', 'cree_par' => $this->responsable->id,
        ]);
        $this->definirCode($adjoint, '135790');
        $horodatage = now()->toIso8601String();

        $declaration = $this->tablette(
            $this->lignes(100), $this->bloc($this->lignes(100), $horodatage, '135790', self::CLE, $adjoint), $horodatage,
            self::CLE, null, $adjoint->id,
        );

        $this->assertSame('verifiee_code', $declaration->verification_identite);
        $this->assertSame($adjoint->id, $declaration->signataire_user_id);
        $this->assertSame($delegation->id, $declaration->delegation_id);
        $this->assertSame('retenue', $declaration->statut);
    }

    public function test_horodatage_avant_l_ouverture_echec_non_compte(): void
    {
        $horodatage = $this->restitution->created_at->copy()->subHour()->toIso8601String();

        $declaration = $this->tablette($this->lignes(100), $this->bloc($this->lignes(100), $horodatage), $horodatage);

        $this->assertSame('echec', $declaration->verification_identite);
        $this->assertSame('a_verifier', $declaration->statut);
        $this->assertSame(0, $this->echecs());
    }

    public function test_horodatage_avec_decalage_conserve_pour_la_preuve(): void
    {
        // La tablette signe à l'heure de Paris ; le serveur garde le texte pour la preuve et l'instant dans son fuseau.
        $instant = now()->setTimezone('Europe/Paris');
        $texte = $instant->format('Y-m-d\TH:i:sP');

        $declaration = $this->tablette($this->lignes(100), $this->bloc($this->lignes(100), $texte), $texte);

        $this->assertSame('verifiee_code', $declaration->verification_identite);
        $this->assertSame($instant->getTimestamp(), Carbon::parse($declaration->horodatage_terminal)->getTimestamp());
    }

    public function test_contenu_atteste_different_refuse_et_journalise_la_restitution(): void
    {
        $horodatage = now()->toIso8601String();

        try {
            $this->tablette($this->lignes(90), $this->bloc($this->lignes(100), $horodatage), $horodatage);
            $this->fail('Un bloc qui atteste un autre contenu devait être refusé.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Preuve ne correspondant pas au contenu de la déclaration.', $e->getMessage());
        }

        $anomalie = JournalEntree::where('action', 'anomalie_preuve')->sole();
        $this->assertSame('restitution', $anomalie->objet_type);
        $this->assertSame($this->restitution->id, $anomalie->objet_id);
        $this->assertSame(0, RestitutionDeclaration::count());
        $this->assertSame(0, $this->echecs());
    }

    public function test_bloc_deja_porte_par_une_declaration_de_restitution_refuse(): void
    {
        $horodatage = now()->toIso8601String();
        $bloc = $this->bloc($this->lignes(100), $horodatage);
        $declaration = $this->tablette($this->lignes(100), $bloc, $horodatage);

        $contenu = app(PreuveService::class)->contenuPreuveRestitution(
            $this->restitution, self::SIGNATAIRE, $this->sousPrefet->id, $this->lignes(100), self::CLE, $horodatage,
        );
        try {
            app(PreuveService::class)->verifierDans(
                ContextePreuve::restitution($this->restitution), $contenu, self::CLE, $bloc, $this->sousPrefet, Carbon::parse($horodatage), null,
            );
            $this->fail('Un bloc déjà porté devait être refusé.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Preuve déjà utilisée par une autre déclaration.', $e->getMessage());
        }

        $anomalie = JournalEntree::where('action', 'anomalie_preuve')->sole();
        $this->assertSame('restitution_declaration', $anomalie->objet_type);
        $this->assertSame($declaration->id, $anomalie->objet_id);
        $this->assertSame('bloc_reutilise', $anomalie->apres['nature']);
    }

    public function test_verrou_tenu_a_reessayer_sans_compter_d_echec(): void
    {
        config(['logistique.declaration_attente_verrou' => 0]);
        $verrou = Cache::lock('logistique:restitution-tablette:'.$this->restitution->id, 30);
        $this->assertTrue($verrou->get());
        $horodatage = now()->toIso8601String();

        try {
            $this->tablette($this->lignes(100), $this->bloc($this->lignes(100), $horodatage, '000111'), $horodatage);
            $this->fail('Un envoi pendant un autre devait être refusé, à réessayer.');
        } catch (DeclarationEnCours) {
            // 409 : à réessayer.
        } finally {
            $verrou->release();
        }

        $this->assertSame(0, RestitutionDeclaration::count());
        $this->assertSame(0, $this->echecs());
    }

    public function test_repli_sans_code_pour_un_agent_detenteur(): void
    {
        $detenteur = User::factory()->create(['role' => 'agent_transport']);
        $this->approuverPlanFictif([[$this->sousPrefecture1, $this->urnes, 200]]);
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100], $detenteur);
        $this->deposerRecue($decharge, [$this->urnes->id => 95]);
        $this->service()->ouvrir($this->cycle);
        $this->restitution = Restitution::where('detenteur_type', 'agent')->where('detenteur_id', $detenteur->id)->sole();
        $this->service()->affecterAgent($this->restitution, $this->agentRetour);

        $declaration = $this->tablette($this->lignes(5), null, now()->toIso8601String(), self::CLE, null, $detenteur->id);

        // L'agent détenteur reste rattaché comme signataire, sans que son identité soit établie.
        $this->assertSame($detenteur->id, $declaration->signataire_user_id);
        $this->assertSame('non_verifiee', $declaration->verification_identite);
        $this->assertSame('a_verifier', $declaration->statut);
    }

    public function test_prise_en_charge_par_l_agent(): void
    {
        // Sans déclaration retenue, pas de prise en charge.
        $this->actingAs($this->agentRetour);
        try {
            $this->service()->prendreEnCharge($this->restitution, $this->agentRetour);
            $this->fail('La prise en charge sans déclaration retenue devait être refusée.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Aucune déclaration retenue : le retour ne peut pas être pris en charge.', $e->getMessage());
        }

        $this->actingAs($this->responsable);
        $this->compte($this->lignes(90));
        $this->actingAs($this->agentRetour);
        $this->service()->prendreEnCharge($this->restitution, $this->agentRetour);

        $this->restitution->refresh();
        $this->assertSame('en_retour', $this->restitution->statut);
        $this->assertSame($this->agentRetour->id, $this->restitution->agent_id);
        $journal = JournalEntree::where('action', 'prise_en_charge_restitution')->sole();
        $this->assertSame($this->restitution->id, $journal->objet_id);
        $this->assertSame('en_retour', $journal->apres['statut']);
        $this->assertSame($this->agentRetour->id, $journal->apres['agent_id']);

        // Pris en charge : plus aucune déclaration, ni nouvelle prise en charge.
        $horodatage = now()->toIso8601String();
        try {
            $this->tablette($this->lignes(90), $this->bloc($this->lignes(90), $horodatage), $horodatage);
            $this->fail('Une déclaration après la prise en charge devait être refusée.');
        } catch (RegleLogistique $e) {
            $this->assertSame("Cette restitution n'attend plus de déclaration.", $e->getMessage());
        }
        $this->assertSame(0, $this->echecs());
        $this->expectException(RegleLogistique::class);
        $this->service()->prendreEnCharge($this->restitution, $this->agentRetour);
    }

    public function test_prise_en_charge_par_le_gestionnaire_pour_l_agent_designe(): void
    {
        $this->compte($this->lignes(100));
        $autre = User::factory()->create(['role' => 'agent_transport']);
        $this->actingAs(User::factory()->create(['role' => 'logistique_gestionnaire']));

        try {
            $this->service()->prendreEnCharge($this->restitution, $autre);
            $this->fail("Un agent autre que l'agent désigné ne devait pas prendre en charge.");
        } catch (RegleLogistique $e) {
            $this->assertSame("Cet agent n'est pas l'agent désigné pour ce retour.", $e->getMessage());
        }

        $this->service()->prendreEnCharge($this->restitution, $this->agentRetour);
        $this->assertSame('en_retour', $this->restitution->refresh()->statut);
    }

    public function test_agent_non_designe_ne_prend_pas_en_charge(): void
    {
        $this->compte($this->lignes(100));
        $autre = User::factory()->create(['role' => 'agent_transport']);
        $this->actingAs($autre);

        $this->expectException(ModelNotFoundException::class);
        $this->service()->prendreEnCharge($this->restitution, $autre);
    }

    public function test_agent_detenteur_declare_et_prend_en_charge_son_retour(): void
    {
        // Un agent charge 100 urnes ; le préfet n'en reçoit que 90 : 10 attendues de l'agent.
        $agent = User::factory()->create(['role' => 'agent_transport']);
        $this->approuverPlanFictif([[$this->sousPrefecture1, $this->urnes, 200]]);
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100], $agent);
        $this->deposerRecue($decharge, [$this->urnes->id => 90]);
        $this->service()->ouvrir($this->cycle);
        $restitution = Restitution::where('detenteur_type', 'agent')->where('detenteur_id', $agent->id)->sole();
        $this->assertSame(10, (int) $restitution->lignes()->sole()->attendu);

        // Un autre agent ne déclare pas pour lui.
        try {
            $this->compte($this->lignes(10), User::factory()->create(['role' => 'agent_transport']), null, $restitution);
            $this->fail('Un autre agent ne devait pas déclarer cette restitution.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Restitution hors de votre périmètre.', $e->getMessage());
        }

        $declaration = $this->compte($this->lignes(10), $agent, null, $restitution);
        $this->assertSame('retenue', $declaration->statut);
        $this->assertSame($agent->id, $declaration->signataire_user_id);

        $this->actingAs($agent);
        $this->service()->prendreEnCharge($restitution, $agent);

        $restitution->refresh();
        $this->assertSame('en_retour', $restitution->statut);
        $this->assertSame($agent->id, $restitution->agent_id);
    }

    public function test_hors_detenteur_refuse(): void
    {
        // Au compte : ni l'autorité d'une autre sous-préfecture, ni le préfet (l'autorité du détenteur seule).
        foreach ([$this->autoriteFictive($this->sousPrefecture2), $this->autoriteFictive($this->prefecture)] as $tiers) {
            try {
                $this->compte($this->lignes(100), $tiers);
                $this->fail('Une autorité autre que le détenteur ne devait pas déclarer.');
            } catch (RegleLogistique $e) {
                $this->assertSame('Restitution hors de votre périmètre.', $e->getMessage());
            }
        }

        // À la tablette : un agent qui n'est pas l'agent de retour désigné ne voit pas la restitution, et ne
        // peut pas tester le code du détenteur.
        $intrus = User::factory()->create(['role' => 'agent_transport']);
        $horodatage = now()->toIso8601String();
        foreach ([self::CODE, '000111'] as $code) {
            try {
                $this->tablette($this->lignes(100), $this->bloc($this->lignes(100), $horodatage, $code), $horodatage, self::CLE, $intrus);
                $this->fail('Un agent non désigné ne devait pas déclarer à la tablette.');
            } catch (ModelNotFoundException) {
                // 404 : la restitution n'existe pas pour lui.
            }
        }

        $this->assertSame(0, RestitutionDeclaration::count());
        $this->assertSame(0, $this->echecs());
        $this->assertSame(0, $this->declareRestitue());
    }

    public function test_affectation_de_l_agent_de_retour(): void
    {
        $autre = User::factory()->create(['role' => 'agent_transport']);

        $this->service()->affecterAgent($this->restitution, $autre);

        $this->assertSame($autre->id, $this->restitution->refresh()->agent_id);
        $journal = JournalEntree::where('action', 'affectation_agent_restitution')->orderByDesc('id')->first();
        $this->assertSame(['agent_id' => $this->agentRetour->id], $journal->avant);
        $this->assertSame(['agent_id' => $autre->id], $journal->apres);

        // L'ancien agent peut encore envoyer une saisie déjà faite : voir test_ancien_agent_de_retour_declaration_a_verifier.
        $refus = [
            'Seul un agent de transport actif peut être désigné pour le retour.' => [
                $this->responsable, User::factory()->create(['role' => 'agent_transport', 'actif' => false]),
            ],
        ];
        foreach ($refus as $message => $agents) {
            foreach ($agents as $agent) {
                try {
                    $this->service()->affecterAgent($this->restitution, $agent);
                    $this->fail($message);
                } catch (RegleLogistique $e) {
                    $this->assertSame($message, $e->getMessage());
                }
            }
        }

        $this->actingAs($this->sousPrefet);
        try {
            $this->service()->affecterAgent($this->restitution, $this->agentRetour);
            $this->fail('Une autorité de terrain ne devait pas désigner l\'agent.');
        } catch (RegleLogistique $e) {
            $this->assertSame("Seul un gestionnaire logistique peut désigner l'agent de retour.", $e->getMessage());
        }

        // Une fois le retour pris en charge, l'agent ne change plus.
        $this->actingAs($this->responsable);
        $this->compte($this->lignes(100));
        $this->service()->prendreEnCharge($this->restitution, $autre);
        $this->expectException(RegleLogistique::class);
        $this->service()->affecterAgent($this->restitution, $this->agentRetour);
    }

    public function test_ancien_agent_de_retour_declaration_a_verifier(): void
    {
        // Saisie faite hors connexion par l'agent désigné, puis un autre agent est désigné avant l'envoi (spec §13).
        $nouveau = User::factory()->create(['role' => 'agent_transport']);
        $this->service()->affecterAgent($this->restitution, $nouveau);
        $horodatage = now()->toIso8601String();
        $lignes = $this->lignes(100);
        $bloc = $this->bloc($lignes, $horodatage);

        $declaration = $this->tablette($lignes, $bloc, $horodatage);

        $this->assertSame('a_verifier', $declaration->statut);
        $this->assertSame(['affectation_retiree'], $declaration->anomalies);
        $this->assertSame('verifiee_code', $declaration->verification_identite, 'Les contrôles de preuve sont les mêmes.');
        $this->assertSame($this->agentRetour->id, $declaration->user_id);
        $this->assertSame(0, $this->declareRestitue(), 'Rien ne compte avant l\'arbitrage du responsable.');
        $this->assertSame(0, $this->echecs());

        // Rejeu de l'envoi : la déclaration enregistrée, sans rien refaire.
        $rejeu = $this->tablette($lignes, $bloc, $horodatage);
        $this->assertSame($declaration->id, $rejeu->id);
        $this->assertSame(1, RestitutionDeclaration::count());
        $this->assertSame(1, JournalEntree::where('action', 'declaration_restitution')->count());

        // Un mauvais code d'un ancien agent est compté comme pour l'agent désigné.
        $autreHorodatage = now()->addMinute()->toIso8601String();
        RestitutionDeclaration::query()->update(['statut' => 'ecartee']);
        $echec = $this->tablette($lignes, $this->bloc($lignes, $autreHorodatage, '000111', 'cle-restitution-0002'), $autreHorodatage, 'cle-restitution-0002');
        $this->assertSame('echec', $echec->verification_identite);
        $this->assertSame(['affectation_retiree', 'identite'], $echec->anomalies);
        $this->assertSame(1, $this->echecs());

        // Le nouvel agent ne prend pas en charge sur cette déclaration : elle attend l'arbitrage.
        $this->actingAs($nouveau);
        try {
            $this->service()->prendreEnCharge($this->restitution, $nouveau);
            $this->fail('Prise en charge acceptée sans déclaration retenue.');
        } catch (RegleLogistique) {
            $this->assertSame('a_preparer', $this->restitution->refresh()->statut);
        }
    }

    public function test_declaration_possible_sur_un_cycle_clos(): void
    {
        app(StockService::class)->cloturerCycle($this->cycle, 'Fin du scrutin');

        $declaration = $this->compte($this->lignes(100));

        $this->assertSame('retenue', $declaration->statut);
        $this->actingAs($this->agentRetour);
        $this->service()->prendreEnCharge($this->restitution, $this->agentRetour);
        $this->assertSame('en_retour', $this->restitution->refresh()->statut);
    }

    public function test_pieces_rattachees_et_visibles_du_detenteur_et_de_l_agent(): void
    {
        $octets = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=')."\xFF\xD9r";
        $photo = hash('sha256', $octets);
        $signature = hash('sha256', $octets.'s');
        $horodatage = now()->toIso8601String();

        // La photo est envoyée avant la déclaration, la signature est seulement annoncée.
        $this->actingAs($this->agentRetour);
        app(PieceJointeService::class)->enregistrer(UploadedFile::fake()->createWithContent('photo.jpg', $octets), $photo);
        $this->actingAs($this->responsable);
        $declaration = $this->tablette(
            $this->lignes(100), $this->bloc($this->lignes(100), $horodatage), $horodatage, self::CLE, null, null,
            [$photo => 'photo', $signature => 'signature'],
        );

        $pieces = $declaration->pieces()->get()->keyBy('empreinte');
        $this->assertNotNull($pieces[$photo]->piece_jointe_id);
        $this->assertNull($pieces[$signature]->piece_jointe_id);

        // La signature arrive ensuite : elle est rattachée.
        $this->actingAs($this->agentRetour);
        app(PieceJointeService::class)->enregistrer(UploadedFile::fake()->createWithContent('signature.jpg', $octets.'s'), $signature);
        $this->assertNotNull($declaration->pieces()->where('empreinte', $signature)->value('piece_jointe_id'));

        $pieceJointes = app(PieceJointeService::class);
        $this->assertNotNull($pieceJointes->visiblePar($this->sousPrefet, $photo));
        $this->assertNotNull($pieceJointes->visiblePar($this->agentRetour, $photo));
        $this->assertNotNull($pieceJointes->visiblePar($this->responsable, $photo));
        $this->assertNull($pieceJointes->visiblePar(User::factory()->create(['role' => 'agent_transport']), $photo));
        $this->assertNull($pieceJointes->visiblePar($this->autoriteFictive($this->sousPrefecture2), $photo));
    }

    public function test_piece_d_un_autre_compte_reste_attendue(): void
    {
        $octets = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=')."\xFF\xD9t";
        $empreinte = hash('sha256', $octets);
        $tiers = User::factory()->create(['role' => 'agent_transport']);
        $this->actingAs($tiers);
        app(PieceJointeService::class)->enregistrer(UploadedFile::fake()->createWithContent('photo.jpg', $octets), $empreinte);
        $this->actingAs($this->responsable);

        $horodatage = now()->toIso8601String();
        $declaration = $this->tablette(
            $this->lignes(100), $this->bloc($this->lignes(100), $horodatage), $horodatage, self::CLE, null, null, [$empreinte => 'photo'],
        );

        // L'auteur n'a pas envoyé ces octets : la pièce reste attendue.
        $this->assertNull($declaration->pieces()->sole()->piece_jointe_id);
    }
}

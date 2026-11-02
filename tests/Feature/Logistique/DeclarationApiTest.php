<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\DeclarationEnCours;
use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\DeclarationPiece;
use App\Models\Logistique\Delegation;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\PieceJointe;
use App\Models\Logistique\Remise;
use App\Models\User;
use App\Services\Logistique\CodeReceptionService;
use App\Services\Logistique\DeclarationService;
use App\Services\Logistique\DelegationService;
use App\Services\Logistique\PerimetreTerrain;
use App\Services\Logistique\PieceJointeService;
use App\Services\Logistique\PreuveService;
use App\Support\Canonique;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class DeclarationApiTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    private const CODE = '482915';

    private const CLE = 'cle-idempotence-0001';

    private static ?string $pem = null;

    private string $chemin;

    private User $prefet;

    private User $agent;

    private Decharge $chargee;

    private Remise $remise;

    private array $corpsFige = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::$pem ??= $this->genererCle();
        $this->chemin = storage_path('framework/testing/declaration-api-'.getmypid().'-'.uniqid().'.pem');
        file_put_contents($this->chemin, self::$pem);
        config(['logistique.cle_preuve_privee' => $this->chemin]);
        Storage::fake('pieces');
        config(['logistique.disque_pieces' => 'pieces']);

        $this->creerDecorFictif();
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 300], [$this->sousPrefecture1, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 1000, 400);
        $this->prefet = $this->autoriteFictive($this->prefecture);
        $this->definirCode($this->prefet, self::CODE);
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
        return Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->firstOrFail();
    }

    private function definirCode(User $u, string $code): void
    {
        $this->actingAs($u);
        app(CodeReceptionService::class)->choisir($u, $code, 'password');
        $this->actingAs($this->responsable);
    }

    private function jpeg(string $variante = 'a'): string
    {
        return base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=')."\xFF\xD9".$variante;
    }

    /** @return array<string, mixed> le corps de requête sans bloc ni pièces */
    private function corps(array $surcharge = [], ?Remise $remise = null, ?User $signataire = null): array
    {
        $remise ??= $this->remise;

        return array_replace([
            'mode' => 'tablette',
            'cle' => self::CLE,
            'horodatage_terminal' => now()->toIso8601String(),
            'signataire' => ['user_id' => ($signataire ?? $this->prefet)->id, 'nom' => 'Awa Diop', 'qualite' => 'Préfète'],
            'lignes' => [['materiel_id' => $this->urnes->id, 'recu' => 20, 'defectueux' => 1, 'observation' => 'Caisse écrasée']],
        ], $surcharge);
    }

    /** Le contenu que la tablette attesterait : celui de contenuPreuve, tel que le JS le produit. */
    private function contenu(array $corps, ?Remise $remise = null): array
    {
        return [
            'decharge_id' => (int) ($remise ?? $this->remise)->decharge_id,
            'decharge_identifiant' => Decharge::whereKey(($remise ?? $this->remise)->decharge_id)->value('identifiant'),
            'remise_id' => (int) ($remise ?? $this->remise)->id,
            'cle' => $corps['cle'],
            'signataire' => [
                'user_id' => $corps['signataire']['user_id'], 'nom' => $corps['signataire']['nom'], 'qualite' => $corps['signataire']['qualite'],
            ],
            'lignes' => array_map(fn (array $l) => [
                'materiel_id' => $l['materiel_id'], 'recu' => $l['recu'], 'defectueux' => $l['defectueux'], 'observation' => $l['observation'] ?? null,
            ], $corps['lignes']),
            'horodatage_terminal' => $corps['horodatage_terminal'],
        ];
    }

    /** Ajoute le bloc chiffré (RSA-OAEP, SHA-1) comme le fait la tablette. */
    private function avecBloc(array $corps, string $code = self::CODE, ?Remise $remise = null): array
    {
        $clair = json_encode(['code' => $code, 'cle' => $corps['cle'], 'empreinte' => Canonique::empreinte($this->contenu($corps, $remise))]);
        $this->assertTrue(openssl_public_encrypt($clair, $chiffre, app(PreuveService::class)->clePublique(), OPENSSL_PKCS1_OAEP_PADDING));

        return $corps + ['bloc_preuve' => base64_encode($chiffre)];
    }

    private function envoyer(array $corps, ?User $par = null, ?Remise $remise = null)
    {
        Sanctum::actingAs($par ?? $this->agent, ['terrain']);

        return $this->postJson('/api/terrain/remises/'.($remise ?? $this->remise)->id.'/declarations', $corps);
    }

    private function televerser(string $octets, ?User $par = null)
    {
        Sanctum::actingAs($par ?? $this->agent, ['terrain']);

        return $this->withHeaders(['Accept' => 'application/json'])->post('/api/terrain/pieces', [
            'fichier' => UploadedFile::fake()->createWithContent('piece.jpg', $octets),
            'empreinte' => hash('sha256', $octets),
        ]);
    }

    private function echecs(?User $u = null): int
    {
        return (int) ($u ?? $this->prefet)->fresh()->code_echecs;
    }

    public function test_declaration_tablette_avec_preuve_verifiee(): void
    {
        // Heure de la tablette avec un décalage : l'instant stocké est le même, quel que soit le fuseau du serveur.
        $corps = $this->avecBloc($this->corps([
            'horodatage_terminal' => now()->setTimezone('Europe/Paris')->format('Y-m-d\TH:i:sP'),
            'gps' => ['lat' => 14.716677, 'lng' => -17.467686, 'precision' => 12],
        ]));

        $reponse = $this->envoyer($corps)->assertCreated();

        $reponse->assertExactJson(['declaration' => [
            'id' => Declaration::firstOrFail()->id,
            'statut' => 'retenue',
            'verification_identite' => 'verifiee_code',
            'contestation' => 'aucune',
            'anomalies' => [],
        ]]);
        $declaration = Declaration::firstOrFail();
        $this->assertSame('tablette', $declaration->mode);
        $this->assertSame($this->agent->id, $declaration->user_id);
        $this->assertSame($this->prefet->id, $declaration->signataire_user_id);
        $this->assertNull($declaration->delegation_id);
        $this->assertSame(hash('sha256', base64_decode($corps['bloc_preuve'])), $declaration->bloc_empreinte);
        $this->assertSame('Awa Diop', $declaration->signataire_nom);
        $this->assertSame(Carbon::parse($corps['horodatage_terminal'])->getTimestamp(), $declaration->horodatage_terminal->getTimestamp());
        $this->assertEquals(14.716677, (float) $declaration->gps_lat);
        $this->assertEquals(-17.467686, (float) $declaration->gps_lng);
        $this->assertSame(12, $declaration->gps_precision);
        $this->assertSame([[$this->urnes->id, 20, 1, 'Caisse écrasée']], $declaration->lignes->map(fn ($l) => [$l->materiel_id, $l->recu, $l->defectueux, $l->observation])->all());
        $this->assertSame('recue', $this->remise->fresh()->statut);
        $this->assertSame('recue_destinataire', $this->chargee->fresh()->statut_acheminement);
        $this->assertSame(0, $this->echecs());
        $this->assertStringNotContainsString(self::CODE, JournalEntree::all()->toJson());
    }

    /**
     * Remplace PreuveService par une version qui appelle $avant (contenu, cle, bloc, signataire, instant) après
     * chaque vérification (la vraie est exécutée).
     */
    private function surveillerVerifier(\Closure $avant): void
    {
        $this->app->bind(PreuveService::class, fn ($app) => new class($app->make(CodeReceptionService::class), $app->make(DelegationService::class), $avant) extends PreuveService
        {
            public function __construct(CodeReceptionService $codes, DelegationService $delegations, private \Closure $avant)
            {
                parent::__construct($codes, $delegations);
            }

            public function verifier(array $contenu, string $cle, string $blocBase64, User $signataire, CarbonInterface $horodatageTerminal, ?Delegation $delegation): array
            {
                $resultat = parent::verifier($contenu, $cle, $blocBase64, $signataire, $horodatageTerminal, $delegation);
                // Après la vérification, avant l'enregistrement : là où un envoi concurrent s'intercalerait.
                ($this->avant)($contenu, $cle, $blocBase64, $signataire, $horodatageTerminal);

                return $resultat;
            }
        });
    }

    /** Ce qu'un envoi concurrent de la même clé aurait enregistré entre-temps (même contenu ; bloc au choix). */
    private function declarerEnConcurrence(array $corps, ?string $blocEmpreinte): void
    {
        app(DeclarationService::class)->declarer(
            Remise::findOrFail($this->remise->id), 'tablette', ['nom' => 'Awa Diop', 'qualite' => 'Préfète'],
            [$this->urnes->id => ['recu' => 20, 'defectueux' => 1, 'observation' => 'Caisse écrasée']],
            $corps['cle'], Carbon::parse($corps['horodatage_terminal']),
            ['verification' => 'verifiee_code', 'bloc_empreinte' => $blocEmpreinte, 'signataire_user_id' => $this->prefet->id, 'delegation_id' => null],
        );
    }

    public function test_verification_hors_de_toute_transaction(): void
    {
        $niveauAvant = DB::transactionLevel();
        $vu = [];
        $this->surveillerVerifier(function () use (&$vu) {
            $vu[] = DB::transactionLevel();
        });

        $corps = $this->avecBloc($this->corps());
        $this->envoyer($corps)->assertCreated();
        $this->envoyer($corps)->assertOk();

        // Une seule vérification (le rejeu n'en lance pas), au niveau de transaction d'avant la requête.
        $this->assertSame([$niveauAvant], $vu);
    }

    public function test_envoi_concurrent_de_la_meme_cle_ne_compte_qu_un_echec(): void
    {
        config(['logistique.declaration_attente_verrou' => 0]);
        $refuse = [];
        $dejaPasse = false;
        $this->surveillerVerifier(function () use (&$refuse, &$dejaPasse) {
            // Une seule fois : sans verrou, le second envoi vérifierait à son tour et rappellerait ce rappel sans fin.
            if ($dejaPasse) {
                return;
            }
            $dejaPasse = true;
            // Pendant la vérification du premier envoi, un second envoi identique arrive : il attend le verrou.
            try {
                app(DeclarationService::class)->declarerParTablette(
                    $this->remise, ['nom' => 'Awa Diop', 'qualite' => 'Préfète'], $this->corps()['lignes'], self::CLE,
                    $this->corpsFige['horodatage_terminal'], $this->prefet->id, $this->corpsFige['bloc_preuve'],
                );
            } catch (DeclarationEnCours) {
                $refuse[] = true;
            }
        });
        $this->corpsFige = $this->avecBloc($this->corps(), '000111');

        $this->envoyer($this->corpsFige)->assertCreated();

        $this->assertSame([true], $refuse);
        $this->assertSame(1, $this->echecs());
        $this->assertSame(1, Declaration::count());
        // Le second envoi, réessayé une fois le verrou rendu, retrouve la déclaration sans recompter.
        $this->envoyer($this->corpsFige)->assertOk();
        $this->assertSame(1, $this->echecs());
    }

    public function test_verrou_tenu_409_sans_compter_d_echec(): void
    {
        config(['logistique.declaration_attente_verrou' => 0]);
        $verrou = Cache::lock('logistique:declaration-tablette:'.$this->remise->id, 30);
        $this->assertTrue($verrou->get());

        $this->envoyer($this->avecBloc($this->corps(), '000111'))->assertStatus(409)
            ->assertExactJson(['message' => 'Déclaration en cours de traitement, réessayez.']);
        $this->assertSame(0, Declaration::count());
        $this->assertSame(0, $this->echecs());

        $verrou->release();
        $this->envoyer($this->avecBloc($this->corps()))->assertCreated();
    }

    public function test_rejeu_entre_la_verification_et_l_enregistrement_retrouve_la_declaration(): void
    {
        // Filet sous verrou du cycle (declarer) : un envoi identique enregistré entre-temps est rejoué, pas dupliqué.
        $corps = $this->avecBloc($this->corps());
        $empreinte = PreuveService::empreinteBloc($corps['bloc_preuve']);
        $this->surveillerVerifier(fn () => $this->declarerEnConcurrence($corps, $empreinte));

        $this->envoyer($corps)->assertOk()->assertJsonPath('declaration.verification_identite', 'verifiee_code');
        $this->assertSame(1, Declaration::count());
    }

    public function test_enregistrement_concurrent_avec_un_autre_bloc_refuse(): void
    {
        $corps = $this->avecBloc($this->corps());
        $this->surveillerVerifier(fn () => $this->declarerEnConcurrence($corps, hash('sha256', 'autre bloc')));

        $this->envoyer($corps)->assertStatus(422)
            ->assertJsonPath('message', "Clé d'idempotence déjà utilisée pour un autre contenu.");
        $this->assertSame(1, Declaration::count());
    }

    public function test_violation_d_unicite_sur_la_cle_rejoue_l_enregistrement_gagnant(): void
    {
        $corps = $this->avecBloc($this->corps());
        $empreinte = PreuveService::empreinteBloc($corps['bloc_preuve']);
        $service = new class(app(PerimetreTerrain::class), app(DelegationService::class), app(PreuveService::class), app(PieceJointeService::class)) extends DeclarationService
        {
            public bool $fait = false;

            public ?\Closure $concurrent = null;

            public function declarer(Remise $remise, string $mode, array $signataire, array $lignes, string $cle, ?CarbonInterface $horodatageTerminal = null, ?array $preuve = null, array $pieces = [], ?array $gps = null): Declaration
            {
                if (! $this->fait) {
                    $this->fait = true;
                    // Un autre envoi de la même clé a gagné la course à l'insertion.
                    parent::declarer($remise, $mode, $signataire, $lignes, $cle, $horodatageTerminal, $preuve, $pieces, $gps);

                    throw new UniqueConstraintViolationException('sqlite', 'insert into "declarations" ...', [], new \PDOException('UNIQUE constraint failed: declarations.cle_idempotence'));
                }

                return parent::declarer($remise, $mode, $signataire, $lignes, $cle, $horodatageTerminal, $preuve, $pieces, $gps);
            }
        };
        $this->app->instance(DeclarationService::class, $service);

        $this->envoyer($corps)->assertOk()->assertJsonPath('declaration.statut', 'retenue');
        $this->assertSame(1, Declaration::count());
        $this->assertSame(0, $this->echecs());
        $this->assertNotNull($empreinte);
    }

    public function test_declaration_de_tablette_sans_preuve_non_verifiee(): void
    {
        $this->actingAs($this->agent);
        $declaration = app(DeclarationService::class)->declarer(
            $this->remise, 'tablette', ['nom' => 'Awa Diop', 'qualite' => 'Préfète'],
            [$this->urnes->id => ['recu' => 20, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(),
        );

        $declaration = $declaration->fresh();
        $this->assertSame('non_verifiee', $declaration->verification_identite);
        $this->assertSame(['identite'], $declaration->anomalies);
        $this->assertSame('a_verifier', $declaration->statut);
    }

    public function test_retransmission_identique_trois_fois(): void
    {
        // Un code faux est compté une seule fois : les retransmissions ne rappellent pas la vérification.
        $corps = $this->avecBloc($this->corps(['pieces' => [['empreinte' => hash('sha256', $this->jpeg()), 'role' => 'photo']]]), '000111');

        $premiere = $this->envoyer($corps)->assertCreated();
        $this->assertSame('echec', $premiere->json('declaration.verification_identite'));
        $this->assertSame(1, $this->echecs());

        $deuxieme = $this->envoyer($corps)->assertOk();
        $troisieme = $this->envoyer($corps)->assertOk();

        $this->assertSame($premiere->json(), $deuxieme->json());
        $this->assertSame($premiere->json(), $troisieme->json());
        $this->assertSame(1, Declaration::count());
        $this->assertSame(1, Declaration::firstOrFail()->lignes()->count());
        $this->assertSame(1, DeclarationPiece::count());
        $this->assertSame(1, $this->echecs());
        $this->assertSame(1, JournalEntree::where('action', 'declaration_reception')->count());
    }

    public function test_meme_cle_contenu_different_refuse(): void
    {
        $corps = $this->avecBloc($this->corps());
        $this->envoyer($corps)->assertCreated();

        $autre = $this->corps(['lignes' => [['materiel_id' => $this->urnes->id, 'recu' => 19, 'defectueux' => 0, 'observation' => null]]]);
        $this->envoyer($this->avecBloc($autre))->assertStatus(422)
            ->assertExactJson(['message' => "Clé d'idempotence déjà utilisée pour un autre contenu."]);

        $this->assertSame(1, Declaration::count());
        $this->assertSame(0, $this->echecs());
        $this->assertSame(1, JournalEntree::where('action', 'anomalie_idempotence')->count());
    }

    public function test_meme_cle_meme_contenu_bloc_different_refuse(): void
    {
        // Le chiffrement RSA-OAEP est aléatoire : un second chiffrement du même clair est un autre bloc.
        $this->envoyer($this->avecBloc($this->corps()))->assertCreated();

        $this->envoyer($this->avecBloc($this->corps()))->assertStatus(422)
            ->assertExactJson(['message' => "Clé d'idempotence déjà utilisée pour un autre contenu."]);
        $this->assertSame(1, Declaration::count());
        $this->assertSame(0, $this->echecs());
    }

    public function test_repli_puis_rejeu_avec_bloc_refuse_et_sans_bloc_accepte(): void
    {
        $this->envoyer($this->corps())->assertCreated();

        $this->envoyer($this->corps())->assertOk();
        $this->envoyer($this->avecBloc($this->corps()))->assertStatus(422)
            ->assertJsonPath('message', "Clé d'idempotence déjà utilisée pour un autre contenu.");
        $this->assertSame(1, Declaration::count());
    }

    public function test_rejeu_par_un_autre_utilisateur_refuse(): void
    {
        $corps = $this->avecBloc($this->corps());
        $this->envoyer($corps)->assertCreated();

        // Le nouvel agent de la décharge est dans le périmètre, mais la clé n'est pas la sienne.
        $nouvel = User::factory()->create(['role' => 'agent_transport']);
        $this->chargee->forceFill(['agent_id' => $nouvel->id])->save();

        $this->envoyer($corps, $nouvel)->assertStatus(422)
            ->assertJsonPath('message', "Clé d'idempotence déjà utilisée pour un autre contenu.");
    }

    public function test_bloc_deja_utilise_par_une_autre_declaration(): void
    {
        $corps = $this->avecBloc($this->corps());
        $this->envoyer($corps)->assertCreated();

        $this->actingAs($this->responsable);
        $autre = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 10], $this->agent);
        $remiseAutre = $this->livraison($autre);
        $rejoue = ['cle' => 'cle-idempotence-0002', 'bloc_preuve' => $corps['bloc_preuve']] + $this->corps([], $remiseAutre);

        $this->envoyer($rejoue, null, $remiseAutre)->assertStatus(422)
            ->assertExactJson(['message' => 'Preuve déjà utilisée par une autre déclaration.']);

        $this->assertSame(1, Declaration::count());
        $this->assertSame(1, JournalEntree::where('action', 'anomalie_preuve')->count());
        $this->assertSame(0, $this->echecs());
    }

    public function test_bloc_ne_correspondant_pas_au_contenu_refuse(): void
    {
        $corps = $this->avecBloc($this->corps());
        $corps['lignes'][0]['recu'] = 19;

        $this->envoyer($corps)->assertStatus(422)
            ->assertExactJson(['message' => 'Preuve ne correspondant pas au contenu de la déclaration.']);

        $this->assertSame(0, Declaration::count());
        $this->assertSame(1, JournalEntree::where('action', 'anomalie_preuve')->count());
        $this->assertSame(0, $this->echecs());
    }

    public function test_photo_envoyee_apres_la_declaration_rattachee(): void
    {
        $signature = $this->jpeg('s');
        $photo = $this->jpeg('p');
        $this->televerser($signature)->assertCreated();

        $corps = $this->avecBloc($this->corps(['pieces' => [
            ['empreinte' => hash('sha256', $signature), 'role' => 'signature'],
            ['empreinte' => hash('sha256', $photo), 'role' => 'photo'],
        ]]));
        $this->envoyer($corps)->assertCreated();

        $lignes = DeclarationPiece::orderBy('role', 'desc')->get();
        $this->assertCount(2, $lignes);
        $this->assertSame('signature', $lignes[0]->role);
        $this->assertNotNull($lignes[0]->piece_jointe_id);
        $this->assertSame('photo', $lignes[1]->role);
        $this->assertNull($lignes[1]->piece_jointe_id);

        // La photo arrive plus tard (envoi interrompu puis repris) : elle est rattachée.
        $this->televerser($photo)->assertCreated();

        $this->assertSame(PieceJointe::where('empreinte', hash('sha256', $photo))->value('id'), DeclarationPiece::where('role', 'photo')->value('piece_jointe_id'));
        $this->assertSame(0, DeclarationPiece::whereNull('piece_jointe_id')->count());
    }

    public function test_signature_et_photo_envoyees_apres_la_declaration_rattachees(): void
    {
        // Signature et photo interrompues : la déclaration est enregistrée sans les attendre, avec leurs empreintes.
        $signature = $this->jpeg('s');
        $photo = $this->jpeg('p');
        $corps = $this->avecBloc($this->corps(['pieces' => [
            ['empreinte' => hash('sha256', $signature), 'role' => 'signature'],
            ['empreinte' => hash('sha256', $photo), 'role' => 'photo'],
        ]]));
        $this->envoyer($corps)->assertCreated()->assertJsonPath('declaration.statut', 'retenue')
            ->assertJsonPath('declaration.verification_identite', 'verifiee_code');
        $this->assertSame(2, DeclarationPiece::whereNull('piece_jointe_id')->count());

        // Les pièces arrivent plus tard, dans n'importe quel ordre ; le rejeu de la déclaration n'y change rien.
        $this->televerser($photo)->assertCreated();
        $this->envoyer($corps)->assertOk();
        $this->televerser($signature)->assertCreated();

        $this->assertSame(0, DeclarationPiece::whereNull('piece_jointe_id')->count());
        $this->assertSame(
            PieceJointe::where('empreinte', hash('sha256', $signature))->value('id'),
            DeclarationPiece::where('role', 'signature')->value('piece_jointe_id'),
        );
        $this->assertSame(1, Declaration::count());
    }

    public function test_repli_sans_code_a_verifier(): void
    {
        $reponse = $this->envoyer($this->corps())->assertCreated();

        $reponse->assertJsonPath('declaration.verification_identite', 'non_verifiee')
            ->assertJsonPath('declaration.statut', 'a_verifier')
            ->assertJsonPath('declaration.anomalies', ['identite']);
        $declaration = Declaration::firstOrFail();
        $this->assertNull($declaration->bloc_empreinte);
        $this->assertSame($this->prefet->id, $declaration->signataire_user_id);
        $this->assertSame('a_verifier', $this->remise->fresh()->statut);
        $this->assertSame(0, $this->echecs());
    }

    public function test_repli_sans_autorite_rattachee_nom_et_qualite_saisis(): void
    {
        // Aucun compte rattaché au destinataire : la tablette n'a pas d'identifiant de signataire à envoyer.
        $this->prefecture->forceFill(['autorite_user_id' => null])->save();
        $corps = $this->corps(['signataire' => ['nom' => 'Mame Diarra Fall', 'qualite' => 'Préfet par intérim']]);

        $this->envoyer($corps)->assertCreated()
            ->assertJsonPath('declaration.verification_identite', 'non_verifiee')
            ->assertJsonPath('declaration.statut', 'a_verifier')
            ->assertJsonPath('declaration.anomalies', ['identite']);

        $declaration = Declaration::firstOrFail();
        $this->assertNull($declaration->signataire_user_id);
        $this->assertNull($declaration->bloc_empreinte);
        $this->assertSame(['Mame Diarra Fall', 'Préfet par intérim'], [$declaration->signataire_nom, $declaration->signataire_qualite]);
        $this->assertSame(0, $this->echecs());

        // Rejeu identique : le même résultat ; identifiant explicitement nul : accepté de même.
        $this->envoyer($corps)->assertOk();
        $this->envoyer($this->corps(['cle' => 'cle-idempotence-0003', 'signataire' => ['user_id' => null, 'nom' => 'X', 'qualite' => 'Y']]))
            ->assertStatus(422)->assertJsonPath('message', 'Une déclaration tablette existe déjà pour cette remise.');
    }

    public function test_bloc_de_preuve_sans_signataire_identifie_refuse(): void
    {
        $corps = $this->avecBloc($this->corps());
        unset($corps['signataire']['user_id']);

        $this->envoyer($corps)->assertStatus(422)->assertJsonValidationErrors('signataire.user_id');
        $this->assertSame(0, Declaration::count());
    }

    public function test_lignes_controlees_avant_le_code(): void
    {
        // Défectueux supérieur au reçu : refusé dès la validation, le code faux n'est pas compté.
        $defectueux = $this->corps(['lignes' => [['materiel_id' => $this->urnes->id, 'recu' => 5, 'defectueux' => 9, 'observation' => null]]]);
        $this->envoyer($this->avecBloc($defectueux, '000111'))->assertStatus(422)->assertJsonValidationErrors('lignes.0.defectueux');

        // Un article absent de la remise : refusé par le service avant toute vérification du code.
        $autreArticle = $this->corps(['lignes' => [
            ['materiel_id' => $this->urnes->id, 'recu' => 20, 'defectueux' => 0, 'observation' => null],
            ['materiel_id' => $this->isoloirs->id, 'recu' => 3, 'defectueux' => 0, 'observation' => null],
        ]]);
        $this->envoyer($this->avecBloc($autreArticle, '000111'))->assertStatus(422)
            ->assertExactJson(['message' => 'Chaque ligne de la remise doit être déclarée.']);

        // Directement au service : défectueux supérieur au reçu, refusé avant le code.
        $this->actingAs($this->agent);
        $corps = $this->avecBloc($defectueux, '000111');
        try {
            app(DeclarationService::class)->declarerParTablette(
                $this->remise, ['nom' => 'Awa Diop', 'qualite' => 'Préfète'], $corps['lignes'], self::CLE,
                $corps['horodatage_terminal'], $this->prefet->id, $corps['bloc_preuve'],
            );
            $this->fail('Une ligne incohérente devait être refusée.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringStartsWith('Défectueux supérieur au reçu', $e->getMessage());
        }

        $this->assertSame(0, Declaration::count());
        $this->assertSame(0, $this->echecs());
        $this->assertSame(0, JournalEntree::where('action', 'echec_code_reception')->count());
    }

    public function test_horodatage_de_la_tablette_ramene_au_fuseau_avant_la_delegation(): void
    {
        // La délégation finit dans une heure ; l'adjoint signe 50 minutes après son début, à l'heure de Paris
        // (+01:00) : lue comme une heure du serveur, la signature tomberait après la fin.
        $debut = now()->startOfSecond();
        $adjoint = User::factory()->create(['role' => 'sous_prefet']);
        $this->definirCode($adjoint, '864209');
        $this->actingAs($this->prefet);
        $delegation = app(DelegationService::class)->deleguer($this->prefecture, $adjoint, $debut, $debut->copy()->addHour(), 'Mission');
        $this->actingAs($this->responsable);
        Carbon::setTestNow($debut->copy()->addMinutes(55));

        $corps = $this->corps(['horodatage_terminal' => $debut->copy()->addMinutes(50)->setTimezone('+01:00')->format('Y-m-d\TH:i:sP')], null, $adjoint);
        $this->envoyer($this->avecBloc($corps, '864209'))->assertCreated()
            ->assertJsonPath('declaration.verification_identite', 'verifiee_code')
            ->assertJsonPath('declaration.statut', 'retenue');

        $declaration = Declaration::firstOrFail();
        $this->assertSame($delegation->id, $declaration->delegation_id);
        $this->assertSame($debut->copy()->addMinutes(50)->getTimestamp(), $declaration->horodatage_terminal->getTimestamp());
    }

    public function test_mauvais_code_echec_a_verifier(): void
    {
        $this->envoyer($this->avecBloc($this->corps(), '000111'))->assertCreated()
            ->assertJsonPath('declaration.verification_identite', 'echec')
            ->assertJsonPath('declaration.statut', 'a_verifier')
            ->assertJsonPath('declaration.anomalies', ['identite']);

        $this->assertSame(1, $this->echecs());
        $this->assertSame('a_verifier', $this->remise->fresh()->statut);
    }

    public function test_affectation_retiree_a_verifier(): void
    {
        $nouvel = User::factory()->create(['role' => 'agent_transport']);
        $this->chargee->forceFill(['agent_id' => $nouvel->id])->save();

        $this->envoyer($this->avecBloc($this->corps()))->assertCreated()
            ->assertJsonPath('declaration.verification_identite', 'verifiee_code')
            ->assertJsonPath('declaration.statut', 'a_verifier')
            ->assertJsonPath('declaration.anomalies', ['affectation_retiree']);
    }

    public function test_jeton_d_un_autre_agent_refuse(): void
    {
        $autreAgent = User::factory()->create(['role' => 'agent_transport']);

        // Un code faux d'un agent hors périmètre n'est même pas compté : la vérification n'a pas lieu.
        $this->envoyer($this->avecBloc($this->corps(), '000111'), $autreAgent)->assertNotFound()
            ->assertExactJson(['message' => 'Ressource introuvable.']);

        $this->assertSame(0, Declaration::count());
        $this->assertSame(0, $this->echecs());

        $gestionnaire = User::factory()->create(['role' => 'logistique_gestionnaire']);
        $this->envoyer($this->corps(), $gestionnaire)->assertForbidden();
        $this->assertSame(0, Declaration::count());
    }

    public function test_acces_sans_jeton_et_remise_inconnue(): void
    {
        // Aucun compte connecté (le décor en avait ouvert un en session).
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/terrain/remises/'.$this->remise->id.'/declarations', $this->corps())->assertUnauthorized();

        Sanctum::actingAs($this->agent, ['terrain']);
        $this->postJson('/api/terrain/remises/999999/declarations', $this->corps())->assertNotFound();
    }

    public function test_delegue_signe_avec_son_propre_code(): void
    {
        $adjoint = User::factory()->create(['role' => 'sous_prefet']);
        $this->definirCode($adjoint, '864209');
        $this->actingAs($this->prefet);
        $delegation = app(DelegationService::class)->deleguer($this->prefecture, $adjoint, now()->subMinute(), null, 'Mission');
        $this->actingAs($this->responsable);

        $corps = $this->corps([], null, $adjoint);
        $this->envoyer($this->avecBloc($corps, '864209'))->assertCreated()
            ->assertJsonPath('declaration.verification_identite', 'verifiee_code')
            ->assertJsonPath('declaration.statut', 'retenue');

        $declaration = Declaration::firstOrFail();
        $this->assertSame($adjoint->id, $declaration->signataire_user_id);
        $this->assertSame($delegation->id, $declaration->delegation_id);
    }

    public function test_signataire_sans_delegation_ni_titre_echec(): void
    {
        $intrus = User::factory()->create(['role' => 'sous_prefet']);
        $this->definirCode($intrus, '864209');

        $this->envoyer($this->avecBloc($this->corps([], null, $intrus), '864209'))->assertCreated()
            ->assertJsonPath('declaration.verification_identite', 'echec')
            ->assertJsonPath('declaration.statut', 'a_verifier');
        $this->assertSame(0, $this->echecs($intrus));
    }

    public function test_signataire_inconnu_echec(): void
    {
        $corps = $this->corps(['signataire' => ['user_id' => 987654, 'nom' => 'Inconnu', 'qualite' => 'Préfet']]);
        $corps = $corps + ['bloc_preuve' => base64_encode(str_repeat('a', 256))];

        $this->envoyer($corps)->assertCreated()
            ->assertJsonPath('declaration.verification_identite', 'echec')
            ->assertJsonPath('declaration.statut', 'a_verifier');
        $this->assertNull(Declaration::firstOrFail()->signataire_user_id);
        $this->envoyer($corps)->assertOk();
    }

    public function test_deuxieme_declaration_tablette_refusee_sans_compter_d_echec(): void
    {
        $this->envoyer($this->avecBloc($this->corps()))->assertCreated();

        $deuxieme = $this->corps(['cle' => 'cle-idempotence-0009']);
        $this->envoyer($this->avecBloc($deuxieme, '000111'))->assertStatus(422)
            ->assertJsonPath('message', 'Une déclaration tablette existe déjà pour cette remise.');
        $this->assertSame(0, $this->echecs());
    }

    public function test_pieces_et_gps_valides_a_la_validation(): void
    {
        $empreinte = hash('sha256', 'x');
        foreach ([
            ['pieces' => [['empreinte' => 'abc', 'role' => 'photo']]],
            ['pieces' => [['empreinte' => $empreinte, 'role' => 'bordereau']]],
            ['pieces' => [['empreinte' => $empreinte, 'role' => 'photo'], ['empreinte' => $empreinte, 'role' => 'signature']]],
            ['gps' => ['lat' => 91, 'lng' => 0, 'precision' => 1]],
            ['gps' => ['lat' => 0, 'lng' => 181, 'precision' => 1]],
            ['gps' => ['lat' => 0, 'lng' => 0, 'precision' => -1]],
        ] as $surcharge) {
            $this->envoyer($this->corps($surcharge))->assertStatus(422);
        }
        $this->assertSame(0, Declaration::count());
    }

    public function test_horodatage_strict_avec_decalage(): void
    {
        foreach (['2026-11-05T10:00:00', '2026-11-05T10:00:00Z', '2026-11-05 10:00:00+00:00', '2026-11-05T10:00:00.123+00:00', 'hier', '2026-13-45T10:00:00+00:00'] as $mauvais) {
            $this->envoyer($this->corps(['horodatage_terminal' => $mauvais]))->assertStatus(422);
        }
        $this->assertSame(0, Declaration::count());

        $this->envoyer($this->corps(['horodatage_terminal' => now()->setTimezone('Africa/Dakar')->format('Y-m-d\TH:i:sP')]))->assertCreated();
    }

    public function test_cle_et_corps_invalides(): void
    {
        $this->envoyer($this->corps(['cle' => 'courte']))->assertStatus(422);
        $this->envoyer($this->corps(['cle' => str_repeat('a', 65)]))->assertStatus(422);
        $this->envoyer($this->corps(['cle' => 'cle avec espaces!!!']))->assertStatus(422);
        $this->envoyer($this->corps(['mode' => 'compte']))->assertStatus(422);
        $this->envoyer($this->corps(['lignes' => []]))->assertStatus(422);
        $doublon = ['materiel_id' => $this->urnes->id, 'recu' => 1, 'defectueux' => 0];
        $this->envoyer($this->corps(['lignes' => [$doublon, $doublon]]))->assertStatus(422);
        // Défectueux supérieur au reçu : refusé dès la validation de la requête (le service le refuse aussi).
        $this->envoyer($this->corps(['lignes' => [['materiel_id' => $this->urnes->id, 'recu' => 5, 'defectueux' => 9]]]))->assertStatus(422)
            ->assertJsonValidationErrors('lignes.0.defectueux');
        $this->assertSame(0, Declaration::count());
    }

    public function test_voir_photo_selon_perimetre(): void
    {
        $photo = $this->jpeg('p');
        $empreinte = hash('sha256', $photo);
        $this->televerser($photo)->assertCreated();
        $this->envoyer($this->avecBloc($this->corps(['pieces' => [['empreinte' => $empreinte, 'role' => 'photo']]])))->assertCreated();

        $autreAgent = User::factory()->create(['role' => 'agent_transport']);
        $autrePrefet = $this->autoriteFictive($this->sousPrefecture1);
        $this->definirCode($autrePrefet, '135790');
        $gestionnaire = User::factory()->create(['role' => 'logistique_gestionnaire']);

        // Le gestionnaire central, l'agent de la décharge et l'autorité destinataire la voient.
        $this->actingAs($gestionnaire)->get('/logistique/pieces/'.$empreinte)->assertOk();
        $reponse = $this->actingAs($this->agent)->get('/terrain/pieces/'.$empreinte)->assertOk();
        $this->assertSame($photo, $reponse->streamedContent());
        $this->assertSame('image/jpeg', $reponse->headers->get('Content-Type'));
        $this->assertSame('nosniff', $reponse->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $reponse->headers->get('Cache-Control'));
        $this->actingAs($this->prefet)->get('/terrain/pieces/'.$empreinte)->assertOk();

        // Hors périmètre : 404, comme pour une pièce qui n'existe pas.
        $this->actingAs($autreAgent)->get('/terrain/pieces/'.$empreinte)->assertNotFound();
        $this->actingAs($autrePrefet)->get('/terrain/pieces/'.$empreinte)->assertNotFound();
        $this->actingAs($this->prefet)->get('/terrain/pieces/'.hash('sha256', 'inconnue'))->assertNotFound();
        $this->actingAs($this->prefet)->get('/logistique/pieces/'.$empreinte)->assertForbidden();
        $this->actingAs($gestionnaire)->get('/logistique/pieces/'.hash('sha256', 'inconnue'))->assertNotFound();
    }

    public function test_affectation_retiree_agent_ne_voit_plus_la_photo(): void
    {
        $photo = $this->jpeg('p');
        $empreinte = hash('sha256', $photo);
        $this->televerser($photo);
        $this->envoyer($this->avecBloc($this->corps(['pieces' => [['empreinte' => $empreinte, 'role' => 'photo']]])))->assertCreated();

        $this->chargee->forceFill(['agent_id' => User::factory()->create(['role' => 'agent_transport'])->id])->save();

        $this->actingAs($this->agent)->get('/terrain/pieces/'.$empreinte)->assertNotFound();
    }

    public function test_piece_d_un_autre_compte_attendue_jusqu_a_l_envoi_par_son_auteur(): void
    {
        $photo = $this->jpeg('x');
        $empreinte = hash('sha256', $photo);
        $autre = User::factory()->create(['role' => 'agent_transport']);
        $this->televerser($photo, $autre)->assertCreated();

        // L'agent annonce des octets que seul un autre compte a déposés : il ne peut pas se les attribuer.
        $this->envoyer($this->avecBloc($this->corps(['pieces' => [['empreinte' => $empreinte, 'role' => 'photo']]])))->assertCreated();
        $this->assertNull(DeclarationPiece::firstOrFail()->piece_jointe_id);
        $this->call('HEAD', '/api/terrain/pieces/'.$empreinte)->assertNotFound();

        // Il prouve les posséder en les envoyant : doublon (200), et la pièce attendue est liée.
        $this->televerser($photo)->assertOk();
        $this->assertSame(PieceJointe::firstOrFail()->id, DeclarationPiece::firstOrFail()->piece_jointe_id);
        $this->call('HEAD', '/api/terrain/pieces/'.$empreinte)->assertOk();
        $this->assertSame(1, PieceJointe::count());
    }

    /* ── Service : mode compte et filet de concurrence ── */

    public function test_disque_des_pieces_hors_de_public(): void
    {
        $racine = (string) config('filesystems.disks.'.(require config_path('logistique.php'))['disque_pieces'].'.root');
        $this->assertNotSame('', $racine);
        $this->assertStringStartsNotWith(realpath(public_path()) ?: public_path(), $racine);
        $this->assertStringNotContainsString(DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR, $racine.DIRECTORY_SEPARATOR);
    }

    public function test_mode_compte_verification_compte(): void
    {
        $this->actingAs($this->prefet);
        $declaration = app(DeclarationService::class)->declarer(
            $this->remise, 'compte', ['nom' => 'Awa Diop', 'qualite' => 'Préfète'],
            [$this->urnes->id => ['recu' => 20, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(),
        );

        $this->assertSame('compte', $declaration->fresh()->verification_identite);
        $this->assertSame('retenue', $declaration->statut);
        $this->assertSame([], $declaration->anomalies ?? []);
        $this->assertNull($declaration->fresh()->bloc_empreinte);
    }

    public function test_service_bloc_reutilise_pris_au_filet_unique(): void
    {
        $corps = $this->avecBloc($this->corps());
        $this->envoyer($corps)->assertCreated();
        $empreinte = Declaration::firstOrFail()->bloc_empreinte;

        $this->actingAs($this->responsable);
        $autre = $this->livraison($this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 10], $this->agent));
        $this->actingAs($this->agent);

        try {
            app(DeclarationService::class)->declarer(
                $autre, 'tablette', ['nom' => 'Awa Diop', 'qualite' => 'Préfète'],
                [$this->urnes->id => ['recu' => 10, 'defectueux' => 0, 'observation' => null]], 'cle-idempotence-0002', now(),
                ['verification' => 'verifiee_code', 'bloc_empreinte' => $empreinte, 'signataire_user_id' => $this->prefet->id, 'delegation_id' => null],
            );
            $this->fail('Le bloc déjà utilisé aurait dû être refusé.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Preuve déjà utilisée par une autre déclaration.', $e->getMessage());
        }
        $this->assertSame(1, Declaration::count());
    }
}

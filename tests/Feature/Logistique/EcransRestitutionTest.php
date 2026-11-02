<?php

namespace Tests\Feature\Logistique;

use App\Models\Logistique\Delegation;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\PieceJointe;
use App\Models\Logistique\Restitution;
use App\Models\Logistique\RestitutionDeclaration;
use App\Models\Logistique\RestitutionDeclarationPiece;
use App\Models\Logistique\RestitutionDocument;
use App\Models\Logistique\RestitutionLigne;
use App\Models\User;
use App\Services\Logistique\CodeReceptionService;
use App\Services\Logistique\DeclarationService;
use App\Services\Logistique\PieceJointeService;
use App\Services\Logistique\PreuveService;
use App\Services\Logistique\RemiseService;
use App\Services\Logistique\RestitutionService;
use App\Support\Canonique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class EcransRestitutionTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    private const CODE = '482915';

    private const SIGNATAIRE = ['nom' => 'Ibrahima Ba', 'qualite' => 'Sous-préfet'];

    private const REFUS_ARBITRAGE = 'Une déclaration de restitution est à vérifier : le responsable doit arbitrer avant la prise en charge.';

    private static ?string $pem = null;

    private string $chemin;

    private User $gestionnaire;

    private User $sousPrefet;

    private User $agentRetour;

    protected function setUp(): void
    {
        parent::setUp();

        self::$pem ??= $this->genererCle();
        $this->chemin = storage_path('framework/testing/restitution-ecrans-'.getmypid().'-'.uniqid().'.pem');
        file_put_contents($this->chemin, self::$pem);
        config(['logistique.cle_preuve_privee' => $this->chemin]);
        Storage::fake('pieces');
        config(['logistique.disque_pieces' => 'pieces']);
        Storage::fake(config('logistique.disque_decharges'));

        $this->creerDecorFictif();
        $this->approuverPlanFictif([[$this->sousPrefecture1, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 1000, 300);
        $this->urnes->forceFill(['reutilisable' => true])->save();

        // Le préfet reçoit 100 urnes et les redistribue toutes au sous-préfet : 100 attendues du sous-préfet.
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => 100]);
        $this->actingAs($this->autoriteFictive($this->prefecture));
        $remise = app(RemiseService::class)->preparerRedistribution($decharge->refresh(), [$this->urnes->id => 100]);
        $this->sousPrefet = $this->autoriteFictive($this->sousPrefecture1);
        $this->actingAs($this->sousPrefet);
        app(DeclarationService::class)->declarer(
            $remise, 'compte', self::SIGNATAIRE,
            [$this->urnes->id => ['recu' => 100, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(),
        );
        $this->actingAs($this->sousPrefet);
        app(CodeReceptionService::class)->choisir($this->sousPrefet, self::CODE, 'password');

        $this->gestionnaire = User::factory()->create(['role' => 'logistique_gestionnaire']);
        $this->agentRetour = User::factory()->create(['role' => 'agent_transport', 'name' => 'Diallo', 'prenom' => 'Moussa']);
        $this->actingAs($this->responsable);
    }

    protected function tearDown(): void
    {
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

    /** Ouverture par le service (le responsable connecté) : la restitution du sous-préfet. */
    private function ouverte(): Restitution
    {
        $this->actingAs($this->responsable);
        app(RestitutionService::class)->ouvrir($this->cycle);

        return Restitution::where('detenteur_type', 'destinataire')->where('detenteur_id', $this->sousPrefecture1->id)->sole();
    }

    /** Restitution déclarée au compte par le sous-préfet (retenue), agent désigné. */
    private function declaree(int $restitue = 100): Restitution
    {
        $restitution = $this->ouverte();
        app(RestitutionService::class)->affecterAgent($restitution, $this->agentRetour);
        $this->actingAs($this->sousPrefet);
        app(RestitutionService::class)->declarer($restitution, 'compte', self::SIGNATAIRE, [$this->urnes->id => ['restitue' => $restitue]], (string) Str::uuid());
        $this->actingAs($this->responsable);

        return $restitution->refresh();
    }

    /** Retour pris en charge par l'agent désigné. */
    private function enRetour(int $restitue = 100): Restitution
    {
        $restitution = $this->declaree($restitue);
        $this->actingAs($this->agentRetour);
        app(RestitutionService::class)->prendreEnCharge($restitution, $this->agentRetour);
        $this->actingAs($this->responsable);

        return $restitution->refresh();
    }

    private function ligne(Restitution $r): RestitutionLigne
    {
        return RestitutionLigne::where('restitution_id', $r->id)->where('materiel_id', $this->urnes->id)->sole();
    }

    private function terrain(User $utilisateur): static
    {
        Sanctum::actingAs($utilisateur, ['terrain']);

        return $this;
    }

    /** @return list<array<string, mixed>> */
    private function restitutionsApi(User $utilisateur): array
    {
        try {
            return $this->terrain($utilisateur)->getJson('/api/terrain/affectations')
                ->assertOk()->assertJsonStructure(['genere_le', 'elements', 'restitutions'])->json('restitutions');
        } finally {
            $this->actingAs($this->responsable);
        }
    }

    /** Corps d'une déclaration de tablette, avec le bloc que chiffrerait la tablette (code du sous-préfet). */
    private function corpsTablette(Restitution $r, int $restitue, string $cle, ?string $code = self::CODE): array
    {
        $horodatage = now()->format('Y-m-d\TH:i:sP');
        $lignes = [['materiel_id' => $this->urnes->id, 'restitue' => $restitue, 'observation' => null]];
        $bloc = null;
        if ($code !== null) {
            $contenu = app(PreuveService::class)->contenuPreuveRestitution($r, self::SIGNATAIRE, $this->sousPrefet->id, $lignes, $cle, $horodatage);
            $clair = json_encode(['code' => $code, 'cle' => $cle, 'empreinte' => Canonique::empreinte($contenu)]);
            $this->assertTrue(openssl_public_encrypt($clair, $chiffre, app(PreuveService::class)->clePublique(), OPENSSL_PKCS1_OAEP_PADDING));
            $bloc = base64_encode($chiffre);
        }

        return [
            'mode' => 'tablette', 'cle' => $cle, 'horodatage_terminal' => $horodatage,
            'signataire' => ['user_id' => $this->sousPrefet->id] + self::SIGNATAIRE,
            'lignes' => $lignes, 'bloc_preuve' => $bloc,
        ];
    }

    public function test_ouverture_depuis_l_ecran(): void
    {
        $index = route('logistique.restitutions.index', $this->cycle);
        $ouverture = route('logistique.restitutions.ouvrir', $this->cycle);

        // Le gestionnaire voit l'écran, sans l'ouverture (réservée au responsable).
        $this->actingAs($this->gestionnaire)->get($index)->assertOk()->assertSee('Restitution')->assertDontSee($ouverture, false);
        $this->post($ouverture)->assertForbidden();
        $this->assertSame(0, Restitution::count());

        $this->actingAs($this->responsable)->get($index)->assertOk()->assertSee($ouverture, false)->assertSee('Aucune restitution');
        $this->post($ouverture)->assertRedirect()->assertSessionHas('success', '1 restitution(s) ouverte(s), 0 inchangée(s).');
        $restitution = Restitution::sole();
        $this->assertSame('a_preparer', $restitution->statut);
        $this->assertSame(100, $this->ligne($restitution)->attendu);

        // L'écran liste la restitution, avec un lien vers sa page (le chemin imprimé par le QR de la fiche).
        $this->assertSame('/logistique/restitutions/'.$restitution->id, route('logistique.restitutions.show', $restitution, false));
        $this->get($index)->assertOk()->assertSee('Sous-préfecture de Sindia')->assertSee('À préparer')
            ->assertSee(route('logistique.restitutions.show', $restitution), false);
        $this->get(route('logistique.restitutions.index', [$this->cycle, 'statut' => 'controlee']))->assertOk()->assertDontSee(route('logistique.restitutions.show', $restitution), false);
        $this->get(route('logistique.restitutions.index', [$this->cycle, 'detenteur' => 'agent']))->assertOk()->assertDontSee(route('logistique.restitutions.show', $restitution), false);

        // Une seconde ouverture ne crée rien.
        $this->post($ouverture)->assertSessionHas('success', '0 restitution(s) ouverte(s), 1 inchangée(s).');
        $this->assertSame(1, Restitution::count());

        // Désignation de l'agent de retour depuis la page de la restitution (P6-R3).
        $this->actingAs($this->gestionnaire)->get(route('logistique.restitutions.show', $restitution))->assertOk()
            ->assertSee(route('logistique.restitutions.agent', $restitution), false)->assertSee('Moussa Diallo');
        $this->post(route('logistique.restitutions.agent', $restitution), ['agent_id' => $this->agentRetour->id])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame($this->agentRetour->id, $restitution->fresh()->agent_id);
        $this->post(route('logistique.restitutions.agent', $restitution), ['agent_id' => $this->gestionnaire->id])
            ->assertSessionHas('error', 'Seul un agent de transport actif peut être désigné pour le retour.');
        $this->assertSame($this->agentRetour->id, $restitution->fresh()->agent_id);
    }

    public function test_recalcul_depuis_l_ecran(): void
    {
        $restitution = $this->ouverte();
        $page = route('logistique.restitutions.show', $restitution);
        $recalcul = route('logistique.restitutions.recalcul', $restitution);
        $this->ligne($restitution)->forceFill(['attendu' => 7])->save();

        // Réservé au responsable : le gestionnaire ne voit pas le bouton et l'envoi lui est interdit.
        $this->actingAs($this->gestionnaire)->get($page)->assertOk()->assertDontSee($recalcul, false);
        $this->post($recalcul)->assertForbidden();
        $this->assertSame(7, $this->ligne($restitution)->attendu);

        $this->actingAs($this->responsable)->get($page)->assertOk()->assertSee($recalcul, false)->assertSee('Recalculer les attendus');
        $this->from($page)->post($recalcul)->assertRedirect($page)->assertSessionHas('success', 'Attendus recalculés.');
        $this->assertSame(100, $this->ligne($restitution)->attendu);
        $this->assertSame(1, JournalEntree::where('action', 'recalcul_restitution')->where('objet_id', $restitution->id)->count());

        // Une fois déclarée, les attendus ne bougent plus : plus de bouton, et le refus du service revient en message.
        $this->actingAs($this->sousPrefet);
        app(RestitutionService::class)->declarer($restitution, 'compte', self::SIGNATAIRE, [$this->urnes->id => ['restitue' => 100]], (string) Str::uuid());
        $this->actingAs($this->responsable)->get($page)->assertOk()->assertDontSee($recalcul, false);
        $this->from($page)->post($recalcul)->assertRedirect($page)
            ->assertSessionHas('error', 'Cette restitution a déjà une déclaration : ses attendus ne peuvent plus être recalculés.');
    }

    public function test_detenteur_declare_depuis_son_compte(): void
    {
        $restitution = $this->ouverte();
        $declaration = route('terrain.restitutions.declarer', $restitution);

        $this->actingAs($this->sousPrefet)->get(route('terrain.restitutions.index'))->assertOk()
            ->assertSee('Sous-préfecture de Sindia')->assertSee($declaration, false)->assertSee('name="_token"', false);

        // Hors périmètre : un autre sous-préfet ne voit rien et l'envoi n'existe pas pour lui.
        $autre = $this->autoriteFictive($this->sousPrefecture2);
        $this->actingAs($autre)->get(route('terrain.restitutions.index'))->assertOk()->assertDontSee($declaration, false);
        $this->post($declaration, [
            'cle' => (string) Str::ulid(), 'signataire_nom' => 'X', 'signataire_qualite' => 'Y',
            'lignes' => [$this->urnes->id => ['restitue' => 100]],
        ])->assertNotFound();
        // Le préfet voit les décharges de la sous-préfecture, mais n'en est pas le détenteur.
        $this->actingAs($this->autoriteFictive($this->prefecture))->post($declaration, [
            'cle' => (string) Str::ulid(), 'signataire_nom' => 'X', 'signataire_qualite' => 'Y',
            'lignes' => [$this->urnes->id => ['restitue' => 100]],
        ])->assertNotFound();
        $this->assertSame(0, RestitutionDeclaration::count());

        // Un adjoint délégué en vigueur la voit aussi.
        $adjoint = User::factory()->create(['role' => 'sous_prefet']);
        Delegation::create([
            'destinataire_id' => $this->sousPrefecture1->id, 'autorite_user_id' => $this->sousPrefet->id, 'delegue_user_id' => $adjoint->id,
            'du' => now()->subDay(), 'au' => now()->addDay(), 'motif' => 'Intérim', 'cree_par' => $this->responsable->id,
        ]);
        $this->actingAs($adjoint)->get(route('terrain.restitutions.index'))->assertOk()->assertSee($declaration, false);

        // Quantité invalide : refus sans rien écrire.
        $this->actingAs($this->sousPrefet)->post($declaration, [
            'cle' => (string) Str::ulid(), 'signataire_nom' => 'Ibrahima Ba', 'signataire_qualite' => 'Sous-préfet',
            'lignes' => [$this->urnes->id => ['restitue' => -1]],
        ])->assertSessionHasErrors('lignes.'.$this->urnes->id.'.restitue');

        $this->post($declaration, [
            'cle' => (string) Str::ulid(), 'signataire_nom' => 'Ibrahima Ba', 'signataire_qualite' => 'Sous-préfet',
            'lignes' => [$this->urnes->id => ['restitue' => 90, 'observation' => 'Dix urnes cassées']],
        ])->assertRedirect(route('terrain.restitutions.index'))->assertSessionHas('success', 'Restitution déclarée.');

        $enregistree = RestitutionDeclaration::sole();
        $this->assertSame('compte', $enregistree->mode);
        $this->assertSame('retenue', $enregistree->statut);
        $this->assertSame($this->sousPrefet->id, $enregistree->signataire_user_id);
        $this->assertSame(90, $this->ligne($restitution)->declare_restitue);

        // Déclarée : la page la montre sans nouveau formulaire.
        $this->get(route('terrain.restitutions.index'))->assertOk()->assertDontSee($declaration, false)->assertSee('Retenue');
    }

    public function test_api_declaration_tablette(): void
    {
        $restitution = $this->ouverte();
        $this->actingAs($this->gestionnaire)->post(route('logistique.restitutions.agent', $restitution), ['agent_id' => $this->agentRetour->id]);

        // L'agent désigné reçoit la restitution à déclarer sur sa tablette ; la forme est celle du vecteur partagé.
        [$element] = $this->restitutionsApi($this->agentRetour);
        $this->assertSame($restitution->id, $element['id']);
        $this->assertSame('a_preparer', $element['statut']);
        $this->assertSame(['type' => 'destinataire', 'id' => $this->sousPrefecture1->id, 'nom' => 'Sous-préfecture de Sindia'], $element['detenteur']);
        $this->assertSame([['materiel_id' => $this->urnes->id, 'libelle' => 'Urnes', 'attendu' => 100]], $element['lignes']);
        $this->assertSame($this->sousPrefet->id, $element['autorite_attendue']['user_id']);
        $this->assertTrue($element['peut_declarer_tablette']);
        $this->assertFalse($element['peut_prendre_en_charge']);
        $this->assertSame(Canonique::empreinte(Arr::except($element, 'empreinte_element')), $element['empreinte_element']);

        // Le détenteur la voit aussi, sans la déclarer sur une tablette.
        [$vue] = $this->restitutionsApi($this->sousPrefet);
        $this->assertFalse($vue['peut_declarer_tablette']);
        $this->assertFalse($vue['peut_prendre_en_charge']);
        // Un autre agent ne la voit pas et ne peut ni déclarer ni prendre en charge (404).
        $autreAgent = User::factory()->create(['role' => 'agent_transport']);
        $this->assertSame([], $this->restitutionsApi($autreAgent));
        $url = '/api/terrain/restitutions/'.$restitution->id;
        $cle = 'cle-tablette-restitution-01';
        $this->terrain($autreAgent)->postJson($url.'/declarations', $this->corpsTablette($restitution, 100, $cle))->assertNotFound();
        $this->terrain($autreAgent)->postJson($url.'/prise-en-charge')->assertNotFound();

        // Saisie invalide : 422 en français, rien d'écrit.
        $invalide = $this->corpsTablette($restitution, 100, $cle);
        $invalide['lignes'][0]['restitue'] = -3;
        $this->terrain($this->agentRetour)->postJson($url.'/declarations', $invalide)->assertStatus(422)->assertJsonValidationErrors('lignes.0.restitue');

        // Prise en charge refusée tant que rien n'est retenu.
        $this->terrain($this->agentRetour)->postJson($url.'/prise-en-charge')->assertStatus(422)
            ->assertExactJson(['message' => 'Aucune déclaration retenue : le retour ne peut pas être pris en charge.']);

        // Envoi de la tablette, code vérifié ; rejoué trois fois : une seule déclaration.
        $corps = $this->corpsTablette($restitution, 100, $cle);
        $premier = $this->terrain($this->agentRetour)->postJson($url.'/declarations', $corps)->assertCreated();
        $this->assertSame('retenue', $premier->json('declaration.statut'));
        $this->assertSame('verifiee_code', $premier->json('declaration.verification_identite'));
        $this->assertSame([], $premier->json('declaration.anomalies'));
        foreach (range(1, 2) as $i) {
            $this->terrain($this->agentRetour)->postJson($url.'/declarations', $corps)->assertOk()
                ->assertJsonPath('declaration.id', $premier->json('declaration.id'));
        }
        $this->assertSame(1, RestitutionDeclaration::count());
        $this->assertSame(100, $this->ligne($restitution)->declare_restitue);

        // Retenue : la prise en charge est proposée, puis faite (et rejouable sans erreur).
        [$element] = $this->restitutionsApi($this->agentRetour);
        $this->assertTrue($element['declaration_retenue']);
        $this->assertTrue($element['peut_prendre_en_charge']);
        $this->terrain($this->agentRetour)->postJson($url.'/prise-en-charge')->assertOk()
            ->assertJsonPath('restitution.statut', 'en_retour')->assertJsonPath('restitution.agent_id', $this->agentRetour->id);
        $this->terrain($this->agentRetour)->postJson($url.'/prise-en-charge')->assertOk()->assertJsonPath('restitution.statut', 'en_retour');
        $this->assertSame('en_retour', $restitution->fresh()->statut);
        $this->assertSame(1, JournalEntree::where('action', 'prise_en_charge_restitution')->count());

        // En retour : encore listée, plus rien à faire sur la tablette.
        [$element] = $this->restitutionsApi($this->agentRetour);
        $this->assertSame('en_retour', $element['statut']);
        $this->assertFalse($element['peut_declarer_tablette']);
        $this->assertFalse($element['peut_prendre_en_charge']);
        // Sans jeton : 401.
        $this->app['auth']->forgetGuards();
        $this->postJson($url.'/prise-en-charge')->assertUnauthorized();
    }

    public function test_prise_en_charge_refusee_pendant_un_arbitrage(): void
    {
        $restitution = $this->declaree(100);
        $url = '/api/terrain/restitutions/'.$restitution->id;

        // Une déclaration de tablette divergente reste à vérifier : la retenue ne bouge pas.
        $this->terrain($this->agentRetour)->postJson($url.'/declarations', $this->corpsTablette($restitution, 90, 'cle-tablette-divergente-1'))
            ->assertCreated()->assertJsonPath('declaration.statut', 'a_verifier');
        [$element] = $this->restitutionsApi($this->agentRetour);
        $this->assertTrue($element['arbitrage_en_attente']);

        // La tablette reçoit le message du serveur.
        $this->terrain($this->agentRetour)->postJson($url.'/prise-en-charge')->assertStatus(422)->assertExactJson(['message' => self::REFUS_ARBITRAGE]);

        // Le central voit les deux déclarations, avec l'état d'identité de chacune et l'arbitrage pour le responsable.
        $retenue = RestitutionDeclaration::where('statut', 'retenue')->sole();
        $aVerifier = RestitutionDeclaration::where('statut', 'a_verifier')->sole();
        $page = route('logistique.restitutions.show', $restitution);
        $this->actingAs($this->gestionnaire)->get($page)->assertOk()
            ->assertSee('Une déclaration de restitution est à vérifier')
            ->assertSee('Confirmée depuis le compte')->assertSee('Vérifiée par code')
            ->assertDontSee(route('logistique.restitutions.retenir', $retenue), false);
        $this->post(route('logistique.restitutions.retenir', $retenue), ['motif' => 'Essai'])->assertForbidden();

        $this->actingAs($this->responsable)->get($page)->assertOk()
            ->assertSee(route('logistique.restitutions.retenir', $retenue), false)
            ->assertSee(route('logistique.restitutions.retenir', $aVerifier), false)
            ->assertSee('Confirmer la déclaration retenue')->assertSee('Retenir cette déclaration');
        $this->post(route('logistique.restitutions.retenir', $retenue), ['motif' => ''])->assertSessionHasErrors('motif');
        $this->post(route('logistique.restitutions.retenir', $retenue), ['motif' => 'Compte confirmé par téléphone'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('ecartee', $aVerifier->fresh()->statut);

        // Arbitré : la prise en charge passe.
        $this->terrain($this->agentRetour)->postJson($url.'/prise-en-charge')->assertOk()->assertJsonPath('restitution.statut', 'en_retour');
    }

    public function test_controle_depuis_l_ecran(): void
    {
        $restitution = $this->enRetour(100);
        $page = route('logistique.restitutions.show', $restitution);
        $pdfDeclaration = route('logistique.restitutions.pdf', [$restitution, 'declaration']);
        $pdfControle = route('logistique.restitutions.pdf', [$restitution, 'controle']);

        $this->actingAs($this->gestionnaire)->get($page)->assertOk()
            ->assertSee(route('logistique.restitutions.receptionner', $restitution), false)
            ->assertDontSee(route('logistique.restitutions.controler', $restitution), false)
            ->assertSee($pdfDeclaration, false)->assertDontSee($pdfControle, false);

        // Fiche de déclaration émise à la première demande, puis réimprimée telle quelle.
        $premiere = $this->get($pdfDeclaration)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $premiere->getContent());
        $this->assertSame($premiere->getContent(), $this->get($pdfDeclaration)->assertOk()->getContent());
        $this->assertSame(1, RestitutionDocument::count());
        $this->get($pdfControle)->assertRedirect($page)->assertSessionHas('error');
        $this->get(route('logistique.restitutions.pdf', [$restitution, 'inconnu']))->assertNotFound();

        // Réception : 100 reçues au central.
        $this->post(route('logistique.restitutions.receptionner', $restitution), ['recus' => [$this->urnes->id => 100]])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('recue_central', $restitution->fresh()->statut);
        $this->get($page)->assertOk()->assertSee(route('logistique.restitutions.controler', $restitution), false)
            ->assertSee(route('logistique.restitutions.receptionner', $restitution), false)->assertSee('Corriger la réception');

        // Correction de la réception avant le contrôle : le formulaire reprend le reçu enregistré.
        $this->post(route('logistique.restitutions.receptionner', $restitution), ['recus' => [$this->urnes->id => 90]])->assertSessionHas('success');
        $this->get($page)->assertOk()->assertSee('name="recus['.$this->urnes->id.']" required min="0" max="10000000" step="1" value="90"', false);
        $this->post(route('logistique.restitutions.receptionner', $restitution), ['recus' => [$this->urnes->id => 100]])->assertSessionHas('success');

        // Contrôle incomplet : refusé avec le message du service.
        $this->post(route('logistique.restitutions.controler', $restitution), ['controle' => [$this->urnes->id => ['bon' => 80, 'a_reparer' => 10, 'reforme' => 0]]])
            ->assertSessionHas('error', 'Contrôle incomplet pour Urnes : bon + à réparer + réformé doit valoir 100.');

        $this->post(route('logistique.restitutions.controler', $restitution), ['controle' => [$this->urnes->id => ['bon' => 80, 'a_reparer' => 10, 'reforme' => 10]]])
            ->assertRedirect()->assertSessionHas('success');
        $ligne = $this->ligne($restitution);
        $this->assertSame([80, 10, 10], [$ligne->bon, $ligne->a_reparer, $ligne->reforme]);
        $this->assertSame('recue_central', $restitution->fresh()->statut, 'La réforme attend la décision du responsable.');
        $this->get($page)->assertOk()->assertDontSee(route('logistique.restitutions.receptionner', $restitution), false)->assertDontSee('Corriger la réception');

        // Décision de réforme : le responsable seul.
        $reforme = route('logistique.restitutions.reforme', $ligne);
        $this->get($page)->assertDontSee($reforme, false);
        $this->post($reforme, ['motif' => 'Essai'])->assertForbidden();
        $this->actingAs($this->responsable)->get($page)->assertSee($reforme, false);
        $this->post($reforme, ['motif' => 'Urnes fendues, irréparables'])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('controlee', $restitution->fresh()->statut);
        $this->assertSame('Urnes fendues, irréparables', $ligne->fresh()->motif_reforme);

        $this->get($page)->assertOk()->assertSee($pdfControle, false)->assertSee('Urnes fendues, irréparables');
        $this->get($pdfControle)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(2, RestitutionDocument::count());
    }

    public function test_ecarts_affiches(): void
    {
        $restitution = $this->enRetour(90);
        $this->actingAs($this->gestionnaire);
        $this->post(route('logistique.restitutions.receptionner', $restitution), ['recus' => [$this->urnes->id => 85]])->assertSessionHas('success');

        $index = route('logistique.restitutions.index', $this->cycle);
        $ecart = collect(app(RestitutionService::class)->ecarts($this->cycle))->sole();
        $this->assertSame([100, 90, 85, 10, 5], [$ecart['attendu'], $ecart['declare_restitue'], $ecart['recu_central'], $ecart['non_restitue'], $ecart['perdu_en_retour']]);
        $this->get($index)->assertOk()->assertSee('Écarts de restitution')
            ->assertSeeInOrder(['Sous-préfecture de Sindia', 'Urnes', '100', '90', '85', '10', '5'])
            ->assertSee(route('logistique.restitutions.justifier', $ecart['ligne_id']), false);

        $this->post(route('logistique.restitutions.justifier', $ecart['ligne_id']), ['justification' => ''])->assertSessionHasErrors('justification');
        $this->post(route('logistique.restitutions.justifier', $ecart['ligne_id']), ['justification' => 'Cinq urnes égarées au transit'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('Cinq urnes égarées au transit', $this->ligne($restitution)->justification);
        $this->assertSame(1, JournalEntree::where('action', 'justification_restitution')->count());
        $this->get($index)->assertOk()->assertSee('Cinq urnes égarées au transit');
    }

    public function test_toutes_les_ecritures_protegees(): void
    {
        $restitution = $this->declaree(100);
        $ligne = $this->ligne($restitution);
        $declaration = RestitutionDeclaration::sole();

        $gerer = [
            ['logistique.restitutions.agent', [$restitution]],
            ['logistique.restitutions.receptionner', [$restitution]],
            ['logistique.restitutions.controler', [$restitution]],
            ['logistique.restitutions.justifier', [$ligne]],
        ];
        $administrer = [
            ['logistique.restitutions.ouvrir', [$this->cycle]],
            ['logistique.restitutions.recalcul', [$restitution]],
            ['logistique.restitutions.reforme', [$ligne]],
            ['logistique.restitutions.retenir', [$declaration]],
        ];
        $lectures = [
            ['logistique.restitutions.index', [$this->cycle]],
            ['logistique.restitutions.show', [$restitution]],
            ['logistique.restitutions.pdf', [$restitution, 'declaration']],
        ];
        $donnees = [
            'agent_id' => $this->agentRetour->id, 'motif' => 'Essai', 'justification' => 'Essai',
            'recus' => [$this->urnes->id => 100], 'controle' => [$this->urnes->id => ['bon' => 100, 'a_reparer' => 0, 'reforme' => 0]],
        ];
        $gouverneur = User::factory()->create(['role' => 'gouverneur', 'region_id' => 1, 'region_nom' => 'Thiès']);
        $terrain = [$this->sousPrefet, $this->autoriteFictive($this->prefecture), $this->agentRetour, $gouverneur];

        foreach ([...$gerer, ...$administrer] as [$nom, $parametres]) {
            $url = route($nom, $parametres);
            auth()->logout();
            $this->post($url, $donnees)->assertRedirect(route('login'));
            foreach ([...$terrain, ...(in_array([$nom, $parametres], $administrer, true) ? [$this->gestionnaire] : [])] as $utilisateur) {
                $this->actingAs($utilisateur)->post($url, $donnees)->assertForbidden();
            }
            $middleware = Route::getRoutes()->getByName($nom)->gatherMiddleware();
            $this->assertContains('web', $middleware, $nom);
            $this->assertContains('auth', $middleware, $nom);
            $this->assertContains(in_array([$nom, $parametres], $administrer, true) ? 'can:logistique-administrer' : 'can:logistique-gerer', $middleware, $nom);
        }
        // Les pages et la fiche (aperçu compris) : personnel logistique seulement.
        foreach ($lectures as [$nom, $parametres]) {
            auth()->logout();
            $this->get(route($nom, $parametres))->assertRedirect(route('login'));
            foreach ($terrain as $utilisateur) {
                $this->actingAs($utilisateur)->get(route($nom, $parametres))->assertForbidden();
            }
            $this->assertContains('can:logistique-gerer', Route::getRoutes()->getByName($nom)->gatherMiddleware(), $nom);
        }

        // Aucune route de restitution n'échappe à la liste, avec le droit annoncé.
        $declarees = collect([...$gerer, ...$lectures])->mapWithKeys(fn ($e) => [$e[0] => 'can:logistique-gerer'])
            ->merge(collect($administrer)->mapWithKeys(fn ($e) => [$e[0] => 'can:logistique-administrer']));
        $reelles = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'logistique.restitutions.'));
        $this->assertEqualsCanonicalizing($declarees->keys()->all(), $reelles->map->getName()->values()->all());

        // Côté terrain : déclaration au compte réservée aux comptes de terrain (et, en plus, au détenteur : 404).
        $url = route('terrain.restitutions.declarer', $restitution);
        auth()->logout();
        $this->post($url, [])->assertRedirect(route('login'));
        foreach ([$this->gestionnaire, $this->responsable, $gouverneur] as $utilisateur) {
            $this->actingAs($utilisateur)->post($url, [])->assertForbidden();
            $this->actingAs($utilisateur)->get(route('terrain.restitutions.index'))->assertForbidden();
        }
        $this->actingAs($this->agentRetour)->post($url, ['cle' => (string) Str::ulid(), 'signataire_nom' => 'X', 'signataire_qualite' => 'Y', 'lignes' => [$this->urnes->id => ['restitue' => 1]]])->assertNotFound();
        foreach (['terrain.restitutions.index', 'terrain.restitutions.declarer'] as $nom) {
            $middleware = Route::getRoutes()->getByName($nom)->gatherMiddleware();
            $this->assertContains('auth', $middleware, $nom);
            $this->assertContains('can:logistique-terrain', $middleware, $nom);
            $this->assertContains('premier-acces', $middleware, $nom);
        }

        // API : jeton de terrain requis, droit de terrain.
        foreach (['declarations', 'prise-en-charge'] as $action) {
            $requete = Request::create('/api/terrain/restitutions/'.$restitution->id.'/'.$action, 'POST');
            $middleware = Route::getRoutes()->match($requete)->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware, $action);
            $this->assertContains('abilities:terrain', $middleware, $action);
            $this->assertContains('can:logistique-terrain', $middleware, $action);
            $this->app['auth']->forgetGuards();
            $this->postJson('/api/terrain/restitutions/'.$restitution->id.'/'.$action, [])->assertUnauthorized();
            $this->terrain($this->gestionnaire)->postJson('/api/terrain/restitutions/'.$restitution->id.'/'.$action, [])->assertForbidden();
            $this->app['auth']->forgetGuards();
        }

        // Rien n'a été écrit par ces refus.
        $this->assertSame('a_preparer', $restitution->fresh()->statut);
        $this->assertSame(1, RestitutionDeclaration::count());
        $this->assertNull($ligne->fresh()->justification);
        $this->assertSame(0, RestitutionDocument::count());

        // Les formulaires portent leur jeton CSRF.
        $this->actingAs($this->responsable)->get(route('logistique.restitutions.show', $restitution))->assertSee('name="_token"', false);
        $this->get(route('logistique.restitutions.index', $this->cycle))->assertSee('name="_token"', false);
    }

    public function test_menus_de_la_restitution(): void
    {
        $this->actingAs($this->gestionnaire)->get(route('logistique.stock.index'))->assertOk()
            ->assertSee(route('logistique.restitutions.index', $this->cycle), false);
        $this->actingAs($this->sousPrefet)->get(route('terrain.decharges.index'))->assertOk()
            ->assertSee(route('terrain.restitutions.index'), false);
    }

    public function test_forme_de_l_element_de_restitution_celle_du_vecteur_partage(): void
    {
        $vecteurs = json_decode(file_get_contents(base_path('tests/fixtures/canonique.json')), true, 512, JSON_THROW_ON_ERROR);
        $vecteur = collect($vecteurs)->firstWhere('note', 'élément de restitution de la tablette (sans son empreinte)')['entree'] ?? null;
        $this->assertIsArray($vecteur);
        $restitution = $this->ouverte();
        app(RestitutionService::class)->affecterAgent($restitution, $this->agentRetour);

        $element = Arr::except($this->restitutionsApi($this->agentRetour)[0], 'empreinte_element');

        $cles = fn (array $a) => collect($a)->keys()->sort()->values()->all();
        $this->assertSame($cles($vecteur), $cles($element));
        foreach (['detenteur', 'autorite_attendue'] as $partie) {
            $this->assertSame($cles($vecteur[$partie]), $cles($element[$partie]), $partie);
        }
        $this->assertSame($cles($vecteur['lignes'][0]), $cles($element['lignes'][0]));
        $this->assertSame($cles($vecteur['autorite_attendue']['delegues'][0]), ['nom', 'qualite', 'user_id']);
        foreach (['declaration_retenue', 'arbitrage_en_attente', 'peut_declarer_tablette', 'peut_prendre_en_charge'] as $drapeau) {
            $this->assertIsBool($element[$drapeau], $drapeau);
        }
    }

    public function test_vecteurs_du_contenu_de_preuve_de_restitution(): void
    {
        $vecteurs = json_decode(file_get_contents(base_path('tests/fixtures/contenu-preuve-restitution.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertGreaterThanOrEqual(2, count($vecteurs));

        foreach ($vecteurs as $i => $vecteur) {
            $e = $vecteur['entree'];
            $restitution = (new Restitution)->forceFill(['id' => $e['restitution_id']]);
            $contenu = app(PreuveService::class)->contenuPreuveRestitution(
                $restitution, $e['signataire'], $e['signataire_user_id'], $e['lignes'], $e['cle'], $e['horodatage_terminal'],
            );

            $this->assertSame($vecteur['json'], Canonique::json($contenu), "JSON du vecteur {$i}");
            $this->assertSame($vecteur['empreinte'], Canonique::empreinte($contenu), "Empreinte du vecteur {$i}");
            $this->assertSame(hash('sha256', $vecteur['json']), $vecteur['empreinte'], "Cohérence du vecteur {$i}");
        }
    }

    public function test_tablette_ne_redeclare_pas_une_restitution_deja_declaree_sur_tablette(): void
    {
        $restitution = $this->ouverte();
        app(RestitutionService::class)->affecterAgent($restitution, $this->agentRetour);
        [$avant] = $this->restitutionsApi($this->agentRetour);
        $this->assertTrue($avant['peut_declarer_tablette']);

        $this->terrain($this->agentRetour)->postJson('/api/terrain/restitutions/'.$restitution->id.'/declarations', $this->corpsTablette($restitution, 100, 'cle-tablette-restitution-02'))
            ->assertCreated();

        // Tablette réinstallée ou seconde tablette : la déclaration de tablette existe déjà côté serveur.
        [$apres] = $this->restitutionsApi($this->agentRetour);
        $this->assertFalse($apres['peut_declarer_tablette']);
        $this->assertTrue($apres['declaration_retenue']);
        $this->assertTrue($apres['peut_prendre_en_charge']);

        // Écartée par l'arbitrage, elle ne compte plus : la tablette peut de nouveau déclarer.
        RestitutionDeclaration::where('restitution_id', $restitution->id)->update(['statut' => 'ecartee']);
        [$ecartee] = $this->restitutionsApi($this->agentRetour);
        $this->assertTrue($ecartee['peut_declarer_tablette']);
    }

    public function test_agent_detenteur_declare_depuis_son_compte_et_prend_en_charge_par_l_api(): void
    {
        // Un agent charge 100 urnes, la préfecture n'en reçoit que 90 : 10 attendues de l'agent lui-même.
        $detenteur = User::factory()->create(['role' => 'agent_transport', 'name' => 'Ndiaye', 'prenom' => 'Ousmane']);
        $this->approuverPlanFictif([[$this->sousPrefecture1, $this->urnes, 200]]);
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100], $detenteur);
        $this->deposerRecue($decharge, [$this->urnes->id => 90]);
        $this->actingAs($this->responsable);
        app(RestitutionService::class)->ouvrir($this->cycle);
        $restitution = Restitution::where('detenteur_type', 'agent')->where('detenteur_id', $detenteur->id)->sole();
        $this->assertNull($restitution->agent_id);

        $element = collect($this->restitutionsApi($detenteur))->firstWhere('id', $restitution->id);
        $this->assertSame(['type' => 'agent', 'id' => $detenteur->id, 'nom' => 'Ousmane Ndiaye'], $element['detenteur']);
        $this->assertFalse($element['peut_declarer_tablette'], 'un agent détenteur déclare depuis son compte');
        $this->assertFalse($element['peut_prendre_en_charge'], 'rien de retenu');

        // Déclaration depuis son compte sur le web.
        $this->actingAs($detenteur)->get(route('terrain.restitutions.index'))->assertOk()
            ->assertSee(route('terrain.restitutions.declarer', $restitution), false);
        $this->post(route('terrain.restitutions.declarer', $restitution), [
            'cle' => (string) Str::ulid(), 'signataire_nom' => 'Ousmane Ndiaye', 'signataire_qualite' => 'Agent de transport',
            'lignes' => [$this->urnes->id => ['restitue' => 10, 'observation' => '']],
        ])->assertRedirect(route('terrain.restitutions.index'))->assertSessionHas('success', 'Restitution déclarée.');
        $this->assertSame('retenue', RestitutionDeclaration::where('restitution_id', $restitution->id)->sole()->statut);

        $element = collect($this->restitutionsApi($detenteur))->firstWhere('id', $restitution->id);
        $this->assertFalse($element['peut_declarer_tablette']);
        $this->assertTrue($element['peut_prendre_en_charge'], 'sans agent désigné, le détenteur prend son retour en charge');

        // Prise en charge par l'API, par le détenteur lui-même ; un rejeu renvoie le même résultat.
        $url = '/api/terrain/restitutions/'.$restitution->id.'/prise-en-charge';
        $this->terrain($detenteur)->postJson($url)->assertOk()
            ->assertJsonPath('restitution.statut', 'en_retour')->assertJsonPath('restitution.agent_id', $detenteur->id);
        $this->terrain($detenteur)->postJson($url)->assertOk()->assertJsonPath('restitution.statut', 'en_retour');
        $this->assertSame($detenteur->id, $restitution->refresh()->agent_id);
    }

    public function test_api_declaration_d_un_ancien_agent_de_retour_a_verifier(): void
    {
        $restitution = $this->ouverte();
        app(RestitutionService::class)->affecterAgent($restitution, $this->agentRetour);
        $corps = $this->corpsTablette($restitution, 100, 'cle-tablette-ancien-agent-1');
        // Un autre agent est désigné pendant que la saisie attend le réseau sur la tablette du premier.
        $nouveau = User::factory()->create(['role' => 'agent_transport']);
        app(RestitutionService::class)->affecterAgent($restitution, $nouveau);
        $url = '/api/terrain/restitutions/'.$restitution->id.'/declarations';

        $premier = $this->terrain($this->agentRetour)->postJson($url, $corps)->assertCreated()
            ->assertJsonPath('declaration.statut', 'a_verifier')
            ->assertJsonPath('declaration.verification_identite', 'verifiee_code')
            ->assertJsonPath('declaration.anomalies', ['affectation_retiree']);
        $this->terrain($this->agentRetour)->postJson($url, $corps)->assertOk()->assertJsonPath('declaration.id', $premier->json('declaration.id'));
        $this->assertSame(1, RestitutionDeclaration::count());

        // Un agent jamais désigné pour cette restitution : elle n'existe pas pour lui.
        $intrus = User::factory()->create(['role' => 'agent_transport']);
        $this->terrain($intrus)->postJson($url, $this->corpsTablette($restitution, 100, 'cle-tablette-ancien-agent-2'))->assertNotFound();
        $this->assertSame(1, RestitutionDeclaration::count());

        // Le central voit l'anomalie en clair.
        $this->actingAs($this->responsable)->get(route('logistique.restitutions.show', $restitution))->assertOk()
            ->assertSee('Agent de retour n&#039;étant plus désigné', false);
    }

    public function test_jeton_d_une_autorite_sur_l_api_de_restitution_introuvable(): void
    {
        $restitution = $this->declaree();
        $url = '/api/terrain/restitutions/'.$restitution->id;

        // Le sous-préfet détenteur, avec un jeton de terrain : ni déclaration de tablette ni prise en charge.
        $this->terrain($this->sousPrefet)->postJson($url.'/declarations', $this->corpsTablette($restitution, 100, 'cle-tablette-restitution-03'))->assertNotFound();
        $this->terrain($this->sousPrefet)->postJson($url.'/prise-en-charge')->assertNotFound();
        $this->assertSame('a_preparer', $restitution->refresh()->statut);
        $this->assertSame(1, RestitutionDeclaration::where('restitution_id', $restitution->id)->count());
    }

    public function test_page_terrain_ne_montre_que_les_pieces_visibles(): void
    {
        $restitution = $this->declaree();
        $declaration = RestitutionDeclaration::where('restitution_id', $restitution->id)->sole();
        $visible = str_repeat('a', 64);
        $cachee = str_repeat('b', 64);
        foreach ([$visible => 'signature', $cachee => 'photo'] as $empreinte => $role) {
            $piece = PieceJointe::create(['empreinte' => $empreinte, 'chemin' => 'pieces/'.$empreinte.'.png', 'taille' => 10, 'type_mime' => 'image/png', 'user_id' => $this->sousPrefet->id]);
            RestitutionDeclarationPiece::create(['restitution_declaration_id' => $declaration->id, 'empreinte' => $empreinte, 'role' => $role, 'piece_jointe_id' => $piece->id]);
        }
        // Le service de visibilité refuse la seconde pièce à ce compte.
        $this->partialMock(PieceJointeService::class, fn ($mock) => $mock->shouldReceive('visiblePar')
            ->andReturnUsing(fn (User $u, string $empreinte) => $empreinte === $cachee ? null : PieceJointe::where('empreinte', $empreinte)->first()));

        $this->actingAs($this->sousPrefet)->get(route('terrain.restitutions.index'))->assertOk()
            ->assertSee(route('terrain.pieces.show', $visible), false)
            ->assertDontSee(route('terrain.pieces.show', $cachee), false);
    }
}

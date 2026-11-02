<?php

namespace Tests\Feature\Logistique;

use App\Models\Logistique\Appareil;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\DeclarationPiece;
use App\Models\Logistique\Delegation;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\PieceJointe;
use App\Models\Logistique\Remise;
use App\Models\NotificationOp;
use App\Models\User;
use App\Services\Logistique\AvisReceptionService;
use App\Services\Logistique\CodeReceptionService;
use App\Services\Logistique\DechargePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class EcransIdentiteTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    private const LIBELLES = [
        'en_attente' => 'Vérification en attente',
        'verifiee_code' => 'Vérifiée par code',
        'verifiee_appareil' => 'Vérifiée par l\'appareil',
        'non_verifiee' => 'Non vérifiée',
        'a_verifier' => 'Identité à vérifier',
        'echec' => 'Échec du code',
        'compte' => 'Confirmée depuis le compte',
    ];

    private const CONFIRMEES = ['verifiee_code', 'verifiee_appareil', 'compte'];

    private User $prefet;

    private User $gestionnaire;

    private Decharge $decharge;

    private Declaration $declaration;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('pieces');
        config(['logistique.disque_pieces' => 'pieces']);
        Storage::fake(config('logistique.disque_decharges'));

        $this->creerDecorFictif();
        $this->gestionnaire = User::factory()->create(['role' => 'logistique_gestionnaire']);
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 300], [$this->sousPrefecture1, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 1000, 400);

        $this->decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 20]);
        $this->declaration = $this->deposerRecue($this->decharge, [$this->urnes->id => 20]);
        $this->prefet = $this->autoriteFictive($this->prefecture);
        $this->actingAs($this->responsable);
    }

    /** Fixe des colonnes que seule la tablette renseigne (hors assignation de masse). */
    private function poser(array $colonnes): Declaration
    {
        $this->declaration->forceFill($colonnes)->save();

        return $this->declaration->refresh();
    }

    private function fiche(): string
    {
        return route('logistique.decharges.show', $this->decharge);
    }

    private function ficheTerrain(): string
    {
        return route('terrain.decharges.show', $this->decharge);
    }

    private function jpeg(string $variante): string
    {
        return base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=')."\xFF\xD9".$variante;
    }

    /** Signature et photo stockées et rattachées à la déclaration ; renvoie leurs empreintes. */
    private function joindrePieces(): array
    {
        $empreintes = [];
        foreach (['signature', 'photo'] as $role) {
            $octets = $this->jpeg($role);
            $empreinte = hash('sha256', $octets);
            $chemin = 'pieces/'.substr($empreinte, 0, 2).'/'.$empreinte.'.jpg';
            Storage::disk('pieces')->put($chemin, $octets);
            $piece = PieceJointe::create([
                'empreinte' => $empreinte, 'chemin' => $chemin, 'taille' => strlen($octets),
                'type_mime' => 'image/jpeg', 'user_id' => $this->prefet->id,
            ]);
            DeclarationPiece::create([
                'declaration_id' => $this->declaration->id, 'empreinte' => $empreinte, 'role' => $role, 'piece_jointe_id' => $piece->id,
            ]);
            $empreintes[$role] = $empreinte;
        }

        return $empreintes;
    }

    public function test_etats_d_identite_affiches(): void
    {
        $this->actingAs($this->responsable);
        foreach (self::LIBELLES as $etat => $libelle) {
            $this->poser(['verification_identite' => $etat, 'signataire_user_id' => $this->prefet->id]);

            $reponse = $this->get($this->fiche())->assertOk()->assertSee($libelle)->assertSee($this->prefet->nom_complet);
            // Aucune signature qui n'est pas établie ne passe pour une signature confirmée.
            foreach (self::LIBELLES as $autre => $texte) {
                if ($autre !== $etat) {
                    $reponse->assertDontSee($texte);
                }
            }
        }

        // Une déclaration sans valeur (ancienne) est « Non vérifiée », jamais confirmée.
        $ancienne = new Declaration;
        $this->assertSame('Non vérifiée', $ancienne->libelleVerification());
        $this->assertFalse($ancienne->identiteEtablie());
        foreach (self::LIBELLES as $etat => $libelle) {
            $this->assertSame($libelle, (new Declaration(['mode' => 'tablette']))->forceFill(['verification_identite' => $etat])->libelleVerification());
            $this->assertSame(in_array($etat, self::CONFIRMEES, true), (new Declaration)->forceFill(['verification_identite' => $etat])->identiteEtablie());
        }

        // Délégation : le vrai signataire est le délégué, au titre de l'autorité qui a délégué.
        $delegue = User::factory()->create(['role' => 'sous_prefet', 'name' => 'Ndiaye', 'prenom' => 'Moussa']);
        $delegation = Delegation::create([
            'destinataire_id' => $this->prefecture->id, 'autorite_user_id' => $this->prefet->id, 'delegue_user_id' => $delegue->id,
            'du' => now()->subDay(), 'motif' => 'Déplacement', 'cree_par' => $this->prefet->id,
        ]);
        $this->poser(['verification_identite' => 'verifiee_code', 'signataire_user_id' => $delegue->id, 'delegation_id' => $delegation->id,
            'gps_lat' => '14.791300', 'gps_lng' => '-16.925600', 'gps_precision' => 12]);
        $this->get($this->fiche())->assertOk()
            ->assertSee($delegue->nom_complet)->assertSee('par délégation de '.$this->prefet->nom_complet)
            ->assertSee('14.791300')->assertSee('-16.925600')->assertSee('12 m')
            ->assertSee('Vérifiée par code');

        // Sans délégation ni position, rien de tout cela n'est affiché.
        $this->poser(['delegation_id' => null, 'gps_lat' => null, 'gps_lng' => null, 'gps_precision' => null, 'signataire_user_id' => $this->prefet->id]);
        $this->get($this->fiche())->assertOk()->assertDontSee('par délégation de')->assertDontSee('Position');

        // L'écran des vérifications montre le même état à côté du signataire.
        $this->poser(['verification_identite' => 'echec']);
        Remise::whereKey($this->declaration->remise_id)->update(['statut' => 'a_verifier']);
        $this->get(route('logistique.declarations.a-verifier', $this->cycle))->assertOk()
            ->assertSee('Échec du code')->assertSee($this->prefet->nom_complet)->assertDontSee('Vérifiée par code');

        // Côté terrain, la même mention accompagne le signataire.
        $this->actingAs($this->prefet);
        $this->get($this->ficheTerrain())->assertOk()->assertSee('Échec du code')->assertDontSee('Vérifiée par code');
    }

    public function test_signature_et_photo_visibles_selon_le_droit(): void
    {
        $empreintes = $this->joindrePieces();
        $centrale = fn (string $e) => route('logistique.pieces.show', $e);
        $terrain = fn (string $e) => route('terrain.pieces.show', $e);

        // Central (gérer) : liens et fichiers.
        foreach ([$this->responsable, $this->gestionnaire] as $central) {
            $this->actingAs($central);
            $this->get($this->fiche())->assertOk()
                ->assertSee($centrale($empreintes['signature']), false)->assertSee($centrale($empreintes['photo']), false);
            $this->get($centrale($empreintes['signature']))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        }

        // Terrain dans son périmètre : liens et fichiers.
        $this->actingAs($this->prefet);
        $this->get($this->ficheTerrain())->assertOk()
            ->assertSee($terrain($empreintes['signature']), false)->assertSee($terrain($empreintes['photo']), false);
        $this->get($terrain($empreintes['photo']))->assertOk();
        // Un terrain n'a pas accès aux pièces par la voie centrale.
        $this->get($centrale($empreintes['photo']))->assertForbidden();

        // Terrain hors périmètre : ni lien, ni fichier.
        $autrePrefecture = Destinataire::create(['type' => 'prefecture', 'libelle' => 'Préfecture de Kaolack', 'departement_id' => 20]);
        $etranger = $this->autoriteFictive($autrePrefecture);
        $this->actingAs($etranger);
        $this->get($this->ficheTerrain())->assertNotFound();
        $this->get($terrain($empreintes['signature']))->assertNotFound();
        $this->get($terrain($empreintes['photo']))->assertNotFound();

        // Gouverneur (suivi seulement) : ni lien, ni fichier.
        $gouverneur = User::factory()->create(['role' => 'gouverneur', 'region_id' => 1, 'region_nom' => 'Thiès']);
        $this->actingAs($gouverneur);
        $this->get($this->fiche())->assertForbidden();
        $this->get($this->ficheTerrain())->assertNotFound();
        $this->get($centrale($empreintes['signature']))->assertForbidden();
        $this->get($terrain($empreintes['signature']))->assertForbidden();

        // Une pièce encore attendue (pas de fichier) n'a aucun lien.
        DeclarationPiece::query()->update(['piece_jointe_id' => null]);
        $this->actingAs($this->responsable);
        $this->get($this->fiche())->assertOk()
            ->assertDontSee($centrale($empreintes['signature']), false)->assertSee('Signature attendue')->assertSee('Photo attendue');
    }

    public function test_contestation_visible_et_levable(): void
    {
        $this->poser(['verification_identite' => 'verifiee_code', 'signataire_user_id' => $this->prefet->id]);
        $levee = route('logistique.declarations.contestation.levee', $this->declaration);

        // Sans contestation : ni étiquette ni formulaire.
        $this->get($this->fiche())->assertOk()->assertDontSee('Contestée')->assertDontSee($levee, false);

        // Le signataire conteste depuis son compte.
        $this->actingAs($this->prefet);
        $this->post(route('terrain.declarations.contestation', $this->declaration), ['motif' => 'Ce n\'est pas moi.'])->assertSessionHas('success');
        $this->assertSame('contestee', $this->declaration->fresh()->contestation);

        // Le central voit la contestation et peut la lever ; un gestionnaire la voit sans pouvoir la lever.
        $this->actingAs($this->gestionnaire);
        $this->get($this->fiche())->assertOk()->assertSee('Contestée')->assertDontSee($levee, false);
        $this->actingAs($this->responsable);
        $this->get($this->fiche())->assertOk()->assertSee('Contestée')->assertSee($levee, false);

        $this->post($levee, [])->assertSessionHasErrors('motif');
        $this->assertSame('contestee', $this->declaration->fresh()->contestation);
        $this->post($levee, ['motif' => str_repeat('x', 501)])->assertSessionHasErrors('motif');

        $this->post($levee, ['motif' => 'Vérifié auprès du préfet.'])
            ->assertRedirect($this->fiche())->assertSessionHas('success');
        $this->assertSame('levee', $this->declaration->fresh()->contestation);
        $this->assertSame(1, JournalEntree::where('action', 'levee_contestation')->count());
        $this->get($this->fiche())->assertOk()->assertSee('Contestation levée')->assertDontSee($levee, false);

        // Lever deux fois : la règle du service revient en message, sans erreur 500.
        $this->post($levee, ['motif' => 'Encore'])->assertSessionHas('error', 'Aucune contestation à lever pour cette réception.');

        // Le signataire voit l'issue côté terrain.
        $this->actingAs($this->prefet);
        $this->get($this->ficheTerrain())->assertOk()->assertSee('Contestation levée');
    }

    public function test_avis_et_contestation_cote_terrain(): void
    {
        $this->poser(['signataire_user_id' => $this->prefet->id]);
        $contester = route('terrain.declarations.contestation', $this->declaration);
        app(AvisReceptionService::class)->envoyer($this->declaration);
        $this->assertSame(1, NotificationOp::where('type', 'logistique_reception')->count());

        // Le signataire voit l'avis et le formulaire « Contester ».
        $this->actingAs($this->prefet);
        $this->get($this->ficheTerrain())->assertOk()
            ->assertSee('Avis de réception')->assertSee('Contester')->assertSee($contester, false);

        // Un autre compte de la décharge (l'agent) ne voit ni l'avis ni le formulaire, et ne peut pas contester (404).
        $this->actingAs($this->decharge->agent);
        $this->get($this->ficheTerrain())->assertOk()->assertDontSee('Avis de réception')->assertDontSee($contester, false);
        $this->post($contester, ['motif' => 'Moi non plus.'])->assertNotFound();
        $this->assertSame('aucune', $this->declaration->fresh()->contestation);

        // Une fois contestée, le formulaire disparaît pour le signataire, avec l'étiquette « Contestée ».
        $this->actingAs($this->prefet);
        $this->post($contester, ['motif' => 'Ce n\'est pas moi.'])->assertSessionHas('success');
        $this->get($this->ficheTerrain())->assertOk()->assertSee('Contestée')->assertDontSee($contester, false);
        $this->post($contester, [])->assertSessionHasErrors('motif');
    }

    public function test_designation_d_appareil(): void
    {
        $uuid = (string) Str::uuid();
        $jeton = $this->prefet->createToken('Tablette', ['terrain'])->accessToken;
        $jeton->forceFill(['appareil_identifiant' => $uuid])->save();

        // Le gestionnaire consulte l'écran, sans pouvoir désigner.
        $this->actingAs($this->gestionnaire);
        $this->get(route('logistique.appareils.index'))->assertOk()->assertSee('Appareils désignés')
            ->assertDontSee(route('logistique.appareils.designer', $this->prefecture), false);
        $this->post(route('logistique.appareils.designer', $this->prefecture), ['identifiant' => $uuid, 'nom' => 'Tablette', 'jeton_id' => $jeton->getKey()])->assertForbidden();
        $this->assertSame(0, Appareil::count());

        // Le responsable désigne en choisissant le jeton à épingler.
        $this->actingAs($this->responsable);
        $this->get(route('logistique.appareils.index'))->assertOk()
            ->assertSee(route('logistique.appareils.designer', $this->prefecture), false)->assertSee('name="jeton_id"', false)
            ->assertSee('Aucun appareil désigné');
        $this->post(route('logistique.appareils.designer', $this->prefecture), ['identifiant' => $uuid, 'nom' => 'Tablette du préfet', 'jeton_id' => $jeton->getKey()])
            ->assertSessionHas('success');
        $appareil = Appareil::sole();
        $this->assertSame($uuid, $appareil->identifiant);
        $this->assertSame((int) $jeton->getKey(), (int) $appareil->jeton_id);
        $this->get(route('logistique.appareils.index'))->assertOk()
            ->assertSee('Tablette du préfet')->assertSee('Tablette reconnue')->assertSee(route('logistique.appareils.revoquer', $appareil), false);

        // Entrées invalides : message de validation, rien d'écrit.
        $this->post(route('logistique.appareils.designer', $this->prefecture), ['identifiant' => 'pas-un-uuid', 'nom' => 'X'])->assertSessionHasErrors('identifiant');
        $this->post(route('logistique.appareils.designer', $this->prefecture), ['identifiant' => (string) Str::uuid()])->assertSessionHasErrors('nom');

        // Révocation par le responsable seulement.
        $this->actingAs($this->gestionnaire);
        $this->post(route('logistique.appareils.revoquer', $appareil), ['motif' => 'Perdue'])->assertForbidden();
        $this->actingAs($this->responsable);
        $this->post(route('logistique.appareils.revoquer', $appareil), ['motif' => 'Perdue'])->assertSessionHas('success');
        $this->assertFalse($appareil->fresh()->actif);

        // Le menu mène à l'écran.
        $this->get(route('logistique.appareils.index'))->assertSee('href="'.route('logistique.appareils.index').'"', false)->assertSee('Appareils');
    }

    public function test_reinitialisation_du_code_depuis_l_ecran(): void
    {
        $this->actingAs($this->prefet);
        app(CodeReceptionService::class)->choisir($this->prefet, '482915', 'password');
        $this->assertNotNull($this->prefet->fresh()->code_reception_hash);
        $reinitialiser = route('logistique.utilisateurs.code.reinitialiser', $this->prefet);

        // Le gestionnaire n'a ni l'écran, ni le droit.
        $this->actingAs($this->gestionnaire);
        $this->get(route('logistique.utilisateurs.codes'))->assertForbidden();
        $this->post($reinitialiser, ['motif' => 'Oubli'])->assertForbidden();
        $this->assertNotNull($this->prefet->fresh()->code_reception_hash);

        // Le responsable atteint l'écran depuis le menu, voit l'état du code, et le réinitialise avec un motif.
        $this->actingAs($this->responsable);
        $this->get(route('logistique.appareils.index'))->assertSee('href="'.route('logistique.utilisateurs.codes').'"', false);
        $this->get(route('logistique.utilisateurs.codes'))->assertOk()
            ->assertSee($this->prefet->nom_complet)->assertSee('Choisi')->assertSee($reinitialiser, false);
        $this->post($reinitialiser, [])->assertSessionHasErrors('motif');
        $this->assertNotNull($this->prefet->fresh()->code_reception_hash);

        $this->post($reinitialiser, ['motif' => 'Oubli du code'])->assertSessionHas('success');
        $this->assertNull($this->prefet->fresh()->code_reception_hash);
        $this->get(route('logistique.utilisateurs.codes'))->assertOk()->assertSee('À choisir');

        // Un compte sans code de réception (le gestionnaire) est refusé par la règle, sans erreur 500.
        $this->post(route('logistique.utilisateurs.code.reinitialiser', $this->gestionnaire), ['motif' => 'Test'])->assertSessionHas('error');
    }

    public function test_toutes_les_ecritures_protegees(): void
    {
        $appareil = Appareil::create([
            'destinataire_id' => $this->prefecture->id, 'identifiant' => (string) Str::uuid(), 'nom' => 'Tablette', 'designe_par' => $this->responsable->id,
        ]);
        $remise = Remise::where('decharge_id', $this->decharge->id)->firstOrFail();

        $administrer = [
            ['logistique.appareils.designer', [$this->prefecture]],
            ['logistique.appareils.revoquer', [$appareil]],
            ['logistique.remises.arbitrage', [$remise]],
            ['logistique.decharges.reserve.liberer', [$this->decharge]],
            ['logistique.utilisateurs.code.reinitialiser', [$this->prefet]],
            ['logistique.declarations.contestation.levee', [$this->declaration]],
        ];
        $terrain = [['terrain.declarations.contestation', [$this->declaration]]];
        $donnees = ['motif' => 'Essai', 'identifiant' => (string) Str::uuid(), 'nom' => 'Tablette', 'decision' => 'rejeter'];

        $gouverneur = User::factory()->create(['role' => 'gouverneur', 'region_id' => 1, 'region_nom' => 'Thiès']);
        $refuses = [$this->gestionnaire, $this->prefet, $gouverneur, $this->decharge->agent];

        foreach ($administrer as [$nom, $parametres]) {
            $url = route($nom, $parametres);
            $this->flushSession();
            auth()->logout();
            $this->post($url, $donnees)->assertRedirect(route('login'));

            foreach ($refuses as $utilisateur) {
                $this->actingAs($utilisateur);
                $this->post($url, $donnees)->assertForbidden();
            }

            // Garde-fous de route : session web (CSRF), connexion et droit d'administrer.
            $middleware = Route::getRoutes()->getByName($nom)->gatherMiddleware();
            $this->assertContains('web', $middleware, $nom);
            $this->assertContains('auth', $middleware, $nom);
            $this->assertContains('can:logistique-administrer', $middleware, $nom);
        }

        // Contester : réservé à un compte de terrain (et, en plus, au signataire).
        foreach ($terrain as [$nom, $parametres]) {
            $url = route($nom, $parametres);
            auth()->logout();
            $this->post($url, $donnees)->assertRedirect(route('login'));
            foreach ([$this->gestionnaire, $this->responsable, $gouverneur] as $utilisateur) {
                $this->actingAs($utilisateur);
                $this->post($url, $donnees)->assertForbidden();
            }
            $middleware = Route::getRoutes()->getByName($nom)->gatherMiddleware();
            $this->assertContains('web', $middleware, $nom);
            $this->assertContains('auth', $middleware, $nom);
            $this->assertContains('can:logistique-terrain', $middleware, $nom);
        }

        // Rien n'a été écrit par ces refus.
        $this->assertSame(1, Appareil::count());
        $this->assertTrue($appareil->fresh()->actif);
        $this->assertSame('aucune', $this->declaration->fresh()->contestation);

        // Les formulaires de ces écrans portent leur jeton CSRF.
        $this->poser(['contestation' => 'contestee']);
        $this->actingAs($this->responsable);
        $this->get($this->fiche())->assertSee('name="_token"', false);
        $this->get(route('logistique.appareils.index'))->assertSee('name="_token"', false);
        $this->get(route('logistique.utilisateurs.codes'))->assertSee('name="_token"', false);
    }

    private function badge(Declaration $declaration): string
    {
        $html = view('logistique.declarations._preuve', [
            'declaration' => $declaration, 'routePiece' => 'logistique.pieces.show', 'piecesVisibles' => null, 'peutLever' => false,
        ])->render();
        $this->assertSame(1, preg_match('#<span class="px-2 py-0\.5 rounded-full text-xs font-medium ([^"]+)">'.preg_quote(e($declaration->libelleVerification()), '#').'</span>#u', $html, $m), 'badge de vérification absent');

        return $m[1];
    }

    public function test_couleur_du_badge_d_identite(): void
    {
        foreach (self::LIBELLES as $etat => $libelle) {
            $declaration = $this->poser(['verification_identite' => $etat, 'contestation' => 'aucune', 'signataire_user_id' => $this->prefet->id]);
            $classe = $this->badge($declaration);
            if (in_array($etat, self::CONFIRMEES, true)) {
                $this->assertSame('bg-green-100 text-green-700', $classe, $etat);
            } else {
                $this->assertStringNotContainsString('green', $classe, $etat);
            }
        }
        $this->assertStringContainsString('red', $this->badge($this->poser(['verification_identite' => 'echec'])));

        // Une contestation ouverte retire le vert, même sur une identité établie ; levée, il revient.
        $this->assertStringNotContainsString('green', $this->badge($this->poser(['verification_identite' => 'verifiee_code', 'contestation' => 'contestee'])));
        $this->assertSame('bg-green-100 text-green-700', $this->badge($this->poser(['verification_identite' => 'verifiee_code', 'contestation' => 'levee'])));

        // Une valeur absente se lit « Non vérifiée », en ambre, jamais en vert.
        $sans = (new Declaration)->forceFill(['verification_identite' => null]);
        $classe = $this->badge($sans);
        $this->assertStringNotContainsString('green', $classe);
        $html = view('logistique.declarations._preuve', ['declaration' => $sans, 'routePiece' => 'logistique.pieces.show', 'piecesVisibles' => null, 'peutLever' => false])->render();
        $this->assertStringContainsString('Non vérifiée', $html);
    }

    public function test_pieces_et_avis_d_une_autre_decharge_invisibles(): void
    {
        $empreintes = $this->joindrePieces();
        $this->poser(['signataire_user_id' => $this->prefet->id]);

        // Un agent affecté à une autre décharge ne voit ni la page, ni les liens, ni les fichiers.
        $autre = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 5]);
        $this->actingAs($autre->agent);
        $this->get(route('terrain.decharges.show', $autre))->assertOk()->assertDontSee($empreintes['signature'])->assertDontSee($empreintes['photo']);
        $this->get($this->ficheTerrain())->assertNotFound();
        $this->get(route('terrain.pieces.show', $empreintes['signature']))->assertNotFound();
        $this->get(route('terrain.pieces.show', $empreintes['photo']))->assertNotFound();

        // L'avis reçu pour une autre décharge n'est pas montré sur celle-ci.
        NotificationOp::create([
            'user_id' => $this->prefet->id, 'titre' => 'Réception confirmée', 'type' => 'logistique_reception',
            'message' => 'Vous avez confirmé la réception de la décharge n° ZZ-999 le 01/01/2026 à 10:00 (1 articles). Si ce n\'est pas vous, contestez.',
        ]);
        $this->actingAs($this->prefet);
        $this->get($this->ficheTerrain())->assertOk()->assertDontSee('Avis de réception');
    }

    public function test_decharge_pdf_affiche_l_etat_d_identite(): void
    {
        $service = app(DechargePdfService::class);
        $delegue = User::factory()->create(['role' => 'sous_prefet', 'name' => 'Ndiaye', 'prenom' => 'Moussa']);
        $delegation = Delegation::create([
            'destinataire_id' => $this->prefecture->id, 'autorite_user_id' => $this->prefet->id, 'delegue_user_id' => $delegue->id,
            'du' => now()->subDay(), 'motif' => 'Déplacement', 'cree_par' => $this->prefet->id,
        ]);
        $texte = fn (string $html) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('#<(style|title)\b.*?</\1>#si', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        // Retenue mais identité en échec : jamais imprimée comme confirmée.
        $this->poser(['statut' => 'retenue', 'verification_identite' => 'echec', 'signataire_user_id' => $this->prefet->id]);
        $imprime = $texte($service->html($this->decharge->refresh(), 'reception'));
        $this->assertStringContainsString('Échec du code', $imprime);
        $this->assertStringContainsString('identité non établie', $imprime);
        $this->assertStringContainsString($this->prefet->nom_complet, $imprime);
        $this->assertStringNotContainsString('Vérifiée par code', $imprime);

        // Identité établie, signée par un délégué.
        $this->poser(['verification_identite' => 'verifiee_code', 'signataire_user_id' => $delegue->id, 'delegation_id' => $delegation->id]);
        $imprime = $texte($service->html($this->decharge->refresh(), 'reception'));
        $this->assertStringContainsString('Vérifiée par code', $imprime);
        $this->assertStringContainsString($delegue->nom_complet.', par délégation de '.$this->prefet->nom_complet, $imprime);
        $this->assertStringNotContainsString('identité non établie', $imprime);
        $this->assertStringNotContainsString('Échec du code', $imprime);

        // Le PDF reste produit (dompdf) .
        $this->assertStringStartsWith('%PDF', $service->pdf($service->html($this->decharge->refresh(), 'reception')));
    }
}

<?php

namespace Tests\Feature\Logistique;

use App\Models\Logistique\Decharge;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\PlanDotation;
use App\Models\Logistique\Reclamation;
use App\Models\Logistique\Remise;
use App\Models\User;
use App\Services\Logistique\DechargeService;
use App\Services\Logistique\RemiseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\PlanApprouveFictif;
use Tests\Support\RecensementFictif;
use Tests\TestCase;

class EspaceTerrainTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    /** @var array{regions: array<string,int>, departements: array<string,int>} */
    private array $geo;

    private Destinataire $prefectureKaolack;

    private PlanDotation $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('logistique.disque_decharges'));
        RecensementFictif::creerSchema();
        $this->geo = RecensementFictif::geo([
            'Thiès' => ['Thiès' => ['Sindia' => ['Ndiass']]],
            'Kaolack' => ['Kaolack' => ['Gandiaye' => ['Gandiaye']]],
        ]);
        $this->creerDecorFictif();
        $this->prefecture->update(['departement_id' => $this->geo['departements']['Thiès']]);
        $this->prefectureKaolack = Destinataire::create([
            'type' => 'prefecture', 'libelle' => 'Préfecture de Kaolack', 'departement_id' => $this->geo['departements']['Kaolack'],
        ]);

        $this->plan = $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 100],
            [$this->sousPrefecture1, $this->urnes, 100],
            [$this->prefectureKaolack, $this->urnes, 40],
        ]);
        $this->stockerFictif($this->urnes, 1000, 500);
    }

    private function utilisateur(string $role, array $attributs = []): User
    {
        return User::factory()->create(['role' => $role] + $attributs)->fresh();
    }

    private function gouverneur(string $region = 'Thiès'): User
    {
        return $this->utilisateur('gouverneur', ['region_id' => $this->geo['regions'][$region], 'region_nom' => $region]);
    }

    /** Décharge via le préfet, chargée puis reçue par le préfet (100 urnes). */
    private function recueParLePrefet(int $defectueux = 0): Decharge
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => ['recu' => 100, 'defectueux' => $defectueux]]);

        return $decharge->refresh();
    }

    private function redistribution(Decharge $decharge, int $quantite): Remise
    {
        $this->actingAs($this->autoriteFictive($this->prefecture));
        $remise = app(RemiseService::class)->preparerRedistribution($decharge, [$this->urnes->id => $quantite]);
        Auth::logout();

        return $remise;
    }

    /** Décharge écrite directement (hors circuit de validation) pour tester la seule visibilité. */
    private function deposer(Destinataire $destinataire, string $statut = 'validee', string $categorie = 'materiel_lourd'): Decharge
    {
        $decharge = Decharge::create([
            'cycle_id' => $this->cycle->id, 'destinataire_id' => $destinataire->id, 'categorie' => $categorie,
            'nature' => 'initiale', 'plan_dotation_id' => $this->plan->id, 'prepare_par' => $this->responsable->id,
        ]);
        $decharge->forceFill(['statut_document' => $statut])->save();

        return $decharge;
    }

    /** Décharge de la préfecture de Kaolack (hors périmètre du préfet de Thiès). */
    private function dechargeKaolack(string $statut = 'validee'): Decharge
    {
        return $this->deposer($this->prefectureKaolack, $statut);
    }

    /** @return array<string, mixed> */
    private function corpsDeclaration(Remise $remise, int $recu, int $defectueux = 0, ?string $cle = null): array
    {
        return [
            'cle' => $cle ?? (string) Str::ulid(),
            'signataire_nom' => 'Aminata Sow', 'signataire_qualite' => 'Sous-préfète',
            'lignes' => $remise->lignes->mapWithKeys(fn ($l) => [$l->materiel_id => [
                'recu' => $recu, 'defectueux' => $defectueux, 'observation' => 'Colis ouverts',
            ]])->all(),
        ];
    }

    public function test_sous_prefet_declare_depuis_son_compte(): void
    {
        $decharge = $this->recueParLePrefet();
        $redistribution = $this->redistribution($decharge, 80)->load('lignes');
        $sousPrefet = $this->autoriteFictive($this->sousPrefecture1);
        $this->assertSame('recue_intermediaire', $decharge->fresh()->statut_acheminement);

        $this->actingAs($sousPrefet)->get(route('terrain.decharges.show', $decharge))
            ->assertOk()
            ->assertSee($decharge->numero)
            ->assertSee(route('terrain.declarations.store', $redistribution), false)
            ->assertSee($sousPrefet->nom_complet)
            ->assertSee('Sous-préfet')
            ->assertDontSee(route('terrain.redistributions.store', $decharge), false);

        $this->post(route('terrain.declarations.store', $redistribution), $this->corpsDeclaration($redistribution, 80, 5))
            ->assertRedirect(route('terrain.decharges.show', $decharge))
            ->assertSessionHas('success');

        $declaration = Declaration::where('remise_id', $redistribution->id)->sole();
        $this->assertSame('compte', $declaration->mode);
        $this->assertSame('retenue', $declaration->statut);
        $this->assertSame($sousPrefet->id, $declaration->user_id);
        $this->assertSame(['Aminata Sow', 'Sous-préfète'], [$declaration->signataire_nom, $declaration->signataire_qualite]);
        $this->assertSame([[80, 5, 'Colis ouverts']], $declaration->lignes->map(fn ($l) => [$l->recu, $l->defectueux, $l->observation])->all());
        $this->assertSame('recue_destinataire', $decharge->fresh()->statut_acheminement);

        $this->get(route('terrain.decharges.show', $decharge))->assertOk()->assertSee('Retenue')->assertSee('Aminata Sow');
    }

    public function test_declaration_refusee_affiche_l_erreur_du_service(): void
    {
        $decharge = $this->recueParLePrefet();
        $redistribution = $this->redistribution($decharge, 80)->load('lignes');
        $this->actingAs($this->autoriteFictive($this->sousPrefecture1));

        // Défectueux supérieur au reçu : règle du service, jamais d'erreur 500.
        $this->post(route('terrain.declarations.store', $redistribution), $this->corpsDeclaration($redistribution, 10, 20))
            ->assertRedirect()->assertSessionHas('error', 'Défectueux supérieur au reçu pour Urnes.');
        // Champs absents : validation du formulaire.
        $this->post(route('terrain.declarations.store', $redistribution), ['cle' => 'court'])
            ->assertSessionHasErrors(['cle', 'signataire_nom', 'signataire_qualite', 'lignes']);

        $this->assertSame(0, Declaration::where('remise_id', $redistribution->id)->count());
    }

    public function test_double_envoi_une_seule_declaration(): void
    {
        $decharge = $this->recueParLePrefet();
        $redistribution = $this->redistribution($decharge, 80)->load('lignes');
        $this->actingAs($this->autoriteFictive($this->sousPrefecture1));
        $corps = $this->corpsDeclaration($redistribution, 80);

        $this->post(route('terrain.declarations.store', $redistribution), $corps)->assertSessionHas('success');
        $this->post(route('terrain.declarations.store', $redistribution), $corps)->assertSessionHas('success')->assertSessionMissing('error');

        $this->assertSame(1, Declaration::where('remise_id', $redistribution->id)->count());
        // La page redonne un formulaire neuf à chaque affichage.
        $premiere = $this->get(route('terrain.decharges.show', $decharge))->getContent();
        $this->assertSame(0, preg_match('/name="cle"/', $premiere), 'Plus de formulaire une fois la réception retenue.');
    }

    public function test_cle_du_formulaire_est_un_ulid_neuf_a_chaque_affichage(): void
    {
        $decharge = $this->recueParLePrefet();
        $this->redistribution($decharge, 80);
        $this->actingAs($this->autoriteFictive($this->sousPrefecture1));

        $cles = [];
        foreach ([1, 2] as $_) {
            $this->assertSame(1, preg_match('/name="cle" value="([0-9A-HJKMNP-TV-Z]{26})"/', $this->get(route('terrain.decharges.show', $decharge))->getContent(), $m));
            $cles[] = $m[1];
        }
        $this->assertNotSame($cles[0], $cles[1]);
    }

    public function test_prefet_declare_la_livraison_et_voit_son_solde(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $livraison = Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->with('lignes')->sole();
        $prefet = $this->autoriteFictive($this->prefecture);
        $this->actingAs($prefet);

        $this->get(route('terrain.decharges.show', $decharge))
            ->assertOk()->assertSee(route('terrain.declarations.store', $livraison), false);
        $this->post(route('terrain.declarations.store', $livraison), $this->corpsDeclaration($livraison, 100, 10))
            ->assertSessionHas('success');

        $this->get(route('terrain.decharges.show', $decharge))
            ->assertOk()
            ->assertSeeInOrder(['Solde du préfet', 'Reçu utilisable', 'Transféré', 'Reliquat', 'Urnes', '90', '0', '90'])
            ->assertSee(route('terrain.redistributions.store', $decharge), false);
    }

    public function test_prefet_redistribue_depuis_son_espace(): void
    {
        $decharge = $this->recueParLePrefet();
        $prefet = $this->autoriteFictive($this->prefecture);
        $this->actingAs($prefet);

        $this->get(route('terrain.decharges.show', $decharge))
            ->assertOk()
            ->assertSeeInOrder(['Solde du préfet', 'Reçu utilisable', 'Transféré', 'Reliquat', 'Urnes', '100', '0', '100'])
            ->assertSee(route('terrain.redistributions.store', $decharge), false);

        // Plus que le reliquat : refus du service.
        $this->post(route('terrain.redistributions.store', $decharge), ['lignes' => [$this->urnes->id => 150]])
            ->assertSessionHas('error');
        $this->assertSame(0, Remise::where('etape', 'redistribution')->count());

        $this->post(route('terrain.redistributions.store', $decharge), ['lignes' => [$this->urnes->id => 80]])
            ->assertRedirect(route('terrain.decharges.show', $decharge))->assertSessionHas('success');

        $remise = Remise::where('etape', 'redistribution')->with('lignes')->sole();
        $this->assertSame('en_attente', $remise->statut);
        $this->assertSame(80, $remise->lignes->sole()->quantite);

        $this->get(route('terrain.decharges.show', $decharge))
            ->assertOk()
            ->assertSeeInOrder(['Reçu utilisable', 'Transféré', 'Reliquat', 'Urnes', '100', '80', '20'])
            ->assertSee(route('terrain.redistributions.annuler', $remise), false);

        // Annulation : motif obligatoire, puis le reliquat est libéré.
        $this->post(route('terrain.redistributions.annuler', $remise), [])->assertSessionHasErrors('motif');
        $this->post(route('terrain.redistributions.annuler', $remise), ['motif' => 'Mauvais destinataire.'])
            ->assertSessionHas('success');
        $this->assertSame('annulee', $remise->fresh()->statut);
        $this->get(route('terrain.decharges.show', $decharge))
            ->assertOk()->assertSeeInOrder(['Reçu utilisable', 'Transféré', 'Reliquat', 'Urnes', '100', '0', '100']);
    }

    public function test_redistribution_impossible_sur_un_cycle_clos_mais_declaration_possible(): void
    {
        $decharge = $this->recueParLePrefet();
        $redistribution = $this->redistribution($decharge, 60)->load('lignes');
        $this->cycle->forceFill(['statut' => 'clos'])->save();

        $prefet = $this->autoriteFictive($this->prefecture);
        $this->actingAs($prefet)->get(route('terrain.decharges.show', $decharge))
            ->assertOk()
            ->assertDontSee(route('terrain.redistributions.store', $decharge), false)
            ->assertDontSee(route('terrain.redistributions.annuler', $redistribution), false);
        $this->post(route('terrain.redistributions.store', $decharge), ['lignes' => [$this->urnes->id => 10]])
            ->assertSessionHas('error', 'Cycle clôturé : redistribution impossible.');

        // P4-R17 : une réception se déclare après la clôture.
        $this->actingAs($this->autoriteFictive($this->sousPrefecture1))
            ->post(route('terrain.declarations.store', $redistribution), $this->corpsDeclaration($redistribution, 60))
            ->assertSessionHas('success');
        $this->assertSame(1, Declaration::where('remise_id', $redistribution->id)->count());
    }

    public function test_prefet_ne_voit_pas_une_autre_prefecture(): void
    {
        $prefet = $this->autoriteFictive($this->prefecture);
        $propre = $this->recueParLePrefet();
        $autre = $this->dechargeKaolack();
        $brouillon = $this->dechargeKaolack('brouillon');
        $brouillonPropre = $this->deposer($this->prefecture, 'brouillon', 'imprime');
        $remiseAutre = Remise::create(['decharge_id' => $autre->id, 'etape' => 'livraison', 'de_type' => 'agent', 'de_id' => 1, 'a_type' => 'destinataire', 'a_id' => $this->prefectureKaolack->id, 'preparee_par' => $this->responsable->id]);
        $this->actingAs($prefet);

        $this->get(route('terrain.decharges.index'))
            ->assertOk()->assertSee($propre->numero)->assertDontSee('Préfecture de Kaolack');
        $this->get(route('terrain.decharges.show', $propre))->assertOk();
        foreach ([$autre, $brouillon, $brouillonPropre] as $inaccessible) {
            $this->get(route('terrain.decharges.show', $inaccessible))->assertNotFound();
        }

        $this->post(route('terrain.redistributions.store', $autre), ['lignes' => [$this->urnes->id => 1]])->assertNotFound();
        $this->post(route('terrain.reclamations.store', $autre), [
            'materiel_id' => $this->urnes->id, 'motif' => 'manquant', 'quantite' => 1, 'message' => 'Test',
        ])->assertNotFound();
        $this->post(route('terrain.declarations.store', $remiseAutre), ['cle' => (string) Str::ulid()])->assertNotFound();
        $this->post(route('terrain.redistributions.annuler', $remiseAutre), ['motif' => 'x'])->assertNotFound();

        $this->assertSame(0, Reclamation::count());
        $this->assertSame(0, Declaration::where('remise_id', $remiseAutre->id)->count());
    }

    public function test_liste_paginee_et_limitee_au_perimetre(): void
    {
        $this->recueParLePrefet();
        $this->dechargeKaolack();
        $this->actingAs($this->autoriteFictive($this->prefecture));

        $reponse = $this->get(route('terrain.decharges.index'))->assertOk();
        $reponse->assertViewHas('decharges', fn ($decharges) => $decharges->perPage() === 50 && $decharges->total() === 1);
    }

    public function test_agent_voit_ses_affectations_et_reclame(): void
    {
        $agent = $this->utilisateur('agent_transport');
        $affectee = $this->deposerChargee($this->prefecture, [$this->urnes->id => 100], $agent);
        $autre = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 50]);
        $livraison = Remise::where('decharge_id', $affectee->id)->where('etape', 'livraison')->with('lignes')->sole();
        $this->actingAs($agent);

        $this->get(route('terrain.decharges.index'))
            ->assertOk()->assertSee($affectee->numero)->assertDontSee($autre->numero);
        $this->get(route('terrain.decharges.show', $affectee))
            ->assertOk()->assertSee('Livraison')->assertSee('Chargement')
            ->assertSee(route('terrain.reclamations.store', $affectee), false)
            // Pas de déclaration « compte » pour l'agent : web = mode compte de l'autorité destinataire.
            ->assertDontSee(route('terrain.declarations.store', $livraison), false);
        $this->get(route('terrain.decharges.show', $autre))->assertNotFound();

        $this->post(route('terrain.declarations.store', $livraison), $this->corpsDeclaration($livraison, 100))
            ->assertSessionHas('error');
        $this->assertSame(0, Declaration::count());

        $this->post(route('terrain.reclamations.store', $affectee), [
            'materiel_id' => $this->urnes->id, 'motif' => 'manquant', 'quantite' => 10, 'message' => 'Dix urnes absentes au chargement.',
        ])->assertRedirect(route('terrain.decharges.show', $affectee))->assertSessionHas('success');

        $reclamation = Reclamation::sole();
        $this->assertSame([$affectee->id, $this->urnes->id, 'manquant', 10, $agent->id], [
            $reclamation->decharge_id, $reclamation->materiel_id, $reclamation->motif, $reclamation->quantite, $reclamation->ouverte_par,
        ]);
        $this->assertSame('ouverte', $affectee->fresh()->statut_reclamation);
        $this->get(route('terrain.decharges.show', $affectee))
            ->assertOk()->assertSee('Dix urnes absentes au chargement.')->assertSee('Ouverte');

        // Quantité réclamée supérieure à la décharge, formulaire incomplet : refus sans erreur 500.
        $this->post(route('terrain.reclamations.store', $affectee), [
            'materiel_id' => $this->urnes->id, 'motif' => 'manquant', 'quantite' => 101, 'message' => 'Trop.',
        ])->assertSessionHas('error');
        $this->post(route('terrain.reclamations.store', $affectee), ['motif' => 'inconnu'])
            ->assertSessionHasErrors(['materiel_id', 'motif', 'quantite', 'message']);
        $this->assertSame(1, Reclamation::count());
    }

    public function test_reclamation_possible_sur_un_cycle_clos(): void
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 100]);
        $this->cycle->forceFill(['statut' => 'clos'])->save();

        $this->actingAs($this->autoriteFictive($this->prefecture))
            ->post(route('terrain.reclamations.store', $decharge), [
                'materiel_id' => $this->urnes->id, 'motif' => 'defectueux', 'quantite' => 5, 'message' => 'Urnes fendues.',
            ])->assertSessionHas('success');
        $this->assertSame(1, Reclamation::count());
    }

    public function test_decharge_non_partie_sans_formulaire_de_reclamation(): void
    {
        $service = app(DechargeService::class);
        $decharge = $service->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        foreach ($decharge->lignes as $ligne) {
            $service->modifierLigne($ligne, $ligne->materiel_id === $this->urnes->id ? 10 : 0, null);
        }
        $service->valider($decharge);
        $this->assertSame('a_preparer', $decharge->fresh()->statut_acheminement);

        $this->actingAs($this->autoriteFictive($this->prefecture))->get(route('terrain.decharges.show', $decharge))
            ->assertOk()->assertDontSee(route('terrain.reclamations.store', $decharge), false);
    }

    public function test_gouverneur_suivi_de_sa_region_lecture_seule(): void
    {
        $thies = $this->recueParLePrefet();
        $kaolack = $this->dechargeKaolack();
        $gouverneur = $this->gouverneur();
        $this->actingAs($gouverneur);

        $this->get(route('terrain.suivi'))
            ->assertOk()
            ->assertSee('Préfecture de Thiès')->assertSee('Sous-préfecture de Sindia')
            ->assertDontSee('Préfecture de Kaolack')
            ->assertDontSee('Clôturer avec écart');
        // Indicateurs seulement (§14) : ni la liste, ni la page d'une décharge, même de sa région.
        $this->get(route('terrain.decharges.index'))->assertForbidden();
        $this->get(route('terrain.decharges.show', $thies))->assertNotFound();
        $this->get(route('terrain.decharges.show', $kaolack))->assertNotFound();
        $this->get(route('logistique.decharges.qr', $thies->identifiant))->assertForbidden();

        // Toute écriture du terrain lui est interdite.
        $this->post(route('terrain.reclamations.store', $thies), [
            'materiel_id' => $this->urnes->id, 'motif' => 'manquant', 'quantite' => 1, 'message' => 'Test',
        ])->assertForbidden();
        $this->post(route('terrain.redistributions.store', $thies), ['lignes' => [$this->urnes->id => 1]])->assertForbidden();
        $this->assertSame(0, Reclamation::count());
        $this->assertSame(0, Remise::where('etape', 'redistribution')->count());
    }

    public function test_suivi_du_terrain_limite_au_perimetre(): void
    {
        $this->recueParLePrefet();
        $this->actingAs($this->autoriteFictive($this->prefecture));

        $this->get(route('terrain.suivi'))
            ->assertOk()->assertSee('Par destinataire')->assertSee('Par article')
            ->assertSee('Préfecture de Thiès')->assertDontSee('Préfecture de Kaolack')
            ->assertSee('Urnes');

        // Un gouverneur sans région, un préfet sans préfecture : rien, jamais tout.
        $this->actingAs($this->utilisateur('prefet'))->get(route('terrain.suivi'))->assertOk()->assertDontSee('Préfecture de');
        $this->actingAs($this->utilisateur('gouverneur'))->get(route('terrain.suivi'))->assertOk()->assertDontSee('Préfecture de');
    }

    public function test_suivi_choisit_le_cycle(): void
    {
        $this->actingAs($this->autoriteFictive($this->prefecture));

        $this->get(route('terrain.suivi'))->assertOk()->assertViewHas('cycle', fn ($cycle) => $cycle->id === $this->cycle->id);
        $this->get(route('terrain.suivi', ['cycle' => 'abc']))->assertOk();
        $this->get(route('terrain.suivi', ['cycle' => 9999]))->assertOk();
    }

    public function test_agent_de_transport_sans_suivi(): void
    {
        $this->actingAs($this->utilisateur('agent_transport'))->get(route('terrain.suivi'))->assertForbidden();
    }

    public function test_roles_centraux_sans_espace_terrain(): void
    {
        $decharge = $this->recueParLePrefet();
        $remise = Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->sole();

        foreach (['logistique_gestionnaire', 'logistique_responsable', 'admin', 'commission'] as $role) {
            $this->actingAs($this->utilisateur($role));
            $this->get(route('terrain.decharges.index'))->assertForbidden();
            $this->get(route('terrain.decharges.show', $decharge))->assertNotFound();
            $this->get(route('terrain.suivi'))->assertForbidden();
            $this->post(route('terrain.declarations.store', $remise), ['cle' => (string) Str::ulid()])->assertForbidden();
            $this->post(route('terrain.redistributions.store', $decharge), [])->assertForbidden();
            $this->post(route('terrain.redistributions.annuler', $remise), ['motif' => 'x'])->assertForbidden();
            $this->post(route('terrain.reclamations.store', $decharge), [])->assertForbidden();
        }
    }

    public function test_compte_inactif_sans_espace_terrain(): void
    {
        $this->recueParLePrefet();
        $prefet = $this->autoriteFictive($this->prefecture);
        $prefet->forceFill(['actif' => false])->save();

        $this->actingAs($prefet)->get(route('terrain.decharges.index'))->assertForbidden();
        $this->get(route('terrain.suivi'))->assertForbidden();
    }

    public function test_routes_terrain_toutes_protegees(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'terrain.'))
            ->keyBy(fn ($r) => $r->getName());

        $this->assertEqualsCanonicalizing([
            'terrain.decharges.index', 'terrain.decharges.show', 'terrain.suivi',
            'terrain.declarations.store', 'terrain.redistributions.store', 'terrain.redistributions.annuler', 'terrain.reclamations.store',
            'terrain.code.edit', 'terrain.code.update',
            'terrain.delegations.index', 'terrain.delegations.store', 'terrain.delegations.revoquer',
            'terrain.pieces.show',
        ], $routes->keys()->all());

        foreach ($routes as $nom => $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth', $middleware, $nom);
            // Le suivi ouvre aussi au gouverneur ; la page d'une décharge se refuse dans le contrôleur (404).
            $attendu = match ($nom) {
                'terrain.suivi' => 'can:logistique-suivi-terrain',
                'terrain.decharges.show' => null,
                default => 'can:logistique-terrain',
            };
            if ($attendu !== null) {
                $this->assertContains($attendu, $middleware, $nom);
            }
            // Tout l'espace terrain mène d'abord au choix du code, sauf les pages du code, le suivi et la page d'une décharge.
            if (in_array($nom, ['terrain.suivi', 'terrain.decharges.show'], true) || str_starts_with($nom, 'terrain.code.')) {
                $this->assertNotContains('premier-acces', $middleware, $nom);
            } else {
                $this->assertContains('premier-acces', $middleware, $nom);
            }
            $this->assertNotContains('can:logistique-suivi-terrain', array_diff($middleware, $nom === 'terrain.suivi' ? ['can:logistique-suivi-terrain'] : []), "{$nom} : ouvert au gouverneur.");
        }
    }

    public function test_qr_dans_le_perimetre_redirige_vers_le_terrain(): void
    {
        $decharge = $this->recueParLePrefet();

        $this->actingAs($this->autoriteFictive($this->prefecture))
            ->get(route('logistique.decharges.qr', $decharge->identifiant))
            ->assertRedirect(route('terrain.decharges.show', $decharge));
        $this->actingAs($this->autoriteFictive($this->sousPrefecture1))
            ->get(route('logistique.decharges.qr', $decharge->identifiant))
            ->assertRedirect(route('terrain.decharges.show', $decharge));
        $this->actingAs($decharge->agent)
            ->get(route('logistique.decharges.qr', $decharge->identifiant))
            ->assertRedirect(route('terrain.decharges.show', $decharge));
    }

    public function test_qr_hors_perimetre_refuse(): void
    {
        $decharge = $this->recueParLePrefet();
        $autrePrefet = $this->utilisateur('prefet');
        $autre = $this->autoriteFictive($this->prefectureKaolack);

        foreach ([$autrePrefet, $autre, $this->gouverneur('Kaolack'), $this->utilisateur('commission'), $this->utilisateur('agent_transport')] as $utilisateur) {
            $this->actingAs($utilisateur)->get(route('logistique.decharges.qr', $decharge->identifiant))->assertForbidden();
        }
    }

    public function test_qr_d_un_brouillon_refuse_au_terrain(): void
    {
        $brouillon = $this->deposer($this->prefecture, 'brouillon', 'imprime');

        $this->actingAs($this->autoriteFictive($this->prefecture))
            ->get(route('logistique.decharges.qr', $brouillon->identifiant))->assertForbidden();
    }

    public function test_qr_gestionnaire_vers_le_central(): void
    {
        $decharge = $this->recueParLePrefet();

        foreach (['logistique_gestionnaire', 'logistique_responsable', 'admin'] as $role) {
            $this->actingAs($this->utilisateur($role))->get(route('logistique.decharges.qr', $decharge->identifiant))
                ->assertRedirect(route('logistique.decharges.show', $decharge));
        }
    }

    public function test_qr_inconnu_et_sans_connexion(): void
    {
        $decharge = $this->recueParLePrefet();

        $this->actingAs($this->autoriteFictive($this->prefecture))->get(route('logistique.decharges.qr', 'inconnu'))->assertNotFound();
        Auth::logout();
        $this->get(route('logistique.decharges.qr', $decharge->identifiant))->assertRedirect(route('login'));
    }

    public function test_menu_terrain(): void
    {
        $this->recueParLePrefet();

        $this->actingAs($this->autoriteFictive($this->prefecture))->get(route('terrain.decharges.index'))
            ->assertOk()->assertSee('Mes décharges')->assertSee(route('terrain.suivi'), false)
            ->assertDontSee('Suivi de la région')->assertDontSee(route('logistique.stock.index'), false);
        $this->actingAs($this->gouverneur())->get(route('terrain.suivi'))
            ->assertOk()->assertSee('Suivi de la région')->assertDontSee('Mes décharges')->assertDontSee('Décharges de la région')
            ->assertDontSee(route('terrain.decharges.index'), false);
        $this->actingAs($this->utilisateur('agent_transport'))->get(route('terrain.decharges.index'))
            ->assertOk()->assertSee('Mes décharges')->assertDontSee(route('terrain.suivi'), false)
            ->assertDontSee(route('operations.index'), false);
    }

    public function test_chef_de_poste_et_agent_arrivent_sur_leur_espace(): void
    {
        foreach (['chef_poste', 'agent_transport'] as $role) {
            $this->actingAs($this->utilisateur($role))->get(route('dashboard'))->assertRedirect(route('terrain.decharges.index'));
        }
    }
}

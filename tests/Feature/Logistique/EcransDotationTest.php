<?php

namespace Tests\Feature\Logistique;

use App\Models\Logistique\Commande;
use App\Models\Logistique\CommissionAdministrative;
use App\Models\Logistique\CorrespondanceGeo;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\InstantaneEffectifs;
use App\Models\Logistique\InstantaneLigne;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\PlanDotation;
use App\Models\Logistique\PlanDotationLigne;
use App\Models\Logistique\PosteEtranger;
use App\Models\User;
use App\Services\Logistique\CleService;
use App\Services\Logistique\DecoupageService;
use App\Services\Logistique\PlanDotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\RecensementFictif;
use Tests\TestCase;

class EcransDotationTest extends TestCase
{
    use RefreshDatabase;

    private Cycle $cycle;

    private User $gestionnaire;

    private User $responsable;

    private Materiel $urnes;

    private Destinataire $sindia;

    private InstantaneEffectifs $instantane;

    private PlanDotation $plan;

    /** @var array<string,int> */
    private array $communes;

    protected function setUp(): void
    {
        parent::setUp();
        RecensementFictif::creerSchema();
        $this->gestionnaire = User::factory()->create(['role' => 'logistique_gestionnaire']);
        $this->responsable = User::factory()->create(['role' => 'logistique_responsable']);
        $this->actingAs($this->responsable);

        $geo = RecensementFictif::geo(['Thiès' => ['Mbour' => ['Sindia' => ['Ndiass']]]]);
        $this->communes = $geo['communes'];

        $decoupage = app(DecoupageService::class);
        $decoupage->genererDestinataires();
        $this->cycle = Cycle::create(['libelle' => 'Cycle A', 'type' => 'scrutin']);
        $decoupage->genererPerimetre($this->cycle);
        $this->sindia = Destinataire::where('libelle', 'Sous-préfecture de Sindia')->firstOrFail();

        $this->urnes = Materiel::create(['libelle' => 'Urnes', 'categorie' => 'materiel_lourd', 'unite_stock' => 'pièce']);
        app(CleService::class)->enregistrer($this->cycle, $this->urnes, [
            'base' => 'bureau_vote', 'ratio' => '1', 'majoration_distribuee' => '0', 'cle_affichee' => '1 par bureau de vote',
        ]);

        $this->instantane = $this->instantaneFige(['electeurs_non_rapproches' => 0]);
        $this->plan = app(PlanDotationService::class)->calculer($this->cycle, $this->instantane);
    }

    /** @param  array<string,mixed>  $bilan */
    private function instantaneFige(array $bilan = []): InstantaneEffectifs
    {
        $instantane = InstantaneEffectifs::create([
            'cycle_id' => $this->cycle->id,
            'version'  => (int) InstantaneEffectifs::where('cycle_id', $this->cycle->id)->max('version') + 1,
            'user_id'  => $this->responsable->id,
        ]);
        $instantane->forceFill(['statut' => 'fige', 'bilan' => $bilan])->save();
        InstantaneLigne::create([
            'instantane_id' => $instantane->id, 'commune_id' => $this->communes['Ndiass'], 'departement_id' => 1,
            'libelle_commune' => 'Ndiass', 'electeurs' => 5000, 'bureaux_vote' => 31, 'lieux_vote' => 10,
            'commissions' => 1, 'rapprochee' => true,
        ]);

        return $instantane;
    }

    private function ligne(): PlanDotationLigne
    {
        return PlanDotationLigne::where('plan_dotation_id', $this->plan->id)->firstOrFail();
    }

    public function test_gestionnaire_voit_plan_mais_ne_peut_pas_approuver(): void
    {
        $this->actingAs($this->gestionnaire);

        $this->get(route('logistique.plans.show', $this->plan))
            ->assertOk()
            ->assertDontSee('Approuver');

        $this->post(route('logistique.plans.approuver', $this->plan))->assertForbidden();
        $this->assertSame('brouillon', $this->plan->fresh()->statut);
    }

    public function test_responsable_approuve_depuis_l_ecran(): void
    {
        $this->get(route('logistique.plans.show', $this->plan))
            ->assertOk()
            ->assertSee('Approuver');

        $this->post(route('logistique.plans.approuver', $this->plan))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('approuve', $this->plan->fresh()->statut);
    }

    public function test_approbation_bloquee_affiche_l_erreur(): void
    {
        $this->ligne()->update(['anomalie' => 'Effectif absent pour Ndiass']);

        $this->post(route('logistique.plans.approuver', $this->plan))
            ->assertRedirect()
            ->assertSessionHas('error', 'Plan non approuvable : 1 anomalie(s).');

        $this->assertSame('brouillon', $this->plan->fresh()->statut);
    }

    public function test_annexe_de_calcul_affichee(): void
    {
        $this->get(route('logistique.plans.show', $this->plan))
            ->assertOk()
            ->assertSeeInOrder(['Urnes', '31', '31.0000', '0.00', '31']);
    }

    public function test_correction_sans_motif_refusee(): void
    {
        $this->post(route('logistique.plan-lignes.corriger', $this->ligne()), ['valeur' => 40])
            ->assertSessionHasErrors('motif');

        $this->assertNull($this->ligne()->correction);
    }

    public function test_bilan_instantane_affiche(): void
    {
        $instantane = $this->instantaneFige(['electeurs_total' => 5012, 'electeurs_non_rapproches' => 12]);

        $this->get(route('logistique.instantanes.show', $instantane))
            ->assertOk()
            ->assertSee('Électeurs non rapprochés')
            ->assertSeeInOrder(['Électeurs non rapprochés', '12']);
    }

    public function test_proposition_de_commande_creee(): void
    {
        app(PlanDotationService::class)->approuver($this->plan);

        $this->get(route('logistique.besoin.show', $this->cycle))->assertOk()->assertSee('Urnes');

        $this->post(route('logistique.besoin.commander', $this->cycle), [
            'reference'     => 'CMD-DOT-1',
            'fournisseur'   => 'Imprimerie nationale',
            'date_commande' => '2026-11-10',
            'quantites'     => [$this->urnes->id => 31],
        ])->assertRedirect();

        $commande = Commande::where('reference', 'CMD-DOT-1')->firstOrFail();
        $this->assertSame('brouillon', $commande->statut);
        $this->assertSame($this->cycle->id, $commande->lignes()->firstOrFail()->cycle_id);
    }

    public function test_commandes_brouillon_du_cycle_rappelees_avant_la_proposition(): void
    {
        app(PlanDotationService::class)->approuver($this->plan);
        $this->get(route('logistique.besoin.show', $this->cycle))
            ->assertOk()->assertDontSee('Des commandes brouillon existent déjà pour ce cycle.');

        $this->post(route('logistique.besoin.commander', $this->cycle), [
            'reference' => 'CMD-DOT-1', 'fournisseur' => 'Imprimerie nationale', 'date_commande' => '2026-11-10',
            'quantites' => [$this->urnes->id => 31],
        ])->assertRedirect();
        Commande::create(['reference' => 'CMD-HORS-CYCLE', 'fournisseur' => 'Autre', 'date_commande' => '2026-11-11', 'user_id' => $this->responsable->id]);

        $this->get(route('logistique.besoin.show', $this->cycle))
            ->assertOk()
            ->assertSeeInOrder(['Des commandes brouillon existent déjà pour ce cycle.', 'CMD-DOT-1', '10/11/2026', '1 ligne', 'Proposition'])
            ->assertDontSee('CMD-HORS-CYCLE');
    }

    public function test_gestionnaire_ne_modifie_pas_les_cles(): void
    {
        $this->actingAs($this->gestionnaire);

        $this->put(route('logistique.cles.update', [$this->cycle, $this->urnes]), ['base' => 'electeur', 'ratio' => '2'])
            ->assertForbidden();
        $this->get(route('logistique.cles.index', $this->cycle))->assertOk();
    }

    public function test_menu_dotation_visible(): void
    {
        $this->actingAs($this->gestionnaire);

        $this->get(route('logistique.cycles.index'))
            ->assertOk()
            ->assertSee('Plans de dotation')
            ->assertSee('Géographie')
            ->assertSee('Effectifs');
    }

    public function test_tous_les_ecrans_de_lecture_se_rendent(): void
    {
        app(CleService::class)->ajouterEntite($this->cycle, ['type' => 'liste', 'nom' => 'Liste B']);
        CorrespondanceGeo::create([
            'contexte_region' => 'Thiès', 'contexte_departement' => 'Mbour', 'libelle_commune' => 'Ndiass', 'statut' => 'proposee',
        ]);
        PosteEtranger::create(['pays' => 'France', 'ville' => 'Paris', 'type' => 'consulat', 'nom' => 'Consulat de Paris']);

        foreach ([$this->responsable, $this->gestionnaire] as $utilisateur) {
            $this->actingAs($utilisateur);
            $ecrans = [
                route('logistique.destinataires.index'),
                route('logistique.destinataires.index', ['type' => 'sous_prefecture', 'q' => 'Sindia']),
                route('logistique.decoupage.show', $this->cycle),
                route('logistique.decoupage.show', [$this->cycle, 'destinataire' => $this->sindia->id]),
                route('logistique.decoupage.show', [$this->cycle, 'sans_destinataire' => 1]),
                route('logistique.correspondances.index'),
                route('logistique.correspondances.index', ['statut' => 'tous']),
                route('logistique.commissions.index', $this->cycle),
                route('logistique.instantanes.index', $this->cycle),
                route('logistique.instantanes.show', $this->instantane),
                route('logistique.instantanes.show', [$this->instantane, 'non_rapprochees' => 1]),
                route('logistique.entites.index', $this->cycle),
                route('logistique.cles.index', $this->cycle),
                route('logistique.plans.index', $this->cycle),
                route('logistique.plans.show', $this->plan),
                route('logistique.plans.show', [$this->plan, 'anomalies' => 1, 'materiel' => $this->urnes->id, 'destinataire' => $this->sindia->id]),
                route('logistique.besoin.show', $this->cycle),
            ];
            foreach ($ecrans as $url) {
                $this->get($url)->assertOk();
            }
        }

        $this->actingAs($this->responsable);
        foreach ([route('logistique.postes.index'), route('logistique.postes.create')] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('logistique.postes.edit', PosteEtranger::firstOrFail()))->assertOk();

        $this->actingAs($this->gestionnaire);
        $this->get(route('logistique.postes.index'))->assertForbidden();
    }

    public function test_ecritures_depuis_les_ecrans(): void
    {
        // Postes et destinataires.
        $this->post(route('logistique.postes.store'), ['pays' => 'France', 'ville' => 'Paris', 'type' => 'consulat', 'nom' => 'Consulat de Paris'])
            ->assertRedirect(route('logistique.postes.index'));
        $poste = PosteEtranger::firstOrFail();
        $this->put(route('logistique.postes.update', $poste), ['pays' => 'France', 'ville' => 'Paris', 'type' => 'ambassade', 'nom' => 'Ambassade de Paris'])
            ->assertRedirect(route('logistique.postes.index'));
        $this->assertSame(['ambassade', false], [$poste->fresh()->type, $poste->fresh()->actif]);

        $this->put(route('logistique.destinataires.update', $this->sindia), ['signataire_nom' => 'M. Diop', 'signataire_qualite' => 'Sous-préfet', 'actif' => '1'])
            ->assertSessionHas('success');
        $this->assertSame('M. Diop', $this->sindia->fresh()->signataire_nom);

        // Découpage : affectation avec motif obligatoire.
        $autre = Destinataire::where('type', 'prefecture')->firstOrFail();
        $this->post(route('logistique.decoupage.affecter', $this->cycle), ['destinataire_id' => $autre->id, 'commune_id' => $this->communes['Ndiass']])
            ->assertSessionHasErrors('motif');
        $this->post(route('logistique.decoupage.affecter', $this->cycle), [
            'destinataire_id' => $autre->id, 'commune_id' => $this->communes['Ndiass'], 'motif' => 'Rattachement',
        ])->assertSessionHas('success');
        $this->assertSame($autre->id, (int) \App\Models\Logistique\PerimetreDestinataire::where('commune_id', $this->communes['Ndiass'])->value('destinataire_id'));

        // Clé : ratio et majoration restent des décimaux exacts.
        $this->put(route('logistique.cles.update', [$this->cycle, $this->urnes]), [
            'base' => 'electeur', 'ratio' => '0.0125', 'majoration_distribuee' => '12.5', 'reserve_centrale' => '', 'minimum' => '3',
            'arrondi' => 'dizaine', 'cle_affichee' => '1 pour 80', 'minimum_par_commune' => '1',
        ])->assertSessionHas('success');
        $cle = \App\Models\Logistique\CleRepartition::where('materiel_id', $this->urnes->id)->firstOrFail();
        $this->assertSame(['0.0125', '12.50', null, 3, 'dizaine', true], [
            $cle->ratio, $cle->majoration_distribuee, $cle->reserve_centrale, $cle->minimum, $cle->arrondi, $cle->minimum_par_commune,
        ]);
        $this->put(route('logistique.cles.update', [$this->cycle, $this->urnes]), ['base' => 'electeur', 'ratio' => '0', 'arrondi' => 'unite'])
            ->assertSessionHasErrors('ratio');

        // Entités.
        $this->post(route('logistique.entites.store', $this->cycle), ['type' => 'candidat', 'nom' => 'Candidat Z', 'national' => '1'])
            ->assertSessionHas('success');
        $entite = \App\Models\Logistique\EntiteBulletin::where('nom', 'Candidat Z')->firstOrFail();
        $this->put(route('logistique.entites.update', [$this->cycle, $entite]), ['nom' => 'Candidat Y'])->assertSessionHas('success');
        $this->assertSame('Candidat Y', $entite->fresh()->nom);

        // Commissions.
        $this->post(route('logistique.commissions.fixes', $this->cycle))->assertSessionHas('success');
        $commission = CommissionAdministrative::where('cycle_id', $this->cycle->id)->firstOrFail();
        $this->delete(route('logistique.commissions.destroy', [$this->cycle, $commission]))->assertSessionHasErrors('motif');
        $this->delete(route('logistique.commissions.destroy', [$this->cycle, $commission]), ['motif' => 'Doublon'])->assertSessionHas('success');
        $this->post(route('logistique.commissions.store', $this->cycle), ['type' => 'itinerante', 'commune_id' => $this->communes['Ndiass']])
            ->assertSessionHas('success');

        // Correspondances.
        $correspondance = CorrespondanceGeo::create([
            'contexte_region' => 'Thiès', 'contexte_departement' => 'Mbour', 'libelle_commune' => 'Ndiass2', 'statut' => 'proposee',
        ]);
        $this->post(route('logistique.correspondances.valider', $correspondance), ['commune_id' => $this->communes['Ndiass']])->assertSessionHas('success');
        $this->assertSame('validee', $correspondance->fresh()->statut);
        $this->post(route('logistique.correspondances.rejeter', $correspondance), [])->assertSessionHasErrors('motif');
        $this->post(route('logistique.correspondances.rejeter', $correspondance), ['motif' => 'Mauvaise commune'])->assertSessionHas('success');

        // Instantané : capture puis figement, calcul d'un plan, correction.
        $this->post(route('logistique.instantanes.store', $this->cycle))->assertRedirect();
        $capture = InstantaneEffectifs::where('cycle_id', $this->cycle->id)->orderByDesc('version')->firstOrFail();
        $this->assertSame('brouillon', $capture->statut);
        $this->post(route('logistique.instantanes.figer', $capture))->assertSessionHas('success');
        $this->assertSame('fige', $capture->fresh()->statut);

        $this->post(route('logistique.plans.store', $this->cycle), ['instantane_id' => $this->instantane->id])->assertRedirect();
        $plan = PlanDotation::where('cycle_id', $this->cycle->id)->orderByDesc('version')->firstOrFail();
        $this->assertSame(2, $plan->version);
        $ligne = PlanDotationLigne::where('plan_dotation_id', $plan->id)->where('materiel_id', $this->urnes->id)->firstOrFail();
        $this->post(route('logistique.plan-lignes.corriger', $ligne), ['valeur' => 40, 'motif' => 'Ajustement'])->assertSessionHas('success');
        $this->assertSame(40, $ligne->fresh()->correction);

        // Un instantané d'un autre cycle est refusé pour le calcul.
        $autreCycle = Cycle::create(['libelle' => 'Cycle B', 'type' => 'scrutin']);
        $this->post(route('logistique.plans.store', $autreCycle), ['instantane_id' => $this->instantane->id])->assertSessionHasErrors('instantane_id');
    }

    public function test_corrections_filtrees_signalees_et_annulables_depuis_l_ecran(): void
    {
        $enveloppes = Materiel::create(['libelle' => 'Enveloppes', 'categorie' => 'imprime', 'unite_stock' => 'pièce']);
        app(CleService::class)->enregistrer($this->cycle, $enveloppes, ['base' => 'electeur', 'ratio' => '1', 'majoration_distribuee' => '0']);
        $v1 = app(PlanDotationService::class)->calculer($this->cycle, $this->instantane);
        $urnes = PlanDotationLigne::where('plan_dotation_id', $v1->id)->where('materiel_id', $this->urnes->id)->firstOrFail();
        app(PlanDotationService::class)->corriger($urnes, 40, 'Ajustement');

        // Nouvel instantané : 32 bureaux au lieu de 31.
        $instantane = $this->instantaneFige(['electeurs_non_rapproches' => 0]);
        InstantaneLigne::where('instantane_id', $instantane->id)->update(['bureaux_vote' => 32]);
        $v2 = app(PlanDotationService::class)->calculer($this->cycle, $instantane);
        $reprise = PlanDotationLigne::where('plan_dotation_id', $v2->id)->where('materiel_id', $this->urnes->id)->firstOrFail();

        $this->get(route('logistique.plans.show', [$v2, 'corrigees' => 1]))
            ->assertOk()
            ->assertViewHas('lignes', fn ($lignes) => $lignes->total() === 1 && $lignes->first()->id === $reprise->id)
            ->assertSee('calcul modifié depuis la correction (31 → 32)')
            ->assertSee('Annuler la correction');

        $this->actingAs($this->gestionnaire);
        $this->get(route('logistique.plans.show', $v2))->assertOk()->assertDontSee('Annuler la correction');
        $this->post(route('logistique.plan-lignes.annuler-correction', $reprise), ['motif' => 'Retour'])->assertForbidden();

        $this->actingAs($this->responsable);
        $this->post(route('logistique.plan-lignes.annuler-correction', $reprise), [])->assertSessionHasErrors('motif');
        $this->post(route('logistique.plan-lignes.annuler-correction', $reprise), ['motif' => 'Retour au calcul'])
            ->assertSessionHas('success');
        $this->assertNull($reprise->fresh()->correction);
    }

    public function test_toutes_les_ecritures_protegees(): void
    {
        $poste = PosteEtranger::create(['pays' => 'France', 'ville' => 'Paris', 'type' => 'consulat', 'nom' => 'Consulat de Paris']);
        $commission = CommissionAdministrative::create(['cycle_id' => $this->cycle->id, 'commune_id' => $this->communes['Ndiass'], 'type' => 'fixe']);
        $correspondance = CorrespondanceGeo::create([
            'contexte_region' => 'Thiès', 'contexte_departement' => 'Mbour', 'libelle_commune' => 'Ndiass', 'statut' => 'proposee',
        ]);
        $entite = app(CleService::class)->ajouterEntite($this->cycle, ['type' => 'candidat', 'nom' => 'Candidat A']);
        $ligne = $this->ligne();

        $this->actingAs($this->gestionnaire);

        // Écritures de ce plan réservées aux responsables : nom de route => paramètres.
        $ecritures = [
            'logistique.postes.store'          => [],
            'logistique.postes.update'         => [$poste],
            'logistique.destinataires.generer' => [],
            'logistique.destinataires.update'  => [$this->sindia],
            'logistique.decoupage.generer'     => [$this->cycle],
            'logistique.decoupage.affecter'    => [$this->cycle],
            'logistique.decoupage.copier'      => [$this->cycle],
            'logistique.decoupage.retirer'     => [$this->cycle],
            'logistique.correspondances.rapprocher' => [],
            'logistique.correspondances.valider'    => [$correspondance],
            'logistique.correspondances.rejeter'    => [$correspondance],
            'logistique.commissions.store'     => [$this->cycle],
            'logistique.commissions.fixes'     => [$this->cycle],
            'logistique.commissions.destroy'   => [$this->cycle, $commission],
            'logistique.entites.store'         => [$this->cycle],
            'logistique.entites.update'        => [$this->cycle, $entite],
            'logistique.cles.update'           => [$this->cycle, $this->urnes],
            'logistique.cles.copier'           => [$this->cycle],
            'logistique.plan-lignes.corriger'  => [$ligne],
            'logistique.plan-lignes.annuler-correction' => [$ligne],
            'logistique.plans.approuver'       => [$this->plan],
            'logistique.besoin.commander'      => [$this->cycle],
            'logistique.utilisateurs.code.reinitialiser' => [User::factory()->create(['role' => 'chef_poste'])],
        ];

        $this->assertGreaterThan(15, count($ecritures));

        foreach ($ecritures as $nom => $parametres) {
            $route = Route::getRoutes()->getByName($nom);
            $this->assertNotNull($route, "Route absente : {$nom}");
            $this->assertContains('can:logistique-administrer', $route->gatherMiddleware(), "Droit manquant sur {$nom}");

            $methode = strtolower($route->methods()[0]);
            $this->call(strtoupper($methode), route($nom, $parametres))->assertForbidden();
        }

        // Aucune autre écriture « administrer » de ce plan n'échappe à la liste.
        $administrer = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'logistique.')
                && ! in_array('GET', $r->methods(), true)
                && in_array('can:logistique-administrer', $r->gatherMiddleware(), true))
            ->map(fn ($r) => $r->getName())
            ->reject(fn ($nom) => preg_match('/^logistique\.(cycles|mouvements|attributions|commandes|commande-lignes|circuit|decharges|declarations|clotures-ecart|appareils|remises)\./', $nom) === 1)
            ->values()->all();
        $this->assertSame([], array_values(array_diff($administrer, array_keys($ecritures))));
    }
}

<?php

namespace Tests\Feature\Logistique;

use App\Models\Logistique\BesoinRemplacement;
use App\Models\Logistique\CircuitDepartement;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\DechargeDocument;
use App\Models\User;
use App\Services\Logistique\DechargePdfService;
use App\Services\Logistique\DechargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\PlanApprouveFictif;
use Tests\Support\RecensementFictif;
use Tests\TestCase;

class EcransDechargesTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    private User $gestionnaire;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('logistique.disque_decharges'));
        RecensementFictif::creerSchema();
        $this->creerDecorFictif();
        $this->gestionnaire = User::factory()->create(['role' => 'logistique_gestionnaire']);
    }

    private function service(): DechargeService
    {
        return app(DechargeService::class);
    }

    /** Plan approuvé (urnes : 100 pour la préfecture) et stock suffisant ; agit en responsable. */
    private function preparerPlan(int $dotation = 100): void
    {
        $this->actingAs($this->responsable);
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, $dotation]]);
        $this->stockerFictif($this->urnes, 1000);
    }

    private function dechargeValidee(?int $quantite = null): Decharge
    {
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        if ($quantite !== null) {
            $this->service()->modifierLigne($decharge->lignes()->firstOrFail(), $quantite, null);
        }
        $this->service()->valider($decharge);

        return $decharge->fresh();
    }

    public function test_gestionnaire_prepare_valide_et_telecharge(): void
    {
        $this->preparerPlan();
        $this->actingAs($this->gestionnaire);

        $this->post(route('logistique.decharges.store', $this->cycle), [
            'destinataire_id' => $this->prefecture->id, 'categorie' => 'materiel_lourd',
        ])->assertSessionHasNoErrors();
        $decharge = Decharge::firstOrFail();
        $this->assertSame('brouillon', $decharge->statut_document);

        $this->post(route('logistique.decharges.valider', $decharge))
            ->assertRedirect(route('logistique.decharges.show', $decharge))
            ->assertSessionHas('success');
        $this->assertSame('validee', $decharge->fresh()->statut_document);
        $this->assertSame(1, DechargeDocument::where('decharge_id', $decharge->id)->where('exemplaire', 'initial')->count());

        $reponse = $this->get(route('logistique.decharges.pdf', [$decharge, 'initial']));
        $reponse->assertOk();
        $this->assertStringStartsWith('application/pdf', $reponse->headers->get('Content-Type'));
        $document = DechargeDocument::where('decharge_id', $decharge->id)->firstOrFail();
        $this->assertSame(app(DechargePdfService::class)->contenu($document), $reponse->getContent());

        $this->get(route('logistique.decharges.pdf', [$decharge, 'annulation']))->assertNotFound();
        $this->get(route('logistique.decharges.pdf', [$decharge, 'reception']))->assertNotFound();
    }

    public function test_exemplaire_de_reception_depuis_la_fiche(): void
    {
        $this->preparerPlan();
        $chargee = $this->deposerChargee($this->prefecture, [$this->urnes->id => 100]);
        $this->actingAs($this->gestionnaire);

        $this->get(route('logistique.decharges.show', $chargee))
            ->assertOk()->assertDontSee('Exemplaire de réception');
        $this->get(route('logistique.decharges.pdf', [$chargee, 'reception']))->assertNotFound();

        $this->actingAs($this->responsable);
        $this->deposerRecue($chargee, [$this->urnes->id => 100]);
        $this->actingAs($this->gestionnaire);

        $this->get(route('logistique.decharges.show', $chargee))
            ->assertOk()->assertSee('Exemplaire de réception')->assertSee('Non émis')->assertSee('Émettre le PDF');
        $this->post(route('logistique.decharges.emettre', $chargee), ['exemplaire' => 'reception'])
            ->assertSessionHas('success');

        $this->get(route('logistique.decharges.show', $chargee))
            ->assertOk()->assertSee(route('logistique.decharges.pdf', [$chargee, 'reception']), false);
        $reponse = $this->get(route('logistique.decharges.pdf', [$chargee, 'reception']));
        $reponse->assertOk();
        $this->assertStringStartsWith('%PDF', $reponse->getContent());
    }

    public function test_besoin_affiche_a_cote_de_la_dotation_avec_un_remplacement(): void
    {
        $this->preparerPlan();
        $decharge = $this->dechargeValidee(100);
        BesoinRemplacement::create([
            'cycle_id' => $this->cycle->id, 'destinataire_id' => $this->prefecture->id, 'materiel_id' => $this->urnes->id,
            'quantite' => 10, 'motif' => 'Matériel perdu', 'autorise_par' => $this->responsable->id,
        ]);
        $this->actingAs($this->gestionnaire);

        // Dotation 100, besoin 110, engagé 100, reste 10.
        $this->get(route('logistique.expeditions.index', $this->cycle))
            ->assertOk()->assertSeeInOrder(['Dotation', 'Besoin', 'Engagé', 'Reste', 'Préfecture de Thiès', '100', '110', '100', '10']);
        $this->get(route('logistique.ecarts.index', $this->cycle))
            ->assertOk()->assertSeeInOrder(['Dotation', 'Besoin', 'Engagé', 'Préfecture de Thiès', 'Urnes', '100', '110', '100']);
        $this->get(route('logistique.decharges.show', $decharge))
            ->assertOk()->assertSeeInOrder(['Dotation approuvée', 'Besoin', 'Déjà engagé', 'Urnes', '100', '110']);
    }

    public function test_apercu_du_brouillon_en_ligne(): void
    {
        $this->preparerPlan();
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->actingAs($this->gestionnaire);

        $reponse = $this->get(route('logistique.decharges.apercu', $decharge));
        $reponse->assertOk();
        $this->assertStringStartsWith('application/pdf', $reponse->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $reponse->getContent());
        $this->assertStringStartsWith('inline', $reponse->headers->get('Content-Disposition'));
    }

    public function test_validation_conservee_quand_l_emission_echoue(): void
    {
        $this->preparerPlan();
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->partialMock(DechargePdfService::class, function ($mock) {
            $mock->shouldReceive('emettre')->andThrow(new RuntimeException('disque plein'));
        });
        $this->actingAs($this->gestionnaire);

        $this->post(route('logistique.decharges.valider', $decharge))
            ->assertRedirect(route('logistique.decharges.show', $decharge))
            ->assertSessionHas('warning');

        $this->assertSame('validee', $decharge->fresh()->statut_document);
        $this->assertSame(0, DechargeDocument::count());
    }

    public function test_emission_manquante_depuis_la_fiche(): void
    {
        $this->preparerPlan();
        $decharge = $this->dechargeValidee();
        $this->assertSame(0, DechargeDocument::count());
        $this->actingAs($this->gestionnaire);

        $this->get(route('logistique.decharges.show', $decharge))
            ->assertOk()->assertSee('Émettre le PDF');
        $this->post(route('logistique.decharges.emettre', $decharge), ['exemplaire' => 'initial'])
            ->assertSessionHas('success');
        $this->assertSame(1, DechargeDocument::where('exemplaire', 'initial')->count());

        // L'exemplaire de réception est connu, mais pas émissible tant que le destinataire n'a rien reçu.
        $this->post(route('logistique.decharges.emettre', $decharge), ['exemplaire' => 'reception'])
            ->assertSessionHasNoErrors()->assertSessionHas('error', 'Ce document ne peut pas être émis pour cette décharge.');
        $this->post(route('logistique.decharges.emettre', $decharge), ['exemplaire' => 'inconnu'])
            ->assertSessionHasErrors('exemplaire');
        $this->get(route('logistique.decharges.show', $decharge))
            ->assertOk()->assertDontSee('Émettre le PDF');
    }

    public function test_gestionnaire_ne_peut_pas_annuler(): void
    {
        $this->preparerPlan();
        $decharge = $this->dechargeValidee();
        $this->actingAs($this->gestionnaire);

        $this->post(route('logistique.decharges.annuler', $decharge), ['motif' => 'Erreur'])->assertForbidden();

        $this->assertSame('validee', $decharge->fresh()->statut_document);
        $this->get(route('logistique.decharges.show', $decharge))
            ->assertOk()->assertDontSee(route('logistique.decharges.annuler', $decharge), false);
    }

    public function test_responsable_annule_avec_motif(): void
    {
        $this->preparerPlan();
        $decharge = $this->dechargeValidee();

        $this->get(route('logistique.decharges.show', $decharge))
            ->assertSee(route('logistique.decharges.annuler', $decharge), false);
        $this->post(route('logistique.decharges.annuler', $decharge), ['motif' => 'Quantité erronée'])
            ->assertRedirect(route('logistique.decharges.show', $decharge))
            ->assertSessionHas('success');

        $decharge->refresh();
        $this->assertSame('annulee', $decharge->statut_document);
        $this->assertSame('Quantité erronée', $decharge->motif_annulation);
        $this->assertSame(1, DechargeDocument::where('decharge_id', $decharge->id)->where('exemplaire', 'annulation')->count());

        $this->post(route('logistique.decharges.annuler', $this->dechargeValidee()), ['motif' => ''])
            ->assertSessionHasErrors('motif');
    }

    public function test_decharge_annulee_affichee_annulee_et_reliee(): void
    {
        $this->preparerPlan();
        $decharge = $this->dechargeValidee(60);
        $this->service()->annuler($decharge, 'Quantité erronée');

        $this->post(route('logistique.decharges.remplacer', $decharge))->assertSessionHas('success');
        $remplacante = Decharge::where('decharge_remplacee_id', $decharge->id)->firstOrFail();

        $this->get(route('logistique.decharges.show', $decharge))
            ->assertOk()
            ->assertSee('ANNULÉE')
            ->assertSee(route('logistique.decharges.show', $remplacante), false);
        $this->get(route('logistique.decharges.show', $remplacante))
            ->assertOk()
            ->assertSee(route('logistique.decharges.show', $decharge), false);
    }

    public function test_plafond_affiche_l_erreur(): void
    {
        $this->preparerPlan();
        $premier = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        // Le brouillon vise 100 ; 80 sont engagés par ailleurs (décharge validée écrite directement).
        $autre = Decharge::create([
            'cycle_id' => $this->cycle->id, 'destinataire_id' => $this->prefecture->id, 'categorie' => 'materiel_lourd',
            'nature' => 'initiale', 'plan_dotation_id' => $premier->plan_dotation_id, 'prepare_par' => $this->responsable->id,
        ]);
        $autre->forceFill(['statut_document' => 'validee', 'numero' => '1-00099'])->save();
        $autre->lignes()->create([
            'materiel_id' => $this->urnes->id, 'dotation_approuvee' => 100, 'quantite' => 80,
        ]);

        $reponse = $this->post(route('logistique.decharges.valider', $premier));

        $reponse->assertRedirect();
        $this->assertStringContainsString('Plafond du besoin dépassé', (string) session('error'));
        $this->assertSame('brouillon', $premier->fresh()->statut_document);
        $this->assertSame(0, DechargeDocument::count());
    }

    public function test_quantite_negative_refusee(): void
    {
        $this->preparerPlan();
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $ligne = $decharge->lignes()->firstOrFail();

        $this->put(route('logistique.decharge-lignes.update', $ligne), ['quantite' => -1])
            ->assertSessionHasErrors('quantite');
        $this->put(route('logistique.decharge-lignes.update', $ligne), ['quantite' => 10000001])
            ->assertSessionHasErrors('quantite');
        $this->assertSame(100, $ligne->fresh()->quantite);

        $this->put(route('logistique.decharge-lignes.update', $ligne), ['quantite' => 40, 'observations' => 'Livraison partielle'])
            ->assertSessionHasNoErrors();
        $this->assertSame(40, $ligne->fresh()->quantite);
        $this->assertSame('Livraison partielle', $ligne->fresh()->observations);
    }

    public function test_expeditions_affiche_le_reste(): void
    {
        $this->preparerPlan();
        $this->dechargeValidee(60);
        $this->actingAs($this->gestionnaire);

        $this->get(route('logistique.expeditions.index', $this->cycle))
            ->assertOk()
            ->assertSee('Préfecture de Thiès')
            ->assertSeeInOrder(['100', '60', '40']);
        $this->get(route('logistique.expeditions.index', [$this->cycle, 'reste' => 1, 'categorie' => 'materiel_lourd', 'departement' => 10]))
            ->assertOk()->assertSee('Préfecture de Thiès');
        $this->get(route('logistique.expeditions.index', [$this->cycle, 'categorie' => 'imprime']))
            ->assertOk()->assertSee('Aucune ligne');
    }

    public function test_expeditions_sans_requete_par_destinataire(): void
    {
        $this->actingAs($this->responsable);
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 1000);
        $this->dechargeValidee(60);

        $compter = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('logistique.expeditions.index', $this->cycle))->assertOk();

            return count(DB::getQueryLog());
        };
        $pour1 = $compter();

        $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 100], [$this->sousPrefecture1, $this->urnes, 50],
            [$this->sousPrefecture2, $this->urnes, 30], [$this->poste, $this->urnes, 20],
            [$this->sousPrefecture1, $this->isoloirs, 5], [$this->sousPrefecture2, $this->enveloppes, 5],
        ]);
        $pour4 = $compter();

        $this->assertSame($pour1, $pour4);
        $this->get(route('logistique.expeditions.index', $this->cycle))
            ->assertSee('Sous-préfecture de Sindia')->assertSee('Consulat général de Paris');
    }

    public function test_expeditions_prepare_un_lot(): void
    {
        $this->preparerPlan();
        $this->actingAs($this->gestionnaire);

        $this->post(route('logistique.decharges.lot', $this->cycle), [
            'categorie' => 'materiel_lourd', 'filtre' => ['departement_id' => 10],
        ])->assertSessionHas('success');
        $this->assertSame(1, Decharge::where('statut_document', 'brouillon')->count());

        $this->post(route('logistique.decharges.lot', $this->cycle), [
            'categorie' => 'materiel_lourd', 'filtre' => ['departement_id' => 10, 'etranger' => 1],
        ])->assertSessionHas('error');
        $this->post(route('logistique.decharges.lot', $this->cycle), ['categorie' => 'inconnue'])
            ->assertSessionHasErrors('categorie');
    }

    public function test_liste_des_decharges_filtrable(): void
    {
        $this->preparerPlan();
        $this->dechargeValidee(60);
        $this->actingAs($this->gestionnaire);

        $this->get(route('logistique.decharges.index', $this->cycle))
            ->assertOk()->assertSee('Préfecture de Thiès');
        $this->get(route('logistique.decharges.index', [
            $this->cycle, 'statut' => 'validee', 'categorie' => 'materiel_lourd', 'nature' => 'initiale',
            'departement' => 10, 'destinataire' => $this->prefecture->id,
        ]))->assertOk()->assertSee('Préfecture de Thiès');
        $this->get(route('logistique.decharges.index', [$this->cycle, 'statut' => 'annulee']))
            ->assertOk()->assertSee('Aucune décharge');
    }

    public function test_telechargement_en_lot(): void
    {
        $this->preparerPlan();
        $this->dechargeValidee(60);
        $this->actingAs($this->gestionnaire);

        $reponse = $this->get(route('logistique.decharges.lot-pdf', [$this->cycle, 'filtre' => ['departement_id' => 10]]));
        $reponse->assertOk()->assertDownload();

        $this->get(route('logistique.decharges.lot-pdf', [$this->cycle, 'filtre' => ['departement_id' => 99]]))
            ->assertRedirect()->assertSessionHas('error');
    }

    public function test_ecarts_proposent_le_reliquat(): void
    {
        $this->preparerPlan();
        $this->dechargeValidee(60);
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 150]]);

        $this->get(route('logistique.ecarts.index', $this->cycle))
            ->assertOk()
            ->assertSee('Préparer le reliquat')
            ->assertSee(route('logistique.decharges.store', $this->cycle), false);
    }

    public function test_ecarts_proposent_l_annulation_au_responsable_seul(): void
    {
        $this->preparerPlan();
        $decharge = $this->dechargeValidee(100);
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 80]]);

        $this->get(route('logistique.ecarts.index', $this->cycle))
            ->assertOk()->assertSee('Annuler et remplacer')
            ->assertSee(route('logistique.decharges.show', $decharge), false);

        $this->actingAs($this->gestionnaire);
        $this->get(route('logistique.ecarts.index', $this->cycle))
            ->assertOk()->assertDontSee('Annuler et remplacer');
    }

    public function test_ecarts_signalent_le_materiel_parti(): void
    {
        $this->preparerPlan();
        $decharge = $this->dechargeValidee(100);
        $decharge->forceFill(['statut_acheminement' => 'chargee'])->save();
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 80]]);

        $this->get(route('logistique.ecarts.index', $this->cycle))
            ->assertOk()->assertSee('Signalé : matériel déjà parti')->assertDontSee('Annuler et remplacer');
    }

    public function test_circuit_modifiable_par_le_responsable_seul(): void
    {
        $this->actingAs($this->gestionnaire);
        $this->get(route('logistique.circuit.show', $this->cycle))
            ->assertOk()->assertSee('Préfecture de Thiès')->assertDontSee(route('logistique.circuit.update', [$this->cycle, 10]), false);
        $this->put(route('logistique.circuit.update', [$this->cycle, 10]), ['circuit' => 'direct', 'motif' => 'Accès difficile'])
            ->assertForbidden();
        $this->assertSame(0, CircuitDepartement::count());

        $this->actingAs($this->responsable);
        $this->get(route('logistique.circuit.show', $this->cycle))
            ->assertOk()->assertSee(route('logistique.circuit.update', [$this->cycle, 10]), false);
        $this->put(route('logistique.circuit.update', [$this->cycle, 10]), ['circuit' => 'direct', 'motif' => 'Accès difficile'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('direct', CircuitDepartement::where('cycle_id', $this->cycle->id)->where('departement_id', 10)->value('circuit'));

        $this->put(route('logistique.circuit.update', [$this->cycle, 10]), ['circuit' => 'inconnu', 'motif' => 'x'])
            ->assertSessionHasErrors('circuit');
        $this->put(route('logistique.circuit.update', [$this->cycle, 10]), ['circuit' => 'direct'])
            ->assertSessionHasErrors('motif');

        $this->put(route('logistique.circuit.update', [$this->cycle, 10]), ['circuit' => '', 'motif' => 'Retour au défaut'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, CircuitDepartement::count());
    }

    public function test_menu_decharges_visible(): void
    {
        $this->actingAs($this->gestionnaire);

        $this->get(route('logistique.cycles.index'))
            ->assertOk()
            ->assertSee(route('logistique.circuit.show', $this->cycle), false)
            ->assertSee(route('logistique.expeditions.index', $this->cycle), false)
            ->assertSee(route('logistique.decharges.index', $this->cycle), false)
            ->assertSee(route('logistique.ecarts.index', $this->cycle), false)
            ->assertSee('Expéditions')
            ->assertSee('Décharges')
            ->assertSee('Écarts');
    }

    public function test_lectures_refusees_hors_logistique(): void
    {
        $this->preparerPlan();
        $decharge = $this->dechargeValidee();
        $this->actingAs(User::factory()->create(['role' => 'prefet']));

        foreach ([
            route('logistique.circuit.show', $this->cycle),
            route('logistique.expeditions.index', $this->cycle),
            route('logistique.decharges.index', $this->cycle),
            route('logistique.decharges.show', $decharge),
            route('logistique.decharges.apercu', $decharge),
            route('logistique.decharges.pdf', [$decharge, 'initial']),
            route('logistique.decharges.lot-pdf', $this->cycle),
            route('logistique.ecarts.index', $this->cycle),
        ] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_toutes_les_ecritures_protegees(): void
    {
        $this->preparerPlan();
        $brouillon = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $ligne = $brouillon->lignes()->firstOrFail();

        // [méthode, URL, corps, droit exigé]
        $ecritures = [
            ['PUT', route('logistique.circuit.update', [$this->cycle, 10]), ['circuit' => 'direct', 'motif' => 'x'], 'administrer'],
            ['POST', route('logistique.decharges.annuler', $brouillon), ['motif' => 'x'], 'administrer'],
            ['POST', route('logistique.decharges.store', $this->cycle), ['destinataire_id' => $this->prefecture->id, 'categorie' => 'materiel_lourd'], 'gerer'],
            ['POST', route('logistique.decharges.lot', $this->cycle), ['categorie' => 'materiel_lourd'], 'gerer'],
            ['PUT', route('logistique.decharge-lignes.update', $ligne), ['quantite' => 1], 'gerer'],
            ['POST', route('logistique.decharges.actualiser', $brouillon), [], 'gerer'],
            ['DELETE', route('logistique.decharges.destroy', $brouillon), ['motif' => 'x'], 'gerer'],
            ['POST', route('logistique.decharges.valider', $brouillon), [], 'gerer'],
            ['POST', route('logistique.decharges.remplacer', $brouillon), [], 'gerer'],
            ['POST', route('logistique.decharges.emettre', $brouillon), ['exemplaire' => 'initial'], 'gerer'],
        ];

        $sansDroit = [
            'administrer' => User::factory()->create(['role' => 'logistique_gestionnaire']),
            'gerer' => User::factory()->create(['role' => 'prefet']),
        ];
        foreach ($ecritures as [$methode, $url, $corps, $droit]) {
            $this->actingAs($sansDroit[$droit])->call($methode, $url, $corps)->assertForbidden();
        }

        $this->assertSame('brouillon', $brouillon->fresh()->statut_document);
        $this->assertSame(100, $ligne->fresh()->quantite);
        $this->assertSame(0, CircuitDepartement::count());

        // Aucune écriture de circuit ou de décharge n'échappe à la liste, avec le droit annoncé.
        $declarees = collect($ecritures)->map(fn (array $e) => app('router')->getRoutes()->match(Request::create($e[1], $e[0]))->getName());
        $reelles = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => preg_match('/^logistique\.(circuit|decharges|decharge-lignes)\./', (string) $r->getName()) === 1
                && ! in_array('GET', $r->methods(), true));
        $this->assertSame([], $reelles->map->getName()->diff($declarees)->values()->all());
        foreach ($reelles as $route) {
            $droit = in_array('can:logistique-administrer', $route->gatherMiddleware(), true) ? 'administrer' : 'gerer';
            $this->assertSame(collect($ecritures)->first(fn ($e) => app('router')->getRoutes()->match(Request::create($e[1], $e[0]))->getName() === $route->getName())[3], $droit, $route->getName());
        }
    }

    public function test_pdf_altere_ramene_a_la_fiche_avec_l_erreur(): void
    {
        $this->preparerPlan();
        $decharge = $this->dechargeValidee();
        $document = app(DechargePdfService::class)->emettre($decharge, 'initial');
        Storage::disk(config('logistique.disque_decharges'))->put($document->chemin, '%PDF-falsifie');
        $this->actingAs($this->gestionnaire);

        $this->get(route('logistique.decharges.pdf', [$decharge, 'initial']))
            ->assertRedirect(route('logistique.decharges.show', $decharge))
            ->assertSessionHas('error', 'Fichier archivé altéré ou absent : réimpression refusée.');
    }

    public function test_apercu_en_echec_ramene_a_la_fiche_sans_erreur_500(): void
    {
        $this->preparerPlan();
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->partialMock(DechargePdfService::class, function ($mock) {
            $mock->shouldReceive('pdf')->andThrow(new RuntimeException('moteur PDF indisponible'));
        });
        $this->actingAs($this->gestionnaire);

        $this->get(route('logistique.decharges.apercu', $decharge))
            ->assertRedirect(route('logistique.decharges.show', $decharge))
            ->assertSessionHas('error', 'Aperçu impossible : moteur PDF indisponible');
    }

    /** Nombre de cellules d'en-tête et de la première ligne du tableau des lignes de la fiche. */
    private function colonnesFiche(string $html): array
    {
        preg_match('#<table.*?</table>#s', $html, $table);
        preg_match('#<thead>(.*?)</thead>#s', $table[0], $tete);
        preg_match('#<tbody[^>]*>(.*?)</tbody>#s', $table[0], $corps);
        preg_match('#<tr[^>]*>(.*?)</tr>#s', $corps[1], $ligne);

        return [preg_match_all('#<th\b#', $tete[1]), preg_match_all('#<td\b#', $ligne[1]), $ligne[1]];
    }

    public function test_fiche_d_un_brouillon_sur_cycle_clos_garde_des_colonnes_alignees(): void
    {
        $this->preparerPlan();
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->cycle->forceFill(['statut' => 'clos'])->save();
        $this->actingAs($this->gestionnaire);

        $html = $this->get(route('logistique.decharges.show', $decharge))->assertOk()->getContent();
        [$entetes, $cellules] = $this->colonnesFiche($html);
        $this->assertSame($entetes, $cellules);
        $this->assertSame(6, $entetes);

        $decharge->lignes()->delete();
        $html = $this->get(route('logistique.decharges.show', $decharge))->assertOk()->getContent();
        [, , $vide] = $this->colonnesFiche($html);
        $this->assertStringContainsString('colspan="6"', $vide);
    }

    public function test_fiche_d_un_brouillon_sur_cycle_ouvert_garde_des_colonnes_alignees(): void
    {
        $this->preparerPlan();
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->actingAs($this->gestionnaire);

        [$entetes, $cellules] = $this->colonnesFiche($this->get(route('logistique.decharges.show', $decharge))->assertOk()->getContent());

        $this->assertSame(7, $entetes);
        $this->assertSame(7, $cellules);
    }
}

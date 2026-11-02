<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Arbitrage;
use App\Models\Logistique\BesoinRemplacement;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\DechargeDocument;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Reclamation;
use App\Models\Logistique\Remise;
use App\Models\User;
use App\Services\Logistique\DechargePdfService;
use App\Services\Logistique\DechargeService;
use App\Services\Logistique\DeclarationService;
use App\Services\Logistique\IndicateurService;
use App\Services\Logistique\ReclamationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\PlanApprouveFictif;
use Tests\Support\RecensementFictif;
use Tests\TestCase;

class EcransRemisesTest extends TestCase
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
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->sousPrefecture1, $this->urnes, 50]]);
        $this->stockerFictif($this->urnes, 1000, 500);
    }

    private function enTantQue(User $utilisateur, callable $action): mixed
    {
        $this->actingAs($utilisateur);
        try {
            return $action();
        } finally {
            $this->actingAs($this->responsable);
        }
    }

    /** Décharge validée (non chargée, sans agent) de $quantite urnes. */
    private function dechargeValidee(int $quantite, $destinataire = null): Decharge
    {
        $service = app(DechargeService::class);
        $decharge = $service->preparer($this->cycle, $destinataire ?? $this->prefecture, 'materiel_lourd');
        foreach ($decharge->lignes as $ligne) {
            $service->modifierLigne($ligne, $ligne->materiel_id === $this->urnes->id ? $quantite : 0, null);
        }
        $service->valider($decharge);

        return $decharge->refresh();
    }

    /** Déclaration « tablette » de l'agent de la décharge sur sa remise de livraison. */
    private function declarerTablette(Decharge $decharge, int $recu): void
    {
        $remise = Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->with('lignes')->firstOrFail();
        $this->enTantQue($decharge->agent, fn () => app(DeclarationService::class)->declarer(
            $remise, 'tablette', ['nom' => 'Agent', 'qualite' => 'Transport'],
            [$this->urnes->id => ['recu' => $recu, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(),
        ));
    }

    /** Livraison à vérifier : l'autorité déclare 100 reçues, l'agent 60. */
    private function aVerifier(): Decharge
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => 100]);
        $this->declarerTablette($decharge, 60);

        return $decharge->refresh();
    }

    private function tablette(Decharge $decharge): Declaration
    {
        return Declaration::where('decharge_id', $decharge->id)->where('mode', 'tablette')->sole();
    }

    private function reclamationOuverte(Decharge $decharge, int $quantite = 10): Reclamation
    {
        return $this->enTantQue($this->autoriteFictive($this->prefecture), fn () => app(ReclamationService::class)->ouvrir(
            $decharge, $this->urnes, 'manquant', $quantite, 'Dix urnes manquent à la livraison.',
        ));
    }

    public function test_affecter_puis_charger(): void
    {
        $decharge = $this->dechargeValidee(100);
        $agent = User::factory()->create(['role' => 'agent_transport']);
        $this->actingAs($this->gestionnaire);

        $this->get(route('logistique.decharges.show', $decharge))
            ->assertOk()->assertSee(route('logistique.decharges.agent', $decharge), false)
            ->assertDontSee('Chargée — en transit');

        // Sans agent, le chargement est refusé avec le message du service.
        $this->post(route('logistique.decharges.charger', $decharge))->assertSessionHas('error');
        $this->assertSame('a_preparer', $decharge->fresh()->statut_acheminement);

        $this->put(route('logistique.decharges.agent', $decharge), ['agent_id' => $agent->id, 'motif' => 'Tournée de Thiès'])
            ->assertSessionHas('success');
        $this->assertSame($agent->id, (int) $decharge->fresh()->agent_id);
        $this->put(route('logistique.decharges.agent', $decharge), ['agent_id' => $agent->id])->assertSessionHasErrors('motif');
        $this->put(route('logistique.decharges.agent', $decharge), ['agent_id' => 999999, 'motif' => 'x'])->assertSessionHasErrors('agent_id');

        $this->post(route('logistique.decharges.charger', $decharge))
            ->assertRedirect(route('logistique.decharges.show', $decharge))
            ->assertSessionHas('success');

        $this->assertSame('chargee', $decharge->fresh()->statut_acheminement);
        $this->assertSame(2, Remise::where('decharge_id', $decharge->id)->count());
        $this->get(route('logistique.decharges.show', $decharge))
            ->assertOk()->assertSee('Chargée — en transit')->assertDontSee('livrée')
            ->assertSee('Chargement')->assertSee('Livraison')->assertSee('En attente')
            ->assertDontSee(route('logistique.decharges.charger', $decharge), false)
            ->assertDontSee(route('logistique.decharges.agent', $decharge), false);
    }

    public function test_retirer_l_agent_exige_un_motif(): void
    {
        $decharge = $this->dechargeValidee(100);
        $agent = User::factory()->create(['role' => 'agent_transport']);
        app(DechargeService::class)->affecterAgent($decharge, $agent, 'Tournée');
        $this->actingAs($this->gestionnaire);

        $this->put(route('logistique.decharges.agent', $decharge), ['agent_id' => '', 'motif' => 'Agent indisponible'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertNull($decharge->fresh()->agent_id);
    }

    public function test_chargement_a_l_etranger_avec_transporteur(): void
    {
        $this->approuverPlanFictif([[$this->poste, $this->urnes, 40]]);
        $decharge = $this->dechargeValidee(40, $this->poste);
        $this->assertSame('etranger', $decharge->circuit);
        $this->actingAs($this->gestionnaire);

        $this->get(route('logistique.decharges.show', $decharge))->assertOk()->assertSee('transporteur_nom', false);
        $this->post(route('logistique.decharges.charger', $decharge), ['transporteur_nom' => 'Moussa Ndiaye'])
            ->assertSessionHas('error');
        $this->post(route('logistique.decharges.charger', $decharge), ['transporteur_nom' => str_repeat('a', 151)])
            ->assertSessionHasErrors('transporteur_nom');

        $this->post(route('logistique.decharges.charger', $decharge), [
            'transporteur_nom' => 'Moussa Ndiaye', 'transporteur_qualite' => 'Chauffeur',
            'transporteur_societe' => 'Fret Express', 'transporteur_bordereau' => 'BL-2026-001',
        ])->assertSessionHas('success');

        $chargement = Remise::where('decharge_id', $decharge->id)->where('etape', 'chargement')->sole();
        $this->assertSame('Moussa Ndiaye', $chargement->transporteur_nom);
        $this->assertSame('BL-2026-001', $chargement->bordereau_numero);
        $this->get(route('logistique.decharges.show', $decharge))
            ->assertOk()->assertSee('Chargée — en transit')->assertSee('Fret Express')->assertSee('BL-2026-001');
    }

    public function test_ecran_a_verifier_montre_les_deux_declarations(): void
    {
        $decharge = $this->aVerifier();
        $this->actingAs($this->gestionnaire);

        $this->get(route('logistique.declarations.a-verifier', $this->cycle))
            ->assertOk()
            ->assertSeeInOrder([$decharge->numero, 'Préfecture de Thiès', 'Annoncé', 'Déclaration : Compte', 'Signataire de test', 'Déclaration : Tablette', 'Agent', 'Urnes', '60'])
            ->assertSee('Réservé aux responsables logistiques')
            ->assertDontSee(route('logistique.declarations.retenir', $this->tablette($decharge)), false);

        $this->actingAs($this->responsable);
        $this->get(route('logistique.declarations.a-verifier', $this->cycle))
            ->assertOk()
            ->assertSee(route('logistique.declarations.retenir', $this->tablette($decharge)), false)
            ->assertSee(route('logistique.declarations.rejeter', $this->tablette($decharge)), false);
    }

    public function test_ecran_a_verifier_vide(): void
    {
        $this->actingAs($this->gestionnaire);

        $this->get(route('logistique.declarations.a-verifier', $this->cycle))
            ->assertOk()->assertSee('Aucune réception à vérifier');
    }

    public function test_responsable_retient(): void
    {
        $decharge = $this->aVerifier();
        $tablette = $this->tablette($decharge);
        $this->actingAs($this->responsable);

        $this->post(route('logistique.declarations.retenir', $tablette), [])->assertSessionHasErrors('motif');
        $this->post(route('logistique.declarations.retenir', $tablette), ['motif' => 'Constat de l\'agent.'])
            ->assertSessionHas('success');

        $this->assertSame('retenue', $tablette->fresh()->statut);
        $decharge->refresh();
        $this->assertSame('recue_destinataire', $decharge->statut_acheminement);
        $this->assertSame(1, Arbitrage::where('objet', 'declaration')->where('declaration_id', $tablette->id)->where('decision', 'retenue')->count());
        // La retenue amène la réception par le destinataire : l'exemplaire de réception est émis.
        $this->assertSame(1, DechargeDocument::where('decharge_id', $decharge->id)->where('exemplaire', 'reception')->count());
        $this->get(route('logistique.declarations.a-verifier', $this->cycle))->assertOk()->assertSee('Aucune réception à vérifier');
    }

    public function test_retenue_conservee_quand_l_emission_echoue(): void
    {
        $decharge = $this->aVerifier();
        $tablette = $this->tablette($decharge);
        $this->partialMock(DechargePdfService::class, function ($mock) {
            $mock->shouldReceive('emettre')->andThrow(new RuntimeException('disque plein'));
        });
        $this->actingAs($this->responsable);

        $this->post(route('logistique.declarations.retenir', $tablette), ['motif' => 'Constat de l\'agent.'])
            ->assertSessionHas('success')->assertSessionHas('warning');

        $this->assertSame('retenue', $tablette->fresh()->statut);
        $this->assertSame('recue_destinataire', $decharge->fresh()->statut_acheminement);
        $this->assertSame(0, DechargeDocument::count());
    }

    public function test_regle_refusee_a_l_emission_n_est_pas_signalee_comme_erreur(): void
    {
        $decharge = $this->aVerifier();
        $tablette = $this->tablette($decharge);
        $this->partialMock(DechargePdfService::class, function ($mock) {
            $mock->shouldReceive('emettre')->andThrow(new RegleLogistique('Modèle de décharge indisponible.'));
        });
        Exceptions::fake();
        $this->actingAs($this->responsable);

        $this->post(route('logistique.declarations.retenir', $tablette), ['motif' => 'Constat de l\'agent.'])
            ->assertSessionHas('success')->assertSessionHas('warning');

        Exceptions::assertNothingReported();
    }

    public function test_responsable_rejette(): void
    {
        $decharge = $this->aVerifier();
        $tablette = $this->tablette($decharge);
        $this->actingAs($this->responsable);

        $this->post(route('logistique.declarations.rejeter', $tablette), ['motif' => 'Tablette mal renseignée.'])
            ->assertSessionHas('success');

        $this->assertSame('ecartee', $tablette->fresh()->statut);
        $this->assertSame(1, Arbitrage::where('declaration_id', $tablette->id)->where('decision', 'ecartee')->count());
    }

    public function test_arbitrage_refuse_affiche_l_erreur(): void
    {
        $decharge = $this->aVerifier();
        $tablette = $this->tablette($decharge);
        $this->actingAs($this->responsable);
        $this->post(route('logistique.declarations.retenir', $tablette), ['motif' => 'Constat.'])->assertSessionHas('success');

        // Plus rien à arbitrer : règle du service, pas d'erreur 500.
        $this->post(route('logistique.declarations.retenir', $tablette), ['motif' => 'Encore.'])
            ->assertRedirect()->assertSessionHas('error', 'Rien à arbitrer pour cette déclaration.');
    }

    public function test_gestionnaire_ne_peut_pas_retenir(): void
    {
        $decharge = $this->aVerifier();
        $tablette = $this->tablette($decharge);
        $this->actingAs($this->gestionnaire);

        $this->post(route('logistique.declarations.retenir', $tablette), ['motif' => 'Constat.'])->assertForbidden();
        $this->post(route('logistique.declarations.rejeter', $tablette), ['motif' => 'Constat.'])->assertForbidden();

        $this->assertSame('a_verifier', $tablette->fresh()->statut);
        $this->assertSame(0, Arbitrage::count());
    }

    public function test_reclamation_resolue_par_le_gestionnaire(): void
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 100]);
        $reclamation = $this->reclamationOuverte($decharge);
        $this->actingAs($this->gestionnaire);

        $this->get(route('logistique.reclamations.index', $this->cycle))
            ->assertOk()->assertSeeInOrder([$decharge->numero, 'Urnes', 'Manquant', '10', 'Ouverte'])
            ->assertSee(route('logistique.reclamations.show', $reclamation), false);
        $this->get(route('logistique.reclamations.index', [$this->cycle, 'statut' => 'resolue']))
            ->assertOk()->assertSee('Aucune réclamation');
        $this->get(route('logistique.reclamations.index', [$this->cycle, 'statut' => 'ouverte', 'motif' => 'defectueux']))
            ->assertOk()->assertSee('Aucune réclamation');
        $this->get(route('logistique.reclamations.index', [$this->cycle, 'statut' => 'ouverte', 'motif' => 'manquant']))
            ->assertOk()->assertSee($decharge->numero);

        $this->get(route('logistique.reclamations.show', $reclamation))
            ->assertOk()->assertSee('Dix urnes manquent à la livraison.')
            ->assertSee(route('logistique.reclamations.prendre', $reclamation), false);
        $this->post(route('logistique.reclamations.prendre', $reclamation))->assertSessionHas('success');
        $this->assertSame('en_traitement', $reclamation->fresh()->statut);

        $this->post(route('logistique.reclamations.resoudre', $reclamation), ['issue' => 'article_retrouve'])
            ->assertSessionHasErrors('motif');
        $this->post(route('logistique.reclamations.resoudre', $reclamation), ['issue' => 'inconnue', 'motif' => 'x'])
            ->assertSessionHasErrors('issue');
        $this->post(route('logistique.reclamations.resoudre', $reclamation), ['issue' => 'article_retrouve', 'motif' => 'Retrouvées au dépôt.'])
            ->assertSessionHas('success');

        $reclamation->refresh();
        $this->assertSame('resolue', $reclamation->statut);
        $this->assertSame('article_retrouve', $reclamation->issue);
        $this->get(route('logistique.reclamations.show', $reclamation))
            ->assertOk()->assertSee('Article retrouvé')->assertSee('Retrouvées au dépôt.')
            ->assertDontSee(route('logistique.reclamations.resoudre', $reclamation), false);
    }

    public function test_le_gestionnaire_ne_voit_pas_les_issues_du_responsable(): void
    {
        $reclamation = $this->reclamationOuverte($this->deposerChargee($this->prefecture, [$this->urnes->id => 100]));

        $this->actingAs($this->gestionnaire);
        $this->get(route('logistique.reclamations.show', $reclamation))
            ->assertOk()->assertSee('Article retrouvé')->assertDontSee('Remplacement autorisé')->assertDontSee('Clôture sans remplacement');
        $this->post(route('logistique.reclamations.resoudre', $reclamation), ['issue' => 'remplacement_autorise', 'motif' => 'Perdu.', 'quantite' => 10])
            ->assertRedirect()->assertSessionHas('error', 'Seul un responsable logistique peut autoriser un remplacement.');
        $this->assertSame('ouverte', $reclamation->fresh()->statut);

        $this->actingAs($this->responsable);
        $this->get(route('logistique.reclamations.show', $reclamation))
            ->assertOk()->assertSee('Remplacement autorisé')->assertSee('Clôture sans remplacement');
    }

    public function test_remplacement_autorise_par_le_responsable(): void
    {
        $reclamation = $this->reclamationOuverte($this->deposerChargee($this->prefecture, [$this->urnes->id => 100]));
        $this->actingAs($this->responsable);

        $this->post(route('logistique.reclamations.resoudre', $reclamation), ['issue' => 'remplacement_autorise', 'motif' => 'Perdu en route.', 'quantite' => 10])
            ->assertSessionHas('success');

        $this->assertSame(10, (int) BesoinRemplacement::sole()->quantite);
        $remplacement = Decharge::where('nature', 'remplacement')->sole();
        $this->get(route('logistique.reclamations.show', $reclamation))
            ->assertOk()->assertSee(route('logistique.decharges.show', $remplacement), false);
    }

    public function test_erreur_de_declaration_designe_la_declaration(): void
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 100]);
        $declaration = $this->deposerRecue($decharge, [$this->urnes->id => 90]);
        $reclamation = $this->reclamationOuverte($decharge->refresh());
        $this->actingAs($this->gestionnaire);

        $this->get(route('logistique.reclamations.show', $reclamation))->assertOk()->assertSee('declaration_id', false);
        $this->post(route('logistique.reclamations.resoudre', $reclamation), ['issue' => 'erreur_declaration', 'motif' => 'Saisie erronée.'])
            ->assertSessionHas('error');
        $this->post(route('logistique.reclamations.resoudre', $reclamation), [
            'issue' => 'erreur_declaration', 'motif' => 'Saisie erronée.', 'declaration_id' => $declaration->id,
        ])->assertSessionHas('success');

        $this->assertSame('resolue', $reclamation->fresh()->statut);
    }

    public function test_cloture_ecart_par_le_responsable(): void
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 80]);
        $this->deposerRecue($decharge, [$this->urnes->id => 80]);
        $corps = [
            'destinataire_id' => $this->prefecture->id, 'materiel_id' => $this->urnes->id,
            'abandon' => 20, 'expedition_annulee' => 0, 'motif' => 'Écart accepté après constat.',
        ];

        $this->actingAs($this->gestionnaire);
        $this->post(route('logistique.clotures-ecart.store', $this->cycle), $corps)->assertForbidden();
        $this->assertSame(0, Arbitrage::count());

        $this->actingAs($this->responsable);
        $this->post(route('logistique.clotures-ecart.store', $this->cycle), ['motif' => ''] + $corps)->assertSessionHasErrors('motif');
        $this->post(route('logistique.clotures-ecart.store', $this->cycle), ['abandon' => 0] + $corps)
            ->assertSessionHas('error', 'Les quantités doivent être positives et au moins l\'une d\'elles non nulle.');
        $this->post(route('logistique.clotures-ecart.store', $this->cycle), ['abandon' => 21] + $corps)
            ->assertSessionHas('error');
        $this->post(route('logistique.clotures-ecart.store', $this->cycle), $corps)->assertSessionHas('success');

        $arbitrage = Arbitrage::where('objet', 'cloture_ecart')->sole();
        $this->assertSame(20, $arbitrage->quantite_abandon_reception);
        $this->assertSame('cloture_avec_ecart', app(IndicateurService::class)
            ->parDestinataire($this->cycle, [$this->prefecture->id])->firstWhere('materiel.id', $this->urnes->id)['etat']);
    }

    /** Cellules de la ligne (destinataire, article) du tableau de suivi, en texte. */
    private function ligneDeSuivi(string $html, int $destinataireId, int $materielId): array
    {
        $this->assertSame(1, preg_match('#<tr[^>]*data-ligne="'.$destinataireId.'-'.$materielId.'"[^>]*>(.*?)</tr>#s', $html, $ligne), 'Ligne de suivi absente.');
        preg_match_all('#<td[^>]*>(.*?)</td>#s', $ligne[1], $cellules);

        return array_map(fn (string $c) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($c)))), $cellules[1]);
    }

    public function test_suivi_affiche_les_indicateurs(): void
    {
        // 70 expédiées, 64 reçues dont 4 défectueuses : en transit 6, reçu utilisable 60, reste à réceptionner 40.
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 70]);
        $this->deposerRecue($decharge, [$this->urnes->id => ['recu' => 64, 'defectueux' => 4]]);
        $this->actingAs($this->gestionnaire);

        $reponse = $this->get(route('logistique.suivi.index', $this->cycle))->assertOk();
        $reponse->assertSee('Par article')->assertSee('Par destinataire')
            ->assertSeeInOrder(['Besoin', 'Expédié', 'En transit', 'Reçu utilisable', 'Reste à réceptionner']);

        $cellules = $this->ligneDeSuivi($reponse->getContent(), $this->prefecture->id, $this->urnes->id);
        $this->assertSame(['Préfecture de Thiès', 'Urnes', '100', '100', '70', '70', '30', '6', '0', '64', '60', '40', '0', 'En cours'], array_slice($cellules, 0, 14));
    }

    public function test_suivi_filtre_par_departement_et_par_etat(): void
    {
        $this->actingAs($this->gestionnaire);

        // La sous-préfecture suit le département de sa préfecture.
        $this->get(route('logistique.suivi.index', [$this->cycle, 'departement' => 10]))
            ->assertOk()->assertSee('Préfecture de Thiès')->assertSee('Sous-préfecture de Sindia');
        $this->get(route('logistique.suivi.index', [$this->cycle, 'departement' => 99]))
            ->assertOk()->assertDontSee('Sous-préfecture de Sindia')->assertSee('Aucun suivi');
        $this->get(route('logistique.suivi.index', [$this->cycle, 'etat' => 'solde_complet']))
            ->assertOk()->assertDontSee('Sous-préfecture de Sindia');
        $this->get(route('logistique.suivi.index', [$this->cycle, 'etat' => 'en_cours']))
            ->assertOk()->assertSee('Sous-préfecture de Sindia');
        // Valeurs de filtre inconnues : ignorées, jamais d'erreur.
        $this->get(route('logistique.suivi.index', [$this->cycle, 'etat' => 'inconnu', 'departement' => 'abc']))
            ->assertOk()->assertSee('Sous-préfecture de Sindia');
    }

    public function test_suivi_propose_la_cloture_avec_ecart_au_responsable_seul(): void
    {
        $this->actingAs($this->gestionnaire);
        $this->get(route('logistique.suivi.index', $this->cycle))
            ->assertOk()->assertDontSee(route('logistique.clotures-ecart.store', $this->cycle), false);

        $this->actingAs($this->responsable);
        $this->get(route('logistique.suivi.index', $this->cycle))
            ->assertOk()->assertSee(route('logistique.clotures-ecart.store', $this->cycle), false)->assertSee('Clôturer avec écart');
    }

    public function test_suivi_limite_l_expedition_annulee_au_non_engage(): void
    {
        // 100 de besoin, 30 engagés par une décharge validée : au plus 70 d'expédition annulée.
        $this->dechargeValidee(30);
        $this->actingAs($this->responsable);

        $this->get(route('logistique.suivi.index', $this->cycle))
            ->assertOk()
            ->assertSee('Expédition annulée (non engagé, au plus 70)')
            ->assertSee('name="expedition_annulee" min="0" max="70"', false);
    }

    public function test_fiche_de_decharge_montre_le_libelle_du_mode(): void
    {
        $decharge = $this->aVerifier();
        $this->actingAs($this->gestionnaire);

        $this->get(route('logistique.decharges.show', $decharge))
            ->assertOk()->assertSee('mode : Compte')->assertSee('mode : Tablette')
            ->assertDontSee('mode compte')->assertDontSee('mode tablette');
    }

    public function test_menu_visible(): void
    {
        $this->actingAs($this->gestionnaire);

        $this->get(route('logistique.cycles.index'))
            ->assertOk()
            ->assertSee(route('logistique.declarations.a-verifier', $this->cycle), false)
            ->assertSee(route('logistique.reclamations.index', $this->cycle), false)
            ->assertSee(route('logistique.suivi.index', $this->cycle), false)
            ->assertSee('À vérifier')
            ->assertSee('Réclamations')
            ->assertSee('Suivi');
        $this->get(route('logistique.suivi.index', $this->cycle))
            ->assertOk()->assertSee(route('logistique.reclamations.index', $this->cycle), false);
    }

    public function test_lectures_refusees_hors_logistique(): void
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 100]);
        $reclamation = $this->reclamationOuverte($decharge);
        $this->actingAs($this->autoriteFictive($this->prefecture));

        foreach ([
            route('logistique.declarations.a-verifier', $this->cycle),
            route('logistique.reclamations.index', $this->cycle),
            route('logistique.reclamations.show', $reclamation),
            route('logistique.suivi.index', $this->cycle),
        ] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_toutes_les_ecritures_protegees(): void
    {
        $decharge = $this->aVerifier();
        $tablette = $this->tablette($decharge);
        $reclamation = $this->reclamationOuverte($decharge);
        $validee = $this->dechargeValidee(50, $this->sousPrefecture1);

        // [méthode, URL, corps, droit exigé]
        $ecritures = [
            ['PUT', route('logistique.decharges.agent', $validee), ['agent_id' => null, 'motif' => 'x'], 'gerer'],
            ['POST', route('logistique.decharges.charger', $validee), [], 'gerer'],
            ['POST', route('logistique.declarations.retenir', $tablette), ['motif' => 'x'], 'administrer'],
            ['POST', route('logistique.declarations.rejeter', $tablette), ['motif' => 'x'], 'administrer'],
            ['POST', route('logistique.reclamations.prendre', $reclamation), [], 'gerer'],
            ['POST', route('logistique.reclamations.resoudre', $reclamation), ['issue' => 'article_retrouve', 'motif' => 'x'], 'gerer'],
            ['POST', route('logistique.clotures-ecart.store', $this->cycle), [
                'destinataire_id' => $this->prefecture->id, 'materiel_id' => $this->urnes->id, 'abandon' => 1, 'motif' => 'x',
            ], 'administrer'],
        ];

        $sansDroit = [
            'administrer' => $this->gestionnaire,
            'gerer' => User::factory()->create(['role' => 'prefet']),
        ];
        foreach ($ecritures as [$methode, $url, $corps, $droit]) {
            $this->actingAs($sansDroit[$droit])->call($methode, $url, $corps)->assertForbidden();
        }

        $this->assertSame('a_verifier', $tablette->fresh()->statut);
        $this->assertSame('ouverte', $reclamation->fresh()->statut);
        $this->assertSame('a_preparer', $validee->fresh()->statut_acheminement);
        $this->assertSame(0, Arbitrage::count());

        // Aucune écriture de ces écrans n'échappe à la liste, avec le droit annoncé.
        $declarees = collect($ecritures)->mapWithKeys(fn (array $e) => [
            app('router')->getRoutes()->match(Request::create($e[1], $e[0]))->getName() => $e[3],
        ]);
        $reelles = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => (preg_match('/^logistique\.(declarations|reclamations|clotures-ecart)\./', (string) $r->getName()) === 1
                || in_array($r->getName(), ['logistique.decharges.agent', 'logistique.decharges.charger'], true))
                && ! in_array('GET', $r->methods(), true));
        $this->assertSame([], $reelles->map->getName()->diff($declarees->keys())->values()->all());
        foreach ($reelles as $route) {
            $droit = in_array('can:logistique-administrer', $route->gatherMiddleware(), true) ? 'administrer' : 'gerer';
            $this->assertSame($declarees[$route->getName()], $droit, $route->getName());
        }
    }
}

<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Arbitrage;
use App\Models\Logistique\BesoinRemplacement;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\Reclamation;
use App\Models\Logistique\Remise;
use App\Models\User;
use App\Services\Logistique\DechargeService;
use App\Services\Logistique\EngagementService;
use App\Services\Logistique\IndicateurService;
use App\Services\Logistique\ReclamationService;
use App\Services\Logistique\RemiseService;
use App\Services\Logistique\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class ReclamationTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerDecorFictif();
        $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 100], [$this->prefecture, $this->isoloirs, 50],
            [$this->sousPrefecture1, $this->urnes, 30],
        ]);
        $this->stockerFictif($this->urnes, 1000, 500);
        $this->stockerFictif($this->isoloirs, 1000, 100);
    }

    private function service(): ReclamationService
    {
        return app(ReclamationService::class);
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

    /** Exécute $action en tant que $utilisateur puis rétablit le responsable. */
    private function en(User $utilisateur, callable $action): mixed
    {
        $this->actingAs($utilisateur);
        try {
            return $action();
        } finally {
            $this->actingAs($this->responsable);
        }
    }

    /** Décharge de 100 urnes partie (chargée) vers la préfecture. */
    private function partie(int $quantite = 100): Decharge
    {
        return $this->deposerChargee($this->prefecture, [$this->urnes->id => $quantite]);
    }

    private function ouvrir(Decharge $decharge, int $quantite = 10, string $motif = 'manquant', ?Materiel $article = null): Reclamation
    {
        return $this->en($this->autoriteFictive($this->prefecture), fn () => $this->service()->ouvrir(
            $decharge, $article ?? $this->urnes, $motif, $quantite, 'Dix urnes manquent à la livraison.',
        ));
    }

    private function gestionnaire(): User
    {
        return User::factory()->create(['role' => 'logistique_gestionnaire']);
    }

    public function test_ouvrir_par_l_autorite_apres_depart(): void
    {
        $decharge = $this->partie();

        $reclamation = $this->ouvrir($decharge);

        $this->assertSame('ouverte', $reclamation->statut);
        $this->assertNull($reclamation->issue);
        $this->assertSame([$decharge->id, $this->urnes->id, 'manquant', 10], [
            $reclamation->decharge_id, $reclamation->materiel_id, $reclamation->motif, $reclamation->quantite,
        ]);
        $this->assertSame('Dix urnes manquent à la livraison.', $reclamation->message);
        $this->assertSame($this->autoriteFictive($this->prefecture)->id, $reclamation->ouverte_par);
        $this->assertNull($reclamation->traitee_par);
        $this->assertSame('ouverte', $decharge->refresh()->statut_reclamation);
        $entree = JournalEntree::where('action', 'ouverture_reclamation')->sole();
        $this->assertSame('reclamation', $entree->objet_type);
        $this->assertSame($reclamation->id, (int) $entree->objet_id);

        // L'agent de la décharge peut aussi réclamer.
        $autre = $this->en($decharge->agent, fn () => $this->service()->ouvrir($decharge, $this->urnes, 'defectueux', 3, 'Trois urnes cassées.'));
        $this->assertSame($decharge->agent->id, $autre->ouverte_par);
    }

    public function test_ouvrir_avant_depart_refuse(): void
    {
        $service = app(DechargeService::class);
        $decharge = $service->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $service->valider($decharge);

        $this->refus(fn () => $this->ouvrir($decharge->refresh()), "La décharge n'est pas encore partie.");
        $this->assertSame(0, Reclamation::count());
    }

    public function test_ouvrir_hors_perimetre_refuse(): void
    {
        $decharge = $this->partie();
        $message = 'Réclamation hors de votre périmètre.';

        // Autorité d'un autre destinataire, gestionnaire central, compte inactif.
        $autre = $this->autoriteFictive($this->sousPrefecture2);
        $inactif = User::factory()->create(['role' => 'prefet', 'actif' => false]);
        foreach ([$autre, $this->gestionnaire(), $inactif] as $candidat) {
            $this->refus(fn () => $this->en($candidat, fn () => $this->service()->ouvrir($decharge, $this->urnes, 'manquant', 1, 'Message.')), $message);
        }
        // Le sous-préfet d'une décharge via le préfet n'est pas dans le périmètre d'une autre préfecture.
        $this->assertSame(0, Reclamation::count());
    }

    public function test_ouvrir_controle_les_valeurs(): void
    {
        $decharge = $this->partie();

        foreach ([
            fn () => $this->ouvrir($decharge, 0),
            fn () => $this->ouvrir($decharge, 101),
            fn () => $this->ouvrir($decharge, 5, 'perdu'),
            fn () => $this->ouvrir($decharge, 5, 'manquant', $this->isoloirs),
            fn () => $this->en($this->autoriteFictive($this->prefecture), fn () => $this->service()->ouvrir($decharge, $this->urnes, 'manquant', 5, '  ')),
            fn () => $this->en($this->autoriteFictive($this->prefecture), fn () => $this->service()->ouvrir($decharge, $this->urnes, 'manquant', 5, str_repeat('a', 1001))),
        ] as $appel) {
            try {
                $appel();
                $this->fail('Une valeur invalide doit être refusée.');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, Reclamation::count());
            }
        }
    }

    public function test_statut_reclamation_de_la_decharge_suit(): void
    {
        $decharge = $this->partie();
        $premiere = $this->ouvrir($decharge, 10);
        $this->assertSame('ouverte', $decharge->refresh()->statut_reclamation);

        $this->service()->prendreEnCharge($premiere);
        $this->assertSame('en_traitement', $premiere->refresh()->statut);
        $this->assertSame($this->responsable->id, $premiere->traitee_par);
        $this->assertSame('en_traitement', $decharge->refresh()->statut_reclamation);

        // Une réclamation ouverte l'emporte sur une réclamation en traitement.
        $seconde = $this->ouvrir($decharge, 4, 'defectueux');
        $this->assertSame('ouverte', $decharge->refresh()->statut_reclamation);

        $this->service()->resoudre($premiere, 'article_retrouve', 'Retrouvées au dépôt.');
        $this->assertSame('ouverte', $decharge->refresh()->statut_reclamation);

        $this->service()->prendreEnCharge($seconde);
        $this->assertSame('en_traitement', $decharge->refresh()->statut_reclamation);

        $this->service()->resoudre($seconde, 'article_retrouve', 'Retrouvées aussi.');
        $this->assertSame('resolue', $decharge->refresh()->statut_reclamation);

        $this->refus(fn () => $this->service()->prendreEnCharge($seconde->refresh()), 'Seule une réclamation ouverte peut être prise en charge.');
        $troisieme = $this->ouvrir($decharge);
        $this->refus(fn () => $this->en($this->autoriteFictive($this->prefecture), fn () => $this->service()->prendreEnCharge($troisieme)), 'Seul le personnel logistique peut traiter une réclamation.');
        $this->assertSame('ouverte', $troisieme->refresh()->statut);
    }

    public function test_remplacement_autorise_cree_besoin_et_brouillon(): void
    {
        $decharge = $this->partie(80);
        $reclamation = $this->ouvrir($decharge, 10);
        $engagements = app(EngagementService::class);
        $this->assertSame(100, $engagements->besoin($this->cycle, $this->prefecture, $this->urnes));

        $this->service()->resoudre($reclamation, 'remplacement_autorise', 'Urnes détériorées.', 10);

        $this->assertSame(110, $engagements->besoin($this->cycle, $this->prefecture, $this->urnes));
        $besoin = BesoinRemplacement::sole();
        $this->assertSame([$this->cycle->id, $this->prefecture->id, $this->urnes->id, 10], [
            $besoin->cycle_id, $besoin->destinataire_id, $besoin->materiel_id, $besoin->quantite,
        ]);
        $this->assertSame($reclamation->id, $besoin->reclamation_id);
        $this->assertSame($this->responsable->id, $besoin->autorise_par);
        $this->assertSame('Urnes détériorées.', $besoin->motif);

        $brouillon = Decharge::where('nature', 'remplacement')->sole();
        $this->assertSame('brouillon', $brouillon->statut_document);
        $this->assertSame($besoin->id, $brouillon->besoin_remplacement_id);
        $this->assertSame([$this->prefecture->id, 'materiel_lourd'], [$brouillon->destinataire_id, $brouillon->categorie]);
        $this->assertSame([[$this->urnes->id, 10]], $brouillon->lignes->map(fn ($l) => [$l->materiel_id, $l->quantite])->all());

        $reclamation->refresh();
        $this->assertSame(['en_traitement', 'remplacement_autorise'], [$reclamation->statut, $reclamation->issue]);
        $this->assertSame('en_traitement', $decharge->refresh()->statut_reclamation);
        $this->assertSame(1, JournalEntree::where('action', 'resolution_reclamation')->count());

        // Le brouillon initial reste possible : il ne reprend que le reste hors remplacement (20), le remplacement a son brouillon.
        $reliquat = app(DechargeService::class)->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->assertSame('reliquat', $reliquat->nature);
        $this->assertNull($reliquat->besoin_remplacement_id);
        $this->assertSame(20, $reliquat->lignes->firstWhere('materiel_id', $this->urnes->id)->quantite);

        // Un seul brouillon par besoin.
        $this->refus(fn () => app(DechargeService::class)->preparerRemplacement($besoin), 'Ce remplacement a déjà sa décharge.');
        $this->refus(fn () => $this->service()->resoudre($reclamation->refresh(), 'remplacement_autorise', 'Encore.', 5), 'Un remplacement est déjà autorisé pour cette réclamation.');
    }

    public function test_remplacement_sur_dotation_entierement_engagee_ne_va_qu_au_brouillon_de_remplacement(): void
    {
        $decharge = $this->partie(100);
        $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 100], [$this->prefecture, $this->isoloirs, 50], [$this->sousPrefecture1, $this->urnes, 30],
        ]);
        $reclamation = $this->ouvrir($decharge, 10);

        $this->service()->resoudre($reclamation, 'remplacement_autorise', 'Urnes détériorées.', 10);

        $reliquat = app(DechargeService::class)->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->assertSame([$this->isoloirs->id], $reliquat->lignes->pluck('materiel_id')->all());
        $remplacement = Decharge::where('nature', 'remplacement')->sole();
        $this->assertSame(10, $remplacement->lignes->sole()->quantite);
    }

    public function test_remplacement_reserve_au_responsable(): void
    {
        $decharge = $this->partie();
        $reclamation = $this->ouvrir($decharge);

        $this->refus(
            fn () => $this->en($this->gestionnaire(), fn () => $this->service()->resoudre($reclamation, 'remplacement_autorise', 'Motif.', 10)),
            'Seul un responsable logistique peut autoriser un remplacement.',
        );
        $this->assertSame(0, BesoinRemplacement::count());
        $this->assertSame('ouverte', $reclamation->refresh()->statut);

        foreach ([0, 11, null] as $quantite) {
            try {
                $this->service()->resoudre($reclamation, 'remplacement_autorise', 'Motif.', $quantite);
                $this->fail('Une quantité de remplacement hors bornes doit être refusée.');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, BesoinRemplacement::count());
            }
        }
    }

    /** Valide, affecte, charge le brouillon de remplacement et déclare sa réception par la préfecture. */
    private function recevoirRemplacement(Decharge $remplacement, int $recu, int $defectueux): void
    {
        $service = app(DechargeService::class);
        $service->valider($remplacement);
        $service->affecterAgent($remplacement, User::factory()->create(['role' => 'agent_transport']), 'Agent de test');
        app(RemiseService::class)->charger($remplacement->refresh());
        $this->deposerRecue($remplacement->refresh(), [$this->urnes->id => ['recu' => $recu, 'defectueux' => $defectueux]]);
    }

    public function test_resolue_quand_le_remplacement_est_recu_utilisable(): void
    {
        $decharge = $this->partie();
        $this->deposerRecue($decharge, [$this->urnes->id => ['recu' => 100, 'defectueux' => 10]]);
        $reclamation = $this->ouvrir($decharge, 10, 'defectueux');
        $this->service()->resoudre($reclamation, 'remplacement_autorise', 'Urnes à remplacer.', 10);
        $remplacement = Decharge::where('nature', 'remplacement')->sole();

        $this->recevoirRemplacement($remplacement, 10, 0);

        $reclamation->refresh();
        $this->assertSame(['resolue', 'remplacement_autorise'], [$reclamation->statut, $reclamation->issue]);
        $this->assertNotNull($reclamation->resolue_le);
        $this->assertSame('resolue', $decharge->refresh()->statut_reclamation);
        $this->assertSame('recue_destinataire', $remplacement->refresh()->statut_acheminement);
        $this->assertSame(2, JournalEntree::where('action', 'resolution_reclamation')->count());
    }

    public function test_remplacement_recu_en_partie_defectueux_reste_en_traitement(): void
    {
        $decharge = $this->partie();
        $reclamation = $this->ouvrir($decharge, 10, 'defectueux');
        $this->service()->resoudre($reclamation, 'remplacement_autorise', 'Urnes à remplacer.', 10);

        $this->recevoirRemplacement(Decharge::where('nature', 'remplacement')->sole(), 10, 5);

        $this->assertSame('en_traitement', $reclamation->refresh()->statut);
        $this->assertSame('en_traitement', $decharge->refresh()->statut_reclamation);
    }

    public function test_article_retrouve_resolue(): void
    {
        $decharge = $this->partie();
        $reclamation = $this->ouvrir($decharge);

        $this->service()->resoudre($reclamation, 'article_retrouve', 'Retrouvées au dépôt.');

        $reclamation->refresh();
        $this->assertSame(['resolue', 'article_retrouve', 'Retrouvées au dépôt.'], [$reclamation->statut, $reclamation->issue, $reclamation->motif_cloture]);
        $this->assertSame($this->responsable->id, $reclamation->traitee_par);
        $this->assertNotNull($reclamation->resolue_le);
        $this->assertSame('resolue', $decharge->refresh()->statut_reclamation);
        $this->assertSame(0, BesoinRemplacement::count());

        $entree = JournalEntree::where('action', 'resolution_reclamation')->sole();
        $this->assertSame('Retrouvées au dépôt.', $entree->motif);

        $this->refus(fn () => $this->service()->resoudre($reclamation, 'article_retrouve', 'Encore.'), 'Cette réclamation est déjà résolue.');
    }

    public function test_resoudre_controle_issue_et_motif(): void
    {
        $reclamation = $this->ouvrir($this->partie());

        foreach ([['inconnue', 'Motif.'], ['article_retrouve', '  '], ['article_retrouve', str_repeat('a', 501)]] as [$issue, $motif]) {
            try {
                $this->service()->resoudre($reclamation, $issue, $motif);
                $this->fail('Une résolution invalide doit être refusée.');
            } catch (InvalidArgumentException) {
                $this->assertSame('ouverte', $reclamation->refresh()->statut);
            }
        }
    }

    public function test_erreur_declaration_rouvre_la_remise(): void
    {
        $decharge = $this->partie();
        $erronee = $this->deposerRecue($decharge, [$this->urnes->id => 90]);
        $remise = Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->sole();
        $this->assertSame('retenue', $erronee->refresh()->statut);
        $this->assertSame('recue_destinataire', $decharge->refresh()->statut_acheminement);
        $reclamation = $this->ouvrir($decharge, 10);

        $this->service()->resoudre($reclamation, 'erreur_declaration', 'Saisie erronée du préfet.', null, $erronee);

        $this->assertSame('remplacee', $erronee->refresh()->statut);
        $this->assertSame(90, $erronee->lignes->sole()->recu);
        $this->assertSame('en_attente', $remise->refresh()->statut);
        $this->assertSame('chargee', $decharge->refresh()->statut_acheminement);
        $reclamation->refresh();
        $this->assertSame(['resolue', 'erreur_declaration'], [$reclamation->statut, $reclamation->issue]);
        $this->assertSame('resolue', $decharge->statut_reclamation);
        $this->assertTrue(JournalEntree::where('action', 'statut_declaration')->where('objet_id', $erronee->id)->get()
            ->contains(fn ($e) => ($e->apres['statut'] ?? null) === 'remplacee'));

        // Une nouvelle déclaration du même mode est acceptée ; l'ancienne reste visible.
        $nouvelle = $this->deposerRecue($decharge, [$this->urnes->id => 100]);
        $this->assertSame('retenue', $nouvelle->statut);
        $this->assertSame('recue_destinataire', $decharge->refresh()->statut_acheminement);
        $this->assertSame(['remplacee', 'retenue'], Declaration::where('remise_id', $remise->id)->orderBy('id')->pluck('statut')->all());
    }

    public function test_erreur_declaration_refuse_une_declaration_etrangere_ou_non_retenue(): void
    {
        $decharge = $this->partie();
        $autre = $this->deposerChargee($this->prefecture, [$this->isoloirs->id => 50]);
        $retenue = $this->deposerRecue($decharge, [$this->urnes->id => 90]);
        $etrangere = $this->deposerRecue($autre, [$this->isoloirs->id => 50]);
        $reclamation = $this->ouvrir($decharge, 10);

        $this->refus(fn () => $this->service()->resoudre($reclamation, 'erreur_declaration', 'Erreur.', null, $etrangere), 'Cette déclaration ne concerne pas la décharge réclamée.');
        $retenue->forceFill(['statut' => 'a_verifier'])->save();
        $this->refus(fn () => $this->service()->resoudre($reclamation, 'erreur_declaration', 'Erreur.', null, $retenue), 'Seule une déclaration retenue peut être remplacée.');
        try {
            $this->service()->resoudre($reclamation, 'erreur_declaration', 'Erreur.');
            $this->fail('La déclaration erronée est obligatoire.');
        } catch (InvalidArgumentException) {
            $this->assertSame('ouverte', $reclamation->refresh()->statut);
        }
    }

    public function test_cloture_sans_remplacement_ecart_accepte(): void
    {
        $decharge = $this->partie();
        $this->deposerRecue($decharge, [$this->urnes->id => 90]);
        $reclamation = $this->ouvrir($decharge, 10);

        $this->refus(
            fn () => $this->en($this->gestionnaire(), fn () => $this->service()->resoudre($reclamation, 'cloture_sans_remplacement', 'Écart accepté.')),
            'Seul un responsable logistique peut clôturer une réclamation sans remplacement.',
        );
        $this->assertSame(0, Arbitrage::count());

        $this->service()->resoudre($reclamation, 'cloture_sans_remplacement', 'Écart accepté.');

        $arbitrage = Arbitrage::sole();
        $this->assertSame(['cloture_ecart', 10, 0, $this->prefecture->id, $this->urnes->id], [
            $arbitrage->objet, $arbitrage->quantite_abandon_reception, $arbitrage->quantite_expedition_annulee,
            $arbitrage->destinataire_id, $arbitrage->materiel_id,
        ]);
        $reclamation->refresh();
        $this->assertSame(['resolue', 'cloture_sans_remplacement'], [$reclamation->statut, $reclamation->issue]);
        $this->assertSame('resolue', $decharge->refresh()->statut_reclamation);
    }

    public function test_cloture_sans_remplacement_apres_cloture_du_cycle_passe_l_indicateur_en_cloture_avec_ecart(): void
    {
        $decharge = $this->partie();
        $this->deposerRecue($decharge, [$this->urnes->id => 90]);
        app(StockService::class)->cloturerCycle($this->cycle, 'Fin du cycle');
        $reclamation = $this->ouvrir($decharge, 10);

        $this->service()->resoudre($reclamation, 'cloture_sans_remplacement', 'Écart accepté après le scrutin.');

        $this->assertSame(['resolue', 'cloture_sans_remplacement'], [$reclamation->refresh()->statut, $reclamation->issue]);
        $this->assertSame(10, Arbitrage::sole()->quantite_abandon_reception);
        $ligne = collect(app(IndicateurService::class)->parDestinataire($this->cycle->refresh()))
            ->first(fn ($l) => $l['destinataire']->is($this->prefecture));
        $this->assertSame('cloture_avec_ecart', $ligne['etat']);
    }

    public function test_cloture_sans_remplacement_sans_reste_a_receptionner_ne_cree_pas_d_arbitrage(): void
    {
        $decharge = $this->partie();
        $this->deposerRecue($decharge, [$this->urnes->id => 100]);
        $reclamation = $this->ouvrir($decharge, 10);

        // Tout est déjà reçu utilisable : rien à abandonner, la réclamation est close sans arbitrage.
        $this->service()->resoudre($reclamation, 'cloture_sans_remplacement', 'Réception complète finalement.');

        $this->assertSame(0, Arbitrage::count());
        $this->assertSame(['resolue', 'cloture_sans_remplacement'], [$reclamation->refresh()->statut, $reclamation->issue]);
        $this->assertSame('resolue', $decharge->refresh()->statut_reclamation);
        $this->assertSame(0, JournalEntree::where('action', 'resolution_reclamation')->sole()->apres['arbitrage_id'] ?? 0);
    }

    public function test_cycle_clos_ouvrir_et_issues_sans_stock_acceptes(): void
    {
        $decharge = $this->partie();
        $declaration = $this->deposerRecue($decharge, [$this->urnes->id => 90]);
        $retrouvee = $this->ouvrir($decharge, 3);
        $erreur = $this->ouvrir($decharge, 10);
        $remplacement = $this->ouvrir($decharge, 4, 'defectueux');
        $cloture = $this->ouvrir($decharge, 5);
        app(StockService::class)->cloturerCycle($this->cycle, 'Fin du cycle');

        $apres = $this->ouvrir($decharge, 2);
        $this->service()->prendreEnCharge($apres);
        $this->service()->resoudre($retrouvee, 'article_retrouve', 'Retrouvées.');
        $this->assertSame('resolue', $retrouvee->refresh()->statut);

        $this->refus(fn () => $this->service()->resoudre($remplacement, 'remplacement_autorise', 'Motif.', 4), 'Cycle clôturé : décharge de remplacement impossible.');
        $this->assertSame(0, BesoinRemplacement::count());
        $this->assertSame(0, Arbitrage::count());

        // Un abandon seul ne déplace aucun stock : la clôture sans remplacement reste possible après la clôture du cycle.
        $this->service()->resoudre($cloture, 'cloture_sans_remplacement', 'Motif.');
        $this->assertSame(['resolue', 'cloture_sans_remplacement'], [$cloture->refresh()->statut, $cloture->issue]);
        $this->assertSame([5, 0], [Arbitrage::sole()->quantite_abandon_reception, Arbitrage::sole()->quantite_expedition_annulee]);

        $this->service()->resoudre($erreur, 'erreur_declaration', 'Saisie erronée.', null, $declaration);
        $this->assertSame('remplacee', $declaration->refresh()->statut);
        $this->assertSame('chargee', $decharge->refresh()->statut_acheminement);
    }

    public function test_brouillons_de_remplacement_independants_des_brouillons_initiaux(): void
    {
        $decharge = $this->partie(80);
        $service = app(DechargeService::class);
        // Un brouillon initial existe déjà ; chaque besoin a malgré cela son propre brouillon.
        $initial = $service->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $premiere = $this->ouvrir($decharge, 10);
        $seconde = $this->ouvrir($decharge, 5, 'defectueux');

        $this->service()->resoudre($premiere, 'remplacement_autorise', 'Premier remplacement.', 10);
        $this->service()->resoudre($seconde, 'remplacement_autorise', 'Second remplacement.', 5);

        $this->assertSame('reliquat', $initial->nature);
        $brouillons = Decharge::where('nature', 'remplacement')->orderBy('id')->get();
        $this->assertCount(2, $brouillons);
        $this->assertSame([10, 5], $brouillons->map(fn ($d) => $d->lignes->sole()->quantite)->all());
        $this->assertCount(2, $brouillons->pluck('besoin_remplacement_id')->unique());
        $this->assertSame(1, Decharge::where('statut_document', 'brouillon')->whereIn('nature', ['initiale', 'reliquat'])->count());
    }

    public function test_remplacer_une_decharge_de_remplacement_annulee_garde_le_besoin(): void
    {
        $decharge = $this->partie(80);
        $this->service()->resoudre($this->ouvrir($decharge, 10), 'remplacement_autorise', 'Remplacement.', 10);
        $service = app(DechargeService::class);
        $remplacement = Decharge::where('nature', 'remplacement')->sole();
        $besoinId = $remplacement->besoin_remplacement_id;
        $service->actualiser($remplacement);
        $this->assertSame([10], $remplacement->lignes()->pluck('quantite')->all());
        $service->valider($remplacement);
        $service->annuler($remplacement, 'Erreur de saisie.');

        $nouvelle = $service->remplacer($remplacement->refresh());

        $this->assertSame(['remplacement', $besoinId], [$nouvelle->nature, $nouvelle->besoin_remplacement_id]);
        $this->assertNull($remplacement->refresh()->besoin_remplacement_id);
        $this->assertSame(10, $nouvelle->lignes->sole()->quantite);
        $this->refus(fn () => $service->preparerRemplacement(BesoinRemplacement::findOrFail($besoinId)), 'Ce remplacement a déjà sa décharge.');
    }

    public function test_remplacement_en_partie_defectueux_se_cloture_sur_le_reste_a_receptionner(): void
    {
        $decharge = $this->partie();
        $this->deposerRecue($decharge, [$this->urnes->id => ['recu' => 100, 'defectueux' => 10]]);
        $reclamation = $this->ouvrir($decharge, 10, 'defectueux');
        $this->service()->resoudre($reclamation, 'remplacement_autorise', 'Urnes à remplacer.', 10);
        $this->recevoirRemplacement(Decharge::where('nature', 'remplacement')->sole(), 10, 5);
        $this->assertSame('en_traitement', $reclamation->refresh()->statut);

        // Utilisable : 90 + 5 = 95 sur 100 ; l'abandon est plafonné à 5 et non à la quantité réclamée (10).
        $this->service()->resoudre($reclamation, 'cloture_sans_remplacement', 'On garde les 5 restantes.');

        $arbitrage = Arbitrage::sole();
        $this->assertSame([5, 0], [$arbitrage->quantite_abandon_reception, $arbitrage->quantite_expedition_annulee]);
        $reclamation->refresh();
        $this->assertSame(['resolue', 'cloture_sans_remplacement'], [$reclamation->statut, $reclamation->issue]);
        $this->assertSame('resolue', $decharge->refresh()->statut_reclamation);
        $entree = JournalEntree::where('action', 'resolution_reclamation')->orderByDesc('id')->first();
        $this->assertSame('remplacement_autorise', $entree->avant['issue']);
        $this->assertSame('cloture_sans_remplacement', $entree->apres['issue']);
    }

    public function test_article_retrouve_sur_une_reclamation_a_remplacement_autorise(): void
    {
        $decharge = $this->partie(80);
        $reclamation = $this->ouvrir($decharge, 10);
        $this->service()->resoudre($reclamation, 'remplacement_autorise', 'Remplacement.', 10);

        $this->service()->resoudre($reclamation->refresh(), 'article_retrouve', 'Retrouvées finalement.');

        $this->assertSame(['resolue', 'article_retrouve'], [$reclamation->refresh()->statut, $reclamation->issue]);
        $this->assertSame('resolue', $decharge->refresh()->statut_reclamation);
        $entree = JournalEntree::where('action', 'resolution_reclamation')->orderByDesc('id')->first();
        $this->assertSame('remplacement_autorise', $entree->avant['issue']);
    }

    public function test_remplacement_annule_se_reautorise_sur_le_meme_besoin(): void
    {
        $decharge = $this->partie(80);
        $reclamation = $this->ouvrir($decharge, 10);
        $service = app(DechargeService::class);
        $this->service()->resoudre($reclamation, 'remplacement_autorise', 'Remplacement.', 10);
        $premier = Decharge::where('nature', 'remplacement')->sole();
        $besoinId = $premier->besoin_remplacement_id;
        $this->refus(fn () => $this->service()->resoudre($reclamation->refresh(), 'remplacement_autorise', 'Encore.', 10), 'Un remplacement est déjà autorisé pour cette réclamation.');

        $service->valider($premier);
        $service->annuler($premier, 'Erreur de saisie.');

        $this->refus(fn () => $this->service()->resoudre($reclamation->refresh(), 'remplacement_autorise', 'Autre quantité.', 7), 'La quantité du remplacement autorisé est de 10 : elle ne peut pas changer.');
        $this->service()->resoudre($reclamation->refresh(), 'remplacement_autorise', 'Nouvelle décharge.');

        $this->assertSame(1, BesoinRemplacement::count());
        $nouvelle = Decharge::where('nature', 'remplacement')->where('statut_document', 'brouillon')->sole();
        $this->assertSame($besoinId, $nouvelle->besoin_remplacement_id);
        $this->assertNull($premier->refresh()->besoin_remplacement_id);
        $this->assertSame(10, $nouvelle->lignes->sole()->quantite);
        $this->assertSame('en_traitement', $reclamation->refresh()->statut);
        $this->assertSame(110, app(EngagementService::class)->besoin($this->cycle, $this->prefecture, $this->urnes));
    }

    public function test_brouillon_de_remplacement_ni_supprime_ni_gonfle(): void
    {
        $decharge = $this->partie(80);
        $this->service()->resoudre($this->ouvrir($decharge, 10), 'remplacement_autorise', 'Remplacement.', 10);
        $brouillon = Decharge::where('nature', 'remplacement')->sole();
        $service = app(DechargeService::class);

        $this->refus(fn () => $service->supprimerBrouillon($brouillon, 'Motif.'), 'Ce brouillon porte un remplacement autorisé : il ne peut pas être supprimé.');
        $this->assertSame(1, Decharge::where('nature', 'remplacement')->count());

        $ligne = $brouillon->lignes->sole();
        $this->refus(fn () => $service->modifierLigne($ligne, 11, null), 'La quantité d\'un remplacement ne peut pas dépasser celle du besoin autorisé (10).');
        $service->modifierLigne($ligne, 6, null);
        $this->assertSame(6, $ligne->refresh()->quantite);
    }

    public function test_service_sans_auteur_refuse(): void
    {
        $decharge = $this->partie();
        $reclamation = $this->ouvrir($decharge);
        auth()->logout();

        $this->expectException(\LogicException::class);
        $this->service()->resoudre($reclamation, 'article_retrouve', 'Motif.');
    }
}

<?php

namespace Tests\Feature\Logistique;

use App\Models\Logistique\Decharge;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\Remise;
use App\Models\User;
use App\Services\Logistique\ArbitrageService;
use App\Services\Logistique\BesoinService;
use App\Services\Logistique\DechargeService;
use App\Services\Logistique\DeclarationService;
use App\Services\Logistique\IndicateurService;
use App\Services\Logistique\ReclamationService;
use App\Services\Logistique\RemiseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class IndicateursTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerDecorFictif();
        $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 100], [$this->prefecture, $this->isoloirs, 50],
            [$this->sousPrefecture1, $this->urnes, 100],
        ]);
        $this->stockerFictif($this->urnes, 1000, 500);
        $this->stockerFictif($this->isoloirs, 1000, 100);
    }

    private function indicateurs(): IndicateurService
    {
        return app(IndicateurService::class);
    }

    /** @return array<string, mixed> */
    private function ligne(Destinataire $destinataire, ?Materiel $article = null, ?array $ids = null): array
    {
        $article ??= $this->urnes;
        $ligne = $this->indicateurs()->parDestinataire($this->cycle, $ids)
            ->first(fn (array $l) => $l['destinataire']->is($destinataire) && $l['materiel']->is($article));
        $this->assertNotNull($ligne, "Aucune ligne pour {$destinataire->libelle} / {$article->libelle}.");

        return $ligne;
    }

    /** @return array<string, mixed> */
    private function article(?Materiel $article = null): array
    {
        $article ??= $this->urnes;

        return $this->indicateurs()->parArticle($this->cycle)->first(fn (array $l) => $l['materiel']->is($article));
    }

    /** @param  array<string, int|string>  $attendu */
    private function assertLigne(array $attendu, array $ligne): void
    {
        $obtenu = [];
        foreach (array_keys($attendu) as $cle) {
            $obtenu[$cle] = $ligne[$cle] ?? null;
        }
        $this->assertEquals($attendu, $obtenu);
    }

    private function en(User $auteur, callable $action): mixed
    {
        $this->actingAs($auteur);
        try {
            return $action();
        } finally {
            $this->actingAs($this->responsable);
        }
    }

    /** Remise de livraison d'une décharge directe, ou de redistribution d'une décharge via le préfet. */
    private function declarerRecu(User $auteur, Remise $remise, int $recu, int $defectueux = 0, string $mode = 'compte'): void
    {
        $lignes = [];
        foreach ($remise->lignes as $l) {
            $lignes[$l->materiel_id] = ['recu' => $recu, 'defectueux' => $defectueux, 'observation' => null];
        }
        $this->en($auteur, fn () => app(DeclarationService::class)->declarer(
            $remise, $mode, ['nom' => 'Signataire', 'qualite' => 'Autorité'], $lignes, (string) Str::uuid(),
        ));
    }

    public function test_charge_non_recu_en_transit(): void
    {
        $this->deposerChargee($this->prefecture, [$this->urnes->id => 100]);

        $this->assertLigne([
            'dotation' => 100, 'besoin_expedition' => 100, 'objectif_reception' => 100, 'engage' => 100,
            'expedie' => 100, 'reste_a_expedier' => 0, 'surplus_expedie' => 0,
            'en_transit' => 100, 'chez_intermediaire' => 0, 'recu' => 0, 'recu_utilisable' => 0,
            'reste_a_receptionner' => 100, 'reclamations_actives' => 0, 'etat' => 'en_cours',
        ], $this->ligne($this->prefecture));
    }

    public function test_100_recus_dont_10_defectueux(): void
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => ['recu' => 100, 'defectueux' => 10]]);

        $this->assertLigne([
            'expedie' => 100, 'en_transit' => 0, 'chez_intermediaire' => 0, 'recu' => 100,
            'recu_utilisable' => 90, 'reste_a_receptionner' => 10, 'etat' => 'en_cours',
        ], $this->ligne($this->prefecture));
        $this->assertSame(90, $this->indicateurs()->recuUtilisable($this->cycle, $this->prefecture, $this->urnes));
    }

    public function test_scenario_remplacement_deterioration(): void
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => ['recu' => 100, 'defectueux' => 10]]);
        $reclamation = $this->en($this->autoriteFictive($this->prefecture), fn () => app(ReclamationService::class)->ouvrir(
            $decharge, $this->urnes, 'defectueux', 10, 'Dix urnes détériorées.',
        ));
        $this->assertSame(1, $this->ligne($this->prefecture)['reclamations_actives']);

        app(ReclamationService::class)->resoudre($reclamation, 'remplacement_autorise', 'Urnes à remplacer.', 10);

        // Le remplacement entre dans le besoin (110) mais pas dans l'objectif de réception (100).
        $this->assertLigne([
            'dotation' => 100, 'besoin_expedition' => 110, 'objectif_reception' => 100, 'expedie' => 100,
            'reste_a_expedier' => 10, 'recu_utilisable' => 90, 'reste_a_receptionner' => 10,
            'reclamations_actives' => 1, 'etat' => 'en_cours',
        ], $this->ligne($this->prefecture));

        $remplacement = Decharge::where('nature', 'remplacement')->sole();
        $service = app(DechargeService::class);
        $service->valider($remplacement);
        $service->affecterAgent($remplacement, User::factory()->create(['role' => 'agent_transport']), 'Agent de test');
        app(RemiseService::class)->charger($remplacement->refresh());
        $this->deposerRecue($remplacement->refresh(), [$this->urnes->id => ['recu' => 10, 'defectueux' => 0]]);

        $this->assertLigne([
            'dotation' => 100, 'besoin_expedition' => 110, 'objectif_reception' => 100, 'engage' => 110, 'expedie' => 110,
            'reste_a_expedier' => 0, 'surplus_expedie' => 0, 'en_transit' => 0, 'recu' => 110,
            'recu_utilisable' => 100, 'reste_a_receptionner' => 0, 'reclamations_actives' => 0, 'etat' => 'solde_complet',
        ], $this->ligne($this->prefecture));
    }

    public function test_divergentes_a_verifier_et_rien_compte(): void
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 100]);
        $remise = Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->with('lignes')->sole();
        $this->declarerRecu($decharge->agent, $remise, 100, 0, 'tablette');
        $this->declarerRecu($this->autoriteFictive($this->prefecture), $remise, 90);
        $this->assertSame('a_verifier', $remise->refresh()->statut);

        $this->assertLigne(['recu' => 0, 'recu_utilisable' => 0, 'reste_a_receptionner' => 100, 'etat' => 'en_cours'], $this->ligne($this->prefecture));
        $this->assertSame(0, $this->indicateurs()->recuUtilisable($this->cycle, $this->prefecture, $this->urnes));

        // Arbitrée : la déclaration retenue compte, l'autre jamais.
        $tablette = $remise->declarations()->where('mode', 'tablette')->sole();
        app(ArbitrageService::class)->retenir($tablette, 'Constat de l\'agent.');

        $this->assertLigne(['recu' => 100, 'recu_utilisable' => 100, 'reste_a_receptionner' => 0, 'etat' => 'solde_complet'], $this->ligne($this->prefecture));
    }

    public function test_reliquat_chez_le_prefet(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => 100]);
        $this->assertLigne([
            'expedie' => 100, 'chez_intermediaire' => 100, 'en_transit' => 0, 'recu' => 0, 'recu_utilisable' => 0,
        ], $this->ligne($this->sousPrefecture1));

        $redistribution = $this->en($this->autoriteFictive($this->prefecture), fn () => app(RemiseService::class)->preparerRedistribution($decharge->refresh(), [$this->urnes->id => 80]));

        // Transféré mais pas encore reçu : 20 chez le préfet, 80 en route vers le sous-préfet.
        $this->assertLigne(['chez_intermediaire' => 20, 'en_transit' => 80, 'recu' => 0], $this->ligne($this->sousPrefecture1));

        $this->declarerRecu($this->autoriteFictive($this->sousPrefecture1), $redistribution->load('lignes'), 80);

        $ligne = $this->ligne($this->sousPrefecture1);
        $this->assertLigne([
            'expedie' => 100, 'chez_intermediaire' => 20, 'en_transit' => 0, 'recu' => 80, 'recu_utilisable' => 80,
            'objectif_reception' => 100, 'reste_a_receptionner' => 20, 'etat' => 'en_cours',
        ], $ligne);
        $this->assertSame(app(RemiseService::class)->soldePrefet($decharge->refresh())[$this->urnes->id]['reliquat'], $ligne['chez_intermediaire']);
        // Le reçu du préfet ne compte pas pour la préfecture elle-même.
        $this->assertSame(100, $this->ligne($this->prefecture)['reste_a_receptionner']);
    }

    public function test_ecart_accepte_etat_cloture(): void
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 80]);
        $this->deposerRecue($decharge, [$this->urnes->id => 80]);
        $this->assertSame('en_cours', $this->ligne($this->prefecture)['etat']);

        app(ArbitrageService::class)->cloturerAvecEcart($this->cycle, $this->prefecture, $this->urnes, 20, 20, 'Écart accepté.');

        $this->assertLigne([
            'dotation' => 100, 'besoin_expedition' => 80, 'objectif_reception' => 80, 'expedie' => 80,
            'reste_a_expedier' => 0, 'recu_utilisable' => 80, 'reste_a_receptionner' => 0, 'etat' => 'cloture_avec_ecart',
        ], $this->ligne($this->prefecture));
    }

    public function test_plan_revise_a_la_baisse_surplus(): void
    {
        $this->deposerChargee($this->prefecture, [$this->urnes->id => 100]);
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 80], [$this->prefecture, $this->isoloirs, 50], [$this->sousPrefecture1, $this->urnes, 100]]);

        $this->assertLigne([
            'dotation' => 80, 'besoin_expedition' => 80, 'objectif_reception' => 80, 'expedie' => 100,
            'reste_a_expedier' => 0, 'surplus_expedie' => 20,
        ], $this->ligne($this->prefecture));
        // Par article, le reste et le surplus se calculent sur les totaux (comme BesoinService), jamais destinataire par destinataire.
        $this->assertLigne(['besoin_expedition' => 180, 'expedie' => 100, 'reste_a_expedier' => 80, 'surplus_expedie' => 0], $this->article());
        $this->assertLigne(['besoin_expedition' => 80, 'expedie' => 100, 'reste_a_expedier' => 0, 'surplus_expedie' => 20],
            $this->indicateurs()->parArticle($this->cycle, [$this->prefecture->id])->first(fn (array $l) => $l['materiel']->is($this->urnes)));
    }

    public function test_expedie_par_destinataire_egale_expedie_par_article(): void
    {
        $this->deposerChargee($this->prefecture, [$this->urnes->id => 60]);
        $this->deposerChargee($this->prefecture, [$this->urnes->id => 25]);
        $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 40]);
        $this->deposerChargee($this->prefecture, [$this->isoloirs->id => 50]);
        $besoin = app(BesoinService::class);

        $parDestinataire = $this->indicateurs()->parDestinataire($this->cycle);
        $this->assertSame(85, $this->ligne($this->prefecture)['expedie']);
        $this->assertSame(40, $this->ligne($this->sousPrefecture1)['expedie']);
        foreach ([$this->urnes, $this->isoloirs] as $article) {
            $somme = $parDestinataire->filter(fn (array $l) => $l['materiel']->is($article))->sum('expedie');
            $this->assertSame($besoin->expedie($this->cycle, $article), $somme);
            $ligneArticle = $this->article($article);
            $this->assertSame($somme, $ligneArticle['expedie']);
            $this->assertSame($besoin->besoinExpedition($this->cycle, $article), $ligneArticle['besoin_expedition']);
            $this->assertSame($besoin->resteAExpedier($this->cycle, $article), $ligneArticle['reste_a_expedier']);
            $this->assertSame($besoin->surplusExpedie($this->cycle, $article), $ligneArticle['surplus_expedie']);
        }
    }

    public function test_par_article_somme_les_destinataires_et_trie_par_ordre(): void
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => ['recu' => 100, 'defectueux' => 10]]);
        $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);

        $articles = $this->indicateurs()->parArticle($this->cycle);

        $this->assertSame(['Urnes', 'Isoloirs'], $articles->map(fn (array $l) => $l['materiel']->libelle)->all());
        $this->assertArrayNotHasKey('etat', $articles[0]);
        $this->assertLigne([
            'dotation' => 200, 'besoin_expedition' => 200, 'objectif_reception' => 200, 'engage' => 200, 'expedie' => 200,
            'reste_a_expedier' => 0, 'en_transit' => 100, 'chez_intermediaire' => 0, 'recu' => 100,
            'recu_utilisable' => 90, 'reste_a_receptionner' => 110, 'reclamations_actives' => 0,
        ], $articles[0]);
        $this->assertLigne(['dotation' => 50, 'expedie' => 0, 'reste_a_expedier' => 50, 'reste_a_receptionner' => 50], $articles[1]);
    }

    public function test_article_hors_plan_avec_remplacement_et_filtre_par_destinataire(): void
    {
        DB::table('besoins_remplacement')->insert([
            'cycle_id' => $this->cycle->id, 'destinataire_id' => $this->sousPrefecture2->id, 'materiel_id' => $this->enveloppes->id,
            'quantite' => 7, 'motif' => 'Perte', 'autorise_par' => $this->responsable->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ligne = $this->ligne($this->sousPrefecture2, $this->enveloppes);
        $this->assertLigne([
            'dotation' => 0, 'besoin_expedition' => 7, 'objectif_reception' => 0, 'reste_a_expedier' => 7,
            'reste_a_receptionner' => 0, 'etat' => 'en_cours',
        ], $ligne);

        $this->assertSame(1, $this->indicateurs()->parDestinataire($this->cycle, [$this->sousPrefecture2->id])->count());
        $this->assertSame(2, $this->indicateurs()->parDestinataire($this->cycle, [$this->prefecture->id])->count());
        $this->assertSame(0, $this->indicateurs()->parDestinataire($this->cycle, [])->count());
        $this->assertSame(0, $this->indicateurs()->parDestinataire($this->cycle, [$this->poste->id])->count());
        $this->assertSame(7, $this->indicateurs()->parArticle($this->cycle, [$this->sousPrefecture2->id])->sole()['besoin_expedition']);
    }

    public function test_requetes_bornees(): void
    {
        $compter = function (callable $appel): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $appel();
            $requetes = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $requetes;
        };
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => 90]);

        $destinataires = $compter(fn () => $this->indicateurs()->parDestinataire($this->cycle));
        $articles = $compter(fn () => $this->indicateurs()->parArticle($this->cycle));
        $this->assertLessThanOrEqual(14, $destinataires);
        $this->assertLessThanOrEqual(14, $articles);

        // Autant de requêtes avec davantage de destinataires, de décharges, de redistributions et de réclamations.
        $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 100], [$this->prefecture, $this->isoloirs, 50], [$this->sousPrefecture1, $this->urnes, 100],
            [$this->sousPrefecture2, $this->urnes, 40], [$this->poste, $this->urnes, 30], [$this->poste, $this->isoloirs, 5],
        ]);
        $d1 = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($d1, [$this->urnes->id => 100]);
        $this->en($this->autoriteFictive($this->prefecture), fn () => app(RemiseService::class)->preparerRedistribution($d1->refresh(), [$this->urnes->id => 30]));
        $d2 = $this->deposerChargee($this->sousPrefecture2, [$this->urnes->id => 40]);
        $this->en($this->autoriteFictive($this->sousPrefecture2), fn () => app(ReclamationService::class)->ouvrir($d2, $this->urnes, 'manquant', 3, 'Trois manquent.'));
        app(ArbitrageService::class)->cloturerAvecEcart($this->cycle, $this->poste, $this->isoloirs, 0, 5, 'Annulé.');
        DB::table('besoins_remplacement')->insert([
            'cycle_id' => $this->cycle->id, 'destinataire_id' => $this->poste->id, 'materiel_id' => $this->urnes->id,
            'quantite' => 2, 'motif' => 'Perte', 'autorise_par' => $this->responsable->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame($destinataires, $compter(fn () => $this->indicateurs()->parDestinataire($this->cycle)));
        $this->assertSame($articles, $compter(fn () => $this->indicateurs()->parArticle($this->cycle)));
        $this->assertGreaterThan(5, $this->indicateurs()->parDestinataire($this->cycle)->count());
    }
}

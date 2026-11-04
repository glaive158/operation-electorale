<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Arbitrage;
use App\Models\Logistique\BesoinRemplacement;
use App\Models\Logistique\Commande;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\InstantaneEffectifs;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\MouvementStock;
use App\Models\Logistique\PlanDotation;
use App\Models\Logistique\PlanDotationLigne;
use App\Models\User;
use App\Services\Logistique\BesoinService;
use App\Services\Logistique\CleService;
use App\Services\Logistique\CommandeService;
use App\Services\Logistique\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class BesoinTest extends TestCase
{
    use RefreshDatabase;

    private Cycle $cycle;

    private User $responsable;

    private Materiel $urnes;

    private Materiel $isoloirs;

    private Destinataire $destinataire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->responsable = User::factory()->create(['role' => 'logistique_responsable']);
        $this->actingAs($this->responsable);

        $this->cycle = Cycle::create(['libelle' => 'Cycle A', 'type' => 'scrutin']);
        $this->urnes = Materiel::create(['libelle' => 'Urnes', 'categorie' => 'materiel_lourd', 'unite_stock' => 'pièce', 'ordre' => 1]);
        $this->isoloirs = Materiel::create(['libelle' => 'Isoloirs', 'categorie' => 'materiel_lourd', 'unite_stock' => 'pièce', 'ordre' => 2]);
        $this->destinataire = Destinataire::create(['type' => 'prefecture', 'libelle' => 'Préfecture de Thiès']);
    }

    private function besoin(): BesoinService
    {
        return app(BesoinService::class);
    }

    private function stock(): StockService
    {
        return app(StockService::class);
    }

    /**
     * Plan approuvé du cycle, sans passer par le calcul : une ligne par article.
     *
     * @param  array<int, array{0: Materiel, 1: int, 2?: string, 3?: string}>  $articles  [article, dotation approuvée, réserve figée (%), exact]
     */
    private function planApprouve(array $articles, int $version = 1): PlanDotation
    {
        PlanDotation::where('cycle_id', $this->cycle->id)->where('statut', 'approuve')->update(['statut' => 'remplace']);

        $instantane = InstantaneEffectifs::create(['cycle_id' => $this->cycle->id, 'version' => $version, 'user_id' => $this->responsable->id]);
        $parametres = [];
        foreach ($articles as $article) {
            $parametres[$article[0]->id] = ['base' => 'bureau_vote', 'ratio' => '1', 'majoration' => '0.00', 'reserve' => $article[2] ?? '0.00'];
        }

        $plan = PlanDotation::create([
            'cycle_id' => $this->cycle->id, 'version' => $version, 'instantane_id' => $instantane->id,
            'parametres' => $parametres, 'calcule_par' => $this->responsable->id, 'anomalies' => [],
        ]);
        $plan->forceFill(['statut' => 'approuve', 'approuve_par' => $this->responsable->id, 'approuve_le' => now()])->save();

        foreach ($articles as $article) {
            [$materiel, $dotation] = $article;
            PlanDotationLigne::create([
                'plan_dotation_id' => $plan->id, 'destinataire_id' => $this->destinataire->id, 'materiel_id' => $materiel->id,
                'effectif' => $dotation, 'exact' => $article[3] ?? (string) $dotation, 'majoration' => '0.00', 'minimum' => 0,
                'arrondi' => 'superieur', 'dotation_calculee' => $dotation,
            ])->forceFill(['dotation_approuvee' => $dotation])->save();
        }

        return $plan;
    }

    private function planUrnes(string $reserve = '0.00'): PlanDotation
    {
        return $this->planApprouve([[$this->urnes, 1000, $reserve]]);
    }

    private function entete(string $reference = 'CMD-A'): array
    {
        return ['reference' => $reference, 'fournisseur' => 'Imprimerie nationale', 'date_commande' => '2026-11-10'];
    }

    public function test_plan_approuve_stock_nul_besoin_plus_reserve(): void
    {
        $this->planUrnes('5.00');

        $this->assertSame(1000, $this->besoin()->besoinExpedition($this->cycle, $this->urnes));
        $this->assertSame(50, $this->besoin()->reserveCentraleCible($this->cycle, $this->urnes));
        $this->assertSame(1050, $this->besoin()->approvisionnementRestant($this->cycle, $this->urnes));
    }

    public function test_attribution_reduit_la_proposition(): void
    {
        $this->planUrnes();
        $this->stock()->inventaireInitial($this->urnes, 700, 'Stock existant');
        $this->stock()->attribuer($this->cycle, $this->urnes, 700, 'Pour le cycle');

        $this->assertSame(300, $this->besoin()->approvisionnementRestant($this->cycle, $this->urnes));
    }

    public function test_stock_reserve_au_cycle_couvre_le_besoin(): void
    {
        $this->planUrnes('0.00');
        $this->stock()->inventaireInitial($this->urnes, 1000, 'Stock existant');
        $this->stock()->reserver($this->cycle, $this->urnes, 1000);

        $this->assertSame(0, $this->besoin()->approvisionnementRestant($this->cycle, $this->urnes));
    }

    public function test_commande_attendue_compte(): void
    {
        $this->planUrnes();
        $commande = app(CommandeService::class)->creer($this->entete(), [
            ['materiel_id' => $this->urnes->id, 'quantite_commandee' => 400, 'cycle_id' => $this->cycle->id],
        ]);
        app(CommandeService::class)->confirmer($commande);

        $this->assertSame(600, $this->besoin()->approvisionnementRestant($this->cycle, $this->urnes));
    }

    public function test_approvisionnement_jamais_negatif(): void
    {
        $this->planUrnes();
        $this->stock()->inventaireInitial($this->urnes, 1500, 'Stock existant');
        $this->stock()->attribuer($this->cycle, $this->urnes, 1500, 'Pour le cycle');

        $this->assertSame(0, $this->besoin()->approvisionnementRestant($this->cycle, $this->urnes));
    }

    public function test_expedie_et_surplus(): void
    {
        $this->planUrnes();
        $this->stock()->inventaireInitial($this->urnes, 1000, 'Stock existant');
        $reservation = $this->stock()->reserver($this->cycle, $this->urnes, 1000);
        $reservation->update(['statut' => 'consommee']);
        $mouvement = $this->stock()->enregistrer($this->urnes, 'sortie_expedition', -1000, $reservation);

        // La référence d'une sortie est la table de la réservation : convention des plans suivants.
        $this->assertSame('reservations_stock', $mouvement->reference_type);
        $this->assertSame($reservation->id, $mouvement->reference_id);

        $this->assertSame(1000, $this->besoin()->expedie($this->cycle, $this->urnes));
        $this->assertSame(0, $this->besoin()->resteAExpedier($this->cycle, $this->urnes));
        $this->assertSame(0, $this->besoin()->surplusExpedie($this->cycle, $this->urnes));

        $this->planApprouve([[$this->urnes, 800]], 2);

        $this->assertSame(800, $this->besoin()->besoinExpedition($this->cycle, $this->urnes));
        $this->assertSame(1000, $this->besoin()->expedie($this->cycle, $this->urnes));
        $this->assertSame(0, $this->besoin()->resteAExpedier($this->cycle, $this->urnes));
        $this->assertSame(200, $this->besoin()->surplusExpedie($this->cycle, $this->urnes));
    }

    public function test_expedie_ignore_les_autres_cycles_articles_et_types(): void
    {
        $this->planApprouve([[$this->urnes, 1000], [$this->isoloirs, 50]]);
        $autre = Cycle::create(['libelle' => 'Cycle B', 'type' => 'scrutin']);
        $this->stock()->inventaireInitial($this->urnes, 1000, 'Stock existant');
        $this->stock()->inventaireInitial($this->isoloirs, 100, 'Stock existant');

        $reservation = $this->stock()->reserver($this->cycle, $this->urnes, 300);
        $reservation->update(['statut' => 'consommee']);
        $this->stock()->enregistrer($this->urnes, 'sortie_expedition', -300, $reservation);
        $autreCycle = $this->stock()->reserver($autre, $this->urnes, 200);
        $autreCycle->update(['statut' => 'consommee']);
        $this->stock()->enregistrer($this->urnes, 'sortie_expedition', -200, $autreCycle);
        $this->stock()->enregistrer($this->urnes, 'reforme', -10);
        $autreArticle = $this->stock()->reserver($this->cycle, $this->isoloirs, 40);
        $autreArticle->update(['statut' => 'consommee']);
        $this->stock()->enregistrer($this->isoloirs, 'sortie_expedition', -40, $autreArticle);

        $this->assertSame(300, $this->besoin()->expedie($this->cycle, $this->urnes));
        $this->assertSame(700, $this->besoin()->resteAExpedier($this->cycle, $this->urnes));
        $this->assertSame(40, $this->besoin()->expedie($this->cycle, $this->isoloirs));
        $this->assertSame(200, $this->besoin()->expedie($autre, $this->urnes));
    }

    public function test_sortie_compensee_n_est_plus_expediee(): void
    {
        $this->planUrnes();
        $this->stock()->inventaireInitial($this->urnes, 1000, 'Stock existant');
        $reservation = $this->stock()->reserver($this->cycle, $this->urnes, 1000);
        $reservation->update(['statut' => 'consommee']);
        $sortie = $this->stock()->enregistrer($this->urnes, 'sortie_expedition', -1000, $reservation);
        $this->assertSame(1000, $this->besoin()->expedie($this->cycle, $this->urnes));

        $this->stock()->compenser($sortie, 'Saisie erronée');

        $this->assertSame(0, $this->besoin()->expedie($this->cycle, $this->urnes));
        $this->assertSame(1000, $this->besoin()->resteAExpedier($this->cycle, $this->urnes));
        $this->assertSame(0, $this->besoin()->surplusExpedie($this->cycle, $this->urnes));
    }

    public function test_compensation_d_un_autre_mouvement_ne_change_pas_l_expedie(): void
    {
        $this->planUrnes();
        $this->stock()->inventaireInitial($this->urnes, 1000, 'Stock existant');
        $this->stock()->enregistrer($this->urnes, 'reforme', -10);
        $reservation = $this->stock()->reserver($this->cycle, $this->urnes, 300);
        $reservation->update(['statut' => 'consommee']);
        $this->stock()->enregistrer($this->urnes, 'sortie_expedition', -300, $reservation);
        $reforme = MouvementStock::where('type', 'reforme')->sole();
        $this->stock()->compenser($reforme, 'Réforme annulée');

        $this->assertSame(300, $this->besoin()->expedie($this->cycle, $this->urnes));
    }

    public function test_sortie_d_une_reservation_consommee_compte(): void
    {
        $this->planUrnes();
        $this->stock()->inventaireInitial($this->urnes, 500, 'Stock existant');
        $reservation = $this->stock()->reserver($this->cycle, $this->urnes, 200);
        $reservation->update(['statut' => 'consommee']);
        $this->stock()->enregistrer($this->urnes, 'sortie_expedition', -200, $reservation);

        $this->assertSame('consommee', $reservation->fresh()->statut);
        $this->assertSame(200, $this->besoin()->expedie($this->cycle, $this->urnes));
    }

    public function test_sans_plan_approuve_tout_est_nul(): void
    {
        $this->assertSame(0, $this->besoin()->besoinExpedition($this->cycle, $this->urnes));
        $this->assertSame(0, $this->besoin()->reserveCentraleCible($this->cycle, $this->urnes));
        $this->assertSame(0, $this->besoin()->approvisionnementRestant($this->cycle, $this->urnes));
        $this->assertCount(0, $this->besoin()->tableau($this->cycle));
    }

    public function test_un_brouillon_ne_compte_pas(): void
    {
        $plan = $this->planUrnes();
        $plan->forceFill(['statut' => 'brouillon'])->save();

        $this->assertSame(0, $this->besoin()->besoinExpedition($this->cycle, $this->urnes));
        $this->assertCount(0, $this->besoin()->tableau($this->cycle));
    }

    public function test_reserve_cible_arrondie_au_superieur_sur_la_base_exacte(): void
    {
        // Dotation approuvée 1101 (majorée), exact 1001 : la réserve se calcule sur l'exact.
        $this->planApprouve([[$this->urnes, 1101, '5.00', '1001']]);
        $this->assertSame(51, $this->besoin()->reserveCentraleCible($this->cycle, $this->urnes));

        $this->planApprouve([[$this->urnes, 1101, '2.50', '1000.0000']], 2);
        $this->assertSame(25, $this->besoin()->reserveCentraleCible($this->cycle, $this->urnes));
    }

    public function test_reserve_cible_somme_les_lignes_exactes_avant_le_plafond(): void
    {
        $plan = $this->planApprouve([[$this->urnes, 10, '10.00', '4.5000']]);
        $autre = Destinataire::create(['type' => 'prefecture', 'libelle' => 'Préfecture de Mbour']);
        PlanDotationLigne::create([
            'plan_dotation_id' => $plan->id, 'destinataire_id' => $autre->id, 'materiel_id' => $this->urnes->id,
            'effectif' => 5, 'exact' => '5.2500', 'majoration' => '0.00', 'minimum' => 0,
            'arrondi' => 'superieur', 'dotation_calculee' => 6,
        ])->forceFill(['dotation_approuvee' => 6])->save();

        // 9.75 x 10 % = 0.975 : plafond 1 (et non 1 + 1 en arrondissant chaque ligne).
        $this->assertSame(16, $this->besoin()->besoinExpedition($this->cycle, $this->urnes));
        $this->assertSame(1, $this->besoin()->reserveCentraleCible($this->cycle, $this->urnes));
    }

    public function test_reserve_cible_suit_le_plan_et_non_la_cle_courante(): void
    {
        $this->planUrnes('5.00');
        app(CleService::class)->enregistrer($this->cycle, $this->urnes, [
            'base' => 'bureau_vote', 'ratio' => '1', 'reserve_centrale' => '20', 'cle_affichee' => '1 par bureau de vote',
        ]);

        $this->assertSame(50, $this->besoin()->reserveCentraleCible($this->cycle, $this->urnes));
    }

    public function test_reserve_absente_du_plan_vaut_zero(): void
    {
        $plan = $this->planUrnes('5.00');
        $plan->update(['parametres' => []]);

        $this->assertSame(0, $this->besoin()->reserveCentraleCible($this->cycle, $this->urnes));
        $this->assertSame(1000, $this->besoin()->approvisionnementRestant($this->cycle, $this->urnes));
    }

    public function test_tableau_une_ligne_par_article_du_plan_dans_l_ordre_du_catalogue(): void
    {
        $this->planApprouve([[$this->isoloirs, 200], [$this->urnes, 1000, '5.00']]);
        Materiel::create(['libelle' => 'Hors plan', 'categorie' => 'materiel_lourd', 'unite_stock' => 'pièce']);
        $this->stock()->inventaireInitial($this->urnes, 300, 'Stock existant');
        $this->stock()->attribuer($this->cycle, $this->urnes, 200, 'Pour le cycle');
        $this->stock()->reserver($this->cycle, $this->urnes, 100);

        $tableau = $this->besoin()->tableau($this->cycle);

        $this->assertCount(2, $tableau);
        $this->assertSame(['Urnes', 'Isoloirs'], $tableau->map(fn ($l) => $l['materiel']->libelle)->all());
        $this->assertTrue($tableau[0]['materiel']->is($this->urnes));

        $this->assertSame([
            'besoin' => 1000, 'expedie' => 0, 'reste' => 1000, 'surplus' => 0, 'reserve_cible' => 50,
            'reserve_stock' => 100, 'attribue' => 100, 'a_recevoir' => 0, 'approvisionnement' => 850,
        ], collect($tableau[0])->except('materiel')->all());
        $this->assertSame([
            'besoin' => 200, 'expedie' => 0, 'reste' => 200, 'surplus' => 0, 'reserve_cible' => 0,
            'reserve_stock' => 0, 'attribue' => 0, 'a_recevoir' => 0, 'approvisionnement' => 200,
        ], collect($tableau[1])->except('materiel')->all());
    }

    public function test_tableau_prend_le_besoin_cumule_remplacements_et_clotures_compris(): void
    {
        $this->planApprouve([[$this->urnes, 1000], [$this->isoloirs, 200]]);
        $autre = Destinataire::create(['type' => 'prefecture', 'libelle' => 'Préfecture de Kaolack']);
        $enveloppes = Materiel::create(['libelle' => 'Enveloppes', 'categorie' => 'imprime', 'unite_stock' => 'pièce', 'ordre' => 3]);
        $remplacer = fn (Destinataire $d, Materiel $m, int $q) => BesoinRemplacement::create([
            'cycle_id' => $this->cycle->id, 'destinataire_id' => $d->id, 'materiel_id' => $m->id,
            'quantite' => $q, 'motif' => 'Détériorés', 'autorise_par' => $this->responsable->id,
        ]);
        $remplacer($this->destinataire, $this->urnes, 30);
        $remplacer($autre, $this->urnes, 5);
        // Article absent du plan : seul le remplacement porte un besoin.
        $remplacer($this->destinataire, $enveloppes, 12);
        Arbitrage::create([
            'cycle_id' => $this->cycle->id, 'objet' => 'cloture_ecart', 'destinataire_id' => $this->destinataire->id,
            'materiel_id' => $this->isoloirs->id, 'decision' => 'ecart_accepte',
            'quantite_expedition_annulee' => 50, 'motif' => 'Écart accepté', 'user_id' => $this->responsable->id,
        ]);

        $tableau = $this->besoin()->tableau($this->cycle)->keyBy(fn ($l) => $l['materiel']->id);

        $this->assertSame([$this->urnes->id, $this->isoloirs->id, $enveloppes->id], $tableau->keys()->all());
        foreach ([$this->urnes, $this->isoloirs, $enveloppes] as $article) {
            $this->assertSame($this->besoin()->besoinExpedition($this->cycle, $article), $tableau[$article->id]['besoin']);
            $this->assertSame($this->besoin()->resteAExpedier($this->cycle, $article), $tableau[$article->id]['reste']);
            $this->assertSame($this->besoin()->surplusExpedie($this->cycle, $article), $tableau[$article->id]['surplus']);
        }
        $this->assertSame([1035, 150, 12], [$tableau[$this->urnes->id]['besoin'], $tableau[$this->isoloirs->id]['besoin'], $tableau[$enveloppes->id]['besoin']]);
        $this->assertSame(0, $tableau[$enveloppes->id]['reserve_cible']);
        $this->assertSame(12, $tableau[$enveloppes->id]['approvisionnement']);
    }

    private function planDeuxArticles(): void
    {
        $this->planApprouve([[$this->urnes, 1000, '5.00'], [$this->isoloirs, 200]]);
    }

    public function test_conversion_ignore_les_lignes_nulles(): void
    {
        $this->planDeuxArticles();

        $commande = $this->besoin()->convertirEnCommande(
            $this->cycle, $this->entete(), [$this->urnes->id => 300, $this->isoloirs->id => 0], 'Budget limité',
        );

        $this->assertSame('brouillon', $commande->statut);
        $this->assertSame('CMD-A', $commande->reference);
        $this->assertCount(1, $commande->lignes);
        $ligne = $commande->lignes->first();
        $this->assertSame($this->urnes->id, $ligne->materiel_id);
        $this->assertSame(300, $ligne->quantite_commandee);
        $this->assertSame($this->cycle->id, $ligne->cycle_id);
    }

    public function test_conversion_a_la_proposition_sans_motif_et_journalisee(): void
    {
        $this->planDeuxArticles();

        $commande = $this->besoin()->convertirEnCommande(
            $this->cycle, $this->entete(), [$this->urnes->id => 1050, $this->isoloirs->id => 200], null,
        );

        $this->assertSame([1050, 200], $commande->lignes->sortBy('materiel_id')->pluck('quantite_commandee')->values()->all());
        $this->assertSame([$this->cycle->id], $commande->lignes->pluck('cycle_id')->unique()->values()->all());

        $entree = JournalEntree::where('objet_type', 'commande')->where('objet_id', $commande->id)->where('action', 'proposition_commande')->sole();
        $this->assertSame($this->responsable->id, $entree->user_id);
        $this->assertSame([$this->urnes->id => 1050, $this->isoloirs->id => 200], $entree->avant);
        $this->assertSame([$this->urnes->id => 1050, $this->isoloirs->id => 200], $entree->apres);
        $this->assertNull($entree->motif);
    }

    public function test_conversion_motif_si_quantites_modifiees(): void
    {
        $this->planDeuxArticles();
        $quantites = [$this->urnes->id => 900, $this->isoloirs->id => 200];

        foreach ([null, '', '   '] as $motif) {
            try {
                $this->besoin()->convertirEnCommande($this->cycle, $this->entete(), $quantites, $motif);
                $this->fail('Le motif est obligatoire quand les quantités diffèrent.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Motif obligatoire : quantités différentes de la proposition.', $e->getMessage());
            }
        }
        $this->assertSame(0, Commande::count());

        $commande = $this->besoin()->convertirEnCommande($this->cycle, $this->entete(), $quantites, '  Livraison partielle ');

        $entree = JournalEntree::where('objet_type', 'commande')->where('objet_id', $commande->id)->sole();
        $this->assertSame('Livraison partielle', $entree->motif);
        $this->assertSame([$this->urnes->id => 1050, $this->isoloirs->id => 200], $entree->avant);
        $this->assertSame([$this->urnes->id => 900, $this->isoloirs->id => 200], $entree->apres);
    }

    public function test_un_article_omis_est_une_modification(): void
    {
        $this->planDeuxArticles();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Motif obligatoire : quantités différentes de la proposition.');
        $this->besoin()->convertirEnCommande($this->cycle, $this->entete(), [$this->urnes->id => 1050], null);
    }

    public function test_un_article_hors_proposition_est_une_modification(): void
    {
        $this->planUrnes();
        $this->stock()->inventaireInitial($this->urnes, 1000, 'Stock existant');
        $this->stock()->attribuer($this->cycle, $this->urnes, 1000, 'Pour le cycle');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Motif obligatoire');
        $this->besoin()->convertirEnCommande($this->cycle, $this->entete(), [$this->urnes->id => 10], null);
    }

    public function test_conversion_refusee_au_gestionnaire(): void
    {
        $this->planDeuxArticles();
        $this->actingAs(User::factory()->create(['role' => 'logistique_gestionnaire']));

        try {
            $this->besoin()->convertirEnCommande($this->cycle, $this->entete(), [$this->urnes->id => 1050, $this->isoloirs->id => 200], null);
            $this->fail('Seul le responsable convertit une proposition.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Seul un responsable logistique peut affecter une commande à un cycle.', $e->getMessage());
        }
        $this->assertSame(0, Commande::count());
        $this->assertSame(0, JournalEntree::where('action', 'proposition_commande')->count());
    }

    public function test_rien_a_commander(): void
    {
        $this->planDeuxArticles();

        foreach ([[], [$this->urnes->id => 0, $this->isoloirs->id => 0]] as $quantites) {
            try {
                $this->besoin()->convertirEnCommande($this->cycle, $this->entete(), $quantites, 'Aucun achat');
                $this->fail('Une commande sans ligne positive est refusée.');
            } catch (RegleLogistique $e) {
                $this->assertSame('Rien à commander.', $e->getMessage());
            }
        }
        $this->assertSame(0, Commande::count());
    }

    public function test_quantites_invalides_refusees(): void
    {
        $this->planDeuxArticles();

        foreach ([[$this->urnes->id => -1], [$this->urnes->id => 1.5], [$this->urnes->id => 'abc'], [$this->urnes->id => null], ['x' => 10]] as $quantites) {
            try {
                $this->besoin()->convertirEnCommande($this->cycle, $this->entete(), $quantites, 'Motif');
                $this->fail('Quantité invalide acceptée : '.json_encode($quantites));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('entier', $e->getMessage());
            }
        }
        $this->assertSame(0, Commande::count());
    }

    public function test_motif_trop_long_refuse(): void
    {
        $this->planDeuxArticles();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('500 caractères');
        $this->besoin()->convertirEnCommande($this->cycle, $this->entete(), [$this->urnes->id => 10], str_repeat('a', 501));
    }

    public function test_commande_refusee_ne_laisse_ni_commande_ni_journal(): void
    {
        $this->planDeuxArticles();
        app(CommandeService::class)->creer($this->entete(), [['materiel_id' => $this->urnes->id, 'quantite_commandee' => 1]]);

        try {
            $this->besoin()->convertirEnCommande($this->cycle, $this->entete(), [$this->urnes->id => 1050, $this->isoloirs->id => 200], null);
            $this->fail('La référence est déjà prise.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('existe déjà', $e->getMessage());
        }
        $this->assertSame(1, Commande::count());
        $this->assertSame(0, JournalEntree::where('action', 'proposition_commande')->count());
    }

    public function test_conversion_sur_cycle_clos_refusee(): void
    {
        $this->planDeuxArticles();
        $this->stock()->cloturerCycle($this->cycle, 'Fin');

        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Cycle clôturé : commande impossible.');
        $this->besoin()->convertirEnCommande($this->cycle, $this->entete(), [$this->urnes->id => 1050, $this->isoloirs->id => 200], null);
    }
}

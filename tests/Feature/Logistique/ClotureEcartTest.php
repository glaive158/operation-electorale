<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Arbitrage;
use App\Models\Logistique\BesoinRemplacement;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\Remise;
use App\Models\User;
use App\Services\Logistique\ArbitrageService;
use App\Services\Logistique\BesoinService;
use App\Services\Logistique\EngagementService;
use App\Services\Logistique\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class ClotureEcartTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    private const MOTIF = 'Écart accepté après constat.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerDecorFictif();
        $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 100], [$this->prefecture, $this->isoloirs, 100],
        ]);
        $this->stockerFictif($this->urnes, 1000, 300);
        // Les isoloirs ne sont pas attribués au cycle : la proposition de commande reste à calculer.
        $this->stockerFictif($this->isoloirs, 1000);
    }

    private function cloturer(int $abandon, int $annulee, ?Materiel $article = null, ?Destinataire $destinataire = null, string $motif = self::MOTIF): Arbitrage
    {
        return app(ArbitrageService::class)->cloturerAvecEcart(
            $this->cycle, $destinataire ?? $this->prefecture, $article ?? $this->urnes, $abandon, $annulee, $motif,
        );
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

    /** Décharge de $quantite urnes chargée, puis reçue : $recu au total dont $defectueux défectueuses. */
    private function recue(int $quantite, int $recu, int $defectueux = 0): Decharge
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => $quantite]);
        $this->deposerRecue($decharge, [$this->urnes->id => ['recu' => $recu, 'defectueux' => $defectueux]]);

        return $decharge->refresh();
    }

    public function test_ecart_20_accepte_apres_80_expediees_et_recues(): void
    {
        $this->recue(80, 80);
        $engagement = app(EngagementService::class);
        $besoins = app(BesoinService::class);
        $this->assertSame(100, $engagement->besoin($this->cycle, $this->prefecture, $this->urnes));
        $this->assertSame(20, $besoins->resteAExpedier($this->cycle, $this->urnes));

        $arbitrage = $this->cloturer(20, 20);

        $this->assertSame('cloture_ecart', $arbitrage->objet);
        $this->assertSame('ecart_accepte', $arbitrage->decision);
        $this->assertSame([20, 20], [$arbitrage->quantite_abandon_reception, $arbitrage->quantite_expedition_annulee]);
        $this->assertSame([$this->cycle->id, $this->prefecture->id, $this->urnes->id], [$arbitrage->cycle_id, $arbitrage->destinataire_id, $arbitrage->materiel_id]);
        $this->assertNull($arbitrage->remise_id);
        $this->assertNull($arbitrage->declaration_id);
        $this->assertSame(self::MOTIF, $arbitrage->motif);
        $this->assertSame($this->responsable->id, $arbitrage->user_id);

        // Objectif 80, besoin 80, plus rien à expédier.
        $this->assertSame(80, $engagement->besoin($this->cycle, $this->prefecture, $this->urnes));
        $this->assertSame(0, $besoins->resteAExpedier($this->cycle, $this->urnes));

        $entree = JournalEntree::where('action', 'cloture_avec_ecart')->sole();
        $this->assertSame('arbitrage', $entree->objet_type);
        $this->assertSame($arbitrage->id, (int) $entree->objet_id);
        $this->assertSame(self::MOTIF, $entree->motif);
        $this->assertSame(20, $entree->apres['quantite_abandon_reception']);
        $this->assertSame(20, $entree->apres['quantite_expedition_annulee']);
        $this->assertSame(1, Arbitrage::count());
    }

    public function test_expedition_annulee_limitee_au_non_engage(): void
    {
        $this->deposerChargee($this->prefecture, [$this->urnes->id => 80]);

        $this->refus(fn () => $this->cloturer(0, 30),
            'Expédition annulée supérieure au non engagé pour Urnes : non engagé 20. Annulez d\'abord la décharge validée non partie.');
        $this->assertSame(0, Arbitrage::count());

        $this->cloturer(0, 20);
        $this->assertSame(80, app(EngagementService::class)->besoin($this->cycle, $this->prefecture, $this->urnes));

        // Le non engagé est épuisé : une nouvelle part est refusée.
        $this->refus(fn () => $this->cloturer(0, 1),
            'Expédition annulée supérieure au non engagé pour Urnes : non engagé 0. Annulez d\'abord la décharge validée non partie.');
        $this->assertSame(1, Arbitrage::count());
    }

    public function test_abandon_limite_au_reste_a_receptionner(): void
    {
        // 80 expédiées, 70 reçues dont 10 défectueuses : 60 utilisables, il reste 40 à réceptionner sur 100.
        $this->recue(80, 70, 10);

        $this->refus(fn () => $this->cloturer(41, 0), 'Abandon supérieur au reste à réceptionner pour Urnes : reste 40.');
        $this->assertSame(0, Arbitrage::count());

        $this->cloturer(40, 0);

        // L'objectif tient compte de l'abandon déjà accepté : 100 − 40 = 60, reçu 60.
        $this->refus(fn () => $this->cloturer(1, 0), 'Abandon supérieur au reste à réceptionner pour Urnes : reste 0.');
        $this->assertSame(1, Arbitrage::count());
    }

    public function test_un_remplacement_compte_dans_le_besoin_mais_pas_dans_l_objectif_de_reception(): void
    {
        BesoinRemplacement::create([
            'cycle_id' => $this->cycle->id, 'destinataire_id' => $this->prefecture->id, 'materiel_id' => $this->urnes->id,
            'quantite' => 10, 'motif' => 'Urnes détruites', 'autorise_par' => $this->responsable->id,
        ]);
        $engagement = app(EngagementService::class);
        $this->assertSame(110, $engagement->besoin($this->cycle, $this->prefecture, $this->urnes));

        // 80 expédiées, 60 utilisables : l'objectif reste la dotation (100), le reste est 40 et non 50.
        $this->recue(80, 60);
        $this->refus(fn () => $this->cloturer(41, 0), 'Abandon supérieur au reste à réceptionner pour Urnes : reste 40.');

        // Le non engagé, lui, porte le remplacement : 110 − 80 = 30.
        $this->refus(fn () => $this->cloturer(0, 31),
            'Expédition annulée supérieure au non engagé pour Urnes : non engagé 30. Annulez d\'abord la décharge validée non partie.');
        $this->cloturer(40, 30);
        $this->assertSame(80, $engagement->besoin($this->cycle, $this->prefecture, $this->urnes));
    }

    public function test_seules_les_declarations_retenues_au_destinataire_final_comptent(): void
    {
        // Décharge de la sous-préfecture 1 via le préfet : la livraison au préfet n'est pas une réception finale.
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->sousPrefecture1, $this->urnes, 100]]);
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => 100]);

        // Rien n'est encore reçu par la sous-préfecture : tout l'objectif reste à réceptionner.
        $this->cloturer(100, 0, $this->urnes, $this->sousPrefecture1);
        $this->assertSame(1, Arbitrage::count());
        $this->refus(fn () => $this->cloturer(1, 0, $this->urnes, $this->sousPrefecture1),
            'Abandon supérieur au reste à réceptionner pour Urnes : reste 0.');
    }

    public function test_reserve_au_responsable(): void
    {
        foreach (['logistique_gestionnaire', 'prefet', 'agent_transport'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->refus(fn () => $this->cloturer(0, 10), 'Seul un responsable logistique peut clôturer un écart.');
        }
        $this->assertSame(0, Arbitrage::count());
        $this->assertSame(0, JournalEntree::where('action', 'cloture_avec_ecart')->count());
    }

    public function test_motif_et_quantites_valides(): void
    {
        foreach ([[0, 10, ''], [0, 10, '   '], [0, 10, str_repeat('a', 501)]] as [$abandon, $annulee, $motif]) {
            try {
                $this->cloturer($abandon, $annulee, null, null, $motif);
                $this->fail('Le motif aurait dû être refusé.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Le motif est obligatoire (500 caractères au plus).', $e->getMessage());
            }
        }
        foreach ([[-1, 10], [10, -1], [0, 0]] as [$abandon, $annulee]) {
            try {
                $this->cloturer($abandon, $annulee);
                $this->fail('Les quantités auraient dû être refusées.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Les quantités doivent être positives et au moins l\'une d\'elles non nulle.', $e->getMessage());
            }
        }
        $this->assertSame(0, Arbitrage::count());
    }

    public function test_cycle_cloture_refuse_la_cloture_d_ecart(): void
    {
        Cycle::whereKey($this->cycle->id)->update(['statut' => 'clos']);

        $this->refus(fn () => $this->cloturer(0, 10), 'Cycle clôturé : clôture d\'écart impossible.');
        $this->assertSame(0, Arbitrage::count());
    }

    public function test_proposition_de_commande_reduite(): void
    {
        $besoins = app(BesoinService::class);
        $avant = $besoins->approvisionnementRestant($this->cycle, $this->isoloirs);
        $this->assertSame(100, $avant);

        $this->cloturer(0, 30, $this->isoloirs);

        $this->assertSame(70, $besoins->approvisionnementRestant($this->cycle, $this->isoloirs));
    }

    public function test_cloture_du_cycle_refusee_tant_qu_une_remise_attend(): void
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 80]);
        $stock = app(StockService::class);
        $message = 'Des remises sont encore en attente de réception ou à vérifier : déclarez, arbitrez ou clôturez avec écart avant de clôturer le cycle.';

        // Livraison en attente.
        $this->refus(fn () => $stock->cloturerCycle($this->cycle, 'Fin'), $message);
        $this->assertTrue($this->cycle->refresh()->estOuvert());

        // Même avec un écart accepté : la remise de livraison attend toujours sa déclaration.
        $this->cloturer(0, 20);
        $this->refus(fn () => $stock->cloturerCycle($this->cycle, 'Fin'), $message);

        // Livraison à vérifier (excédent).
        $this->deposerRecue($decharge, [$this->urnes->id => 90]);
        $this->assertSame('a_verifier', Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->value('statut'));
        $this->refus(fn () => $stock->cloturerCycle($this->cycle, 'Fin'), $message);
        $this->assertTrue($this->cycle->refresh()->estOuvert());
    }

    public function test_cloture_du_cycle_acceptee_quand_les_remises_sont_recues(): void
    {
        $decharge = $this->recue(80, 80);
        $this->cloturer(20, 20);
        $this->assertSame(['recue', 'recue'], Remise::where('decharge_id', $decharge->id)->orderBy('id')->pluck('statut')->all());

        app(StockService::class)->cloturerCycle($this->cycle, 'Fin du scrutin');

        $this->assertFalse($this->cycle->refresh()->estOuvert());
    }

    public function test_une_remise_annulee_ne_bloque_pas_la_cloture(): void
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 80]);
        $this->deposerRecue($decharge, [$this->urnes->id => 80]);
        Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->update(['statut' => 'annulee']);

        app(StockService::class)->cloturerCycle($this->cycle, 'Fin');

        $this->assertFalse($this->cycle->refresh()->estOuvert());
    }
}

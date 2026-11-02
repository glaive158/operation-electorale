<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Arbitrage;
use App\Models\Logistique\BesoinRemplacement;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\Remise;
use App\Models\User;
use App\Services\Logistique\ArbitrageService;
use App\Services\Logistique\BesoinService;
use App\Services\Logistique\DeclarationService;
use App\Services\Logistique\EngagementService;
use App\Services\Logistique\RemiseService;
use App\Services\Logistique\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_ce_qui_attend_chez_le_prefet_pour_un_sous_prefet_ne_compte_pas_pour_le_prefet(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->sousPrefecture1, $this->urnes, 200]]);
        // Deux décharges via le préfet pour la sous-préfecture 1 : l'une reçue (100), l'autre à vérifier (excédent).
        $recue = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($recue, [$this->urnes->id => 100]);
        $aVerifier = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 50]);
        $this->deposerRecue($aVerifier, [$this->urnes->id => 60]);
        $this->assertSame('a_verifier', Remise::where('decharge_id', $aVerifier->id)->where('etape', 'livraison')->value('statut'));

        // Les 100 reçues par le préfet pour le sous-préfet ne réduisent pas le reste du préfet, et la
        // réception à vérifier de la sous-préfecture ne bloque pas l'abandon du préfet.
        $this->cloturer(100, 0, $this->urnes, $this->prefecture);
        $this->assertSame(1, Arbitrage::count());
        $this->refus(fn () => $this->cloturer(1, 0, $this->urnes, $this->prefecture),
            'Abandon supérieur au reste à réceptionner pour Urnes : reste 0.');
    }

    public function test_la_redistribution_retenue_reduit_le_reste_du_sous_prefet(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->sousPrefecture1, $this->urnes, 100]]);
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => 100]);

        // Reçu par le préfet seulement : rien n'est encore reçu par la sous-préfecture.
        $this->assertSame(0, Arbitrage::count());

        $prefet = $this->autoriteFictive($this->prefecture);
        $this->actingAs($prefet);
        $redistribution = app(RemiseService::class)->preparerRedistribution($decharge->refresh(), [$this->urnes->id => 80]);
        $this->actingAs($this->autoriteFictive($this->sousPrefecture1));
        app(DeclarationService::class)->declarer(
            $redistribution, 'compte', ['nom' => 'Ibrahima Ba', 'qualite' => 'Sous-préfet'],
            [$this->urnes->id => ['recu' => 80, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(),
        );
        $this->actingAs($this->responsable);

        // 100 de dotation, 80 retenus à la sous-préfecture : reste 20.
        $this->refus(fn () => $this->cloturer(21, 0, $this->urnes, $this->sousPrefecture1),
            'Abandon supérieur au reste à réceptionner pour Urnes : reste 20.');
        $this->cloturer(20, 0, $this->urnes, $this->sousPrefecture1);
        $this->assertSame(1, Arbitrage::count());
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

    public function test_le_cycle_se_cloture_avec_une_livraison_en_attente(): void
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 80]);
        $this->assertSame('en_attente', Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->value('statut'));

        app(StockService::class)->cloturerCycle($this->cycle, 'Fin du scrutin');

        $this->assertFalse($this->cycle->refresh()->estOuvert());
        $this->assertSame('en_attente', Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->value('statut'));
    }

    public function test_abandon_refuse_tant_qu_une_reception_est_a_verifier(): void
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 80]);
        $this->deposerRecue($decharge, [$this->urnes->id => 90]);
        $this->assertSame('a_verifier', Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->value('statut'));
        $message = 'Une réception est à vérifier pour Urnes : arbitrez-la avant d\'accepter un écart.';

        $this->refus(fn () => $this->cloturer(10, 0), $message);
        $this->refus(fn () => $this->cloturer(10, 20), $message);
        $this->assertSame(0, Arbitrage::count());

        // Une expédition annulée seule ne dépend pas du reçu : elle reste permise.
        $this->cloturer(0, 20);
        // Un autre article n'est pas concerné par la réception à vérifier.
        $this->cloturer(10, 0, $this->isoloirs);

        // Une fois la réception arbitrée, l'abandon est de nouveau accepté.
        $declaration = Declaration::where('decharge_id', $decharge->id)->sole();
        app(ArbitrageService::class)->rejeter($declaration, 'Excédent impossible.');
        $this->cloturer(10, 0);
        $this->assertSame(3, Arbitrage::where('objet', 'cloture_ecart')->count());
    }
}

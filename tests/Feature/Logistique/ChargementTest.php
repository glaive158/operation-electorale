<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Exceptions\Logistique\StockInsuffisant;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\MouvementStock;
use App\Models\Logistique\Remise;
use App\Models\Logistique\ReservationStock;
use App\Models\User;
use App\Services\Logistique\BesoinService;
use App\Services\Logistique\DechargeService;
use App\Services\Logistique\RemiseService;
use App\Services\Logistique\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class ChargementTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerDecorFictif();
        $this->stock = app(StockService::class);
    }

    /** Décharge validée de $quantite urnes pour $destinataire, avec un agent affecté si demandé. */
    private function dechargeValidee(int $quantite, $destinataire = null, bool $avecAgent = true): Decharge
    {
        $service = app(DechargeService::class);
        $decharge = $service->preparer($this->cycle, $destinataire ?? $this->prefecture, 'materiel_lourd');
        foreach ($decharge->lignes as $ligne) {
            $service->modifierLigne($ligne, $ligne->materiel_id === $this->urnes->id ? $quantite : 0, null);
        }
        $service->valider($decharge);
        if ($avecAgent) {
            $service->affecterAgent($decharge, User::factory()->create(['role' => 'agent_transport']), 'Tournée');
        }

        return $decharge->refresh();
    }

    private function remises(Decharge $decharge, string $etape): Remise
    {
        return Remise::where('decharge_id', $decharge->id)->where('etape', $etape)->with('lignes')->firstOrFail();
    }

    public function test_chargement_consomme_la_reservation_et_sort_du_stock(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 100);
        $decharge = $this->dechargeValidee(60);
        $reservationId = $decharge->lignes->first()->reservation_id;

        app(RemiseService::class)->charger($decharge);

        $this->assertSame(440, $this->stock->physique($this->urnes));
        $this->assertSame(0, $this->stock->reserve($this->urnes, $this->cycle));
        $reservation = ReservationStock::findOrFail($reservationId);
        $this->assertSame('consommee', $reservation->statut);
        $sortie = MouvementStock::where('type', 'sortie_expedition')->where('materiel_id', $this->urnes->id)->sole();
        $this->assertSame(-60, $sortie->quantite);
        $this->assertSame('bon', $sortie->etat);
        $this->assertSame('reservations_stock', $sortie->reference_type);
        $this->assertSame($reservation->id, $sortie->reference_id);
    }

    public function test_chargement_du_dernier_stock_reserve(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 100);
        $decharge = $this->dechargeValidee(100);
        $this->assertSame(100, $this->stock->reserve($this->urnes, $this->cycle));

        app(RemiseService::class)->charger($decharge);

        $this->assertSame(0, $this->stock->physique($this->urnes));
        $this->assertSame(0, $this->stock->reserve($this->urnes, $this->cycle));
    }

    public function test_expedie_suit_le_chargement(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 100);
        $decharge = $this->dechargeValidee(60);
        $this->assertSame(0, app(BesoinService::class)->expedie($this->cycle, $this->urnes));

        app(RemiseService::class)->charger($decharge);

        $this->assertSame(60, app(BesoinService::class)->expedie($this->cycle, $this->urnes));
    }

    public function test_chargement_cree_chargement_et_livraison(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 100);
        $decharge = $this->dechargeValidee(60);

        $retour = app(RemiseService::class)->charger($decharge);

        $decharge->refresh();
        $this->assertSame('chargee', $decharge->statut_acheminement);
        $chargement = $this->remises($decharge, 'chargement');
        $this->assertTrue($retour->is($chargement));
        $this->assertSame('magasin', $chargement->de_type);
        $this->assertNull($chargement->de_id);
        $this->assertSame('agent', $chargement->a_type);
        $this->assertSame($decharge->agent_id, (int) $chargement->a_id);
        $this->assertSame('recue', $chargement->statut);
        $this->assertSame($this->responsable->id, (int) $chargement->preparee_par);
        $this->assertSame([[$this->urnes->id, 60]], $chargement->lignes->map(fn ($l) => [$l->materiel_id, $l->quantite])->all());

        $livraison = $this->remises($decharge, 'livraison');
        $this->assertSame('agent', $livraison->de_type);
        $this->assertSame($decharge->agent_id, (int) $livraison->de_id);
        $this->assertSame('destinataire', $livraison->a_type);
        $this->assertSame($this->prefecture->id, (int) $livraison->a_id);
        $this->assertSame('en_attente', $livraison->statut);
        $this->assertSame([[$this->urnes->id, 60]], $livraison->lignes->map(fn ($l) => [$l->materiel_id, $l->quantite])->all());

        $entree = JournalEntree::where('action', 'chargement_decharge')->sole();
        $this->assertSame($decharge->id, (int) $entree->objet_id);
        $this->assertSame('a_preparer', $entree->avant['statut_acheminement']);
        $this->assertSame('chargee', $entree->apres['statut_acheminement']);
        $this->assertSame(60, $entree->apres['lignes'][0]['quantite']);
    }

    public function test_via_prefet_livraison_au_prefet(): void
    {
        $this->approuverPlanFictif([[$this->sousPrefecture1, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 100);
        $decharge = $this->dechargeValidee(30, $this->sousPrefecture1);
        $this->assertSame($this->prefecture->id, (int) $decharge->remis_a_id);

        app(RemiseService::class)->charger($decharge);

        $livraison = $this->remises($decharge, 'livraison');
        $this->assertSame($this->prefecture->id, (int) $livraison->a_id);
    }

    public function test_sans_agent_refuse(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 100);
        $decharge = $this->dechargeValidee(60, null, false);

        try {
            app(RemiseService::class)->charger($decharge);
            $this->fail('Le chargement sans agent aurait dû être refusé.');
        } catch (RegleLogistique $e) {
            $this->assertStringContainsString('Affectez un agent', $e->getMessage());
        }

        $this->assertSame('a_preparer', $decharge->refresh()->statut_acheminement);
        $this->assertSame(0, Remise::count());
        $this->assertSame(60, $this->stock->reserve($this->urnes, $this->cycle));
    }

    public function test_etranger_avec_transporteur(): void
    {
        $this->approuverPlanFictif([[$this->poste, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 100);
        $decharge = $this->dechargeValidee(40, $this->poste, false);
        $this->assertSame('etranger', $decharge->circuit);
        $transporteur = ['nom' => 'Moussa Ndiaye', 'qualite' => 'Chauffeur', 'societe' => 'Fret Express', 'bordereau' => 'BL-2026-001'];

        app(RemiseService::class)->charger($decharge, $transporteur);

        $this->assertSame('chargee', $decharge->refresh()->statut_acheminement);
        $chargement = $this->remises($decharge, 'chargement');
        $this->assertSame('transporteur', $chargement->a_type);
        $this->assertNull($chargement->a_id);
        $this->assertSame('Moussa Ndiaye', $chargement->transporteur_nom);
        $this->assertSame('Chauffeur', $chargement->transporteur_qualite);
        $this->assertSame('Fret Express', $chargement->transporteur_societe);
        $this->assertSame('BL-2026-001', $chargement->bordereau_numero);
        $livraison = $this->remises($decharge, 'livraison');
        $this->assertSame('transporteur', $livraison->de_type);
        $this->assertSame($this->poste->id, (int) $livraison->a_id);
    }

    public function test_etranger_transporteur_incomplet_refuse(): void
    {
        $this->approuverPlanFictif([[$this->poste, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 100);
        $decharge = $this->dechargeValidee(40, $this->poste, false);

        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Affectez un agent');
        app(RemiseService::class)->charger($decharge, ['nom' => 'Moussa Ndiaye', 'qualite' => 'Chauffeur']);
    }

    public function test_annulation_impossible_apres_chargement(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 100);
        $decharge = $this->dechargeValidee(60);
        app(RemiseService::class)->charger($decharge);

        try {
            app(DechargeService::class)->annuler($decharge, 'Trop tard');
            $this->fail("L'annulation d'une décharge chargée aurait dû être refusée.");
        } catch (RegleLogistique $e) {
            $this->assertStringContainsString('déjà partie', $e->getMessage());
        }
    }

    public function test_double_chargement_refuse(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 100);
        $decharge = $this->dechargeValidee(60);
        app(RemiseService::class)->charger($decharge);

        try {
            app(RemiseService::class)->charger($decharge);
            $this->fail('Un second chargement aurait dû être refusé.');
        } catch (RegleLogistique $e) {
            $this->assertStringContainsString('validée non partie', $e->getMessage());
        }

        $this->assertSame(2, Remise::count());
        $this->assertSame(1, MouvementStock::where('type', 'sortie_expedition')->count());
    }

    public function test_chargement_atomique(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->prefecture, $this->isoloirs, 50]]);
        $this->stockerFictif($this->urnes, 500, 100);
        $this->stockerFictif($this->isoloirs, 500, 50);
        $service = app(DechargeService::class);
        $decharge = $service->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        foreach ($decharge->lignes as $ligne) {
            $service->modifierLigne($ligne, 20, null);
        }
        $service->valider($decharge);
        $service->affecterAgent($decharge, User::factory()->create(['role' => 'agent_transport']), 'Tournée');

        // Rupture d'invariant : la réservation de la seconde ligne est libérée à la main.
        $lignes = $decharge->lignes()->orderBy('materiel_id')->get();
        $this->stock->liberer(ReservationStock::findOrFail($lignes[1]->reservation_id), 'Libération manuelle');

        try {
            app(RemiseService::class)->charger($decharge);
            $this->fail('Le chargement aurait dû échouer.');
        } catch (LogicException) {
        }

        $this->assertSame('active', ReservationStock::findOrFail($lignes[0]->reservation_id)->statut);
        $this->assertSame('a_preparer', $decharge->refresh()->statut_acheminement);
        $this->assertSame(0, Remise::count());
        $this->assertSame(0, MouvementStock::where('type', 'sortie_expedition')->count());
    }

    public function test_chargement_atomique_si_une_consommation_ulterieure_echoue(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->prefecture, $this->isoloirs, 50]]);
        $this->stockerFictif($this->urnes, 500, 100);
        $this->stockerFictif($this->isoloirs, 500, 50);
        $service = app(DechargeService::class);
        $decharge = $service->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        foreach ($decharge->lignes as $ligne) {
            $service->modifierLigne($ligne, 20, null);
        }
        $service->valider($decharge);
        $service->affecterAgent($decharge, User::factory()->create(['role' => 'agent_transport']), 'Tournée');
        $lignes = $decharge->lignes()->orderBy('materiel_id')->get();

        // La première consommation aboutit, la seconde échoue : tout doit être annulé.
        $this->app->bind(StockService::class, fn () => new class extends StockService
        {
            private int $appels = 0;

            public function consommer(ReservationStock $reservation): MouvementStock
            {
                if (++$this->appels === 2) {
                    throw new StockInsuffisant('Stock insuffisant (simulé).');
                }

                return parent::consommer($reservation);
            }
        });

        try {
            app(RemiseService::class)->charger($decharge);
            $this->fail('Le chargement aurait dû échouer.');
        } catch (StockInsuffisant) {
        }

        $this->assertSame('active', ReservationStock::findOrFail($lignes[0]->reservation_id)->statut);
        $this->assertSame('active', ReservationStock::findOrFail($lignes[1]->reservation_id)->statut);
        $this->assertSame(0, MouvementStock::where('type', 'sortie_expedition')->count());
        $this->assertSame(0, Remise::count());
        $this->assertSame('a_preparer', $decharge->refresh()->statut_acheminement);
    }

    public function test_transporteur_aux_champs_blancs_est_ignore(): void
    {
        $this->approuverPlanFictif([[$this->poste, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 100);
        $decharge = $this->dechargeValidee(40, $this->poste, false);

        // Des champs seulement blancs comptent comme absents : on retombe sur « Affectez un agent ».
        try {
            app(RemiseService::class)->charger($decharge, ['nom' => '   ', 'qualite' => " \t", 'societe' => '', 'bordereau' => null]);
            $this->fail('Le chargement sans porteur aurait dû être refusé.');
        } catch (RegleLogistique $e) {
            $this->assertStringContainsString('Affectez un agent', $e->getMessage());
        }
        $this->assertSame(0, Remise::count());

        // Même pour un circuit non étranger, un transporteur blanc n'est pas une erreur d'entrée.
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $locale = $this->dechargeValidee(10);
        app(RemiseService::class)->charger($locale, ['nom' => ' ', 'societe' => '  ']);
        $this->assertSame('agent', $this->remises($locale, 'chargement')->a_type);
    }

    public function test_chargement_reserve_au_personnel_logistique(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 100);
        $decharge = $this->dechargeValidee(60);
        $this->actingAs(User::factory()->create(['role' => 'agent_transport']));

        $this->expectException(RegleLogistique::class);
        app(RemiseService::class)->charger($decharge);
    }

    public function test_consommer_refuse_une_reservation_non_active(): void
    {
        $this->stockerFictif($this->urnes, 100);
        $reservation = $this->stock->reserver($this->cycle, $this->urnes, 10);
        $this->stock->liberer($reservation, 'Test');

        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Seule une réservation active peut être consommée.');
        $this->stock->consommer($reservation);
    }

    public function test_deposer_chargee_du_decor(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 100);

        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 25]);

        $this->assertSame('chargee', $decharge->statut_acheminement);
        $this->assertSame(25, $this->remises($decharge, 'chargement')->lignes->sole()->quantite);
    }
}

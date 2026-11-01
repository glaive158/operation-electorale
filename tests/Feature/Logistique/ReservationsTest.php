<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Exceptions\Logistique\StockInsuffisant;
use App\Models\Logistique\AttributionStock;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\MouvementStock;
use App\Models\Logistique\ReservationStock;
use App\Models\User;
use App\Services\Logistique\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class ReservationsTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    private User $auteur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stock = app(StockService::class);
        $this->auteur = User::factory()->create(['role' => 'logistique_gestionnaire']);
        $this->actingAs($this->auteur);
    }

    private function urne(int $inventaire): Materiel
    {
        $urne = Materiel::create(['libelle' => 'Urne', 'categorie' => 'materiel_lourd']);
        $this->stock->inventaireInitial($urne, $inventaire, 'Inventaire magasin');

        return $urne;
    }

    private function cycle(string $libelle): Cycle
    {
        return Cycle::create(['libelle' => $libelle, 'type' => 'scrutin']);
    }

    public function test_reservation_reduit_le_libre(): void
    {
        $urne = $this->urne(500);
        $a = $this->cycle('Cycle A');

        $r = $this->stock->reserver($a, $urne, 400);

        $this->assertSame(400, $this->stock->reserve($urne));
        $this->assertSame(400, $this->stock->reserve($urne, $a));
        $this->assertSame(100, $this->stock->libre($urne));
        $this->assertSame(500, $this->stock->physique($urne));
        $this->assertSame('active', $r->fresh()->statut);
        $this->assertSame($this->auteur->id, $r->user_id);
    }

    public function test_reservation_au_dela_du_libre_refusee(): void
    {
        $urne = $this->urne(500);
        $a = $this->cycle('Cycle A');
        $b = $this->cycle('Cycle B');
        $this->stock->reserver($a, $urne, 400);

        try {
            $this->stock->reserver($b, $urne, 200);
            $this->fail('Une StockInsuffisant était attendue.');
        } catch (StockInsuffisant $e) {
            $this->assertSame('Stock disponible insuffisant pour Urne : demandé 200, disponible 100', $e->getMessage());
        }

        $this->assertSame(100, $this->stock->libre($urne));
        $this->assertSame(1, ReservationStock::count());
    }

    public function test_deux_cycles_ne_comptent_pas_sur_le_meme_stock(): void
    {
        $urne = $this->urne(500);
        $a = $this->cycle('Cycle A');
        $b = $this->cycle('Cycle B');
        $this->stock->attribuer($a, $urne, 400, 'Dotation A');

        try {
            $this->stock->attribuer($b, $urne, 400, 'Dotation B');
            $this->fail('Une StockInsuffisant était attendue.');
        } catch (StockInsuffisant) {
            $this->assertSame(0, $this->stock->attribue($urne, $b));
        }

        $this->stock->attribuer($b, $urne, 100, 'Dotation B');

        $this->assertSame(400, $this->stock->attribue($urne, $a));
        $this->assertSame(100, $this->stock->attribue($urne, $b));
        $this->assertSame(500, $this->stock->attribue($urne));
        $this->assertSame(0, $this->stock->libreNonAttribue($urne));
    }

    public function test_attribuer_fixe_la_quantite_totale_du_cycle(): void
    {
        $urne = $this->urne(500);
        $a = $this->cycle('Cycle A');

        $this->stock->attribuer($a, $urne, 400, 'Dotation');
        $attribution = $this->stock->attribuer($a, $urne, 450, 'Révision de la dotation');

        $this->assertSame(450, $this->stock->attribue($urne, $a));
        $this->assertSame(450, $attribution->quantite);
        $this->assertSame(1, AttributionStock::count());

        $this->stock->attribuer($a, $urne, 0, 'Dotation retirée');
        $this->assertSame(0, $this->stock->attribue($urne, $a));
    }

    public function test_attribution_negative_ou_sur_cycle_clos_refusee(): void
    {
        $urne = $this->urne(500);
        $a = $this->cycle('Cycle A');

        try {
            $this->stock->attribuer($a, $urne, -1, 'Erreur');
            $this->fail('Une InvalidArgumentException était attendue.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, AttributionStock::count());
        }

        $a->update(['statut' => 'clos']);
        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Cycle clos : attribution impossible.');
        $this->stock->attribuer($a, $urne, 10, 'Dotation');
    }

    public function test_reservation_consomme_d_abord_l_attribution_du_cycle(): void
    {
        $urne = $this->urne(100);
        $a = $this->cycle('Cycle A');
        $this->stock->attribuer($a, $urne, 100, 'Dotation');

        $r = $this->stock->reserver($a, $urne, 60);

        $this->assertSame(60, $this->stock->reserve($urne));
        $this->assertSame(40, $this->stock->attribue($urne, $a));
        $this->assertSame(0, $this->stock->libreNonAttribue($urne));
        $this->assertSame(60, $r->quantite_sur_attribution);
    }

    public function test_reservation_complete_l_attribution_par_le_stock_commun(): void
    {
        $urne = $this->urne(100);
        $a = $this->cycle('Cycle A');
        $this->stock->attribuer($a, $urne, 30, 'Dotation');

        $r = $this->stock->reserver($a, $urne, 50);

        $this->assertSame(30, $r->quantite_sur_attribution);
        $this->assertSame(0, $this->stock->attribue($urne, $a));
        $this->assertSame(50, $this->stock->libreNonAttribue($urne));
    }

    public function test_autre_cycle_ne_peut_pas_prendre_le_stock_attribue(): void
    {
        $urne = $this->urne(100);
        $a = $this->cycle('Cycle A');
        $b = $this->cycle('Cycle B');
        $this->stock->attribuer($a, $urne, 100, 'Dotation');

        $this->expectException(StockInsuffisant::class);
        $this->expectExceptionMessage('Stock disponible insuffisant pour Urne : demandé 1, disponible 0');
        $this->stock->reserver($b, $urne, 1);
    }

    public function test_liberation_rend_l_attribution(): void
    {
        $urne = $this->urne(100);
        $a = $this->cycle('Cycle A');
        $this->stock->attribuer($a, $urne, 100, 'Dotation');
        $r = $this->stock->reserver($a, $urne, 60);

        $this->stock->liberer($r, 'Expédition annulée');

        $this->assertSame(100, $this->stock->attribue($urne, $a));
        $this->assertSame(0, $this->stock->reserve($urne));
        $this->assertSame('liberee', $r->fresh()->statut);
    }

    public function test_liberation_vers_stock_commun(): void
    {
        $urne = $this->urne(100);
        $a = $this->cycle('Cycle A');
        $this->stock->attribuer($a, $urne, 100, 'Dotation');
        $r = $this->stock->reserver($a, $urne, 60);

        $this->stock->liberer($r, 'Retour au stock commun', true);

        $this->assertSame(40, $this->stock->attribue($urne, $a));
        $this->assertSame(60, $this->stock->libreNonAttribue($urne));
        $this->assertSame(0, $this->stock->reserve($urne));
    }

    public function test_seule_une_reservation_active_se_libere(): void
    {
        $urne = $this->urne(100);
        $a = $this->cycle('Cycle A');
        $this->stock->attribuer($a, $urne, 100, 'Dotation');
        $r = $this->stock->reserver($a, $urne, 60);
        $this->stock->liberer($r, 'Annulation');

        try {
            $this->stock->liberer($r, 'Deuxième fois');
            $this->fail('Une RegleLogistique était attendue.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Seule une réservation active peut être libérée.', $e->getMessage());
        }

        $this->assertSame(100, $this->stock->attribue($urne, $a));
    }

    public function test_cloture_libere_sans_toucher_au_physique(): void
    {
        $urne = $this->urne(100);
        $a = $this->cycle('Cycle A');
        $r = $this->stock->reserver($a, $urne, 60);
        $this->stock->attribuer($a, $urne, 40, 'Dotation');
        $mouvements = MouvementStock::count();

        $this->stock->cloturerCycle($a, 'Fin du scrutin');

        $this->assertSame(0, $this->stock->reserve($urne));
        $this->assertSame(0, $this->stock->attribue($urne));
        $this->assertSame(0, $this->stock->attribue($urne, $a));
        $this->assertSame(100, $this->stock->physique($urne));
        $this->assertSame(100, $this->stock->libreNonAttribue($urne));
        $this->assertSame($mouvements, MouvementStock::count());
        $this->assertSame('liberee', $r->fresh()->statut);
        $a->refresh();
        $this->assertSame('clos', $a->statut);
        $this->assertNotNull($a->clos_le);
    }

    public function test_cloture_d_un_cycle_deja_clos_refusee(): void
    {
        $a = $this->cycle('Cycle A');
        $this->stock->cloturerCycle($a, 'Fin');

        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Ce cycle est déjà clos.');
        $this->stock->cloturerCycle($a, 'Encore');
    }

    public function test_reservation_sur_cycle_clos_refusee(): void
    {
        $urne = $this->urne(100);
        $a = $this->cycle('Cycle A');
        $this->stock->cloturerCycle($a, 'Fin');

        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Cycle clos : réservation impossible.');
        $this->stock->reserver($a, $urne, 10);
    }

    public function test_reservation_sur_cycle_clos_entre_temps_refusee(): void
    {
        $urne = $this->urne(100);
        $a = $this->cycle('Cycle A');
        Cycle::whereKey($a->id)->update(['statut' => 'clos']);

        // Le modèle en main dit « ouvert » : l'état relu en base fait foi.
        $this->expectException(RegleLogistique::class);
        $this->stock->reserver($a, $urne, 10);
    }

    public function test_reservation_quantite_nulle_refusee(): void
    {
        $urne = $this->urne(100);
        $a = $this->cycle('Cycle A');

        foreach ([0, -3] as $q) {
            try {
                $this->stock->reserver($a, $urne, $q);
                $this->fail('Une InvalidArgumentException était attendue.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, ReservationStock::count());
    }

    public function test_reservation_garde_sa_reference(): void
    {
        $urne = $this->urne(100);
        $a = $this->cycle('Cycle A');

        $r = $this->stock->reserver($a, $urne, 10, $a);

        $this->assertSame('cycles_electoraux', $r->reference_type);
        $this->assertSame($a->id, $r->reference_id);
    }

    public function test_reservation_sans_auteur_refusee(): void
    {
        $urne = $this->urne(100);
        $a = $this->cycle('Cycle A');
        auth()->logout();

        try {
            $this->stock->reserver($a, $urne, 10);
            $this->fail('Une LogicException était attendue.');
        } catch (LogicException $e) {
            $this->assertSame('Réservation de stock sans auteur.', $e->getMessage());
        }
        $this->assertSame(0, ReservationStock::count());
    }

    public function test_sortie_qui_entamerait_le_stock_reserve_refusee(): void
    {
        $urne = $this->urne(500);
        $a = $this->cycle('Cycle A');
        $this->stock->reserver($a, $urne, 400);

        try {
            $this->stock->enregistrer($urne, 'sortie_expedition', -200, null, 'Sortie');
            $this->fail('Une StockInsuffisant était attendue.');
        } catch (StockInsuffisant $e) {
            $this->assertSame(
                'Stock engagé pour Urne : réservé 400, attribué 0, physique après mouvement 300 — mouvement impossible.',
                $e->getMessage(),
            );
        }

        $this->assertSame(500, $this->stock->physique($urne));
        $this->assertSame(100, $this->stock->libre($urne));

        // Dans la limite du libre, la sortie passe comme avant.
        $this->stock->enregistrer($urne, 'sortie_expedition', -100, null, 'Sortie du libre');
        $this->assertSame(400, $this->stock->physique($urne));
        $this->assertSame(0, $this->stock->libre($urne));
    }

    public function test_compensation_qui_entamerait_le_stock_attribue_refusee(): void
    {
        $urne = Materiel::create(['libelle' => 'Urne', 'categorie' => 'materiel_lourd']);
        $inventaire = $this->stock->inventaireInitial($urne, 100, 'Inventaire');
        $a = $this->cycle('Cycle A');
        $this->stock->attribuer($a, $urne, 100, 'Dotation');

        try {
            $this->stock->compenser($inventaire, 'Erreur de saisie');
            $this->fail('Une StockInsuffisant était attendue.');
        } catch (StockInsuffisant $e) {
            $this->assertSame(
                'Stock engagé pour Urne : réservé 0, attribué 100, physique après mouvement 0 — mouvement impossible.',
                $e->getMessage(),
            );
        }

        $this->assertSame(100, $this->stock->physique($urne));
        $this->assertSame(1, MouvementStock::count());
    }

    public function test_reservation_sur_attribution_apres_sortie_refusee(): void
    {
        $urne = $this->urne(500);
        $a = $this->cycle('Cycle A');
        $this->stock->reserver($a, $urne, 400);
        try {
            $this->stock->enregistrer($urne, 'sortie_expedition', -200, null, 'Sortie');
        } catch (StockInsuffisant) {
            // Refus attendu : rien n'a bougé.
        }

        $this->stock->attribuer($a, $urne, 100, 'Dotation');
        $r = $this->stock->reserver($a, $urne, 50);

        $this->assertSame(50, $r->quantite_sur_attribution);
        $this->assertSame(450, $this->stock->reserve($urne));
        $this->assertSame(50, $this->stock->attribue($urne, $a));
        $this->assertSame(0, $this->stock->libreNonAttribue($urne));
    }

    public function test_mouvement_a_reparer_non_concerne_par_l_engagement(): void
    {
        $urne = $this->urne(100);
        $a = $this->cycle('Cycle A');
        $this->stock->attribuer($a, $urne, 100, 'Dotation');
        $this->stock->enregistrer($urne, 'reclassement', 5, null, 'Urnes abîmées', 'a_reparer');

        $this->stock->enregistrer($urne, 'reforme', -5, null, 'Réforme', 'a_reparer');

        $this->assertSame(0, $this->stock->physique($urne, 'a_reparer'));
        $this->assertSame(100, $this->stock->physique($urne));
    }

    public function test_attribution_sans_auteur_refusee(): void
    {
        $urne = $this->urne(100);
        $a = $this->cycle('Cycle A');
        auth()->logout();

        try {
            $this->stock->attribuer($a, $urne, 10, 'Dotation');
            $this->fail('Une LogicException était attendue.');
        } catch (LogicException $e) {
            $this->assertSame('Opération de stock sans auteur.', $e->getMessage());
        }
        $this->assertSame(0, AttributionStock::count());
    }

    public function test_liberation_sans_auteur_refusee(): void
    {
        $urne = $this->urne(100);
        $a = $this->cycle('Cycle A');
        $r = $this->stock->reserver($a, $urne, 10);
        auth()->logout();

        try {
            $this->stock->liberer($r, 'Annulation');
            $this->fail('Une LogicException était attendue.');
        } catch (LogicException $e) {
            $this->assertSame('Opération de stock sans auteur.', $e->getMessage());
        }
        $this->assertSame('active', $r->fresh()->statut);
    }

    public function test_cloture_sans_auteur_refusee(): void
    {
        $a = $this->cycle('Cycle A');
        auth()->logout();

        try {
            $this->stock->cloturerCycle($a, 'Fin');
            $this->fail('Une LogicException était attendue.');
        } catch (LogicException $e) {
            $this->assertSame('Opération de stock sans auteur.', $e->getMessage());
        }
        $this->assertSame('ouvert', $a->fresh()->statut);
        $this->assertSame(0, JournalEntree::where('objet_type', 'cycle')->count());
    }

    public function test_suppression_d_un_utilisateur_auteur_de_reservation_refusee(): void
    {
        $urne = $this->urne(100);
        $a = $this->cycle('Cycle A');
        $autre = User::factory()->create(['role' => 'logistique_responsable']);
        $this->actingAs($autre);
        $r = $this->stock->reserver($a, $urne, 10);

        try {
            DB::table('users')->where('id', $autre->id)->delete();
            $this->fail('Une QueryException était attendue.');
        } catch (QueryException) {
            $this->assertSame($autre->id, $r->fresh()->user_id);
            $this->assertDatabaseHas('users', ['id' => $autre->id]);
        }
    }

    public function test_attribution_liberation_et_cloture_sont_journalisees(): void
    {
        $urne = $this->urne(100);
        $a = $this->cycle('Cycle A');
        $attribution = $this->stock->attribuer($a, $urne, 80, 'Dotation');
        $r = $this->stock->reserver($a, $urne, 30);
        $this->stock->liberer($r, 'Annulation');
        $this->stock->cloturerCycle($a, 'Fin du scrutin');

        $entree = JournalEntree::where('objet_type', 'attribution_stock')->where('objet_id', $attribution->id)->sole();
        $this->assertSame('attribution', $entree->action);
        $this->assertSame(['quantite' => 0], $entree->avant);
        $this->assertSame(['quantite' => 80], $entree->apres);
        $this->assertSame('Dotation', $entree->motif);

        $this->assertDatabaseHas('journal_logistique', [
            'objet_type' => 'reservation_stock', 'objet_id' => $r->id, 'action' => 'liberation', 'motif' => 'Annulation',
        ]);
        $this->assertDatabaseHas('journal_logistique', [
            'objet_type' => 'cycle', 'objet_id' => $a->id, 'action' => 'cloture', 'motif' => 'Fin du scrutin',
        ]);
    }
}

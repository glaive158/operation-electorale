<?php

namespace Tests\Feature\Logistique;

use App\Models\Logistique\Commande;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\MouvementStock;
use App\Models\User;
use App\Services\Logistique\CommandeService;
use App\Services\Logistique\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EcransLogistiqueTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    private CommandeService $commandes;

    private User $gestionnaire;

    private User $responsable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stock = app(StockService::class);
        $this->commandes = app(CommandeService::class);
        $this->gestionnaire = User::factory()->create(['role' => 'logistique_gestionnaire']);
        $this->responsable = User::factory()->create(['role' => 'logistique_responsable']);
    }

    private function materiel(string $libelle = 'Urne', string $categorie = 'materiel_lourd'): Materiel
    {
        return Materiel::create(['libelle' => $libelle, 'categorie' => $categorie]);
    }

    private function cycle(string $libelle = 'Cycle A'): Cycle
    {
        return Cycle::create(['libelle' => $libelle, 'type' => 'scrutin']);
    }

    public function test_tableau_de_stock_affiche_les_indicateurs(): void
    {
        $this->actingAs($this->gestionnaire);
        $urne = $this->materiel('Urne');
        $a = $this->cycle();
        $this->stock->inventaireInitial($urne, 500, 'Inventaire');
        $this->stock->reserver($a, $urne, 400);

        $reponse = $this->get(route('logistique.stock.index'))->assertOk();

        $reponse->assertSeeInOrder(['Urne', '500', '400']);
        $reponse->assertSee('Libre non attribué');
        $reponse->assertSee('Commandé à recevoir');
        $reponse->assertSeeInOrder(['Urne', '500', '400', '100']);
    }

    public function test_filtre_par_categorie(): void
    {
        $this->actingAs($this->gestionnaire);
        $urne = $this->materiel('Urne', 'materiel_lourd');
        $bulletin = $this->materiel('Bulletin', 'imprime');

        // Le lien vers les mouvements n'existe que sur les lignes du tableau (la liste de l'inventaire contient tous les articles).
        $this->get(route('logistique.stock.index', ['categorie' => 'imprime']))
            ->assertOk()
            ->assertSee(route('logistique.stock.mouvements', $bulletin), false)
            ->assertDontSee(route('logistique.stock.mouvements', $urne), false);
    }

    public function test_gestionnaire_saisit_un_inventaire_initial(): void
    {
        $urne = $this->materiel();

        $this->actingAs($this->gestionnaire)
            ->post(route('logistique.stock.inventaire'), [
                'materiel_id' => $urne->id, 'quantite' => 120, 'motif' => 'Inventaire',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(120, $this->stock->physique($urne));
    }

    public function test_inventaire_exige_une_quantite_positive_et_un_motif(): void
    {
        $urne = $this->materiel();

        $this->actingAs($this->gestionnaire)
            ->post(route('logistique.stock.inventaire'), ['materiel_id' => $urne->id, 'quantite' => 0])
            ->assertSessionHasErrors(['quantite', 'motif']);

        $this->assertSame(0, $this->stock->physique($urne));
    }

    public function test_gestionnaire_ne_peut_pas_compenser(): void
    {
        $this->actingAs($this->responsable);
        $urne = $this->materiel();
        $mouvement = $this->stock->inventaireInitial($urne, 50, 'Inventaire');

        $this->actingAs($this->gestionnaire)
            ->post(route('logistique.mouvements.compenser', $mouvement), ['motif' => 'Erreur'])
            ->assertForbidden();
        $this->assertSame(50, $this->stock->physique($urne));

        $this->actingAs($this->responsable)
            ->post(route('logistique.mouvements.compenser', $mouvement), ['motif' => 'Erreur de saisie'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, $this->stock->physique($urne));
    }

    public function test_page_des_mouvements(): void
    {
        $this->actingAs($this->responsable);
        $urne = $this->materiel();
        $this->stock->inventaireInitial($urne, 50, 'Inventaire de départ');

        $this->get(route('logistique.stock.mouvements', $urne))
            ->assertOk()->assertSee('Urne')->assertSee('Inventaire de départ');
    }

    public function test_gestionnaire_ne_peut_pas_attribuer_ni_cloturer(): void
    {
        $urne = $this->materiel();
        $a = $this->cycle();

        $this->actingAs($this->gestionnaire)
            ->put(route('logistique.attributions.update', [$a, $urne]), ['quantite' => 1, 'motif' => 'x'])
            ->assertForbidden();
        $this->actingAs($this->gestionnaire)
            ->post(route('logistique.cycles.cloturer', $a), ['motif' => 'x'])
            ->assertForbidden();
    }

    public function test_attribution_via_formulaire(): void
    {
        $this->actingAs($this->responsable);
        $urne = $this->materiel();
        $a = $this->cycle();
        $this->stock->inventaireInitial($urne, 100, 'Inventaire');

        $this->put(route('logistique.attributions.update', [$a, $urne]), ['quantite' => 30, 'motif' => 'Dotation'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(30, $this->stock->attribue($urne, $a));
    }

    public function test_attribution_excessive_affiche_une_erreur(): void
    {
        $this->actingAs($this->responsable);
        $urne = $this->materiel();
        $a = $this->cycle();
        $this->stock->inventaireInitial($urne, 10, 'Inventaire');

        $reponse = $this->from(route('logistique.stock.mouvements', $urne))
            ->put(route('logistique.attributions.update', [$a, $urne]), ['quantite' => 11, 'motif' => 'Dotation']);

        $reponse->assertRedirect(route('logistique.stock.mouvements', $urne));
        $reponse->assertSessionHas('error');
        $this->assertSame(0, $this->stock->attribue($urne, $a));
    }

    public function test_motif_obligatoire_pour_cloturer(): void
    {
        $a = $this->cycle();

        $this->actingAs($this->responsable)
            ->post(route('logistique.cycles.cloturer', $a))
            ->assertSessionHasErrors('motif');

        $this->assertTrue($a->fresh()->estOuvert());
    }

    public function test_cloture_du_cycle(): void
    {
        $this->actingAs($this->responsable);
        $a = $this->cycle();

        $this->post(route('logistique.cycles.cloturer', $a), ['motif' => 'Scrutin terminé'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($a->fresh()->estOuvert());
        $this->assertSame(1, JournalEntree::where('objet_type', 'cycle')->where('action', 'cloture')->count());

        $this->post(route('logistique.cycles.cloturer', $a), ['motif' => 'Encore'])
            ->assertSessionHas('error');
    }

    public function test_cycle_clos_non_modifiable(): void
    {
        $this->actingAs($this->responsable);
        $a = $this->cycle();
        $this->stock->cloturerCycle($a, 'Terminé');

        $this->get(route('logistique.cycles.edit', $a))
            ->assertRedirect()->assertSessionHas('error', 'Cycle clôturé : modification impossible.');

        $this->put(route('logistique.cycles.update', $a), [
            'libelle' => 'Renommé', 'type' => 'scrutin', 'majoration_distribuee' => 10,
            'reserve_centrale' => 0, 'circuit_defaut' => 'direct',
        ])->assertRedirect()->assertSessionHas('error', 'Cycle clôturé : modification impossible.');

        $this->assertSame('Cycle A', $a->fresh()->libelle);
    }

    public function test_la_modification_d_un_cycle_ne_peut_pas_le_cloturer(): void
    {
        $this->actingAs($this->responsable);
        $a = $this->cycle();

        $this->put(route('logistique.cycles.update', $a), [
            'libelle' => 'Cycle A bis', 'type' => 'scrutin', 'majoration_distribuee' => 10,
            'reserve_centrale' => 0, 'circuit_defaut' => 'direct',
            'statut' => 'clos', 'clos_le' => '2026-11-01 10:00:00',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $a = $a->fresh();
        $this->assertSame('Cycle A bis', $a->libelle);
        $this->assertTrue($a->estOuvert());
        $this->assertNull($a->clos_le);
    }

    public function test_statut_et_date_de_cloture_ne_s_assignent_pas_en_masse(): void
    {
        $a = $this->cycle();

        $a->update(['statut' => 'clos', 'clos_le' => now()]);

        $a = $a->fresh();
        $this->assertTrue($a->estOuvert());
        $this->assertNull($a->clos_le);
    }

    public function test_creation_d_une_commande_puis_confirmation(): void
    {
        $this->actingAs($this->gestionnaire);
        $urne = $this->materiel();

        $this->post(route('logistique.commandes.store'), [
            'reference' => 'CMD-100', 'fournisseur' => 'Imprimerie nationale', 'date_commande' => '2026-11-01',
            'lignes' => [
                ['materiel_id' => $urne->id, 'quantite_commandee' => 100, 'cycle_id' => ''],
                ['materiel_id' => '', 'quantite_commandee' => '', 'cycle_id' => ''],
            ],
        ])->assertSessionHasNoErrors();

        $commande = Commande::where('reference', 'CMD-100')->firstOrFail();
        $this->assertSame('brouillon', $commande->statut);
        $this->assertCount(1, $commande->lignes);
        $this->assertNull($commande->lignes->first()->cycle_id);

        $this->get(route('logistique.commandes.index'))->assertOk()->assertSee('CMD-100');
        $this->get(route('logistique.commandes.show', $commande))->assertOk()->assertSee('Imprimerie nationale');

        $this->post(route('logistique.commandes.confirmer', $commande))->assertRedirect();
        $this->assertSame('confirmee', $commande->fresh()->statut);
    }

    public function test_gestionnaire_ne_peut_pas_viser_un_cycle_depuis_le_formulaire(): void
    {
        $this->actingAs($this->gestionnaire);
        $urne = $this->materiel();
        $a = $this->cycle();

        $this->post(route('logistique.commandes.store'), [
            'reference' => 'CMD-110', 'fournisseur' => 'F', 'date_commande' => '2026-11-01',
            'lignes' => [['materiel_id' => $urne->id, 'quantite_commandee' => 100, 'cycle_id' => $a->id]],
        ])->assertSessionHas('error', 'Seul un responsable logistique peut affecter une commande à un cycle.');

        $this->assertSame(0, Commande::count());
    }

    public function test_responsable_vise_un_cycle_depuis_le_formulaire(): void
    {
        $this->actingAs($this->responsable);
        $urne = $this->materiel();
        $a = $this->cycle();

        $this->post(route('logistique.commandes.store'), [
            'reference' => 'CMD-111', 'fournisseur' => 'F', 'date_commande' => '2026-11-01',
            'lignes' => [['materiel_id' => $urne->id, 'quantite_commandee' => 100, 'cycle_id' => $a->id]],
        ])->assertSessionHasNoErrors();

        $this->assertSame($a->id, Commande::where('reference', 'CMD-111')->firstOrFail()->lignes->first()->cycle_id);
    }

    public function test_selecteur_de_cycle_reserve_au_responsable(): void
    {
        $this->cycle('Cycle visible');

        $this->actingAs($this->gestionnaire)->get(route('logistique.commandes.create'))
            ->assertOk()->assertDontSee('Cycle visé')->assertDontSee('Cycle visible');
        $this->actingAs($this->responsable)->get(route('logistique.commandes.create'))
            ->assertOk()->assertSee('Cycle visé')->assertSee('Cycle visible');
    }

    public function test_commande_sans_ligne_est_refusee(): void
    {
        $this->actingAs($this->gestionnaire);

        $this->post(route('logistique.commandes.store'), [
            'reference' => 'CMD-101', 'fournisseur' => 'F', 'date_commande' => '2026-11-01',
            'lignes' => [['materiel_id' => '', 'quantite_commandee' => '']],
        ])->assertSessionHas('error');

        $this->assertSame(0, Commande::count());
    }

    public function test_reception_via_formulaire(): void
    {
        $this->actingAs($this->gestionnaire);
        $urne = $this->materiel();
        $commande = $this->commandes->creer(
            ['reference' => 'CMD-200', 'fournisseur' => 'F', 'date_commande' => '2026-11-01'],
            [['materiel_id' => $urne->id, 'quantite_commandee' => 100]],
        );
        $this->commandes->confirmer($commande);
        $ligne = $commande->lignes()->first();

        $this->post(route('logistique.commandes.receptions.store', $commande), [
            'date_reception' => '2026-11-10', 'bon_livraison' => 'BL-1',
            'lignes' => [$ligne->id => ['acceptee' => 60]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(60, $this->stock->physique($urne));
        $this->assertSame('partiellement_recue', $commande->fresh()->statut);

        $this->get(route('logistique.commandes.show', $commande))
            ->assertOk()->assertSeeInOrder(['Reste à recevoir', '40']);

        $this->get(route('logistique.stock.index'))->assertOk()->assertSeeInOrder(['Urne', '60', '40']);
    }

    public function test_reception_excessive_affiche_une_erreur(): void
    {
        $this->actingAs($this->gestionnaire);
        $urne = $this->materiel();
        $commande = $this->commandes->creer(
            ['reference' => 'CMD-201', 'fournisseur' => 'F', 'date_commande' => '2026-11-01'],
            [['materiel_id' => $urne->id, 'quantite_commandee' => 10]],
        );
        $this->commandes->confirmer($commande);
        $ligne = $commande->lignes()->first();

        $this->post(route('logistique.commandes.receptions.store', $commande), [
            'date_reception' => '2026-11-10', 'bon_livraison' => 'BL-1',
            'lignes' => [$ligne->id => ['acceptee' => 11]],
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, $this->stock->physique($urne));
    }

    public function test_annulation_et_abandon_reserves_au_responsable(): void
    {
        $this->actingAs($this->responsable);
        $urne = $this->materiel();
        $commande = $this->commandes->creer(
            ['reference' => 'CMD-300', 'fournisseur' => 'F', 'date_commande' => '2026-11-01'],
            [['materiel_id' => $urne->id, 'quantite_commandee' => 100]],
        );
        $this->commandes->confirmer($commande);
        $ligne = $commande->lignes()->first();

        $this->actingAs($this->gestionnaire)
            ->post(route('logistique.commandes.annuler', $commande), ['motif' => 'x'])->assertForbidden();
        $this->actingAs($this->gestionnaire)
            ->post(route('logistique.commande-lignes.abandonner', $ligne), ['quantite' => 5, 'motif' => 'x'])->assertForbidden();

        $this->actingAs($this->responsable)
            ->post(route('logistique.commande-lignes.abandonner', $ligne), ['quantite' => 30, 'motif' => 'Non livré'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(70, $ligne->fresh()->resteARecevoir());

        $this->post(route('logistique.commandes.annuler', $commande))->assertSessionHasErrors('motif');
        $this->post(route('logistique.commandes.annuler', $commande), ['motif' => 'Fournisseur défaillant'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('annulee', $commande->fresh()->statut);
    }

    public function test_menu_logistique_seul_pour_un_gestionnaire(): void
    {
        $this->actingAs($this->gestionnaire)->get(route('logistique.stock.index'))
            ->assertOk()
            ->assertSee('Logistique')
            ->assertSee('Commandes')
            ->assertDontSee('Fichier électoral')
            ->assertDontSee('Nouvelle opération');

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('logistique.stock.index'))
            ->assertOk()
            ->assertSee('Logistique')
            ->assertSee('Fichier électoral');
    }

    public function test_menu_sans_logistique_pour_un_prefet(): void
    {
        $prefet = User::factory()->create(['role' => 'prefet']);

        $this->actingAs($prefet)->get(route('operations.index'))
            ->assertOk()
            ->assertSee('Fichier électoral')
            ->assertDontSee('Logistique');
    }

    public function test_pages_du_responsable_affichent_les_actions_d_administration(): void
    {
        $this->actingAs($this->responsable);
        $urne = $this->materiel();
        $ouvert = $this->cycle('Cycle ouvert');
        $clos = $this->cycle('Cycle clos');
        $this->stock->cloturerCycle($clos, 'Terminé');
        $commande = $this->commandes->creer(
            ['reference' => 'CMD-400', 'fournisseur' => 'F', 'date_commande' => '2026-11-01'],
            [['materiel_id' => $urne->id, 'quantite_commandee' => 20]],
        );
        $this->commandes->confirmer($commande);

        $this->get(route('logistique.cycles.index'))
            ->assertOk()
            ->assertSee(route('logistique.cycles.cloturer', $ouvert), false)
            ->assertDontSee(route('logistique.cycles.cloturer', $clos), false);
        $this->get(route('logistique.commandes.show', $commande))
            ->assertOk()
            ->assertSee(route('logistique.commandes.annuler', $commande), false)
            ->assertSee('Enregistrer une réception');
        $this->get(route('logistique.stock.mouvements', $urne))
            ->assertOk()
            ->assertSee(route('logistique.attributions.update', [$ouvert, $urne]), false)
            ->assertDontSee(route('logistique.attributions.update', [$clos, $urne]), false);
        $this->get(route('logistique.commandes.create'))->assertOk()->assertSee('Cycle ouvert');

        $this->actingAs($this->gestionnaire);
        $this->get(route('logistique.commandes.show', $commande))
            ->assertOk()->assertDontSee(route('logistique.commandes.annuler', $commande), false);
    }

    public function test_un_mouvement_compense_n_est_plus_proposé(): void
    {
        $this->actingAs($this->responsable);
        $urne = $this->materiel();
        $mouvement = $this->stock->inventaireInitial($urne, 50, 'Inventaire');
        $this->stock->compenser($mouvement, 'Erreur');

        $this->assertSame(2, MouvementStock::count());
        $this->get(route('logistique.stock.mouvements', $urne))->assertOk();
    }
}

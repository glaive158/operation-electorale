<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Commande;
use App\Models\Logistique\CommandeLigne;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\MouvementStock;
use App\Models\Logistique\ReceptionFournisseur;
use App\Models\User;
use App\Services\Logistique\CommandeService;
use App\Services\Logistique\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class CommandesTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    private CommandeService $commandes;

    private User $auteur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stock = app(StockService::class);
        $this->commandes = app(CommandeService::class);
        $this->auteur = User::factory()->create(['role' => 'logistique_gestionnaire']);
        $this->actingAs($this->auteur);
    }

    private function materiel(string $libelle = 'Enveloppe'): Materiel
    {
        return Materiel::create(['libelle' => $libelle, 'categorie' => 'imprime']);
    }

    private function cycle(string $libelle = 'Cycle A'): Cycle
    {
        return Cycle::create(['libelle' => $libelle, 'type' => 'scrutin']);
    }

    /**
     * @param  array<int, array{materiel_id:int, quantite_commandee:int, cycle_id?:?int}>  $lignes
     */
    private function commande(array $lignes, bool $confirmer = true, string $reference = 'CMD-001'): Commande
    {
        $commande = $this->commandes->creer([
            'reference'     => $reference,
            'fournisseur'   => 'Imprimerie nationale',
            'date_commande' => '2026-11-01',
        ], $lignes);

        if ($confirmer) {
            $this->commandes->confirmer($commande);
        }

        return $commande;
    }

    /**
     * L'affectation à un cycle est un droit du responsable ; la suite (réception) reste ouverte au gestionnaire.
     *
     * @param  array<int, array{materiel_id:int, quantite_commandee:int, cycle_id?:?int}>  $lignes
     */
    private function commandeAttribuee(array $lignes, string $reference = 'CMD-001'): Commande
    {
        $this->actingAs(User::factory()->create(['role' => 'logistique_responsable']));
        $commande = $this->commande($lignes, true, $reference);
        $this->actingAs($this->auteur);

        return $commande;
    }

    private function recevoir(Commande $commande, array $lignes): ReceptionFournisseur
    {
        return $this->commandes->recevoir($commande, [
            'date_reception' => '2026-11-10',
            'bon_livraison'  => 'BL-001',
        ], $lignes);
    }

    public function test_creation_en_brouillon(): void
    {
        $m = $this->materiel();
        $commande = $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 100]], false);

        $this->assertSame('brouillon', $commande->fresh()->statut);
        $this->assertSame($this->auteur->id, $commande->user_id);
        $this->assertCount(1, $commande->lignes);
        $this->assertSame(100, $commande->lignes[0]->resteARecevoir());
    }

    public function test_creation_sans_ligne_ou_quantite_nulle_refusee(): void
    {
        $m = $this->materiel();
        $entete = ['reference' => 'CMD-X', 'fournisseur' => 'F', 'date_commande' => '2026-11-01'];

        try {
            $this->commandes->creer($entete, []);
            $this->fail('Une commande sans ligne devrait être refusée.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $this->commandes->creer($entete, [['materiel_id' => $m->id, 'quantite_commandee' => 0]]);
    }

    public function test_confirmer_seulement_depuis_brouillon(): void
    {
        $m = $this->materiel();
        $commande = $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 10]]);

        $this->assertSame('confirmee', $commande->fresh()->statut);
        $this->assertTrue(JournalEntree::where('objet_type', 'commande')->where('action', 'confirmation')->exists());

        $this->expectException(RegleLogistique::class);
        $this->commandes->confirmer($commande);
    }

    public function test_reception_en_deux_livraisons(): void
    {
        $m = $this->materiel();
        $commande = $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 10000]]);
        $ligne = $commande->lignes[0];

        $this->recevoir($commande, [$ligne->id => ['acceptee' => 4000, 'rejetee' => 0]]);

        $this->assertSame(4000, $this->stock->physique($m));
        $this->assertSame('partiellement_recue', $commande->fresh()->statut);
        $this->assertSame(6000, $ligne->fresh()->resteARecevoir());

        $this->recevoir($commande, [$ligne->id => ['acceptee' => 5900, 'rejetee' => 100]]);

        $this->assertSame(9900, $this->stock->physique($m));
        $this->assertSame(100, $ligne->fresh()->resteARecevoir());
        $this->assertSame(9900, $ligne->fresh()->quantite_acceptee);
        $this->assertSame(100, $ligne->fresh()->quantite_rejetee);
        $this->assertSame('partiellement_recue', $commande->fresh()->statut);

        $mouvement = MouvementStock::where('materiel_id', $m->id)->where('type', 'entree_fournisseur')->first();
        $this->assertSame('receptions_fournisseur', $mouvement->reference_type);
        $this->assertSame(2, ReceptionFournisseur::count());
        $this->assertTrue(JournalEntree::where('objet_type', 'commande')->where('action', 'reception')->exists());
    }

    public function test_abandon_du_rejete_solde_la_commande(): void
    {
        $m = $this->materiel();
        $commande = $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 10000]]);
        $ligne = $commande->lignes[0];
        $this->recevoir($commande, [$ligne->id => ['acceptee' => 4000, 'rejetee' => 0]]);
        $this->recevoir($commande, [$ligne->id => ['acceptee' => 5900, 'rejetee' => 100]]);

        $this->commandes->abandonner($ligne, 100, 'Fournisseur ne peut pas remplacer');

        $this->assertSame(0, $ligne->fresh()->resteARecevoir());
        $this->assertSame(100, $ligne->fresh()->quantite_abandonnee);
        $this->assertSame('recue', $commande->fresh()->statut);
        $this->assertTrue(JournalEntree::where('objet_type', 'commande_ligne')->where('action', 'abandon')->exists());
    }

    public function test_abandon_superieur_au_reste_refuse(): void
    {
        $m = $this->materiel();
        $commande = $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 100]]);
        $ligne = $commande->lignes[0];

        $this->expectException(RegleLogistique::class);
        $this->commandes->abandonner($ligne, 101, 'Trop');
    }

    public function test_reception_superieure_au_reste_refusee(): void
    {
        $m = $this->materiel();
        $commande = $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 100]]);
        $ligne = $commande->lignes[0];

        try {
            $this->recevoir($commande, [$ligne->id => ['acceptee' => 101, 'rejetee' => 0]]);
            $this->fail('Une réception supérieure au reste devrait être refusée.');
        } catch (RegleLogistique) {
        }

        $this->assertSame(0, $this->stock->physique($m));
        $this->assertSame(0, ReceptionFournisseur::count());
    }

    public function test_reception_partiellement_invalide_n_ecrit_rien(): void
    {
        $a = $this->materiel('Enveloppe A');
        $b = $this->materiel('Enveloppe B');
        $commande = $this->commande([
            ['materiel_id' => $a->id, 'quantite_commandee' => 100],
            ['materiel_id' => $b->id, 'quantite_commandee' => 100],
        ]);
        [$l1, $l2] = [$commande->lignes[0], $commande->lignes[1]];

        try {
            $this->recevoir($commande, [
                $l1->id => ['acceptee' => 50, 'rejetee' => 0],
                $l2->id => ['acceptee' => 10, 'rejetee' => -1],
            ]);
            $this->fail('Une quantité négative devrait être refusée.');
        } catch (RegleLogistique) {
        }

        $this->assertSame(0, MouvementStock::count());
        $this->assertSame(0, ReceptionFournisseur::count());
        $this->assertSame(0, $l1->fresh()->quantite_acceptee);
        $this->assertSame('confirmee', $commande->fresh()->statut);
    }

    public function test_reception_ligne_vide_ou_etrangere_refusee(): void
    {
        $m = $this->materiel();
        $commande = $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 100]]);
        $autre = $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 100]], true, 'CMD-002');
        $ligne = $commande->lignes[0];

        try {
            $this->recevoir($commande, [$ligne->id => ['acceptee' => 0, 'rejetee' => 0]]);
            $this->fail('Une ligne à 0/0 devrait être refusée.');
        } catch (RegleLogistique) {
        }

        $this->expectException(InvalidArgumentException::class);
        $this->recevoir($commande, [$autre->lignes[0]->id => ['acceptee' => 10, 'rejetee' => 0]]);
    }

    public function test_reception_sur_commande_brouillon_refusee(): void
    {
        $m = $this->materiel();
        $commande = $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 100]], false);

        $this->expectException(RegleLogistique::class);
        $this->recevoir($commande, [$commande->lignes[0]->id => ['acceptee' => 10, 'rejetee' => 0]]);
    }

    public function test_annulation_impossible_apres_reception(): void
    {
        $m = $this->materiel();
        $commande = $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 100]]);
        $this->recevoir($commande, [$commande->lignes[0]->id => ['acceptee' => 10, 'rejetee' => 0]]);

        try {
            $this->commandes->annuler($commande, 'Erreur');
            $this->fail('L\'annulation après réception devrait être refusée.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Commande déjà partiellement reçue : abandonner les reliquats ligne par ligne.', $e->getMessage());
        }

        $this->assertSame('partiellement_recue', $commande->fresh()->statut);
    }

    public function test_annulation_avant_reception(): void
    {
        $m = $this->materiel();
        $commande = $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 100]]);

        $this->commandes->annuler($commande, 'Fournisseur défaillant');

        $this->assertSame('annulee', $commande->fresh()->statut);
        $this->assertTrue(JournalEntree::where('objet_type', 'commande')->where('action', 'annulation')->exists());

        $this->expectException(RegleLogistique::class);
        $this->recevoir($commande, [$commande->lignes[0]->id => ['acceptee' => 10, 'rejetee' => 0]]);
    }

    public function test_commande_attribuee_au_cycle_alimente_son_attribution(): void
    {
        $m = $this->materiel();
        $a = $this->cycle();
        $commande = $this->commandeAttribuee([['materiel_id' => $m->id, 'quantite_commandee' => 300, 'cycle_id' => $a->id]]);

        $this->assertSame(300, $this->stock->commandeARecevoir($m, $a));

        $this->recevoir($commande, [$commande->lignes[0]->id => ['acceptee' => 300, 'rejetee' => 0]]);

        $this->assertSame(300, $this->stock->attribue($m, $a));
        $this->assertSame(0, $this->stock->commandeARecevoir($m, $a));
    }

    public function test_reception_pour_cycle_clos_va_au_stock_commun(): void
    {
        $m = $this->materiel();
        $a = $this->cycle();
        $commande = $this->commandeAttribuee([['materiel_id' => $m->id, 'quantite_commandee' => 100, 'cycle_id' => $a->id]]);
        $this->stock->cloturerCycle($a, 'Fin de cycle');

        $this->recevoir($commande, [$commande->lignes[0]->id => ['acceptee' => 50, 'rejetee' => 0]]);

        $this->assertSame(50, $this->stock->physique($m));
        $this->assertSame(0, $this->stock->attribue($m, $a));
    }

    public function test_commande_a_recevoir_ignore_brouillons_et_annulees(): void
    {
        $m = $this->materiel();
        $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 100]], false, 'CMD-B');
        $annulee = $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 50]], true, 'CMD-A');
        $this->commandes->annuler($annulee, 'Annulée');
        $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 30]], true, 'CMD-C');

        $this->assertSame(30, $this->stock->commandeARecevoir($m));
    }

    public function test_operations_sans_auteur_refusees(): void
    {
        $m = $this->materiel();
        $brouillon = $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 100]], false);
        $confirmee = $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 100]], true, 'CMD-002');
        $ligne = $confirmee->lignes[0];

        auth()->logout();

        $operations = [
            'confirmer'  => fn () => $this->commandes->confirmer($brouillon),
            'annuler'    => fn () => $this->commandes->annuler($brouillon, 'Motif'),
            'creer'      => fn () => $this->commandes->creer(
                ['reference' => 'CMD-X', 'fournisseur' => 'F', 'date_commande' => '2026-11-01'],
                [['materiel_id' => $m->id, 'quantite_commandee' => 1]],
            ),
            'recevoir'   => fn () => $this->recevoir($confirmee, [$ligne->id => ['acceptee' => 10, 'rejetee' => 0]]),
            'abandonner' => fn () => $this->commandes->abandonner($ligne, 10, 'Motif'),
        ];

        foreach ($operations as $nom => $operation) {
            try {
                $operation();
                $this->fail("{$nom} sans auteur devrait être refusé.");
            } catch (LogicException) {
            }
        }

        $this->assertSame(0, MouvementStock::count());
        $this->assertSame('brouillon', $brouillon->fresh()->statut);
        $this->assertSame(0, $ligne->fresh()->quantite_abandonnee);
    }

    public function test_abandon_partiel_sans_reception_garde_confirmee_puis_annulation_possible(): void
    {
        $m = $this->materiel();
        $commande = $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 100]]);
        $ligne = $commande->lignes[0];

        $this->commandes->abandonner($ligne, 40, 'Besoin réduit');

        $this->assertSame('confirmee', $commande->fresh()->statut);
        $this->assertSame(60, $ligne->fresh()->resteARecevoir());

        $this->commandes->annuler($commande, 'Fournisseur défaillant');
        $this->assertSame('annulee', $commande->fresh()->statut);
    }

    public function test_abandon_total_sans_reception_annule_la_commande(): void
    {
        $m = $this->materiel();
        $commande = $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 100]]);

        $this->commandes->abandonner($commande->lignes[0], 100, 'Commande abandonnée');

        $this->assertSame('annulee', $commande->fresh()->statut);
        $this->assertSame(0, $this->stock->commandeARecevoir($m));
    }

    public function test_abandon_total_apres_reception_solde_la_commande(): void
    {
        $m = $this->materiel();
        $commande = $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 100]]);
        $this->recevoir($commande, [$commande->lignes[0]->id => ['acceptee' => 60, 'rejetee' => 0]]);

        $this->commandes->abandonner($commande->lignes[0], 40, 'Reliquat abandonné');

        $this->assertSame('recue', $commande->fresh()->statut);
    }

    public function test_gestionnaire_ne_peut_pas_affecter_une_commande_a_un_cycle(): void
    {
        $m = $this->materiel();
        $a = $this->cycle();

        try {
            $this->commandes->creer(
                ['reference' => 'CMD-G', 'fournisseur' => 'F', 'date_commande' => '2026-11-01'],
                [['materiel_id' => $m->id, 'quantite_commandee' => 10, 'cycle_id' => $a->id]],
            );
            $this->fail('Une RegleLogistique était attendue.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Seul un responsable logistique peut affecter une commande à un cycle.', $e->getMessage());
        }
        $this->assertSame(0, Commande::count());
    }

    public function test_responsable_affecte_une_commande_a_un_cycle(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'logistique_responsable']));
        $m = $this->materiel();
        $a = $this->cycle();

        $commande = $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 10, 'cycle_id' => $a->id]], false);

        $this->assertSame($a->id, $commande->lignes()->first()->cycle_id);
    }

    public function test_commande_sur_cycle_clos_refusee(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'logistique_responsable']));
        $m = $this->materiel();
        $a = $this->cycle();
        $this->stock->cloturerCycle($a, 'Fin de cycle');

        try {
            $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 10, 'cycle_id' => $a->id]], false);
            $this->fail('Une RegleLogistique était attendue.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Cycle clôturé : commande impossible.', $e->getMessage());
        }
        $this->assertSame(0, Commande::count());
    }

    public function test_commande_sur_materiel_inactif_refusee(): void
    {
        $m = $this->materiel();
        $m->update(['actif' => false]);

        try {
            $this->commande([['materiel_id' => $m->id, 'quantite_commandee' => 10]], false);
            $this->fail('Une InvalidArgumentException était attendue.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, Commande::count());
        }
    }

    public function test_dates_et_longueurs_invalides_refusees(): void
    {
        $m = $this->materiel();
        $lignes = [['materiel_id' => $m->id, 'quantite_commandee' => 10]];
        $entete = ['reference' => 'CMD-V', 'fournisseur' => 'F', 'date_commande' => '2026-11-01'];

        $invalides = [
            ['date_commande' => '01/11/2026'],
            ['date_commande' => '2026-02-30'],
            ['reference' => str_repeat('R', 51)],
            ['fournisseur' => str_repeat('F', 201)],
        ];
        foreach ($invalides as $modif) {
            try {
                $this->commandes->creer(array_merge($entete, $modif), $lignes);
                $this->fail('Une en-tête invalide devrait être refusée : ' . json_encode($modif));
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(0, Commande::count());

        $commande = $this->commande($lignes);
        $ligneId = $commande->lignes[0]->id;
        foreach ([
            ['date_reception' => 'demain', 'bon_livraison' => 'BL'],
            ['date_reception' => '2026-11-10', 'bon_livraison' => str_repeat('B', 101)],
        ] as $enteteReception) {
            try {
                $this->commandes->recevoir($commande, $enteteReception, [$ligneId => ['acceptee' => 5, 'rejetee' => 0]]);
                $this->fail('Une en-tête de réception invalide devrait être refusée.');
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(0, ReceptionFournisseur::count());
        $this->assertSame(0, MouvementStock::count());
    }

    public function test_reste_a_recevoir_est_commande_moins_accepte_moins_abandonne(): void
    {
        $ligne = new CommandeLigne([
            'quantite_commandee' => 100, 'quantite_acceptee' => 60, 'quantite_rejetee' => 10, 'quantite_abandonnee' => 15,
        ]);

        $this->assertSame(25, $ligne->resteARecevoir());
    }
}

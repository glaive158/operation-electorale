<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\CleRepartition;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\EntiteBulletin;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Materiel;
use App\Models\User;
use App\Services\Logistique\CleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\Support\RecensementFictif;
use Tests\TestCase;

class ClesEntitesTest extends TestCase
{
    use RefreshDatabase;

    private CleService $cles;

    private Cycle $cycle;

    private Materiel $urne;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cles = app(CleService::class);
        $this->actingAs(User::factory()->create(['role' => 'logistique_responsable']));

        $this->cycle = Cycle::create(['libelle' => 'Cycle A', 'type' => 'scrutin']);
        $this->urne = Materiel::create([
            'libelle' => 'Urne', 'categorie' => 'materiel_lourd', 'unite_stock' => 'pièce',
        ]);
    }

    private function valeursUrne(array $surcharge = []): array
    {
        return array_merge([
            'base' => 'bureau_vote', 'par_entite' => false, 'ratio' => '1',
            'cle_affichee' => '1 par bureau de vote',
        ], $surcharge);
    }

    public function test_cle_creee_puis_modifiee_avec_journal(): void
    {
        $cle = $this->cles->enregistrer($this->cycle, $this->urne, $this->valeursUrne());

        $this->assertSame('bureau_vote', $cle->base);
        $this->assertSame('1.0000', $cle->ratio);
        $this->assertSame('unite', $cle->arrondi);
        $this->assertSame(0, $cle->minimum);
        $this->assertNull($cle->majoration_distribuee);
        $this->assertSame(1, JournalEntree::where('objet_type', 'cle_repartition')->where('action', 'creation_cle')->count());

        $modifiee = $this->cles->enregistrer($this->cycle, $this->urne, $this->valeursUrne([
            'ratio' => '2.5', 'minimum' => 3, 'majoration_distribuee' => '5',
        ]));

        $this->assertSame($cle->id, $modifiee->id);
        $this->assertSame(1, CleRepartition::count());
        $this->assertSame('2.5000', $modifiee->ratio);
        $this->assertSame('5.00', $modifiee->majoration_distribuee);

        $entree = JournalEntree::where('objet_type', 'cle_repartition')->where('action', 'modification_cle')->firstOrFail();
        $this->assertSame('1.0000', $entree->avant['ratio']);
        $this->assertSame('2.5000', $entree->apres['ratio']);
    }

    public function test_minimum_par_commune_interdit_pour_forfait(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cles->enregistrer($this->cycle, $this->urne, $this->valeursUrne([
            'base' => 'forfait', 'minimum_par_commune' => true,
        ]));
    }

    public function test_minimum_par_commune_interdit_pour_circonscription(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cles->enregistrer($this->cycle, $this->urne, $this->valeursUrne([
            'base' => 'circonscription', 'minimum_par_commune' => true,
        ]));
    }

    public function test_conditionnement_exige_un_facteur(): void
    {
        try {
            $this->cles->enregistrer($this->cycle, $this->urne, $this->valeursUrne(['arrondi_conditionnement' => true]));
            $this->fail('Un arrondi au conditionnement sans facteur doit être refusé.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, CleRepartition::count());
        }

        $this->urne->update(['facteur_conditionnement' => 50]);
        $cle = $this->cles->enregistrer($this->cycle, $this->urne, $this->valeursUrne(['arrondi_conditionnement' => true]));
        $this->assertTrue($cle->arrondi_conditionnement);
    }

    public function test_ratio_et_pourcentages_valides_en_decimal_exact(): void
    {
        foreach (['0', '-1', '100000.0001', 'abc', '', '1.00001'] as $ratio) {
            try {
                $this->cles->enregistrer($this->cycle, $this->urne, $this->valeursUrne(['ratio' => $ratio]));
                $this->fail("Ratio « {$ratio} » accepté à tort.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $cle = $this->cles->enregistrer($this->cycle, $this->urne, $this->valeursUrne(['ratio' => '100000']));
        $this->assertSame('100000.0000', $cle->ratio);

        foreach (['-0.01', '100.01', 'x'] as $pct) {
            foreach (['majoration_distribuee', 'reserve_centrale'] as $champ) {
                try {
                    $this->cles->enregistrer($this->cycle, $this->urne, $this->valeursUrne([$champ => $pct]));
                    $this->fail("{$champ} « {$pct} » accepté à tort.");
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }

        $cle = $this->cles->enregistrer($this->cycle, $this->urne, $this->valeursUrne([
            'majoration_distribuee' => '100', 'reserve_centrale' => '0',
        ]));
        $this->assertSame('100.00', $cle->majoration_distribuee);
        $this->assertSame('0.00', $cle->reserve_centrale);
    }

    public function test_cycle_clos_refuse(): void
    {
        $this->cles->enregistrer($this->cycle, $this->urne, $this->valeursUrne());
        Cycle::whereKey($this->cycle->id)->update(['statut' => 'clos']);

        $this->expectException(RegleLogistique::class);
        $this->cles->enregistrer($this->cycle, $this->urne, $this->valeursUrne(['ratio' => '2']));
    }

    public function test_sans_auteur_refuse(): void
    {
        auth()->logout();

        $this->expectException(LogicException::class);
        $this->cles->enregistrer($this->cycle, $this->urne, $this->valeursUrne());
    }

    public function test_ajouter_entite_cree_article_et_cle(): void
    {
        $entite = $this->cles->ajouterEntite($this->cycle, ['type' => 'liste', 'nom' => 'Liste A', 'ordre' => 1]);

        $this->assertSame('Bulletin de vote — Liste A', $entite->materiel->libelle);
        $this->assertSame('imprime', $entite->materiel->categorie);
        $this->assertSame('Bulletins de vote', $entite->materiel->regroupement);
        $this->assertTrue($entite->materiel->indivisible);
        $this->assertFalse($entite->materiel->reutilisable);
        $this->assertTrue($entite->national);
        $this->assertTrue($entite->materiel->entiteBulletin()->exists());

        $cle = CleRepartition::where('cycle_id', $this->cycle->id)->where('materiel_id', $entite->materiel_id)->firstOrFail();
        $this->assertSame('electeur', $cle->base);
        $this->assertSame('1.0000', $cle->ratio);
        $this->assertFalse($cle->par_entite);
        $this->assertSame("Nombre d'inscrits du B.V", $cle->cle_affichee);
    }

    public function test_ajouter_entite_refuse_un_nom_en_double_ou_invalide(): void
    {
        $this->cles->ajouterEntite($this->cycle, ['type' => 'liste', 'nom' => 'Liste A', 'ordre' => 1]);

        foreach ([
            ['type' => 'liste', 'nom' => 'Liste A', 'ordre' => 2],
            ['type' => 'parti', 'nom' => 'Liste B', 'ordre' => 2],
            ['type' => 'liste', 'nom' => '  ', 'ordre' => 2],
            ['type' => 'liste', 'nom' => 'Liste C', 'ordre' => 2, 'national' => false],
        ] as $donnees) {
            try {
                $this->cles->ajouterEntite($this->cycle, $donnees);
                $this->fail('Entité invalide acceptée : '.json_encode($donnees));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, EntiteBulletin::count());
        $this->assertSame(1, Materiel::where('categorie', 'imprime')->count());
    }

    public function test_entite_departementale_ne_couvre_que_son_departement(): void
    {
        RecensementFictif::creerSchema();
        $geo = RecensementFictif::geo([
            'Thiès' => ['Mbour' => ['Sindia' => ['Ndiass']], 'Thiès' => ['Thiès Nord' => ['Thiès 1']]],
        ]);
        $mbour = $geo['departements']['Mbour'];
        $thies = $geo['departements']['Thiès'];

        $entite = $this->cles->ajouterEntite($this->cycle, [
            'type' => 'candidat', 'nom' => 'Candidat X', 'ordre' => 2, 'national' => false,
            'perimetre' => ['departements' => [$mbour]],
        ]);

        $this->assertTrue($entite->couvre($mbour, null, null));
        $this->assertFalse($entite->couvre($thies, null, null));
        $this->assertFalse($entite->couvre(null, $geo['communes']['Ndiass'], null));
        $this->assertFalse($entite->couvre(null, null, 'France'));

        $communale = $this->cles->ajouterEntite($this->cycle, [
            'type' => 'liste', 'nom' => 'Liste Ndiass', 'ordre' => 3, 'national' => false,
            'perimetre' => ['communes' => [$geo['communes']['Ndiass']], 'pays' => ['France']],
        ]);
        $this->assertTrue($communale->couvre(null, $geo['communes']['Ndiass'], null));
        $this->assertTrue($communale->couvre(null, null, 'France'));
        $this->assertFalse($communale->couvre($mbour, null, null));

        $nationale = $this->cles->ajouterEntite($this->cycle, ['type' => 'liste', 'nom' => 'Liste N', 'ordre' => 4]);
        $this->assertTrue($nationale->couvre($thies, null, null));
        $this->assertTrue($nationale->couvre(null, null, 'Italie'));
    }

    public function test_cle_bulletin_doit_rester_par_electeur(): void
    {
        $entite = $this->cles->ajouterEntite($this->cycle, ['type' => 'liste', 'nom' => 'Liste A', 'ordre' => 1]);

        foreach ([['base' => 'bureau_vote'], ['par_entite' => true]] as $surcharge) {
            try {
                $this->cles->enregistrer($this->cycle, $entite->materiel, array_merge(
                    ['base' => 'electeur', 'par_entite' => false, 'ratio' => '1'],
                    $surcharge,
                ));
                $this->fail('Une clé de bulletin non par électeur doit être refusée.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $cle = $this->cles->enregistrer($this->cycle, $entite->materiel, [
            'base' => 'electeur', 'par_entite' => false, 'ratio' => '1.2',
        ]);
        $this->assertSame('1.2000', $cle->ratio);
    }

    public function test_renommer_entite_met_a_jour_l_article(): void
    {
        $entite = $this->cles->ajouterEntite($this->cycle, ['type' => 'liste', 'nom' => 'Liste A', 'ordre' => 1]);

        $this->cles->renommerEntite($entite, 'Liste Alpha');

        $this->assertSame('Liste Alpha', $entite->fresh()->nom);
        $this->assertSame('Bulletin de vote — Liste Alpha', $entite->materiel->fresh()->libelle);
        $this->assertSame(1, JournalEntree::where('objet_type', 'entite_bulletin')->where('action', 'renommage_entite')->count());
    }

    public function test_copier_ignore_les_bulletins_et_l_existant(): void
    {
        $tente = Materiel::create(['libelle' => 'Tente', 'categorie' => 'materiel_lourd']);
        $this->cles->enregistrer($this->cycle, $this->urne, $this->valeursUrne());
        $this->cles->enregistrer($this->cycle, $tente, $this->valeursUrne(['base' => 'lieu_vote']));
        $this->cles->ajouterEntite($this->cycle, ['type' => 'liste', 'nom' => 'Liste A', 'ordre' => 1]);

        $cible = Cycle::create(['libelle' => 'Cycle B', 'type' => 'scrutin']);
        $this->cles->enregistrer($cible, $tente, $this->valeursUrne(['base' => 'ca', 'ratio' => '3']));

        $this->assertSame(1, $this->cles->copier($this->cycle, $cible));

        $this->assertSame(2, CleRepartition::where('cycle_id', $cible->id)->count());
        $copiee = CleRepartition::where('cycle_id', $cible->id)->where('materiel_id', $this->urne->id)->firstOrFail();
        $this->assertSame('bureau_vote', $copiee->base);
        $this->assertSame('ca', CleRepartition::where('cycle_id', $cible->id)->where('materiel_id', $tente->id)->value('base'));

        $this->assertSame(0, $this->cles->copier($this->cycle, $cible));
    }

    public function test_majoration_effective_derogation_ou_cycle(): void
    {
        $this->cycle->update(['majoration_distribuee' => 10, 'reserve_centrale' => 2]);
        $cle = $this->cles->enregistrer($this->cycle, $this->urne, $this->valeursUrne());

        $this->assertSame('10.00', $cle->majorationEffective());
        $this->assertSame('2.00', $cle->reserveEffective());

        $cle = $this->cles->enregistrer($this->cycle, $this->urne, $this->valeursUrne([
            'majoration_distribuee' => '0', 'reserve_centrale' => '7.5',
        ]));
        $this->assertSame('0.00', $cle->majorationEffective());
        $this->assertSame('7.50', $cle->reserveEffective());
    }

    public function test_cycle_sans_valeur_chargee_donne_quand_meme_la_valeur_du_cycle(): void
    {
        // Cycle créé sans les colonnes à défaut : le modèle ne les porte pas avant relecture.
        $cle = $this->cles->enregistrer($this->cycle, $this->urne, $this->valeursUrne());

        $this->assertSame('10.00', CleRepartition::findOrFail($cle->id)->majorationEffective());
        $this->assertSame('0.00', CleRepartition::findOrFail($cle->id)->reserveEffective());
    }
}

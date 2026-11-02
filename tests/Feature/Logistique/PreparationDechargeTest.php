<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\BesoinRemplacement;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\DechargeLigne;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\PerimetreDestinataire;
use App\Models\Logistique\PlanDotation;
use App\Models\Logistique\PlanDotationLigne;
use App\Models\User;
use App\Services\Logistique\DechargeService;
use App\Services\Logistique\EngagementService;
use App\Services\Logistique\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\PlanApprouveFictif;
use Tests\Support\RecensementFictif;
use Tests\TestCase;

class PreparationDechargeTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerDecorFictif();
    }

    private function service(): DechargeService
    {
        return app(DechargeService::class);
    }

    public function test_preparer_initiale_avec_le_reste(): void
    {
        $plan = $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]], [$this->urnes->id => '1 par bureau de vote']);

        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');

        $this->assertSame('brouillon', $decharge->statut_document);
        $this->assertSame('a_preparer', $decharge->statut_acheminement);
        $this->assertSame('aucune', $decharge->statut_reclamation);
        $this->assertSame('initiale', $decharge->nature);
        $this->assertSame($plan->id, (int) $decharge->plan_dotation_id);
        $this->assertSame($this->responsable->id, (int) $decharge->prepare_par);
        $this->assertNull($decharge->circuit);
        $this->assertNull($decharge->remis_a_id);
        $this->assertNull($decharge->numero);
        $this->assertSame(26, strlen($decharge->identifiant));

        $this->assertCount(1, $decharge->lignes);
        $ligne = $decharge->lignes->first();
        $this->assertSame($this->urnes->id, $ligne->materiel_id);
        $this->assertSame(100, $ligne->quantite);
        $this->assertSame(100, $ligne->dotation_approuvee);
        $this->assertSame(0, $ligne->deja_engage);
        $this->assertSame('1 par bureau de vote', $ligne->cle_affichee);
        $this->assertFalse($decharge->estPartie());

        $entree = JournalEntree::where('action', 'preparation_decharge')->firstOrFail();
        $this->assertSame($decharge->id, (int) $entree->objet_id);
    }

    public function test_rien_a_expedier_refuse(): void
    {
        // Pas de ligne de plan pour cette catégorie.
        $this->approuverPlanFictif([[$this->prefecture, $this->enveloppes, 100]]);

        try {
            $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
            $this->fail('Une préparation sans rien à expédier doit être refusée.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Rien à expédier pour cette catégorie.', $e->getMessage());
        }

        // Dotation entièrement engagée par une décharge validée.
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $validee = $this->deposerValidee($this->prefecture, 'materiel_lourd', [[$this->urnes, 100]]);
        $this->assertNotNull($validee);

        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Rien à expédier pour cette catégorie.');
        $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
    }

    public function test_brouillon_unique_par_destinataire_et_categorie(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->sousPrefecture1, $this->urnes, 10]]);
        $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');

        // Un autre destinataire n'est pas concerné.
        $this->service()->preparer($this->cycle, $this->sousPrefecture1, 'materiel_lourd');

        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Une décharge brouillon existe déjà pour ce destinataire et cette catégorie.');
        $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
    }

    public function test_aucun_plan_approuve_refuse(): void
    {
        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Aucun plan de dotation approuvé.');
        $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
    }

    public function test_categories_separees(): void
    {
        $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 100],
            [$this->prefecture, $this->isoloirs, 40],
            [$this->prefecture, $this->enveloppes, 5000],
        ]);

        $lourd = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $imprime = $this->service()->preparer($this->cycle, $this->prefecture, 'imprime');

        $this->assertNotSame($lourd->id, $imprime->id);
        $this->assertSame([$this->urnes->id, $this->isoloirs->id], $lourd->lignes()->orderBy('materiel_id')->pluck('materiel_id')->all());
        $this->assertSame([$this->enveloppes->id], $imprime->lignes()->pluck('materiel_id')->all());
        $this->assertSame(2, Decharge::where('statut_document', 'brouillon')->count());
    }

    public function test_modifier_ligne_brouillon_seulement(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $ligne = $decharge->lignes->first();

        $this->service()->modifierLigne($ligne, 60, '  Livraison partielle ');

        $this->assertSame(60, $ligne->quantite);
        $this->assertSame('Livraison partielle', $ligne->observations);
        $entree = JournalEntree::where('action', 'modification_ligne_decharge')->firstOrFail();
        $this->assertSame(100, $entree->avant['quantite']);
        $this->assertSame(60, $entree->apres['quantite']);

        $this->service()->modifierLigne($ligne, 0, null);
        $this->assertSame(0, $ligne->fresh()->quantite);

        foreach ([-1, 10_000_001] as $invalide) {
            try {
                $this->service()->modifierLigne($ligne, $invalide, null);
                $this->fail('Quantité hors limites acceptée.');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, $ligne->fresh()->quantite);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $this->service()->modifierLigne($ligne, 5, str_repeat('x', 501));
    }

    public function test_modifier_ligne_refuse_hors_brouillon(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $decharge->forceFill(['statut_document' => 'validee'])->save();

        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Seul un brouillon est modifiable.');
        $this->service()->modifierLigne($decharge->lignes->first(), 50, null);
    }

    public function test_droit_gerer_exige(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->actingAs(User::factory()->create(['role' => 'prefet']));

        $this->expectException(RegleLogistique::class);
        $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
    }

    public function test_cycle_clos_refuse(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->cycle->forceFill(['statut' => 'clos'])->save();

        $this->expectException(RegleLogistique::class);
        $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
    }

    public function test_supprimer_brouillon_journalise(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->prefecture, $this->isoloirs, 40]]);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $id = $decharge->id;

        $this->service()->supprimerBrouillon($decharge, 'Préparée par erreur');

        $this->assertDatabaseMissing('decharges', ['id' => $id]);
        $this->assertSame(0, DechargeLigne::count());
        $entree = JournalEntree::where('action', 'suppression_decharge_brouillon')->firstOrFail();
        $this->assertSame($id, (int) $entree->objet_id);
        $this->assertSame('Préparée par erreur', $entree->motif);
        $this->assertCount(2, $entree->avant['lignes']);
        $this->assertSame(100, $entree->avant['lignes'][0]['quantite']);
    }

    public function test_supprimer_exige_motif_et_brouillon(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');

        try {
            $this->service()->supprimerBrouillon($decharge, '   ');
            $this->fail('Motif vide accepté.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseHas('decharges', ['id' => $decharge->id]);
        }

        $decharge->forceFill(['statut_document' => 'validee'])->save();
        $this->expectException(RegleLogistique::class);
        $this->service()->supprimerBrouillon($decharge, 'Motif');
    }

    public function test_actualiser_rattache_au_nouveau_plan(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->prefecture, $this->isoloirs, 40]]);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->service()->modifierLigne($decharge->lignes->firstWhere('materiel_id', $this->urnes->id), 70, 'Partiel');

        $plan2 = $this->approuverPlanFictif(
            [[$this->prefecture, $this->urnes, 120], [$this->prefecture, $this->enveloppes, 10]],
            [$this->urnes->id => 'Nouvelle clé'],
        );
        // Nouvel article de la catégorie, absent de la v1 ; l'isoloir disparaît du plan.
        $nouveau = Materiel::create(['libelle' => 'Urnes de secours', 'categorie' => 'materiel_lourd', 'unite_stock' => 'pièce', 'ordre' => 9]);
        PlanDotationLigne::create([
            'plan_dotation_id' => $plan2->id, 'destinataire_id' => $this->prefecture->id, 'materiel_id' => $nouveau->id,
            'effectif' => 15, 'exact' => '15', 'majoration' => '0.00', 'minimum' => 0, 'arrondi' => 'unite', 'dotation_calculee' => 15,
        ])->forceFill(['dotation_approuvee' => 15])->save();

        $this->service()->actualiser($decharge);

        $this->assertSame($plan2->id, (int) $decharge->plan_dotation_id);
        $lignes = $decharge->lignes()->get()->keyBy('materiel_id');
        $this->assertSame([$this->urnes->id, $nouveau->id], $lignes->keys()->sort()->values()->all());
        $this->assertSame(120, $lignes[$this->urnes->id]->dotation_approuvee);
        $this->assertSame(70, $lignes[$this->urnes->id]->quantite);
        $this->assertSame('Partiel', $lignes[$this->urnes->id]->observations);
        $this->assertSame('Nouvelle clé', $lignes[$this->urnes->id]->cle_affichee);
        $this->assertSame(15, $lignes[$nouveau->id]->quantite);
        $this->assertNotNull(JournalEntree::where('action', 'actualisation_decharge')->first());
    }

    public function test_actualiser_refuse_hors_brouillon(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $decharge->forceFill(['statut_document' => 'annulee'])->save();

        $this->expectException(RegleLogistique::class);
        $this->service()->actualiser($decharge);
    }

    public function test_lot_par_departement(): void
    {
        $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 100],
            [$this->sousPrefecture1, $this->urnes, 30],
            [$this->poste, $this->urnes, 8],
        ]);

        $res = $this->service()->preparerLot($this->cycle, 'materiel_lourd', ['departement_id' => 10]);

        // Préfecture et sous-préfecture 1 ont un reste ; la sous-préfecture 2 n'a rien ; le poste est hors département.
        $this->assertSame(['creees' => 2, 'ignorees' => 1], $res);
        $this->assertEqualsCanonicalizing(
            [$this->prefecture->id, $this->sousPrefecture1->id],
            Decharge::pluck('destinataire_id')->all(),
        );

        // Rejoué : les brouillons existants sont ignorés.
        $this->assertSame(['creees' => 0, 'ignorees' => 3], $this->service()->preparerLot($this->cycle, 'materiel_lourd', ['departement_id' => 10]));
    }

    public function test_lot_etranger_et_sans_filtre(): void
    {
        $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 100],
            [$this->sousPrefecture2, $this->urnes, 30],
            [$this->poste, $this->urnes, 8],
        ]);

        $this->assertSame(['creees' => 1, 'ignorees' => 0], $this->service()->preparerLot($this->cycle, 'materiel_lourd', ['etranger' => true]));
        $this->assertSame($this->poste->id, (int) Decharge::firstOrFail()->destinataire_id);

        // Tous les destinataires actifs : le poste a déjà son brouillon, la sous-préfecture 1 n'a rien.
        $this->assertSame(['creees' => 2, 'ignorees' => 2], $this->service()->preparerLot($this->cycle, 'materiel_lourd', []));

        // Un destinataire inactif n'est pas visé.
        $this->sousPrefecture2->forceFill(['actif' => false])->save();
        $brouillon = Decharge::where('destinataire_id', $this->sousPrefecture2->id)->firstOrFail();
        $brouillon->lignes()->delete();
        $brouillon->delete();
        $this->assertSame(['creees' => 0, 'ignorees' => 3], $this->service()->preparerLot($this->cycle, 'materiel_lourd', []));
    }

    public function test_lot_par_region(): void
    {
        RecensementFictif::creerSchema();
        $geo = RecensementFictif::geo(['Thiès' => ['Thiès' => []], 'Dakar' => ['Dakar' => []]]);
        $this->prefecture->forceFill(['departement_id' => $geo['departements']['Thiès']])->save();
        $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 100],
            [$this->sousPrefecture1, $this->urnes, 30],
            [$this->poste, $this->urnes, 8],
        ]);

        $res = $this->service()->preparerLot($this->cycle, 'materiel_lourd', ['region_id' => $geo['regions']['Thiès']]);
        $this->assertSame(['creees' => 2, 'ignorees' => 1], $res);

        $this->assertSame(['creees' => 0, 'ignorees' => 0], $this->service()->preparerLot($this->cycle, 'materiel_lourd', ['region_id' => $geo['regions']['Dakar']]));
    }

    public function test_lot_filtre_invalide_et_atomique(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);

        foreach ([['departement_id' => 10, 'etranger' => true], ['etranger' => false], ['commune_id' => 1]] as $filtre) {
            try {
                $this->service()->preparerLot($this->cycle, 'materiel_lourd', $filtre);
                $this->fail('Filtre invalide accepté.');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, Decharge::count());
            }
        }
    }

    public function test_nature_reliquat_apres_une_validee(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->deposerValidee($this->prefecture, 'materiel_lourd', [[$this->urnes, 60]]);

        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');

        $this->assertSame('reliquat', $decharge->nature);
        $this->assertSame(40, $decharge->lignes->first()->quantite);
        $this->assertSame(100, $decharge->lignes->first()->dotation_approuvee);
    }

    public function test_engagement_etat_et_requetes(): void
    {
        $engagements = app(EngagementService::class);
        $this->assertTrue($engagements->etat($this->cycle, $this->prefecture)->isEmpty());

        $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 100], [$this->prefecture, $this->isoloirs, 40],
            [$this->prefecture, $this->enveloppes, 50], [$this->sousPrefecture1, $this->urnes, 7],
        ]);
        $this->deposerValidee($this->prefecture, 'materiel_lourd', [[$this->urnes, 130], [$this->isoloirs, 15]]);
        $this->deposerValidee($this->prefecture, 'materiel_lourd', [[$this->isoloirs, 5]]);
        // Brouillon et annulée ne comptent pas ; une autre destinataire non plus.
        $this->deposerValidee($this->prefecture, 'materiel_lourd', [[$this->isoloirs, 9]], 'brouillon');
        $this->deposerValidee($this->prefecture, 'materiel_lourd', [[$this->isoloirs, 9]], 'annulee');
        $this->deposerValidee($this->sousPrefecture1, 'materiel_lourd', [[$this->urnes, 2]]);

        DB::enableQueryLog();
        $etat = $engagements->etat($this->cycle, $this->prefecture);
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(2, $requetes);
        $this->assertEqualsCanonicalizing([$this->urnes->id, $this->isoloirs->id, $this->enveloppes->id], $etat->keys()->all());
        $this->assertSame(['dotation' => 100, 'engage' => 130, 'reste' => 0], collect($etat[$this->urnes->id])->only(['dotation', 'engage', 'reste'])->all());
        $this->assertSame(['dotation' => 40, 'engage' => 20, 'reste' => 20], collect($etat[$this->isoloirs->id])->only(['dotation', 'engage', 'reste'])->all());
        $this->assertSame(0, $etat[$this->enveloppes->id]['engage']);
        $this->assertSame($this->urnes->id, $etat[$this->urnes->id]['materiel']->id);

        $this->assertSame([$this->enveloppes->id], $engagements->etat($this->cycle, $this->prefecture, 'imprime')->keys()->all());
        $this->assertSame(20, $engagements->engage($this->cycle, $this->prefecture, $this->isoloirs));
        $this->assertSame(0, $engagements->engage($this->cycle, $this->poste, $this->isoloirs));
    }

    public function test_preparation_sans_effet_sur_le_stock(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 300);
        $stock = app(StockService::class);
        $avant = [$stock->reserve($this->urnes, $this->cycle), $stock->attribue($this->urnes, $this->cycle), $stock->libre($this->urnes)];

        $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');

        $this->assertSame($avant, [$stock->reserve($this->urnes, $this->cycle), $stock->attribue($this->urnes, $this->cycle), $stock->libre($this->urnes)]);
        $this->assertSame(0, $stock->reserve($this->urnes, $this->cycle));
        $this->assertDatabaseCount('reservations_stock', 0);
        $this->assertNull(DechargeLigne::firstOrFail()->reservation_id);
    }

    public function test_plan_fictif_ecrit_instantane_et_perimetres(): void
    {
        $plan = $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->poste, $this->urnes, 8]]);

        $instantane = $plan->instantane;
        $this->assertTrue($instantane->estUtilisable());
        $this->assertSame(4, $instantane->lignes()->count());
        $this->assertSame(4, PerimetreDestinataire::where('cycle_id', $this->cycle->id)->count());

        // Une v2 réutilise les périmètres et fige un nouvel instantané.
        $plan2 = $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 120]]);
        $this->assertSame(4, PerimetreDestinataire::where('cycle_id', $this->cycle->id)->count());
        $this->assertSame(2, $plan2->version);
        $this->assertSame('remplace', $plan->fresh()->statut);
    }

    /**
     * Décharge posée directement dans un statut donné (la validation n'existe pas encore).
     *
     * @param  list<array{0: Materiel, 1: int}>  $lignes
     */
    private function remplacement(Destinataire $destinataire, Materiel $article, int $quantite): BesoinRemplacement
    {
        return BesoinRemplacement::create([
            'cycle_id' => $this->cycle->id, 'destinataire_id' => $destinataire->id, 'materiel_id' => $article->id,
            'quantite' => $quantite, 'motif' => 'Matériel perdu', 'autorise_par' => $this->responsable->id,
        ]);
    }

    public function test_brouillon_initial_hors_besoins_de_remplacement(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->prefecture, $this->isoloirs, 40]]);
        $this->remplacement($this->prefecture, $this->urnes, 10);

        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');

        $this->assertSame('initiale', $decharge->nature);
        $this->assertSame(100, $decharge->lignes->firstWhere('materiel_id', $this->urnes->id)->quantite);
        $this->assertSame(40, $decharge->lignes->firstWhere('materiel_id', $this->isoloirs->id)->quantite);
    }

    public function test_reliquat_sans_ligne_pour_un_article_entierement_engage_malgre_un_remplacement(): void
    {
        $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 100], [$this->prefecture, $this->isoloirs, 40], [$this->sousPrefecture1, $this->urnes, 50],
        ]);
        $this->deposerValidee($this->prefecture, 'materiel_lourd', [[$this->urnes, 100]]);
        $this->remplacement($this->prefecture, $this->urnes, 10);

        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');

        $this->assertSame('reliquat', $decharge->nature);
        $this->assertSame([$this->isoloirs->id], $decharge->lignes->pluck('materiel_id')->all());

        $this->deposerValidee($this->sousPrefecture1, 'materiel_lourd', [[$this->urnes, 50]]);
        $this->remplacement($this->sousPrefecture1, $this->urnes, 10);
        try {
            $this->service()->preparer($this->cycle, $this->sousPrefecture1, 'materiel_lourd');
            $this->fail('Un besoin de remplacement seul ne donne pas de brouillon initial.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Rien à expédier pour cette catégorie.', $e->getMessage());
        }
    }

    public function test_actualiser_n_ajoute_pas_un_article_couvert_par_un_remplacement(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->prefecture, $this->isoloirs, 40]]);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $decharge->lignes()->where('materiel_id', $this->urnes->id)->delete();
        $this->deposerValidee($this->prefecture, 'materiel_lourd', [[$this->urnes, 100]]);
        $this->remplacement($this->prefecture, $this->urnes, 10);

        $this->service()->actualiser($decharge);

        $this->assertSame([$this->isoloirs->id], $decharge->lignes()->pluck('materiel_id')->all());
    }

    public function test_remplacer_une_annulee_plafonne_hors_remplacements(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $annulee = $this->deposerValidee($this->prefecture, 'materiel_lourd', [[$this->urnes, 100]], 'annulee');
        $this->deposerValidee($this->prefecture, 'materiel_lourd', [[$this->urnes, 60]]);
        $this->remplacement($this->prefecture, $this->urnes, 10);

        $remplacante = $this->service()->remplacer($annulee);

        $this->assertSame(40, $remplacante->lignes->first()->quantite);
    }

    public function test_les_remplacements_valides_ne_comptent_pas_comme_engage_initial(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->remplacement($this->prefecture, $this->urnes, 10);
        $remplacement = $this->deposerValidee($this->prefecture, 'materiel_lourd', [[$this->urnes, 10]]);
        $remplacement->forceFill(['nature' => 'remplacement'])->save();

        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');

        $this->assertSame('initiale', $decharge->nature);
        $this->assertSame(100, $decharge->lignes->first()->quantite);
    }

    private function deposerValidee(Destinataire $destinataire, string $categorie, array $lignes, string $statut = 'validee'): Decharge
    {
        $decharge = Decharge::create([
            'cycle_id' => $this->cycle->id, 'destinataire_id' => $destinataire->id, 'categorie' => $categorie,
            'nature' => 'initiale', 'plan_dotation_id' => PlanDotation::courantApprouve($this->cycle)->id,
            'prepare_par' => $this->responsable->id,
        ]);
        $decharge->forceFill(['statut_document' => $statut])->save();
        foreach ($lignes as [$article, $quantite]) {
            DechargeLigne::create([
                'decharge_id' => $decharge->id, 'materiel_id' => $article->id, 'dotation_approuvee' => $quantite, 'quantite' => $quantite,
            ]);
        }

        return $decharge;
    }
}

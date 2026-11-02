<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Exceptions\Logistique\StockInsuffisant;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\DechargeLigne;
use App\Models\Logistique\EntiteBulletin;
use App\Models\Logistique\InstantaneLigne;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\PerimetreDestinataire;
use App\Models\Logistique\PlanDotation;
use App\Models\Logistique\ReservationStock;
use App\Models\User;
use App\Services\Logistique\DechargeService;
use App\Services\Logistique\DonneesDecharge;
use App\Services\Logistique\PlanDotationService;
use App\Services\Logistique\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class ValidationDechargeTest extends TestCase
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

    private function service(): DechargeService
    {
        return app(DechargeService::class);
    }

    public function test_validation_reserve_chaque_ligne(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->prefecture, $this->isoloirs, 40]]);
        $this->stockerFictif($this->urnes, 500);
        $this->stockerFictif($this->isoloirs, 500);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');

        $this->service()->valider($decharge);

        $decharge->refresh();
        $this->assertSame('validee', $decharge->statut_document);
        $this->assertSame($this->responsable->id, (int) $decharge->valide_par);
        $this->assertNotNull($decharge->valide_le);
        $this->assertSame(100, $this->stock->reserve($this->urnes, $this->cycle));
        $this->assertSame(40, $this->stock->reserve($this->isoloirs, $this->cycle));

        foreach ($decharge->lignes()->get() as $ligne) {
            $this->assertNotNull($ligne->reservation_id);
            $reservation = ReservationStock::findOrFail($ligne->reservation_id);
            $this->assertSame($ligne->quantite, $reservation->quantite);
            $this->assertSame($ligne->materiel_id, $reservation->materiel_id);
            $this->assertSame('decharge_lignes', $reservation->reference_type);
            $this->assertSame($ligne->id, $reservation->reference_id);
            $this->assertSame(0, $ligne->deja_engage);
        }

        $entree = JournalEntree::where('action', 'validation_decharge')->firstOrFail();
        $this->assertSame($decharge->id, (int) $entree->objet_id);
        $this->assertCount(2, $entree->apres['lignes']);
    }

    public function test_plafond_deux_decharges_de_100_sur_dotation_100(): void
    {
        $plan = $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 300);
        $d1 = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');

        $d2 = Decharge::create([
            'cycle_id' => $this->cycle->id, 'destinataire_id' => $this->prefecture->id, 'categorie' => 'materiel_lourd',
            'nature' => 'initiale', 'plan_dotation_id' => $plan->id, 'prepare_par' => $this->responsable->id,
        ]);
        DechargeLigne::create([
            'decharge_id' => $d2->id, 'materiel_id' => $this->urnes->id, 'dotation_approuvee' => 100, 'quantite' => 100,
        ]);

        $this->service()->valider($d1);
        $this->assertSame(100, $this->stock->reserve($this->urnes, $this->cycle));

        try {
            $this->service()->valider($d2);
            $this->fail('Le plafond du besoin doit être refusé.');
        } catch (RegleLogistique $e) {
            $this->assertStringContainsString('Plafond du besoin dépassé', $e->getMessage());
            $this->assertSame('Plafond du besoin dépassé pour Urnes : besoin 100, déjà engagé 100, demandé 100.', $e->getMessage());
        }

        $this->assertSame(100, $this->stock->reserve($this->urnes, $this->cycle));
        $this->assertSame('brouillon', $d2->fresh()->statut_document);
        $this->assertNull($d2->fresh()->numero);
    }

    public function test_plafond_vise_aussi_une_quantite_relevee_au_dessus_du_reste(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 300);
        $d1 = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->service()->modifierLigne($d1->lignes->first(), 60, null);
        $this->service()->valider($d1);

        $reliquat = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->assertSame(40, $reliquat->lignes->first()->quantite);
        $this->service()->modifierLigne($reliquat->lignes->first(), 50, null);

        try {
            $this->service()->valider($reliquat);
            $this->fail('Une quantité au-dessus du reste doit être refusée.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Plafond du besoin dépassé pour Urnes : besoin 100, déjà engagé 60, demandé 50.', $e->getMessage());
        }
        $this->assertSame(60, $this->stock->reserve($this->urnes, $this->cycle));
        $this->assertSame('brouillon', $reliquat->fresh()->statut_document);
    }

    public function test_livraison_partielle_60_puis_reliquat_40(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 60);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->service()->modifierLigne($decharge->lignes->first(), 60, 'Livraison partielle');
        $this->service()->valider($decharge);

        $reliquat = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');

        $this->assertSame('reliquat', $reliquat->nature);
        $this->assertCount(1, $reliquat->lignes);
        $this->assertSame(40, $reliquat->lignes->first()->quantite);
        $this->assertSame(100, $reliquat->lignes->first()->dotation_approuvee);
        $this->assertSame(60, $decharge->lignes()->first()->quantite);
    }

    public function test_stock_insuffisant_rien_reserve(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->prefecture, $this->isoloirs, 40]]);
        $this->stockerFictif($this->urnes, 500);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');

        try {
            $this->service()->valider($decharge);
            $this->fail('Un stock insuffisant doit être refusé.');
        } catch (StockInsuffisant) {
            $this->assertSame(0, $this->stock->reserve($this->urnes, $this->cycle));
            $this->assertSame(0, ReservationStock::count());
        }

        $decharge->refresh();
        $this->assertSame('brouillon', $decharge->statut_document);
        $this->assertNull($decharge->numero);
        $this->assertNull($decharge->donnees_gelees);
        $this->assertSame(2, $decharge->lignes()->count());
        $this->assertSame(0, $decharge->lignes()->whereNotNull('reservation_id')->count());
        $this->assertSame(0, JournalEntree::where('action', 'validation_decharge')->count());
    }

    public function test_attribution_consommee_avant_le_libre(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 100);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->service()->modifierLigne($decharge->lignes->first(), 60, null);

        $this->service()->valider($decharge);

        $this->assertSame(40, $this->stock->attribue($this->urnes, $this->cycle));
        $this->assertSame(60, $this->stock->reserve($this->urnes, $this->cycle));
    }

    public function test_plan_change_validation_refusee(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 120]]);

        try {
            $this->service()->valider($decharge);
            $this->fail('Une décharge sur un ancien plan ne se valide pas.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Le plan de dotation a changé : actualisez la décharge.', $e->getMessage());
        }
        $this->assertSame(0, ReservationStock::count());

        $this->service()->actualiser($decharge);
        $this->service()->valider($decharge);
        $this->assertSame('validee', $decharge->fresh()->statut_document);
    }

    public function test_lignes_a_zero_retirees_et_decharge_vide_refusee(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->prefecture, $this->isoloirs, 40]]);
        $this->stockerFictif($this->urnes, 500);
        $this->stockerFictif($this->isoloirs, 500);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $lignes = $decharge->lignes()->orderBy('materiel_id')->get();
        $this->service()->modifierLigne($lignes[1], 0, null);

        $this->service()->valider($decharge);

        $this->assertSame([$this->urnes->id], $decharge->lignes()->pluck('materiel_id')->all());
        $this->assertSame(0, $this->stock->reserve($this->isoloirs, $this->cycle));

        // Toutes les lignes à zéro : refus, et les lignes restent en place (la transaction est annulée).
        $this->approuverPlanFictif([[$this->sousPrefecture1, $this->urnes, 10]]);
        $vide = $this->service()->preparer($this->cycle, $this->sousPrefecture1, 'materiel_lourd');
        $this->service()->modifierLigne($vide->lignes->first(), 0, null);

        try {
            $this->service()->valider($vide);
            $this->fail('Une décharge sans quantité doit être refusée.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Décharge sans quantité à expédier.', $e->getMessage());
        }
        $this->assertSame(1, $vide->lignes()->count());
        $this->assertSame('brouillon', $vide->fresh()->statut_document);
    }

    public function test_numero_sequentiel_par_cycle(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->sousPrefecture1, $this->urnes, 10]]);
        $this->stockerFictif($this->urnes, 500);

        $d1 = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->service()->valider($d1);
        $d2 = $this->service()->preparer($this->cycle, $this->sousPrefecture1, 'materiel_lourd');
        $this->service()->valider($d2);

        $this->assertSame("{$this->cycle->id}-00001", $d1->fresh()->numero);
        $this->assertSame("{$this->cycle->id}-00002", $d2->fresh()->numero);

        $autre = Cycle::create(['libelle' => 'Cycle B', 'type' => 'scrutin']);
        $planB = PlanDotation::create([
            'cycle_id' => $autre->id, 'version' => 1,
            'instantane_id' => PlanDotation::courantApprouve($this->cycle)->instantane_id,
            'parametres' => [], 'calcule_par' => $this->responsable->id, 'anomalies' => [],
        ]);
        $planB->forceFill(['statut' => 'approuve', 'approuve_par' => $this->responsable->id, 'approuve_le' => now()])->save();
        $planB->lignes()->create([
            'destinataire_id' => $this->prefecture->id, 'materiel_id' => $this->urnes->id, 'effectif' => 5, 'exact' => '5',
            'majoration' => '0.00', 'minimum' => 0, 'arrondi' => 'unite', 'dotation_calculee' => 5,
        ])->forceFill(['dotation_approuvee' => 5])->save();
        $d3 = $this->service()->preparer($autre, $this->prefecture, 'materiel_lourd');
        $this->service()->valider($d3);

        $this->assertSame("{$autre->id}-00001", $d3->fresh()->numero);
        $this->assertNotSame($this->cycle->id, $autre->id);
    }

    public function test_donnees_gelees_inchangees_apres_modification(): void
    {
        EntiteBulletin::create(['cycle_id' => $this->cycle->id, 'type' => 'candidat', 'nom' => 'Candidat X', 'ordre' => 1, 'national' => true, 'materiel_id' => $this->enveloppes->id]);
        $this->cycle->forceFill(['date_scrutin' => '2027-02-21'])->save();
        $this->prefecture->forceFill(['signataire_nom' => 'Ndiaye', 'signataire_qualite' => 'Préfet'])->save();
        $this->approuverPlanFictif([[$this->prefecture, $this->enveloppes, 5000]], [$this->enveloppes->id => '1 par électeur']);
        $this->stockerFictif($this->enveloppes, 6000);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'imprime');
        $this->service()->modifierLigne($decharge->lignes->first(), 3000, 'Première tranche');

        $this->service()->valider($decharge);

        $gelees = $decharge->fresh()->donnees_gelees;
        $this->assertSame("{$this->cycle->id}-00001", $gelees['numero']);
        $this->assertSame('DÉCHARGE DE MATÉRIEL ÉLECTORAL — Imprimés — INITIALE', $gelees['titre']);
        $this->assertSame(config('logistique.entete'), $gelees['entete']);
        $this->assertSame('Dakar', $gelees['lieu']);
        $this->assertSame(config('logistique.nb_decharge'), $gelees['nb']);
        $this->assertSame(now()->format('Y-m-d'), $gelees['date']);
        $this->assertSame('Cycle A', $gelees['cycle']['libelle']);
        $this->assertSame('2027-02-21', $gelees['cycle']['date_scrutin']);
        $this->assertSame('Ndiaye', $gelees['destinataire']['signataire_nom']);
        $this->assertSame('via_prefet', $gelees['circuit']);
        $this->assertSame($this->prefecture->libelle, $gelees['remis_a']['libelle']);
        $this->assertSame(1000 + $this->prefecture->id, $gelees['effectifs']['electeurs']);
        $this->assertSame(PlanDotation::courantApprouve($this->cycle)->id, $gelees['plan']['id']);
        $ligne = $gelees['lignes'][0];
        $this->assertSame('Enveloppes', $ligne['libelle']);
        $this->assertSame('Candidat X', $ligne['entite']);
        $this->assertSame(5000, $ligne['dotation_approuvee']);
        $this->assertSame(0, $ligne['deja_engage']);
        $this->assertSame(3000, $ligne['quantite']);
        $this->assertSame('Première tranche', $ligne['observations']);
        $this->assertSame('1 par électeur', $ligne['cle_affichee']);
        $this->assertSame(5000, $ligne['annexe']['effectif']);
        $this->assertSame('unite', $ligne['annexe']['arrondi']);

        $this->prefecture->forceFill(['signataire_nom' => 'Autre nom'])->save();
        $this->approuverPlanFictif([[$this->prefecture, $this->enveloppes, 9000]]);

        $this->assertSame($gelees, $decharge->fresh()->donnees_gelees);
        $this->assertSame('Autre nom', DonneesDecharge::construire($decharge->fresh())['destinataire']['signataire_nom']);
    }

    public function test_circuit_et_remis_a_figes(): void
    {
        $this->cycle->forceFill(['circuit_defaut' => 'via_prefet'])->save();
        $this->approuverPlanFictif([[$this->sousPrefecture1, $this->urnes, 10], [$this->poste, $this->urnes, 5]]);
        $this->stockerFictif($this->urnes, 500);

        $sous = $this->service()->preparer($this->cycle, $this->sousPrefecture1, 'materiel_lourd');
        $this->service()->valider($sous);
        $poste = $this->service()->preparer($this->cycle, $this->poste, 'materiel_lourd');
        $this->service()->valider($poste);

        $sous->refresh();
        $this->assertSame('via_prefet', $sous->circuit);
        $this->assertSame($this->prefecture->id, (int) $sous->remis_a_id);
        $this->assertSame($this->prefecture->libelle, $sous->donnees_gelees['remis_a']['libelle']);
        $this->assertSame($this->sousPrefecture1->libelle, $sous->donnees_gelees['destinataire']['libelle']);
        $poste->refresh();
        $this->assertSame('etranger', $poste->circuit);
        $this->assertSame($this->poste->id, (int) $poste->remis_a_id);
    }

    public function test_cycle_clos_refuse(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->cycle->forceFill(['statut' => 'clos'])->save();

        $this->expectException(RegleLogistique::class);
        $this->service()->valider($decharge);
    }

    public function test_seul_un_brouillon_se_valide_et_le_droit_gerer_est_exige(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');

        $this->actingAs(User::factory()->create(['role' => 'prefet']));
        try {
            $this->service()->valider($decharge);
            $this->fail('Le droit logistique-gerer est exigé.');
        } catch (RegleLogistique) {
            $this->assertSame('brouillon', $decharge->fresh()->statut_document);
        }

        $this->actingAs($this->responsable);
        $this->service()->valider($decharge);
        try {
            $this->service()->valider($decharge);
            $this->fail('Une décharge validée ne se valide pas deux fois.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Seul un brouillon peut être validé.', $e->getMessage());
        }
        $this->assertSame(100, $this->stock->reserve($this->urnes, $this->cycle));
    }

    public function test_effectifs_destinataire_somme_les_lignes_du_perimetre(): void
    {
        $plan = $this->approuverPlanFictif([[$this->sousPrefecture1, $this->urnes, 10]]);
        $localite = ['commune_id' => 5000, 'pays' => null, 'ville' => null];
        PerimetreDestinataire::create(['cycle_id' => $this->cycle->id, 'destinataire_id' => $this->sousPrefecture1->id] + $localite);
        $ligne = fn (array $attributs) => InstantaneLigne::create($attributs + [
            'instantane_id' => $plan->instantane_id, 'departement_id' => 10, 'pays' => null, 'ville' => null,
            'libelle_commune' => 'Commune', 'rapprochee' => true, 'bureaux_vote' => 3, 'lieux_vote' => 2, 'commissions' => 1,
        ]);
        $ligne(['commune_id' => 5000, 'electeurs' => 50]);
        // Hors périmètre de la sous-préfecture, et ligne non rapprochée : exclues.
        $ligne(['commune_id' => 6000, 'electeurs' => 700]);
        $ligne(['commune_id' => 5000, 'electeurs' => 900, 'rapprochee' => false]);

        $effectifs = app(PlanDotationService::class)->effectifsDestinataire($plan, $this->sousPrefecture1);

        $this->assertSame(
            ['electeurs' => 1000 + $this->sousPrefecture1->id + 50, 'bureaux_vote' => 4 + 3, 'lieux_vote' => 2 + 2, 'commissions' => 1 + 1],
            $effectifs,
        );

        $etranger = app(PlanDotationService::class)->effectifsDestinataire($plan, $this->poste);
        $this->assertSame(1000 + $this->poste->id, $etranger['electeurs']);
    }
}

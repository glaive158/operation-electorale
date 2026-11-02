<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\PlanDotation;
use App\Models\Logistique\ReservationStock;
use App\Models\User;
use App\Services\Logistique\DechargeService;
use App\Services\Logistique\EngagementService;
use App\Services\Logistique\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class AnnulationDechargeTest extends TestCase
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

    /** Décharge de $quantite urnes pour la préfecture, validée. */
    private function dechargeValidee(int $quantite): Decharge
    {
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->service()->modifierLigne($decharge->lignes->first(), $quantite, null);
        $this->service()->valider($decharge);

        return $decharge->fresh();
    }

    public function test_annulation_rend_a_l_attribution_du_cycle(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 100);
        $decharge = $this->dechargeValidee(60);
        $this->assertSame(40, $this->stock->attribue($this->urnes, $this->cycle));
        $this->assertSame(60, $this->stock->reserve($this->urnes, $this->cycle));

        $this->service()->annuler($decharge, 'Erreur de saisie');

        $this->assertSame(100, $this->stock->attribue($this->urnes, $this->cycle));
        $this->assertSame(0, $this->stock->reserve($this->urnes, $this->cycle));
        $decharge->refresh();
        $this->assertSame('annulee', $decharge->statut_document);
        $this->assertSame($this->responsable->id, (int) $decharge->annule_par);
        $this->assertNotNull($decharge->annule_le);
        $this->assertSame('Erreur de saisie', $decharge->motif_annulation);
        $reservation = ReservationStock::findOrFail($decharge->lignes->first()->reservation_id);
        $this->assertSame('liberee', $reservation->statut);

        $entree = JournalEntree::where('action', 'annulation_decharge')->firstOrFail();
        $this->assertSame($decharge->id, (int) $entree->objet_id);
        $this->assertSame('Erreur de saisie', $entree->motif);
        $this->assertSame('validee', $entree->avant['statut_document']);
        $this->assertSame('annulee', $entree->apres['statut_document']);
    }

    public function test_annulation_vers_stock_commun_sur_demande(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 100);
        $decharge = $this->dechargeValidee(60);
        $libreAvant = $this->stock->libreNonAttribue($this->urnes);

        $this->service()->annuler($decharge, 'Retour au stock commun', true);

        $this->assertSame(40, $this->stock->attribue($this->urnes, $this->cycle));
        $this->assertSame(0, $this->stock->reserve($this->urnes, $this->cycle));
        $this->assertSame($libreAvant + 60, $this->stock->libreNonAttribue($this->urnes));
    }

    public function test_annulation_reservee_au_responsable(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 100);
        $decharge = $this->dechargeValidee(60);

        $this->actingAs(User::factory()->create(['role' => 'logistique_gestionnaire']));
        try {
            $this->service()->annuler($decharge, 'Tentative');
            $this->fail("L'annulation est réservée au responsable.");
        } catch (RegleLogistique) {
            // attendu
        }

        $this->assertSame('validee', $decharge->fresh()->statut_document);
        $this->assertSame(60, $this->stock->reserve($this->urnes, $this->cycle));
        $this->assertSame(40, $this->stock->attribue($this->urnes, $this->cycle));
        $this->assertSame(0, JournalEntree::where('action', 'annulation_decharge')->count());
    }

    public function test_decharge_partie_non_annulable(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500);
        $decharge = $this->dechargeValidee(60);
        $decharge->forceFill(['statut_acheminement' => 'chargee'])->save();

        try {
            $this->service()->annuler($decharge, 'Trop tard');
            $this->fail('Une décharge partie ne s\'annule pas.');
        } catch (RegleLogistique $e) {
            $this->assertSame(
                'Décharge déjà partie : annulation impossible ; seule une réintégration physique remet du matériel en stock.',
                $e->getMessage(),
            );
        }

        $this->assertSame('validee', $decharge->fresh()->statut_document);
        $this->assertSame(60, $this->stock->reserve($this->urnes, $this->cycle));
    }

    public function test_seule_une_decharge_validee_s_annule(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500);
        $brouillon = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');

        try {
            $this->service()->annuler($brouillon, 'Motif');
            $this->fail('Un brouillon ne s\'annule pas.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Seule une décharge validée peut être annulée.', $e->getMessage());
        }

        $this->service()->valider($brouillon);
        $this->service()->annuler($brouillon, 'Motif');
        try {
            $this->service()->annuler($brouillon, 'Encore');
            $this->fail('Une annulée ne s\'annule pas deux fois.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Seule une décharge validée peut être annulée.', $e->getMessage());
        }
    }

    public function test_annulation_refusee_si_cycle_clos(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500);
        $decharge = $this->dechargeValidee(60);
        $this->cycle->forceFill(['statut' => 'clos'])->save();

        $this->expectException(RegleLogistique::class);
        $this->service()->annuler($decharge, 'Trop tard');
    }

    public function test_motif_obligatoire(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500);
        $decharge = $this->dechargeValidee(60);

        foreach (['', '   ', str_repeat('x', 501)] as $motif) {
            try {
                $this->service()->annuler($decharge, $motif);
                $this->fail('Le motif est obligatoire (500 caractères au plus).');
            } catch (InvalidArgumentException) {
                // attendu
            }
        }

        $this->assertSame('validee', $decharge->fresh()->statut_document);
        $this->assertSame(60, $this->stock->reserve($this->urnes, $this->cycle));
    }

    public function test_annulee_reste_visible_et_reliee(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500);
        $decharge = $this->dechargeValidee(60);
        $this->service()->annuler($decharge, 'Quantité erronée');

        $remplacante = $this->service()->remplacer($decharge);

        $this->assertSame('brouillon', $remplacante->statut_document);
        $this->assertSame($decharge->id, (int) $remplacante->decharge_remplacee_id);
        $this->assertSame($decharge->cycle_id, $remplacante->cycle_id);
        $this->assertSame($decharge->destinataire_id, $remplacante->destinataire_id);
        $this->assertSame('materiel_lourd', $remplacante->categorie);
        $this->assertSame($decharge->nature, $remplacante->nature);
        $this->assertSame(PlanDotation::courantApprouve($this->cycle)->id, (int) $remplacante->plan_dotation_id);
        $this->assertSame($this->responsable->id, (int) $remplacante->prepare_par);
        $this->assertSame($remplacante->id, $decharge->remplacante()->firstOrFail()->id);
        $this->assertSame($decharge->id, $remplacante->remplacee->id);
        $this->assertSame('annulee', $decharge->fresh()->statut_document);
        $this->assertSame(1, $remplacante->lignes()->count());
        $this->assertSame(60, $remplacante->lignes->first()->quantite);
        $this->assertSame(100, $remplacante->lignes->first()->dotation_approuvee);
        $this->assertSame('Clé Urnes', $remplacante->lignes->first()->cle_affichee);

        $entree = JournalEntree::where('action', 'remplacement_decharge')->firstOrFail();
        $this->assertSame($remplacante->id, (int) $entree->objet_id);
        $this->assertSame($decharge->id, $entree->apres['decharge_remplacee_id']);
        $this->assertCount(1, $entree->apres['lignes']);
    }

    public function test_remplacement_plafonne_au_reste(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500);
        $this->dechargeValidee(70);
        $d2 = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->assertSame(30, $d2->lignes->first()->quantite);
        $this->service()->valider($d2);
        $this->service()->annuler($d2, 'À refaire');
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 90]]);

        $remplacante = $this->service()->remplacer($d2->fresh());

        $this->assertSame(20, $remplacante->lignes->first()->quantite);
        $this->assertSame(90, $remplacante->lignes->first()->dotation_approuvee);
        $this->assertSame('reliquat', $remplacante->nature);
    }

    public function test_remplacement_omet_les_lignes_a_zero(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->prefecture, $this->isoloirs, 40]]);
        $this->stockerFictif($this->urnes, 500);
        $this->stockerFictif($this->isoloirs, 500);
        $d1 = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->service()->valider($d1);
        $this->service()->annuler($d1, 'À refaire');

        // Une autre décharge prend les urnes (isoloirs à zéro) : l'ancienne ligne d'urnes n'a plus de reste.
        $autre = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->service()->modifierLigne($autre->lignes()->where('materiel_id', $this->isoloirs->id)->first(), 0, null);
        $this->service()->valider($autre);

        $remplacante = $this->service()->remplacer($d1->fresh());

        $this->assertSame([$this->isoloirs->id], $remplacante->lignes()->pluck('materiel_id')->all());
        $this->assertSame(40, $remplacante->lignes->first()->quantite);
    }

    public function test_remplacement_sans_reste_refuse(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500);
        $d1 = $this->dechargeValidee(100);
        $this->service()->annuler($d1, 'À refaire');
        $autre = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->service()->valider($autre);

        try {
            $this->service()->remplacer($d1->fresh());
            $this->fail('Sans reste, le remplacement est refusé.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Rien à expédier pour cette catégorie.', $e->getMessage());
        }
        $this->assertSame(0, Decharge::whereNotNull('decharge_remplacee_id')->count());
    }

    public function test_remplacement_unique(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500);
        $decharge = $this->dechargeValidee(60);
        $this->service()->annuler($decharge, 'Quantité erronée');
        $premiere = $this->service()->remplacer($decharge->fresh());

        try {
            $this->service()->remplacer($decharge->fresh());
            $this->fail('Une seule remplaçante active par annulée.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Cette décharge a déjà une remplaçante.', $e->getMessage());
        }

        // Si la remplaçante est elle-même annulée, une nouvelle remplaçante est permise.
        $this->service()->valider($premiere);
        $this->service()->annuler($premiere, 'Encore');
        $seconde = $this->service()->remplacer($decharge->fresh());
        $this->assertSame($decharge->id, (int) $seconde->decharge_remplacee_id);
        $this->assertSame(2, Decharge::where('decharge_remplacee_id', $decharge->id)->count());
        $this->assertSame($seconde->id, $decharge->fresh()->remplacante->id);
    }

    public function test_remplacement_regles_d_acces_et_de_statut(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500);
        $decharge = $this->dechargeValidee(60);

        try {
            $this->service()->remplacer($decharge);
            $this->fail('Seule une annulée se remplace.');
        } catch (RegleLogistique) {
            $this->assertSame(0, Decharge::whereNotNull('decharge_remplacee_id')->count());
        }

        $this->service()->annuler($decharge, 'Motif');

        $this->actingAs(User::factory()->create(['role' => 'prefet']));
        try {
            $this->service()->remplacer($decharge->fresh());
            $this->fail('Le droit logistique-gerer est exigé.');
        } catch (RegleLogistique) {
            $this->assertSame(0, Decharge::whereNotNull('decharge_remplacee_id')->count());
        }

        // Le gestionnaire peut remplacer (droit « gérer »).
        $this->actingAs(User::factory()->create(['role' => 'logistique_gestionnaire']));
        $this->assertSame('brouillon', $this->service()->remplacer($decharge->fresh())->statut_document);
    }

    public function test_remplacement_respecte_le_brouillon_unique(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500);
        $decharge = $this->dechargeValidee(60);
        $this->service()->annuler($decharge, 'Motif');
        $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');

        try {
            $this->service()->remplacer($decharge->fresh());
            $this->fail('Un brouillon existe déjà pour ce destinataire et cette catégorie.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Une décharge brouillon existe déjà pour ce destinataire et cette catégorie.', $e->getMessage());
        }
        $this->assertSame(0, Decharge::whereNotNull('decharge_remplacee_id')->count());
    }

    public function test_ecart_au_chargement_80_sur_100(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500);
        $d1 = $this->dechargeValidee(100);
        $this->assertSame(100, $this->stock->reserve($this->urnes, $this->cycle));

        $this->service()->annuler($d1, 'chargement 80');
        $remplacante = $this->service()->remplacer($d1->fresh());
        $this->assertSame(100, $remplacante->lignes->first()->quantite);
        $this->service()->modifierLigne($remplacante->lignes->first(), 80, 'Chargement de 80');
        $this->service()->valider($remplacante);

        $this->assertSame(80, $this->stock->reserve($this->urnes, $this->cycle));
        $etat = app(EngagementService::class)->etat($this->cycle, $this->prefecture, 'materiel_lourd')->get($this->urnes->id);
        $this->assertSame(80, $etat['engage']);
        $this->assertSame(20, $etat['reste']);
        $this->assertSame('annulee', $d1->fresh()->statut_document);
        $this->assertSame('chargement 80', $d1->fresh()->motif_annulation);
        $this->assertSame('validee', $remplacante->fresh()->statut_document);
    }
}

<?php

namespace Tests\Feature\Logistique;

use App\Models\Logistique\Decharge;
use App\Services\Logistique\DechargeService;
use App\Services\Logistique\EcartVersionService;
use App\Services\Logistique\EngagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class EcartVersionTest extends TestCase
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

    private function ecarts()
    {
        return app(EcartVersionService::class)->analyser($this->cycle);
    }

    /** Décharge validée de $quantite urnes pour le destinataire (1re ligne de la catégorie lourde). */
    private function dechargeValidee(int $quantite, $destinataire = null): Decharge
    {
        $decharge = $this->service()->preparer($this->cycle, $destinataire ?? $this->prefecture, 'materiel_lourd');
        $this->service()->modifierLigne($decharge->lignes()->where('materiel_id', $this->urnes->id)->firstOrFail(), $quantite, null);
        $this->service()->valider($decharge);

        return $decharge->fresh();
    }

    private function faireDepartir(Decharge $decharge): void
    {
        $decharge->forceFill(['statut_acheminement' => 'chargee'])->save();
    }

    public function test_reliquat_si_dotation_augmentee(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 200);
        $this->dechargeValidee(100);
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 120]]);

        $ecarts = $this->ecarts();

        $this->assertCount(1, $ecarts);
        $e = $ecarts->first();
        $this->assertSame('reliquat', $e['type']);
        $this->assertSame(20, $e['quantite']);
        $this->assertSame(120, $e['dotation']);
        $this->assertSame(100, $e['engage']);
        $this->assertSame([], $e['decharges']);
        $this->assertSame('materiel_lourd', $e['categorie']);
        $this->assertSame($this->prefecture->id, $e['destinataire']->id);
        $this->assertSame($this->urnes->id, $e['materiel']->id);
    }

    public function test_annulation_si_baisse_non_partie(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 200);
        $decharge = $this->dechargeValidee(100);
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 80]]);

        $ecarts = $this->ecarts();

        $this->assertCount(1, $ecarts);
        $e = $ecarts->first();
        $this->assertSame('annulation', $e['type']);
        $this->assertSame(20, $e['quantite']);
        $this->assertSame(80, $e['dotation']);
        $this->assertSame(100, $e['engage']);
        $this->assertSame([$decharge->id], $e['decharges']);

        // Corriger : annuler puis remplacer redonne un brouillon calé sur le nouveau plan.
        $this->service()->annuler($decharge, 'Plan revu à la baisse');
        $remplacante = $this->service()->remplacer($decharge->fresh());
        $this->assertSame('brouillon', $remplacante->statut_document);
        $this->assertSame(80, $remplacante->lignes->first()->quantite);
        $this->assertCount(0, $this->ecarts());
    }

    public function test_signalement_si_baisse_deja_partie(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 200);
        $decharge = $this->dechargeValidee(100);
        $this->faireDepartir($decharge);
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 80]]);

        $ecarts = $this->ecarts();

        $this->assertCount(1, $ecarts);
        $e = $ecarts->first();
        $this->assertSame('signalement', $e['type']);
        $this->assertSame(20, $e['quantite']);
        $this->assertSame([], $e['decharges']);
        $etat = app(EngagementService::class)->etat($this->cycle, $this->prefecture, 'materiel_lourd')->get($this->urnes->id);
        $this->assertSame(0, $etat['reste']);
    }

    public function test_baisse_mixte(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 200);
        $partie = $this->dechargeValidee(60);
        $this->faireDepartir($partie);
        $reliquat = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->assertSame(40, $reliquat->lignes->first()->quantite);
        $this->service()->valider($reliquat);
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 50]]);

        $ecarts = $this->ecarts()->values();

        $this->assertCount(2, $ecarts);
        $this->assertSame('annulation', $ecarts[0]['type']);
        $this->assertSame(40, $ecarts[0]['quantite']);
        $this->assertSame([$reliquat->id], $ecarts[0]['decharges']);
        $this->assertSame('signalement', $ecarts[1]['type']);
        $this->assertSame(10, $ecarts[1]['quantite']);
        $this->assertSame([], $ecarts[1]['decharges']);
        $this->assertSame(100, $ecarts[0]['engage']);
        $this->assertSame(50, $ecarts[0]['dotation']);
    }

    public function test_baisse_partielle_plafonnee_au_non_parti(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 200);
        $this->dechargeValidee(100);
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 70]]);

        $e = $this->ecarts()->sole();

        $this->assertSame('annulation', $e['type']);
        $this->assertSame(30, $e['quantite']);
    }

    public function test_article_retire_du_plan(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->prefecture, $this->isoloirs, 40]]);
        $this->stockerFictif($this->urnes, 500, 200);
        $this->stockerFictif($this->isoloirs, 500, 200);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->service()->valider($decharge);
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);

        $e = $this->ecarts()->sole();

        $this->assertSame($this->isoloirs->id, $e['materiel']->id);
        $this->assertSame(0, $e['dotation']);
        $this->assertSame(40, $e['engage']);
        $this->assertSame('annulation', $e['type']);
        $this->assertSame(40, $e['quantite']);
        $this->assertSame([$decharge->id], $e['decharges']);
    }

    public function test_sans_ecart_rien(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 200);
        $this->dechargeValidee(100);

        $this->assertCount(0, $this->ecarts());
        // Article absent du nouveau plan (R6) : la dotation compte pour zéro.
        $this->approuverPlanFictif([[$this->sousPrefecture1, $this->urnes, 30]]);
        $this->assertCount(1, $this->ecarts());
        $this->assertSame('annulation', $this->ecarts()->first()['type']);
    }

    public function test_aucun_plan_approuve(): void
    {
        $this->assertCount(0, $this->ecarts());
    }

    public function test_decharge_annulee_ignoree(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500, 200);
        $annulee = $this->dechargeValidee(100);
        $this->service()->annuler($annulee, 'Erreur');
        $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 80]]);

        $this->assertCount(0, $this->ecarts());
    }

    public function test_tri_et_requetes_bornees(): void
    {
        $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 100], [$this->prefecture, $this->isoloirs, 100],
            [$this->sousPrefecture1, $this->urnes, 100], [$this->sousPrefecture2, $this->urnes, 100],
            [$this->poste, $this->urnes, 100],
        ]);
        $this->stockerFictif($this->urnes, 1000, 1000);
        $this->stockerFictif($this->isoloirs, 1000, 1000);
        foreach ([$this->prefecture, $this->sousPrefecture1, $this->sousPrefecture2, $this->poste] as $d) {
            $decharge = $this->service()->preparer($this->cycle, $d, 'materiel_lourd');
            $this->service()->valider($decharge);
        }
        $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 90], [$this->prefecture, $this->isoloirs, 120],
            [$this->sousPrefecture1, $this->urnes, 110], [$this->sousPrefecture2, $this->urnes, 50],
            [$this->poste, $this->urnes, 100],
        ]);

        DB::enableQueryLog();
        $ecarts = $this->ecarts()->values();
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(4, $requetes);
        $this->assertSame(
            [
                ['Préfecture de Thiès', 'Urnes', 'annulation'],
                ['Préfecture de Thiès', 'Isoloirs', 'reliquat'],
                ['Sous-préfecture de Sindia', 'Urnes', 'reliquat'],
                ['Sous-préfecture de Thiénaba', 'Urnes', 'annulation'],
            ],
            $ecarts->map(fn ($e) => [$e['destinataire']->libelle, $e['materiel']->libelle, $e['type']])->all(),
        );
    }

    public function test_tri_des_destinataires_accentues(): void
    {
        $this->sousPrefecture2->forceFill(['libelle' => 'Éthiopie'])->save();
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->sousPrefecture2, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 1000, 1000);
        foreach ([$this->prefecture, $this->sousPrefecture2] as $d) {
            $this->service()->valider($this->service()->preparer($this->cycle, $d, 'materiel_lourd'));
        }
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 120], [$this->sousPrefecture2, $this->urnes, 120]]);

        $this->assertSame(
            ['Éthiopie', 'Préfecture de Thiès'],
            $this->ecarts()->map(fn ($e) => $e['destinataire']->libelle)->all(),
        );
    }
}

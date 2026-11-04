<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Arbitrage;
use App\Models\Logistique\BesoinRemplacement;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\DechargeLigne;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\PlanDotation;
use App\Services\Logistique\BesoinService;
use App\Services\Logistique\DechargeService;
use App\Services\Logistique\EcartVersionService;
use App\Services\Logistique\EngagementService;
use App\Services\Logistique\IndicateurService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class BesoinCumuleTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerDecorFictif();
    }

    private function engagements(): EngagementService
    {
        return app(EngagementService::class);
    }

    private function remplacer(Destinataire $destinataire, Materiel $materiel, int $quantite): BesoinRemplacement
    {
        return BesoinRemplacement::create([
            'cycle_id' => $this->cycle->id, 'destinataire_id' => $destinataire->id, 'materiel_id' => $materiel->id,
            'quantite' => $quantite, 'motif' => 'Urnes détériorées', 'autorise_par' => $this->responsable->id,
        ]);
    }

    private function clore(Destinataire $destinataire, Materiel $materiel, int $expeditionAnnulee): Arbitrage
    {
        return Arbitrage::create([
            'cycle_id' => $this->cycle->id, 'objet' => 'cloture_ecart', 'destinataire_id' => $destinataire->id,
            'materiel_id' => $materiel->id, 'decision' => 'cloture_avec_ecart',
            'quantite_expedition_annulee' => $expeditionAnnulee, 'motif' => 'Écart accepté', 'user_id' => $this->responsable->id,
        ]);
    }

    private function dechargeValidee(Destinataire $destinataire, Materiel $materiel, int $quantite): Decharge
    {
        $service = app(DechargeService::class);
        $decharge = $service->preparer($this->cycle, $destinataire, $materiel->categorie);
        $service->modifierLigne($decharge->lignes()->where('materiel_id', $materiel->id)->firstOrFail(), $quantite, null);
        $service->valider($decharge);

        return $decharge->fresh();
    }

    /** Brouillon de remplacement écrit directement (la préparation par besoin vient avec les réclamations). */
    private function brouillonRemplacement(Destinataire $destinataire, Materiel $materiel, int $quantite): Decharge
    {
        $decharge = Decharge::create([
            'cycle_id' => $this->cycle->id, 'destinataire_id' => $destinataire->id, 'categorie' => $materiel->categorie,
            'nature' => 'remplacement', 'plan_dotation_id' => PlanDotation::courantApprouve($this->cycle)->id,
            'prepare_par' => $this->responsable->id,
        ]);
        DechargeLigne::create([
            'decharge_id' => $decharge->id, 'materiel_id' => $materiel->id, 'dotation_approuvee' => 100, 'quantite' => $quantite,
        ]);

        return $decharge;
    }

    public function test_besoin_egal_dotation_sans_remplacement(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);

        $this->assertSame(100, $this->engagements()->besoin($this->cycle, $this->prefecture, $this->urnes));
        $this->assertSame(0, $this->engagements()->besoin($this->cycle, $this->prefecture, $this->isoloirs));

        $entree = $this->engagements()->etat($this->cycle, $this->prefecture)->get($this->urnes->id);
        $this->assertSame(['dotation' => 100, 'besoin' => 100, 'engage' => 0, 'reste' => 100], collect($entree)->only(['dotation', 'besoin', 'engage', 'reste'])->all());
    }

    public function test_remplacement_augmente_le_besoin_et_le_reste(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 1000);
        $this->dechargeValidee($this->prefecture, $this->urnes, 100);
        $this->remplacer($this->prefecture, $this->urnes, 10);

        $this->assertSame(110, $this->engagements()->besoin($this->cycle, $this->prefecture, $this->urnes));

        $entree = $this->engagements()->etat($this->cycle, $this->prefecture)->get($this->urnes->id);
        $this->assertSame(['dotation' => 100, 'besoin' => 110, 'engage' => 100, 'reste' => 10], collect($entree)->only(['dotation', 'besoin', 'engage', 'reste'])->all());

        // Un remplacement d'un autre destinataire ou d'un autre cycle ne compte pas.
        $this->remplacer($this->sousPrefecture1, $this->urnes, 7);
        $this->assertSame(110, $this->engagements()->besoin($this->cycle, $this->prefecture, $this->urnes));

        $synthese = $this->engagements()->synthese($this->cycle)->get($this->prefecture->id.'|materiel_lourd');
        $this->assertSame(['dotation' => 100, 'besoin' => 110, 'engage' => 100, 'reste' => 10], collect($synthese)->only(['dotation', 'besoin', 'engage', 'reste'])->all());
    }

    public function test_cloture_reduit_le_besoin(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->clore($this->prefecture, $this->urnes, 20);
        // Une déclaration arbitrée ne réduit pas l'expédition.
        Arbitrage::create([
            'cycle_id' => $this->cycle->id, 'objet' => 'declaration', 'destinataire_id' => $this->prefecture->id,
            'materiel_id' => $this->urnes->id, 'decision' => 'retenir', 'quantite_expedition_annulee' => 5,
            'motif' => 'Arbitrage', 'user_id' => $this->responsable->id,
        ]);

        $this->assertSame(80, $this->engagements()->besoin($this->cycle, $this->prefecture, $this->urnes));
        $entree = $this->engagements()->etat($this->cycle, $this->prefecture)->get($this->urnes->id);
        $this->assertSame(80, $entree['besoin']);
        $this->assertSame(80, $entree['reste']);
        $this->assertSame(100, $entree['dotation']);

        // Jamais négatif.
        $this->clore($this->prefecture, $this->urnes, 500);
        $this->assertSame(0, $this->engagements()->besoin($this->cycle, $this->prefecture, $this->urnes));
    }

    public function test_plafond_utilise_le_besoin(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 1000);
        $this->dechargeValidee($this->prefecture, $this->urnes, 100);
        $this->remplacer($this->prefecture, $this->urnes, 10);

        $service = app(DechargeService::class);
        // Le brouillon initial ne reprend pas le remplacement : une décharge de remplacement le porte.
        $this->assertSame([], app(EngagementService::class)->etatInitial($this->cycle, $this->prefecture, 'materiel_lourd')->filter(fn ($e) => $e['reste'] > 0)->keys()->all());
        $decharge = $this->brouillonRemplacement($this->prefecture, $this->urnes, 10);
        $ligne = $decharge->lignes()->where('materiel_id', $this->urnes->id)->firstOrFail();

        // Contournement du plafond de saisie : seule la validation arbitre.
        $ligne->forceFill(['quantite' => 11])->save();
        try {
            $service->valider($decharge);
            $this->fail('La validation de 11 aurait dû être refusée.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Plafond du besoin dépassé pour Urnes : besoin 110, déjà engagé 100, demandé 11.', $e->getMessage());
        }

        $ligne->forceFill(['quantite' => 10])->save();
        $service->valider($decharge->fresh());
        $this->assertSame('validee', $decharge->fresh()->statut_document);
    }

    public function test_article_hors_plan_avec_remplacement_visible(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->remplacer($this->prefecture, $this->isoloirs, 4);

        $etat = $this->engagements()->etat($this->cycle, $this->prefecture);
        $this->assertEqualsCanonicalizing([$this->urnes->id, $this->isoloirs->id], $etat->keys()->all());
        $this->assertSame(['dotation' => 0, 'besoin' => 4, 'engage' => 0, 'reste' => 4], collect($etat[$this->isoloirs->id])->only(['dotation', 'besoin', 'engage', 'reste'])->all());
        $this->assertSame($this->isoloirs->id, $etat[$this->isoloirs->id]['materiel']->id);
        $this->assertArrayNotHasKey('dotation_plan', $etat[$this->isoloirs->id]['materiel']->getAttributes());

        // Filtre par catégorie : l'article hors plan suit sa propre catégorie.
        $this->assertSame([], $this->engagements()->etat($this->cycle, $this->prefecture, 'imprime')->keys()->all());
        $this->assertEqualsCanonicalizing([$this->urnes->id, $this->isoloirs->id], $this->engagements()->etat($this->cycle, $this->prefecture, 'materiel_lourd')->keys()->all());

        $synthese = $this->engagements()->synthese($this->cycle)->get($this->prefecture->id.'|materiel_lourd');
        $this->assertSame(['dotation' => 100, 'besoin' => 104, 'engage' => 0, 'reste' => 104], collect($synthese)->only(['dotation', 'besoin', 'engage', 'reste'])->all());
    }

    public function test_ecarts_comparent_au_besoin(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 1000);
        $this->dechargeValidee($this->prefecture, $this->urnes, 100);
        $this->remplacer($this->prefecture, $this->urnes, 10);
        $remplacement = $this->brouillonRemplacement($this->prefecture, $this->urnes, 10);
        $service = app(DechargeService::class);
        $service->valider($remplacement);

        $this->assertCount(0, app(EcartVersionService::class)->analyser($this->cycle));

        // Le plan baisse à 80 : besoin 90, engagé 110, tout est encore à préparer, d'où une annulation de 20.
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 80]]);
        $ecarts = app(EcartVersionService::class)->analyser($this->cycle);
        $this->assertCount(1, $ecarts);
        $this->assertSame('annulation', $ecarts[0]['type']);
        $this->assertSame(20, $ecarts[0]['quantite']);
        $this->assertSame(80, $ecarts[0]['dotation']);
        $this->assertSame(90, $ecarts[0]['besoin']);
        $this->assertSame(110, $ecarts[0]['engage']);
    }

    public function test_besoin_expedition_par_article_inclut_remplacements(): void
    {
        $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 100], [$this->sousPrefecture1, $this->urnes, 30], [$this->prefecture, $this->isoloirs, 50],
        ]);
        $this->remplacer($this->prefecture, $this->urnes, 10);
        $this->remplacer($this->sousPrefecture2, $this->urnes, 5);
        $this->clore($this->sousPrefecture1, $this->urnes, 30);
        $this->clore($this->prefecture, $this->isoloirs, 20);

        $besoin = app(BesoinService::class);
        $this->assertSame(100 + 10 + 30 + 5 - 30, $besoin->besoinExpedition($this->cycle, $this->urnes));
        $this->assertSame(30, $besoin->besoinExpedition($this->cycle, $this->isoloirs));
        $this->assertSame(0, $besoin->besoinExpedition($this->cycle, $this->enveloppes));
    }

    public function test_toutes_les_lectures_du_besoin_concordent(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->sousPrefecture1, $this->urnes, 30]]);
        $this->remplacer($this->prefecture, $this->urnes, 10);
        $this->clore($this->prefecture, $this->urnes, 20);
        $this->stockerFictif($this->urnes, 1000, 300);
        $this->dechargeValidee($this->prefecture, $this->urnes, 60);

        // Dotation 100 + remplacement 10 - expédition annulée 20.
        $this->assertSame(90, $this->engagements()->besoin($this->cycle, $this->prefecture, $this->urnes));
        $this->assertSame(90, $this->engagements()->etat($this->cycle, $this->prefecture)[$this->urnes->id]['besoin']);
        $this->assertSame(90, $this->engagements()->synthese($this->cycle)[$this->prefecture->id.'|materiel_lourd']['besoin']);
        $ecart = app(EcartVersionService::class)->analyser($this->cycle)
            ->first(fn (array $e) => $e['destinataire']->is($this->prefecture) && $e['materiel']->is($this->urnes));
        $this->assertSame(90, $ecart['besoin']);
        // La préfecture (90) et la sous-préfecture (30) : le besoin d'expédition de l'article les additionne.
        $this->assertSame(120, app(BesoinService::class)->besoinExpedition($this->cycle, $this->urnes));
        $indicateurs = app(IndicateurService::class)->parDestinataire($this->cycle)
            ->first(fn (array $l) => $l['destinataire']->is($this->prefecture) && $l['materiel']->is($this->urnes));
        $this->assertSame(90, $indicateurs['besoin_expedition']);
        $this->assertSame(100, $indicateurs['dotation']);
        $this->assertSame(120, app(IndicateurService::class)->parArticle($this->cycle)->first(fn (array $l) => $l['materiel']->is($this->urnes))['besoin_expedition']);
    }

    public function test_nombre_de_requetes_borne(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->sousPrefecture1, $this->urnes, 30]]);
        $this->remplacer($this->prefecture, $this->isoloirs, 4);
        $this->clore($this->prefecture, $this->urnes, 10);

        $compter = function (callable $appel): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $appel();
            $requetes = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $requetes;
        };

        $this->assertLessThanOrEqual(2, $compter(fn () => $this->engagements()->etat($this->cycle, $this->prefecture)));
        $this->assertLessThanOrEqual(4, $compter(fn () => app(EcartVersionService::class)->analyser($this->cycle)));
        $synthese = $compter(fn () => $this->engagements()->synthese($this->cycle));

        foreach ([$this->sousPrefecture2, $this->poste] as $autre) {
            $this->remplacer($autre, $this->urnes, 3);
        }
        $this->assertSame($synthese, $compter(fn () => $this->engagements()->synthese($this->cycle)));
    }
}

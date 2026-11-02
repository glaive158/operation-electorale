<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Remise;
use App\Models\Logistique\Restitution;
use App\Models\User;
use App\Services\Logistique\DeclarationService;
use App\Services\Logistique\RemiseService;
use App\Services\Logistique\RestitutionService;
use App\Services\Logistique\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class OuvertureRestitutionTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    private $decharge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerDecorFictif();
        $this->approuverPlanFictif([[$this->sousPrefecture1, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 1000, 300);
        $this->urnes->forceFill(['reutilisable' => true])->save();

        // Le préfet reçoit 100 urnes puis en redistribue 80, reçues par le sous-préfet : 20 / 80.
        $this->decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($this->decharge, [$this->urnes->id => 100]);
        $this->redistribuerEtDeclarer(80);
    }

    private function redistribuerEtDeclarer(int $quantite): Remise
    {
        $this->actingAs($this->autoriteFictive($this->prefecture));
        $remise = app(RemiseService::class)->preparerRedistribution($this->decharge->refresh(), [$this->urnes->id => $quantite]);
        $this->actingAs($this->autoriteFictive($this->sousPrefecture1));
        app(DeclarationService::class)->declarer(
            $remise, 'compte', ['nom' => 'Ibrahima Ba', 'qualite' => 'Sous-préfet'],
            [$this->urnes->id => ['recu' => $quantite, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(),
        );
        $this->actingAs($this->responsable);

        return $remise;
    }

    private function service(): RestitutionService
    {
        return app(RestitutionService::class);
    }

    /** @return array<int, int> attendu par article */
    private function attendus(Restitution $restitution): array
    {
        return $restitution->lignes()->orderBy('materiel_id')->pluck('attendu', 'materiel_id')->map(fn ($v) => (int) $v)->all();
    }

    private function restitutionDe(string $type, int $id): Restitution
    {
        return Restitution::where('cycle_id', $this->cycle->id)->where('detenteur_type', $type)->where('detenteur_id', $id)->firstOrFail();
    }

    public function test_ouverture_une_fois_par_detenteur(): void
    {
        $this->assertSame(['creees' => 2, 'inchangees' => 0], $this->service()->ouvrir($this->cycle));

        $prefecture = $this->restitutionDe('destinataire', $this->prefecture->id);
        $sousPrefecture = $this->restitutionDe('destinataire', $this->sousPrefecture1->id);
        $this->assertSame('a_preparer', $prefecture->statut);
        $this->assertSame($this->responsable->id, $prefecture->ouverte_par);
        $this->assertNull($prefecture->agent_id);
        $this->assertSame([$this->urnes->id => 20], $this->attendus($prefecture));
        $this->assertSame([$this->urnes->id => 80], $this->attendus($sousPrefecture));
        $ligne = $prefecture->lignes()->firstOrFail();
        $this->assertSame([0, 0, 0, 0, 0], [$ligne->declare_restitue, $ligne->recu_central, $ligne->bon, $ligne->a_reparer, $ligne->reforme]);
        $this->assertSame(2, JournalEntree::where('action', 'ouverture_restitution')->count());

        $this->assertSame(['creees' => 0, 'inchangees' => 2], $this->service()->ouvrir($this->cycle));
        $this->assertSame(2, Restitution::count());
    }

    public function test_nouvelle_restitution_pour_un_detenteur_dont_la_precedente_est_controlee(): void
    {
        $this->service()->ouvrir($this->cycle);
        $prefecture = $this->restitutionDe('destinataire', $this->prefecture->id);
        $prefecture->forceFill(['statut' => 'controlee'])->save();
        $prefecture->lignes()->update(['declare_restitue' => 20]);

        // Plus rien d'attendu du préfet : aucune nouvelle restitution.
        $this->assertSame(['creees' => 0, 'inchangees' => 1], $this->service()->ouvrir($this->cycle));

        // Une réception ultérieure rend du matériel attendu : une restitution est rouverte.
        $this->approuverPlanFictif([[$this->sousPrefecture1, $this->urnes, 200]]);
        $this->decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($this->decharge, [$this->urnes->id => 100]);
        $this->assertSame(['creees' => 1, 'inchangees' => 1], $this->service()->ouvrir($this->cycle));
        $this->assertSame(2, Restitution::where('detenteur_id', $this->prefecture->id)->count());
    }

    public function test_restitution_ouverte_sur_cycle_clos(): void
    {
        app(StockService::class)->cloturerCycle($this->cycle, 'Fin du scrutin');

        $this->assertSame(['creees' => 2, 'inchangees' => 0], $this->service()->ouvrir($this->cycle->refresh()));
        $this->assertSame('clos', $this->cycle->statut);
    }

    public function test_ouverture_reservee_au_responsable(): void
    {
        foreach (['logistique_gestionnaire', 'agent_transport', 'prefet'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            try {
                $this->service()->ouvrir($this->cycle);
                $this->fail("Le rôle {$role} n'aurait pas dû pouvoir ouvrir la restitution.");
            } catch (RegleLogistique $e) {
                $this->assertSame('Seul un responsable logistique peut ouvrir la restitution.', $e->getMessage());
            }
        }
        $this->assertSame(0, Restitution::count());
    }

    public function test_ouverture_sans_auteur_refusee(): void
    {
        $this->app['auth']->guard()->logout();

        $this->expectException(LogicException::class);
        $this->service()->ouvrir($this->cycle);
    }

    public function test_recalculer_remet_les_attendus_au_solde_courant(): void
    {
        $this->service()->ouvrir($this->cycle);
        $prefecture = $this->restitutionDe('destinataire', $this->prefecture->id);
        $this->assertSame([$this->urnes->id => 20], $this->attendus($prefecture));

        // Le préfet redistribue encore 5 urnes au sous-préfet.
        $this->redistribuerEtDeclarer(5);
        $this->service()->recalculer($prefecture);

        $this->assertSame([$this->urnes->id => 15], $this->attendus($prefecture->refresh()));
        $this->assertSame(1, JournalEntree::where('action', 'recalcul_restitution')->where('objet_id', $prefecture->id)->count());
    }

    public function test_recalculer_ajoute_les_articles_nouveaux_et_retire_les_soldes_nuls(): void
    {
        $this->service()->ouvrir($this->cycle);
        $prefecture = $this->restitutionDe('destinataire', $this->prefecture->id);

        // Le préfet redistribue tout le reliquat : sa ligne disparaît (rien n'a été déclaré dessus).
        $this->redistribuerEtDeclarer(20);
        $this->service()->recalculer($prefecture);
        $this->assertSame([], $this->attendus($prefecture->refresh()));

        // Un autre article réutilisable reçu par le préfet apparaît au recalcul.
        $this->isoloirs->forceFill(['reutilisable' => true])->save();
        $this->approuverPlanFictif([[$this->sousPrefecture1, $this->urnes, 100], [$this->sousPrefecture1, $this->isoloirs, 50]]);
        $this->stockerFictif($this->isoloirs, 1000, 100);
        $chargee = $this->deposerChargee($this->sousPrefecture1, [$this->isoloirs->id => 50]);
        $this->deposerRecue($chargee, [$this->isoloirs->id => 50]);
        $this->service()->recalculer($prefecture);
        $this->assertSame([$this->isoloirs->id => 50], $this->attendus($prefecture->refresh()));
    }

    public function test_recalculer_garde_une_ligne_deja_declaree(): void
    {
        $this->service()->ouvrir($this->cycle);
        $prefecture = $this->restitutionDe('destinataire', $this->prefecture->id);
        $prefecture->lignes()->update(['declare_restitue' => 20]);
        $this->redistribuerEtDeclarer(20);

        $this->service()->recalculer($prefecture);

        $this->assertSame([$this->urnes->id => 0], $this->attendus($prefecture->refresh()));
    }

    public function test_recalculer_seulement_a_preparer(): void
    {
        $this->service()->ouvrir($this->cycle);
        $prefecture = $this->restitutionDe('destinataire', $this->prefecture->id);

        foreach (['en_retour', 'recue_central', 'controlee'] as $statut) {
            $prefecture->forceFill(['statut' => $statut])->save();
            try {
                $this->service()->recalculer($prefecture);
                $this->fail("Le recalcul d'une restitution {$statut} aurait dû être refusé.");
            } catch (RegleLogistique $e) {
                $this->assertSame('Seule une restitution à préparer peut être recalculée.', $e->getMessage());
            }
        }
    }

    public function test_recalculer_reserve_au_responsable(): void
    {
        $this->service()->ouvrir($this->cycle);
        $prefecture = $this->restitutionDe('destinataire', $this->prefecture->id);
        $this->actingAs(User::factory()->create(['role' => 'logistique_gestionnaire']));

        $this->expectException(RegleLogistique::class);
        $this->service()->recalculer($prefecture);
    }
}

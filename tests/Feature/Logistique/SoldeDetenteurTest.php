<?php

namespace Tests\Feature\Logistique;

use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Remise;
use App\Models\Logistique\Restitution;
use App\Models\Logistique\RestitutionLigne;
use App\Models\User;
use App\Services\Logistique\DeclarationService;
use App\Services\Logistique\RemiseService;
use App\Services\Logistique\SoldeDetenteurService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class SoldeDetenteurTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerDecorFictif();
        $this->approuverPlanFictif([
            [$this->sousPrefecture1, $this->urnes, 100],
            [$this->sousPrefecture1, $this->isoloirs, 50],
        ]);
        $this->stockerFictif($this->urnes, 1000, 300);
        $this->stockerFictif($this->isoloirs, 1000, 100);
        $this->urnes->forceFill(['reutilisable' => true])->save();
    }

    private function en(User $auteur, callable $action): mixed
    {
        $this->actingAs($auteur);
        try {
            return $action();
        } finally {
            $this->actingAs($this->responsable);
        }
    }

    private function redistribuer(Decharge $decharge, array $lignes): Remise
    {
        return $this->en($this->autoriteFictive($this->prefecture),
            fn () => app(RemiseService::class)->preparerRedistribution($decharge, $lignes));
    }

    private function declarerRedistribution(Remise $remise, int $recu, int $defectueux = 0): void
    {
        $this->en($this->autoriteFictive($this->sousPrefecture1), fn () => app(DeclarationService::class)->declarer(
            $remise, 'compte', ['nom' => 'Ibrahima Ba', 'qualite' => 'Sous-préfet'],
            [$this->urnes->id => ['recu' => $recu, 'defectueux' => $defectueux, 'observation' => null]], (string) Str::uuid(),
        ));
    }

    /** Solde d'un détenteur pour un article (0 si absent du résultat). */
    private function solde(string $type, int $id, ?int $materielId = null, ?Cycle $cycle = null): int
    {
        $ligne = app(SoldeDetenteurService::class)->soldes($cycle ?? $this->cycle)
            ->first(fn (array $s) => $s['detenteur_type'] === $type && $s['detenteur_id'] === $id && $s['materiel_id'] === ($materielId ?? $this->urnes->id));

        return $ligne['solde'] ?? 0;
    }

    public function test_attendu_prefet_et_sous_prefet(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => 100]);
        $remise = $this->redistribuer($decharge->refresh(), [$this->urnes->id => 80]);
        $this->declarerRedistribution($remise, 80);

        $soldes = app(SoldeDetenteurService::class)->soldes($this->cycle);

        $this->assertSame([
            ['detenteur_type' => 'destinataire', 'detenteur_id' => $this->prefecture->id, 'materiel_id' => $this->urnes->id, 'solde' => 20],
            ['detenteur_type' => 'destinataire', 'detenteur_id' => $this->sousPrefecture1->id, 'materiel_id' => $this->urnes->id, 'solde' => 80],
        ], $soldes->sortBy('detenteur_id')->values()->all());
    }

    public function test_defectueux_compris_dans_l_attendu(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => ['recu' => 100, 'defectueux' => 10]]);

        $this->assertSame(100, $this->solde('destinataire', $this->prefecture->id));
    }

    public function test_articles_non_reutilisables_ignores(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->isoloirs->id => 50]);
        $this->deposerRecue($decharge, [$this->isoloirs->id => 50]);

        $this->assertSame(0, $this->solde('destinataire', $this->prefecture->id, $this->isoloirs->id));
        $this->assertSame([], app(SoldeDetenteurService::class)->soldes($this->cycle)->all());
    }

    public function test_agent_attendu_du_manquant(): void
    {
        $agent = User::factory()->create(['role' => 'agent_transport']);
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100], $agent);
        $this->deposerRecue($decharge, [$this->urnes->id => 90]);

        $this->assertSame(10, $this->solde('agent', $agent->id));
        $this->assertSame(90, $this->solde('destinataire', $this->prefecture->id));
    }

    public function test_agent_tient_tout_tant_que_la_livraison_n_est_pas_retenue(): void
    {
        $agent = User::factory()->create(['role' => 'agent_transport']);
        $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100], $agent);

        $this->assertSame(100, $this->solde('agent', $agent->id));
        $this->assertSame(0, $this->solde('destinataire', $this->prefecture->id));
    }

    public function test_redistribution_a_arbitrer_ne_decompte_pas_le_prefet(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => 100]);
        $remise = $this->redistribuer($decharge->refresh(), [$this->urnes->id => 80]);
        $remise->forceFill(['statut' => 'a_arbitrer'])->save();

        $this->assertSame(100, $this->solde('destinataire', $this->prefecture->id));
    }

    public function test_remise_annulee_ne_compte_pas(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => 100]);
        $remise = $this->redistribuer($decharge->refresh(), [$this->urnes->id => 80]);
        $remise->forceFill(['statut' => 'annulee'])->save();

        $this->assertSame(100, $this->solde('destinataire', $this->prefecture->id));
    }

    public function test_retours_deja_declares_sont_deduits_et_jamais_negatifs(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => 100]);
        $restitution = (new Restitution(['cycle_id' => $this->cycle->id, 'detenteur_type' => 'destinataire', 'detenteur_id' => $this->prefecture->id]))
            ->forceFill(['ouverte_par' => $this->responsable->id]);
        $restitution->save();
        $ligne = RestitutionLigne::create(['restitution_id' => $restitution->id, 'materiel_id' => $this->urnes->id, 'attendu' => 100]);

        $ligne->forceFill(['declare_restitue' => 30])->save();
        $this->assertSame(70, $this->solde('destinataire', $this->prefecture->id));

        $ligne->forceFill(['declare_restitue' => 100])->save();
        $this->assertSame(0, $this->solde('destinataire', $this->prefecture->id));
        $this->assertSame([], app(SoldeDetenteurService::class)->soldes($this->cycle)->all());

        $ligne->forceFill(['declare_restitue' => 130])->save();
        $this->assertSame(0, $this->solde('destinataire', $this->prefecture->id));
    }

    public function test_cycles_independants(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => 100]);
        $autre = Cycle::create(['libelle' => 'Cycle B', 'type' => 'scrutin']);

        $this->assertSame(0, $this->solde('destinataire', $this->prefecture->id, null, $autre));
        $this->assertSame(100, $this->solde('destinataire', $this->prefecture->id));
    }

    public function test_requetes_bornees(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => 100]);
        $this->redistribuer($decharge->refresh(), [$this->urnes->id => 10]);
        $this->redistribuer($decharge, [$this->urnes->id => 10]);

        DB::enableQueryLog();
        app(SoldeDetenteurService::class)->soldes($this->cycle);
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(5, $requetes);
    }
}

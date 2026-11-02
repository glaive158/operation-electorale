<?php

namespace Tests\Feature\Logistique;

use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Remise;
use App\Models\Logistique\Restitution;
use App\Models\Logistique\RestitutionLigne;
use App\Models\User;
use App\Services\Logistique\DechargeService;
use App\Services\Logistique\DeclarationService;
use App\Services\Logistique\RemiseService;
use App\Services\Logistique\RestitutionService;
use App\Services\Logistique\SoldeDetenteurService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
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

    public function test_attendu_des_restitutions_controlees_deduit_et_jamais_negatif(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => 100]);
        $restitution = (new Restitution(['cycle_id' => $this->cycle->id, 'detenteur_type' => 'destinataire', 'detenteur_id' => $this->prefecture->id]))
            ->forceFill(['ouverte_par' => $this->responsable->id, 'statut' => 'en_retour']);
        $restitution->save();
        $ligne = RestitutionLigne::create(['restitution_id' => $restitution->id, 'materiel_id' => $this->urnes->id, 'attendu' => 60]);
        $ligne->forceFill(['declare_restitue' => 30])->save();

        // Tant qu'elle n'est pas contrôlée, la restitution ne solde rien (elle reste ouverte pour son détenteur).
        $this->assertSame(100, $this->solde('destinataire', $this->prefecture->id));

        // Contrôlée, elle solde son attendu, quel que soit le retour déclaré : l'écart est à justifier, pas à rouvrir.
        $restitution->forceFill(['statut' => 'controlee'])->save();
        $this->assertSame(40, $this->solde('destinataire', $this->prefecture->id));

        $ligne->forceFill(['attendu' => 100])->save();
        $this->assertSame(0, $this->solde('destinataire', $this->prefecture->id));
        $this->assertSame([], app(SoldeDetenteurService::class)->soldes($this->cycle)->all());

        $ligne->forceFill(['attendu' => 130])->save();
        $this->assertSame(0, $this->solde('destinataire', $this->prefecture->id));
    }

    public function test_restitution_controlee_avec_non_restitue_ne_rouvre_rien(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => 100]);
        $service = app(RestitutionService::class);
        $this->assertSame(['creees' => 1, 'inchangees' => 0], $service->ouvrir($this->cycle));
        $restitution = Restitution::sole();
        $agent = User::factory()->create(['role' => 'agent_transport']);
        $service->affecterAgent($restitution, $agent);

        // Le préfet n'en rend que 90 sur 100 : le retour est pris en charge, reçu et contrôlé.
        $this->en($this->autoriteFictive($this->prefecture), fn () => $service->declarer(
            $restitution, 'compte', ['nom' => 'Awa Fall', 'qualite' => 'Préfet'], [$this->urnes->id => ['restitue' => 90]], (string) Str::uuid(),
        ));
        $this->en($agent, fn () => $service->prendreEnCharge($restitution, $agent));
        $service->receptionner($restitution, [$this->urnes->id => 90]);
        $service->controler($restitution, [$this->urnes->id => ['bon' => 90, 'a_reparer' => 0, 'reforme' => 0]]);
        $this->assertSame('controlee', $restitution->fresh()->statut);

        // Les dix manquantes sont un écart à justifier, pas une nouvelle restitution.
        $this->assertSame(0, $this->solde('destinataire', $this->prefecture->id));
        $this->assertSame(['creees' => 0, 'inchangees' => 0], $service->ouvrir($this->cycle));
        $this->assertSame(1, Restitution::count());
        $ecart = $service->ecarts($this->cycle)->sole();
        $this->assertSame([100, 90, 90, 10, 0], [$ecart['attendu'], $ecart['declare_restitue'], $ecart['recu_central'], $ecart['non_restitue'], $ecart['perdu_en_retour']]);
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

    public function test_seule_la_declaration_retenue_compte(): void
    {
        $agent = User::factory()->create(['role' => 'agent_transport']);
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100], $agent);
        $remise = Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->with('lignes')->firstOrFail();
        $lignes = [$this->urnes->id => ['recu' => 100, 'defectueux' => 0, 'observation' => null]];
        $preuve = ['verification' => 'verifiee_code', 'bloc_empreinte' => null, 'signataire_user_id' => null, 'delegation_id' => null];

        // Tablette de l'agent (retenue) puis compte du préfet (concordante) : la quantité n'est comptée qu'une fois.
        $tablette = $this->en($agent, fn () => app(DeclarationService::class)->declarer(
            $remise, 'tablette', ['nom' => 'Awa Fall', 'qualite' => 'Préfet'], $lignes, (string) Str::uuid(), null, $preuve,
        ));
        $compte = $this->en($this->autoriteFictive($this->prefecture), fn () => app(DeclarationService::class)->declarer(
            $remise, 'compte', ['nom' => 'Awa Fall', 'qualite' => 'Préfet'], $lignes, (string) Str::uuid(),
        ));
        $this->assertSame(['retenue', 'concordante'], [$tablette->refresh()->statut, $compte->refresh()->statut]);

        $this->assertSame(2, Declaration::where('remise_id', $remise->id)->count());
        $this->assertSame([
            ['detenteur_type' => 'destinataire', 'detenteur_id' => $this->prefecture->id, 'materiel_id' => $this->urnes->id, 'solde' => 100],
        ], app(SoldeDetenteurService::class)->soldes($this->cycle)->all());
    }

    public function test_redistribution_avec_manquant_n_est_attendue_de_personne(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => 100]);
        $remise = $this->redistribuer($decharge->refresh(), [$this->urnes->id => 80]);
        $this->declarerRedistribution($remise, 70);
        $this->assertSame('retenue', Declaration::where('remise_id', $remise->id)->firstOrFail()->statut);

        // Le préfet est déchargé des 80 transférés, le sous-préfet ne doit que les 70 reçus : les 10 manquants
        // ne sont attendus de personne (ruling P6-R2).
        $this->assertSame(20, $this->solde('destinataire', $this->prefecture->id));
        $this->assertSame(70, $this->solde('destinataire', $this->sousPrefecture1->id));
        $this->assertSame(90, app(SoldeDetenteurService::class)->soldes($this->cycle)->sum('solde'));
    }

    public function test_circuit_etranger_credite_le_destinataire_sans_agent_ni_transporteur(): void
    {
        $this->approuverPlanFictif([[$this->poste, $this->urnes, 100]]);
        $service = app(DechargeService::class);
        $decharge = $service->preparer($this->cycle, $this->poste, 'materiel_lourd');
        foreach ($decharge->lignes as $ligne) {
            $service->modifierLigne($ligne, $ligne->materiel_id === $this->urnes->id ? 40 : 0, null);
        }
        $service->valider($decharge);
        app(RemiseService::class)->charger($decharge->refresh(), [
            'nom' => 'Moussa Ndiaye', 'qualite' => 'Chauffeur', 'societe' => 'Fret Express', 'bordereau' => 'BL-2026-001',
        ]);

        // Chargée, non reçue : personne ne doit rien (le transporteur n'est pas un détenteur).
        $this->assertSame([], app(SoldeDetenteurService::class)->soldes($this->cycle)->all());

        $this->deposerRecue($decharge->refresh(), [$this->urnes->id => 40]);

        $this->assertSame([
            ['detenteur_type' => 'destinataire', 'detenteur_id' => $this->poste->id, 'materiel_id' => $this->urnes->id, 'solde' => 40],
        ], app(SoldeDetenteurService::class)->soldes($this->cycle)->all());
    }

    public function test_nombre_de_requetes_independant_du_volume(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => 100]);
        $this->assertSame(1, app(SoldeDetenteurService::class)->soldes($this->cycle)->count());
        $petit = $this->nombreDeRequetes(fn () => app(SoldeDetenteurService::class)->soldes($this->cycle));

        // Plusieurs décharges, détenteurs et articles : un agent de plus, un second sous-préfet, des isoloirs, des redistributions.
        $this->isoloirs->forceFill(['reutilisable' => true])->save();
        $this->approuverPlanFictif([
            [$this->sousPrefecture1, $this->urnes, 200], [$this->sousPrefecture2, $this->urnes, 100],
            [$this->sousPrefecture1, $this->isoloirs, 50],
        ]);
        $autreAgent = User::factory()->create(['role' => 'agent_transport']);
        $deuxieme = $this->deposerChargee($this->sousPrefecture2, [$this->urnes->id => 100], $autreAgent);
        $this->deposerRecue($deuxieme, [$this->urnes->id => 90]);
        $troisieme = $this->deposerChargee($this->sousPrefecture1, [$this->isoloirs->id => 50]);
        $this->deposerRecue($troisieme, [$this->isoloirs->id => 50]);
        $this->declarerRedistribution($this->redistribuer($decharge->refresh(), [$this->urnes->id => 10]), 10);
        $this->redistribuer($deuxieme->refresh(), [$this->urnes->id => 20]);

        $this->assertGreaterThanOrEqual(4, app(SoldeDetenteurService::class)->soldes($this->cycle)->count());
        $grand = $this->nombreDeRequetes(fn () => app(SoldeDetenteurService::class)->soldes($this->cycle));

        $this->assertSame($petit, $grand);
        $this->assertLessThanOrEqual(5, $grand);
    }

    public function test_filtre_detenteur_type_et_identifiant_ensemble(): void
    {
        $service = app(SoldeDetenteurService::class);

        foreach ([['destinataire', null], [null, 5], ['inconnu', 5]] as [$type, $id]) {
            try {
                $service->soldes($this->cycle, $type, $id);
                $this->fail('Le filtre incomplet ou inconnu aurait dû être refusé.');
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertSame([], $service->soldes($this->cycle, 'agent', 5)->all());
    }

    private function nombreDeRequetes(callable $action): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $action();
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $requetes;
    }
}

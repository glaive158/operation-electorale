<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\CommissionAdministrative;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\JournalEntree;
use App\Models\User;
use App\Services\Logistique\CommissionService;
use App\Services\Logistique\DecoupageService;
use App\Services\Logistique\NomGeo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\Support\RecensementFictif;
use Tests\TestCase;

class CommissionsTest extends TestCase
{
    use RefreshDatabase;

    private CommissionService $commissions;

    /** @var array{regions: array<string,int>, departements: array<string,int>, arrondissements: array<string,int>, communes: array<string,int>} */
    private array $geo;

    private Cycle $cycle;

    protected function setUp(): void
    {
        parent::setUp();
        RecensementFictif::creerSchema();
        $this->commissions = app(CommissionService::class);
        $this->actingAs(User::factory()->create(['role' => 'logistique_responsable']));

        $this->geo = RecensementFictif::geo([
            'Thiès' => [
                'Mbour' => [
                    'Sindia' => ['Ndiass', 'Popenguine'],
                    'Fissel' => ['Ndiaganiao'],
                ],
            ],
        ]);

        $decoupage = app(DecoupageService::class);
        $decoupage->genererDestinataires();
        $this->cycle = Cycle::create(['libelle' => 'Cycle A', 'type' => 'scrutin']);
        $decoupage->genererPerimetre($this->cycle);
    }

    public function test_une_commission_fixe_par_commune_couverte(): void
    {
        $this->assertSame(3, CommissionAdministrative::creerFixesParCommune($this->cycle));
        $this->assertSame(3, CommissionAdministrative::where('cycle_id', $this->cycle->id)->where('type', 'fixe')->count());
        $this->assertSame(1, JournalEntree::where('action', 'creation_commissions_fixes')->count());

        $this->assertSame(0, CommissionAdministrative::creerFixesParCommune($this->cycle));
        $this->assertSame(3, CommissionAdministrative::where('cycle_id', $this->cycle->id)->count());
        $this->assertSame(1, JournalEntree::where('action', 'creation_commissions_fixes')->count());
    }

    public function test_itinerante_s_ajoute_a_la_fixe(): void
    {
        CommissionAdministrative::creerFixesParCommune($this->cycle);
        $ndiass = $this->geo['communes']['Ndiass'];

        $this->commissions->creer($this->cycle, ['type' => 'itinerante', 'commune_id' => $ndiass, 'libelle' => 'Tournée Ndiass']);

        $compte = CommissionAdministrative::compterParLocalite($this->cycle);
        $this->assertSame(2, $compte["commune:{$ndiass}"]);
        $this->assertSame(1, $compte['commune:'.$this->geo['communes']['Popenguine']]);
        $this->assertCount(3, $compte);
        $this->assertSame(1, JournalEntree::where('action', 'creation_commission')->count());
    }

    public function test_commission_etranger_par_ville(): void
    {
        $this->commissions->creer($this->cycle, ['type' => 'etranger', 'pays' => 'France', 'ville' => 'Lyon']);
        $this->commissions->creer($this->cycle, ['type' => 'etranger', 'pays' => '  france', 'ville' => 'LYON']);

        // Les deux graphies désignent la même localité : clé normalisée, comptée deux fois.
        $this->assertSame(2, CommissionAdministrative::compterParLocalite($this->cycle)['FRANCE|LYON']);
        $this->assertSame(NomGeo::cleEtranger('France', 'Lyon'), 'FRANCE|LYON');
    }

    public function test_localite_incoherente_refusee(): void
    {
        $ndiass = $this->geo['communes']['Ndiass'];

        foreach ([
            ['type' => 'fixe'],
            ['type' => 'itinerante', 'pays' => 'France', 'ville' => 'Lyon'],
            ['type' => 'fixe', 'commune_id' => $ndiass, 'pays' => 'France', 'ville' => 'Lyon'],
            ['type' => 'etranger', 'pays' => 'France'],
            ['type' => 'etranger', 'commune_id' => $ndiass, 'pays' => 'France', 'ville' => 'Lyon'],
            ['type' => 'inconnu', 'commune_id' => $ndiass],
        ] as $donnees) {
            try {
                $this->commissions->creer($this->cycle, $donnees);
                $this->fail('Une localité incohérente doit être refusée : '.json_encode($donnees));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        CommissionAdministrative::create(['cycle_id' => $this->cycle->id, 'type' => 'fixe']);
    }

    public function test_commune_inconnue_refusee(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->commissions->creer($this->cycle, ['type' => 'fixe', 'commune_id' => 999999]);
    }

    public function test_suppression_exige_un_motif_et_est_journalisee(): void
    {
        $commission = $this->commissions->creer($this->cycle, ['type' => 'etranger', 'pays' => 'France', 'ville' => 'Lyon']);

        try {
            $this->commissions->supprimer($commission, '  ');
            $this->fail('Le motif est obligatoire.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Le motif est obligatoire.', $e->getMessage());
        }

        $this->commissions->supprimer($commission, 'Créée par erreur');
        $this->assertSame(0, CommissionAdministrative::count());
        $this->assertSame('Créée par erreur', JournalEntree::where('action', 'suppression_commission')->firstOrFail()->motif);
    }

    public function test_cycle_clos_refuse(): void
    {
        $commission = $this->commissions->creer($this->cycle, ['type' => 'etranger', 'pays' => 'France', 'ville' => 'Lyon']);
        DB::table('cycles_electoraux')->where('id', $this->cycle->id)->update(['statut' => 'clos']);
        $this->cycle->refresh();

        foreach ([
            fn () => CommissionAdministrative::creerFixesParCommune($this->cycle),
            fn () => $this->commissions->creer($this->cycle, ['type' => 'fixe', 'commune_id' => $this->geo['communes']['Ndiass']]),
            fn () => $this->commissions->supprimer($commission, 'Test'),
        ] as $action) {
            try {
                $action();
                $this->fail('Un cycle clos doit refuser la modification des commissions.');
            } catch (RegleLogistique $e) {
                $this->assertSame('Cycle clôturé : commissions non modifiables.', $e->getMessage());
            }
        }

        $this->assertSame(1, CommissionAdministrative::where('cycle_id', $this->cycle->id)->count());
    }

    public function test_operation_sans_auteur_refusee(): void
    {
        auth()->logout();

        $this->expectException(LogicException::class);
        CommissionAdministrative::creerFixesParCommune($this->cycle);
    }

    public function test_cycles_independants(): void
    {
        $autre = Cycle::create(['libelle' => 'Cycle B', 'type' => 'scrutin']);
        CommissionAdministrative::creerFixesParCommune($this->cycle);

        $this->assertSame([], CommissionAdministrative::compterParLocalite($autre));
    }
}

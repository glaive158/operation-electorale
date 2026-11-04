<?php

namespace Tests\Feature\Logistique;

use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Remise;
use App\Models\User;
use App\Services\Logistique\AffectationsTerrain;
use Database\Seeders\LogistiqueTerrainDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class DemoTerrainSeederTest extends TestCase
{
    use RefreshDatabase;

    private string $cle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cle = tempnam(sys_get_temp_dir(), 'preuve').'.pem';
        config(['logistique.cle_preuve_privee' => $this->cle]);
    }

    protected function tearDown(): void
    {
        @unlink($this->cle);
        parent::tearDown();
    }

    private function semer(): void
    {
        Artisan::call('db:seed', ['--class' => LogistiqueTerrainDemoSeeder::class]);
        Auth::forgetUser();
    }

    public function test_scenario_pret_pour_l_application_de_terrain(): void
    {
        $this->semer();

        $cycle = Cycle::firstOrFail();
        $this->assertTrue($cycle->estOuvert());
        $agent = User::where('email', 'demo.agent@terrain.test')->firstOrFail();
        $autorite = User::where('email', 'demo.souspref@terrain.test')->firstOrFail();
        $this->assertSame('agent_transport', $agent->role);
        $this->assertSame('sous_prefet', $autorite->role);

        // Le sous-préfet a déjà choisi son code : l'application de terrain lui est ouverte.
        $this->assertFalse((bool) $autorite->doit_choisir_code);
        $this->assertNotNull($autorite->code_reception_hash);

        $decharge = Decharge::firstOrFail();
        $this->assertSame('validee', $decharge->statut_document);
        $this->assertSame($agent->id, (int) $decharge->agent_id);
        $livraison = Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->firstOrFail();
        $this->assertSame('en_attente', $livraison->statut);

        // L'agent télécharge cette remise, avec l'autorité attendue.
        $elements = app(AffectationsTerrain::class)->pour($agent);
        $this->assertCount(1, $elements);
        $this->assertSame($livraison->id, $elements[0]['remise']['id']);
        $this->assertSame($autorite->id, $elements[0]['autorite_attendue']['user_id']);
        $this->assertCount(2, $elements[0]['lignes']);

        $this->assertFileExists($this->cle);
    }

    public function test_seeder_idempotent_tant_que_la_livraison_attend_puis_nouveau_cycle(): void
    {
        $this->semer();
        $this->semer();

        $this->assertSame(1, Cycle::count());
        $this->assertSame(1, Decharge::count());
        $this->assertSame(3, User::count());
        $ancien = User::where('email', 'demo.souspref@terrain.test')->value('code_reception_hash');
        $this->assertNotNull($ancien);

        // Livraison constatée : la démonstration suivante ouvre un nouveau cycle sans toucher aux comptes.
        Remise::where('etape', 'livraison')->update(['statut' => 'recue']);
        $this->semer();

        $this->assertSame(2, Cycle::count());
        $this->assertSame(2, Decharge::count());
        $this->assertSame(3, User::count());
        $this->assertSame(1, Remise::where('etape', 'livraison')->where('statut', 'en_attente')->count());
    }

    public function test_refuse_en_production(): void
    {
        $this->app['env'] = 'production';

        $this->semer();

        $this->assertSame(0, User::count());
    }
}

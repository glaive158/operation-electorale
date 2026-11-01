<?php

namespace Tests\Feature\Logistique;

use App\Models\Logistique\CorrespondanceGeo;
use App\Models\Logistique\JournalEntree;
use App\Models\User;
use App\Services\Logistique\CorrespondanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\Support\RecensementFictif;
use Tests\TestCase;

class CorrespondancesTest extends TestCase
{
    use RefreshDatabase;

    private CorrespondanceService $service;

    private User $auteur;

    /** @var array{regions: array<string,int>, departements: array<string,int>, arrondissements: array<string,int>, communes: array<string,int>} */
    private array $geo;

    protected function setUp(): void
    {
        parent::setUp();
        RecensementFictif::creerSchema();
        $this->service = app(CorrespondanceService::class);
        $this->auteur = User::factory()->create(['role' => 'logistique_responsable']);
        $this->actingAs($this->auteur);

        $this->geo = RecensementFictif::geo([
            'Thiès' => [
                'Mbour' => ['Sindia' => ['Ndiass', 'Popenguine']],
            ],
            'Kaolack' => [
                'Kaolack' => ['Ndiaffate' => ['Ndiass']],
            ],
            'Sédhiou' => [
                'Sédhiou' => ['Bambali' => ['Sédhiou']],
            ],
        ]);
    }

    private function communeId(string $departement, string $nom): int
    {
        return (int) \Illuminate\Support\Facades\DB::connection('recensement')->table('communes')
            ->join('arrondissements', 'arrondissements.id', '=', 'communes.arrondissement_id')
            ->join('departements', 'departements.id', '=', 'arrondissements.departement_id')
            ->where('departements.nom', $departement)->where('communes.nom', $nom)
            ->value('communes.id');
    }

    private function trouver(string $region, string $departement, string $commune): CorrespondanceGeo
    {
        return CorrespondanceGeo::where([
            'contexte_region' => $region, 'contexte_departement' => $departement, 'libelle_commune' => $commune,
        ])->firstOrFail();
    }

    public function test_correspondance_exacte_validee_automatiquement(): void
    {
        RecensementFictif::electeurs('national', 'THIES', 'MBOUR', 'NDIASS', 'B1', 'L1', 2);

        $resultat = $this->service->rapprocher();

        $c = $this->trouver('THIES', 'MBOUR', 'NDIASS');
        $this->assertSame('validee', $c->statut);
        $this->assertSame('auto', $c->methode);
        $this->assertSame($this->communeId('Mbour', 'Ndiass'), $c->commune_id);
        $this->assertSame(['creees' => 1, 'auto' => 1, 'a_valider' => 0], $resultat);
    }

    public function test_variante_d_accent_validee_automatiquement(): void
    {
        RecensementFictif::electeurs('national', 'SEDHIOU', 'SEDHIOU', 'SEDHIOU', 'B1', 'L1', 1);

        $this->service->rapprocher();

        $c = $this->trouver('SEDHIOU', 'SEDHIOU', 'SEDHIOU');
        $this->assertSame('validee', $c->statut);
        $this->assertSame($this->communeId('Sédhiou', 'Sédhiou'), $c->commune_id);
    }

    public function test_homonyme_rapproche_dans_son_departement(): void
    {
        RecensementFictif::electeurs('national', 'THIES', 'MBOUR', 'NDIASS', 'B1', 'L1', 1);

        $this->service->rapprocher();

        $c = $this->trouver('THIES', 'MBOUR', 'NDIASS');
        $this->assertSame('validee', $c->statut);
        $this->assertSame($this->communeId('Mbour', 'Ndiass'), $c->commune_id);
        $this->assertNotSame($this->communeId('Kaolack', 'Ndiass'), $c->commune_id);
    }

    public function test_inconnue_proposee_sans_commune(): void
    {
        RecensementFictif::electeurs('national', 'THIES', 'MBOUR', 'XYZ', 'B1', 'L1', 1);

        $resultat = $this->service->rapprocher();

        $c = $this->trouver('THIES', 'MBOUR', 'XYZ');
        $this->assertSame('proposee', $c->statut);
        $this->assertNull($c->commune_id);
        $this->assertSame(['creees' => 1, 'auto' => 0, 'a_valider' => 1], $resultat);
    }

    public function test_plusieurs_candidats_proposes_avec_le_plus_petit_id(): void
    {
        // Deux communes homonymes dans le même département.
        $premier = $this->communeId('Mbour', 'Ndiass');
        $arr = \Illuminate\Support\Facades\DB::connection('recensement')->table('arrondissements')->where('nom', 'Sindia')->value('id');
        $second = \Illuminate\Support\Facades\DB::connection('recensement')->table('communes')->insertGetId(['nom' => 'Ndiass', 'arrondissement_id' => $arr]);
        RecensementFictif::electeurs('national', 'THIES', 'MBOUR', 'NDIASS', 'B1', 'L1', 1);

        $resultat = $this->service->rapprocher();

        $c = $this->trouver('THIES', 'MBOUR', 'NDIASS');
        $this->assertSame('proposee', $c->statut);
        $this->assertSame($premier, $c->commune_id);
        $this->assertLessThan($second, $c->commune_id);
        $this->assertSame(1, $resultat['a_valider']);
    }

    public function test_triplets_incomplets_ou_etrangers_ignores(): void
    {
        RecensementFictif::electeurs('etranger', 'France', null, 'Paris', 'B1', 'L1', 1);
        \Illuminate\Support\Facades\DB::connection('recensement')->table('fichier_electoral')->insert([
            ['origine' => 'national', 'departement' => 'THIES', 'arrondissement' => 'MBOUR', 'commune' => null, 'lieu_vote' => 'X', 'code_bureau' => 'B2'],
            ['origine' => 'national', 'departement' => 'THIES', 'arrondissement' => '', 'commune' => 'NDIASS', 'lieu_vote' => 'X', 'code_bureau' => 'B3'],
        ]);

        $resultat = $this->service->rapprocher();

        $this->assertSame(['creees' => 0, 'auto' => 0, 'a_valider' => 0], $resultat);
        $this->assertSame(0, CorrespondanceGeo::count());
    }

    public function test_validation_manuelle_et_journal(): void
    {
        RecensementFictif::electeurs('national', 'THIES', 'MBOUR', 'XYZ', 'B1', 'L1', 1);
        $this->service->rapprocher();
        $c = $this->trouver('THIES', 'MBOUR', 'XYZ');
        $id = $this->communeId('Mbour', 'Popenguine');

        $this->service->valider($c, $id);

        $c->refresh();
        $this->assertSame('validee', $c->statut);
        $this->assertSame('manuelle', $c->methode);
        $this->assertSame($id, $c->commune_id);
        $this->assertSame($this->auteur->id, $c->valide_par);
        $this->assertNotNull($c->valide_le);
        $this->assertTrue(JournalEntree::where('objet_type', 'correspondance_geo')
            ->where('objet_id', $c->id)->where('action', 'validation')->exists());
    }

    public function test_validation_commune_inconnue_refusee(): void
    {
        RecensementFictif::electeurs('national', 'THIES', 'MBOUR', 'XYZ', 'B1', 'L1', 1);
        $this->service->rapprocher();
        $c = $this->trouver('THIES', 'MBOUR', 'XYZ');

        $this->expectException(InvalidArgumentException::class);
        $this->service->valider($c, 999999);
    }

    public function test_rejet_exige_un_motif(): void
    {
        RecensementFictif::electeurs('national', 'THIES', 'MBOUR', 'XYZ', 'B1', 'L1', 1);
        $this->service->rapprocher();
        $c = $this->trouver('THIES', 'MBOUR', 'XYZ');

        $this->expectException(InvalidArgumentException::class);
        $this->service->rejeter($c, ' ');
    }

    public function test_rejet_journalise_et_efface_la_commune(): void
    {
        RecensementFictif::electeurs('national', 'THIES', 'MBOUR', 'NDIASS', 'B1', 'L1', 1);
        $this->service->rapprocher();
        $c = $this->trouver('THIES', 'MBOUR', 'NDIASS');

        $this->service->rejeter($c, 'Commune inexistante');

        $c->refresh();
        $this->assertSame('rejetee', $c->statut);
        $this->assertNull($c->commune_id);
        $this->assertSame('Commune inexistante', $c->motif);
        $this->assertTrue(JournalEntree::where('objet_type', 'correspondance_geo')
            ->where('objet_id', $c->id)->where('action', 'rejet')->exists());
    }

    public function test_rapprocher_ne_retouche_pas_les_validees(): void
    {
        RecensementFictif::electeurs('national', 'THIES', 'MBOUR', 'NDIASS', 'B1', 'L1', 1);
        $this->service->rapprocher();
        $c = $this->trouver('THIES', 'MBOUR', 'NDIASS');
        $autre = $this->communeId('Mbour', 'Popenguine');
        $this->service->valider($c, $autre);

        $resultat = $this->service->rapprocher();

        $c->refresh();
        $this->assertSame($autre, $c->commune_id);
        $this->assertSame('manuelle', $c->methode);
        $this->assertSame(['creees' => 0, 'auto' => 0, 'a_valider' => 0], $resultat);
        $this->assertSame(1, CorrespondanceGeo::count());
    }

    public function test_communeValidee_ignore_proposees_et_rejetees(): void
    {
        RecensementFictif::electeurs('national', 'THIES', 'MBOUR', 'NDIASS', 'B1', 'L1', 1);
        RecensementFictif::electeurs('national', 'THIES', 'MBOUR', 'XYZ', 'B2', 'L1', 1);
        RecensementFictif::electeurs('national', 'THIES', 'MBOUR', 'POPENGUINE', 'B3', 'L2', 1);
        $this->service->rapprocher();
        $this->service->rejeter($this->trouver('THIES', 'MBOUR', 'POPENGUINE'), 'Erreur de saisie');

        $this->assertSame($this->communeId('Mbour', 'Ndiass'), $this->service->communeValidee('THIES', 'MBOUR', 'NDIASS'));
        $this->assertNull($this->service->communeValidee('THIES', 'MBOUR', 'XYZ'));
        $this->assertNull($this->service->communeValidee('THIES', 'MBOUR', 'POPENGUINE'));
        $this->assertNull($this->service->communeValidee('THIES', 'MBOUR', 'INCONNU'));
    }

    public function test_operation_sans_auteur_refusee(): void
    {
        RecensementFictif::electeurs('national', 'THIES', 'MBOUR', 'XYZ', 'B1', 'L1', 1);
        auth()->logout();

        $this->expectException(LogicException::class);
        $this->service->rapprocher();
    }
}

<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Jobs\Logistique\CapturerInstantane;
use App\Models\Logistique\CommissionAdministrative;
use App\Models\Logistique\CorrespondanceGeo;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\InstantaneEffectifs;
use App\Models\Logistique\InstantaneLigne;
use App\Models\Logistique\JournalEntree;
use App\Models\User;
use App\Services\Logistique\CorrespondanceService;
use App\Services\Logistique\DecoupageService;
use App\Services\Logistique\InstantaneService;
use App\Services\Logistique\LectureRecensement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use LogicException;
use RuntimeException;
use Tests\Support\RecensementFictif;
use Tests\TestCase;

class InstantaneTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{regions: array<string,int>, departements: array<string,int>, arrondissements: array<string,int>, communes: array<string,int>} */
    private array $geo;

    private Cycle $cycle;

    private User $utilisateur;

    protected function setUp(): void
    {
        parent::setUp();
        RecensementFictif::creerSchema();
        $this->utilisateur = User::factory()->create(['role' => 'logistique_responsable']);
        $this->actingAs($this->utilisateur);

        $this->geo = RecensementFictif::geo([
            'Thiès' => [
                'Mbour' => [
                    'Sindia' => ['Ndiass', 'Popenguine'],
                ],
            ],
        ]);

        $decoupage = app(DecoupageService::class);
        $decoupage->genererDestinataires();
        $this->cycle = Cycle::create(['libelle' => 'Cycle A', 'type' => 'scrutin']);
        $decoupage->genererPerimetre($this->cycle);
    }

    private function service(): InstantaneService
    {
        return app(InstantaneService::class);
    }

    /** Capture complète (file synchrone) : retourne l'instantané en brouillon. */
    private function capturer(): InstantaneEffectifs
    {
        return $this->service()->demarrer($this->cycle);
    }

    private function valider(string $region, string $departement, string $commune, string $nomCommune): void
    {
        CorrespondanceGeo::create([
            'contexte_region' => $region, 'contexte_departement' => $departement, 'libelle_commune' => $commune,
            'commune_id' => $this->geo['communes'][$nomCommune], 'statut' => 'validee', 'methode' => 'auto',
        ]);
    }

    private function ligneCommune(InstantaneEffectifs $i, string $commune): InstantaneLigne
    {
        return InstantaneLigne::where('instantane_id', $i->id)
            ->where('commune_id', $this->geo['communes'][$commune])->firstOrFail();
    }

    public function test_capture_agrege_par_commune(): void
    {
        RecensementFictif::electeurs('national', 'Thiès', 'Mbour', 'Ndiass', '0101010101', '01010101', 10);
        RecensementFictif::electeurs('national', 'Thiès', 'Mbour', 'Ndiass', '0101010102', '01010101', 20);
        app(CorrespondanceService::class)->rapprocher();

        $i = $this->capturer();

        $this->assertSame('brouillon', $i->statut);
        $this->assertSame(1, $i->version);
        $ligne = $this->ligneCommune($i, 'Ndiass');
        $this->assertSame(30, $ligne->electeurs);
        $this->assertSame(2, $ligne->bureaux_vote);
        $this->assertSame(1, $ligne->lieux_vote);
        $this->assertTrue($ligne->rapprochee);
        $this->assertSame($this->geo['departements']['Mbour'], $ligne->departement_id);
        $this->assertNotNull($ligne->destinataire_id);
        $this->assertSame(30, $i->bilan['electeurs_total']);
        $this->assertSame(30, $i->bilan['electeurs_rapproches']);
        $this->assertSame(0, $i->bilan['electeurs_non_rapproches']);
        $this->assertSame(['Thiès' => 30], $i->bilan['totaux_par_region']);
    }

    public function test_deux_graphies_meme_commune_additionnees(): void
    {
        RecensementFictif::electeurs('national', 'Thiès', 'Mbour', 'NDIASS', '0101010101', '01010101', 30);
        RecensementFictif::electeurs('national', 'Thiès', 'Mbour', "N'DIASS", '0101010201', '01010102', 5);
        $this->valider('Thiès', 'Mbour', 'NDIASS', 'Ndiass');
        $this->valider('Thiès', 'Mbour', "N'DIASS", 'Ndiass');

        $i = $this->capturer();

        $this->assertSame(1, InstantaneLigne::where('instantane_id', $i->id)->count());
        $ligne = $this->ligneCommune($i, 'Ndiass');
        $this->assertSame(35, $ligne->electeurs);
        $this->assertSame(2, $ligne->bureaux_vote);
        $this->assertSame(2, $ligne->lieux_vote);
    }

    public function test_commune_non_rapprochee_isolee_dans_le_bilan(): void
    {
        RecensementFictif::electeurs('national', 'Thiès', 'Mbour', 'Ndiass', '0101010101', '01010101', 10);
        RecensementFictif::electeurs('national', 'Thiès', 'Mbour', 'Inconnue', '0202020201', '02020202', 7);
        RecensementFictif::electeurs('national', 'Thiès', 'Mbour', 'Sans decision', '0303030301', '03030303', 4);
        app(CorrespondanceService::class)->rapprocher();
        // Triplet proposé (candidat connu) mais non validé : reste non rapproché.
        CorrespondanceGeo::where('libelle_commune', 'Inconnue')->update(['commune_id' => $this->geo['communes']['Popenguine']]);

        $i = $this->capturer();

        $nonRapprochees = InstantaneLigne::where('instantane_id', $i->id)->where('rapprochee', false)->orderBy('libelle_commune')->get();
        $this->assertCount(2, $nonRapprochees);
        $this->assertNull($nonRapprochees[0]->commune_id);
        $this->assertNull($nonRapprochees[0]->destinataire_id);
        $this->assertSame('Inconnue', $nonRapprochees[0]->libelle_commune);
        $this->assertSame('Thiès', $nonRapprochees[0]->contexte_region);
        $this->assertSame('Mbour', $nonRapprochees[0]->contexte_departement);
        $this->assertSame(7, $nonRapprochees[0]->electeurs);

        $this->assertSame(21, $i->bilan['electeurs_total']);
        $this->assertSame(11, $i->bilan['electeurs_non_rapproches']);
        $this->assertSame(10, $i->bilan['electeurs_rapproches']);
        $this->assertCount(2, $i->bilan['lignes_non_rapprochees']);
        $this->assertSame(21, array_sum($i->bilan['totaux_par_region']));
    }

    public function test_aucun_electeur_perdu_sans_correspondance(): void
    {
        RecensementFictif::electeurs('national', 'Thiès', 'Mbour', 'Ndiass', '0101010101', '01010101', 10);

        $i = $this->capturer();

        $this->assertSame(10, $i->bilan['electeurs_total']);
        $this->assertSame(10, $i->bilan['electeurs_non_rapproches']);
        $this->assertSame(10, InstantaneLigne::where('instantane_id', $i->id)->sum('electeurs'));
    }

    public function test_correspondance_vers_commune_disparue_reste_non_rapprochee(): void
    {
        RecensementFictif::electeurs('national', 'Thiès', 'Mbour', 'Ndiass', '0101010101', '01010101', 10);
        CorrespondanceGeo::create([
            'contexte_region' => 'Thiès', 'contexte_departement' => 'Mbour', 'libelle_commune' => 'Ndiass',
            'commune_id' => 99999, 'statut' => 'validee', 'methode' => 'manuelle',
        ]);

        $i = $this->capturer();

        $this->assertSame(10, $i->bilan['electeurs_non_rapproches']);
        $this->assertSame(1, InstantaneLigne::where('instantane_id', $i->id)->where('rapprochee', false)->count());
    }

    public function test_etranger_par_pays_et_ville(): void
    {
        RecensementFictif::electeurs('etranger', 'France', null, 'Lyon', '9001010101', '90010101', 12);

        $i = $this->capturer();

        $ligne = InstantaneLigne::where('instantane_id', $i->id)->where('pays', 'France')->firstOrFail();
        $this->assertSame('Lyon', $ligne->ville);
        $this->assertNull($ligne->commune_id);
        $this->assertSame(12, $ligne->electeurs);
        $this->assertTrue($ligne->rapprochee);
        $this->assertSame(12, $i->bilan['totaux_par_region']['Étranger']);
        $this->assertSame(12, $i->bilan['electeurs_total']);
        $this->assertSame(12, $i->bilan['electeurs_rapproches']);
    }

    public function test_localites_sans_destinataire_listees(): void
    {
        RecensementFictif::electeurs('etranger', 'France', null, 'Lyon', '9001010101', '90010101', 12);

        $i = $this->capturer();

        $this->assertSame(['France | Lyon'], $i->bilan['localites_sans_destinataire']);
        $this->assertNull(InstantaneLigne::where('instantane_id', $i->id)->where('pays', 'France')->value('destinataire_id'));
    }

    public function test_commissions_copiees(): void
    {
        RecensementFictif::electeurs('national', 'Thiès', 'Mbour', 'Ndiass', '0101010101', '01010101', 10);
        app(CorrespondanceService::class)->rapprocher();
        CommissionAdministrative::creerFixesParCommune($this->cycle);
        CommissionAdministrative::create([
            'cycle_id' => $this->cycle->id, 'type' => 'itinerante', 'commune_id' => $this->geo['communes']['Ndiass'],
        ]);

        $i = $this->capturer();

        $this->assertSame(2, $this->ligneCommune($i, 'Ndiass')->commissions);
    }

    public function test_controles_rejoues(): void
    {
        RecensementFictif::electeurs('national', 'Thiès', 'Mbour', 'Ndiass', '010101010', '01010101', 1);
        RecensementFictif::electeurs('national', 'Thiès', 'Mbour', 'Ndiass', '0101030101', 'A', 1);
        RecensementFictif::electeurs('national', 'Thiès', 'Mbour', 'Ndiass', '0101030102', 'B', 1);

        $i = $this->capturer();

        $this->assertSame(1, $i->bilan['controles']['codes_longueur_invalide']);
        $this->assertSame(1, $i->bilan['controles']['prefixes_incoherents']);
        $this->assertSame(0, $i->bilan['controles']['codes_multi_communes']);
    }

    public function test_controle_code_sur_plusieurs_communes(): void
    {
        RecensementFictif::electeurs('national', 'Thiès', 'Mbour', 'Ndiass', '0101010101', '01010101', 1);
        RecensementFictif::electeurs('national', 'Thiès', 'Mbour', 'Popenguine', '0101010101', '01010101', 1);

        $this->assertSame(1, $this->capturer()->bilan['controles']['codes_multi_communes']);
    }

    public function test_valeurs_blanches_ignorees_par_les_agregats(): void
    {
        RecensementFictif::electeurs('national', 'Thiès', 'Mbour', '   ', '0101010101', '01010101', 3);
        RecensementFictif::electeurs('national', 'Thiès', 'Mbour', 'Ndiass', '0101010101', '01010101', 2);

        $agregats = app(LectureRecensement::class)->agregatsNational();

        $this->assertCount(1, $agregats);
        $this->assertSame('Ndiass', $agregats->first()->commune);
        $this->assertSame(2, (int) $agregats->first()->electeurs);
    }

    public function test_versions_successives_et_figer(): void
    {
        RecensementFictif::electeurs('national', 'Thiès', 'Mbour', 'Ndiass', '0101010101', '01010101', 3);

        $v1 = $this->capturer();
        $v2 = $this->capturer();

        $this->assertSame([1, 2], [$v1->version, $v2->version]);
        $this->assertFalse($v2->estUtilisable());

        $this->service()->figer($v2);

        $v2->refresh();
        $this->assertSame('fige', $v2->statut);
        $this->assertTrue($v2->estUtilisable());
        $this->assertSame($this->utilisateur->id, $v2->fige_par);
        $this->assertNotNull($v2->fige_le);
        $this->assertSame(1, JournalEntree::where('action', 'figement_instantane')->count());

        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Seul un instantané brouillon peut être figé.');
        $this->service()->figer($v2);
    }

    public function test_echec_de_capture_n_ecrit_aucune_ligne(): void
    {
        RecensementFictif::electeurs('national', 'Thiès', 'Mbour', 'Ndiass', '0101010101', '01010101', 3);
        $this->app->bind(LectureRecensement::class, fn () => new class extends LectureRecensement
        {
            public function agregatsNational(): Collection
            {
                throw new RuntimeException('Base du recensement indisponible');
            }
        });

        $i = $this->capturer();

        $this->assertSame('echec', $i->statut);
        $this->assertSame('Base du recensement indisponible', $i->erreur);
        $this->assertSame(0, InstantaneLigne::count());

        // Un échec ne bloque pas la capture suivante.
        $this->app->forgetInstance(InstantaneService::class);
        $this->app->bind(LectureRecensement::class, fn () => new LectureRecensement);
        $this->assertSame(2, $this->capturer()->version);
    }

    public function test_cycle_clos_pendant_la_capture_annule_les_lignes(): void
    {
        RecensementFictif::electeurs('national', 'Thiès', 'Mbour', 'Ndiass', '0101010101', '01010101', 3);
        Queue::fake();
        $i = $this->service()->demarrer($this->cycle);
        $this->cycle->forceFill(['statut' => 'clos'])->save();

        $this->service()->capturer($i);

        $i->refresh();
        $this->assertSame('echec', $i->statut);
        $this->assertNotEmpty($i->erreur);
        $this->assertSame(0, InstantaneLigne::count());
    }

    public function test_capture_en_cours_unique(): void
    {
        Queue::fake();

        $premier = $this->service()->demarrer($this->cycle);
        $this->assertSame('en_cours', $premier->statut);
        Queue::assertPushed(CapturerInstantane::class, 1);

        $this->expectException(RegleLogistique::class);
        $this->service()->demarrer($this->cycle);
    }

    public function test_demarrer_refuse_un_cycle_clos(): void
    {
        $this->cycle->forceFill(['statut' => 'clos'])->save();

        $this->expectException(RegleLogistique::class);
        $this->service()->demarrer($this->cycle);
    }

    public function test_demarrer_exige_un_auteur(): void
    {
        auth()->logout();

        $this->expectException(LogicException::class);
        $this->service()->demarrer($this->cycle);
    }

    public function test_le_job_est_mis_en_file(): void
    {
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, new CapturerInstantane(1));
    }
}

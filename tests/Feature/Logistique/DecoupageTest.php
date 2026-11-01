<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\PerimetreDestinataire;
use App\Models\Logistique\PosteEtranger;
use App\Models\User;
use App\Services\Logistique\DecoupageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecensementFictif;
use Tests\TestCase;

class DecoupageTest extends TestCase
{
    use RefreshDatabase;

    private DecoupageService $decoupage;

    /** @var array{regions: array<string,int>, departements: array<string,int>, arrondissements: array<string,int>, communes: array<string,int>} */
    private array $geo;

    protected function setUp(): void
    {
        parent::setUp();
        RecensementFictif::creerSchema();
        $this->decoupage = app(DecoupageService::class);
        $this->actingAs(User::factory()->create(['role' => 'logistique_responsable']));

        $this->geo = RecensementFictif::geo([
            'Thiès' => [
                'Mbour' => [
                    'Sindia' => ['Ndiass', 'Popenguine'],
                    'Fissel' => ['Ndiaganiao'],
                ],
                'Thiès' => [
                    'Thiénaba' => [],
                ],
            ],
        ]);
    }

    private function cycle(string $libelle = 'Cycle A'): Cycle
    {
        return Cycle::create(['libelle' => $libelle, 'type' => 'scrutin']);
    }

    private function destinataire(string $libelle): Destinataire
    {
        return Destinataire::where('libelle', $libelle)->firstOrFail();
    }

    public function test_generer_destinataires_cree_prefectures_et_sous_prefectures(): void
    {
        $resultat = $this->decoupage->genererDestinataires();

        $this->assertSame(5, $resultat['crees']);
        $this->assertSame(2, Destinataire::where('type', 'prefecture')->count());
        $this->assertSame(3, Destinataire::where('type', 'sous_prefecture')->count());
        $this->assertSame(
            $this->destinataire('Préfecture de Mbour')->id,
            $this->destinataire('Sous-préfecture de Sindia')->prefecture_id,
        );

        $this->assertSame(0, $this->decoupage->genererDestinataires()['crees']);
        $this->assertSame(5, Destinataire::count());
    }

    public function test_genererPerimetre_affecte_chaque_commune_une_fois(): void
    {
        $this->decoupage->genererDestinataires();
        $cycle = $this->cycle();

        $premier = $this->decoupage->genererPerimetre($cycle);
        $this->assertSame(3, $premier['affectees']);
        $this->assertSame(0, $premier['sans_destinataire']);
        $this->assertSame(3, PerimetreDestinataire::where('cycle_id', $cycle->id)->count());

        $perimetre = PerimetreDestinataire::where('commune_id', $this->geo['communes']['Ndiaganiao'])->firstOrFail();
        $this->assertSame($this->destinataire('Sous-préfecture de Fissel')->id, $perimetre->destinataire_id);

        $second = $this->decoupage->genererPerimetre($cycle);
        $this->assertSame(0, $second['affectees']);
        $this->assertSame(3, PerimetreDestinataire::where('cycle_id', $cycle->id)->count());
    }

    public function test_reaffectation_manuelle_conservee_au_regenerer(): void
    {
        $this->decoupage->genererDestinataires();
        $cycle = $this->cycle();
        $this->decoupage->genererPerimetre($cycle);

        $prefecture = $this->destinataire('Préfecture de Mbour');
        $popenguine = $this->geo['communes']['Popenguine'];
        $this->decoupage->affecter($cycle, $prefecture, $popenguine, null, null, 'Rattachement direct');

        $this->decoupage->genererPerimetre($cycle);

        $perimetre = PerimetreDestinataire::where('cycle_id', $cycle->id)->where('commune_id', $popenguine)->firstOrFail();
        $this->assertSame($prefecture->id, $perimetre->destinataire_id);

        $entree = JournalEntree::where('action', 'affectation')->latest('id')->firstOrFail();
        $this->assertSame('Rattachement direct', $entree->motif);
    }

    public function test_doublon_de_localite_impossible(): void
    {
        $this->decoupage->genererDestinataires();
        $cycle = $this->cycle();
        $commune = $this->geo['communes']['Ndiass'];

        $this->decoupage->affecter($cycle, $this->destinataire('Préfecture de Mbour'), $commune, null, null, 'Premier choix');
        $this->decoupage->affecter($cycle, $this->destinataire('Sous-préfecture de Fissel'), $commune, null, null, 'Second choix');

        $lignes = PerimetreDestinataire::where('cycle_id', $cycle->id)->where('commune_id', $commune)->get();
        $this->assertCount(1, $lignes);
        $this->assertSame($this->destinataire('Sous-préfecture de Fissel')->id, $lignes->first()->destinataire_id);
    }

    public function test_etranger_consulat_prefere_a_l_ambassade(): void
    {
        PosteEtranger::create(['pays' => 'France', 'ville' => 'Paris', 'type' => 'ambassade', 'nom' => 'Ambassade de Paris']);
        PosteEtranger::create(['pays' => 'France', 'ville' => 'Lyon', 'type' => 'consulat', 'nom' => 'Consulat de Lyon']);
        RecensementFictif::electeurs('etranger', 'France', null, 'Marseille', 'B1', 'Salle A', 2);
        RecensementFictif::electeurs('etranger', 'France', null, 'Paris', 'B2', 'Salle B', 2);

        $this->decoupage->genererDestinataires();
        $cycle = $this->cycle();
        $resultat = $this->decoupage->genererPerimetre($cycle);

        $this->assertSame(0, $resultat['sans_destinataire']);

        $marseille = PerimetreDestinataire::where('pays', 'France')->where('ville', 'Marseille')->firstOrFail();
        $this->assertSame($this->destinataire('Consulat de Lyon')->id, $marseille->destinataire_id);
        $this->assertSame('FRANCE|MARSEILLE', $marseille->localite());

        $paris = PerimetreDestinataire::where('pays', 'France')->where('ville', 'Paris')->firstOrFail();
        $this->assertSame($this->destinataire('Ambassade de Paris')->id, $paris->destinataire_id);
    }

    public function test_ville_du_fichier_comparee_au_poste_sans_tenir_compte_de_la_casse(): void
    {
        PosteEtranger::create(['pays' => 'FRANCE', 'ville' => 'Lyon', 'type' => 'consulat', 'nom' => 'Consulat de Lyon']);
        PosteEtranger::create(['pays' => 'FRANCE', 'ville' => 'Paris', 'type' => 'consulat', 'nom' => 'Consulat général de Paris']);
        RecensementFictif::electeurs('etranger', 'FRANCE', null, 'PARIS', 'B2', 'Salle B', 2);
        RecensementFictif::electeurs('etranger', ' france ', null, 'paris ', 'B3', 'Salle C', 1);

        $this->decoupage->genererDestinataires();
        $cycle = $this->cycle();
        $resultat = $this->decoupage->genererPerimetre($cycle);

        // Deux graphies de la même ville : une seule localité, confiée au consulat de la ville.
        $this->assertSame(['affectees' => 3 + 1, 'sans_destinataire' => 0], $resultat);
        $paris = PerimetreDestinataire::where('cycle_id', $cycle->id)->whereNull('commune_id')->sole();
        $this->assertSame($this->destinataire('Consulat général de Paris')->id, $paris->destinataire_id);
        $this->assertSame(trim($paris->pays), $paris->pays);
        $this->assertSame(trim($paris->ville), $paris->ville);
        $this->assertSame('FRANCE|PARIS', $paris->localite());

        // Réaffectation et retrait retrouvent la localité quelle que soit la graphie saisie.
        $this->decoupage->affecter($cycle, $this->destinataire('Consulat de Lyon'), null, 'France', 'Paris', 'renfort');
        $this->assertSame(1, PerimetreDestinataire::where('cycle_id', $cycle->id)->whereNull('commune_id')->count());
        $this->assertSame($this->destinataire('Consulat de Lyon')->id, $paris->fresh()->destinataire_id);

        $this->decoupage->retirer($cycle, null, 'france', 'PARIS', 'ville fermée');
        $this->assertSame(0, PerimetreDestinataire::where('cycle_id', $cycle->id)->whereNull('commune_id')->count());
    }

    public function test_etranger_sans_poste_reste_sans_destinataire(): void
    {
        RecensementFictif::electeurs('etranger', 'Japon', null, 'Tokyo', 'B1', 'Salle A', 3);

        $this->decoupage->genererDestinataires();
        $resultat = $this->decoupage->genererPerimetre($this->cycle());

        $this->assertSame(1, $resultat['sans_destinataire']);
        $this->assertSame(0, PerimetreDestinataire::whereNotNull('pays')->count());
    }

    public function test_etranger_ligne_sans_ville_ou_sans_pays_ignoree(): void
    {
        DB::connection('recensement')->table('fichier_electoral')->insert([
            ['origine' => 'etranger', 'departement' => 'France', 'arrondissement' => null, 'commune' => null, 'lieu_vote' => 'X', 'code_bureau' => 'B1'],
            ['origine' => 'etranger', 'departement' => 'France', 'arrondissement' => null, 'commune' => '', 'lieu_vote' => 'X', 'code_bureau' => 'B2'],
            ['origine' => 'etranger', 'departement' => null, 'arrondissement' => null, 'commune' => 'Paris', 'lieu_vote' => 'X', 'code_bureau' => 'B3'],
        ]);
        RecensementFictif::electeurs('etranger', 'Japon', null, 'Tokyo', 'B4', 'Salle A', 1);

        $this->decoupage->genererDestinataires();
        $resultat = $this->decoupage->genererPerimetre($this->cycle());

        $this->assertSame(1, $resultat['sans_destinataire']);
        $this->assertSame(0, PerimetreDestinataire::whereNotNull('pays')->count());
    }

    public function test_cycle_clos_refuse(): void
    {
        $this->decoupage->genererDestinataires();
        $source = $this->cycle('Source');
        $this->decoupage->genererPerimetre($source);
        $cible = $this->cycle('Cible');
        DB::table('cycles_electoraux')->where('id', $cible->id)->update(['statut' => 'clos']);
        $cible->refresh();
        $prefecture = $this->destinataire('Préfecture de Mbour');

        foreach ([
            fn () => $this->decoupage->genererPerimetre($cible),
            fn () => $this->decoupage->affecter($cible, $prefecture, $this->geo['communes']['Ndiass'], null, null, 'Test'),
            fn () => $this->decoupage->copierPerimetre($source, $cible),
        ] as $action) {
            try {
                $action();
                $this->fail('Un cycle clos doit refuser la modification du découpage.');
            } catch (RegleLogistique $e) {
                $this->assertSame('Cycle clôturé : découpage non modifiable.', $e->getMessage());
            }
        }

        $this->assertSame(0, PerimetreDestinataire::where('cycle_id', $cible->id)->count());
    }

    public function test_copier_perimetre_ne_copie_que_les_localites_absentes(): void
    {
        $this->decoupage->genererDestinataires();
        $source = $this->cycle('Source');
        $this->decoupage->genererPerimetre($source);
        $prefecture = $this->destinataire('Préfecture de Mbour');
        $ndiass = $this->geo['communes']['Ndiass'];
        $this->decoupage->affecter($source, $prefecture, $ndiass, null, null, 'Rattachement');

        $cible = $this->cycle('Cible');
        $this->decoupage->affecter($cible, $this->destinataire('Sous-préfecture de Fissel'), $this->geo['communes']['Popenguine'], null, null, 'Déjà là');

        $this->assertSame(2, $this->decoupage->copierPerimetre($source, $cible));
        $this->assertSame(0, $this->decoupage->copierPerimetre($source, $cible));

        $this->assertSame($prefecture->id, PerimetreDestinataire::where('cycle_id', $cible->id)->where('commune_id', $ndiass)->value('destinataire_id'));
        $this->assertSame(
            $this->destinataire('Sous-préfecture de Fissel')->id,
            PerimetreDestinataire::where('cycle_id', $cible->id)->where('commune_id', $this->geo['communes']['Popenguine'])->value('destinataire_id'),
        );
    }

    public function test_poste_par_defaut_ignore_les_postes_inactifs(): void
    {
        PosteEtranger::create(['pays' => 'France', 'ville' => 'Lyon', 'type' => 'consulat', 'nom' => 'Consulat de Lyon', 'actif' => false]);
        PosteEtranger::create(['pays' => 'France', 'ville' => 'Paris', 'type' => 'ambassade', 'nom' => 'Ambassade de Paris']);

        $this->assertSame('Ambassade de Paris', PosteEtranger::posteParDefaut('France', 'Marseille')->nom);
        $this->assertNull(PosteEtranger::posteParDefaut('Japon', 'Tokyo'));
    }

    public function test_operation_sans_auteur_refusee(): void
    {
        auth()->logout();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Opération sans auteur.');
        $this->decoupage->genererDestinataires();
    }

    public function test_lecture_recensement_ne_modifie_rien(): void
    {
        RecensementFictif::electeurs('national', 'Thiès', 'Mbour', 'Ndiass', 'B1', 'École A', 4);
        RecensementFictif::electeurs('etranger', 'France', null, 'Paris', 'B2', 'Salle B', 2);

        $tables = ['regions', 'departements', 'arrondissements', 'communes', 'fichier_electoral'];
        $compter = fn () => array_map(fn ($t) => DB::connection('recensement')->table($t)->count(), $tables);
        $avant = $compter();

        $this->decoupage->genererDestinataires();
        $this->decoupage->genererPerimetre($this->cycle());

        $this->assertSame($avant, $compter());
    }
}

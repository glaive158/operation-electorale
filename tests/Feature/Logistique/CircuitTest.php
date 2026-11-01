<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\CircuitDepartement;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\PosteEtranger;
use App\Models\User;
use App\Services\Logistique\CircuitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class CircuitTest extends TestCase
{
    use RefreshDatabase;

    private CircuitService $circuits;

    private Destinataire $prefecture;

    private Destinataire $sousPrefecture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->circuits = app(CircuitService::class);
        $this->actingAs(User::factory()->create(['role' => 'logistique_responsable']));

        $this->prefecture = Destinataire::create(['type' => 'prefecture', 'libelle' => 'Préfecture de Thiès', 'departement_id' => 10]);
        $this->sousPrefecture = Destinataire::create([
            'type' => 'sous_prefecture', 'libelle' => 'Sous-préfecture de Sindia',
            'arrondissement_id' => 100, 'prefecture_id' => $this->prefecture->id,
        ]);
    }

    private function cycle(string $circuit = 'via_prefet'): Cycle
    {
        return Cycle::create(['libelle' => 'Cycle A', 'type' => 'scrutin', 'circuit_defaut' => $circuit]);
    }

    public function test_circuit_par_defaut_du_cycle(): void
    {
        $cycle = $this->cycle('via_prefet');

        $this->assertSame('via_prefet', $this->circuits->circuitPour($cycle, $this->prefecture));
        $this->assertSame('direct', $this->circuits->circuitPour($this->cycle('direct'), $this->prefecture));
    }

    public function test_derogation_par_departement(): void
    {
        $cycle = $this->cycle('via_prefet');

        $this->circuits->definir($cycle, 10, 'direct', 'route coupée');

        $this->assertSame('direct', $this->circuits->circuitPour($cycle, $this->prefecture));
        $this->assertSame('direct', $this->circuits->circuitPour($cycle, $this->sousPrefecture));
        $this->assertSame(1, CircuitDepartement::where('cycle_id', $cycle->id)->count());

        $entree = JournalEntree::where('action', 'definition_circuit')->firstOrFail();
        $this->assertSame('route coupée', $entree->motif);
        $this->assertSame('via_prefet', $entree->avant['circuit']);
        $this->assertFalse($entree->avant['derogation']);
        $this->assertSame('direct', $entree->apres['circuit']);
        $this->assertTrue($entree->apres['derogation']);
    }

    public function test_derogation_remplacee_sans_doublon(): void
    {
        $cycle = $this->cycle('via_prefet');
        $this->circuits->definir($cycle, 10, 'direct', 'a');
        $this->circuits->definir($cycle, 10, 'via_prefet', 'b');

        $this->assertSame(1, CircuitDepartement::where('cycle_id', $cycle->id)->count());
        $this->assertSame('via_prefet', CircuitDepartement::firstOrFail()->circuit);
        $this->assertTrue($this->circuits->departements($cycle)[0]['derogation']);
    }

    public function test_retour_au_defaut(): void
    {
        $cycle = $this->cycle('via_prefet');
        $this->circuits->definir($cycle, 10, 'direct', 'route coupée');

        $this->circuits->definir($cycle, 10, null, 'route rouverte');

        $this->assertSame('via_prefet', $this->circuits->circuitPour($cycle, $this->prefecture));
        $this->assertSame(0, CircuitDepartement::count());
        $dernier = JournalEntree::where('action', 'definition_circuit')->orderByDesc('id')->firstOrFail();
        $this->assertSame('direct', $dernier->avant['circuit']);
        $this->assertSame('via_prefet', $dernier->apres['circuit']);
        $this->assertFalse($dernier->apres['derogation']);
    }

    public function test_poste_toujours_etranger(): void
    {
        $poste = PosteEtranger::create(['pays' => 'France', 'ville' => 'Paris', 'type' => 'consulat', 'nom' => 'Consulat de Paris']);
        $destinataire = Destinataire::create(['type' => 'poste', 'libelle' => 'Consulat de Paris', 'poste_etranger_id' => $poste->id]);

        $this->assertSame('etranger', $this->circuits->circuitPour($this->cycle('via_prefet'), $destinataire));
        $this->assertSame('etranger', $this->circuits->circuitPour($this->cycle('direct'), $destinataire));
        $this->assertSame($destinataire->id, $this->circuits->remisA($this->cycle('via_prefet'), $destinataire)->id);
    }

    public function test_via_prefet_remis_au_prefet(): void
    {
        $remis = $this->circuits->remisA($this->cycle('via_prefet'), $this->sousPrefecture);

        $this->assertSame($this->prefecture->id, $remis->id);
    }

    public function test_direct_remis_au_sous_prefet(): void
    {
        $remis = $this->circuits->remisA($this->cycle('direct'), $this->sousPrefecture);

        $this->assertSame($this->sousPrefecture->id, $remis->id);
    }

    public function test_prefecture_remise_a_elle_meme(): void
    {
        $this->assertSame($this->prefecture->id, $this->circuits->remisA($this->cycle('via_prefet'), $this->prefecture)->id);
        $this->assertSame($this->prefecture->id, $this->circuits->remisA($this->cycle('direct'), $this->prefecture)->id);
    }

    public function test_sous_prefecture_sans_prefecture_refusee_en_via_prefet(): void
    {
        $orpheline = Destinataire::create(['type' => 'sous_prefecture', 'libelle' => 'Sous-préfecture orpheline', 'arrondissement_id' => 200]);

        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Sous-préfecture sans préfecture parente : circuit via le préfet impossible.');
        $this->circuits->remisA($this->cycle('via_prefet'), $orpheline);
    }

    public function test_sous_prefecture_sans_prefecture_acceptee_en_direct(): void
    {
        $orpheline = Destinataire::create(['type' => 'sous_prefecture', 'libelle' => 'Sous-préfecture orpheline', 'arrondissement_id' => 200]);

        $this->assertSame($orpheline->id, $this->circuits->remisA($this->cycle('direct'), $orpheline)->id);
    }

    public function test_derogation_du_departement_pilote_la_remise(): void
    {
        $cycle = $this->cycle('via_prefet');
        $this->circuits->definir($cycle, 10, 'direct', 'route');

        $sousPrefecture = Destinataire::create([
            'type' => 'sous_prefecture', 'libelle' => 'Sous-préfecture de Fissel', 'arrondissement_id' => 101,
            'prefecture_id' => $this->prefecture->id,
        ]);
        $this->assertSame('direct', $this->circuits->circuitPour($cycle, $sousPrefecture));
        $this->assertSame($sousPrefecture->id, $this->circuits->remisA($cycle, $sousPrefecture)->id);
    }

    public function test_gestionnaire_ne_modifie_pas_le_circuit(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'logistique_gestionnaire']));

        $this->expectException(RegleLogistique::class);
        $this->circuits->definir($this->cycle(), 10, 'direct', 'motif');
    }

    public function test_cycle_clos_refuse(): void
    {
        $cycle = $this->cycle();
        Cycle::whereKey($cycle->id)->update(['statut' => 'clos']);

        $this->expectException(RegleLogistique::class);
        $this->circuits->definir($cycle, 10, 'direct', 'motif');
    }

    public function test_motif_obligatoire_et_borne(): void
    {
        $cycle = $this->cycle();
        foreach (['', '   ', str_repeat('x', 501)] as $motif) {
            try {
                $this->circuits->definir($cycle, 10, 'direct', $motif);
                $this->fail('Motif invalide accepté.');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, CircuitDepartement::count());
            }
        }
        $this->circuits->definir($cycle, 10, 'direct', str_repeat('x', 500));
        $this->assertSame(1, CircuitDepartement::count());
    }

    public function test_departement_inconnu_refuse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Département inconnu.');
        $this->circuits->definir($this->cycle(), 999, 'direct', 'motif');
    }

    public function test_circuit_inconnu_refuse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->circuits->definir($this->cycle(), 10, 'camion', 'motif');
    }

    public function test_sans_auteur_refuse(): void
    {
        $cycle = $this->cycle();
        auth()->logout();

        $this->expectException(LogicException::class);
        $this->circuits->definir($cycle, 10, 'direct', 'motif');
    }

    public function test_departements_une_ligne_par_prefecture_triee(): void
    {
        Destinataire::create(['type' => 'prefecture', 'libelle' => 'Préfecture de Dakar', 'departement_id' => 20]);
        $cycle = $this->cycle('via_prefet');
        $this->circuits->definir($cycle, 10, 'direct', 'route');

        $lignes = $this->circuits->departements($cycle);

        $this->assertSame([
            ['departement_id' => 20, 'nom' => 'Préfecture de Dakar', 'circuit' => 'via_prefet', 'derogation' => false],
            ['departement_id' => 10, 'nom' => 'Préfecture de Thiès', 'circuit' => 'direct', 'derogation' => true],
        ], $lignes->all());
    }
}

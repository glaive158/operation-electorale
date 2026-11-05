<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\CircuitSousPrefecture;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\JournalEntree;
use App\Models\User;
use App\Services\Logistique\CircuitService;
use App\Services\Logistique\DechargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class CircuitSousPrefectureTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    private CircuitService $circuits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerDecorFictif();
        $this->circuits = app(CircuitService::class);
        $this->circuitDuCycle('via_prefet');
    }

    private function circuitDuCycle(string $circuit): void
    {
        $this->cycle->forceFill(['circuit_defaut' => $circuit])->save();
    }

    private function orpheline(): Destinataire
    {
        return Destinataire::create(['type' => 'sous_prefecture', 'libelle' => 'Sous-préfecture orpheline', 'arrondissement_id' => 200]);
    }

    public function test_reglage_de_la_sous_prefecture_prime_sur_departement_et_cycle(): void
    {
        $this->circuits->definir($this->cycle, 10, 'direct', 'route coupée');
        $this->circuits->definirSousPrefecture($this->cycle, $this->sousPrefecture1, 'via_prefet', 'pont praticable');

        $this->assertSame('via_prefet', $this->circuits->circuitPour($this->cycle, $this->sousPrefecture1));
        $this->assertSame('direct', $this->circuits->circuitPour($this->cycle, $this->sousPrefecture2));
        $this->assertSame('direct', $this->circuits->circuitPour($this->cycle, $this->prefecture));

        $this->circuits->definir($this->cycle, 10, null, 'route rouverte');
        $this->circuitDuCycle('direct');
        $this->assertSame('via_prefet', $this->circuits->circuitPour($this->cycle, $this->sousPrefecture1));
        $this->assertSame('direct', $this->circuits->circuitPour($this->cycle, $this->sousPrefecture2));
    }

    public function test_reglage_remplace_sans_doublon(): void
    {
        $this->circuits->definirSousPrefecture($this->cycle, $this->sousPrefecture1, 'direct', 'a');
        $this->circuits->definirSousPrefecture($this->cycle, $this->sousPrefecture1, 'via_prefet', 'b');

        $this->assertSame(1, CircuitSousPrefecture::count());
        $this->assertSame('via_prefet', CircuitSousPrefecture::firstOrFail()->circuit);
        $this->assertSame($this->responsable->id, CircuitSousPrefecture::firstOrFail()->user_id);
    }

    public function test_suppression_revient_au_departement(): void
    {
        $this->circuits->definir($this->cycle, 10, 'direct', 'route coupée');
        $this->circuits->definirSousPrefecture($this->cycle, $this->sousPrefecture1, 'via_prefet', 'pont');

        $this->circuits->definirSousPrefecture($this->cycle, $this->sousPrefecture1, null, 'comme le département');

        $this->assertSame(0, CircuitSousPrefecture::count());
        $this->assertSame('direct', $this->circuits->circuitPour($this->cycle, $this->sousPrefecture1));
        $dernier = JournalEntree::where('action', 'definition_circuit_sous_prefecture')->orderByDesc('id')->firstOrFail();
        $this->assertSame(['circuit' => 'via_prefet', 'source' => 'sous_prefecture'], array_intersect_key($dernier->avant, ['circuit' => 1, 'source' => 1]));
        $this->assertSame(['circuit' => 'direct', 'source' => 'departement'], array_intersect_key($dernier->apres, ['circuit' => 1, 'source' => 1]));
    }

    public function test_reglage_cycle_separe(): void
    {
        $autre = Cycle::create(['libelle' => 'Cycle B', 'type' => 'scrutin', 'circuit_defaut' => 'via_prefet']);
        $this->circuits->definirSousPrefecture($this->cycle, $this->sousPrefecture1, 'direct', 'route');

        $this->assertSame('direct', $this->circuits->circuitPour($this->cycle, $this->sousPrefecture1));
        $this->assertSame('via_prefet', $this->circuits->circuitPour($autre, $this->sousPrefecture1));
    }

    public function test_sous_prefecture_directe_dans_un_departement_via_prefet(): void
    {
        $this->circuits->definir($this->cycle, 10, 'via_prefet', 'département servi par le préfet');
        $this->circuits->definirSousPrefecture($this->cycle, $this->sousPrefecture1, 'direct', 'accès direct');

        $this->assertSame($this->sousPrefecture1->id, $this->circuits->remisA($this->cycle, $this->sousPrefecture1)->id);
        $this->assertSame($this->prefecture->id, $this->circuits->remisA($this->cycle, $this->sousPrefecture2)->id);
    }

    public function test_sous_prefecture_via_prefet_dans_un_departement_direct(): void
    {
        $this->circuits->definir($this->cycle, 10, 'direct', 'département servi directement');
        $this->circuits->definirSousPrefecture($this->cycle, $this->sousPrefecture1, 'via_prefet', 'piste impraticable');

        $this->assertSame($this->prefecture->id, $this->circuits->remisA($this->cycle, $this->sousPrefecture1)->id);
        $this->assertSame($this->sousPrefecture2->id, $this->circuits->remisA($this->cycle, $this->sousPrefecture2)->id);
    }

    public function test_orpheline_reglee_en_direct_dans_un_cycle_via_prefet(): void
    {
        $orpheline = $this->orpheline();

        $this->circuits->definirSousPrefecture($this->cycle, $orpheline, 'direct', 'pas de préfecture');

        $this->assertSame('direct', $this->circuits->circuitPour($this->cycle, $orpheline));
        $this->assertSame($orpheline->id, $this->circuits->remisA($this->cycle, $orpheline)->id);
    }

    public function test_decharge_validee_garde_son_circuit_la_suivante_prend_le_nouveau(): void
    {
        $this->approuverPlanFictif([[$this->sousPrefecture1, $this->urnes, 10], [$this->sousPrefecture1, $this->enveloppes, 100]]);
        $this->stockerFictif($this->urnes, 500);
        $this->stockerFictif($this->enveloppes, 500);
        $decharges = app(DechargeService::class);

        $avant = $decharges->preparer($this->cycle, $this->sousPrefecture1, 'materiel_lourd');
        $decharges->valider($avant);
        $brouillon = $decharges->preparer($this->cycle, $this->sousPrefecture1, 'imprime');

        $this->circuits->definirSousPrefecture($this->cycle, $this->sousPrefecture1, 'direct', 'accès direct');
        $decharges->valider($brouillon);

        $avant->refresh();
        $this->assertSame('via_prefet', $avant->circuit);
        $this->assertSame($this->prefecture->id, (int) $avant->remis_a_id);
        $this->assertSame($this->prefecture->libelle, $avant->donnees_gelees['remis_a']['libelle']);

        $brouillon->refresh();
        $this->assertSame('direct', $brouillon->circuit);
        $this->assertSame($this->sousPrefecture1->id, (int) $brouillon->remis_a_id);
        $this->assertSame($this->sousPrefecture1->libelle, $brouillon->donnees_gelees['remis_a']['libelle']);
    }

    public function test_journal_de_la_definition(): void
    {
        $this->circuits->definirSousPrefecture($this->cycle, $this->sousPrefecture1, 'direct', 'route coupée');

        $entree = JournalEntree::where('action', 'definition_circuit_sous_prefecture')->firstOrFail();
        $this->assertSame('route coupée', $entree->motif);
        $this->assertSame('cycle', $entree->objet_type);
        $this->assertSame($this->cycle->id, (int) $entree->objet_id);
        $this->assertSame($this->responsable->id, (int) $entree->user_id);
        $this->assertSame([
            'circuit' => 'via_prefet', 'source' => 'cycle',
            'cycle_id' => $this->cycle->id, 'destinataire_id' => $this->sousPrefecture1->id,
        ], $entree->avant);
        $this->assertSame([
            'circuit' => 'direct', 'source' => 'sous_prefecture',
            'cycle_id' => $this->cycle->id, 'destinataire_id' => $this->sousPrefecture1->id,
        ], $entree->apres);
    }

    public function test_destinataire_autre_qu_une_sous_prefecture_refuse(): void
    {
        foreach ([$this->prefecture, $this->poste] as $destinataire) {
            try {
                $this->circuits->definirSousPrefecture($this->cycle, $destinataire, 'direct', 'motif');
                $this->fail('Destinataire accepté : '.$destinataire->type);
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Ce destinataire n\'est pas une sous-préfecture.', $e->getMessage());
            }
        }
        $this->assertSame(0, CircuitSousPrefecture::count());
        $this->assertSame(0, JournalEntree::where('action', 'definition_circuit_sous_prefecture')->count());
    }

    public function test_via_prefet_sans_prefecture_parente_refuse(): void
    {
        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Sous-préfecture sans préfecture parente : circuit via le préfet impossible.');
        $this->circuits->definirSousPrefecture($this->cycle, $this->orpheline(), 'via_prefet', 'motif');
    }

    public function test_cycle_clos_refuse(): void
    {
        Cycle::whereKey($this->cycle->id)->update(['statut' => 'clos']);

        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Cycle clôturé : circuit de livraison non modifiable.');
        $this->circuits->definirSousPrefecture($this->cycle, $this->sousPrefecture1, 'direct', 'motif');
    }

    public function test_gestionnaire_ne_modifie_pas_le_circuit(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'logistique_gestionnaire']));

        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Seul un responsable logistique peut modifier le circuit de livraison.');
        $this->circuits->definirSousPrefecture($this->cycle, $this->sousPrefecture1, 'direct', 'motif');
    }

    public function test_sans_auteur_refuse(): void
    {
        auth()->logout();

        $this->expectException(LogicException::class);
        $this->circuits->definirSousPrefecture($this->cycle, $this->sousPrefecture1, 'direct', 'motif');
    }

    public function test_motif_obligatoire_et_borne(): void
    {
        foreach (['', '   ', str_repeat('x', 501)] as $motif) {
            try {
                $this->circuits->definirSousPrefecture($this->cycle, $this->sousPrefecture1, 'direct', $motif);
                $this->fail('Motif invalide accepté.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Le motif est obligatoire (500 caractères au plus).', $e->getMessage());
            }
        }
        $this->assertSame(0, CircuitSousPrefecture::count());

        $this->circuits->definirSousPrefecture($this->cycle, $this->sousPrefecture1, 'direct', str_repeat('x', 500));
        $this->assertSame(1, CircuitSousPrefecture::count());
    }

    public function test_circuit_inconnu_refuse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->circuits->definirSousPrefecture($this->cycle, $this->sousPrefecture1, 'camion', 'motif');
    }

    public function test_sous_prefectures_avec_circuit_et_origine(): void
    {
        $orpheline = $this->orpheline();
        $this->circuits->definir($this->cycle, 10, 'direct', 'route');
        $this->circuits->definirSousPrefecture($this->cycle, $this->sousPrefecture2, 'via_prefet', 'piste');

        $lignes = $this->circuits->sousPrefectures($this->cycle);

        $this->assertSame([
            ['id' => $this->sousPrefecture1->id, 'nom' => 'Sous-préfecture de Sindia', 'departement_id' => 10, 'circuit' => 'direct', 'source' => 'departement', 'reglage' => null],
            ['id' => $this->sousPrefecture2->id, 'nom' => 'Sous-préfecture de Thiénaba', 'departement_id' => 10, 'circuit' => 'via_prefet', 'source' => 'sous_prefecture', 'reglage' => 'via_prefet'],
            ['id' => $orpheline->id, 'nom' => 'Sous-préfecture orpheline', 'departement_id' => null, 'circuit' => 'via_prefet', 'source' => 'cycle', 'reglage' => null],
        ], $lignes->all());
    }
}

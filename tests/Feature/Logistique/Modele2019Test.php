<?php

namespace Tests\Feature\Logistique;

use App\Models\Logistique\CleRepartition;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Materiel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class Modele2019Test extends TestCase
{
    use RefreshDatabase;

    private Cycle $cycle;

    private User $responsable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->responsable = User::factory()->create(['role' => 'logistique_responsable']);
        $this->cycle = Cycle::create(['libelle' => 'Présidentielle', 'type' => 'scrutin']);
    }

    private function lancer(?int $auteur = null, ?Cycle $cycle = null): PendingCommand
    {
        return $this->artisan('logistique:modele-2019', [
            'cycle'    => ($cycle ?? $this->cycle)->id,
            '--auteur' => $auteur ?? $this->responsable->id,
        ]);
    }

    public function test_modele_cree_articles_et_cles(): void
    {
        $this->lancer()->assertExitCode(0);

        $this->assertSame(39, CleRepartition::where('cycle_id', $this->cycle->id)->count());
        $this->assertSame(39, Materiel::count());

        $urnes = Materiel::where('libelle', 'Urnes')->firstOrFail();
        $this->assertTrue($urnes->reutilisable);
        $this->assertTrue($urnes->indivisible);
        $this->assertSame('materiel_lourd', $urnes->categorie);

        $cle = CleRepartition::where('cycle_id', $this->cycle->id)->where('materiel_id', $urnes->id)->firstOrFail();
        $this->assertSame('bureau_vote', $cle->base);
        $this->assertSame('1.0000', $cle->ratio);
        $this->assertSame('0.00', $cle->majoration_distribuee);
        $this->assertSame('01 par B.V', $cle->cle_affichee);

        $pv = Materiel::where('libelle', 'Procès-verbal des opérations électorales')->firstOrFail();
        $clePv = CleRepartition::where('materiel_id', $pv->id)->firstOrFail();
        $this->assertSame('17.0000', $clePv->ratio);
        $this->assertSame('17 par B.V', $clePv->cle_affichee);
        $this->assertNull($clePv->majoration_distribuee);
        $this->assertFalse($pv->reutilisable);

        $carte = Materiel::where('libelle', 'Carte de mandataire')->firstOrFail();
        $cleCarte = CleRepartition::where('materiel_id', $carte->id)->firstOrFail();
        $this->assertSame('lieu_vote', $cleCarte->base);
        $this->assertTrue($cleCarte->par_entite);
        $this->assertSame('01 par candidat et par lieu de vote', $cleCarte->cle_affichee);

        $this->assertSame('Cachets', Materiel::where('libelle', 'Cachet « Copie »')->value('regroupement'));
        $this->assertSame(1, CleRepartition::where('par_entite', false)->where('base', 'electeur')->count());
    }

    public function test_modele_idempotent_et_ne_touche_pas_l_existant(): void
    {
        $this->lancer()->assertExitCode(0);

        $pv = Materiel::where('libelle', 'Procès-verbal des opérations électorales')->firstOrFail();
        CleRepartition::where('materiel_id', $pv->id)->update(['ratio' => '20']);

        $this->lancer()->assertExitCode(0);

        $this->assertSame('20.0000', CleRepartition::where('materiel_id', $pv->id)->firstOrFail()->ratio);
        $this->assertSame(39, CleRepartition::where('cycle_id', $this->cycle->id)->count());
        $this->assertSame(39, Materiel::count());
        $this->assertSame(1, Materiel::where('libelle', 'Urnes')->count());
    }

    public function test_modele_refuse_un_cycle_de_revision(): void
    {
        $revision = Cycle::create(['libelle' => 'Révision', 'type' => 'revision']);

        $this->lancer(null, $revision)->assertFailed();

        $this->assertSame(0, CleRepartition::count());
        $this->assertSame(0, Materiel::count());
    }

    public function test_modele_refuse_un_cycle_clos(): void
    {
        Cycle::whereKey($this->cycle->id)->update(['statut' => 'clos']);

        $this->lancer()->assertFailed();

        $this->assertSame(0, CleRepartition::count());
    }

    public function test_modele_exige_un_auteur_responsable(): void
    {
        $gestionnaire = User::factory()->create(['role' => 'logistique_gestionnaire']);

        $this->lancer($gestionnaire->id)->assertFailed();
        $this->lancer(999999)->assertFailed();
        $this->artisan('logistique:modele-2019', ['cycle' => $this->cycle->id])->assertFailed();

        $this->assertSame(0, CleRepartition::count());
        $this->assertSame(0, Materiel::count());
    }
}

<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\PlanDotation;
use App\Models\User;
use App\Services\Logistique\DechargeService;
use App\Services\Logistique\PerimetreTerrain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\PlanApprouveFictif;
use Tests\Support\RecensementFictif;
use Tests\TestCase;

class PerimetreTerrainTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    private PlanDotation $plan;

    private PerimetreTerrain $perimetre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerDecorFictif();
        $this->plan = $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 100], [$this->sousPrefecture1, $this->urnes, 30],
        ]);
        $this->stockerFictif($this->urnes, 1000);
        $this->perimetre = app(PerimetreTerrain::class);
    }

    private function utilisateur(string $role, array $attributs = []): User
    {
        return User::factory()->create(['role' => $role] + $attributs)->fresh();
    }

    /** Écrit directement une décharge (hors circuit de validation) pour tester la seule visibilité. */
    private function deposer(Destinataire $destinataire, string $statut = 'validee', ?Destinataire $remisA = null, ?User $agent = null, string $categorie = 'materiel_lourd'): Decharge
    {
        $decharge = Decharge::create([
            'cycle_id' => $this->cycle->id, 'destinataire_id' => $destinataire->id, 'categorie' => $categorie,
            'nature' => 'initiale', 'plan_dotation_id' => $this->plan->id, 'prepare_par' => $this->responsable->id,
        ]);
        $decharge->forceFill([
            'statut_document' => $statut, 'remis_a_id' => $remisA?->id, 'agent_id' => $agent?->id,
        ])->save();

        return $decharge;
    }

    public function test_prefet_voit_sa_prefecture_et_ses_sous_prefectures(): void
    {
        $prefet = $this->utilisateur('prefet');
        $this->prefecture->update(['autorite_user_id' => $prefet->id]);

        $autre = Destinataire::create(['type' => 'prefecture', 'libelle' => 'Préfecture de Kaolack', 'departement_id' => 11]);
        $sousAutre = Destinataire::create([
            'type' => 'sous_prefecture', 'libelle' => 'Sous-préfecture de Gandiaye',
            'arrondissement_id' => 110, 'prefecture_id' => $autre->id,
        ]);

        $this->assertEqualsCanonicalizing(
            [$this->prefecture->id, $this->sousPrefecture1->id, $this->sousPrefecture2->id],
            $this->perimetre->destinataireIds($prefet)->all(),
        );

        $propre = $this->deposer($this->prefecture);
        $sienne = $this->deposer($this->sousPrefecture1);
        // Décharge d'un destinataire hors périmètre dont la remise se fait entre ses mains.
        $remiseAuPrefet = $this->deposer($this->poste, 'validee', $this->prefecture);
        $this->deposer($autre);
        $this->deposer($sousAutre);

        $this->assertEqualsCanonicalizing(
            [$propre->id, $sienne->id, $remiseAuPrefet->id],
            $this->perimetre->decharges($prefet)->pluck('id')->all(),
        );
        $this->assertTrue($this->perimetre->peutVoir($prefet, $remiseAuPrefet));
        $this->assertTrue($this->perimetre->estAutoriteDe($prefet, $this->prefecture));
        $this->assertFalse($this->perimetre->estAutoriteDe($prefet, $this->sousPrefecture1));
        $this->assertFalse($this->perimetre->estAutoriteDe($prefet, $autre));
    }

    public function test_un_poste_rattache_a_une_prefecture_nentre_pas_dans_le_perimetre_du_prefet(): void
    {
        $prefet = $this->utilisateur('prefet');
        $this->prefecture->update(['autorite_user_id' => $prefet->id]);
        $poste = Destinataire::create([
            'type' => 'poste', 'libelle' => 'Poste rattaché par erreur', 'prefecture_id' => $this->prefecture->id,
        ]);

        $this->assertNotContains($poste->id, $this->perimetre->destinataireIds($prefet)->all());
    }

    public function test_sous_prefet_ne_voit_que_les_siens(): void
    {
        $sousPrefet = $this->utilisateur('sous_prefet');
        $this->sousPrefecture1->update(['autorite_user_id' => $sousPrefet->id]);

        $this->assertSame([$this->sousPrefecture1->id], $this->perimetre->destinataireIds($sousPrefet)->all());

        $sienne = $this->deposer($this->sousPrefecture1);
        $this->deposer($this->sousPrefecture2);
        $this->deposer($this->prefecture);
        // Remise à sa sous-préfecture d'une décharge d'un autre destinataire : non visible (seul le préfet voit remis_a).
        $remiseDuPoste = $this->deposer($this->poste, 'validee', $this->sousPrefecture1);

        $this->assertSame([$sienne->id], $this->perimetre->decharges($sousPrefet)->pluck('id')->all());
        $this->assertFalse($this->perimetre->peutVoir($sousPrefet, $this->deposer($this->prefecture)));

        $chef = $this->utilisateur('chef_poste');
        $this->poste->update(['autorite_user_id' => $chef->id]);
        $duPoste = $this->deposer($this->poste);
        $this->assertSame([$this->poste->id], $this->perimetre->destinataireIds($chef)->all());
        $this->assertEqualsCanonicalizing(
            [$remiseDuPoste->id, $duPoste->id],
            $this->perimetre->decharges($chef)->pluck('id')->all(),
        );
    }

    public function test_agent_voit_ses_affectations_seulement(): void
    {
        $agent = $this->utilisateur('agent_transport');
        $autreAgent = $this->utilisateur('agent_transport');

        $mienne = $this->deposer($this->prefecture, 'validee', null, $agent);
        $annulee = $this->deposer($this->sousPrefecture1, 'annulee', null, $agent);
        $this->deposer($this->sousPrefecture2, 'validee', null, $autreAgent);
        $this->deposer($this->poste, 'validee');

        $this->assertEqualsCanonicalizing([$mienne->id, $annulee->id], $this->perimetre->decharges($agent)->pluck('id')->all());
        $this->assertTrue($this->perimetre->destinataireIds($agent)->isEmpty());
    }

    public function test_gouverneur_voit_sa_region(): void
    {
        RecensementFictif::creerSchema();
        $geo = RecensementFictif::geo([
            'Thiès' => ['Thiès' => ['Sindia' => ['Ndiass']]],
            'Kaolack' => ['Kaolack' => ['Gandiaye' => ['Gandiaye']]],
        ]);
        $this->prefecture->update(['departement_id' => $geo['departements']['Thiès']]);
        $autre = Destinataire::create([
            'type' => 'prefecture', 'libelle' => 'Préfecture de Kaolack', 'departement_id' => $geo['departements']['Kaolack'],
        ]);
        $sousAutre = Destinataire::create([
            'type' => 'sous_prefecture', 'libelle' => 'Sous-préfecture de Gandiaye',
            'arrondissement_id' => 110, 'prefecture_id' => $autre->id,
        ]);

        $gouverneur = $this->utilisateur('gouverneur', ['region_id' => $geo['regions']['Thiès'], 'region_nom' => 'Thiès']);

        $this->assertEqualsCanonicalizing(
            [$this->prefecture->id, $this->sousPrefecture1->id, $this->sousPrefecture2->id],
            $this->perimetre->destinataireIds($gouverneur)->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$autre->id, $sousAutre->id],
            $this->perimetre->destinataireIds($this->utilisateur('gouverneur', ['region_id' => $geo['regions']['Kaolack']]))->all(),
        );

        $a = $this->deposer($this->prefecture);
        $b = $this->deposer($this->sousPrefecture2);
        $this->deposer($autre);
        $this->deposer($sousAutre);
        $this->deposer($this->poste);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->perimetre->decharges($gouverneur)->pluck('id')->all());

        $sansRegion = $this->utilisateur('gouverneur');
        $this->assertTrue($this->perimetre->destinataireIds($sansRegion)->isEmpty());
        $this->assertSame(0, $this->perimetre->decharges($sansRegion)->count());
    }

    public function test_brouillons_jamais_visibles_sur_le_terrain(): void
    {
        $prefet = $this->utilisateur('prefet');
        $this->prefecture->update(['autorite_user_id' => $prefet->id]);
        $agent = $this->utilisateur('agent_transport');

        $brouillon = $this->deposer($this->prefecture, 'brouillon', null, $agent);
        $annulee = $this->deposer($this->prefecture, 'annulee', null, $agent, 'imprime');

        $this->assertSame([$annulee->id], $this->perimetre->decharges($prefet)->pluck('id')->all());
        $this->assertSame([$annulee->id], $this->perimetre->decharges($agent)->pluck('id')->all());
        $this->assertFalse($this->perimetre->peutVoir($prefet, $brouillon));
        $this->assertFalse($this->perimetre->peutVoir($agent, $brouillon));
    }

    public function test_compte_inactif_sans_perimetre(): void
    {
        $prefet = $this->utilisateur('prefet', ['actif' => false]);
        $this->prefecture->update(['autorite_user_id' => $prefet->id]);
        $this->deposer($this->prefecture);

        $this->assertFalse(Gate::forUser($prefet)->allows('logistique-terrain'));
        $this->assertTrue($this->perimetre->destinataireIds($prefet)->isEmpty());
        $this->assertSame(0, $this->perimetre->decharges($prefet)->count());
        $this->assertFalse($this->perimetre->estAutoriteDe($prefet, $this->prefecture));

        foreach (['prefet', 'sous_prefet', 'chef_poste', 'agent_transport'] as $role) {
            $this->assertTrue(Gate::forUser($this->utilisateur($role))->allows('logistique-terrain'), $role);
        }
        foreach (['admin', 'gouverneur', 'commission', 'logistique_gestionnaire', 'logistique_responsable'] as $role) {
            $this->assertFalse(Gate::forUser($this->utilisateur($role))->allows('logistique-terrain'), $role);
        }
        $this->assertTrue($this->utilisateur('chef_poste')->isChefPoste());
        $this->assertTrue($this->utilisateur('agent_transport')->isAgentTransport());
        $this->assertFalse($this->utilisateur('prefet')->isAgentTransport());
    }

    private function dechargeValidee(): Decharge
    {
        $decharge = app(DechargeService::class)->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        app(DechargeService::class)->valider($decharge);

        return $decharge->refresh();
    }

    public function test_affecter_agent_et_retirer(): void
    {
        $decharge = $this->dechargeValidee();
        $agent = $this->utilisateur('agent_transport');
        $autre = $this->utilisateur('agent_transport');

        app(DechargeService::class)->affecterAgent($decharge, $agent, 'Tournée de Thiès');
        $this->assertSame($agent->id, (int) $decharge->refresh()->agent_id);

        app(DechargeService::class)->affecterAgent($decharge, $autre, 'Remplacement de l\'agent');
        $this->assertSame($autre->id, (int) $decharge->refresh()->agent_id);

        app(DechargeService::class)->affecterAgent($decharge, null, 'Tournée annulée');
        $this->assertNull($decharge->refresh()->agent_id);

        $entrees = JournalEntree::where('action', 'affectation_agent')->where('objet_id', $decharge->id)->orderBy('id')->get();
        $this->assertCount(3, $entrees);
        $this->assertSame(['agent_id' => null], $entrees[0]->avant);
        $this->assertSame(['agent_id' => $agent->id], $entrees[0]->apres);
        $this->assertSame('Tournée de Thiès', $entrees[0]->motif);
        $this->assertSame(['agent_id' => $agent->id], $entrees[1]->avant);
        $this->assertSame(['agent_id' => null], $entrees[2]->apres);
        $this->assertSame($this->responsable->id, (int) $entrees[0]->user_id);
    }

    public function test_affectation_refusee_apres_chargement(): void
    {
        $decharge = $this->dechargeValidee();
        $agent = $this->utilisateur('agent_transport');
        $decharge->forceFill(['statut_acheminement' => 'chargee'])->save();

        try {
            app(DechargeService::class)->affecterAgent($decharge, $agent, 'Trop tard');
            $this->fail('Affectation après chargement acceptée.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Agent modifiable seulement sur une décharge validée non partie.', $e->getMessage());
        }
        $this->assertNull($decharge->refresh()->agent_id);

        // Un brouillon ou une annulée ne reçoivent pas d'agent non plus.
        $brouillon = app(DechargeService::class)->preparer($this->cycle, $this->sousPrefecture1, 'materiel_lourd');
        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Agent modifiable seulement sur une décharge validée non partie.');
        app(DechargeService::class)->affecterAgent($brouillon, $agent, 'Brouillon');
    }

    public function test_affectation_refusee_a_un_non_agent(): void
    {
        $decharge = $this->dechargeValidee();
        $inactif = $this->utilisateur('agent_transport', ['actif' => false]);

        foreach ([$this->utilisateur('prefet'), $this->utilisateur('logistique_gestionnaire'), $inactif] as $candidat) {
            try {
                app(DechargeService::class)->affecterAgent($decharge, $candidat, 'Mauvais profil');
                $this->fail('Affectation à un non-agent acceptée.');
            } catch (RegleLogistique $e) {
                $this->assertSame("Cet utilisateur n'est pas un agent de transport actif.", $e->getMessage());
            }
        }
        $this->assertNull($decharge->refresh()->agent_id);
        $this->assertSame(0, JournalEntree::where('action', 'affectation_agent')->count());
    }

    public function test_affectation_reservee_au_personnel_logistique_avec_motif(): void
    {
        $decharge = $this->dechargeValidee();
        $agent = $this->utilisateur('agent_transport');

        try {
            app(DechargeService::class)->affecterAgent($decharge, $agent, '   ');
            $this->fail('Motif vide accepté.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Le motif est obligatoire (500 caractères au plus).', $e->getMessage());
        }

        $this->actingAs($this->utilisateur('prefet'));
        $this->expectException(RegleLogistique::class);
        app(DechargeService::class)->affecterAgent($decharge, $agent, 'Droit insuffisant');
    }
}

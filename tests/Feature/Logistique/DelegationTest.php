<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Delegation;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Remise;
use App\Models\User;
use App\Services\Logistique\AffectationsTerrain;
use App\Services\Logistique\DeclarationService;
use App\Services\Logistique\DelegationService;
use App\Services\Logistique\PerimetreTerrain;
use App\Services\Logistique\RemiseService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class DelegationTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerDecorFictif();
        $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 300],
            [$this->sousPrefecture1, $this->urnes, 100],
        ]);
        $this->stockerFictif($this->urnes, 1000, 400);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function adjoint(string $role = 'sous_prefet', array $attributs = []): User
    {
        return User::factory()->create(['role' => $role] + $attributs);
    }

    /** Délègue en tant que $auteur puis rétablit le responsable comme utilisateur connecté. */
    private function deleguer(User $auteur, Destinataire $destinataire, User $delegue, ?CarbonInterface $du = null, ?CarbonInterface $au = null, string $motif = 'Mission à Dakar'): Delegation
    {
        $this->actingAs($auteur);
        try {
            return app(DelegationService::class)->deleguer($destinataire, $delegue, $du ?? now(), $au, $motif);
        } finally {
            $this->actingAs($this->responsable);
        }
    }

    private function revoquer(User $auteur, Delegation $delegation, string $motif = 'Retour de mission'): void
    {
        $this->actingAs($auteur);
        try {
            app(DelegationService::class)->revoquer($delegation, $motif);
        } finally {
            $this->actingAs($this->responsable);
        }
    }

    private function declarer(User $auteur, Remise $remise, ?string $cle = null): Declaration
    {
        $lignes = [];
        foreach ($remise->lignes as $ligne) {
            $lignes[$ligne->materiel_id] = ['recu' => $ligne->quantite, 'defectueux' => 0, 'observation' => null];
        }

        $this->actingAs($auteur);
        try {
            return app(DeclarationService::class)->declarer(
                $remise, 'compte', ['nom' => $auteur->nom_complet ?: 'Signataire', 'qualite' => 'Délégué'], $lignes, $cle ?? (string) Str::uuid(),
            );
        } finally {
            $this->actingAs($this->responsable);
        }
    }

    private function livraison(Decharge $decharge): Remise
    {
        return Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->with('lignes')->firstOrFail();
    }

    public function test_autorite_delegue_a_son_adjoint(): void
    {
        $prefet = $this->autoriteFictive($this->prefecture);
        $adjoint = $this->adjoint();
        $perimetre = app(PerimetreTerrain::class);
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 20]);

        $this->assertSame([], $perimetre->destinataireIds($adjoint)->all());
        $this->assertFalse($perimetre->peutVoir($adjoint, $decharge));

        $du = now()->startOfSecond();
        $delegation = $this->deleguer($prefet, $this->prefecture, $adjoint, $du, $du->copy()->addDays(10), 'Mission à Dakar');

        $this->assertInstanceOf(Delegation::class, $delegation);
        $this->assertSame($this->prefecture->id, $delegation->destinataire_id);
        $this->assertSame($prefet->id, $delegation->autorite_user_id);
        $this->assertSame($adjoint->id, $delegation->delegue_user_id);
        $this->assertSame($prefet->id, $delegation->cree_par);
        $this->assertTrue($delegation->du->equalTo($du));
        $this->assertTrue($delegation->au->equalTo($du->copy()->addDays(10)));
        $this->assertNull($delegation->revoquee_le);
        $this->assertSame('Mission à Dakar', $delegation->motif);

        // L'adjoint voit désormais la préfecture et sa décharge, et en est l'autorité pour signer.
        $this->assertSame([$this->prefecture->id], $perimetre->destinataireIds($adjoint)->all());
        $this->assertTrue($perimetre->peutVoir($adjoint, $decharge));
        $this->assertTrue($perimetre->estAutoriteDe($adjoint, $this->prefecture));
        $this->assertFalse($perimetre->estAutoriteDe($adjoint, $this->sousPrefecture1));
        // Il n'est pas le titulaire pour autant.
        $this->assertFalse($perimetre->estTitulaireDe($adjoint, $this->prefecture));
        $this->assertTrue($perimetre->estTitulaireDe($prefet, $this->prefecture));

        $entree = JournalEntree::where('action', 'delegation_creee')->sole();
        $this->assertSame('delegation', $entree->objet_type);
        $this->assertSame($delegation->id, $entree->objet_id);
        $this->assertSame($adjoint->id, $entree->apres['delegue_user_id']);
        $this->assertSame('Mission à Dakar', $entree->motif);
    }

    public function test_delegation_a_venir_ou_terminee_ne_donne_rien(): void
    {
        $prefet = $this->autoriteFictive($this->prefecture);
        $adjoint = $this->adjoint();
        $perimetre = app(PerimetreTerrain::class);

        $this->deleguer($prefet, $this->prefecture, $adjoint, now()->addDays(2), now()->addDays(5));
        $this->assertSame([], $perimetre->destinataireIds($adjoint)->all());
        $this->assertFalse($perimetre->estAutoriteDe($adjoint, $this->prefecture));

        Carbon::setTestNow(now()->addDays(3));
        $this->assertSame([$this->prefecture->id], $perimetre->destinataireIds($adjoint)->all());
        Carbon::setTestNow(now()->addDays(2));
        $this->assertSame([], $perimetre->destinataireIds($adjoint)->all());
    }

    public function test_delegation_sans_effet_pour_un_compte_inactif_ou_change_de_role(): void
    {
        $prefet = $this->autoriteFictive($this->prefecture);
        $adjoint = $this->adjoint();
        $this->deleguer($prefet, $this->prefecture, $adjoint);
        $perimetre = app(PerimetreTerrain::class);

        $adjoint->forceFill(['actif' => false])->save();
        $this->assertSame([], $perimetre->destinataireIds($adjoint->fresh())->all());
        $this->assertFalse($perimetre->estAutoriteDe($adjoint->fresh(), $this->prefecture));

        $adjoint->forceFill(['actif' => true, 'role' => 'agent_transport'])->save();
        $this->assertSame([], $perimetre->destinataireIds($adjoint->fresh())->all());
        $this->assertFalse($perimetre->estAutoriteDe($adjoint->fresh(), $this->prefecture));
    }

    public function test_delegation_refusee_dans_ces_cas(): void
    {
        $prefet = $this->autoriteFictive($this->prefecture);
        $verifier = function (string $classe, string $message, callable $action) {
            try {
                $action();
                $this->fail('Une exception était attendue.');
            } catch (\Throwable $e) {
                $this->assertInstanceOf($classe, $e);
                $this->assertSame($message, $e->getMessage());
            }
        };

        $verifier(RegleLogistique::class, 'Le délégué doit être un préfet, un sous-préfet ou un chef de poste actif.',
            fn () => $this->deleguer($prefet, $this->prefecture, $this->adjoint('agent_transport')));
        $verifier(RegleLogistique::class, 'Le délégué doit être un préfet, un sous-préfet ou un chef de poste actif.',
            fn () => $this->deleguer($prefet, $this->prefecture, $this->adjoint('sous_prefet', ['actif' => false])));
        $verifier(RegleLogistique::class, 'Le délégué doit être un préfet, un sous-préfet ou un chef de poste actif.',
            fn () => $this->deleguer($prefet, $this->prefecture, $this->adjoint('gouverneur')));
        $verifier(RegleLogistique::class, 'Une autorité ne peut pas se déléguer à elle-même.',
            fn () => $this->deleguer($prefet, $this->prefecture, $prefet));
        $verifier(RegleLogistique::class, "Cette délégation ne peut pas commencer dans le passé : une réception déjà signée n'est pas régularisée par une délégation.",
            fn () => $this->deleguer($prefet, $this->prefecture, $this->adjoint(), now()->subDay()));
        $verifier(InvalidArgumentException::class, 'La fin de la délégation doit suivre son début.',
            fn () => $this->deleguer($prefet, $this->prefecture, $this->adjoint(), now()->addDay(), now()->addHour()));
        $verifier(InvalidArgumentException::class, 'Le motif est obligatoire (500 caractères au plus).',
            fn () => $this->deleguer($prefet, $this->prefecture, $this->adjoint(), null, null, '  '));
        $verifier(InvalidArgumentException::class, 'Le motif est obligatoire (500 caractères au plus).',
            fn () => $this->deleguer($prefet, $this->prefecture, $this->adjoint(), null, null, str_repeat('x', 501)));
        $verifier(RegleLogistique::class, "Ce destinataire n'a pas de compte d'autorité : rattachez-en un avant de déléguer.",
            fn () => $this->deleguer($this->responsable, $this->sousPrefecture2, $this->adjoint()));

        $adjoint = $this->adjoint();
        $this->deleguer($prefet, $this->prefecture, $adjoint, now(), now()->addDays(5));
        $verifier(RegleLogistique::class, 'Cette personne a déjà une délégation sur ce destinataire pour cette période.',
            fn () => $this->deleguer($prefet, $this->prefecture, $adjoint, now()->addDays(2), null));
        // Une période qui ne chevauche pas l'existante est acceptée.
        $this->deleguer($prefet, $this->prefecture, $adjoint, now()->addDays(5), now()->addDays(8));

        $this->assertSame(2, Delegation::count());
    }

    public function test_tiers_ne_peut_pas_deleguer(): void
    {
        $this->autoriteFictive($this->prefecture);
        $autrePrefet = $this->adjoint('prefet');
        $sousPrefet = $this->autoriteFictive($this->sousPrefecture1);
        $adjoint = $this->adjoint();

        foreach ([$autrePrefet, $sousPrefet, $this->adjoint('agent_transport'), $adjoint] as $tiers) {
            try {
                $this->deleguer($tiers, $this->prefecture, $adjoint);
                $this->fail('Délégation acceptée pour un tiers.');
            } catch (RegleLogistique $e) {
                $this->assertSame('Seule l\'autorité du destinataire ou le responsable logistique peut déléguer.', $e->getMessage());
            }
        }
        $this->assertSame(0, Delegation::count());

        // Un délégué ne redélègue pas.
        $prefet = $this->autoriteFictive($this->prefecture);
        $this->deleguer($prefet, $this->prefecture, $adjoint);
        try {
            $this->deleguer($adjoint, $this->prefecture, $this->adjoint('chef_poste'));
            $this->fail('Redélégation acceptée.');
        } catch (RegleLogistique) {
            $this->assertSame(1, Delegation::count());
        }

        // Le responsable logistique, lui, peut déléguer à la place de l'autorité.
        $autre = $this->adjoint('chef_poste');
        $delegation = $this->deleguer($this->responsable, $this->prefecture, $autre);
        $this->assertSame($prefet->id, $delegation->autorite_user_id);
        $this->assertSame($this->responsable->id, $delegation->cree_par);

        // Sans auteur : invariant.
        auth()->logout();
        $this->expectException(LogicException::class);
        app(DelegationService::class)->deleguer($this->prefecture, $this->adjoint(), now(), null, 'Sans auteur');
    }

    public function test_delegue_declare_en_mode_compte(): void
    {
        $prefet = $this->autoriteFictive($this->prefecture);
        $adjoint = $this->adjoint();
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 20]);
        $remise = $this->livraison($decharge);

        // Sans délégation, l'adjoint ne peut rien signer.
        try {
            $this->declarer($adjoint, $remise);
            $this->fail('Déclaration acceptée sans délégation.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Déclaration hors de votre périmètre.', $e->getMessage());
        }

        $delegation = $this->deleguer($prefet, $this->prefecture, $adjoint);
        $declaration = $this->declarer($adjoint, $remise);

        $this->assertSame('compte', $declaration->mode);
        $this->assertSame('retenue', $declaration->statut);
        $this->assertSame($adjoint->id, $declaration->user_id);
        $this->assertSame($adjoint->id, $declaration->signataire_user_id);
        $this->assertSame($delegation->id, $declaration->delegation_id);
        $this->assertTrue($declaration->delegation->is($delegation));
        $this->assertSame('recue', $remise->refresh()->statut);
        $this->assertSame('recue_destinataire', $decharge->refresh()->statut_acheminement);

        $journal = JournalEntree::where('action', 'declaration_reception')->sole();
        $this->assertSame($delegation->id, $journal->apres['delegation_id']);
        $this->assertSame($adjoint->id, $journal->apres['signataire_user_id']);
    }

    public function test_declaration_du_titulaire_sans_delegation(): void
    {
        $prefet = $this->autoriteFictive($this->prefecture);
        $this->deleguer($prefet, $this->prefecture, $this->adjoint());
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 20]);

        $declaration = $this->declarer($prefet, $this->livraison($decharge));

        $this->assertSame($prefet->id, $declaration->signataire_user_id);
        $this->assertNull($declaration->delegation_id);
    }

    public function test_delegue_de_la_prefecture_signe_la_livraison_via_le_prefet_sans_preparer_la_redistribution(): void
    {
        $prefet = $this->autoriteFictive($this->prefecture);
        $this->autoriteFictive($this->sousPrefecture1);
        $adjoint = $this->adjoint('chef_poste');
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $remise = $this->livraison($decharge);
        $this->assertSame($this->prefecture->id, (int) $remise->a_id);

        $this->deleguer($prefet, $this->prefecture, $adjoint);
        $perimetre = app(PerimetreTerrain::class);

        // La décharge de la sous-préfecture, remise à la préfecture, est visible de l'adjoint.
        $this->assertTrue($perimetre->peutVoir($adjoint, $decharge));
        $this->assertFalse($perimetre->peutVoir($this->adjoint('chef_poste'), $decharge));

        $this->declarer($adjoint, $remise);
        $this->assertSame('recue', $remise->refresh()->statut);

        // Préparer la redistribution reste le fait du préfet.
        $this->actingAs($adjoint);
        try {
            app(RemiseService::class)->preparerRedistribution($decharge->refresh(), [$this->urnes->id => 40]);
            $this->fail('Redistribution préparée par un délégué.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Redistribution hors de votre périmètre.', $e->getMessage());
        }
        $this->actingAs($prefet);
        $redistribution = app(RemiseService::class)->preparerRedistribution($decharge->refresh(), [$this->urnes->id => 40]);
        $this->assertSame('redistribution', $redistribution->etape);

        // Il ne peut pas non plus déclarer, côté préfecture, la remise de la redistribution.
        $this->actingAs($adjoint);
        try {
            app(DeclarationService::class)->declarer(
                $redistribution->load('lignes'), 'tablette', ['nom' => 'Adjoint', 'qualite' => 'Chef de poste'],
                [$this->urnes->id => ['recu' => 40, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(),
            );
            $this->fail('Déclaration de départ acceptée pour un délégué.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Déclaration hors de votre périmètre.', $e->getMessage());
        }
        $this->actingAs($this->responsable);
    }

    public function test_page_terrain_du_delegue_et_declaration_web(): void
    {
        $prefet = $this->autoriteFictive($this->prefecture);
        $adjoint = $this->adjoint();
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 20]);
        $remise = $this->livraison($decharge);
        $this->deleguer($prefet, $this->prefecture, $adjoint);

        $this->actingAs($adjoint);
        $this->get(route('terrain.decharges.index'))->assertOk()->assertSee($decharge->numero);
        $this->get(route('terrain.decharges.show', $decharge))->assertOk()
            ->assertSee(route('terrain.declarations.store', $remise), false);

        $this->post(route('terrain.declarations.store', $remise), [
            'cle' => (string) Str::uuid(), 'signataire_nom' => 'Adjoint Test', 'signataire_qualite' => 'Sous-préfet',
            'lignes' => [$this->urnes->id => ['recu' => 20, 'defectueux' => 0]],
        ])->assertRedirect(route('terrain.decharges.show', $decharge))->assertSessionHas('success');

        $declaration = Declaration::where('remise_id', $remise->id)->sole();
        $this->assertSame($adjoint->id, $declaration->signataire_user_id);
        $this->assertNotNull($declaration->delegation_id);
    }

    public function test_affectations_du_delegue_et_delegues_de_l_autorite(): void
    {
        $prefet = $this->autoriteFictive($this->prefecture);
        $adjoint = $this->adjoint('sous_prefet', ['name' => 'Sow', 'prenom' => 'Fatou']);
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 20]);

        $elements = fn (User $u) => app(AffectationsTerrain::class)->pour($u->fresh());

        $this->assertSame([], $elements($adjoint));
        $delegation = $this->deleguer($prefet, $this->prefecture, $adjoint);

        $duDelegue = $elements($adjoint);
        $this->assertCount(1, $duDelegue);
        $this->assertSame('livraison', $duDelegue[0]['remise']['etape']);
        $this->assertSame($decharge->id, $duDelegue[0]['decharge']['id']);

        $duPrefet = $elements($prefet);
        $this->assertCount(1, $duPrefet);
        $this->assertSame(
            [['user_id' => $adjoint->id, 'nom' => 'Fatou Sow', 'qualite' => 'Sous-préfet']],
            $duPrefet[0]['autorite_attendue']['delegues'],
        );
        $this->assertSame($prefet->id, $duPrefet[0]['autorite_attendue']['user_id']);

        // Même contenu pour le délégué, et la même réponse sur l'API.
        $this->assertSame($duPrefet[0]['autorite_attendue']['delegues'], $duDelegue[0]['autorite_attendue']['delegues']);
        Sanctum::actingAs($adjoint, ['terrain']);
        $this->getJson('/api/terrain/affectations')->assertOk()->assertJsonCount(1, 'elements');
        $this->actingAs($this->responsable);

        // Une délégation révoquée disparaît des délégués et des affectations.
        $this->revoquer($prefet, $delegation);
        $this->assertSame([], $elements($adjoint));
        $this->assertSame([], app(AffectationsTerrain::class)->pour($prefet->fresh())[0]['autorite_attendue']['delegues']);
    }

    public function test_affectations_chargent_les_delegations_en_un_lot(): void
    {
        $prefet = $this->autoriteFictive($this->prefecture);
        $this->deposerChargee($this->prefecture, [$this->urnes->id => 20]);
        $this->deleguer($prefet, $this->prefecture, $this->adjoint());

        $compter = function () use ($prefet) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(AffectationsTerrain::class)->pour($prefet->fresh());
            $requetes = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $requetes;
        };

        $avec1 = $compter();
        $this->deleguer($prefet, $this->prefecture, $this->adjoint('chef_poste'));
        $this->deleguer($prefet, $this->prefecture, $this->adjoint('prefet'));
        $this->assertCount(3, app(AffectationsTerrain::class)->pour($prefet->fresh())[0]['autorite_attendue']['delegues']);

        $this->assertSame($avec1, $compter());
    }

    public function test_revocation_retire_l_acces(): void
    {
        $prefet = $this->autoriteFictive($this->prefecture);
        $adjoint = $this->adjoint();
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 20]);
        $remise = $this->livraison($decharge);
        $perimetre = app(PerimetreTerrain::class);

        $delegation = $this->deleguer($prefet, $this->prefecture, $adjoint);
        $this->assertTrue($perimetre->peutVoir($adjoint, $decharge));

        $this->revoquer($prefet, $delegation, 'Retour de mission');

        $delegation->refresh();
        $this->assertNotNull($delegation->revoquee_le);
        $this->assertSame([], $perimetre->destinataireIds($adjoint)->all());
        $this->assertFalse($perimetre->peutVoir($adjoint, $decharge));
        $this->assertFalse($perimetre->estAutoriteDe($adjoint, $this->prefecture));
        try {
            $this->declarer($adjoint, $remise);
            $this->fail('Déclaration acceptée après révocation.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Déclaration hors de votre périmètre.', $e->getMessage());
        }

        $entree = JournalEntree::where('action', 'delegation_revoquee')->sole();
        $this->assertSame($delegation->id, $entree->objet_id);
        $this->assertNull($entree->avant['revoquee_le']);
        $this->assertNotNull($entree->apres['revoquee_le']);
        $this->assertSame('Retour de mission', $entree->motif);

        // Révoquer deux fois est refusé.
        try {
            $this->revoquer($prefet, $delegation);
            $this->fail('Seconde révocation acceptée.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Cette délégation est déjà révoquée.', $e->getMessage());
        }
        $this->assertSame(1, JournalEntree::where('action', 'delegation_revoquee')->count());
    }

    public function test_qui_peut_revoquer(): void
    {
        $prefet = $this->autoriteFictive($this->prefecture);
        $adjoint = $this->adjoint();
        $delegation = $this->deleguer($prefet, $this->prefecture, $adjoint);

        foreach ([$adjoint, $this->adjoint('prefet'), $this->adjoint('agent_transport')] as $tiers) {
            try {
                $this->revoquer($tiers, $delegation);
                $this->fail('Révocation acceptée pour un tiers.');
            } catch (RegleLogistique $e) {
                $this->assertSame('Seule l\'autorité du destinataire ou le responsable logistique peut révoquer une délégation.', $e->getMessage());
            }
        }
        $this->assertNull($delegation->refresh()->revoquee_le);

        try {
            $this->revoquer($prefet, $delegation, ' ');
            $this->fail('Motif vide accepté.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Le motif est obligatoire (500 caractères au plus).', $e->getMessage());
        }

        // Le responsable logistique révoque à la place de l'autorité.
        $this->revoquer($this->responsable, $delegation);
        $this->assertNotNull($delegation->refresh()->revoquee_le);

        // Une délégation terminée d'elle-même ne se révoque pas.
        $terminee = $this->deleguer($prefet, $this->prefecture, $adjoint, now(), now()->addDay());
        Carbon::setTestNow(now()->addDays(2));
        try {
            $this->revoquer($prefet, $terminee);
            $this->fail('Révocation d\'une délégation terminée.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Cette délégation est déjà terminée.', $e->getMessage());
        }
    }

    public function test_delegation_active_a_un_instant_passe(): void
    {
        $t0 = Carbon::parse('2026-11-10 08:00:00');
        Carbon::setTestNow($t0);
        $prefet = $this->autoriteFictive($this->prefecture);
        $adjoint = $this->adjoint();
        $autre = $this->adjoint('chef_poste');
        $service = app(DelegationService::class);

        $ouverte = $this->deleguer($prefet, $this->prefecture, $adjoint, $t0);
        $this->assertNotNull($service->activeA($this->prefecture, $adjoint, $t0->copy()->addDays(2)));

        // Révoquée le jour 5 : active avant, plus après (intervalle semi-ouvert).
        Carbon::setTestNow($t0->copy()->addDays(5));
        $this->revoquer($prefet, $ouverte);
        $revocation = $ouverte->refresh()->revoquee_le;

        $this->assertTrue($service->activeA($this->prefecture, $adjoint, $t0)?->is($ouverte));
        $this->assertTrue($service->activeA($this->prefecture, $adjoint, $t0->copy()->addDays(2))?->is($ouverte));
        $this->assertTrue($service->activeA($this->prefecture, $adjoint, $revocation->copy()->subSecond())?->is($ouverte));
        $this->assertNull($service->activeA($this->prefecture, $adjoint, $revocation));
        $this->assertNull($service->activeA($this->prefecture, $adjoint, $t0->copy()->addDays(6)));
        // Avant le début, ou pour un autre compte ou un autre destinataire : rien.
        $this->assertNull($service->activeA($this->prefecture, $adjoint, $t0->copy()->subSecond()));
        $this->assertNull($service->activeA($this->prefecture, $autre, $t0->copy()->addDays(2)));
        $this->assertNull($service->activeA($this->sousPrefecture1, $adjoint, $t0->copy()->addDays(2)));

        // Délégation à durée fixe : la fin est exclue.
        $fixe = $this->deleguer($prefet, $this->prefecture, $autre, $t0->copy()->addDays(5), $t0->copy()->addDays(8));
        $this->assertNull($service->activeA($this->prefecture, $autre, $t0->copy()->addDays(5)->subSecond()));
        $this->assertTrue($service->activeA($this->prefecture, $autre, $t0->copy()->addDays(5))?->is($fixe));
        $this->assertTrue($service->activeA($this->prefecture, $autre, $t0->copy()->addDays(8)->subSecond())?->is($fixe));
        $this->assertNull($service->activeA($this->prefecture, $autre, $t0->copy()->addDays(8)));

        // Révoquée avant son début : jamais active.
        $future = $this->deleguer($prefet, $this->prefecture, $this->adjoint('prefet'), $t0->copy()->addDays(20));
        $this->revoquer($prefet, $future);
        $this->assertNull($service->activeA($this->prefecture, User::findOrFail($future->delegue_user_id), $t0->copy()->addDays(21)));
    }

    public function test_ecrans_de_delegation(): void
    {
        $prefet = $this->autoriteFictive($this->prefecture);
        $adjoint = $this->adjoint('sous_prefet', ['name' => 'Sow', 'prenom' => 'Fatou']);
        $formulaire = [
            'destinataire_id' => $this->prefecture->id, 'delegue_user_id' => $adjoint->id,
            'du' => now()->format('Y-m-d\TH:i'), 'au' => now()->addDays(7)->format('Y-m-d\TH:i'), 'motif' => 'Mission à Dakar',
        ];

        $this->actingAs($this->adjoint('agent_transport'));
        $this->get(route('terrain.delegations.index'))->assertForbidden();

        $this->actingAs($prefet);
        $this->get(route('terrain.delegations.index'))->assertOk()
            ->assertSee('Déléguer ma signature')->assertSee('Préfecture de Thiès')->assertSee('Fatou Sow');

        $this->post(route('terrain.delegations.store'), $formulaire)
            ->assertRedirect()->assertSessionHas('success');
        $delegation = Delegation::sole();
        $this->assertSame($adjoint->id, $delegation->delegue_user_id);
        $this->get(route('terrain.delegations.index'))->assertOk()->assertSee('Mission à Dakar');

        // Un destinataire dont on n'est pas l'autorité : refusé sans rien créer ; une règle métier revient au formulaire.
        $this->post(route('terrain.delegations.store'), ['destinataire_id' => $this->sousPrefecture1->id] + $formulaire)
            ->assertSessionHas('error');
        $this->post(route('terrain.delegations.store'), ['delegue_user_id' => $prefet->id] + $formulaire)
            ->assertSessionHas('error');
        $this->post(route('terrain.delegations.store'), ['motif' => ''] + $formulaire)->assertSessionHasErrors('motif');
        $this->assertSame(1, Delegation::count());

        // Le délégué voit ce qu'il a reçu, sans pouvoir révoquer ni déléguer.
        $this->actingAs($adjoint);
        $this->get(route('terrain.delegations.index'))->assertOk()->assertSee('Délégations reçues')->assertSee('Préfecture de Thiès')
            ->assertDontSee(route('terrain.delegations.revoquer', $delegation), false);
        $this->post(route('terrain.delegations.revoquer', $delegation), ['motif' => 'Essai'])->assertNotFound();
        $this->assertNull($delegation->refresh()->revoquee_le);

        $this->actingAs($prefet);
        $this->post(route('terrain.delegations.revoquer', $delegation), ['motif' => 'Retour de mission'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertNotNull($delegation->refresh()->revoquee_le);
        $this->post(route('terrain.delegations.revoquer', $delegation), ['motif' => 'Encore'])->assertSessionHas('error');
        $this->post(route('terrain.delegations.revoquer', $delegation), [])->assertSessionHasErrors('motif');
    }

    public function test_delegation_liee_a_l_autorite_en_titre(): void
    {
        $ancien = $this->autoriteFictive($this->prefecture);
        $adjoint = $this->adjoint();
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 20]);
        $remise = $this->livraison($decharge);
        $delegation = $this->deleguer($ancien, $this->prefecture, $adjoint);
        $perimetre = app(PerimetreTerrain::class);
        $service = app(DelegationService::class);
        $this->assertTrue($perimetre->estAutoriteDe($adjoint, $this->prefecture));

        foreach ([$this->adjoint('prefet')->id, null] as $nouveau) {
            // Le titulaire change (ou le destinataire perd son compte d'autorité) : la délégation ne suit pas.
            $this->prefecture->forceFill(['autorite_user_id' => $nouveau])->save();

            $this->assertSame([], $perimetre->destinataireIds($adjoint->fresh())->all());
            $this->assertSame([], $perimetre->portee($adjoint->fresh())['delegues']->all());
            $this->assertFalse($perimetre->estAutoriteDe($adjoint->fresh(), $this->prefecture->fresh()));
            $this->assertFalse($perimetre->peutVoir($adjoint->fresh(), $decharge));
            $this->assertNull($service->activeA($this->prefecture, $adjoint, now()));
            $this->assertSame([], app(AffectationsTerrain::class)->pour($adjoint->fresh()));
            try {
                $this->declarer($adjoint->fresh(), $remise);
                $this->fail('Déclaration acceptée après le changement de titulaire.');
            } catch (RegleLogistique $e) {
                $this->assertSame('Déclaration hors de votre périmètre.', $e->getMessage());
            }
        }

        // Le nouveau titulaire ne voit pas l'ancienne délégation parmi les délégués de l'autorité attendue.
        $nouveauTitulaire = $this->adjoint('prefet');
        $this->prefecture->forceFill(['autorite_user_id' => $nouveauTitulaire->id])->save();
        $elements = app(AffectationsTerrain::class)->pour($nouveauTitulaire);
        $this->assertSame([], $elements[0]['autorite_attendue']['delegues']);

        // Si l'ancien titulaire revient, la délégation (jamais révoquée) reprend effet.
        $this->prefecture->forceFill(['autorite_user_id' => $ancien->id])->save();
        $this->assertTrue($perimetre->estAutoriteDe($adjoint->fresh(), $this->prefecture->fresh()));
        $this->assertNull($delegation->refresh()->revoquee_le);
    }

    public function test_delegue_d_un_destinataire_n_a_aucun_acces_a_un_autre(): void
    {
        $prefet = $this->autoriteFictive($this->prefecture);
        $sousPrefet = $this->autoriteFictive($this->sousPrefecture1);
        $adjoint = $this->adjoint('chef_poste');
        $deSousPrefecture = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $dePrefecture = $this->deposerChargee($this->prefecture, [$this->urnes->id => 20]);
        $this->deleguer($sousPrefet, $this->sousPrefecture1, $adjoint);
        $perimetre = app(PerimetreTerrain::class);

        $this->assertSame([$this->sousPrefecture1->id], $perimetre->destinataireIds($adjoint)->all());
        $this->assertTrue($perimetre->peutVoir($adjoint, $deSousPrefecture));
        $this->assertFalse($perimetre->peutVoir($adjoint, $dePrefecture));
        $this->assertFalse($perimetre->estAutoriteDe($adjoint, $this->prefecture));
        $this->assertFalse($perimetre->estAutoriteDe($adjoint, $this->sousPrefecture2));

        try {
            $this->declarer($adjoint, $this->livraison($dePrefecture));
            $this->fail('Déclaration acceptée sur un autre destinataire.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Déclaration hors de votre périmètre.', $e->getMessage());
        }
        $this->assertSame($prefet->id, $this->prefecture->refresh()->autorite_user_id);
    }

    public function test_delegation_exactement_a_son_terme_ne_donne_plus_rien(): void
    {
        $t0 = Carbon::parse('2026-11-10 08:00:00');
        Carbon::setTestNow($t0);
        $prefet = $this->autoriteFictive($this->prefecture);
        $adjoint = $this->adjoint();
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 20]);
        $remise = $this->livraison($decharge);
        $au = $t0->copy()->addHour();
        $this->deleguer($prefet, $this->prefecture, $adjoint, $t0, $au);
        $perimetre = app(PerimetreTerrain::class);

        Carbon::setTestNow($au->copy()->subSecond());
        $this->assertTrue($perimetre->estAutoriteDe($adjoint, $this->prefecture));

        // À l'instant de la fin, tout est refusé : périmètre, droit de signer et déclaration.
        Carbon::setTestNow($au);
        $this->assertSame([], $perimetre->destinataireIds($adjoint)->all());
        $this->assertFalse($perimetre->estAutoriteDe($adjoint, $this->prefecture));
        $this->assertFalse($perimetre->peutVoir($adjoint, $decharge));
        try {
            $this->declarer($adjoint, $remise);
            $this->fail('Déclaration acceptée à l\'instant de la fin.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Déclaration hors de votre périmètre.', $e->getMessage());
        }
        $this->assertSame(0, Declaration::count());
    }

    public function test_debut_de_delegation_borne_de_cinq_minutes(): void
    {
        $t0 = Carbon::parse('2026-11-10 08:00:00');
        Carbon::setTestNow($t0);
        $prefet = $this->autoriteFictive($this->prefecture);

        $acceptee = $this->deleguer($prefet, $this->prefecture, $this->adjoint(), $t0->copy()->subMinutes(5));
        $this->assertTrue($acceptee->du->equalTo($t0->copy()->subMinutes(5)));

        try {
            $this->deleguer($prefet, $this->prefecture, $this->adjoint('chef_poste'), $t0->copy()->subMinutes(5)->subSecond());
            $this->fail('Début trop ancien accepté.');
        } catch (RegleLogistique $e) {
            $this->assertStringStartsWith('Cette délégation ne peut pas commencer dans le passé', $e->getMessage());
        }
        $this->assertSame(1, Delegation::count());
    }

    public function test_compte_lie_a_une_delegation_ou_a_une_signature_ne_se_supprime_pas(): void
    {
        $prefet = $this->autoriteFictive($this->prefecture);
        $adjoint = $this->adjoint();
        $libre = $this->adjoint('chef_poste');
        $admin = User::factory()->create(['role' => 'admin']);
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 20]);
        $this->deleguer($prefet, $this->prefecture, $adjoint);
        $this->declarer($adjoint, $this->livraison($decharge));

        $this->actingAs($admin);
        foreach ([$adjoint, $prefet] as $lie) {
            $this->delete(route('users.destroy', $lie))->assertSessionHas('error');
            $this->assertDatabaseHas('users', ['id' => $lie->id]);
        }
        $this->delete(route('users.destroy', $libre))->assertSessionHas('success');
    }
}

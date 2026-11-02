<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Appareil;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\DeclarationLigne;
use App\Models\Logistique\Delegation;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Remise;
use App\Models\Logistique\ReserveAppareil;
use App\Models\User;
use App\Services\Logistique\AffectationsTerrain;
use App\Services\Logistique\AppareilService;
use App\Services\Logistique\ArbitrageService;
use App\Services\Logistique\AvisReceptionService;
use App\Services\Logistique\DechargePdfService;
use App\Services\Logistique\DeclarationService;
use App\Services\Logistique\DelegationService;
use App\Services\Logistique\DonneesDecharge;
use App\Services\Logistique\IndicateurService;
use App\Services\Logistique\RemiseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class AppareilReserveTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    private User $prefet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerDecorFictif();
        $this->approuverPlanFictif([
            [$this->sousPrefecture1, $this->urnes, 100],
            [$this->sousPrefecture1, $this->isoloirs, 50],
        ]);
        $this->stockerFictif($this->urnes, 1000, 300);
        $this->stockerFictif($this->isoloirs, 1000, 100);
        $this->prefet = $this->autoriteFictive($this->prefecture);
    }

    /** Décharge via le préfet, chargée puis reçue par le préfet (100 urnes). */
    private function recueParLePrefet(int $defectueux = 0): Decharge
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => ['recu' => 100, 'defectueux' => $defectueux]]);

        return $decharge->refresh();
    }

    private function en(User $auteur, callable $action): mixed
    {
        $this->actingAs($auteur);
        try {
            return $action();
        } finally {
            $this->actingAs($this->responsable);
        }
    }

    /** @var array<string, PersonalAccessToken> jeton ouvert par la tablette, par identifiant (celui qui est épinglé à la désignation) */
    private array $tablettes = [];

    /** Jeton de terrain de $utilisateur, ouvert en annonçant (ou non) l'UUID d'un appareil. */
    private function jeton(User $utilisateur, ?string $appareil = null): PersonalAccessToken
    {
        $jeton = $utilisateur->createToken($appareil ?? 'Tablette', ['terrain'])->accessToken;
        if ($appareil !== null) {
            $jeton->forceFill(['appareil_identifiant' => mb_strtolower($appareil)])->save();
        }

        return $jeton;
    }

    /**
     * Désigne l'appareil : la tablette s'est d'abord connectée avec son UUID et un compte de la préfecture
     * (`$connecte`, l'autorité par défaut) ; ce jeton est épinglé à la désignation.
     */
    private function designer(?string $identifiant = null, string $nom = 'Tablette de la préfecture', ?Destinataire $prefecture = null, ?User $connecte = null): Appareil
    {
        $identifiant = mb_strtolower($identifiant ?? (string) Str::uuid());
        $prefecture ??= $this->prefecture;
        $this->tablettes[$identifiant] = $this->jeton($connecte ?? $this->autoriteFictive($prefecture), $identifiant);

        return app(AppareilService::class)->designer($prefecture, $identifiant, $nom);
    }

    private function reserver(Appareil $appareil, Decharge $decharge, ?array $quantites = null, ?User $par = null)
    {
        return $this->en($par ?? $this->prefet, fn () => app(AppareilService::class)->reserver($appareil, $decharge, $quantites));
    }

    /** @return array<string, int> */
    private function solde(Decharge $decharge, ?int $materielId = null): array
    {
        return app(RemiseService::class)->soldePrefet($decharge)->get($materielId ?? $this->urnes->id);
    }

    private function refus(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail("Le refus « {$message} » était attendu.");
        } catch (RegleLogistique $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function adjoint(string $role = 'chef_poste'): User
    {
        $adjoint = User::factory()->create(['role' => $role]);
        $this->en($this->prefet, fn () => app(DelegationService::class)->deleguer($this->prefecture, $adjoint, now(), null, 'Adjoint habilité'));

        return $adjoint;
    }

    private function arbitrer(Remise $remise, string $decision, string $motif): void
    {
        $this->en($this->responsable, fn () => app(ArbitrageService::class)->arbitrerRemise($remise, $decision, $motif));
    }

    /** @param  array<int, int>  $quantites */
    private function corps(array $quantites, ?string $cle = null, ?string $horodatage = null): array
    {
        return [
            'cle' => $cle ?? (string) Str::uuid(),
            'horodatage_terminal' => $horodatage ?? '2026-11-06T09:30:00+00:00',
            'lignes' => collect($quantites)->map(fn ($q, $id) => ['materiel_id' => $id, 'quantite' => $q])->values()->all(),
        ];
    }

    /** Authentifie $utilisateur par ce jeton de terrain (un jeton ordinaire, sans appareil, à défaut). */
    private function terrain(User $utilisateur, ?PersonalAccessToken $jeton = null): void
    {
        $utilisateur->withAccessToken($jeton ?? $this->jeton($utilisateur));
        $this->app['auth']->guard('sanctum')->setUser($utilisateur);
        $this->app['auth']->shouldUse('sanctum');
    }

    /**
     * Le jeton d'une requête : par défaut, celui que la tablette de l'appareil annoncé a épinglé s'il appartient à
     * l'utilisateur ; sinon un jeton ouvert avec le même UUID (une autorité qui recopie l'identifiant). `null` : un jeton
     * ordinaire ; un jeton : celui-là.
     */
    private function jetonDeRequete(User $utilisateur, ?string $appareil, PersonalAccessToken|string|null $jeton): ?PersonalAccessToken
    {
        if ($jeton instanceof PersonalAccessToken || ($jeton === null)) {
            return $jeton;
        }
        $epingle = $appareil === null ? null : ($this->tablettes[mb_strtolower($appareil)] ?? null);

        return $epingle !== null && (int) $epingle->tokenable_id === $utilisateur->id ? $epingle : $this->jeton($utilisateur, $appareil);
    }

    private function sync(Decharge $decharge, array $corps, ?string $appareil, ?User $par = null, PersonalAccessToken|string|null $jeton = 'auto')
    {
        $par ??= $this->prefet;
        $this->terrain($par, $this->jetonDeRequete($par, $appareil, $jeton));
        $this->withoutHeader('X-Appareil');

        return $this->withHeaders($appareil === null ? [] : ['X-Appareil' => $appareil])
            ->postJson("/api/terrain/decharges/{$decharge->id}/redistributions", $corps);
    }

    private function api(string $methode, Decharge $decharge, string $chemin, ?string $appareil, array $corps = [], ?User $par = null, PersonalAccessToken|string|null $jeton = 'auto')
    {
        $par ??= $this->prefet;
        $this->terrain($par, $this->jetonDeRequete($par, $appareil, $jeton));
        $this->withoutHeader('X-Appareil');

        return $this->withHeaders($appareil === null ? [] : ['X-Appareil' => $appareil])
            ->json($methode, "/api/terrain/decharges/{$decharge->id}/{$chemin}", $corps);
    }

    public function test_un_seul_appareil_designe_par_prefecture(): void
    {
        $premier = $this->designer(null, 'Première tablette');
        $this->assertTrue($premier->actif);
        $this->assertNull($premier->revoque_le);
        $this->assertSame($this->responsable->id, $premier->designe_par);

        $second = $this->designer(null, 'Seconde tablette');

        $this->assertFalse($premier->fresh()->actif);
        $this->assertNotNull($premier->fresh()->revoque_le);
        $this->assertTrue($second->fresh()->actif);
        $this->assertSame(1, Appareil::where('destinataire_id', $this->prefecture->id)->where('actif', true)->count());
        $this->assertSame(1, JournalEntree::where('action', 'revocation_appareil')->where('objet_id', $premier->id)->count());
        $this->assertSame(1, JournalEntree::where('action', 'designation_appareil')->where('objet_id', $second->id)->count());

        // Redésigner l'appareil déjà désigné ne crée rien.
        $this->assertSame($second->id, app(AppareilService::class)->designer($this->prefecture, $second->identifiant, 'Seconde tablette')->id);
        $this->assertSame(2, Appareil::count());

        // Un appareil révoqué peut être désigné de nouveau : le courant est alors révoqué.
        $this->assertSame($premier->id, $this->designer($premier->identifiant, 'Première tablette')->id);
        $this->assertTrue($premier->fresh()->actif);
        $this->assertFalse($second->fresh()->actif);
    }

    public function test_designation_reservee_au_responsable_et_controlee(): void
    {
        $gestionnaire = User::factory()->create(['role' => 'logistique_gestionnaire']);
        $this->refus(fn () => $this->en($gestionnaire, fn () => $this->designer()), 'Seul un responsable logistique peut désigner un appareil.');
        $this->refus(fn () => $this->en($this->prefet, fn () => $this->designer()), 'Seul un responsable logistique peut désigner un appareil.');
        $this->refus(fn () => $this->designer(null, 'Tablette', $this->sousPrefecture1), 'Un appareil se désigne pour une préfecture.');

        $this->expectException(InvalidArgumentException::class);
        $this->designer('pas-un-uuid');
    }

    public function test_un_appareil_ne_sert_qu_a_une_prefecture(): void
    {
        $autre = Destinataire::create(['type' => 'prefecture', 'libelle' => 'Préfecture de Louga', 'departement_id' => 11]);
        $appareil = $this->designer();

        $this->refus(fn () => $this->designer($appareil->identifiant, 'Tablette', $autre), 'Cet appareil est déjà enregistré pour une autre préfecture.');
    }

    public function test_reserve_par_defaut_tout_le_solde(): void
    {
        $decharge = $this->recueParLePrefet(10);
        $appareil = $this->designer();
        $this->assertSame(['recu_utilisable' => 90, 'transfere' => 0, 'reliquat' => 90, 'reserve_appareil' => 0, 'mobilisable' => 90], $this->solde($decharge));

        $reserve = $this->reserver($appareil, $decharge);

        $this->assertSame([[$this->urnes->id, 90]], $reserve->map(fn ($r) => [$r->materiel_id, $r->quantite])->all());
        $this->assertSame(['recu_utilisable' => 90, 'transfere' => 0, 'reliquat' => 90, 'reserve_appareil' => 90, 'mobilisable' => 0], $this->solde($decharge));
        $this->assertSame(1, JournalEntree::where('action', 'reservation_appareil')->where('objet_id', $appareil->id)->count());
    }

    public function test_reserve_apres_transfert_en_ligne_et_remplacement_de_la_reserve(): void
    {
        $decharge = $this->recueParLePrefet();
        $this->en($this->prefet, fn () => app(RemiseService::class)->preparerRedistribution($decharge, [$this->urnes->id => 20]));
        $appareil = $this->designer();

        $this->reserver($appareil, $decharge);
        $this->assertSame(80, $this->solde($decharge)['reserve_appareil']);

        // Une nouvelle réserve remplace la précédente.
        $this->reserver($appareil, $decharge, [$this->urnes->id => 30]);
        $this->assertSame(30, $this->solde($decharge)['reserve_appareil']);
        $this->assertSame(50, $this->solde($decharge)['mobilisable']);
        $this->assertSame(1, ReserveAppareil::where('statut', 'active')->count());
        $this->assertSame(1, ReserveAppareil::where('statut', 'liberee')->count());

        $this->refus(fn () => $this->reserver($appareil, $decharge, [$this->urnes->id => 81]),
            'Réserve supérieure au mobilisable pour Urnes : mobilisable 80, demandé 81.');
        $this->assertSame(30, $this->solde($decharge)['reserve_appareil']);

        $this->refus(fn () => $this->reserver($appareil, $decharge, [$this->urnes->id => 0]), 'Rien à réserver.');
    }

    public function test_reserve_refusee_pour_un_appareil_non_designe_ou_un_autre_compte(): void
    {
        $decharge = $this->recueParLePrefet();
        $appareil = $this->designer();
        $ancien = $this->designer();

        $this->refus(fn () => $this->reserver($appareil, $decharge), "Cet appareil n'est pas l'appareil désigné de la préfecture.");
        $this->refus(fn () => $this->reserver($ancien, $decharge, null, $this->autoriteFictive($this->sousPrefecture1)), 'Redistribution hors de votre périmètre.');
        $this->assertSame(0, ReserveAppareil::count());
    }

    public function test_adjoint_en_ligne_refuse_quand_tout_est_reserve(): void
    {
        $decharge = $this->recueParLePrefet();
        $appareil = $this->designer();
        $this->reserver($appareil, $decharge);
        $adjoint = $this->adjoint();

        $this->refus(fn () => $this->en($adjoint, fn () => app(RemiseService::class)->preparerRedistribution($decharge, [$this->urnes->id => 50])),
            "Redistribution supérieure au mobilisable du préfet pour Urnes : mobilisable 0, réservé à l'appareil 100, demandé 50.");
        $this->assertSame(0, Remise::where('etape', 'redistribution')->count());

        // Libérée, la réserve redevient mobilisable.
        $this->en($this->prefet, fn () => app(AppareilService::class)->liberer($appareil, $decharge));
        $remise = $this->en($adjoint, fn () => app(RemiseService::class)->preparerRedistribution($decharge, [$this->urnes->id => 50]));
        $this->assertSame($adjoint->id, $remise->preparee_par);
    }

    public function test_appareil_redistribue_jusqu_a_sa_reserve(): void
    {
        $decharge = $this->recueParLePrefet();
        $appareil = $this->designer();
        $this->reserver($appareil, $decharge);

        $premiere = $this->sync($decharge, $this->corps([$this->urnes->id => 60]), $appareil->identifiant)->assertCreated();
        $this->assertSame('en_attente', $premiere->json('redistribution.statut'));
        $this->assertFalse($premiere->json('redistribution.a_arbitrer'));
        $this->assertSame($appareil->identifiant, $premiere->json('redistribution.appareil'));
        $this->assertSame(['recu_utilisable' => 100, 'transfere' => 60, 'reliquat' => 40, 'reserve_appareil' => 40, 'mobilisable' => 0], $this->solde($decharge));

        $this->sync($decharge, $this->corps([$this->urnes->id => 40]), $appareil->identifiant)->assertCreated()
            ->assertJsonPath('redistribution.statut', 'en_attente');

        $this->assertSame(['recu_utilisable' => 100, 'transfere' => 100, 'reliquat' => 0, 'reserve_appareil' => 0, 'mobilisable' => 0], $this->solde($decharge));
        $this->assertSame(0, ReserveAppareil::where('statut', 'active')->count());
        $this->assertSame('consommee', ReserveAppareil::sole()->statut);
        $remise = Remise::findOrFail($premiere->json('redistribution.id'));
        $this->assertSame($appareil->id, $remise->appareil_id);
        $this->assertSame($this->prefet->id, $remise->preparee_par);
        $this->assertSame([$this->prefecture->id, $this->sousPrefecture1->id], [$remise->de_id, $remise->a_id]);
        $this->assertSame('2026-11-06 09:30:00', $remise->horodatage_terminal->format('Y-m-d H:i:s'));
    }

    public function test_deux_appareils_80_plus_50(): void
    {
        $decharge = $this->recueParLePrefet();
        $designe = $this->designer();
        $autre = (string) Str::uuid();
        $this->reserver($designe, $decharge);

        // L'appareil non désigné ne peut rien redistribuer hors ligne : sa remise est à arbitrer.
        $reponse = $this->sync($decharge, $this->corps([$this->urnes->id => 50]), $autre)->assertCreated();
        $this->assertSame('a_arbitrer', $reponse->json('redistribution.statut'));
        $this->assertTrue($reponse->json('redistribution.a_arbitrer'));
        $this->assertSame('Appareil non désigné pour cette préfecture.', $reponse->json('redistribution.raison'));
        $this->assertSame(0, $this->solde($decharge)['transfere']);

        $this->sync($decharge, $this->corps([$this->urnes->id => 80]), $designe->identifiant)->assertCreated()
            ->assertJsonPath('redistribution.statut', 'en_attente');

        $this->assertSame(['recu_utilisable' => 100, 'transfere' => 80, 'reliquat' => 20, 'reserve_appareil' => 20, 'mobilisable' => 0], $this->solde($decharge));
        $this->assertSame(1, Remise::where('statut', 'a_arbitrer')->count());
        $this->assertNull(Remise::where('statut', 'a_arbitrer')->sole()->appareil_id);
        $this->assertSame($autre, Remise::where('statut', 'a_arbitrer')->sole()->appareil_declare);
    }

    public function test_depassement_synchronise_a_arbitrer(): void
    {
        $decharge = $this->recueParLePrefet();
        $appareil = $this->designer();
        $this->reserver($appareil, $decharge, [$this->urnes->id => 60]);

        $reponse = $this->sync($decharge, $this->corps([$this->urnes->id => 80]), $appareil->identifiant)->assertCreated();

        $this->assertSame('a_arbitrer', $reponse->json('redistribution.statut'));
        $this->assertSame("Quantité supérieure à la réserve de l'appareil pour Urnes : réserve 60, demandée 80.", $reponse->json('redistribution.raison'));
        $this->assertSame(['recu_utilisable' => 100, 'transfere' => 0, 'reliquat' => 100, 'reserve_appareil' => 60, 'mobilisable' => 40], $this->solde($decharge));
        $remise = Remise::findOrFail($reponse->json('redistribution.id'));
        $this->assertSame($appareil->id, $remise->appareil_id);
        $this->assertSame([[$this->urnes->id, 80]], $remise->lignes->map(fn ($l) => [$l->materiel_id, $l->quantite])->all());
        $this->assertSame(1, JournalEntree::where('action', 'redistribution_a_arbitrer')->where('objet_id', $remise->id)->count());
        $this->assertSame(0, JournalEntree::where('action', 'preparation_redistribution')->count());

        // La réserve n'a pas été entamée : l'appareil peut encore redistribuer dedans.
        $this->sync($decharge, $this->corps([$this->urnes->id => 60]), $appareil->identifiant)->assertCreated()
            ->assertJsonPath('redistribution.statut', 'en_attente');
        $this->assertSame(60, $this->solde($decharge)['transfere']);
    }

    public function test_appareil_revoque_ses_remises_sont_a_arbitrer_et_sa_reserve_est_liberee(): void
    {
        $decharge = $this->recueParLePrefet();
        $ancien = $this->designer();
        $this->reserver($ancien, $decharge);
        $this->designer(); // le remplace

        $this->assertSame(0, ReserveAppareil::where('statut', 'active')->count());
        $this->assertSame(100, $this->solde($decharge)['mobilisable']);

        $reponse = $this->sync($decharge, $this->corps([$this->urnes->id => 10]), $ancien->identifiant)->assertCreated();
        $this->assertSame('a_arbitrer', $reponse->json('redistribution.statut'));
        $this->assertSame('Appareil révoqué.', $reponse->json('redistribution.raison'));
        $this->assertSame($ancien->id, Remise::findOrFail($reponse->json('redistribution.id'))->appareil_id);
    }

    public function test_liberation_du_reste(): void
    {
        $decharge = $this->recueParLePrefet();
        $appareil = $this->designer();
        $this->reserver($appareil, $decharge);
        $this->sync($decharge, $this->corps([$this->urnes->id => 30]), $appareil->identifiant)->assertCreated();

        $reponse = $this->api('DELETE', $decharge, 'reserve', $appareil->identifiant)->assertOk();

        $this->assertSame([['materiel_id' => $this->urnes->id, 'quantite' => 70]], collect($reponse->json('libere'))->map(fn ($l) => ['materiel_id' => $l['materiel_id'], 'quantite' => $l['quantite']])->all());
        $this->assertSame([], $reponse->json('reserve.lignes'));
        $this->assertSame(['recu_utilisable' => 100, 'transfere' => 30, 'reliquat' => 70, 'reserve_appareil' => 0, 'mobilisable' => 70], $this->solde($decharge));
        $this->assertSame(['consommee' => 0, 'liberee' => 1], [
            'consommee' => ReserveAppareil::where('statut', 'consommee')->count(), 'liberee' => ReserveAppareil::where('statut', 'liberee')->count(),
        ]);
        $this->assertSame(1, JournalEntree::where('action', 'liberation_reserve_appareil')->where('objet_id', $appareil->id)->count());

        // Rien de plus à libérer : la requête reste valide.
        $this->api('DELETE', $decharge, 'reserve', $appareil->identifiant)->assertOk()->assertJsonPath('libere', []);
    }

    public function test_redistribution_synchronisee_idempotente(): void
    {
        $decharge = $this->recueParLePrefet();
        $appareil = $this->designer();
        $this->reserver($appareil, $decharge);
        $corps = $this->corps([$this->urnes->id => 30], 'cle-redistribution-0001');

        $premiere = $this->sync($decharge, $corps, $appareil->identifiant)->assertCreated();
        $rejeu = $this->sync($decharge, $corps, $appareil->identifiant)->assertOk();

        $this->assertSame($premiere->json('redistribution.id'), $rejeu->json('redistribution.id'));
        $this->assertSame(1, Remise::where('etape', 'redistribution')->count());
        $this->assertSame(70, $this->solde($decharge)['reserve_appareil']);
        $this->assertSame(30, $this->solde($decharge)['transfere']);

        $this->sync($decharge, array_replace($corps, ['lignes' => [['materiel_id' => $this->urnes->id, 'quantite' => 31]]]), $appareil->identifiant)
            ->assertStatus(422)->assertJson(['message' => "Clé d'idempotence déjà utilisée pour un autre contenu."]);
        $this->sync($decharge, array_replace($corps, ['horodatage_terminal' => '2026-11-06T09:31:00+00:00']), $appareil->identifiant)
            ->assertStatus(422)->assertJson(['message' => "Clé d'idempotence déjà utilisée pour un autre contenu."]);
        $this->sync($decharge, $corps, (string) Str::uuid())
            ->assertStatus(422)->assertJson(['message' => "Clé d'idempotence déjà utilisée pour un autre contenu."]);
        $this->sync($decharge, $corps, $appareil->identifiant, $this->adjoint())
            ->assertStatus(422)->assertJson(['message' => "Clé d'idempotence déjà utilisée pour un autre contenu."]);

        $this->assertSame(1, Remise::where('etape', 'redistribution')->count());
        $this->assertSame(70, $this->solde($decharge)['reserve_appareil']);
        $this->assertGreaterThanOrEqual(1, JournalEntree::where('action', 'anomalie_idempotence')->count());
    }

    public function test_rejeu_d_une_remise_a_arbitrer_renvoie_la_meme_remise(): void
    {
        $decharge = $this->recueParLePrefet();
        $corps = $this->corps([$this->urnes->id => 30], 'cle-redistribution-0002');
        $inconnu = (string) Str::uuid();

        $premiere = $this->sync($decharge, $corps, $inconnu)->assertCreated();
        $this->sync($decharge, $corps, $inconnu)->assertOk()->assertJsonPath('redistribution.id', $premiere->json('redistribution.id'));
        $this->assertSame(1, Remise::where('etape', 'redistribution')->count());
    }

    public function test_sans_appareil_la_redistribution_suit_la_regle_en_ligne_sur_le_mobilisable(): void
    {
        $decharge = $this->recueParLePrefet();
        $appareil = $this->designer();
        $this->reserver($appareil, $decharge, [$this->urnes->id => 70]);

        $this->sync($decharge, $this->corps([$this->urnes->id => 31]), null)->assertStatus(422);
        $this->assertSame(0, Remise::where('etape', 'redistribution')->count());

        $corps = $this->corps([$this->urnes->id => 30], 'cle-redistribution-0003');
        $this->sync($decharge, $corps, null)->assertCreated()->assertJsonPath('redistribution.statut', 'en_attente')
            ->assertJsonPath('redistribution.appareil', null);
        $this->sync($decharge, $corps, null)->assertOk();
        $this->assertSame(['recu_utilisable' => 100, 'transfere' => 30, 'reliquat' => 70, 'reserve_appareil' => 70, 'mobilisable' => 0], $this->solde($decharge));
    }

    public function test_remise_a_arbitrer_non_comptee_non_declarable_et_absente_des_affectations(): void
    {
        $decharge = $this->recueParLePrefet();
        $sousPrefet = $this->autoriteFictive($this->sousPrefecture1);
        $reponse = $this->sync($decharge, $this->corps([$this->urnes->id => 30]), (string) Str::uuid())->assertCreated();
        $remise = Remise::with('lignes')->findOrFail($reponse->json('redistribution.id'));
        $declarations = Declaration::count();

        $this->assertSame(0, $this->solde($decharge)['transfere']);

        // Le sous-préfet ne la voit pas à faire, et ne peut pas la déclarer.
        $elements = app(AffectationsTerrain::class)->pour($sousPrefet);
        $this->assertSame([], array_values(array_filter($elements, fn ($e) => ($e['remise']['id'] ?? null) === $remise->id)));
        $this->refus(fn () => $this->en($sousPrefet, fn () => app(DeclarationService::class)->declarer(
            $remise, 'compte', ['nom' => 'Sous-préfet', 'qualite' => 'Sous-préfet'],
            [$this->urnes->id => ['recu' => 30, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(),
        )), "Cette remise attend l'arbitrage du responsable : aucune déclaration n'est possible.");
        $this->assertSame($declarations, Declaration::count());

        // Ni du côté de la préfecture (tablette).
        $this->refus(fn () => $this->en($this->prefet, fn () => app(DeclarationService::class)->declarer(
            $remise, 'tablette', ['nom' => 'Préfet', 'qualite' => 'Préfet'],
            [$this->urnes->id => ['recu' => 30, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(),
        )), "Cette remise attend l'arbitrage du responsable : aucune déclaration n'est possible.");
    }

    public function test_une_redistribution_a_verifier_compte_toujours_dans_le_transfere(): void
    {
        $decharge = $this->recueParLePrefet();
        $remise = $this->en($this->prefet, fn () => app(RemiseService::class)->preparerRedistribution($decharge, [$this->urnes->id => 40]));
        $remise->forceFill(['statut' => 'a_verifier'])->save();

        $this->assertSame(40, $this->solde($decharge)['transfere']);
    }

    public function test_arbitrage_valider_une_remise_la_compte_et_la_rend_declarable(): void
    {
        $decharge = $this->recueParLePrefet();
        $remise = Remise::findOrFail($this->sync($decharge, $this->corps([$this->urnes->id => 50]), (string) Str::uuid())->json('redistribution.id'));

        $this->arbitrer($remise, 'valider', 'Tablette prêtée, redistribution confirmée.');

        $this->assertSame('en_attente', $remise->fresh()->statut);
        $this->assertSame(50, $this->solde($decharge)['transfere']);
        $this->assertSame(50, $this->solde($decharge)['mobilisable']);
        $entree = JournalEntree::where('action', 'arbitrage_remise')->where('objet_id', $remise->id)->sole();
        $this->assertSame('Tablette prêtée, redistribution confirmée.', $entree->motif);
        $this->assertSame(['statut' => 'a_arbitrer'], $entree->avant);
        $this->assertSame('en_attente', $entree->apres['statut']);
        $this->assertSame('valider', $entree->apres['decision']);

        $sousPrefet = $this->autoriteFictive($this->sousPrefecture1);
        $this->assertCount(1, array_filter(app(AffectationsTerrain::class)->pour($sousPrefet), fn ($e) => ($e['remise']['id'] ?? null) === $remise->id));
        $this->refus(fn () => $this->arbitrer($remise, 'valider', 'Encore.'), 'Rien à arbitrer pour cette remise.');
    }

    public function test_arbitrage_valider_reverifie_le_plafond_sur_le_mobilisable_du_moment(): void
    {
        $decharge = $this->recueParLePrefet();
        $remise = Remise::findOrFail($this->sync($decharge, $this->corps([$this->urnes->id => 60]), (string) Str::uuid())->json('redistribution.id'));
        // Entre-temps, le préfet redistribue 50 en ligne : il n'en reste que 50 de mobilisables.
        $this->en($this->prefet, fn () => app(RemiseService::class)->preparerRedistribution($decharge, [$this->urnes->id => 50]));

        $this->refus(fn () => $this->arbitrer($remise, 'valider', 'Je valide.'),
            'Redistribution supérieure au mobilisable du préfet pour Urnes : mobilisable 50, demandé 60.');
        $this->assertSame('a_arbitrer', $remise->fresh()->statut);
        $this->assertSame(50, $this->solde($decharge)['transfere']);
    }

    public function test_arbitrage_rejeter_annule_la_remise_avec_son_motif(): void
    {
        $decharge = $this->recueParLePrefet();
        $remise = Remise::findOrFail($this->sync($decharge, $this->corps([$this->urnes->id => 60]), (string) Str::uuid())->json('redistribution.id'));

        $this->arbitrer($remise, 'rejeter', 'Appareil non reconnu.');

        $this->assertSame('annulee', $remise->fresh()->statut);
        $this->assertSame('Appareil non reconnu.', $remise->fresh()->motif_annulation);
        $this->assertSame(0, $this->solde($decharge)['transfere']);
        $this->assertSame('rejeter', JournalEntree::where('action', 'arbitrage_remise')->where('objet_id', $remise->id)->sole()->apres['decision']);
    }

    public function test_arbitrage_de_remise_droits_et_entrees(): void
    {
        $decharge = $this->recueParLePrefet();
        $remise = Remise::findOrFail($this->sync($decharge, $this->corps([$this->urnes->id => 60]), (string) Str::uuid())->json('redistribution.id'));
        $gestionnaire = User::factory()->create(['role' => 'logistique_gestionnaire']);

        $this->refus(fn () => $this->en($gestionnaire, fn () => app(ArbitrageService::class)->arbitrerRemise($remise, 'valider', 'Motif.')),
            'Seul un responsable logistique peut arbitrer une remise.');
        $this->expectException(InvalidArgumentException::class);
        $this->arbitrer($remise, 'peut-etre', 'Motif.');
    }

    public function test_arbitrage_sans_motif_refuse(): void
    {
        $decharge = $this->recueParLePrefet();
        $remise = Remise::findOrFail($this->sync($decharge, $this->corps([$this->urnes->id => 60]), (string) Str::uuid())->json('redistribution.id'));

        $this->expectException(InvalidArgumentException::class);
        $this->arbitrer($remise, 'rejeter', '  ');
    }

    public function test_arbitrage_valider_refuse_une_remise_qui_n_est_pas_a_arbitrer(): void
    {
        $decharge = $this->recueParLePrefet();
        $remise = $this->en($this->prefet, fn () => app(RemiseService::class)->preparerRedistribution($decharge, [$this->urnes->id => 40]));

        $this->refus(fn () => $this->arbitrer($remise, 'valider', 'Motif.'), 'Rien à arbitrer pour cette remise.');
    }

    public function test_delegue_prepare_et_annule_une_redistribution_en_ligne(): void
    {
        $decharge = $this->recueParLePrefet();
        $adjoint = $this->adjoint('sous_prefet');
        $delegation = Delegation::sole();

        $remise = $this->en($adjoint, fn () => app(RemiseService::class)->preparerRedistribution($decharge, [$this->urnes->id => 40]));

        $this->assertSame($adjoint->id, $remise->preparee_par);
        $this->assertSame($delegation->id, JournalEntree::where('action', 'preparation_redistribution')->where('objet_id', $remise->id)->sole()->apres['delegation_id']);

        $this->en($adjoint, fn () => app(RemiseService::class)->annulerRedistribution($remise, 'Erreur de destinataire.'));
        $this->assertSame('annulee', $remise->fresh()->statut);

        // Un compte sans délégation reste hors périmètre.
        $autre = User::factory()->create(['role' => 'chef_poste']);
        $this->refus(fn () => $this->en($autre, fn () => app(RemiseService::class)->preparerRedistribution($decharge, [$this->urnes->id => 10])), 'Redistribution hors de votre périmètre.');
    }

    public function test_delegue_signe_le_depart_de_la_redistribution_et_la_delegation_est_consignee(): void
    {
        $decharge = $this->recueParLePrefet();
        $adjoint = $this->adjoint('chef_poste');
        $remise = $this->en($this->prefet, fn () => app(RemiseService::class)->preparerRedistribution($decharge, [$this->urnes->id => 40]))->load('lignes');

        $declaration = $this->en($adjoint, fn () => app(DeclarationService::class)->declarer(
            $remise, 'tablette', ['nom' => 'Adjoint', 'qualite' => 'Chef de poste'],
            [$this->urnes->id => ['recu' => 40, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(),
        ));

        $this->assertSame($adjoint->id, $declaration->user_id);
        $entree = JournalEntree::where('action', 'declaration_reception')->where('objet_id', $declaration->id)->sole();
        $this->assertSame(Delegation::sole()->id, $entree->apres['delegation_depart_id']);

        // Le titulaire ne laisse aucune délégation de départ.
        $autre = $this->en($this->prefet, fn () => app(RemiseService::class)->preparerRedistribution($decharge, [$this->urnes->id => 10]))->load('lignes');
        $declaration = $this->en($this->prefet, fn () => app(DeclarationService::class)->declarer(
            $autre, 'tablette', ['nom' => 'Préfet', 'qualite' => 'Préfet'],
            [$this->urnes->id => ['recu' => 10, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(),
        ));
        $this->assertNull(JournalEntree::where('action', 'declaration_reception')->where('objet_id', $declaration->id)->sole()->apres['delegation_depart_id']);

        // Un compte étranger à la préfecture ne signe pas le départ.
        $etranger = User::factory()->create(['role' => 'chef_poste']);
        $this->refus(fn () => $this->en($etranger, fn () => app(DeclarationService::class)->declarer(
            $autre, 'tablette', ['nom' => 'X', 'qualite' => 'Chef de poste'],
            [$this->urnes->id => ['recu' => 10, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(),
        )), 'Déclaration hors de votre périmètre.');
    }

    public function test_delegue_voit_les_preparations_et_les_departs_a_signer(): void
    {
        $decharge = $this->recueParLePrefet();
        $adjoint = $this->adjoint('chef_poste');

        $elements = app(AffectationsTerrain::class)->pour($adjoint);
        $preparations = array_values(array_filter($elements, fn ($e) => $e['remise'] === null));
        $this->assertCount(1, $preparations);
        $this->assertSame($decharge->id, $preparations[0]['decharge']['id']);
        $this->assertSame([['materiel_id' => $this->urnes->id, 'libelle' => 'Urnes', 'quantite' => 100]], $preparations[0]['lignes']);

        $remise = $this->en($this->prefet, fn () => app(RemiseService::class)->preparerRedistribution($decharge, [$this->urnes->id => 40]));
        $departs = array_values(array_filter(app(AffectationsTerrain::class)->pour($adjoint), fn ($e) => ($e['remise']['id'] ?? null) === $remise->id));
        $this->assertCount(1, $departs);
    }

    public function test_delegue_reserve_pour_l_appareil_par_l_api(): void
    {
        $decharge = $this->recueParLePrefet();
        $adjoint = $this->adjoint('sous_prefet');
        $appareil = $this->designer(null, 'Tablette', null, $adjoint);

        $this->api('POST', $decharge, 'reserve', $appareil->identifiant, [], $adjoint)->assertOk()
            ->assertJsonPath('reserve.appareil', $appareil->identifiant)
            ->assertJsonPath('reserve.decharge_id', $decharge->id)
            ->assertJsonPath('reserve.lignes.0.materiel_id', $this->urnes->id)
            ->assertJsonPath('reserve.lignes.0.quantite', 100);
        $this->assertSame(100, $this->solde($decharge)['reserve_appareil']);
    }

    public function test_api_reserve_exige_l_appareil_designe(): void
    {
        $decharge = $this->recueParLePrefet();
        $appareil = $this->designer();
        $ancien = $this->designer();
        $autre = Destinataire::create(['type' => 'prefecture', 'libelle' => 'Préfecture de Louga', 'departement_id' => 11]);
        $deLouga = $this->designer(null, 'Tablette de Louga', $autre);

        $this->api('POST', $decharge, 'reserve', null)->assertForbidden()->assertJson(['message' => 'Appareil non désigné pour cette préfecture.']);
        $this->api('POST', $decharge, 'reserve', (string) Str::uuid())->assertForbidden();
        $this->api('POST', $decharge, 'reserve', $appareil->identifiant)->assertForbidden(); // révoqué
        $this->api('POST', $decharge, 'reserve', $deLouga->identifiant)->assertForbidden();
        $this->api('POST', $decharge, 'reserve', 'pas un identifiant')->assertStatus(422);
        $this->assertSame(0, ReserveAppareil::count());

        $this->api('POST', $decharge, 'reserve', $ancien->identifiant, ['lignes' => [['materiel_id' => $this->urnes->id, 'quantite' => 60]]])->assertOk()
            ->assertJsonPath('reserve.lignes.0.quantite', 60)
            ->assertJsonPath('reserve.lignes.0.libelle', 'Urnes');
        $this->api('POST', $decharge, 'reserve', $ancien->identifiant, ['lignes' => [['materiel_id' => $this->urnes->id, 'quantite' => 101]]])
            ->assertStatus(422)->assertJson(['message' => 'Réserve supérieure au mobilisable pour Urnes : mobilisable 100, demandé 101.']);
        $this->api('GET', $decharge, 'reserve', $ancien->identifiant)->assertOk()->assertJsonPath('reserve.lignes.0.quantite', 60);
        $this->api('GET', $decharge, 'reserve', null)->assertForbidden();
    }

    public function test_api_hors_perimetre_est_inconnu(): void
    {
        $decharge = $this->recueParLePrefet();
        $appareil = $this->designer();
        $sousPrefet = $this->autoriteFictive($this->sousPrefecture1);
        $autrePrefet = User::factory()->create(['role' => 'prefet']);

        foreach ([$sousPrefet, $autrePrefet] as $compte) {
            $this->api('POST', $decharge, 'reserve', $appareil->identifiant, [], $compte)->assertNotFound();
            $this->sync($decharge, $this->corps([$this->urnes->id => 10]), $appareil->identifiant, $compte)->assertNotFound();
        }
        $this->assertSame(0, Remise::where('etape', 'redistribution')->count());
        $this->assertSame(0, ReserveAppareil::count());
    }

    public function test_api_sans_jeton_est_refusee(): void
    {
        $decharge = $this->recueParLePrefet();
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/terrain/decharges/{$decharge->id}/reserve")->assertUnauthorized();
        $this->postJson("/api/terrain/decharges/{$decharge->id}/reserve")->assertUnauthorized();
        $this->deleteJson("/api/terrain/decharges/{$decharge->id}/reserve")->assertUnauthorized();
        $this->postJson("/api/terrain/decharges/{$decharge->id}/redistributions", $this->corps([$this->urnes->id => 1]))->assertUnauthorized();
        $this->getJson('/api/terrain/appareil')->assertUnauthorized();
    }

    public function test_api_redistribution_valide_son_corps(): void
    {
        $decharge = $this->recueParLePrefet();
        $appareil = $this->designer();

        foreach ([
            ['cle' => 'court'],
            ['horodatage_terminal' => 'hier'],
            ['lignes' => []],
            ['lignes' => [['materiel_id' => $this->urnes->id, 'quantite' => -1]]],
            ['lignes' => [['materiel_id' => $this->urnes->id, 'quantite' => 5], ['materiel_id' => $this->urnes->id, 'quantite' => 5]]],
        ] as $surcharge) {
            $this->sync($decharge, array_replace($this->corps([$this->urnes->id => 5]), $surcharge), $appareil->identifiant)->assertStatus(422);
        }
        // Un article absent de la décharge est une erreur de saisie, pas une remise à arbitrer.
        $this->sync($decharge, $this->corps([$this->enveloppes->id => 5]), $appareil->identifiant)->assertStatus(422);
        $this->sync($decharge, $this->corps([$this->urnes->id => 0]), $appareil->identifiant)->assertStatus(422)->assertJson(['message' => 'Rien à redistribuer.']);
        $this->assertSame(0, Remise::where('etape', 'redistribution')->count());
    }

    public function test_api_etat_de_l_appareil(): void
    {
        $revoque = $this->designer(null, 'Tablette du préfet');
        $actif = $this->designer();

        $this->terrain($this->prefet, $this->tablettes[$actif->identifiant]);
        $this->withoutHeader('X-Appareil')->getJson('/api/terrain/appareil')->assertOk()
            ->assertJsonPath('designe', true)
            ->assertJsonPath('appareil.identifiant', $actif->identifiant)
            ->assertJsonPath('appareil.prefecture.id', $this->prefecture->id);
        $this->withHeaders(['X-Appareil' => $actif->identifiant])->getJson('/api/terrain/appareil')->assertOk()->assertJsonPath('designe', true);
        // L'en-tête, s'il est envoyé, doit être celui de l'appareil du jeton.
        $this->withHeaders(['X-Appareil' => $revoque->identifiant])->getJson('/api/terrain/appareil')->assertOk()
            ->assertJsonPath('designe', false)->assertJsonPath('appareil', null);

        // Un jeton ordinaire, même ouvert avec l'UUID de la tablette, n'est pas l'appareil.
        $this->terrain($this->prefet, $this->jeton($this->prefet, $actif->identifiant));
        $this->withHeaders(['X-Appareil' => $actif->identifiant])->getJson('/api/terrain/appareil')->assertOk()->assertJsonPath('designe', false);
        $this->terrain($this->prefet, null);
        $this->withoutHeader('X-Appareil')->getJson('/api/terrain/appareil')->assertOk()->assertJsonPath('designe', false);
    }

    public function test_affectations_de_preparation_excluent_la_reserve(): void
    {
        $decharge = $this->recueParLePrefet();
        $appareil = $this->designer();
        $quantites = function () {
            $preparations = array_values(array_filter(app(AffectationsTerrain::class)->pour($this->prefet), fn ($e) => $e['remise'] === null));

            return $preparations === [] ? null : array_column($preparations[0]['lignes'], 'quantite', 'materiel_id');
        };

        $this->assertSame([$this->urnes->id => 100], $quantites());
        $this->reserver($appareil, $decharge, [$this->urnes->id => 60]);
        $this->assertSame([$this->urnes->id => 40], $quantites());
        $this->reserver($appareil, $decharge);
        $this->assertNull($quantites());
    }

    public function test_reserve_refusee_sans_reception_retenue_ou_sur_un_cycle_clos(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $appareil = $this->designer();

        $this->refus(fn () => $this->reserver($appareil, $decharge), "Le préfet n'a pas encore de réception retenue pour cette décharge.");

        $this->deposerRecue($decharge, [$this->urnes->id => 100]);
        $this->cycle->forceFill(['statut' => 'clos'])->save();
        $this->refus(fn () => $this->reserver($appareil, $decharge->refresh()), 'Cycle clôturé : réserve impossible.');
    }

    public function test_synchronisation_sur_un_cycle_clos_est_conservee_a_arbitrer(): void
    {
        $decharge = $this->recueParLePrefet();
        $appareil = $this->designer();
        $this->reserver($appareil, $decharge);
        $this->cycle->forceFill(['statut' => 'clos'])->save();

        $reponse = $this->sync($decharge, $this->corps([$this->urnes->id => 30]), $appareil->identifiant)->assertCreated();

        $this->assertSame('a_arbitrer', $reponse->json('redistribution.statut'));
        $this->assertSame('Cycle clôturé : redistribution impossible.', $reponse->json('redistribution.raison'));
        $this->assertSame(0, $this->solde($decharge)['transfere']);
        $remise = Remise::findOrFail($reponse->json('redistribution.id'));
        $this->refus(fn () => $this->arbitrer($remise, 'valider', 'Motif.'), 'Cycle clôturé : validation impossible.');
        $this->arbitrer($remise, 'rejeter', 'Cycle clos.');
        $this->assertSame('annulee', $remise->fresh()->statut);
    }

    public function test_page_terrain_du_delegue_redistribue_et_montre_la_reserve(): void
    {
        $decharge = $this->recueParLePrefet();
        $appareil = $this->designer();
        $adjoint = $this->adjoint('chef_poste');
        $this->reserver($appareil, $decharge, [$this->urnes->id => 60]);

        $this->actingAs($adjoint)->get(route('terrain.decharges.show', $decharge))->assertOk()
            ->assertSee(route('terrain.redistributions.store', $decharge), false)
            ->assertSee("Réservé à l'appareil", false);
        $this->post(route('terrain.redistributions.store', $decharge), ['lignes' => [$this->urnes->id => 41]])
            ->assertSessionHas('error');
        $this->post(route('terrain.redistributions.store', $decharge), ['lignes' => [$this->urnes->id => 40]])
            ->assertSessionHas('success');

        $this->assertSame(['recu_utilisable' => 100, 'transfere' => 40, 'reliquat' => 60, 'reserve_appareil' => 60, 'mobilisable' => 0], $this->solde($decharge));
    }

    public function test_ecritures_centrales_reservees_aux_responsables(): void
    {
        foreach (['logistique.appareils.designer', 'logistique.appareils.revoquer', 'logistique.remises.arbitrage'] as $nom) {
            $this->assertContains('can:logistique-administrer', Route::getRoutes()->getByName($nom)->gatherMiddleware(), $nom);
        }
        $this->assertContains('can:logistique-gerer', Route::getRoutes()->getByName('logistique.appareils.index')->gatherMiddleware());
    }

    public function test_ecran_central_des_appareils_et_arbitrage_des_remises(): void
    {
        $decharge = $this->recueParLePrefet();
        $remise = Remise::findOrFail($this->sync($decharge, $this->corps([$this->urnes->id => 60]), (string) Str::uuid())->json('redistribution.id'));
        $gestionnaire = User::factory()->create(['role' => 'logistique_gestionnaire']);
        $identifiant = (string) Str::uuid();

        $this->actingAs($gestionnaire);
        $this->get(route('logistique.appareils.index'))->assertOk()->assertSee($this->prefecture->libelle)->assertSee($decharge->numero);
        $this->post(route('logistique.appareils.designer', $this->prefecture), ['identifiant' => $identifiant, 'nom' => 'Tablette'])->assertForbidden();
        $this->post(route('logistique.remises.arbitrage', $remise), ['decision' => 'valider', 'motif' => 'Ok'])->assertForbidden();

        $this->actingAs($this->responsable);
        // La tablette doit d'abord s'être connectée avec un compte de la préfecture.
        $this->post(route('logistique.appareils.designer', $this->prefecture), ['identifiant' => $identifiant, 'nom' => 'Tablette'])
            ->assertSessionHas('error', "Connectez d'abord la tablette avec un compte de la préfecture.");
        $this->jeton($this->prefet, $identifiant);
        $this->post(route('logistique.appareils.designer', $this->prefecture), ['identifiant' => $identifiant, 'nom' => 'Tablette'])
            ->assertRedirect()->assertSessionHas('success');
        $appareil = Appareil::where('identifiant', $identifiant)->sole();
        $this->get(route('logistique.appareils.index'))->assertOk()->assertSee($identifiant)->assertSee('Tablette');

        $this->post(route('logistique.appareils.designer', $this->prefecture), ['identifiant' => 'faux', 'nom' => 'Tablette'])->assertSessionHasErrors('identifiant');
        $this->post(route('logistique.remises.arbitrage', $remise), ['decision' => 'valider'])->assertSessionHasErrors('motif');
        $this->post(route('logistique.remises.arbitrage', $remise), ['decision' => 'rejeter', 'motif' => 'Doublon'])->assertSessionHas('success');
        $this->assertSame('annulee', $remise->fresh()->statut);

        $this->post(route('logistique.appareils.revoquer', $appareil), ['motif' => 'Tablette perdue'])->assertSessionHas('success');
        $this->assertFalse($appareil->fresh()->actif);
    }

    public function test_un_jeton_d_une_autre_autorite_ne_fait_pas_de_l_en_tete_l_appareil_designe(): void
    {
        $decharge = $this->recueParLePrefet();
        $adjoint = $this->adjoint('sous_prefet');
        $appareil = $this->designer(null, 'Tablette', null, $adjoint);
        $this->reserver($appareil, $decharge, [$this->urnes->id => 60], $this->prefet);
        $epingle = $this->tablettes[$appareil->identifiant];
        $this->assertSame($epingle->getKey(), $appareil->fresh()->jeton_id);

        // Le préfet, ou un autre jeton ouvert avec l'UUID de la tablette (connexion qui recopie l'identifiant), ne sont pas l'appareil.
        $this->api('POST', $decharge, 'reserve', $appareil->identifiant, [], $this->prefet)->assertForbidden();
        $this->api('POST', $decharge, 'reserve', $appareil->identifiant, [], $this->prefet, null)->assertForbidden();
        $this->api('GET', $decharge, 'reserve', $appareil->identifiant, [], $adjoint, null)->assertForbidden();
        $this->api('GET', $decharge, 'reserve', $appareil->identifiant, [], $adjoint, $this->jeton($adjoint, $appareil->identifiant))->assertForbidden();
        $this->api('DELETE', $decharge, 'reserve', $appareil->identifiant, [], $this->prefet)->assertForbidden();
        $this->assertSame(60, $this->solde($decharge)['reserve_appareil']);

        // Même chose à la synchronisation : l'identité n'étant pas établie, la remise est à arbitrer, sans toucher la réserve.
        foreach ([$this->prefet, $adjoint] as $compte) {
            $reponse = $this->sync($decharge, $this->corps([$this->urnes->id => 10]), $appareil->identifiant, $compte, $this->jeton($compte, $appareil->identifiant))->assertCreated();
            $this->assertSame('a_arbitrer', $reponse->json('redistribution.statut'));
            $this->assertSame('Appareil non désigné pour cette préfecture.', $reponse->json('redistribution.raison'));
            $this->assertNull(Remise::findOrFail($reponse->json('redistribution.id'))->appareil_id);
        }
        $this->assertSame(['transfere' => 0, 'reserve_appareil' => 60], array_intersect_key($this->solde($decharge), ['transfere' => 0, 'reserve_appareil' => 0]));

        // Le jeton épinglé (en-tête en majuscules compris) est l'appareil, ici ouvert par un adjoint.
        $this->api('GET', $decharge, 'reserve', strtoupper($appareil->identifiant), [], $adjoint, $epingle)->assertOk()
            ->assertJsonPath('reserve.lignes.0.quantite', 60);
        $this->sync($decharge, $this->corps([$this->urnes->id => 10]), strtoupper($appareil->identifiant), $adjoint, $epingle)
            ->assertCreated()->assertJsonPath('redistribution.statut', 'en_attente');
        // L'en-tête, s'il est envoyé, doit être celui de l'appareil du jeton.
        $this->api('GET', $decharge, 'reserve', (string) Str::uuid(), [], $adjoint, $epingle)->assertForbidden();
    }

    public function test_connexion_avec_l_uuid_de_la_tablette_apres_la_designation_ne_la_remplace_pas(): void
    {
        $decharge = $this->recueParLePrefet();
        $appareil = $this->designer();
        $this->reserver($appareil, $decharge, [$this->urnes->id => 60]);

        // Une vraie connexion, avec l'UUID de la tablette : le jeton est lié à l'UUID mais n'est pas épinglé.
        $this->postJson('/api/terrain/jetons', ['email' => $this->prefet->email, 'password' => 'password', 'appareil' => $appareil->identifiant])->assertCreated();
        $nouveau = $this->prefet->tokens()->latest('id')->firstOrFail();
        $this->assertSame($appareil->identifiant, $nouveau->appareil_identifiant);
        $this->api('GET', $decharge, 'reserve', $appareil->identifiant, [], $this->prefet, $nouveau)->assertForbidden();
        $this->sync($decharge, $this->corps([$this->urnes->id => 10]), $appareil->identifiant, $this->prefet, $nouveau)
            ->assertCreated()->assertJsonPath('redistribution.statut', 'a_arbitrer');

        // Il reste à redésigner : le nouveau jeton est alors épinglé (l'ancien ne l'est plus).
        $this->en($this->responsable, fn () => app(AppareilService::class)->designer($this->prefecture, $appareil->identifiant, 'Tablette'));
        $this->assertSame($nouveau->getKey(), $appareil->fresh()->jeton_id);
        $this->assertSame(1, JournalEntree::where('action', 'epinglage_appareil')->where('objet_id', $appareil->id)->count());
        $this->api('GET', $decharge, 'reserve', $appareil->identifiant, [], $this->prefet, $nouveau)->assertOk();
        $this->api('GET', $decharge, 'reserve', $appareil->identifiant, [], $this->prefet, $this->tablettes[$appareil->identifiant])->assertForbidden();
    }

    public function test_jeton_epingle_supprime_ou_expire_l_appareil_n_est_plus_reconnu(): void
    {
        $decharge = $this->recueParLePrefet();
        $appareil = $this->designer();
        $epingle = $this->tablettes[$appareil->identifiant];
        $this->api('GET', $decharge, 'reserve', $appareil->identifiant)->assertOk();

        $epingle->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->assertFalse($appareil->fresh()->estReconnaissable());
        $this->api('GET', $decharge, 'reserve', $appareil->identifiant, [], null, $epingle->fresh())->assertForbidden();
        $this->actingAs($this->responsable)->get(route('logistique.appareils.index'))->assertSee('Tablette non reconnue');

        $epingle->forceFill(['expires_at' => null])->save();
        $this->actingAs($this->responsable)->get(route('logistique.appareils.index'))->assertSee('Tablette reconnue')->assertDontSee('Tablette non reconnue');

        $epingle->delete();
        $this->assertNull($appareil->fresh()->jeton_id);
        $this->assertTrue($appareil->fresh()->actif);
        $this->actingAs($this->responsable)->get(route('logistique.appareils.index'))->assertSee('Tablette non reconnue');
        $this->sync($decharge, $this->corps([$this->urnes->id => 10]), $appareil->identifiant, $this->prefet, $this->jeton($this->prefet, $appareil->identifiant))
            ->assertCreated()->assertJsonPath('redistribution.statut', 'a_arbitrer');
    }

    public function test_designation_exige_une_tablette_connectee_avec_un_compte_de_la_prefecture(): void
    {
        $identifiant = (string) Str::uuid();
        $refus = "Connectez d'abord la tablette avec un compte de la préfecture.";

        // Aucune connexion avec cet UUID.
        $this->refus(fn () => app(AppareilService::class)->designer($this->prefecture, $identifiant, 'Tablette'), $refus);

        // Connexion d'un compte qui n'est pas l'autorité de la préfecture, ou d'une autorité d'une autre préfecture.
        $this->jeton($this->autoriteFictive($this->sousPrefecture1), $identifiant);
        $this->jeton(User::factory()->create(['role' => 'chef_poste']), $identifiant);
        $this->jeton(User::factory()->create(['role' => 'prefet']), $identifiant);
        $this->refus(fn () => app(AppareilService::class)->designer($this->prefecture, $identifiant, 'Tablette'), $refus);

        // Jeton expiré de l'autorité : ignoré.
        $this->jeton($this->prefet, $identifiant)->forceFill(['expires_at' => now()->subDay()])->save();
        $this->refus(fn () => app(AppareilService::class)->designer($this->prefecture, $identifiant, 'Tablette'), $refus);
        $this->assertSame(0, Appareil::count());

        // Le plus récent jeton valide d'un compte actif de la préfecture est épinglé (ici, celui d'un adjoint habilité).
        $adjoint = $this->adjoint('chef_poste');
        $this->jeton($this->prefet, $identifiant);
        $recent = $this->jeton($adjoint, $identifiant);
        $appareil = app(AppareilService::class)->designer($this->prefecture, $identifiant, 'Tablette');
        $this->assertSame($recent->getKey(), $appareil->jeton_id);
    }

    public function test_jeton_cree_pour_un_appareil_porte_son_identifiant(): void
    {
        $uuid = (string) Str::uuid();
        $this->postJson('/api/terrain/jetons', ['email' => $this->prefet->email, 'password' => 'password', 'appareil' => strtoupper($uuid)])
            ->assertCreated();
        $jeton = $this->prefet->tokens()->latest('id')->firstOrFail();
        $this->assertSame($uuid, $jeton->appareil_identifiant);

        $this->postJson('/api/terrain/jetons', ['email' => $this->prefet->email, 'password' => 'password', 'appareil' => 'Tablette 1'])->assertCreated();
        $this->assertNull($this->prefet->tokens()->latest('id')->firstOrFail()->appareil_identifiant);
    }

    private function livraisonDe(Decharge $decharge): Remise
    {
        return Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->with('lignes')->firstOrFail();
    }

    public function test_contestation_apres_reserve_laisse_la_reception_retenue_puis_l_arbitrage_exige_la_liberation(): void
    {
        $decharge = $this->recueParLePrefet();
        $livraison = $this->livraisonDe($decharge);
        $declaration = Declaration::where('remise_id', $livraison->id)->sole();
        $appareil = $this->designer();
        $this->reserver($appareil, $decharge);

        $this->en($this->prefet, fn () => app(AvisReceptionService::class)->contester($declaration, 'Ce n\'est pas moi.'));

        $declaration->refresh();
        $this->assertSame(['retenue', 'contestee'], [$declaration->statut, $declaration->contestation]);
        $this->assertSame('recue', $livraison->fresh()->statut);
        $solde = $this->solde($decharge);
        $this->assertLessThanOrEqual($solde['recu_utilisable'], $solde['transfere'] + $solde['reserve_appareil']);

        $refus = 'Réception utilisée en aval : annulez la redistribution, libérez la réserve (ou traitez l\'exemplaire ou le remplacement) avant d\'arbitrer.';
        $this->refus(fn () => $this->en($this->responsable, fn () => app(ArbitrageService::class)->rejeter($declaration, 'Non.')), $refus);
        $this->assertSame(100, $this->solde($decharge)['recu_utilisable']);

        $this->en($this->prefet, fn () => app(AppareilService::class)->liberer($appareil, $decharge));
        $this->en($this->responsable, fn () => app(ArbitrageService::class)->rejeter($declaration, 'Non.'));
        $this->assertSame('ecartee', $declaration->fresh()->statut);
        $this->assertSame(0, $this->solde($decharge)['recu_utilisable']);
    }

    public function test_declaration_divergente_apres_reserve_ne_defait_pas_la_retenue(): void
    {
        $decharge = $this->recueParLePrefet();
        $livraison = $this->livraisonDe($decharge);
        $retenue = Declaration::where('remise_id', $livraison->id)->sole();
        $appareil = $this->designer();
        $this->reserver($appareil, $decharge);

        $autre = $this->en($decharge->agent, fn () => app(DeclarationService::class)->declarer(
            $livraison, 'tablette', ['nom' => 'Agent', 'qualite' => 'Agent de transport'],
            [$this->urnes->id => ['recu' => 90, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(),
        ));

        $this->assertSame('retenue', $retenue->fresh()->statut);
        $this->assertSame('a_verifier', $autre->fresh()->statut);
        $this->assertContains('divergence', $autre->fresh()->anomalies);
        $this->assertSame('a_verifier', $livraison->fresh()->statut);
        $this->assertSame(['recu_utilisable' => 100, 'transfere' => 0, 'reliquat' => 100, 'reserve_appareil' => 100, 'mobilisable' => 0], $this->solde($decharge));

        // Retenir l'autre déclaration défairait la réception sur laquelle repose la réserve : refusé jusqu'à la libération.
        $refus = 'Réception utilisée en aval : annulez la redistribution, libérez la réserve (ou traitez l\'exemplaire ou le remplacement) avant d\'arbitrer.';
        $this->refus(fn () => $this->en($this->responsable, fn () => app(ArbitrageService::class)->retenir($autre, 'Agent.')), $refus);
        $this->assertSame('retenue', $retenue->fresh()->statut);

        $this->en($this->prefet, fn () => app(AppareilService::class)->liberer($appareil, $decharge));
        $this->en($this->responsable, fn () => app(ArbitrageService::class)->retenir($autre, 'Agent.'));
        $this->assertSame(['ecartee', 'retenue'], [$retenue->fresh()->statut, $autre->fresh()->statut]);
        $this->assertSame(90, $this->solde($decharge)['recu_utilisable']);
    }

    public function test_declaration_identique_apres_reserve_confirme_la_retenue(): void
    {
        $decharge = $this->recueParLePrefet();
        $livraison = $this->livraisonDe($decharge);
        $retenue = Declaration::where('remise_id', $livraison->id)->sole();
        $this->reserver($this->designer(), $decharge);

        // Une seconde déclaration saine et identique (écrite directement, sans anomalie d'identité).
        $confirmation = new Declaration([
            'remise_id' => $livraison->id, 'mode' => 'tablette', 'signataire_nom' => 'Agent', 'signataire_qualite' => 'Agent de transport',
            'user_id' => $decharge->agent_id, 'decharge_id' => $decharge->id, 'cle_idempotence' => (string) Str::uuid(), 'empreinte_contenu' => str_repeat('a', 64),
        ]);
        $confirmation->forceFill(['statut' => 'valide'])->save();
        DeclarationLigne::create(['declaration_id' => $confirmation->id, 'materiel_id' => $this->urnes->id, 'recu' => 100, 'defectueux' => 0]);

        $this->en($this->responsable, fn () => app(DeclarationService::class)->rapprocher($livraison));

        $this->assertSame('retenue', $retenue->fresh()->statut);
        $this->assertSame('concordante', $confirmation->fresh()->statut);
        $this->assertSame('recue', $livraison->fresh()->statut);
    }

    public function test_remise_a_arbitrer_masquee_au_cote_arrivee_sur_la_page_terrain(): void
    {
        $decharge = $this->recueParLePrefet();
        $sousPrefet = $this->autoriteFictive($this->sousPrefecture1);
        $this->sync($decharge, $this->corps([$this->urnes->id => 30]), (string) Str::uuid())->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->actingAs($sousPrefet)->get(route('terrain.decharges.show', $decharge))->assertOk()->assertDontSee('À arbitrer');
        $this->actingAs($this->prefet)->get(route('terrain.decharges.show', $decharge))->assertOk()->assertSee('À arbitrer');
    }

    public function test_a_arbitrer_est_exclue_des_indicateurs_du_document_et_de_l_emission(): void
    {
        $decharge = $this->recueParLePrefet();
        $remiseArbitrer = Remise::findOrFail($this->sync($decharge, $this->corps([$this->urnes->id => 30]), (string) Str::uuid())->json('redistribution.id'));
        $livraison = $this->livraisonDe($decharge);

        // IndicateurService::chezIntermediaire : toujours 100 chez le préfet, rien en transit.
        $ligne = app(IndicateurService::class)->parDestinataire($this->cycle)
            ->first(fn ($l) => $l['destinataire']->id === $this->sousPrefecture1->id && $l['materiel']->id === $this->urnes->id);
        $this->assertSame([100, 0], [$ligne['chez_intermediaire'], $ligne['en_transit']]);

        // DonneesDecharge::reception : la remise à arbitrer n'y figure pas.
        $ids = array_column(DonneesDecharge::reception($decharge)['remises'], 'id');
        $this->assertNotContains($remiseArbitrer->id, $ids);

        // AvisReceptionService::receptionUtilisee : une redistribution à arbitrer n'est pas un usage en aval.
        $this->assertFalse(app(AvisReceptionService::class)->receptionUtilisee($livraison, $decharge));

        // DechargePdfService::receptionEmissible : une redistribution à arbitrer est « en cours ».
        $reste = $this->recueParLePrefetEntierementRedistribuee();
        $this->assertTrue(app(DechargePdfService::class)->receptionEmissible($reste));
        $this->sync($reste, $this->corps([$this->isoloirs->id => 1]), (string) Str::uuid())->assertCreated();
        $this->assertFalse(app(DechargePdfService::class)->receptionEmissible($reste->refresh()));
    }

    /** Une seconde décharge via le préfet (isoloirs), entièrement redistribuée et reçue par le sous-préfet. */
    private function recueParLePrefetEntierementRedistribuee(): Decharge
    {
        $this->actingAs($this->responsable);
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->isoloirs->id => 50]);
        $this->deposerRecue($decharge, [$this->isoloirs->id => 50]);
        $redistribution = $this->en($this->prefet, fn () => app(RemiseService::class)->preparerRedistribution($decharge, [$this->isoloirs->id => 50]));
        $this->en($this->autoriteFictive($this->sousPrefecture1), fn () => app(DeclarationService::class)->declarer(
            $redistribution->load('lignes'), 'compte', ['nom' => 'Sous-préfet', 'qualite' => 'Sous-préfet'],
            [$this->isoloirs->id => ['recu' => 50, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(),
        ));

        return $decharge->refresh();
    }

    public function test_le_signataire_du_depart_ne_constate_pas_l_arrivee(): void
    {
        $decharge = $this->recueParLePrefet();
        $sousPrefet = $this->autoriteFictive($this->sousPrefecture1);
        // L'adjoint est habilité à la préfecture et au sous-préfet.
        $adjoint = $this->adjoint('chef_poste');
        $this->en($sousPrefet, fn () => app(DelegationService::class)->deleguer($this->sousPrefecture1, $adjoint, now(), null, 'Adjoint des deux'));
        $refus = "Le signataire du départ ne peut pas constater l'arrivée.";
        $lignes = [$this->urnes->id => ['recu' => 40, 'defectueux' => 0, 'observation' => null]];
        $signataire = ['nom' => 'Adjoint', 'qualite' => 'Chef de poste'];

        // Départ signé à la tablette par l'adjoint, puis arrivée au compte par le même adjoint : refusée.
        $remise = $this->en($this->prefet, fn () => app(RemiseService::class)->preparerRedistribution($decharge, [$this->urnes->id => 40]))->load('lignes');
        $this->en($adjoint, fn () => app(DeclarationService::class)->declarer($remise, 'tablette', $signataire, $lignes, (string) Str::uuid()));
        $this->refus(fn () => $this->en($adjoint, fn () => app(DeclarationService::class)->declarer($remise, 'compte', $signataire, $lignes, (string) Str::uuid())), $refus);

        // Le sous-préfet, lui, constate l'arrivée.
        $this->en($sousPrefet, fn () => app(DeclarationService::class)->declarer($remise, 'compte', ['nom' => 'Sous-préfet', 'qualite' => 'Sous-préfet'], $lignes, (string) Str::uuid()));

        // Dans l'autre sens : arrivée au compte par l'adjoint, puis départ à la tablette par le même adjoint : refusé.
        $autre = $this->en($this->prefet, fn () => app(RemiseService::class)->preparerRedistribution($decharge, [$this->urnes->id => 10]))->load('lignes');
        $lignesAutre = [$this->urnes->id => ['recu' => 10, 'defectueux' => 0, 'observation' => null]];
        $this->en($adjoint, fn () => app(DeclarationService::class)->declarer($autre, 'compte', $signataire, $lignesAutre, (string) Str::uuid()));
        $this->refus(fn () => $this->en($adjoint, fn () => app(DeclarationService::class)->declarer($autre, 'tablette', $signataire, $lignesAutre, (string) Str::uuid())), $refus);
        // Le préfet signe le départ.
        $this->en($this->prefet, fn () => app(DeclarationService::class)->declarer($autre, 'tablette', ['nom' => 'Préfet', 'qualite' => 'Préfet'], $lignesAutre, (string) Str::uuid()));
        $this->assertSame(2, Declaration::where('remise_id', $autre->id)->count());
    }

    public function test_valider_une_remise_hors_reserve_est_refuse_tant_que_la_reserve_de_l_appareil_est_active(): void
    {
        $decharge = $this->recueParLePrefet();
        $appareil = $this->designer();
        $this->reserver($appareil, $decharge, [$this->urnes->id => 60]);
        $remise = Remise::findOrFail($this->sync($decharge, $this->corps([$this->urnes->id => 80]), $appareil->identifiant)->json('redistribution.id'));
        $this->assertSame('a_arbitrer', $remise->statut);

        $this->refus(fn () => $this->arbitrer($remise, 'valider', 'Je valide.'),
            'Redistribution supérieure au mobilisable du préfet pour Urnes : mobilisable 40, demandé 80.');
        $this->assertSame('a_arbitrer', $remise->fresh()->statut);

        $this->en($this->prefet, fn () => app(AppareilService::class)->liberer($appareil, $decharge));
        $this->arbitrer($remise, 'valider', 'Réserve libérée.');
        $this->assertSame('en_attente', $remise->fresh()->statut);
        $this->assertSame(80, $this->solde($decharge)['transfere']);
    }
}

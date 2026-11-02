<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Remise;
use App\Models\Logistique\RemiseLigne;
use App\Models\User;
use App\Services\Logistique\DeclarationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class DeclarationTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    private const SIGNATAIRE = ['nom' => 'Awa Fall', 'qualite' => 'Préfet'];

    /** Une tablette dont l'identité est établie : sans preuve, une déclaration de tablette n'est jamais retenue d'office. */
    private const PREUVE_VERIFIEE = ['verification' => 'verifiee_code', 'bloc_empreinte' => null, 'signataire_user_id' => null, 'delegation_id' => null];

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerDecorFictif();
        $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 300], [$this->prefecture, $this->isoloirs, 50],
            [$this->sousPrefecture1, $this->urnes, 100],
        ]);
        $this->stockerFictif($this->urnes, 1000, 300);
        $this->stockerFictif($this->isoloirs, 1000, 100);
    }

    private function livraison(Decharge $decharge): Remise
    {
        return Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->with('lignes')->firstOrFail();
    }

    /** @return array<int, array{recu: int, defectueux: int, observation: ?string}> */
    private function lignes(Remise $remise, int $recu, int $defectueux = 0): array
    {
        $lignes = [];
        foreach ($remise->lignes as $ligne) {
            $lignes[$ligne->materiel_id] = ['recu' => $recu, 'defectueux' => $defectueux, 'observation' => null];
        }

        return $lignes;
    }

    /** Déclare en tant que $auteur puis rétablit le responsable comme utilisateur connecté. */
    private function declarer(User $auteur, Remise $remise, string $mode, array $lignes, ?string $cle = null, array $signataire = self::SIGNATAIRE): Declaration
    {
        $this->actingAs($auteur);
        try {
            return app(DeclarationService::class)->declarer($remise, $mode, $signataire, $lignes, $cle ?? (string) Str::uuid(), null, $mode === 'tablette' ? self::PREUVE_VERIFIEE : null);
        } finally {
            $this->actingAs($this->responsable);
        }
    }

    private function statuts(Remise $remise): array
    {
        return Declaration::where('remise_id', $remise->id)->orderBy('id')->pluck('statut')->all();
    }

    private function chargee(?Destinataire $destinataire = null, int $quantite = 100): Decharge
    {
        return $this->deposerChargee($destinataire ?? $this->prefecture, [$this->urnes->id => $quantite]);
    }

    /** Redistribution du préfet vers la sous-préfecture, écrite directement (la tâche 5 la crée par service). */
    private function redistribution(Decharge $decharge, int $quantite): Remise
    {
        $remise = new Remise([
            'decharge_id' => $decharge->id, 'etape' => 'redistribution', 'de_type' => 'destinataire',
            'de_id' => $this->prefecture->id, 'a_type' => 'destinataire', 'a_id' => $this->sousPrefecture1->id,
            'preparee_par' => $this->responsable->id,
        ]);
        $remise->forceFill(['statut' => 'en_attente'])->save();
        RemiseLigne::create(['remise_id' => $remise->id, 'materiel_id' => $this->urnes->id, 'quantite' => $quantite]);

        return $remise->load('lignes');
    }

    public function test_declaration_compte_retenue_et_decharge_recue_destinataire(): void
    {
        $decharge = $this->chargee();
        $remise = $this->livraison($decharge);
        $autorite = $this->autoriteFictive($this->prefecture);

        $declaration = $this->declarer($autorite, $remise, 'compte', $this->lignes($remise, 100, 3));

        $this->assertSame('retenue', $declaration->statut);
        $this->assertSame('compte', $declaration->mode);
        $this->assertSame($autorite->id, $declaration->user_id);
        $this->assertSame($decharge->id, $declaration->decharge_id);
        $this->assertSame('Awa Fall', $declaration->signataire_nom);
        $this->assertSame([[$this->urnes->id, 100, 3]], $declaration->lignes->map(fn ($l) => [$l->materiel_id, $l->recu, $l->defectueux])->all());
        $this->assertSame('recue', $remise->refresh()->statut);
        $this->assertSame('recue_destinataire', $decharge->refresh()->statut_acheminement);
        $this->assertTrue($declaration->remise->is($remise));
        $this->assertSame(1, $remise->declarations()->count());
        $this->assertSame(1, JournalEntree::where('action', 'declaration_reception')->count());
        $this->assertSame(1, JournalEntree::where('action', 'retenue_declaration')->count());
    }

    public function test_via_prefet_recue_intermediaire(): void
    {
        $decharge = $this->chargee($this->sousPrefecture1, 100);
        $remise = $this->livraison($decharge);
        $this->assertSame($this->prefecture->id, (int) $remise->a_id);

        $this->declarer($this->autoriteFictive($this->prefecture), $remise, 'compte', $this->lignes($remise, 100));

        $this->assertSame('recue_intermediaire', $decharge->refresh()->statut_acheminement);
    }

    public function test_redistribution_au_destinataire_final_puis_partielle(): void
    {
        $decharge = $this->chargee($this->sousPrefecture1, 100);
        $livraison = $this->livraison($decharge);
        $this->declarer($this->autoriteFictive($this->prefecture), $livraison, 'compte', $this->lignes($livraison, 100));
        $this->assertSame('recue_intermediaire', $decharge->refresh()->statut_acheminement);

        // Le sous-préfet reçoit une redistribution partielle : la décharge est reçue par le destinataire final.
        $redistribution = $this->redistribution($decharge, 40);
        $declaration = $this->declarer($this->autoriteFictive($this->sousPrefecture1), $redistribution, 'compte', $this->lignes($redistribution, 40));

        $this->assertSame('retenue', $declaration->statut);
        $this->assertSame('recue', $redistribution->refresh()->statut);
        $this->assertSame('recue_destinataire', $decharge->refresh()->statut_acheminement);
    }

    public function test_meme_cle_meme_contenu_renvoie_la_meme_declaration(): void
    {
        $decharge = $this->chargee();
        $remise = $this->livraison($decharge);
        $autorite = $this->autoriteFictive($this->prefecture);
        $lignes = $this->lignes($remise, 100);
        $cle = 'cle-idempotence-0001';

        $premiere = $this->declarer($autorite, $remise, 'compte', $lignes, $cle);
        $deuxieme = $this->declarer($autorite, $remise, 'compte', $lignes, $cle);
        $troisieme = $this->declarer($autorite, $remise, 'compte', $lignes, $cle);

        $this->assertSame($premiere->id, $deuxieme->id);
        $this->assertSame($premiere->id, $troisieme->id);
        $this->assertSame(1, Declaration::count());
        $this->assertSame(1, DB::table('declaration_lignes')->count());
        $this->assertSame(1, JournalEntree::where('action', 'declaration_reception')->count());
        $this->assertSame('retenue', $troisieme->statut);
    }

    public function test_meme_cle_contenu_different_refuse(): void
    {
        $decharge = $this->chargee();
        $remise = $this->livraison($decharge);
        $autorite = $this->autoriteFictive($this->prefecture);
        $cle = 'cle-idempotence-0002';
        $premiere = $this->declarer($autorite, $remise, 'compte', $this->lignes($remise, 100), $cle);

        try {
            $this->declarer($autorite, $remise, 'compte', $this->lignes($remise, 90), $cle);
            $this->fail('Une clé réutilisée pour un autre contenu aurait dû être refusée.');
        } catch (RegleLogistique $e) {
            $this->assertSame("Clé d'idempotence déjà utilisée pour un autre contenu.", $e->getMessage());
        }

        $this->assertSame(1, Declaration::count());
        $entree = JournalEntree::where('action', 'anomalie_idempotence')->sole();
        $this->assertSame($premiere->id, (int) $entree->objet_id);
        // Le signataire fait partie du contenu.
        $this->expectException(RegleLogistique::class);
        $this->declarer($autorite, $remise, 'compte', $this->lignes($remise, 100), $cle, ['nom' => 'Autre', 'qualite' => 'Préfet']);
    }

    public function test_empreinte_independante_de_l_ordre_des_lignes(): void
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 100, $this->isoloirs->id => 20]);
        $remise = $this->livraison($decharge);
        $autorite = $this->autoriteFictive($this->prefecture);
        $endroit = [$this->urnes->id => ['recu' => 100, 'defectueux' => 0, 'observation' => null], $this->isoloirs->id => ['recu' => 20, 'defectueux' => 0, 'observation' => null]];
        $cle = 'cle-idempotence-0003';

        $declaration = $this->declarer($autorite, $remise, 'compte', $endroit, $cle);
        $envers = $this->declarer($autorite, $remise, 'compte', array_reverse($endroit, true), $cle);

        $this->assertSame($declaration->id, $envers->id);
        $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', $declaration->empreinte_contenu));
    }

    public function test_cle_invalide_refusee(): void
    {
        $decharge = $this->chargee();
        $remise = $this->livraison($decharge);
        $autorite = $this->autoriteFictive($this->prefecture);

        foreach (['courte', str_repeat('a', 65), 'cle avec espaces 123456', 'clé-accentuée-12345'] as $cle) {
            try {
                $this->declarer($autorite, $remise, 'compte', $this->lignes($remise, 100), $cle);
                $this->fail("La clé « {$cle} » aurait dû être refusée.");
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(0, Declaration::count());
    }

    public function test_defectueux_superieur_au_recu_refuse(): void
    {
        $remise = $this->livraison($this->chargee());

        try {
            $this->declarer($this->autoriteFictive($this->prefecture), $remise, 'compte', $this->lignes($remise, 10, 11));
            $this->fail('Défectueux supérieur au reçu aurait dû être refusé.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Défectueux supérieur au reçu pour Urnes.', $e->getMessage());
        }
        $this->assertSame(0, Declaration::count());
    }

    public function test_quantites_hors_bornes_refusees(): void
    {
        $remise = $this->livraison($this->chargee());
        $autorite = $this->autoriteFictive($this->prefecture);

        foreach ([-1, 10_000_001] as $recu) {
            try {
                $this->declarer($autorite, $remise, 'compte', $this->lignes($remise, $recu));
                $this->fail("Un reçu de {$recu} aurait dû être refusé.");
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(0, Declaration::count());
    }

    public function test_ligne_manquante_refusee(): void
    {
        $decharge = $this->chargee();
        $remise = $this->livraison($decharge);
        $autorite = $this->autoriteFictive($this->prefecture);

        foreach ([[], [$this->urnes->id => ['recu' => 1, 'defectueux' => 0], $this->isoloirs->id => ['recu' => 1, 'defectueux' => 0]]] as $lignes) {
            try {
                $this->declarer($autorite, $remise, 'compte', $lignes);
                $this->fail('Une déclaration incomplète ou avec une ligne étrangère aurait dû être refusée.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Chaque ligne de la remise doit être déclarée.', $e->getMessage());
            }
        }
        $this->assertSame(0, Declaration::count());
    }

    public function test_excedent_a_verifier(): void
    {
        $decharge = $this->chargee();
        $remise = $this->livraison($decharge);

        $declaration = $this->declarer($this->autoriteFictive($this->prefecture), $remise, 'compte', $this->lignes($remise, 110));

        $this->assertSame('a_verifier', $declaration->statut);
        $this->assertSame(['excedent'], $declaration->anomalies);
        $this->assertSame('a_verifier', $remise->refresh()->statut);
        $this->assertSame('a_verifier', $decharge->refresh()->statut_acheminement);
    }

    public function test_deux_concordantes_une_confirmation(): void
    {
        $decharge = $this->chargee();
        $remise = $this->livraison($decharge);
        $tablette = $this->declarer($decharge->agent, $remise, 'tablette', $this->lignes($remise, 98, 2));
        $this->assertSame('retenue', $tablette->statut);

        $compte = $this->declarer($this->autoriteFictive($this->prefecture), $remise, 'compte', $this->lignes($remise, 98, 2));

        $this->assertSame('retenue', $tablette->refresh()->statut);
        $this->assertSame('concordante', $compte->refresh()->statut);
        $this->assertSame('recue', $remise->refresh()->statut);
        $this->assertSame('recue_destinataire', $decharge->refresh()->statut_acheminement);
        $this->assertSame(1, JournalEntree::where('action', 'retenue_declaration')->count());
    }

    public function test_divergentes_a_verifier_et_rien_compte(): void
    {
        $decharge = $this->chargee();
        $remise = $this->livraison($decharge);
        $tablette = $this->declarer($decharge->agent, $remise, 'tablette', $this->lignes($remise, 100));
        $this->assertSame('retenue', $tablette->statut);

        $compte = $this->declarer($this->autoriteFictive($this->prefecture), $remise, 'compte', $this->lignes($remise, 90));

        $this->assertSame('a_verifier', $tablette->refresh()->statut);
        $this->assertSame('a_verifier', $compte->refresh()->statut);
        $this->assertContains('divergence', $tablette->anomalies);
        $this->assertContains('divergence', $compte->anomalies);
        $this->assertSame(0, Declaration::where('statut', 'retenue')->count());
        $this->assertSame('a_verifier', $remise->refresh()->statut);
        $this->assertSame('a_verifier', $decharge->refresh()->statut_acheminement);
        $this->assertSame(1, JournalEntree::where('action', 'divergence_declarations')->count());
    }

    public function test_ordre_d_arrivee_indifferent(): void
    {
        $premiere = $this->chargee();
        $remiseA = $this->livraison($premiere);
        $this->declarer($this->autoriteFictive($this->prefecture), $remiseA, 'compte', $this->lignes($remiseA, 100));
        $this->declarer($premiere->agent, $remiseA, 'tablette', $this->lignes($remiseA, 100));

        $seconde = $this->chargee();
        $remiseB = $this->livraison($seconde);
        $this->declarer($seconde->agent, $remiseB, 'tablette', $this->lignes($remiseB, 100));
        $this->declarer($this->autoriteFictive($this->prefecture), $remiseB, 'compte', $this->lignes($remiseB, 100));

        foreach ([$remiseA, $remiseB] as $remise) {
            $statuts = $this->statuts($remise);
            sort($statuts);
            $this->assertSame(['concordante', 'retenue'], $statuts);
            $this->assertSame('recue', $remise->refresh()->statut);
        }
        $this->assertSame('recue_destinataire', $premiere->refresh()->statut_acheminement);
        $this->assertSame('recue_destinataire', $seconde->refresh()->statut_acheminement);

        // La plus ancienne est retenue, quel que soit son mode.
        $this->assertSame('retenue', Declaration::where('remise_id', $remiseA->id)->where('mode', 'compte')->value('statut'));
        $this->assertSame('retenue', Declaration::where('remise_id', $remiseB->id)->where('mode', 'tablette')->value('statut'));
    }

    public function test_ordre_d_arrivee_indifferent_pour_la_divergence(): void
    {
        $premiere = $this->chargee();
        $remiseA = $this->livraison($premiere);
        $this->declarer($this->autoriteFictive($this->prefecture), $remiseA, 'compte', $this->lignes($remiseA, 100));
        $this->declarer($premiere->agent, $remiseA, 'tablette', $this->lignes($remiseA, 90));

        $seconde = $this->chargee();
        $remiseB = $this->livraison($seconde);
        $this->declarer($seconde->agent, $remiseB, 'tablette', $this->lignes($remiseB, 90));
        $this->declarer($this->autoriteFictive($this->prefecture), $remiseB, 'compte', $this->lignes($remiseB, 100));

        $this->assertSame(['a_verifier', 'a_verifier'], $this->statuts($remiseA));
        $this->assertSame(['a_verifier', 'a_verifier'], $this->statuts($remiseB));
        $this->assertSame('a_verifier', $premiere->refresh()->statut_acheminement);
        $this->assertSame('a_verifier', $seconde->refresh()->statut_acheminement);
    }

    public function test_anomalie_d_une_declaration_met_les_deux_a_verifier_sans_divergence(): void
    {
        $decharge = $this->chargee();
        $remise = $this->livraison($decharge);
        $ancien = $decharge->agent;
        $decharge->forceFill(['agent_id' => User::factory()->create(['role' => 'agent_transport'])->id])->save();

        // Contenus identiques, mais la tablette vient d'un agent qui n'est plus affecté.
        $tablette = $this->declarer($ancien, $remise, 'tablette', $this->lignes($remise, 100));
        $compte = $this->declarer($this->autoriteFictive($this->prefecture), $remise, 'compte', $this->lignes($remise, 100));

        $this->assertSame('a_verifier', $tablette->refresh()->statut);
        $this->assertSame('a_verifier', $compte->refresh()->statut);
        $this->assertSame(['affectation_retiree'], $tablette->anomalies);
        $this->assertNull($compte->anomalies);
        $this->assertSame(0, JournalEntree::where('action', 'divergence_declarations')->count());
        $this->assertSame('a_verifier', $remise->refresh()->statut);
        $this->assertSame('a_verifier', $decharge->refresh()->statut_acheminement);
    }

    public function test_declaration_retenue_repasse_a_verifier_et_le_journal_garde_la_trace(): void
    {
        $decharge = $this->chargee();
        $remise = $this->livraison($decharge);
        $ancien = $decharge->agent;
        $decharge->forceFill(['agent_id' => User::factory()->create(['role' => 'agent_transport'])->id])->save();

        $compte = $this->declarer($this->autoriteFictive($this->prefecture), $remise, 'compte', $this->lignes($remise, 100));
        $this->assertSame('retenue', $compte->statut);
        $this->assertSame('recue', $remise->refresh()->statut);

        $tablette = $this->declarer($ancien, $remise, 'tablette', $this->lignes($remise, 100));

        $this->assertSame('a_verifier', $compte->refresh()->statut);
        $this->assertSame('a_verifier', $tablette->refresh()->statut);
        $this->assertNull($compte->anomalies);
        $this->assertSame('a_verifier', $remise->refresh()->statut);
        $this->assertSame('a_verifier', $decharge->refresh()->statut_acheminement);

        $entree = JournalEntree::where('action', 'statut_declaration')->where('objet_type', 'declaration')
            ->where('objet_id', $compte->id)->latest('id')->firstOrFail();
        $this->assertSame('retenue', $entree->avant['statut']);
        $this->assertSame('a_verifier', $entree->apres['statut']);
        // La tablette naît déjà à vérifier : aucun changement de statut à tracer pour elle.
        $this->assertSame(0, JournalEntree::where('action', 'statut_declaration')->where('objet_id', $tablette->id)->count());
    }

    public function test_apres_retenue_appele_directement_apres_un_changement_de_statut(): void
    {
        $decharge = $this->chargee($this->sousPrefecture1, 100);
        $livraison = $this->livraison($decharge);
        $this->declarer($this->autoriteFictive($this->prefecture), $livraison, 'compte', $this->lignes($livraison, 100));
        $redistribution = $this->redistribution($decharge, 40);
        $this->declarer($this->autoriteFictive($this->sousPrefecture1), $redistribution, 'compte', $this->lignes($redistribution, 40));
        $service = app(DeclarationService::class);
        $this->assertSame('recue_destinataire', $decharge->refresh()->statut_acheminement);

        // Une remise passée à vérifier à la main : la décharge l'est aussi.
        $redistribution->forceFill(['statut' => 'a_verifier'])->save();
        $service->apresRetenue($redistribution);
        $this->assertSame('a_verifier', $decharge->refresh()->statut_acheminement);

        // Redevenue reçue, avec une déclaration retenue chez le destinataire final.
        $redistribution->forceFill(['statut' => 'recue'])->save();
        $service->apresRetenue($redistribution);
        $this->assertSame('recue_destinataire', $decharge->refresh()->statut_acheminement);
        $this->assertSame(1, JournalEntree::where('action', 'acheminement_decharge')->where('objet_id', $decharge->id)
            ->where('apres->statut_acheminement', 'a_verifier')->count());
    }

    public function test_hors_perimetre_refuse(): void
    {
        $decharge = $this->chargee();
        $remise = $this->livraison($decharge);
        $this->autoriteFictive($this->prefecture);

        $autres = [
            'autre préfet' => User::factory()->create(['role' => 'prefet']),
            'autorité d\'une autre entité' => $this->autoriteFictive($this->sousPrefecture2),
            'responsable central' => $this->responsable,
            'autre agent' => User::factory()->create(['role' => 'agent_transport']),
        ];
        foreach ($autres as $nom => $auteur) {
            try {
                $this->declarer($auteur, $remise, 'compte', $this->lignes($remise, 100));
                $this->fail("Le compte {$nom} n'aurait pas dû pouvoir déclarer.");
            } catch (RegleLogistique $e) {
                $this->assertSame('Déclaration hors de votre périmètre.', $e->getMessage());
            }
        }
        // Un autre agent ne peut pas déclarer sur tablette, ni l'autorité sur tablette.
        foreach ([User::factory()->create(['role' => 'agent_transport']), $this->autoriteFictive($this->prefecture)] as $auteur) {
            try {
                $this->declarer($auteur, $remise, 'tablette', $this->lignes($remise, 100));
                $this->fail('Une déclaration sur tablette hors périmètre aurait dû être refusée.');
            } catch (RegleLogistique $e) {
                $this->assertSame('Déclaration hors de votre périmètre.', $e->getMessage());
            }
        }
        $this->assertSame(0, Declaration::count());
    }

    public function test_compte_inactif_refuse(): void
    {
        $remise = $this->livraison($this->chargee());
        $autorite = $this->autoriteFictive($this->prefecture);
        $autorite->forceFill(['actif' => false])->save();

        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Déclaration hors de votre périmètre.');
        $this->declarer($autorite, $remise, 'compte', $this->lignes($remise, 100));
    }

    public function test_tablette_par_ancien_agent_a_verifier(): void
    {
        $decharge = $this->chargee();
        $remise = $this->livraison($decharge);
        $ancien = $decharge->agent;
        // L'agent est réaffecté après le chargement (écrit directement : la réaffectation y est interdite).
        $decharge->forceFill(['agent_id' => User::factory()->create(['role' => 'agent_transport'])->id])->save();

        $declaration = $this->declarer($ancien, $remise, 'tablette', $this->lignes($remise, 100));

        $this->assertSame('a_verifier', $declaration->statut);
        $this->assertSame(['affectation_retiree'], $declaration->anomalies);
        $this->assertSame('a_verifier', $remise->refresh()->statut);
        $this->assertSame('a_verifier', $decharge->refresh()->statut_acheminement);
    }

    public function test_declaration_sur_decharge_annulee_a_verifier(): void
    {
        $decharge = $this->chargee();
        $remise = $this->livraison($decharge);
        $decharge->forceFill(['statut_document' => 'annulee'])->save();

        $declaration = $this->declarer($this->autoriteFictive($this->prefecture), $remise, 'compte', $this->lignes($remise, 100));

        $this->assertSame('a_verifier', $declaration->statut);
        $this->assertSame(['version_annulee'], $declaration->anomalies);
        $this->assertSame($decharge->id, $declaration->decharge_id);
        $this->assertSame('a_verifier', $remise->refresh()->statut);
    }

    public function test_meme_mode_deux_fois_refuse(): void
    {
        $decharge = $this->chargee();
        $remise = $this->livraison($decharge);
        $autorite = $this->autoriteFictive($this->prefecture);
        $this->declarer($autorite, $remise, 'compte', $this->lignes($remise, 100));

        try {
            $this->declarer($autorite, $remise, 'compte', $this->lignes($remise, 100));
            $this->fail('Une seconde déclaration du même mode aurait dû être refusée.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Une déclaration compte existe déjà pour cette remise.', $e->getMessage());
        }
        $this->assertSame(1, Declaration::count());
    }

    public function test_remise_de_chargement_ou_annulee_n_attend_pas_de_declaration(): void
    {
        $decharge = $this->chargee();
        $chargement = Remise::where('decharge_id', $decharge->id)->where('etape', 'chargement')->with('lignes')->firstOrFail();
        $livraison = $this->livraison($decharge);

        try {
            $this->declarer($decharge->agent, $chargement, 'tablette', $this->lignes($chargement, 100));
            $this->fail('Le chargement ne se déclare pas.');
        } catch (RegleLogistique $e) {
            $this->assertSame("Cette remise n'attend pas de déclaration.", $e->getMessage());
        }

        $livraison->forceFill(['statut' => 'annulee'])->save();
        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage("Cette remise n'attend pas de déclaration.");
        $this->declarer($this->autoriteFictive($this->prefecture), $livraison, 'compte', $this->lignes($livraison, 100));
    }

    public function test_remise_arbitree_n_accepte_plus_de_declaration(): void
    {
        $decharge = $this->chargee();
        $remise = $this->livraison($decharge);
        $this->declarer($decharge->agent, $remise, 'tablette', $this->lignes($remise, 100));
        DB::table('arbitrages')->insert([
            'cycle_id' => $this->cycle->id, 'objet' => 'declaration', 'remise_id' => $remise->id,
            'destinataire_id' => $this->prefecture->id, 'decision' => 'retenir_tablette', 'motif' => 'Test',
            'user_id' => $this->responsable->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage("Cette remise n'attend pas de déclaration.");
        $this->declarer($this->autoriteFictive($this->prefecture), $remise, 'compte', $this->lignes($remise, 100));
    }

    public function test_redistribution_par_tablette_de_l_autorite_expeditrice(): void
    {
        $decharge = $this->chargee($this->sousPrefecture1, 100);
        $redistribution = $this->redistribution($decharge, 40);
        $prefet = $this->autoriteFictive($this->prefecture);

        $declaration = $this->declarer($prefet, $redistribution, 'tablette', $this->lignes($redistribution, 40));
        $this->assertSame('retenue', $declaration->statut);

        $this->expectException(RegleLogistique::class);
        $this->declarer($this->autoriteFictive($this->sousPrefecture2), $redistribution, 'tablette', $this->lignes($redistribution, 40));
    }

    public function test_declaration_sans_auteur_refusee(): void
    {
        $remise = $this->livraison($this->chargee());
        $this->app['auth']->logout();

        $this->expectException(\LogicException::class);
        app(DeclarationService::class)->declarer($remise, 'compte', self::SIGNATAIRE, $this->lignes($remise, 100), (string) Str::uuid());
    }

    public function test_signataire_et_mode_invalides_refuses(): void
    {
        $remise = $this->livraison($this->chargee());
        $autorite = $this->autoriteFictive($this->prefecture);

        foreach ([['nom' => '', 'qualite' => 'Préfet'], ['nom' => 'Awa', 'qualite' => '  '], ['nom' => str_repeat('a', 151), 'qualite' => 'Préfet'], []] as $signataire) {
            try {
                $this->declarer($autorite, $remise, 'compte', $this->lignes($remise, 100), null, $signataire);
                $this->fail('Un signataire invalide aurait dû être refusé.');
            } catch (InvalidArgumentException) {
            }
        }
        try {
            $this->declarer($autorite, $remise, 'courrier', $this->lignes($remise, 100));
            $this->fail('Un mode inconnu aurait dû être refusé.');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame(0, Declaration::count());
    }

    public function test_une_declaration_est_immuable_apres_rapprochement(): void
    {
        $decharge = $this->chargee();
        $remise = $this->livraison($decharge);
        $tablette = $this->declarer($decharge->agent, $remise, 'tablette', $this->lignes($remise, 100, 1));
        $avant = $tablette->lignes->map(fn ($l) => [$l->recu, $l->defectueux])->all();

        $this->declarer($this->autoriteFictive($this->prefecture), $remise, 'compte', $this->lignes($remise, 90));

        $this->assertSame($avant, $tablette->refresh()->lignes->map(fn ($l) => [$l->recu, $l->defectueux])->all());
        $this->assertSame(2, Declaration::count());
    }

    public function test_deposer_recue_du_decor(): void
    {
        $decharge = $this->chargee();

        $declaration = $this->deposerRecue($decharge, [$this->urnes->id => ['recu' => 95, 'defectueux' => 5]]);

        $this->assertSame('compte', $declaration->mode);
        $this->assertSame('retenue', $declaration->statut);
        $this->assertSame([[95, 5]], $declaration->lignes->map(fn ($l) => [$l->recu, $l->defectueux])->all());
        $this->assertTrue(auth()->user()->is($this->responsable));
    }

    public function test_arbitrages_reference_remises_et_declarations(): void
    {
        $references = collect(Schema::getForeignKeys('arbitrages'))->mapWithKeys(
            fn ($cle) => [$cle['columns'][0] => $cle['foreign_table']]
        );

        $this->assertSame('remises', $references['remise_id']);
        $this->assertSame('declarations', $references['declaration_id']);
    }
}

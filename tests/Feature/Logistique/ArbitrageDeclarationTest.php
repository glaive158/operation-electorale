<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Arbitrage;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Remise;
use App\Models\User;
use App\Services\Logistique\ArbitrageService;
use App\Services\Logistique\DeclarationService;
use App\Services\Logistique\RemiseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class ArbitrageDeclarationTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    private const SIGNATAIRE = ['nom' => 'Awa Fall', 'qualite' => 'Préfet'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerDecorFictif();
        $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 300], [$this->sousPrefecture1, $this->urnes, 100],
        ]);
        $this->stockerFictif($this->urnes, 1000, 300);
    }

    private function livraison(Decharge $decharge): Remise
    {
        return Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->with('lignes')->firstOrFail();
    }

    private function declarer(User $auteur, Remise $remise, string $mode, int $recu, int $defectueux = 0): Declaration
    {
        $lignes = [];
        foreach ($remise->lignes as $ligne) {
            $lignes[$ligne->materiel_id] = ['recu' => $recu, 'defectueux' => $defectueux, 'observation' => null];
        }
        $this->actingAs($auteur);
        try {
            return app(DeclarationService::class)->declarer($remise, $mode, self::SIGNATAIRE, $lignes, (string) Str::uuid());
        } finally {
            $this->actingAs($this->responsable);
        }
    }

    /** Remise de livraison à 100 unités portant deux déclarations divergentes : tablette 100, compte 90. */
    private function divergentes(): array
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 100]);
        $remise = $this->livraison($decharge);
        $tablette = $this->declarer($decharge->agent, $remise, 'tablette', 100);
        $compte = $this->declarer($this->autoriteFictive($this->prefecture), $remise, 'compte', 90);

        return [$decharge, $remise, $tablette, $compte];
    }

    public function test_retenir_une_des_divergentes(): void
    {
        [$decharge, $remise, $tablette, $compte] = $this->divergentes();
        $this->assertSame('a_verifier', $remise->refresh()->statut);

        app(ArbitrageService::class)->retenir($compte, 'Le compte du préfet fait foi.');

        $this->assertSame('retenue', $compte->refresh()->statut);
        $this->assertSame('ecartee', $tablette->refresh()->statut);
        $this->assertSame('recue', $remise->refresh()->statut);
        $this->assertSame('recue_destinataire', $decharge->refresh()->statut_acheminement);
        // Les anomalies restent comme historique, les quantités ne changent pas.
        $this->assertContains('divergence', $compte->anomalies);
        $this->assertSame(90, (int) $compte->lignes->first()->recu);
        $this->assertSame(100, (int) $tablette->lignes->first()->recu);
    }

    public function test_accepter_un_excedent(): void
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 100]);
        $remise = $this->livraison($decharge);
        $declaration = $this->declarer($this->autoriteFictive($this->prefecture), $remise, 'compte', 110);
        $this->assertSame('a_verifier', $declaration->statut);
        $this->assertSame('a_verifier', $remise->refresh()->statut);

        app(ArbitrageService::class)->retenir($declaration, 'Excédent constaté et accepté.');

        $this->assertSame('retenue', $declaration->refresh()->statut);
        $this->assertSame(['excedent'], $declaration->anomalies);
        $this->assertSame('recue', $remise->refresh()->statut);
        $this->assertSame('recue_destinataire', $decharge->refresh()->statut_acheminement);
    }

    public function test_rejeter_rouvre_la_remise(): void
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 100]);
        $remise = $this->livraison($decharge);
        $autorite = $this->autoriteFictive($this->prefecture);
        $excedent = $this->declarer($autorite, $remise, 'compte', 110);

        app(ArbitrageService::class)->rejeter($excedent, 'Quantité impossible.');

        $this->assertSame('ecartee', $excedent->refresh()->statut);
        $this->assertSame('en_attente', $remise->refresh()->statut);
        $this->assertSame('chargee', $decharge->refresh()->statut_acheminement);

        // Une nouvelle déclaration, quel que soit son mode, est acceptée et seule elle compte.
        $nouvelle = $this->declarer($decharge->agent, $remise, 'tablette', 100);
        $this->assertSame('retenue', $nouvelle->statut);
        $this->assertSame('recue', $remise->refresh()->statut);
        $this->assertSame('recue_destinataire', $decharge->refresh()->statut_acheminement);
        $this->assertSame('ecartee', $excedent->refresh()->statut);
        $this->assertSame(110, (int) $excedent->lignes->first()->recu);
    }

    public function test_rejeter_une_divergente_relance_le_rapprochement_de_l_autre(): void
    {
        [$decharge, $remise, $tablette, $compte] = $this->divergentes();

        app(ArbitrageService::class)->rejeter($tablette, 'La tablette est erronée.');

        $this->assertSame('ecartee', $tablette->refresh()->statut);
        $this->assertSame('retenue', $compte->refresh()->statut);
        $this->assertSame('recue', $remise->refresh()->statut);
        $this->assertSame('recue_destinataire', $decharge->refresh()->statut_acheminement);
    }

    public function test_une_remise_arbitree_n_accepte_plus_l_autre_mode(): void
    {
        [$decharge, , , $compte] = $this->divergentes();
        app(ArbitrageService::class)->retenir($compte, 'Le compte du préfet fait foi.');

        // Le mode de la déclaration écartée (tablette de l'agent) ne rouvre pas la remise arbitrée.
        try {
            $this->declarer($decharge->agent, $this->livraison($decharge), 'tablette', 100);
            $this->fail('Une remise arbitrée n\'attend plus de déclaration.');
        } catch (RegleLogistique $e) {
            $this->assertSame("Cette remise n'attend pas de déclaration.", $e->getMessage());
        }
        $this->assertSame(2, Declaration::where('decharge_id', $decharge->id)->count());
    }

    public function test_seule_la_declaration_retenue_compte_dans_le_solde_du_prefet(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $remise = $this->livraison($decharge);
        $this->assertSame($this->prefecture->id, $remise->a_id);
        $tablette = $this->declarer($decharge->agent, $remise, 'tablette', 100);
        $compte = $this->declarer($this->autoriteFictive($this->prefecture), $remise, 'compte', 90, 10);
        $solde = fn () => app(RemiseService::class)->soldePrefet($decharge)->get($this->urnes->id);

        // Tant que rien n'est retenu, aucune déclaration ne compte.
        $this->assertSame(['recu_utilisable' => 0, 'transfere' => 0, 'reliquat' => 0], $solde());

        app(ArbitrageService::class)->retenir($compte, 'Le compte du préfet fait foi.');

        // 90 reçus moins 10 défectueux : la tablette écartée (100) ne s'ajoute ni ne remplace.
        $this->assertSame('ecartee', $tablette->refresh()->statut);
        $this->assertSame(['recu_utilisable' => 80, 'transfere' => 0, 'reliquat' => 80], $solde());
    }

    public function test_gestionnaire_ne_peut_pas_arbitrer(): void
    {
        [, $remise, $tablette, $compte] = $this->divergentes();
        $this->actingAs(User::factory()->create(['role' => 'logistique_gestionnaire']));

        foreach (['retenir', 'rejeter'] as $action) {
            try {
                app(ArbitrageService::class)->{$action}($compte, 'Tentative.');
                $this->fail('Le gestionnaire ne peut pas arbitrer.');
            } catch (RegleLogistique $e) {
                $this->assertSame('Seul un responsable logistique peut arbitrer une déclaration.', $e->getMessage());
            }
        }
        $this->assertSame(0, Arbitrage::count());
        $this->assertSame('a_verifier', $compte->refresh()->statut);
        $this->assertSame('a_verifier', $remise->refresh()->statut);
    }

    public function test_arbitrage_journalise_et_trace(): void
    {
        [$decharge, $remise, $tablette, $compte] = $this->divergentes();

        app(ArbitrageService::class)->retenir($tablette, '  La tablette fait foi.  ');

        $arbitrage = Arbitrage::sole();
        $this->assertSame('declaration', $arbitrage->objet);
        $this->assertSame('retenue', $arbitrage->decision);
        $this->assertSame($remise->id, $arbitrage->remise_id);
        $this->assertSame($tablette->id, $arbitrage->declaration_id);
        $this->assertSame($this->cycle->id, $arbitrage->cycle_id);
        $this->assertSame($this->prefecture->id, $arbitrage->destinataire_id);
        $this->assertNull($arbitrage->materiel_id);
        $this->assertSame('La tablette fait foi.', $arbitrage->motif);
        $this->assertSame($this->responsable->id, $arbitrage->user_id);
        $this->assertSame(0, $arbitrage->quantite_abandon_reception);
        $this->assertSame(0, $arbitrage->quantite_expedition_annulee);

        $entree = JournalEntree::where('action', 'arbitrage_declaration')->sole();
        $this->assertSame('declaration', $entree->objet_type);
        $this->assertSame($tablette->id, (int) $entree->objet_id);
        $this->assertSame('La tablette fait foi.', $entree->motif);
        $this->assertSame('a_verifier', $entree->avant['statut']);
        $this->assertSame('retenue', $entree->apres['statut']);
        $this->assertSame([$compte->id], $entree->apres['ecartees']);
        $this->assertSame(1, JournalEntree::where('action', 'statut_declaration')->where('objet_id', $compte->id)
            ->where('apres->statut', 'ecartee')->count());

        // Le rejet est tracé de la même façon, avec la décision « ecartee ».
        $autre = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 50]);
        $remiseAutre = $this->livraison($autre);
        $this->declarer($this->autoriteFictive($this->prefecture), $remiseAutre, 'compte', 60);
        $excedent = Declaration::where('remise_id', $remiseAutre->id)->sole();
        app(ArbitrageService::class)->rejeter($excedent, 'Rejet.');
        $this->assertSame('ecartee', Arbitrage::where('declaration_id', $excedent->id)->value('decision'));
        $this->assertSame(2, JournalEntree::where('action', 'arbitrage_declaration')->count());
    }

    public function test_rien_a_arbitrer(): void
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 100]);
        $remise = $this->livraison($decharge);
        $retenue = $this->declarer($this->autoriteFictive($this->prefecture), $remise, 'compte', 100);
        $this->assertSame('retenue', $retenue->statut);

        foreach (['retenir', 'rejeter'] as $action) {
            try {
                app(ArbitrageService::class)->{$action}($retenue, 'Inutile.');
                $this->fail('Rien à arbitrer.');
            } catch (RegleLogistique $e) {
                $this->assertSame('Rien à arbitrer pour cette déclaration.', $e->getMessage());
            }
        }

        // Une déclaration déjà écartée non plus.
        [, , $tablette, $compte] = $this->divergentes();
        app(ArbitrageService::class)->retenir($compte, 'Le compte fait foi.');
        $this->expectException(RegleLogistique::class);
        app(ArbitrageService::class)->rejeter($tablette->refresh(), 'Déjà écartée.');
    }

    public function test_motif_obligatoire_et_borne(): void
    {
        [, , , $compte] = $this->divergentes();

        foreach (['', '   ', str_repeat('a', 501)] as $motif) {
            try {
                app(ArbitrageService::class)->retenir($compte, $motif);
                $this->fail('Le motif aurait dû être refusé.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Le motif est obligatoire (500 caractères au plus).', $e->getMessage());
            }
        }
        $this->assertSame(0, Arbitrage::count());
        $this->assertSame('a_verifier', $compte->refresh()->statut);
    }

    public function test_arbitrage_et_declaration_restent_possibles_apres_la_cloture_du_cycle(): void
    {
        [, $remise, , $compte] = $this->divergentes();
        $aRejeter = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 20]);
        $excedent = $this->declarer($this->autoriteFictive($this->prefecture), $this->livraison($aRejeter), 'compte', 30);
        $nonDeclaree = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 50]);
        Cycle::whereKey($this->cycle->id)->update(['statut' => 'clos']);

        // L'arbitrage ne touche à aucun stock central : retenir et rejeter passent après la clôture.
        app(ArbitrageService::class)->retenir($compte, 'Le compte du préfet fait foi.');
        $this->assertSame('retenue', $compte->refresh()->statut);
        $this->assertSame('recue', $remise->refresh()->statut);
        app(ArbitrageService::class)->rejeter($excedent, 'Quantité impossible.');
        $this->assertSame('ecartee', $excedent->refresh()->statut);
        $this->assertSame(2, Arbitrage::count());

        // Une livraison non déclarée au moment de la clôture reste déclarable.
        $declaration = $this->declarer($this->autoriteFictive($this->prefecture), $this->livraison($nonDeclaree), 'compte', 50);
        $this->assertSame('retenue', $declaration->statut);
        $this->assertSame('recue_intermediaire', $nonDeclaree->refresh()->statut_acheminement);
    }
}

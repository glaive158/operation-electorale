<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\MouvementStock;
use App\Models\Logistique\Remise;
use App\Models\User;
use App\Services\Logistique\DeclarationService;
use App\Services\Logistique\RemiseService;
use App\Services\Logistique\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class RedistributionTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

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
    }

    /** Décharge via le préfet, chargée puis reçue par le préfet (100 urnes dont $defectueux défectueuses). */
    private function recueParLePrefet(int $defectueux = 0): Decharge
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => ['recu' => 100, 'defectueux' => $defectueux]]);

        return $decharge->refresh();
    }

    /** Exécute $action en tant que $auteur puis rétablit le responsable. */
    private function en(User $auteur, callable $action): mixed
    {
        $this->actingAs($auteur);
        try {
            return $action();
        } finally {
            $this->actingAs($this->responsable);
        }
    }

    private function redistribuer(Decharge $decharge, array $lignes, ?User $auteur = null): Remise
    {
        return $this->en($auteur ?? $this->autoriteFictive($this->prefecture),
            fn () => app(RemiseService::class)->preparerRedistribution($decharge, $lignes));
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

    public function test_prefet_redistribue_80_sur_100_reliquat_20(): void
    {
        $decharge = $this->recueParLePrefet();
        $stock = app(StockService::class);
        $physique = $stock->physique($this->urnes);
        $mouvements = MouvementStock::count();
        $this->assertSame(['recu_utilisable' => 100, 'transfere' => 0, 'reliquat' => 100, 'reserve_appareil' => 0, 'mobilisable' => 100], $this->solde($decharge));

        $remise = $this->redistribuer($decharge, [$this->urnes->id => 80]);

        $this->assertSame('redistribution', $remise->etape);
        $this->assertSame('en_attente', $remise->statut);
        $this->assertSame(['destinataire', $this->prefecture->id, 'destinataire', $this->sousPrefecture1->id],
            [$remise->de_type, $remise->de_id, $remise->a_type, $remise->a_id]);
        $this->assertSame($this->autoriteFictive($this->prefecture)->id, $remise->preparee_par);
        $this->assertSame([[$this->urnes->id, 80]], $remise->lignes->map(fn ($l) => [$l->materiel_id, $l->quantite])->all());
        $this->assertSame(['recu_utilisable' => 100, 'transfere' => 80, 'reliquat' => 20, 'reserve_appareil' => 0, 'mobilisable' => 20], $this->solde($decharge));
        $this->assertSame($physique, $stock->physique($this->urnes));
        $this->assertSame($mouvements, MouvementStock::count());
        $this->assertSame(1, JournalEntree::where('action', 'preparation_redistribution')->where('objet_id', $remise->id)->count());
    }

    public function test_solde_calcule_en_un_nombre_borne_de_requetes(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => 100]);
        $this->redistribuer($decharge->refresh(), [$this->urnes->id => 10]);
        $this->redistribuer($decharge, [$this->urnes->id => 10]);

        DB::enableQueryLog();
        app(RemiseService::class)->soldePrefet($decharge);
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(4, $requetes);
    }

    public function test_solde_sans_reception_retenue_est_nul(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);

        $this->assertSame(['recu_utilisable' => 0, 'transfere' => 0, 'reliquat' => 0, 'reserve_appareil' => 0, 'mobilisable' => 0], $this->solde($decharge));
    }

    public function test_redistribution_120_sur_100_refusee(): void
    {
        $decharge = $this->recueParLePrefet();

        $this->refus(fn () => $this->redistribuer($decharge, [$this->urnes->id => 120]),
            'Redistribution supérieure au reçu utilisable du préfet pour Urnes : reçu utilisable 100, déjà transféré 0, demandé 120.');
        $this->assertSame(0, Remise::where('etape', 'redistribution')->count());
    }

    public function test_deux_redistributions_plafonnees(): void
    {
        $decharge = $this->recueParLePrefet();
        $this->redistribuer($decharge, [$this->urnes->id => 80]);

        $this->refus(fn () => $this->redistribuer($decharge, [$this->urnes->id => 30]),
            'Redistribution supérieure au reçu utilisable du préfet pour Urnes : reçu utilisable 100, déjà transféré 80, demandé 30.');

        $this->redistribuer($decharge, [$this->urnes->id => 20]);
        $this->assertSame(['recu_utilisable' => 100, 'transfere' => 100, 'reliquat' => 0, 'reserve_appareil' => 0, 'mobilisable' => 0], $this->solde($decharge));
        $this->assertSame(2, Remise::where('etape', 'redistribution')->count());
    }

    public function test_defectueux_non_redistribuables(): void
    {
        $decharge = $this->recueParLePrefet(10);
        $this->assertSame(['recu_utilisable' => 90, 'transfere' => 0, 'reliquat' => 90, 'reserve_appareil' => 0, 'mobilisable' => 90], $this->solde($decharge));

        $this->refus(fn () => $this->redistribuer($decharge, [$this->urnes->id => 91]),
            'Redistribution supérieure au reçu utilisable du préfet pour Urnes : reçu utilisable 90, déjà transféré 0, demandé 91.');
        $this->redistribuer($decharge, [$this->urnes->id => 90]);
        $this->assertSame(0, $this->solde($decharge)['reliquat']);
    }

    public function test_reception_prefet_a_verifier_bloque(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => 110]); // excédent : à vérifier

        $this->refus(fn () => $this->redistribuer($decharge, [$this->urnes->id => 10]),
            'Réception du préfet à vérifier : redistribution impossible.');
    }

    public function test_sans_reception_retenue_refuse(): void
    {
        $decharge = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);

        $this->refus(fn () => $this->redistribuer($decharge, [$this->urnes->id => 10]),
            "Le préfet n'a pas encore de réception retenue pour cette décharge.");
    }

    public function test_sous_prefet_declare_et_decharge_recue_destinataire(): void
    {
        $decharge = $this->recueParLePrefet();
        $this->assertSame('recue_intermediaire', $decharge->statut_acheminement);
        $remise = $this->redistribuer($decharge, [$this->urnes->id => 80]);
        $sousPrefet = $this->autoriteFictive($this->sousPrefecture1);

        $declaration = $this->en($sousPrefet, fn () => app(DeclarationService::class)->declarer(
            $remise, 'compte', ['nom' => 'Ibrahima Ba', 'qualite' => 'Sous-préfet'],
            [$this->urnes->id => ['recu' => 80, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(),
        ));

        $this->assertSame('retenue', $declaration->statut);
        $this->assertSame('recue', $remise->refresh()->statut);
        $this->assertSame('recue_destinataire', $decharge->refresh()->statut_acheminement);
        // Le solde du préfet ne bouge pas à la réception : seul le transfert compte.
        $this->assertSame(['recu_utilisable' => 100, 'transfere' => 80, 'reliquat' => 20, 'reserve_appareil' => 0, 'mobilisable' => 20], $this->solde($decharge));
    }

    public function test_autre_prefet_refuse(): void
    {
        $decharge = $this->recueParLePrefet();
        $this->autoriteFictive($this->prefecture);
        $autres = [
            'autre préfet' => User::factory()->create(['role' => 'prefet']),
            'sous-préfet' => $this->autoriteFictive($this->sousPrefecture1),
            'responsable' => $this->responsable,
            'agent' => $decharge->agent,
        ];

        foreach ($autres as $nom => $auteur) {
            try {
                $this->redistribuer($decharge, [$this->urnes->id => 10], $auteur);
                $this->fail("Le compte {$nom} n'aurait pas dû pouvoir redistribuer.");
            } catch (RegleLogistique $e) {
                $this->assertSame('Redistribution hors de votre périmètre.', $e->getMessage());
            }
        }
        $this->assertSame(0, Remise::where('etape', 'redistribution')->count());
    }

    public function test_decharge_directe_hors_circuit_via_prefet_refusee(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 50], [$this->sousPrefecture1, $this->urnes, 100]]);
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 50]);
        $this->deposerRecue($decharge, [$this->urnes->id => 50]);

        $this->refus(fn () => $this->redistribuer($decharge, [$this->urnes->id => 10]),
            'Redistribution hors de votre périmètre.');
    }

    public function test_annuler_redistribution_libere_le_reliquat(): void
    {
        $decharge = $this->recueParLePrefet();
        $remise = $this->redistribuer($decharge, [$this->urnes->id => 80]);
        $this->assertSame(20, $this->solde($decharge)['reliquat']);

        $this->en($this->autoriteFictive($this->prefecture),
            fn () => app(RemiseService::class)->annulerRedistribution($remise, 'Erreur de saisie'));

        $remise->refresh();
        $this->assertSame('annulee', $remise->statut);
        $this->assertSame('Erreur de saisie', $remise->motif_annulation);
        $this->assertSame(['recu_utilisable' => 100, 'transfere' => 0, 'reliquat' => 100, 'reserve_appareil' => 0, 'mobilisable' => 100], $this->solde($decharge));
        $entree = JournalEntree::where('action', 'annulation_redistribution')->where('objet_id', $remise->id)->sole();
        $this->assertSame('en_attente', $entree->avant['statut']);
        $this->assertSame('Erreur de saisie', $entree->motif);
        // Le reliquat libéré peut être redistribué.
        $this->redistribuer($decharge, [$this->urnes->id => 100]);
    }

    public function test_annulation_refusee_si_declaree_ou_autre_auteur_ou_sans_motif(): void
    {
        $decharge = $this->recueParLePrefet();
        $remise = $this->redistribuer($decharge, [$this->urnes->id => 80]);
        $prefet = $this->autoriteFictive($this->prefecture);
        $service = app(RemiseService::class);

        $this->refus(fn () => $this->en($this->autoriteFictive($this->sousPrefecture1), fn () => $service->annulerRedistribution($remise, 'Motif')),
            'Redistribution hors de votre périmètre.');
        try {
            $this->en($prefet, fn () => $service->annulerRedistribution($remise, '   '));
            $this->fail('Un motif vide aurait dû être refusé.');
        } catch (InvalidArgumentException) {
        }

        $this->en($this->autoriteFictive($this->sousPrefecture1), fn () => app(DeclarationService::class)->declarer(
            $remise, 'compte', ['nom' => 'Ibrahima Ba', 'qualite' => 'Sous-préfet'],
            [$this->urnes->id => ['recu' => 80, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(),
        ));
        $this->assertSame(1, Declaration::where('remise_id', $remise->id)->count());
        $this->refus(fn () => $this->en($prefet, fn () => $service->annulerRedistribution($remise, 'Trop tard')),
            'Seule une redistribution en attente, sans déclaration, peut être annulée.');
        $this->assertSame('recue', $remise->refresh()->statut);
    }

    public function test_lignes_invalides(): void
    {
        $decharge = $this->recueParLePrefet();

        $this->refus(fn () => $this->redistribuer($decharge, []), 'Rien à redistribuer.');
        $this->refus(fn () => $this->redistribuer($decharge, [$this->urnes->id => 0]), 'Rien à redistribuer.');
        foreach ([[$this->isoloirs->id => 5], [$this->urnes->id => -1], [$this->urnes->id => 1.5], ['x' => 5]] as $lignes) {
            try {
                $this->redistribuer($decharge, $lignes);
                $this->fail('Des lignes invalides auraient dû être refusées.');
            } catch (InvalidArgumentException) {
            }
        }
        // Les zéros sont omis.
        $remise = $this->redistribuer($decharge, [$this->urnes->id => 10, $this->isoloirs->id => 0]);
        $this->assertCount(1, $remise->lignes);
    }

    public function test_cycle_cloture_ou_decharge_annulee_refuse(): void
    {
        $decharge = $this->recueParLePrefet();
        $decharge->forceFill(['statut_document' => 'annulee'])->save();
        $this->refus(fn () => $this->redistribuer($decharge, [$this->urnes->id => 10]),
            'Seule une décharge validée peut être redistribuée.');

        $decharge->forceFill(['statut_document' => 'validee'])->save();
        $this->cycle->forceFill(['statut' => 'clos'])->save();
        $this->refus(fn () => $this->redistribuer($decharge, [$this->urnes->id => 10]),
            'Cycle clôturé : redistribution impossible.');
    }

    public function test_sans_auteur_refuse(): void
    {
        $decharge = $this->recueParLePrefet();
        $this->app['auth']->logout();

        $this->expectException(\LogicException::class);
        app(RemiseService::class)->preparerRedistribution($decharge, [$this->urnes->id => 10]);
    }
}

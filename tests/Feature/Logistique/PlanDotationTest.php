<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\CleRepartition;
use App\Models\Logistique\CorrespondanceGeo;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\InstantaneEffectifs;
use App\Models\Logistique\InstantaneLigne;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\PerimetreDestinataire;
use App\Models\Logistique\PlanDotation;
use App\Models\Logistique\PlanDotationLigne;
use App\Models\Logistique\PosteEtranger;
use App\Models\User;
use App\Services\Logistique\CleService;
use App\Services\Logistique\DecoupageService;
use App\Services\Logistique\PlanDotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\RecensementFictif;
use Tests\TestCase;

class PlanDotationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{regions: array<string,int>, departements: array<string,int>, arrondissements: array<string,int>, communes: array<string,int>} */
    private array $geo;

    private Cycle $cycle;

    private User $responsable;

    private Materiel $urnes;

    private Materiel $enveloppes;

    private Destinataire $sindia;

    private Destinataire $thienaba;

    protected function setUp(): void
    {
        parent::setUp();
        RecensementFictif::creerSchema();
        $this->responsable = User::factory()->create(['role' => 'logistique_responsable']);
        $this->actingAs($this->responsable);

        $this->geo = RecensementFictif::geo([
            'Thiès' => [
                'Mbour' => ['Sindia' => ['Ndiass', 'Popenguine']],
                'Thiès' => ['Thiénaba' => ['Thiénaba']],
            ],
        ]);

        $decoupage = app(DecoupageService::class);
        $decoupage->genererDestinataires();
        $this->cycle = Cycle::create(['libelle' => 'Cycle A', 'type' => 'scrutin']);
        $decoupage->genererPerimetre($this->cycle);
        $this->sindia = Destinataire::where('libelle', 'Sous-préfecture de Sindia')->firstOrFail();
        $this->thienaba = Destinataire::where('libelle', 'Sous-préfecture de Thiénaba')->firstOrFail();

        $this->urnes = Materiel::create(['libelle' => 'Urnes', 'categorie' => 'materiel_lourd', 'unite_stock' => 'pièce']);
        $this->enveloppes = Materiel::create(['libelle' => 'Enveloppes', 'categorie' => 'imprime', 'unite_stock' => 'pièce']);
        $cles = app(CleService::class);
        $cles->enregistrer($this->cycle, $this->urnes, [
            'base' => 'bureau_vote', 'ratio' => '1', 'majoration_distribuee' => '0', 'cle_affichee' => '1 par bureau de vote',
        ]);
        $cles->enregistrer($this->cycle, $this->enveloppes, [
            'base' => 'electeur', 'ratio' => '1', 'majoration_distribuee' => '10', 'cle_affichee' => '1 par électeur',
        ]);
    }

    private function service(): PlanDotationService
    {
        return app(PlanDotationService::class);
    }

    /** Jeu commun : Sindia = Ndiass + Popenguine, Thiénaba seule. */
    private function lignesCommunes(): array
    {
        return [
            ['commune' => 'Ndiass', 'departement' => 'Mbour', 'electeurs' => 100, 'bureaux_vote' => 4, 'lieux_vote' => 2, 'commissions' => 1],
            ['commune' => 'Popenguine', 'departement' => 'Mbour', 'electeurs' => 50, 'bureaux_vote' => 2, 'lieux_vote' => 1, 'commissions' => 1],
            ['commune' => 'Thiénaba', 'departement' => 'Thiès', 'electeurs' => 200, 'bureaux_vote' => 5, 'lieux_vote' => 2, 'commissions' => 1],
        ];
    }

    /** @param  list<array<string,mixed>>|null  $lignes */
    private function instantane(?array $lignes = null, string $statut = 'fige', ?Cycle $cycle = null, array $bilan = []): InstantaneEffectifs
    {
        $cycle ??= $this->cycle;
        $instantane = InstantaneEffectifs::create([
            'cycle_id' => $cycle->id,
            'version'  => (int) InstantaneEffectifs::where('cycle_id', $cycle->id)->max('version') + 1,
            'user_id'  => $this->responsable->id,
        ]);
        $instantane->forceFill(['statut' => $statut, 'bilan' => $bilan + ['electeurs_non_rapproches' => 0]])->save();

        foreach ($lignes ?? $this->lignesCommunes() as $l) {
            InstantaneLigne::create([
                'instantane_id'   => $instantane->id,
                'commune_id'      => $this->geo['communes'][$l['commune']],
                'departement_id'  => $this->geo['departements'][$l['departement']],
                'libelle_commune' => $l['commune'],
                'electeurs'       => $l['electeurs'],
                'bureaux_vote'    => $l['bureaux_vote'],
                'lieux_vote'      => $l['lieux_vote'],
                'commissions'     => $l['commissions'],
                'rapprochee'      => true,
            ]);
        }

        return $instantane;
    }

    private function ligne(PlanDotation $plan, Materiel $materiel, Destinataire $destinataire): PlanDotationLigne
    {
        return PlanDotationLigne::where('plan_dotation_id', $plan->id)
            ->where('materiel_id', $materiel->id)->where('destinataire_id', $destinataire->id)->firstOrFail();
    }

    private function sansPopenguine(): array
    {
        return array_values(array_filter($this->lignesCommunes(), fn ($l) => $l['commune'] !== 'Popenguine'));
    }

    public function test_calcul_une_ligne_par_destinataire_et_article(): void
    {
        $plan = $this->service()->calculer($this->cycle, $this->instantane());

        $this->assertSame(1, $plan->version);
        $this->assertSame('brouillon', $plan->statut);
        $this->assertSame($this->responsable->id, $plan->calcule_par);
        $this->assertSame([], $plan->anomalies);
        $this->assertSame(4, $plan->lignes()->count());

        $urnes = $this->ligne($plan, $this->urnes, $this->sindia);
        $this->assertSame(6, $urnes->effectif);
        $this->assertSame('6.0000', $urnes->exact);
        $this->assertSame(6, $urnes->dotation_calculee);
        $this->assertNull($urnes->anomalie);
        $this->assertSame(5, $this->ligne($plan, $this->urnes, $this->thienaba)->dotation_calculee);

        $enveloppes = $this->ligne($plan, $this->enveloppes, $this->sindia);
        $this->assertSame(150, $enveloppes->effectif);
        $this->assertSame('10.00', $enveloppes->majoration);
        $this->assertSame(165, $enveloppes->dotation_calculee);
        $this->assertSame(220, $this->ligne($plan, $this->enveloppes, $this->thienaba)->dotation_calculee);

        $this->assertSame('electeur', $plan->parametres[$this->enveloppes->id]['base']);
        $this->assertSame('10.00', $plan->parametres[$this->enveloppes->id]['majoration']);
        $this->assertSame('0.00', $plan->parametres[$this->enveloppes->id]['reserve']);
        $this->assertSame('bureau_vote', $plan->parametres[$this->urnes->id]['base']);
        $this->assertSame(1, JournalEntree::where('objet_type', 'plan_dotation')->where('action', 'calcul_plan')->count());
    }

    public function test_commune_sans_effectif_en_anomalie_et_approbation_bloquee(): void
    {
        CorrespondanceGeo::create([
            'contexte_region' => 'Thiès', 'contexte_departement' => 'Mbour', 'libelle_commune' => 'Popenguine',
            'commune_id' => $this->geo['communes']['Popenguine'], 'statut' => 'validee', 'methode' => 'auto',
        ]);

        $plan = $this->service()->calculer($this->cycle, $this->instantane($this->sansPopenguine()));

        $this->assertSame(4, $plan->lignes()->count());
        $urnes = $this->ligne($plan, $this->urnes, $this->sindia);
        $this->assertSame('Effectif absent pour Popenguine', $urnes->anomalie);
        $this->assertNull($urnes->dotation_calculee);
        $this->assertSame(4, $urnes->effectif);
        $this->assertSame('4.0000', $urnes->exact);
        $this->assertNotNull($this->ligne($plan, $this->urnes, $this->thienaba)->dotation_calculee);

        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Plan non approuvable : 2 anomalie(s).');
        $this->service()->approuver($plan);
    }

    public function test_commune_absente_sans_correspondance_nommee_par_son_numero(): void
    {
        $plan = $this->service()->calculer($this->cycle, $this->instantane($this->sansPopenguine()));

        $this->assertSame(
            'Effectif absent pour commune n° '.$this->geo['communes']['Popenguine'],
            $this->ligne($plan, $this->urnes, $this->sindia)->anomalie,
        );
    }

    public function test_aucune_commission_en_anomalie_pour_la_base_ca(): void
    {
        app(CleService::class)->enregistrer($this->cycle, $this->urnes, ['base' => 'ca']);
        $lignes = $this->lignesCommunes();
        $lignes[0]['commissions'] = 0;

        $plan = $this->service()->calculer($this->cycle, $this->instantane($lignes));

        $this->assertSame('Aucune commission administrative pour Ndiass', $this->ligne($plan, $this->urnes, $this->sindia)->anomalie);
        $this->assertNull($this->ligne($plan, $this->urnes, $this->sindia)->dotation_calculee);
        $this->assertSame(1, $this->ligne($plan, $this->urnes, $this->thienaba)->dotation_calculee);
    }

    public function test_electeurs_non_rapproches_bloquent_l_approbation(): void
    {
        $plan = $this->service()->calculer(
            $this->cycle,
            $this->instantane(null, 'fige', null, ['electeurs_non_rapproches' => 7]),
        );

        $this->assertSame(['7 électeurs non rapprochés'], $plan->anomalies);

        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Plan non approuvable : 1 anomalie(s).');
        $this->service()->approuver($plan);
    }

    public function test_localite_hors_perimetre_signalee_sans_destinataire(): void
    {
        $instantane = $this->instantane();
        InstantaneLigne::create([
            'instantane_id' => $instantane->id, 'pays' => 'France', 'ville' => 'Lyon', 'libelle_commune' => 'Lyon',
            'electeurs' => 12, 'bureaux_vote' => 1, 'lieux_vote' => 1, 'rapprochee' => true,
        ]);
        PerimetreDestinataire::where('cycle_id', $this->cycle->id)
            ->where('commune_id', $this->geo['communes']['Popenguine'])->delete();

        $plan = $this->service()->calculer($this->cycle, $instantane);

        $this->assertEqualsCanonicalizing(
            ['Popenguine sans destinataire', 'Lyon (France) sans destinataire'],
            $plan->anomalies,
        );
        // Popenguine n'est plus au périmètre de Sindia : ses électeurs n'entrent pas dans la dotation.
        $this->assertSame(4, $this->ligne($plan, $this->urnes, $this->sindia)->effectif);
    }

    public function test_ville_etrangere_rapprochee_du_perimetre_malgre_la_casse(): void
    {
        $poste = PosteEtranger::create(['pays' => 'France', 'ville' => 'Paris', 'type' => 'consulat', 'nom' => 'Consulat de Paris']);
        app(DecoupageService::class)->genererDestinataires();
        $consulat = Destinataire::where('poste_etranger_id', $poste->id)->firstOrFail();
        PerimetreDestinataire::create(['cycle_id' => $this->cycle->id, 'destinataire_id' => $consulat->id, 'pays' => 'France', 'ville' => 'Paris']);
        $instantane = $this->instantane();
        InstantaneLigne::create([
            'instantane_id' => $instantane->id, 'pays' => 'FRANCE', 'ville' => 'PARIS ',
            'electeurs' => 12, 'bureaux_vote' => 1, 'lieux_vote' => 1, 'rapprochee' => true,
        ]);

        $plan = $this->service()->calculer($this->cycle, $instantane);

        $this->assertSame([], $plan->anomalies);
        $ligne = $this->ligne($plan, $this->urnes, $consulat);
        $this->assertNull($ligne->anomalie);
        $this->assertSame(1, $ligne->dotation_calculee);
    }

    public function test_correction_motivee_puis_approbation(): void
    {
        $plan = $this->service()->calculer($this->cycle, $this->instantane());
        $ligne = $this->ligne($plan, $this->urnes, $this->sindia);

        $this->service()->corriger($ligne, 30, 'arbitrage');

        $ligne->refresh();
        $this->assertSame(30, $ligne->correction);
        $this->assertSame('arbitrage', $ligne->correction_motif);
        $this->assertSame($this->responsable->id, $ligne->corrige_par);
        $this->assertNotNull($ligne->corrige_le);
        $this->assertSame(6, $ligne->dotation_calculee);
        $entree = JournalEntree::where('objet_type', 'plan_dotation_ligne')->where('action', 'correction_dotation')->firstOrFail();
        $this->assertNull($entree->avant['correction']);
        $this->assertSame(30, $entree->apres['correction']);
        $this->assertSame('arbitrage', $entree->motif);

        $this->service()->approuver($plan);

        $plan->refresh();
        $this->assertSame('approuve', $plan->statut);
        $this->assertSame($this->responsable->id, $plan->approuve_par);
        $this->assertNotNull($plan->approuve_le);
        $this->assertSame(30, $ligne->fresh()->dotationApprouvee());
        $this->assertSame(165, $this->ligne($plan, $this->enveloppes, $this->sindia)->dotationApprouvee());
        $this->assertSame(1, JournalEntree::where('objet_type', 'plan_dotation')->where('action', 'approbation_plan')->count());
    }

    public function test_correction_exige_un_motif_et_une_valeur_positive(): void
    {
        $plan = $this->service()->calculer($this->cycle, $this->instantane());
        $ligne = $this->ligne($plan, $this->urnes, $this->sindia);

        foreach ([[30, '  '], [-1, 'arbitrage']] as [$valeur, $motif]) {
            try {
                $this->service()->corriger($ligne, $valeur, $motif);
                $this->fail('Une correction invalide doit être refusée.');
            } catch (InvalidArgumentException) {
                $this->assertNull($ligne->fresh()->correction);
            }
        }
    }

    public function test_correction_reprise_dans_la_version_suivante(): void
    {
        $instantane = $this->instantane();
        $v1 = $this->service()->calculer($this->cycle, $instantane);
        $this->service()->corriger($this->ligne($v1, $this->urnes, $this->sindia), 30, 'arbitrage');
        $avant = $this->ligne($v1, $this->urnes, $this->sindia)->fresh();

        $v2 = $this->service()->calculer($this->cycle, $instantane);

        $this->assertSame(2, $v2->version);
        $this->assertSame('remplace', $v1->fresh()->statut);
        $reprise = $this->ligne($v2, $this->urnes, $this->sindia);
        $this->assertSame(30, $reprise->correction);
        $this->assertSame('arbitrage', $reprise->correction_motif);
        $this->assertSame($avant->corrige_par, $reprise->corrige_par);
        $this->assertTrue($avant->corrige_le->equalTo($reprise->corrige_le));
        $this->assertSame(6, $reprise->dotation_calculee);
        $this->assertNull($this->ligne($v2, $this->enveloppes, $this->sindia)->correction);
    }

    public function test_besoin_suit_la_derniere_version_approuvee(): void
    {
        $instantane = $this->instantane();
        $v1 = $this->service()->calculer($this->cycle, $instantane);
        $this->service()->approuver($v1);
        $v2 = $this->service()->calculer($this->cycle, $instantane);

        $this->assertSame($v1->id, PlanDotation::courantApprouve($this->cycle)?->id);
        $this->assertSame('approuve', $v1->fresh()->statut);

        $this->service()->approuver($v2);

        $this->assertSame($v2->id, PlanDotation::courantApprouve($this->cycle)?->id);
        $this->assertSame('remplace', $v1->fresh()->statut);
    }

    public function test_bulletin_departemental_hors_perimetre_absent(): void
    {
        $cles = app(CleService::class);
        $limitee = $cles->ajouterEntite($this->cycle, [
            'type' => 'candidat', 'nom' => 'Candidat Mbour', 'national' => false,
            'perimetre' => ['departements' => [$this->geo['departements']['Mbour']]],
        ]);
        $nationale = $cles->ajouterEntite($this->cycle, ['type' => 'candidat', 'nom' => 'Candidat National']);

        $plan = $this->service()->calculer($this->cycle, $this->instantane());

        $bulletins = PlanDotationLigne::where('plan_dotation_id', $plan->id)->where('materiel_id', $limitee->materiel_id)->get();
        $this->assertCount(1, $bulletins);
        $this->assertSame($this->sindia->id, $bulletins->first()->destinataire_id);
        $this->assertSame(150, $bulletins->first()->effectif);
        $this->assertSame(2, PlanDotationLigne::where('plan_dotation_id', $plan->id)->where('materiel_id', $nationale->materiel_id)->count());
    }

    public function test_cle_sur_bulletin_d_un_autre_cycle_ignoree_et_signalee(): void
    {
        $autre = Cycle::create(['libelle' => 'Tour 1', 'type' => 'scrutin']);
        $entite = app(CleService::class)->ajouterEntite($autre, ['type' => 'candidat', 'nom' => 'Candidat X']);
        // Donnée ancienne : la clé a été enregistrée avant le contrôle du cycle du bulletin.
        CleRepartition::create([
            'cycle_id' => $this->cycle->id, 'materiel_id' => $entite->materiel_id, 'base' => 'electeur', 'ratio' => '1',
            'majoration_distribuee' => '0', 'minimum' => 0, 'arrondi' => 'unite',
        ]);

        $plan = $this->service()->calculer($this->cycle, $this->instantane());

        $this->assertSame(0, PlanDotationLigne::where('plan_dotation_id', $plan->id)->where('materiel_id', $entite->materiel_id)->count());
        $this->assertArrayNotHasKey($entite->materiel_id, $plan->parametres);
        $this->assertContains("Clé ignorée : bulletin d'un autre cycle (Bulletin de vote — Candidat X)", $plan->anomalies);
        $this->assertSame(6, $this->ligne($plan, $this->urnes, $this->sindia)->dotation_calculee);
    }

    public function test_instantane_non_fige_refuse(): void
    {
        $this->expectException(RegleLogistique::class);

        $this->service()->calculer($this->cycle, $this->instantane(null, 'brouillon'));
    }

    public function test_instantane_d_un_autre_cycle_refuse(): void
    {
        $autre = Cycle::create(['libelle' => 'Cycle B', 'type' => 'scrutin']);

        $this->expectException(RegleLogistique::class);

        $this->service()->calculer($this->cycle, $this->instantane(null, 'fige', $autre));
    }

    public function test_calcul_sans_cle_refuse(): void
    {
        $autre = Cycle::create(['libelle' => 'Cycle B', 'type' => 'scrutin']);

        $this->expectException(RegleLogistique::class);

        $this->service()->calculer($autre, $this->instantane(null, 'fige', $autre));
    }

    public function test_correction_d_un_plan_approuve_refusee(): void
    {
        $plan = $this->service()->calculer($this->cycle, $this->instantane());
        $this->service()->approuver($plan);

        $this->expectException(RegleLogistique::class);

        $this->service()->corriger($this->ligne($plan, $this->urnes, $this->sindia), 30, 'arbitrage');
    }

    public function test_le_gestionnaire_calcule_mais_ne_corrige_ni_n_approuve(): void
    {
        $plan = $this->service()->calculer($this->cycle, $this->instantane());
        $this->actingAs(User::factory()->create(['role' => 'logistique_gestionnaire']));

        $this->assertSame(2, $this->service()->calculer($this->cycle, $this->instantane())->version);

        $ligne = $this->ligne($plan, $this->urnes, $this->sindia);
        foreach ([fn () => $this->service()->corriger($ligne, 30, 'arbitrage'), fn () => $this->service()->approuver($plan)] as $action) {
            try {
                $action();
                $this->fail('Seul le responsable corrige et approuve.');
            } catch (RegleLogistique) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_calcul_sur_cycle_clos_refuse(): void
    {
        $instantane = $this->instantane();
        $this->cycle->forceFill(['statut' => 'clos'])->save();

        $this->expectException(RegleLogistique::class);

        $this->service()->calculer($this->cycle, $instantane);
    }

    public function test_pas_de_requete_par_ligne(): void
    {
        $instantane = $this->instantane();
        $lignes = $this->lignesCommunes();
        for ($n = 1; $n <= 40; $n++) {
            $destinataire = Destinataire::create(['type' => 'poste', 'libelle' => "Poste {$n}"]);
            PerimetreDestinataire::create([
                'cycle_id' => $this->cycle->id, 'destinataire_id' => $destinataire->id, 'pays' => 'Pays', 'ville' => "Ville {$n}",
            ]);
            InstantaneLigne::create([
                'instantane_id' => $instantane->id, 'pays' => 'Pays', 'ville' => "Ville {$n}", 'libelle_commune' => "Ville {$n}",
                'electeurs' => 10 + $n, 'bureaux_vote' => 1, 'lieux_vote' => 1, 'rapprochee' => true,
            ]);
        }

        DB::enableQueryLog();
        $plan = $this->service()->calculer($this->cycle, $instantane);
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(2 * 42, $plan->lignes()->count());
        $this->assertLessThan(20, $requetes);
    }

    public function test_localite_retiree_du_perimetre_n_est_plus_absente(): void
    {
        $instantane = $this->instantane($this->sansPopenguine());
        $this->assertNotNull($this->ligne($this->service()->calculer($this->cycle, $instantane), $this->urnes, $this->sindia)->anomalie);

        app(DecoupageService::class)->retirer($this->cycle, $this->geo['communes']['Popenguine'], null, null, 'commune sans électeur');

        $this->assertSame(0, PerimetreDestinataire::where('cycle_id', $this->cycle->id)
            ->where('commune_id', $this->geo['communes']['Popenguine'])->count());
        $entree = JournalEntree::where('objet_type', 'perimetre_destinataire')->where('action', 'retrait_perimetre')->firstOrFail();
        $this->assertSame($this->sindia->id, $entree->avant['destinataire_id']);
        $this->assertSame('commune sans électeur', $entree->motif);

        $plan = $this->service()->calculer($this->cycle, $instantane);

        $urnes = $this->ligne($plan, $this->urnes, $this->sindia);
        $this->assertNull($urnes->anomalie);
        $this->assertSame(4, $urnes->dotation_calculee);
    }

    public function test_le_gestionnaire_ne_peut_pas_retirer_une_localite(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'logistique_gestionnaire']));

        try {
            app(DecoupageService::class)->retirer($this->cycle, $this->geo['communes']['Popenguine'], null, null, 'erreur de saisie');
            $this->fail('Seul le responsable retire une localité.');
        } catch (RegleLogistique) {
            $this->assertSame(1, PerimetreDestinataire::where('cycle_id', $this->cycle->id)
                ->where('commune_id', $this->geo['communes']['Popenguine'])->count());
        }
    }

    public function test_retrait_exige_motif_localite_unique_et_presente(): void
    {
        $decoupage = app(DecoupageService::class);
        $commune = $this->geo['communes']['Popenguine'];

        $this->assertRefus(fn () => $decoupage->retirer($this->cycle, $commune, null, null, ' '), InvalidArgumentException::class);
        $this->assertRefus(fn () => $decoupage->retirer($this->cycle, $commune, 'France', 'Lyon', 'motif'), InvalidArgumentException::class);
        $this->assertRefus(fn () => $decoupage->retirer($this->cycle, null, 'France', null, 'motif'), InvalidArgumentException::class);
        $this->assertRefus(fn () => $decoupage->retirer($this->cycle, null, 'France', 'Lyon', 'motif'), RegleLogistique::class);
        $this->assertSame(3, PerimetreDestinataire::where('cycle_id', $this->cycle->id)->count());
    }

    /** @param  class-string<\Throwable>  $classe */
    private function assertRefus(callable $action, string $classe): void
    {
        $leve = null;
        try {
            $action();
        } catch (\Throwable $e) {
            $leve = $e;
        }

        $this->assertInstanceOf($classe, $leve);
    }
}

<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Exceptions\Logistique\StockInsuffisant;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\MouvementStock;
use App\Models\Logistique\Restitution;
use App\Models\Logistique\RestitutionDeclaration;
use App\Models\Logistique\RestitutionLigne;
use App\Models\User;
use App\Services\Logistique\BesoinService;
use App\Services\Logistique\RestitutionService;
use App\Services\Logistique\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class ControleRestitutionTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerDecorFictif();
        $this->stock = app(StockService::class);
        $this->stock->inventaireInitial($this->urnes, 100, 'Inventaire magasin');
        $this->stock->inventaireInitial($this->isoloirs, 50, 'Inventaire magasin');
    }

    private function service(): RestitutionService
    {
        return app(RestitutionService::class);
    }

    /**
     * Restitution du sous-préfet portant un attendu par article ; l'attendu est aussi le retour déclaré.
     *
     * @param  array<int, int>  $attendus  attendu par identifiant d'article
     */
    private function restitution(array $attendus, string $statut): Restitution
    {
        $restitution = (new Restitution(['cycle_id' => $this->cycle->id, 'detenteur_type' => 'destinataire', 'detenteur_id' => $this->sousPrefecture1->id]))
            ->forceFill(['ouverte_par' => $this->responsable->id, 'statut' => $statut]);
        $restitution->save();
        foreach ($attendus as $materielId => $attendu) {
            RestitutionLigne::create(['restitution_id' => $restitution->id, 'materiel_id' => $materielId, 'attendu' => $attendu])
                ->forceFill(['declare_restitue' => $attendu])->save();
        }

        return $restitution;
    }

    /** Restitution reçue au central : la réception a donné le reçu par article. */
    private function recue(array $recus): Restitution
    {
        $restitution = $this->restitution($recus, 'recue_central');
        foreach ($recus as $materielId => $recu) {
            RestitutionLigne::where('restitution_id', $restitution->id)->where('materiel_id', $materielId)->update(['recu_central' => $recu]);
        }

        return $restitution;
    }

    private function ligne(Restitution $r, Materiel $materiel): RestitutionLigne
    {
        return RestitutionLigne::where('restitution_id', $r->id)->where('materiel_id', $materiel->id)->sole();
    }

    private function mouvements(): int
    {
        return MouvementStock::count();
    }

    private function controle(int $bon, int $aReparer, int $reforme): array
    {
        return ['bon' => $bon, 'a_reparer' => $aReparer, 'reforme' => $reforme];
    }

    public function test_reception_enregistre_le_recu_et_passe_a_recue_central(): void
    {
        $r = $this->restitution([$this->urnes->id => 500, $this->isoloirs->id => 40], 'en_retour');
        $avant = $this->mouvements();

        $this->service()->receptionner($r, [$this->urnes->id => 495, $this->isoloirs->id => 40]);

        $this->assertSame('recue_central', $r->statut);
        $this->assertSame('recue_central', $r->fresh()->statut);
        $this->assertSame(495, $this->ligne($r, $this->urnes)->recu_central);
        $this->assertSame(40, $this->ligne($r, $this->isoloirs)->recu_central);
        $this->assertSame($avant, $this->mouvements(), 'La réception ne touche pas au stock.');
        $entree = JournalEntree::where('action', 'reception_restitution')->sole();
        $this->assertSame('restitution', $entree->objet_type);
        $this->assertSame($r->id, $entree->objet_id);
        $this->assertSame($this->responsable->id, $entree->user_id);
    }

    public function test_reception_accepte_un_recu_nul(): void
    {
        $r = $this->restitution([$this->urnes->id => 10], 'en_retour');

        $this->service()->receptionner($r, [$this->urnes->id => 0]);

        $this->assertSame('recue_central', $r->fresh()->statut);
        $this->assertSame(0, $this->ligne($r, $this->urnes)->recu_central);
    }

    public function test_reception_refuse_hors_en_retour(): void
    {
        foreach (['a_preparer', 'recue_central', 'controlee'] as $statut) {
            $r = $this->restitution([$this->urnes->id => 10], $statut);

            try {
                $this->service()->receptionner($r, [$this->urnes->id => 10]);
                $this->fail("Réception acceptée au statut {$statut}.");
            } catch (RegleLogistique $e) {
                $this->assertSame('Seul un retour pris en charge peut être réceptionné au central.', $e->getMessage());
            }
            $this->assertSame($statut, $r->fresh()->statut);
        }
    }

    public function test_reception_refuse_les_lignes_incorrectes(): void
    {
        $r = $this->restitution([$this->urnes->id => 10, $this->isoloirs->id => 5], 'en_retour');

        try {
            $this->service()->receptionner($r, [$this->urnes->id => 10, $this->isoloirs->id => 5, $this->registres->id => 1]);
            $this->fail('Article inconnu accepté.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Article hors de cette restitution.', $e->getMessage());
        }

        try {
            $this->service()->receptionner($r, [$this->urnes->id => 10]);
            $this->fail('Ligne manquante acceptée.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Chaque ligne de la restitution doit être reçue.', $e->getMessage());
        }

        foreach ([-1, 1.5, 'x', null, 10_000_001] as $mauvais) {
            try {
                $this->service()->receptionner($r, [$this->urnes->id => $mauvais, $this->isoloirs->id => 5]);
                $this->fail('Quantité invalide acceptée.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Les quantités reçues doivent être des entiers de 0 à 10 000 000.', $e->getMessage());
            }
        }

        $this->assertSame('en_retour', $r->fresh()->statut);
        $this->assertSame(0, JournalEntree::where('action', 'reception_restitution')->count());
    }

    public function test_reception_et_controle_exigent_le_droit_de_gerer_et_un_auteur(): void
    {
        $r = $this->restitution([$this->urnes->id => 10], 'en_retour');
        $c = $this->recue([$this->isoloirs->id => 10]);

        $this->actingAs(User::factory()->create(['role' => 'agent_transport']));
        try {
            $this->service()->receptionner($r, [$this->urnes->id => 10]);
            $this->fail('Réception acceptée sans le droit.');
        } catch (RegleLogistique) {
            $this->assertSame('en_retour', $r->fresh()->statut);
        }
        try {
            $this->service()->controler($c, [$this->isoloirs->id => $this->controle(10, 0, 0)]);
            $this->fail('Contrôle accepté sans le droit.');
        } catch (RegleLogistique) {
            $this->assertSame('recue_central', $c->fresh()->statut);
        }

        // Un gestionnaire reçoit et contrôle.
        $this->actingAs(User::factory()->create(['role' => 'logistique_gestionnaire']));
        $this->service()->receptionner($r, [$this->urnes->id => 10]);
        $this->service()->controler($c, [$this->isoloirs->id => $this->controle(10, 0, 0)]);
        $this->assertSame('controlee', $c->fresh()->statut);

        $this->app['auth']->forgetGuards();
        $this->expectException(LogicException::class);
        $this->service()->receptionner($this->restitution([$this->urnes->id => 1], 'en_retour'), [$this->urnes->id => 1]);
    }

    public function test_500_restituees_480_bon_20_reformees(): void
    {
        $r = $this->recue([$this->urnes->id => 500]);
        $libre = $this->stock->libre($this->urnes);
        $mouvements = $this->mouvements();

        $this->service()->controler($r, [$this->urnes->id => $this->controle(480, 0, 20)]);

        // Seul le « bon » augmente le libre ; rien ne bouge pour le réformé, et rien n'entre à réparer.
        $this->assertSame($libre + 480, $this->stock->libre($this->urnes));
        $this->assertSame(580, $this->stock->physique($this->urnes));
        $this->assertSame(0, $this->stock->physique($this->urnes, 'a_reparer'));
        $this->assertSame($mouvements + 1, $this->mouvements(), 'Un seul mouvement : les 480 bons.');
        $mouvement = MouvementStock::where('type', 'reintegration')->sole();
        $this->assertSame(480, $mouvement->quantite);
        $this->assertSame('bon', $mouvement->etat);
        $this->assertSame('restitution_lignes', $mouvement->reference_type);
        $this->assertSame($this->ligne($r, $this->urnes)->id, $mouvement->reference_id);
        $this->assertStringContainsString((string) $r->id, $mouvement->motif);
        $this->assertSame(0, MouvementStock::where('type', 'reforme')->count());

        $ligne = $this->ligne($r, $this->urnes);
        $this->assertSame([480, 0, 20], [$ligne->bon, $ligne->a_reparer, $ligne->reforme]);
        $this->assertNull($ligne->motif_reforme);
        $this->assertNull($ligne->reforme_decidee_par);
        $this->assertSame('recue_central', $r->fresh()->statut, 'Le réformé attend la décision du responsable.');

        $decideur = User::factory()->create(['role' => 'logistique_responsable']);
        $this->actingAs($decideur);
        $this->service()->deciderReforme($ligne, '  Urnes brisées par le transport  ');

        $ligne->refresh();
        $this->assertSame('Urnes brisées par le transport', $ligne->motif_reforme);
        $this->assertSame($decideur->id, $ligne->reforme_decidee_par);
        $this->assertSame('controlee', $r->fresh()->statut);
        $this->assertSame($mouvements + 1, $this->mouvements(), 'La décision ne crée aucun mouvement.');
        $this->assertSame(580, $this->stock->physique($this->urnes));

        $controle = JournalEntree::where('action', 'controle_restitution')->sole();
        $this->assertSame($r->id, $controle->objet_id);
        $decision = JournalEntree::where('action', 'decision_reforme_restitution')->sole();
        $this->assertSame($decideur->id, $decision->user_id);
        $this->assertSame('Urnes brisées par le transport', $decision->motif);
    }

    public function test_proposition_de_commande_reduite(): void
    {
        $suivant = Cycle::create(['libelle' => 'Cycle B', 'type' => 'scrutin']);
        $precedent = $this->cycle;
        $this->cycle = $suivant;
        $this->approuverPlanFictif([[$this->sousPrefecture1, $this->urnes, 1000]]);
        $this->cycle = $precedent;

        $besoins = app(BesoinService::class);
        $avant = $besoins->approvisionnementRestant($suivant, $this->urnes);
        $this->assertSame(1000, $avant);

        // Avant la restitution, le stock ne couvre pas l'attribution.
        try {
            $this->stock->attribuer($suivant, $this->urnes, 480, 'Réemploi');
            $this->fail('Attribution acceptée sans stock.');
        } catch (StockInsuffisant) {
        }

        $r = $this->recue([$this->urnes->id => 500]);
        $this->service()->controler($r, [$this->urnes->id => $this->controle(480, 0, 20)]);

        $this->stock->attribuer($suivant, $this->urnes, 480, 'Réemploi des urnes restituées');

        $this->assertSame($avant - 480, $besoins->approvisionnementRestant($suivant, $this->urnes));
    }

    public function test_controle_incomplet_refuse(): void
    {
        $r = $this->recue([$this->urnes->id => 500, $this->isoloirs->id => 20]);
        $mouvements = $this->mouvements();

        // Le premier article est juste, le second incomplet : rien n'est écrit.
        try {
            $this->service()->controler($r, [
                $this->urnes->id => $this->controle(480, 0, 20),
                $this->isoloirs->id => $this->controle(10, 5, 4),
            ]);
            $this->fail('Contrôle incomplet accepté.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Contrôle incomplet pour Isoloirs : bon + à réparer + réformé doit valoir 20.', $e->getMessage());
        }

        $this->assertSame('recue_central', $r->fresh()->statut);
        $this->assertSame($mouvements, $this->mouvements());
        $this->assertSame(0, $this->ligne($r, $this->urnes)->bon);
        $this->assertSame(0, JournalEntree::where('action', 'controle_restitution')->count());

        // Dépasser le reçu est aussi un contrôle incomplet.
        $this->expectException(InvalidArgumentException::class);
        $this->service()->controler($r, [
            $this->urnes->id => $this->controle(500, 1, 0),
            $this->isoloirs->id => $this->controle(20, 0, 0),
        ]);
    }

    public function test_controle_refuse_les_lignes_et_les_quantites_incorrectes(): void
    {
        $r = $this->recue([$this->urnes->id => 10, $this->isoloirs->id => 5]);
        $bon = [$this->urnes->id => $this->controle(10, 0, 0), $this->isoloirs->id => $this->controle(5, 0, 0)];

        try {
            $this->service()->controler($r, $bon + [$this->registres->id => $this->controle(1, 0, 0)]);
            $this->fail('Article inconnu accepté.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Article hors de cette restitution.', $e->getMessage());
        }

        try {
            $this->service()->controler($r, [$this->urnes->id => $this->controle(10, 0, 0)]);
            $this->fail('Ligne manquante acceptée.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Chaque ligne de la restitution doit être contrôlée.', $e->getMessage());
        }

        foreach ([['bon' => -1, 'a_reparer' => 11, 'reforme' => 0], ['bon' => 5.5, 'a_reparer' => 4.5, 'reforme' => 0], ['bon' => 10, 'a_reparer' => 0], ['bon' => 'x', 'a_reparer' => 0, 'reforme' => 0], 7] as $mauvais) {
            try {
                $this->service()->controler($r, [$this->urnes->id => $mauvais, $this->isoloirs->id => $this->controle(5, 0, 0)]);
                $this->fail('Contrôle invalide accepté.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Les quantités contrôlées doivent être des entiers de 0 à 10 000 000.', $e->getMessage());
            }
        }

        $this->assertSame('recue_central', $r->fresh()->statut);
        $this->assertSame(0, MouvementStock::where('type', 'reintegration')->count());
    }

    public function test_a_reparer_hors_libre(): void
    {
        $r = $this->recue([$this->urnes->id => 100]);
        $libre = $this->stock->libre($this->urnes);

        $this->service()->controler($r, [$this->urnes->id => $this->controle(60, 40, 0)]);

        $this->assertSame($libre + 60, $this->stock->libre($this->urnes), 'Seul le bon augmente le libre.');
        $this->assertSame(40, $this->stock->physique($this->urnes, 'a_reparer'));
        $this->assertSame(160, $this->stock->physique($this->urnes));
        $this->assertSame(2, MouvementStock::where('type', 'reintegration')->count());
        $this->assertSame(40, MouvementStock::where('type', 'reintegration')->where('etat', 'a_reparer')->sole()->quantite);
        $ligne = $this->ligne($r, $this->urnes);
        $this->assertSame([60, 40, 0], [$ligne->bon, $ligne->a_reparer, $ligne->reforme]);
        $this->assertSame('controlee', $r->fresh()->statut, 'Aucune réforme n\'attend : le contrôle est clos.');
    }

    public function test_reforme_attend_la_decision(): void
    {
        $r = $this->recue([$this->urnes->id => 30, $this->isoloirs->id => 10]);
        $this->service()->controler($r, [
            $this->urnes->id => $this->controle(25, 0, 5),
            $this->isoloirs->id => $this->controle(6, 0, 4),
        ]);
        $this->assertSame('recue_central', $r->fresh()->statut);

        // Un gestionnaire ne décide pas de la réforme.
        $this->actingAs(User::factory()->create(['role' => 'logistique_gestionnaire']));
        try {
            $this->service()->deciderReforme($this->ligne($r, $this->urnes), 'Brisées');
            $this->fail('Décision acceptée sans le droit d\'administrer.');
        } catch (RegleLogistique) {
        }

        $this->actingAs($this->responsable);
        foreach (['', '   ', str_repeat('a', 501)] as $motif) {
            try {
                $this->service()->deciderReforme($this->ligne($r, $this->urnes), $motif);
                $this->fail('Motif invalide accepté.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Le motif est obligatoire (500 caractères au plus).', $e->getMessage());
            }
        }
        $this->assertNull($this->ligne($r, $this->urnes)->motif_reforme);

        // La première décision laisse la restitution ouverte ; la dernière la clôt.
        $this->service()->deciderReforme($this->ligne($r, $this->urnes), 'Brisées');
        $this->assertSame('recue_central', $r->fresh()->statut);
        $this->service()->deciderReforme($this->ligne($r, $this->isoloirs), 'Rouillés');
        $this->assertSame('controlee', $r->fresh()->statut);

        // Une réforme ne se décide qu'une fois.
        try {
            $this->service()->deciderReforme($this->ligne($r, $this->urnes), 'Autre motif');
            $this->fail('Seconde décision acceptée.');
        } catch (RegleLogistique) {
            $this->assertSame('Brisées', $this->ligne($r, $this->urnes)->motif_reforme);
        }
        $this->assertSame(2, MouvementStock::where('type', 'reintegration')->count());
    }

    public function test_decision_refusee_sans_reforme_a_decider(): void
    {
        $r = $this->recue([$this->urnes->id => 10, $this->isoloirs->id => 10]);

        // Avant le contrôle, rien n'est réformé.
        try {
            $this->service()->deciderReforme($this->ligne($r, $this->urnes), 'Trop tôt');
            $this->fail('Décision acceptée avant le contrôle.');
        } catch (RegleLogistique) {
        }

        $this->service()->controler($r, [
            $this->urnes->id => $this->controle(10, 0, 0),
            $this->isoloirs->id => $this->controle(8, 0, 2),
        ]);

        try {
            $this->service()->deciderReforme($this->ligne($r, $this->urnes), 'Rien à réformer');
            $this->fail('Décision acceptée sur une ligne sans réforme.');
        } catch (RegleLogistique) {
            $this->assertNull($this->ligne($r, $this->urnes)->motif_reforme);
        }
        $this->assertSame(0, JournalEntree::where('action', 'decision_reforme_restitution')->count());
    }

    public function test_controle_refuse_hors_recue_central_ou_deja_fait(): void
    {
        foreach (['a_preparer', 'en_retour', 'controlee'] as $statut) {
            $r = $this->restitution([$this->urnes->id => 10], $statut);
            try {
                $this->service()->controler($r, [$this->urnes->id => $this->controle(10, 0, 0)]);
                $this->fail("Contrôle accepté au statut {$statut}.");
            } catch (RegleLogistique $e) {
                $this->assertSame('Seule une restitution reçue au central peut être contrôlée.', $e->getMessage());
            }
        }

        // Un réformé en attente de décision laisse le statut « reçue » : le contrôle ne se refait pas.
        $r = $this->recue([$this->urnes->id => 10]);
        $this->service()->controler($r, [$this->urnes->id => $this->controle(6, 0, 4)]);
        $this->assertSame('recue_central', $r->fresh()->statut);
        try {
            $this->service()->controler($r, [$this->urnes->id => $this->controle(10, 0, 0)]);
            $this->fail('Second contrôle accepté.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Ce retour est déjà contrôlé.', $e->getMessage());
        }
        $this->assertSame(1, MouvementStock::where('type', 'reintegration')->count());
        $this->assertSame(106, $this->stock->physique($this->urnes));
    }

    public function test_controle_refuse_tant_qu_une_declaration_est_a_verifier(): void
    {
        $r = $this->recue([$this->urnes->id => 10]);
        $declaration = (new RestitutionDeclaration([
            'restitution_id' => $r->id, 'mode' => 'compte', 'signataire_nom' => 'Ibrahima Ba', 'signataire_qualite' => 'Sous-préfet',
            'user_id' => $this->responsable->id, 'cle_idempotence' => 'cle-restitution-0001', 'empreinte_contenu' => str_repeat('a', 64),
        ]))->forceFill(['statut' => 'a_verifier']);
        $declaration->save();

        try {
            $this->service()->controler($r, [$this->urnes->id => $this->controle(10, 0, 0)]);
            $this->fail('Contrôle accepté avec une déclaration à vérifier.');
        } catch (RegleLogistique $e) {
            $this->assertSame("Une déclaration à vérifier attend l'arbitrage du responsable : le contrôle est impossible.", $e->getMessage());
        }
        $this->assertSame('recue_central', $r->fresh()->statut);
        $this->assertSame(0, MouvementStock::where('type', 'reintegration')->count());

        // Arbitrée (écartée), la déclaration ne bloque plus.
        $declaration->forceFill(['statut' => 'ecartee'])->save();
        $this->service()->controler($r, [$this->urnes->id => $this->controle(10, 0, 0)]);
        $this->assertSame('controlee', $r->fresh()->statut);
    }

    public function test_restitution_apres_cloture(): void
    {
        $r = $this->restitution([$this->urnes->id => 20], 'en_retour');
        $this->stock->cloturerCycle($this->cycle, 'Fin du scrutin');
        $this->assertFalse($this->cycle->fresh()->estOuvert());

        $this->service()->receptionner($r, [$this->urnes->id => 20]);
        $this->service()->controler($r, [$this->urnes->id => $this->controle(15, 3, 2)]);
        $this->service()->deciderReforme($this->ligne($r, $this->urnes), 'Cassées');

        $this->assertSame('controlee', $r->fresh()->statut);
        $this->assertSame(115, $this->stock->physique($this->urnes));
        $this->assertSame(3, $this->stock->physique($this->urnes, 'a_reparer'));
    }
}

<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Delegation;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Restitution;
use App\Models\Logistique\RestitutionDeclaration;
use App\Models\Logistique\RestitutionDocument;
use App\Models\Logistique\RestitutionLigne;
use App\Models\User;
use App\Services\Logistique\RestitutionPdfService;
use App\Services\Logistique\RestitutionService;
use App\Services\Logistique\StockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class FicheRestitutionTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    private const ALTERE = 'Fichier archivé altéré ou absent : réimpression refusée.';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('logistique.disque_decharges'));
        $this->creerDecorFictif();
        app(StockService::class)->inventaireInitial($this->urnes, 100, 'Inventaire magasin');
        app(StockService::class)->inventaireInitial($this->isoloirs, 50, 'Inventaire magasin');
    }

    private function service(): RestitutionService
    {
        return app(RestitutionService::class);
    }

    private function pdf(): RestitutionPdfService
    {
        return app(RestitutionPdfService::class);
    }

    /**
     * Restitution du sous-préfet ; une déclaration retenue est posée dès que le retour est déclaré.
     *
     * @param  array<int, array{0: int, 1: int}>  $lignes  [attendu, déclaré restitué] par identifiant d'article
     */
    private function restitution(array $lignes, string $statut, bool $retenue = true, string $detenteurType = 'destinataire', ?int $detenteurId = null): Restitution
    {
        $restitution = (new Restitution(['cycle_id' => $this->cycle->id, 'detenteur_type' => $detenteurType, 'detenteur_id' => $detenteurId ?? $this->sousPrefecture1->id]))
            ->forceFill(['ouverte_par' => $this->responsable->id, 'statut' => $statut]);
        $restitution->save();
        foreach ($lignes as $materielId => [$attendu, $declare]) {
            RestitutionLigne::create(['restitution_id' => $restitution->id, 'materiel_id' => $materielId, 'attendu' => $attendu])
                ->forceFill(['declare_restitue' => $declare])->save();
        }
        if ($retenue) {
            $this->declaration($restitution);
        }

        return $restitution;
    }

    private function declaration(Restitution $r, array $champs = []): RestitutionDeclaration
    {
        $declaration = RestitutionDeclaration::create([
            'restitution_id' => $r->id, 'mode' => 'compte', 'signataire_nom' => 'Mamadou Ba', 'signataire_qualite' => 'Sous-préfet',
            'user_id' => $this->responsable->id, 'cle_idempotence' => Str::random(40), 'empreinte_contenu' => hash('sha256', Str::random(8)),
        ]);
        $declaration->forceFill(array_merge(['statut' => 'retenue', 'verification_identite' => 'compte'], $champs))->save();

        return $declaration;
    }

    private function ligne(Restitution $r, int $materielId): RestitutionLigne
    {
        return RestitutionLigne::where('restitution_id', $r->id)->where('materiel_id', $materielId)->sole();
    }

    private function ecart(Restitution $r, int $materielId): array
    {
        return $this->service()->ecarts($this->cycle)
            ->first(fn (array $e) => $e['detenteur_type'] === $r->detenteur_type && $e['detenteur_id'] === (int) $r->detenteur_id && $e['materiel_id'] === $materielId);
    }

    public function test_non_restitue_10_sur_100(): void
    {
        $r = $this->restitution([$this->urnes->id => [100, 90]], 'en_retour');

        $ecart = $this->ecart($r, $this->urnes->id);

        $this->assertSame(100, $ecart['attendu']);
        $this->assertSame(90, $ecart['declare_restitue']);
        $this->assertSame(10, $ecart['non_restitue']);
        $this->assertSame('Sous-préfecture de Sindia', $ecart['detenteur_nom']);
        $this->assertSame('destinataire', $ecart['detenteur_type']);
        $this->assertNull($ecart['justification']);
    }

    public function test_non_restitue_est_nul_sans_declaration_retenue_et_jamais_negatif(): void
    {
        $sans = $this->restitution([$this->urnes->id => [100, 0]], 'a_preparer', false);
        $this->assertNull($this->ecart($sans, $this->urnes->id)['non_restitue']);

        $excedent = $this->restitution([$this->isoloirs->id => [10, 12]], 'en_retour', true, 'destinataire', $this->sousPrefecture2->id);
        $this->assertSame(0, $this->ecart($excedent, $this->isoloirs->id)['non_restitue']);
    }

    public function test_perdu_en_retour(): void
    {
        $r = $this->restitution([$this->urnes->id => [100, 90], $this->isoloirs->id => [20, 20]], 'en_retour');
        $this->assertNull($this->ecart($r, $this->urnes->id)['perdu_en_retour'], 'Avant réception, recu_central = 0 ne dit pas « perdu ».');

        $this->service()->receptionner($r, [$this->urnes->id => 85, $this->isoloirs->id => 20]);

        $urnes = $this->ecart($r, $this->urnes->id);
        $this->assertSame(85, $urnes['recu_central']);
        $this->assertSame(5, $urnes['perdu_en_retour']);
        $this->assertSame(0, $this->ecart($r, $this->isoloirs->id)['perdu_en_retour']);

        $this->service()->controler($r, [
            $this->urnes->id => ['bon' => 85, 'a_reparer' => 0, 'reforme' => 0],
            $this->isoloirs->id => ['bon' => 20, 'a_reparer' => 0, 'reforme' => 0],
        ]);
        $this->assertSame('controlee', $r->fresh()->statut);
        $this->assertSame(5, $this->ecart($r, $this->urnes->id)['perdu_en_retour']);
    }

    public function test_ecarts_en_requetes_bornees(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $destinataire = Destinataire::create([
                'type' => 'sous_prefecture', 'libelle' => "Sous-préfecture {$i}", 'arrondissement_id' => 200 + $i, 'prefecture_id' => $this->prefecture->id,
            ]);
            $this->restitution([$this->urnes->id => [10, 8], $this->isoloirs->id => [5, 5]], 'en_retour', true, 'destinataire', $destinataire->id);
        }
        $agent = User::factory()->create(['role' => 'agent_transport']);
        $this->restitution([$this->urnes->id => [10, 8]], 'en_retour', true, 'agent', $agent->id);

        DB::enableQueryLog();
        $ecarts = $this->service()->ecarts($this->cycle);
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(13, $ecarts);
        $this->assertLessThanOrEqual(6, $requetes);
    }

    public function test_justification_journalisee(): void
    {
        $r = $this->restitution([$this->urnes->id => [100, 90]], 'en_retour');
        $ligne = $this->ligne($r, $this->urnes->id);

        $this->service()->justifier($ligne, '  Dix urnes détruites lors des incidents de Sindia.  ');

        $this->assertSame('Dix urnes détruites lors des incidents de Sindia.', $ligne->justification);
        $this->assertSame('Dix urnes détruites lors des incidents de Sindia.', $ligne->fresh()->justification);
        $this->assertSame('Dix urnes détruites lors des incidents de Sindia.', $this->ecart($r, $this->urnes->id)['justification']);
        $entree = JournalEntree::where('action', 'justification_restitution')->sole();
        $this->assertSame('restitution', $entree->objet_type);
        $this->assertSame($r->id, $entree->objet_id);
        $this->assertSame($this->responsable->id, $entree->user_id);
        $this->assertSame($ligne->id, $entree->apres['ligne_id']);
        $this->assertNull($entree->avant['justification']);

        $this->service()->justifier($ligne, 'Corrigé.');
        $this->assertSame('Dix urnes détruites lors des incidents de Sindia.', JournalEntree::where('action', 'justification_restitution')->orderBy('id')->first()->apres['justification']);
        $this->assertSame('Dix urnes détruites lors des incidents de Sindia.', JournalEntree::where('action', 'justification_restitution')->orderByDesc('id')->first()->avant['justification']);
    }

    public function test_justification_refusee(): void
    {
        $r = $this->restitution([$this->urnes->id => [100, 90]], 'en_retour');
        $ligne = $this->ligne($r, $this->urnes->id);

        foreach (['', "   \n ", str_repeat('é', 501)] as $texte) {
            try {
                $this->service()->justifier($ligne, $texte);
                $this->fail('Justification invalide acceptée.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('La justification est obligatoire (500 caractères au plus).', $e->getMessage());
            }
        }
        $this->service()->justifier($ligne, str_repeat('é', 500));
        $this->assertSame(500, mb_strlen($ligne->fresh()->justification));

        $this->actingAs(User::factory()->create(['role' => 'agent_transport']));
        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Seul le personnel logistique peut justifier un écart de restitution.');
        $this->service()->justifier($ligne, 'Tentative');
    }

    public function test_fiche_archivee_et_reimprimee(): void
    {
        $r = $this->restitution([$this->urnes->id => [100, 90]], 'en_retour');

        $document = $this->pdf()->emettre($r, 'declaration');

        $disque = Storage::disk(config('logistique.disque_decharges'));
        $this->assertSame("logistique/restitutions/{$r->cycle_id}/{$r->id}-declaration.pdf", $document->chemin);
        $octets = $disque->get($document->chemin);
        $this->assertStringStartsWith('%PDF', $octets);
        $this->assertSame(hash('sha256', $octets), $document->empreinte);
        $this->assertSame(strlen($octets), $document->taille);
        $this->assertSame($this->responsable->id, (int) $document->emis_par);
        $this->assertNotNull($document->emis_le);
        $entree = JournalEntree::where('action', 'emission_pdf_restitution')->sole();
        $this->assertSame('restitution', $entree->objet_type);
        $this->assertSame($r->id, $entree->objet_id);
        $this->assertSame($document->empreinte, $entree->apres['empreinte']);

        // Idempotent : le même exemplaire n'est ni régénéré ni rejournalisé, même si les données ont changé depuis.
        $this->ligne($r, $this->urnes->id)->forceFill(['declare_restitue' => 1])->save();
        $second = $this->pdf()->emettre($r->fresh(), 'declaration');
        $this->assertSame($document->id, $second->id);
        $this->assertSame(1, RestitutionDocument::count());
        $this->assertSame(1, JournalEntree::where('action', 'emission_pdf_restitution')->count());

        // Réimpression : les octets archivés, à l'identique.
        $this->assertSame($octets, $this->pdf()->contenu($second->fresh()));
        $this->assertSame($octets, $disque->get($document->chemin));

        // Fichier altéré, puis absent : détecté.
        $disque->put($document->chemin, '%PDF-falsifie');
        try {
            $this->pdf()->contenu($document->fresh());
            $this->fail('Fichier altéré accepté.');
        } catch (RegleLogistique $e) {
            $this->assertSame(self::ALTERE, $e->getMessage());
        }
        $disque->delete($document->chemin);
        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage(self::ALTERE);
        $this->pdf()->contenu($document->fresh());
    }

    public function test_exemplaires_non_encore_emissibles(): void
    {
        $preparer = $this->restitution([$this->urnes->id => [100, 90]], 'a_preparer');
        $sansRetenue = $this->restitution([$this->urnes->id => [100, 0]], 'en_retour', false, 'destinataire', $this->sousPrefecture2->id);
        $recue = $this->restitution([$this->isoloirs->id => [10, 10]], 'recue_central', true, 'destinataire', $this->poste->id);

        $cas = [
            [$preparer, 'declaration', 'La fiche de déclaration ne peut être émise qu\'une fois le retour pris en charge.'],
            [$sansRetenue, 'declaration', 'La fiche de déclaration ne peut être émise qu\'une fois le retour pris en charge.'],
            [$preparer, 'controle', 'La fiche de contrôle ne peut être émise qu\'une fois la restitution contrôlée.'],
            [$recue, 'controle', 'La fiche de contrôle ne peut être émise qu\'une fois la restitution contrôlée.'],
        ];
        foreach ($cas as [$restitution, $exemplaire, $message]) {
            try {
                $this->pdf()->emettre($restitution, $exemplaire);
                $this->fail("Exemplaire {$exemplaire} émis trop tôt.");
            } catch (RegleLogistique $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
        $this->assertSame(0, RestitutionDocument::count());
        $this->assertSame([], Storage::disk(config('logistique.disque_decharges'))->allFiles());
        $this->assertSame(0, JournalEntree::where('action', 'emission_pdf_restitution')->count());

        // Déjà reçue au central : l'exemplaire de déclaration reste émissible.
        $this->assertSame('declaration', $this->pdf()->emettre($recue, 'declaration')->exemplaire);

        try {
            $this->pdf()->emettre($recue, 'inconnu');
            $this->fail('Exemplaire inconnu accepté.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Exemplaire inconnu : inconnu.', $e->getMessage());
        }
    }

    public function test_emission_reservee_au_personnel_logistique(): void
    {
        $r = $this->restitution([$this->urnes->id => [100, 90]], 'en_retour');
        $this->actingAs(User::factory()->create(['role' => 'agent_transport']));

        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Seul le personnel logistique peut émettre les fiches de restitution.');
        $this->pdf()->emettre($r, 'declaration');
    }

    public function test_contenu_de_la_fiche(): void
    {
        Carbon::setTestNow('2026-11-10 08:00:00');
        $sousPrefet = User::factory()->create(['role' => 'sous_prefet', 'name' => 'Ba', 'prenom' => 'Mamadou']);
        $adjoint = User::factory()->create(['role' => 'sous_prefet', 'name' => 'Sow', 'prenom' => 'Awa']);
        $this->sousPrefecture1->forceFill(['autorite_user_id' => $sousPrefet->id])->save();
        $delegation = Delegation::create([
            'destinataire_id' => $this->sousPrefecture1->id, 'autorite_user_id' => $sousPrefet->id, 'delegue_user_id' => $adjoint->id,
            'du' => now()->subDay(), 'au' => now()->addDay(), 'motif' => 'Intérim', 'cree_par' => $this->responsable->id,
        ]);
        $agent = User::factory()->create(['role' => 'agent_transport', 'name' => 'Ndiaye', 'prenom' => 'Ibrahima']);
        $receptionnaire = User::factory()->create(['role' => 'logistique_gestionnaire', 'name' => 'Faye', 'prenom' => 'Fatou']);
        $controleur = User::factory()->create(['role' => 'logistique_gestionnaire', 'name' => 'Sy', 'prenom' => 'Moussa']);

        $r = $this->restitution([$this->urnes->id => [100, 90], $this->isoloirs->id => [20, 20]], 'a_preparer', false);
        $this->declaration($r, [
            'signataire_user_id' => $adjoint->id, 'delegation_id' => $delegation->id, 'verification_identite' => 'compte',
            'horodatage_terminal' => '2026-11-10 08:15:00',
        ]);
        Carbon::setTestNow('2026-11-10 14:20:00');
        $this->service()->affecterAgent($r, $agent);
        $this->service()->prendreEnCharge($r, $agent);

        Carbon::setTestNow('2026-11-12 09:30:00');
        $this->actingAs($receptionnaire);
        $this->service()->receptionner($r, [$this->urnes->id => 88, $this->isoloirs->id => 20]);
        Carbon::setTestNow('2026-11-12 11:45:00');
        $this->actingAs($controleur);
        $this->service()->controler($r, [
            $this->urnes->id => ['bon' => 80, 'a_reparer' => 5, 'reforme' => 3],
            $this->isoloirs->id => ['bon' => 20, 'a_reparer' => 0, 'reforme' => 0],
        ]);
        Carbon::setTestNow('2026-11-13 16:05:00');
        $this->actingAs($this->responsable);
        $this->service()->deciderReforme($this->ligne($r, $this->urnes->id), 'Urnes brisées, irréparables');
        $this->assertSame('controlee', $r->fresh()->statut);

        // Cellules bornées : un chiffre égaré ailleurs dans la page ne peut pas satisfaire l'ordre attendu.
        $nom = fn (string $libelle) => "<td>{$libelle}</td>";
        $n = fn (int $v) => '<td class="num">'.$v.'</td>';

        $declaration = $this->pdf()->html($r->fresh(), 'declaration');
        $this->seeInOrder($declaration, [
            'FICHE DE RESTITUTION DU MATÉRIEL ÉLECTORAL RÉUTILISABLE',
            'Exemplaire de déclaration',
            '<td>Sous-préfecture de Sindia</td>', 'Cycle A',
            '<th>Article</th>', '<th>Attendu</th>', '<th>Restitué</th>',
            $nom('Urnes'), $n(100), $n(90),
            $nom('Isoloirs'), $n(20), $n(20),
            'Awa Sow, par délégation de Mamadou Ba', 'Confirmée depuis le compte',
            'terminal : 10/11/2026 08:15 ; serveur : 10/11/2026 08:00',
            'Ibrahima Ndiaye — le 10/11/2026 14:20',
        ], false);
        foreach (['Reçu au central', 'Bon', 'À réparer', 'Réformé', 'Urnes brisées'] as $absent) {
            $this->assertStringNotContainsString($absent, $declaration, "« {$absent} » n'a pas sa place sur l'exemplaire de déclaration.");
        }

        $controle = $this->pdf()->html($r->fresh(), 'controle');
        $this->seeInOrder($controle, [
            'FICHE DE RESTITUTION DU MATÉRIEL ÉLECTORAL RÉUTILISABLE',
            'Exemplaire de contrôle',
            '<td>Sous-préfecture de Sindia</td>', 'Cycle A',
            '<th>Article</th>', '<th>Attendu</th>', '<th>Restitué</th>', '<th>Reçu au central</th>', '<th>Bon</th>', '<th>À réparer</th>', '<th>Réformé</th>',
            $nom('Urnes'), $n(100), $n(90), $n(88), $n(80), $n(5), $n(3),
            $nom('Isoloirs'), $n(20), $n(20), $n(20), $n(20), $n(0), $n(0),
            'Awa Sow, par délégation de Mamadou Ba',
            'Ibrahima Ndiaye — le 10/11/2026 14:20',
            'Réception : Fatou Faye — le 12/11/2026 09:30',
            'Contrôle : Moussa Sy — le 12/11/2026 11:45',
            "Urnes : 3 réformé(s) — {$this->responsable->nom_complet}, le 13/11/2026 16:05 — motif : Urnes brisées, irréparables",
        ], false);
        $this->assertStringContainsString('/logistique/restitutions/'.$r->id, $this->pdf()->urlQr($r));
        $this->assertStringStartsWith(rtrim((string) config('app.url'), '/'), $this->pdf()->urlQr($r));
        $this->assertStringContainsString('data:image/png;base64,', $controle);
    }

    public function test_ecarts_cumules_sur_deux_restitutions_du_meme_detenteur(): void
    {
        $r1 = $this->restitution([$this->urnes->id => [100, 90]], 'en_retour');
        $this->service()->receptionner($r1, [$this->urnes->id => 85]);
        $this->service()->controler($r1, [$this->urnes->id => ['bon' => 85, 'a_reparer' => 0, 'reforme' => 0]]);
        $this->assertSame('controlee', $r1->fresh()->statut);
        $this->service()->justifier($this->ligne($r1, $this->urnes->id), 'Cinq urnes détruites.');

        $r2 = $this->restitution([$this->urnes->id => [10, 4]], 'a_preparer');

        $this->assertCount(1, $this->service()->ecarts($this->cycle));
        $ecart = $this->ecart($r2, $this->urnes->id);
        $this->assertSame(100, $ecart['attendu']);
        $this->assertSame(94, $ecart['declare_restitue']);
        $this->assertSame(85, $ecart['recu_central']);
        $this->assertSame(6, $ecart['non_restitue']);
        $this->assertSame(5, $ecart['perdu_en_retour'], 'Le perdu de la première restitution est conservé tant que la seconde n\'est pas reçue.');
        $this->assertSame('Cinq urnes détruites.', $ecart['justification'], 'Sans justification sur la ligne récente, celle de la plus ancienne.');
        $this->assertSame($this->ligne($r2, $this->urnes->id)->id, $ecart['ligne_id']);
        $this->assertSame($r2->id, $ecart['restitution_id']);
    }

    public function test_non_restitue_reporte_tant_que_la_derniere_restitution_n_a_pas_de_declaration(): void
    {
        $r1 = $this->restitution([$this->urnes->id => [100, 90]], 'en_retour');
        $this->service()->receptionner($r1, [$this->urnes->id => 90]);
        $this->service()->controler($r1, [$this->urnes->id => ['bon' => 90, 'a_reparer' => 0, 'reforme' => 0]]);
        $this->restitution([$this->urnes->id => [10, 0]], 'a_preparer', false);

        $this->assertSame(10, $this->ecart($r1, $this->urnes->id)['non_restitue']);

        // Aucune restitution de la paire n'a de déclaration retenue : toujours inconnu.
        $autre = $this->restitution([$this->isoloirs->id => [10, 0]], 'a_preparer', false);
        $this->assertNull($this->ecart($autre, $this->isoloirs->id)['non_restitue']);
    }

    public function test_identite_non_etablie_jamais_imprimee_comme_confirmee(): void
    {
        $r = $this->restitution([$this->urnes->id => [100, 90]], 'en_retour', false);
        $this->declaration($r, ['verification_identite' => 'non_verifiee']);

        $html = $this->pdf()->html($r, 'declaration');

        $this->assertStringContainsString('Non vérifiée', $html);
        $this->assertStringContainsString('identité non établie', $html);
        $this->assertStringNotContainsString('Confirmée', $html);
    }

    /** Ordre des valeurs dans le HTML rendu (les valeurs sont échappées comme le rendu). */
    private function seeInOrder(string $html, array $valeurs, bool $echapper = true): void
    {
        TestResponse::fromBaseResponse(response($html))->assertSeeInOrder($valeurs, $echapper);
    }
}

<?php

namespace Tests\Feature\Logistique;

use App\Models\Logistique\Decharge;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Remise;
use App\Models\User;
use App\Services\Logistique\RemiseService;
use App\Support\Canonique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Laravel\Sanctum\Sanctum;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class AffectationsTest extends TestCase
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
            [$this->prefecture, $this->urnes, 20],
        ]);
        $this->stockerFictif($this->urnes, 1000, 300);
        $this->stockerFictif($this->isoloirs, 1000, 100);
    }

    private function terrain(User $utilisateur): static
    {
        Sanctum::actingAs($utilisateur, ['terrain']);

        return $this;
    }

    /** @return list<array<string, mixed>> */
    private function elements(User $utilisateur): array
    {
        try {
            return $this->terrain($utilisateur)->getJson('/api/terrain/affectations')
                ->assertOk()->assertJsonStructure(['genere_le', 'elements'])->json('elements');
        } finally {
            $this->actingAs($this->responsable);
        }
    }

    /** Résumé lisible : « etape:statut:décharge » (ou « preparer:décharge » pour une redistribution à préparer). */
    private function resume(array $elements): array
    {
        return array_map(fn (array $e) => $e['remise'] === null
            ? 'preparer:'.$e['decharge']['id']
            : $e['remise']['etape'].':'.$e['remise']['statut'].':'.$e['decharge']['id'], $elements);
    }

    private function agentTransport(string $nom): User
    {
        return User::factory()->create(['role' => 'agent_transport', 'name' => $nom, 'prenom' => 'Test']);
    }

    private function redistribuer(Decharge $decharge, array $lignes): Remise
    {
        $prefet = $this->autoriteFictive($this->prefecture);
        $this->actingAs($prefet);
        try {
            return app(RemiseService::class)->preparerRedistribution($decharge, $lignes);
        } finally {
            $this->actingAs($this->responsable);
        }
    }

    public function test_agent_recoit_ses_livraisons_a_faire(): void
    {
        $this->autoriteFictive($this->prefecture)->forceFill(['name' => 'Ndiaye', 'prenom' => 'Moussa'])->save();
        $this->prefecture->forceFill(['signataire_nom' => 'Moussa Ndiaye', 'signataire_qualite' => 'Préfet de Thiès'])->save();
        $agentA = $this->agentTransport('Agent A');
        $agentB = $this->agentTransport('Agent B');
        $d1 = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100, $this->isoloirs->id => 50], $agentA);
        $d2 = $this->deposerChargee($this->prefecture, [$this->urnes->id => 20], $agentB);

        $elements = $this->elements($agentA);

        $this->assertSame(['livraison:en_attente:'.$d1->id], $this->resume($elements));
        $element = $elements[0];
        $livraison = Remise::where('decharge_id', $d1->id)->where('etape', 'livraison')->firstOrFail();
        $this->assertSame([
            'id' => $livraison->id, 'etape' => 'livraison', 'statut' => 'en_attente',
            'de' => 'Test Agent A', 'a' => 'Préfecture de Thiès',
        ], $element['remise']);
        $this->assertSame([
            'id' => $d1->id, 'identifiant' => $d1->identifiant, 'numero' => $d1->numero, 'categorie' => 'materiel_lourd',
            'nature' => 'initiale', 'statut_document' => 'validee', 'statut_acheminement' => 'chargee',
        ], $element['decharge']);
        $this->assertSame([
            'user_id' => $this->autoriteFictive($this->prefecture)->id, 'nom' => 'Moussa Ndiaye',
            'qualite' => 'Préfet de Thiès', 'delegues' => [],
        ], $element['autorite_attendue']);
        $this->assertSame([
            ['materiel_id' => $this->urnes->id, 'libelle' => 'Urnes', 'quantite' => 100],
            ['materiel_id' => $this->isoloirs->id, 'libelle' => 'Isoloirs', 'quantite' => 50],
        ], $element['lignes']);
        $this->assertSame(['livraison:en_attente:'.$d2->id], $this->resume($this->elements($agentB)));

        // Une fois la livraison constatée, plus rien à faire pour l'agent.
        $this->deposerRecue($d1, []);
        $this->assertSame([], $this->elements($agentA));

        // Une réception à vérifier reste à faire.
        $livraison->forceFill(['statut' => 'a_verifier'])->save();
        $this->assertSame(['livraison:a_verifier:'.$d1->id], $this->resume($this->elements($agentA)));
    }

    public function test_prefet_recoit_livraisons_et_redistributions(): void
    {
        $prefet = $this->autoriteFictive($this->prefecture);
        $d1 = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100, $this->isoloirs->id => 50]);
        $d2 = $this->deposerChargee($this->prefecture, [$this->urnes->id => 20]);

        // Deux livraisons attendues, aucune redistribution possible tant que rien n'est retenu.
        $this->assertSame(['livraison:en_attente:'.$d1->id, 'livraison:en_attente:'.$d2->id], $this->resume($this->elements($prefet)));

        $this->deposerRecue($d1, [$this->urnes->id => ['recu' => 100, 'defectueux' => 10]]);
        $elements = $this->elements($prefet);
        $this->assertSame(['livraison:en_attente:'.$d2->id, 'preparer:'.$d1->id], $this->resume($elements));
        $this->assertNull($elements[1]['remise']);
        $this->assertSame([
            ['materiel_id' => $this->urnes->id, 'libelle' => 'Urnes', 'quantite' => 90],
            ['materiel_id' => $this->isoloirs->id, 'libelle' => 'Isoloirs', 'quantite' => 50],
        ], $elements[1]['lignes']);

        // Une redistribution préparée et non reçue est suivie, le reliquat reste à redistribuer.
        $redistribution = $this->redistribuer($d1->refresh(), [$this->urnes->id => 80]);
        $elements = $this->elements($prefet);
        $this->assertSame(
            ['livraison:en_attente:'.$d2->id, 'redistribution:en_attente:'.$d1->id, 'preparer:'.$d1->id],
            $this->resume($elements),
        );
        $this->assertSame($redistribution->id, $elements[1]['remise']['id']);
        $this->assertSame(['Préfecture de Thiès', 'Sous-préfecture de Sindia'], [$elements[1]['remise']['de'], $elements[1]['remise']['a']]);
        $this->assertSame([['materiel_id' => $this->urnes->id, 'libelle' => 'Urnes', 'quantite' => 80]], $elements[1]['lignes']);
        $this->assertSame([
            ['materiel_id' => $this->urnes->id, 'libelle' => 'Urnes', 'quantite' => 10],
            ['materiel_id' => $this->isoloirs->id, 'libelle' => 'Isoloirs', 'quantite' => 50],
        ], $elements[2]['lignes']);

        // Tout redistribué : plus de préparation possible.
        $this->redistribuer($d1->refresh(), [$this->urnes->id => 10, $this->isoloirs->id => 50]);
        $this->assertSame(
            ['livraison:en_attente:'.$d2->id, 'redistribution:en_attente:'.$d1->id, 'redistribution:en_attente:'.$d1->id],
            $this->resume($this->elements($prefet)),
        );
    }

    public function test_sous_prefet_recoit_ce_qu_il_doit_signer(): void
    {
        $sousPrefet = $this->autoriteFictive($this->sousPrefecture1);
        $d1 = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100, $this->isoloirs->id => 50]);

        // La livraison va au préfet : rien pour le sous-préfet.
        $this->assertSame([], $this->elements($sousPrefet));

        $this->deposerRecue($d1, []);
        $this->assertSame([], $this->elements($sousPrefet));

        $redistribution = $this->redistribuer($d1->refresh(), [$this->urnes->id => 60]);
        $elements = $this->elements($sousPrefet);
        $this->assertSame(['redistribution:en_attente:'.$d1->id], $this->resume($elements));
        $this->assertSame($redistribution->id, $elements[0]['remise']['id']);
        $this->assertSame($sousPrefet->id, $elements[0]['autorite_attendue']['user_id']);
        $this->assertSame([], $elements[0]['autorite_attendue']['delegues']);
        $this->assertSame([['materiel_id' => $this->urnes->id, 'libelle' => 'Urnes', 'quantite' => 60]], $elements[0]['lignes']);

        // Chef de poste : la livraison directe au poste lui revient.
        $chef = $this->autoriteFictive($this->poste);
        $this->poste->forceFill(['signataire_nom' => 'Aïssatou Sow', 'signataire_qualite' => 'Consul général'])->save();
        $this->approuverPlanFictif([[$this->poste, $this->enveloppes, 40]]);
        $this->stockerFictif($this->enveloppes, 500, 200);
        $dPoste = $this->deposerChargee($this->poste, [$this->enveloppes->id => 40]);
        $elements = $this->elements($chef);
        $this->assertSame(['livraison:en_attente:'.$dPoste->id], $this->resume($elements));
        $this->assertSame(
            ['user_id' => $chef->id, 'nom' => 'Aïssatou Sow', 'qualite' => 'Consul général', 'delegues' => []],
            $elements[0]['autorite_attendue'],
        );
    }

    public function test_autorite_sans_compte_rattache(): void
    {
        $prefet = $this->autoriteFictive($this->prefecture);
        $this->prefecture->forceFill(['signataire_nom' => 'Mame Diarra Fall', 'signataire_qualite' => 'Préfet', 'autorite_user_id' => null])->save();
        $agent = $this->agentTransport('Agent A');
        $this->deposerChargee($this->prefecture, [$this->urnes->id => 20], $agent);

        $this->assertSame(
            ['user_id' => null, 'nom' => 'Mame Diarra Fall', 'qualite' => 'Préfet', 'delegues' => []],
            $this->elements($agent)[0]['autorite_attendue'],
        );
        $this->assertSame([], $this->elements($prefet));
    }

    public function test_hors_perimetre_absent_et_404(): void
    {
        $agentA = $this->agentTransport('Agent A');
        $agentB = $this->agentTransport('Agent B');
        $d1 = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100, $this->isoloirs->id => 50], $agentA);
        $prefet = $this->autoriteFictive($this->prefecture);

        $autre = Destinataire::create(['type' => 'prefecture', 'libelle' => 'Préfecture de Kaolack', 'departement_id' => 11]);
        $autrePrefet = User::factory()->create(['role' => 'prefet']);
        $autre->forceFill(['autorite_user_id' => $autrePrefet->id])->save();

        $this->assertSame([], $this->elements($agentB));
        $this->assertSame([], $this->elements($autrePrefet));

        foreach ([$agentB, $autrePrefet] as $etranger) {
            $this->terrain($etranger)->getJson('/api/terrain/decharges/'.$d1->identifiant)
                ->assertNotFound()->assertExactJson(['message' => 'Ressource introuvable.']);
        }
        $this->terrain($agentA)->getJson('/api/terrain/decharges/00000000000000000000000000')->assertNotFound();

        foreach ([$agentA, $prefet] as $autorise) {
            $element = $this->terrain($autorise)->getJson('/api/terrain/decharges/'.$d1->identifiant)
                ->assertOk()->assertJsonStructure(['genere_le', 'elements'])->json('elements.0');
            $this->assertSame($d1->id, $element['decharge']['id']);
        }
    }

    public function test_acces_refuse_sans_jeton_ou_sans_droit(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/terrain/affectations')->assertUnauthorized();

        $this->terrain(User::factory()->create(['role' => 'logistique_gestionnaire']))
            ->getJson('/api/terrain/affectations')->assertForbidden();
    }

    public function test_empreinte_element_change_si_la_remise_change(): void
    {
        $agent = $this->agentTransport('Agent A');
        $d1 = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100, $this->isoloirs->id => 50], $agent);

        $avant = $this->elements($agent)[0];
        $this->assertSame($avant['empreinte_element'], $this->elements($agent)[0]['empreinte_element']);
        $this->assertSame(
            Canonique::empreinte(Arr::except($avant, 'empreinte_element')),
            $avant['empreinte_element'],
        );
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $avant['empreinte_element']);

        Remise::where('decharge_id', $d1->id)->where('etape', 'livraison')->firstOrFail()->forceFill(['statut' => 'a_verifier'])->save();
        $apres = $this->elements($agent)[0];
        $this->assertNotSame($avant['empreinte_element'], $apres['empreinte_element']);
        $this->assertSame('a_verifier', $apres['remise']['statut']);

        // Changer le signataire attendu change aussi l'empreinte.
        $this->prefecture->forceFill(['signataire_qualite' => 'Préfet par intérim'])->save();
        $this->assertNotSame($apres['empreinte_element'], $this->elements($agent)[0]['empreinte_element']);
    }

    public function test_canonique_vecteurs(): void
    {
        $vecteurs = json_decode(file_get_contents(base_path('tests/fixtures/canonique.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertGreaterThanOrEqual(8, count($vecteurs));
        foreach ($vecteurs as $i => $vecteur) {
            $this->assertSame($vecteur['json'], Canonique::json($vecteur['entree']), "JSON du vecteur {$i}");
            $this->assertSame($vecteur['empreinte'], Canonique::empreinte($vecteur['entree']), "Empreinte du vecteur {$i}");
            $this->assertSame(hash('sha256', $vecteur['json']), $vecteur['empreinte'], "Cohérence du vecteur {$i}");
        }
    }
}

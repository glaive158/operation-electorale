<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\DechargeDocument;
use App\Models\User;
use App\Services\Logistique\DechargePdfService;
use App\Services\Logistique\DechargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;
use ZipArchive;

class QrLotTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    /** @var list<string> */
    private array $zips = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('logistique.disque_decharges'));
        $this->creerDecorFictif();
    }

    protected function tearDown(): void
    {
        foreach ($this->zips as $zip) {
            @unlink($zip);
        }
        parent::tearDown();
    }

    private function service(): DechargeService
    {
        return app(DechargeService::class);
    }

    private function pdf(): DechargePdfService
    {
        return app(DechargePdfService::class);
    }

    /** Décharge validée pour un destinataire du décor. */
    private function valider($destinataire): Decharge
    {
        $decharge = $this->service()->preparer($this->cycle, $destinataire, 'materiel_lourd');
        $this->service()->valider($decharge);

        return $decharge->fresh();
    }

    /** Préfecture (département 10), sous-préfecture 1 et poste, chacun avec une dotation et du stock. */
    private function preparerDecor(): void
    {
        $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 100],
            [$this->sousPrefecture1, $this->urnes, 50],
            [$this->sousPrefecture2, $this->urnes, 30],
            [$this->poste, $this->urnes, 20],
        ]);
        $this->stockerFictif($this->urnes, 500);
    }

    /** @return array<string, string> nom d'entrée => octets */
    private function entrees(string $chemin): array
    {
        $this->zips[] = $chemin;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($chemin) === true);
        $entrees = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entrees[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();

        return $entrees;
    }

    public function test_qr_redirige_apres_authentification(): void
    {
        $this->preparerDecor();
        $decharge = $this->valider($this->prefecture);
        $this->actingAs(User::factory()->create(['role' => 'logistique_gestionnaire']));

        $reponse = $this->get(route('logistique.decharges.qr', $decharge->identifiant));

        $reponse->assertRedirect(route('logistique.decharges.show', $decharge));
        $this->assertStringContainsString('/logistique/d/'.$decharge->identifiant, route('logistique.decharges.qr', $decharge->identifiant));
    }

    public function test_qr_sans_connexion_vers_login(): void
    {
        $this->preparerDecor();
        $decharge = $this->valider($this->prefecture);
        Auth::logout();

        $this->get(route('logistique.decharges.qr', $decharge->identifiant))->assertRedirect(route('login'));
    }

    /** Le QR s'ouvre à tout compte connecté, mais n'oriente que le personnel logistique et le périmètre de terrain. */
    public function test_qr_refuse_hors_perimetre(): void
    {
        $this->preparerDecor();
        $decharge = $this->valider($this->prefecture);

        foreach (['prefet', 'sous_prefet', 'chef_poste', 'agent_transport', 'gouverneur', 'commission'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get(route('logistique.decharges.qr', $decharge->identifiant))->assertForbidden();
        }
    }

    public function test_qr_inconnu_404(): void
    {
        $this->get(route('logistique.decharges.qr', 'inconnu'))->assertNotFound();
    }

    public function test_lot_par_departement_contient_les_pdf_archives(): void
    {
        $this->preparerDecor();
        $prefecture = $this->valider($this->prefecture);
        $sousPrefecture = $this->valider($this->sousPrefecture1);
        $poste = $this->valider($this->poste);
        $annulee = $this->valider($this->sousPrefecture2);
        $this->service()->annuler($annulee, 'Erreur de saisie');
        $this->pdf()->emettre($prefecture, 'initial');

        $chemin = $this->pdf()->lot($this->cycle, ['departement_id' => 10]);
        $entrees = $this->entrees($chemin);

        // Ordre des entrées : par numéro, l'exemplaire d'annulation juste après l'initial.
        $this->assertSame([
            $prefecture->numero.'.pdf', $sousPrefecture->numero.'.pdf', $annulee->numero.'.pdf', $annulee->numero.'-ANNULEE.pdf',
        ], array_keys($entrees));
        $this->assertNotContains($poste->numero.'.pdf', array_keys($entrees));

        foreach ([$prefecture, $sousPrefecture, $annulee] as $decharge) {
            $document = DechargeDocument::where('decharge_id', $decharge->id)->where('exemplaire', 'initial')->firstOrFail();
            $this->assertSame($this->pdf()->contenu($document), $entrees[$decharge->numero.'.pdf']);
        }
        $document = DechargeDocument::where('decharge_id', $annulee->id)->where('exemplaire', 'annulation')->firstOrFail();
        $this->assertSame($this->pdf()->contenu($document), $entrees[$annulee->numero.'-ANNULEE.pdf']);
    }

    public function test_lot_emet_les_documents_manquants(): void
    {
        $this->preparerDecor();
        $decharge = $this->valider($this->prefecture);
        $this->assertSame(0, DechargeDocument::count());

        $entrees = $this->entrees($this->pdf()->lot($this->cycle, ['departement_id' => 10]));

        $this->assertSame(1, DechargeDocument::where('decharge_id', $decharge->id)->where('exemplaire', 'initial')->count());
        $this->assertSame([$decharge->numero.'.pdf'], array_keys($entrees));
        $this->assertStringStartsWith('%PDF', $entrees[$decharge->numero.'.pdf']);
    }

    public function test_lot_vide_refuse(): void
    {
        $this->preparerDecor();
        $this->valider($this->poste);

        try {
            $this->pdf()->lot($this->cycle, ['departement_id' => 10]);
            $this->fail('Un périmètre sans décharge validée doit être refusé.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Aucune décharge validée dans ce périmètre.', $e->getMessage());
        }
    }
}

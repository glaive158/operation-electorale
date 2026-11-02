<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\DechargeDocument;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Remise;
use App\Services\Logistique\ArbitrageService;
use App\Services\Logistique\DechargePdfService;
use App\Services\Logistique\DechargeService;
use App\Services\Logistique\DeclarationService;
use App\Services\Logistique\DonneesDecharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class PdfReceptionTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('logistique.disque_decharges'));
        $this->creerDecorFictif();
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->sousPrefecture1, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 1000, 500);
    }

    private function pdf(): DechargePdfService
    {
        return app(DechargePdfService::class);
    }

    private function texte(string $html): string
    {
        $html = preg_replace('#<(style|title)\b.*?</\1>#si', '', $html);

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** Décharge de 100 urnes à la préfecture, reçue : 100 reçus dont 10 défectueux. */
    private function dechargeRecue(): Decharge
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 100]);
        $this->deposerRecue($decharge, [$this->urnes->id => ['recu' => 100, 'defectueux' => 10, 'observation' => 'Dix urnes fendues.']]);

        return $decharge->refresh();
    }

    public function test_reception_emise_apres_reception_destinataire(): void
    {
        $decharge = $this->dechargeRecue();
        $this->assertSame('recue_destinataire', $decharge->statut_acheminement);

        $document = $this->pdf()->emettre($decharge, 'reception');

        $this->assertSame('reception', $document->exemplaire);
        $this->assertSame($decharge->id, $document->decharge_id);
        $this->assertSame("logistique/decharges/{$decharge->cycle_id}/{$decharge->numero}-reception.pdf", $document->chemin);
        $this->assertTrue(JournalEntree::where('objet_type', 'decharge')->where('objet_id', $decharge->id)->where('action', 'emission_pdf')->exists());
        $this->assertStringStartsWith('%PDF', $this->pdf()->contenu($document));
    }

    public function test_reception_refusee_avant(): void
    {
        $this->actingAs($this->responsable);
        $brouillon = app(DechargeService::class)->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $chargee = $this->deposerChargee($this->sousPrefecture1, [$this->urnes->id => 100]);

        foreach ([$brouillon, $chargee] as $decharge) {
            try {
                $this->pdf()->emettre($decharge, 'reception');
                $this->fail('L\'exemplaire de réception exige une décharge reçue par son destinataire.');
            } catch (RegleLogistique $e) {
                $this->assertSame('Ce document ne peut pas être émis pour cette décharge.', $e->getMessage());
            }
        }

        // Reçue par le préfet seulement : le destinataire final n'a rien reçu.
        $this->deposerRecue($chargee, [$this->urnes->id => 100]);
        $this->assertSame('recue_intermediaire', $chargee->refresh()->statut_acheminement);
        try {
            $this->pdf()->emettre($chargee, 'reception');
            $this->fail('Une réception à l\'intermédiaire ne suffit pas.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Ce document ne peut pas être émis pour cette décharge.', $e->getMessage());
        }
        $this->assertSame(0, DechargeDocument::count());
        $this->assertSame([], Storage::disk(config('logistique.disque_decharges'))->allFiles());
    }

    public function test_contenu_reception(): void
    {
        $decharge = $this->dechargeRecue();

        $html = $this->pdf()->html($decharge, 'reception');
        $texte = $this->texte($html);

        $this->assertStringContainsString('EXEMPLAIRE DE RÉCEPTION</h1>', $html);
        $this->assertStringContainsString('alt="QR"', $html);
        $morceaux = [
            'RÉPUBLIQUE DU SÉNÉGAL', 'EXEMPLAIRE DE RÉCEPTION', $decharge->numero, 'Préfecture de Thiès',
            'Nomenclature', 'Annoncé', 'Reçu', 'Défectueux', 'Utilisable', 'Observations',
            'Urnes', '100', '100', '10', '90', 'Dix urnes fendues.',
            'Remises', 'Chargement', 'Livraison', 'Reçue',
            'Déclarations', 'Retenue', 'compte', 'Signataire de test',
            $decharge->identifiant,
        ];
        $position = 0;
        foreach ($morceaux as $morceau) {
            $trouve = mb_strpos($texte, $morceau, $position);
            $this->assertNotFalse($trouve, "« {$morceau} » absent ou hors ordre.");
            $position = $trouve + mb_strlen($morceau);
        }
        $this->assertStringNotContainsString('Annexe de calcul', $texte);
    }

    public function test_reception_archivee_et_idempotente(): void
    {
        $decharge = $this->dechargeRecue();
        $disque = Storage::disk(config('logistique.disque_decharges'));

        $premier = $this->pdf()->emettre($decharge, 'reception');
        $octets = $disque->get($premier->chemin);
        $this->assertSame(hash('sha256', $octets), $premier->empreinte);
        $this->assertSame(strlen($octets), $premier->taille);

        // Une réception arbitrée plus tard n'altère pas l'exemplaire déjà émis : le fichier archivé fait foi.
        $avant = DonneesDecharge::reception($decharge);
        $remise = Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->sole();
        $this->declarerTablette($decharge, $remise, 60);
        $this->assertSame('a_verifier', $remise->refresh()->statut);
        $tablette = $remise->declarations()->where('mode', 'tablette')->sole();
        app(ArbitrageService::class)->retenir($tablette, 'Constat de l\'agent.');
        $this->assertNotEquals($avant, DonneesDecharge::reception($decharge->refresh()), 'Les données vivantes de la réception ont bien changé.');

        $second = $this->pdf()->emettre($decharge->refresh(), 'reception');

        $this->assertSame($premier->id, $second->id);
        $this->assertSame($premier->empreinte, $second->empreinte);
        $this->assertSame($octets, $disque->get($second->chemin));
        $this->assertSame($octets, $this->pdf()->contenu($second));
        $this->assertSame(1, DechargeDocument::where('decharge_id', $decharge->id)->where('exemplaire', 'reception')->count());
        $this->assertSame(1, JournalEntree::where('objet_id', $decharge->id)->where('action', 'emission_pdf')->count());
        $this->assertCount(1, $disque->allFiles());
    }

    public function test_exemplaire_emis_reste_lisible_quand_la_reception_repasse_a_verifier(): void
    {
        $decharge = $this->dechargeRecue();
        $document = $this->pdf()->emettre($decharge, 'reception');
        $octets = $this->pdf()->contenu($document);

        $remise = Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->sole();
        $this->declarerTablette($decharge, $remise, 60);
        $this->assertSame('a_verifier', $decharge->refresh()->statut_acheminement);

        $this->assertSame($octets, $this->pdf()->contenu($document->refresh()));
        $this->assertSame($document->id, $this->pdf()->emettre($decharge, 'reception')->id);
    }

    public function test_reception_non_emise_refusee_quand_la_reception_est_a_verifier(): void
    {
        $decharge = $this->dechargeRecue();
        $remise = Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->sole();
        $this->declarerTablette($decharge, $remise, 60);
        $this->assertSame('a_verifier', $decharge->refresh()->statut_acheminement);

        try {
            $this->pdf()->emettre($decharge, 'reception');
            $this->fail('Une réception à vérifier ne s\'émet pas.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Ce document ne peut pas être émis pour cette décharge.', $e->getMessage());
        }
        $this->assertSame(0, DechargeDocument::count());
    }

    public function test_declaration_sans_qualite_sans_virgule_pendante(): void
    {
        $decharge = $this->dechargeRecue();
        Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->sole()
            ->declarations()->sole()->forceFill(['signataire_qualite' => ''])->save();

        $texte = $this->texte($this->pdf()->html($decharge, 'reception'));

        $this->assertStringContainsString('Signataire de test —', $texte);
        $this->assertStringNotContainsString('Signataire de test, —', $texte);
    }

    public function test_reception_ne_compte_que_les_declarations_retenues(): void
    {
        $decharge = $this->deposerChargee($this->prefecture, [$this->urnes->id => 100]);
        $remise = Remise::where('decharge_id', $decharge->id)->where('etape', 'livraison')->sole();
        $this->declarerTablette($decharge, $remise, 100);
        $this->deposerRecue($decharge, [$this->urnes->id => 100]);
        $this->assertSame('recue_destinataire', $decharge->refresh()->statut_acheminement);

        $donnees = DonneesDecharge::reception($decharge);

        $this->assertSame(['retenue', 'concordante'], array_column($donnees['declarations'], 'statut'));
        $this->assertSame([['materiel_id' => $this->urnes->id, 'libelle' => 'Urnes', 'annonce' => 100, 'recu' => 100, 'defectueux' => 0, 'utilisable' => 100, 'observations' => '']], array_map(
            fn (array $l) => array_intersect_key($l, array_flip(['materiel_id', 'libelle', 'annonce', 'recu', 'defectueux', 'utilisable', 'observations'])),
            $donnees['lignes'],
        ));
        $this->assertSame(['chargement', 'livraison'], array_column($donnees['remises'], 'etape'));
    }

    /** Déclaration « tablette » de l'agent de la décharge sur la remise de livraison. */
    private function declarerTablette(Decharge $decharge, Remise $remise, int $recu): void
    {
        $auteur = auth()->user();
        $this->actingAs($decharge->agent);
        try {
            app(DeclarationService::class)->declarer(
                $remise->load('lignes'), 'tablette', ['nom' => 'Agent', 'qualite' => 'Transport'],
                [$this->urnes->id => ['recu' => $recu, 'defectueux' => 0, 'observation' => null]], (string) Str::uuid(),
            );
        } finally {
            $this->actingAs($auteur);
        }
    }
}

<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\DechargeDocument;
use App\Models\Logistique\InstantaneLigne;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\Materiel;
use App\Models\User;
use App\Services\Logistique\DechargePdfService;
use App\Services\Logistique\DechargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PlanApprouveFictif;
use Tests\TestCase;

class DechargePdfTest extends TestCase
{
    use PlanApprouveFictif;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('logistique.disque_decharges'));
        $this->creerDecorFictif();
    }

    private function service(): DechargeService
    {
        return app(DechargeService::class);
    }

    private function pdf(): DechargePdfService
    {
        return app(DechargePdfService::class);
    }

    /** Texte visible du document : balises retirées, espaces normalisés. */
    private function texte(string $html): string
    {
        $html = preg_replace('#<(style|title)\b.*?</\1>#si', '', $html);

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function assertDansLOrdre(array $morceaux, string $texte): void
    {
        $position = 0;
        foreach ($morceaux as $morceau) {
            $trouve = mb_strpos($texte, $morceau, $position);
            $this->assertNotFalse($trouve, "« {$morceau} » absent ou hors ordre.");
            $position = $trouve + mb_strlen($morceau);
        }
    }

    /** Décharge de matériel lourd validée : 60 urnes sur une dotation de 100. */
    private function dechargeValidee(int $quantite = 60): Decharge
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->prefecture, $this->isoloirs, 40]]);
        $this->stockerFictif($this->urnes, 500);
        $this->stockerFictif($this->isoloirs, 500);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->service()->modifierLigne($decharge->lignes->firstWhere('materiel_id', $this->urnes->id), $quantite, null);
        $this->service()->valider($decharge);

        return $decharge->fresh();
    }

    public function test_html_contient_le_tableau_officiel(): void
    {
        $decharge = $this->dechargeValidee();

        $texte = $this->texte($this->pdf()->html($decharge, 'initial'));

        $this->assertDansLOrdre([
            'RÉPUBLIQUE DU SÉNÉGAL', 'INITIALE', $decharge->numero, 'Préfecture de Thiès',
            'Nomenclature', 'Clé de répartition', 'Dotation', 'Observations', 'Urnes',
            'NB :', 'Fait à Dakar, le '.now()->format('d/m/Y'),
            "L'agent de la DGE (prise en charge)", '(réception)', $decharge->identifiant, 'Annexe de calcul',
        ], $texte);
    }

    public function test_dotation_porte_la_quantite_de_cette_expedition(): void
    {
        $decharge = $this->dechargeValidee(60);

        $texte = $this->texte($this->pdf()->html($decharge, 'initial'));
        [$principal, $annexe] = explode('Annexe de calcul', $texte, 2);

        $this->assertStringContainsString('Urnes Clé Urnes 60', $principal);
        $this->assertStringNotContainsString('Urnes Clé Urnes 100', $principal);
        $this->assertMatchesRegularExpression("/Urnes 100 100\\.0000 0\\.00 À l'unité 100 0 60/", $annexe);
    }

    public function test_regroupements_affiches(): void
    {
        $cachet = Materiel::create([
            'libelle' => 'Cachet « A voté »', 'categorie' => 'materiel_lourd', 'regroupement' => 'Cachets',
            'unite_stock' => 'pièce', 'ordre' => 5,
        ]);
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100], [$this->prefecture, $cachet, 30]]);
        $this->stockerFictif($this->urnes, 500);
        $this->stockerFictif($cachet, 500);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->service()->valider($decharge);

        $texte = $this->texte($this->pdf()->html($decharge->fresh(), 'initial'));

        $this->assertDansLOrdre(['Urnes', 'Cachets', 'Cachet « A voté »'], explode('Annexe de calcul', $texte)[0]);
    }

    public function test_effectifs_en_tete(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        InstantaneLigne::where('destinataire_id', $this->prefecture->id)
            ->update(['lieux_vote' => 3, 'bureaux_vote' => 7, 'electeurs' => 2100, 'commissions' => 5]);
        $this->stockerFictif($this->urnes, 500);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->service()->valider($decharge);

        $texte = $this->texte($this->pdf()->html($decharge->fresh(), 'initial'));

        $this->assertStringContainsString('L.V : 3 — B.V : 7 — Électeurs : 2100', $texte);
        $this->assertStringNotContainsString('C.A :', $texte);
    }

    public function test_commissions_pour_un_cycle_de_revision(): void
    {
        $this->cycle->forceFill(['type' => 'revision'])->save();
        $this->approuverPlanFictif([[$this->prefecture, $this->registres, 100]]);
        InstantaneLigne::where('destinataire_id', $this->prefecture->id)->update(['commissions' => 5]);
        $this->stockerFictif($this->registres, 500);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'revision');
        $this->service()->valider($decharge);

        $texte = $this->texte($this->pdf()->html($decharge->fresh(), 'initial'));

        $this->assertStringContainsString('C.A : 5', $texte);
    }

    public function test_emission_archive_le_fichier_et_son_empreinte(): void
    {
        $decharge = $this->dechargeValidee();

        $document = $this->pdf()->emettre($decharge, 'initial');

        $disque = Storage::disk(config('logistique.disque_decharges'));
        $this->assertSame("logistique/decharges/{$decharge->cycle_id}/{$decharge->numero}-initial.pdf", $document->chemin);
        $disque->assertExists($document->chemin);
        $octets = $disque->get($document->chemin);
        $this->assertStringStartsWith('%PDF', $octets);
        $this->assertSame(hash('sha256', $octets), $document->empreinte);
        $this->assertSame(strlen($octets), $document->taille);
        $this->assertSame($this->responsable->id, (int) $document->emis_par);
        $this->assertNotNull($document->emis_le);
        $entree = JournalEntree::where('action', 'emission_pdf')->firstOrFail();
        $this->assertSame($decharge->id, (int) $entree->objet_id);
    }

    public function test_reimpression_sert_le_fichier_archive(): void
    {
        $decharge = $this->dechargeValidee();
        $premier = $this->pdf()->emettre($decharge, 'initial');
        $octets = $this->pdf()->contenu($premier);

        $second = $this->pdf()->emettre($decharge->fresh(), 'initial');
        $this->assertSame($premier->id, $second->id);
        $this->assertSame(1, JournalEntree::where('action', 'emission_pdf')->count());

        $this->prefecture->forceFill(['signataire_nom' => 'Nouveau Préfet'])->save();
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 999]]);

        $this->assertSame($octets, $this->pdf()->contenu($second->fresh()));
        $this->assertStringNotContainsString('Nouveau Préfet', $this->pdf()->html($decharge->fresh(), 'initial'));
    }

    public function test_fichier_altere_detecte(): void
    {
        $document = $this->pdf()->emettre($this->dechargeValidee(), 'initial');
        Storage::disk(config('logistique.disque_decharges'))->put($document->chemin, '%PDF-falsifie');

        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Fichier archivé altéré ou absent : réimpression refusée.');
        $this->pdf()->contenu($document);
    }

    public function test_fichier_absent_detecte(): void
    {
        $document = $this->pdf()->emettre($this->dechargeValidee(), 'initial');
        Storage::disk(config('logistique.disque_decharges'))->delete($document->chemin);

        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Fichier archivé altéré ou absent : réimpression refusée.');
        $this->pdf()->contenu($document);
    }

    public function test_brouillon_non_emis_mais_apercu_filigrane(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500);
        $brouillon = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');

        $this->assertStringContainsString('BROUILLON', $this->pdf()->html($brouillon, 'initial', true));
        try {
            $this->pdf()->emettre($brouillon, 'initial');
            $this->fail('Un brouillon ne s\'émet pas.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Ce document ne peut pas être émis pour cette décharge.', $e->getMessage());
        }
        $this->assertSame(0, DechargeDocument::count());
    }

    public function test_exemplaire_annulation_filigrane(): void
    {
        $decharge = $this->dechargeValidee();
        try {
            $this->pdf()->emettre($decharge, 'annulation');
            $this->fail('L\'exemplaire d\'annulation exige une décharge annulée.');
        } catch (RegleLogistique $e) {
            $this->assertSame('Ce document ne peut pas être émis pour cette décharge.', $e->getMessage());
        }

        $this->service()->annuler($decharge, 'Erreur de saisie');
        $decharge = $decharge->fresh();
        $document = $this->pdf()->emettre($decharge, 'annulation');

        $this->assertSame('annulation', $document->exemplaire);
        $this->assertStringContainsString('ANNULÉE', $this->pdf()->html($decharge, 'annulation'));
        $this->assertStringNotContainsString('ANNULÉE', $this->pdf()->html($decharge, 'initial'));
        $this->pdf()->emettre($decharge, 'initial');
    }

    public function test_qr_et_identifiant_presents(): void
    {
        $decharge = $this->dechargeValidee();

        $html = $this->pdf()->html($decharge, 'initial');

        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString($decharge->identifiant, $html);
        $this->assertStringStartsWith('data:image/png;base64,', $this->pdf()->qr($decharge));
    }

    public function test_donnees_echappees(): void
    {
        $decharge = $this->dechargeValidee();
        $donnees = $decharge->donnees_gelees;
        $donnees['destinataire']['libelle'] = '<script>alert(1)</script>';
        $decharge->forceFill(['donnees_gelees' => $donnees])->save();

        $html = $this->pdf()->html($decharge->fresh(), 'initial');

        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_emission_refusee_sans_droit(): void
    {
        $decharge = $this->dechargeValidee();
        $this->actingAs(User::factory()->create(['role' => 'prefet']));

        $this->expectException(RegleLogistique::class);
        $this->pdf()->emettre($decharge, 'initial');
    }

    public function test_la_page_et_la_redirection_qr(): void
    {
        $decharge = $this->dechargeValidee();

        $this->get(route('logistique.decharges.show', $decharge))->assertOk()->assertSee($decharge->numero)->assertSee('Préfecture de Thiès');
        $this->get(route('logistique.decharges.qr', $decharge->identifiant))->assertRedirect(route('logistique.decharges.show', $decharge));
        $this->get(route('logistique.decharges.qr', 'inconnu'))->assertNotFound();
    }

    public function test_url_du_qr_vient_de_la_configuration_et_non_de_la_requete(): void
    {
        config(['app.url' => 'https://dge.example/']);
        $decharge = $this->dechargeValidee();

        $this->withServerVariables(['HTTP_HOST' => 'autre-hote.test'])->get(route('logistique.decharges.show', $decharge))->assertOk();
        $url = $this->pdf()->urlQr($decharge);

        $this->assertSame('https://dge.example/logistique/d/'.$decharge->identifiant, $url);
    }

    public function test_remplacement_mentionne_sur_le_document(): void
    {
        $this->approuverPlanFictif([[$this->prefecture, $this->urnes, 100]]);
        $this->stockerFictif($this->urnes, 500);
        $origine = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->service()->valider($origine);
        $origine = $origine->fresh();
        $this->assertNull($origine->donnees_gelees['remplace']);
        $this->assertStringNotContainsString('Remplace la décharge', $this->texte($this->pdf()->html($origine, 'initial')));
        $this->service()->annuler($origine, 'Erreur');

        $remplacante = $this->service()->remplacer($origine->fresh());
        $apercu = $this->texte($this->pdf()->html($remplacante, 'initial', true));
        $this->service()->valider($remplacante);
        $remplacante = $remplacante->fresh();

        $this->assertSame($origine->numero, $remplacante->donnees_gelees['remplace']['numero']);
        $this->assertStringContainsString('Remplace la décharge n° '.$origine->numero, $apercu);
        $this->assertDansLOrdre(
            ['DÉCHARGE DE MATÉRIEL ÉLECTORAL', 'Remplace la décharge n° '.$origine->numero, 'Destinataire', 'Nomenclature'],
            $this->texte($this->pdf()->html($remplacante, 'initial')),
        );
    }

    public function test_exemplaire_d_annulation_porte_la_date_et_le_motif(): void
    {
        $decharge = $this->dechargeValidee();
        $this->service()->annuler($decharge, 'Erreur de saisie');
        $decharge = $decharge->fresh();

        $annulation = $this->texte($this->pdf()->html($decharge, 'annulation'));
        $initial = $this->texte($this->pdf()->html($decharge, 'initial'));

        $this->assertStringContainsString('Annulée le '.$decharge->annule_le->format('d/m/Y').' — motif : Erreur de saisie', $annulation);
        $this->assertStringNotContainsString('Annulée le', $initial);
        $this->assertStringNotContainsString('Erreur de saisie', $initial);
    }

    public function test_emission_retire_le_fichier_si_l_enregistrement_echoue(): void
    {
        $decharge = $this->dechargeValidee();
        DechargeDocument::creating(fn () => throw new \RuntimeException('base indisponible'));

        try {
            $this->pdf()->emettre($decharge, 'initial');
            $this->fail('L\'enregistrement du document devait échouer.');
        } catch (\RuntimeException $e) {
            $this->assertSame('base indisponible', $e->getMessage());
        }

        $this->assertSame([], Storage::disk(config('logistique.disque_decharges'))->allFiles());
        $this->assertSame(0, DechargeDocument::count());
    }

    public function test_contenu_refuse_sans_droit(): void
    {
        $document = $this->pdf()->emettre($this->dechargeValidee(), 'initial');
        $this->actingAs(User::factory()->create(['role' => 'prefet']));

        $this->expectException(RegleLogistique::class);
        $this->expectExceptionMessage('Seul le personnel logistique peut télécharger les décharges.');
        $this->pdf()->contenu($document);
    }

    public function test_lignes_triees_par_ordre_puis_libelle_accentue(): void
    {
        $ziploc = Materiel::create(['libelle' => 'Ziploc', 'categorie' => 'materiel_lourd', 'unite_stock' => 'pièce', 'ordre' => 9]);
        $etiquettes = Materiel::create(['libelle' => 'Étiquettes', 'categorie' => 'materiel_lourd', 'unite_stock' => 'pièce', 'ordre' => 9]);
        $this->approuverPlanFictif([
            [$this->prefecture, $this->urnes, 10], [$this->prefecture, $ziploc, 10], [$this->prefecture, $etiquettes, 10],
        ]);
        $this->stockerFictif($this->urnes, 500);
        $this->stockerFictif($ziploc, 500);
        $this->stockerFictif($etiquettes, 500);
        $decharge = $this->service()->preparer($this->cycle, $this->prefecture, 'materiel_lourd');
        $this->service()->valider($decharge);

        $libelles = array_column($decharge->fresh()->donnees_gelees['lignes'], 'libelle');

        $this->assertSame(['Urnes', 'Étiquettes', 'Ziploc'], $libelles);
    }
}

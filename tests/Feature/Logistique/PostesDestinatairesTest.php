<?php

namespace Tests\Feature\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\PosteEtranger;
use App\Models\User;
use App\Services\Logistique\DecoupageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\RecensementFictif;
use Tests\TestCase;

class PostesDestinatairesTest extends TestCase
{
    use RefreshDatabase;

    private const PARIS = ['pays' => 'France', 'ville' => 'Paris', 'type' => 'consulat', 'nom' => 'Consulat de Paris'];

    private function service(): DecoupageService
    {
        return app(DecoupageService::class);
    }

    private function enResponsable(): void
    {
        RecensementFictif::creerSchema();
        $this->actingAs(User::factory()->create(['role' => 'logistique_responsable']));
    }

    public function test_un_gestionnaire_ne_gere_ni_postes_ni_destinataires(): void
    {
        $this->enResponsable();
        $poste = $this->service()->creerPoste(self::PARIS);
        $this->service()->genererDestinataires();
        $destinataire = Destinataire::where('poste_etranger_id', $poste->id)->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => 'logistique_gestionnaire']));

        foreach ([
            fn () => $this->service()->creerPoste(self::PARIS),
            fn () => $this->service()->modifierPoste($poste, self::PARIS),
            fn () => $this->service()->modifierDestinataire($destinataire, ['actif' => false]),
        ] as $action) {
            try {
                $action();
                $this->fail('Une RegleLogistique était attendue.');
            } catch (RegleLogistique) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_poste_en_double_type_invalide_et_signataire_trop_long_refuses(): void
    {
        $this->enResponsable();
        $poste = $this->service()->creerPoste(self::PARIS);

        try {
            $this->service()->creerPoste(self::PARIS);
            $this->fail('Un doublon (pays, nom) doit être refusé.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Un poste « Consulat de Paris » existe déjà pour ce pays.', $e->getMessage());
        }

        $destinataire = Destinataire::create(['type' => 'poste', 'libelle' => 'X']);
        foreach ([
            fn () => $this->service()->creerPoste(['type' => 'mairie'] + self::PARIS),
            fn () => $this->service()->modifierPoste($poste, ['pays' => ''] + self::PARIS),
            fn () => $this->service()->modifierDestinataire($destinataire, ['signataire_nom' => str_repeat('a', 151)]),
        ] as $action) {
            try {
                $action();
                $this->fail('Une InvalidArgumentException était attendue.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_le_destinataire_du_poste_suit_son_nom_et_son_etat(): void
    {
        $this->enResponsable();
        $poste = $this->service()->creerPoste(self::PARIS);
        $this->service()->genererDestinataires();
        $destinataire = Destinataire::where('poste_etranger_id', $poste->id)->firstOrFail();
        $this->assertSame('Consulat de Paris', $destinataire->libelle);

        $this->service()->modifierPoste($poste, ['nom' => 'Consulat général de Paris', 'actif' => false] + self::PARIS);
        $destinataire->refresh();
        $this->assertSame('Consulat général de Paris', $destinataire->libelle);
        $this->assertFalse($destinataire->actif);

        $journal = JournalEntree::where('objet_type', 'poste_etranger')->where('action', 'modification_poste')->firstOrFail();
        $this->assertSame('Consulat de Paris', $journal->avant['destinataire']['libelle']);
        $this->assertSame('Consulat général de Paris', $journal->apres['destinataire']['libelle']);

        $this->service()->modifierPoste($poste, ['nom' => 'Consulat général de Paris', 'actif' => true] + self::PARIS);
        $this->assertTrue($destinataire->fresh()->actif);
    }

    public function test_journal_ecrit_sans_trace_pour_un_changement_nul(): void
    {
        $this->enResponsable();
        $poste = $this->service()->creerPoste(self::PARIS);
        $this->assertSame(1, JournalEntree::where('objet_type', 'poste_etranger')->where('action', 'creation_poste')->count());

        $this->service()->modifierPoste($poste, self::PARIS);
        $this->assertSame(0, JournalEntree::where('action', 'modification_poste')->count());

        $this->service()->modifierPoste($poste, ['ville' => 'Lyon'] + self::PARIS);
        $this->assertSame(1, JournalEntree::where('action', 'modification_poste')->count());

        $destinataire = Destinataire::create(['type' => 'poste', 'libelle' => 'Poste']);
        $this->service()->modifierDestinataire($destinataire, ['signataire_nom' => 'M. Diop']);
        $this->service()->modifierDestinataire($destinataire, ['signataire_nom' => 'M. Diop']);
        $this->assertSame(1, JournalEntree::where('objet_type', 'destinataire')->count());
    }
}

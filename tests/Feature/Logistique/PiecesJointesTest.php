<?php

namespace Tests\Feature\Logistique;

use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\PieceJointe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PiecesJointesTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('pieces');
        config(['logistique.disque_pieces' => 'pieces']);
        $this->agent = User::factory()->create(['role' => 'agent_transport']);
    }

    /** Un JPEG minimal ; la variante change l'empreinte sans changer le type détecté. */
    private function jpeg(string $variante = 'a'): string
    {
        return base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=')."\xFF\xD9".$variante;
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    }

    private function envoyer(string $octets, ?string $empreinte = null, string $nom = 'photo.jpg', ?User $par = null)
    {
        Sanctum::actingAs($par ?? $this->agent, ['terrain']);

        return $this->withHeaders(['Accept' => 'application/json'])->post('/api/terrain/pieces', [
            'fichier' => UploadedFile::fake()->createWithContent($nom, $octets),
            'empreinte' => $empreinte ?? hash('sha256', $octets),
        ]);
    }

    public function test_piece_dedoublonnee_par_empreinte(): void
    {
        $octets = $this->jpeg();
        $empreinte = hash('sha256', $octets);

        $premiere = $this->envoyer($octets)->assertCreated()
            ->assertJsonPath('piece.empreinte', $empreinte)
            ->assertJsonPath('piece.type_mime', 'image/jpeg')
            ->assertJsonPath('piece.taille', strlen($octets));
        $autre = User::factory()->create(['role' => 'agent_transport']);
        $tiers = User::factory()->create(['role' => 'agent_transport']);
        $seconde = $this->envoyer($octets, null, 'autre-nom.jpg', $autre)->assertOk();
        $troisieme = $this->envoyer($octets)->assertOk();

        $this->assertSame($premiere->json('piece'), $seconde->json('piece'));
        $this->assertSame($premiere->json('piece'), $troisieme->json('piece'));
        $this->assertSame(1, PieceJointe::count());
        // Les deux comptes qui ont envoyé les octets les possèdent ; un tiers non.
        $this->assertSame(2, DB::table('piece_possessions')->count());
        Sanctum::actingAs($autre, ['terrain']);
        $this->call('HEAD', '/api/terrain/pieces/'.$empreinte)->assertOk();
        Sanctum::actingAs($tiers, ['terrain']);
        $this->call('HEAD', '/api/terrain/pieces/'.$empreinte)->assertNotFound();
        $this->assertSame(1, JournalEntree::where('action', 'envoi_piece')->count());
        $piece = PieceJointe::firstOrFail();
        $this->assertSame('pieces/'.substr($empreinte, 0, 2).'/'.$empreinte.'.jpg', $piece->chemin);
        $this->assertSame($this->agent->id, $piece->user_id);
        Storage::disk('pieces')->assertExists($piece->chemin);
        $this->assertSame($octets, Storage::disk('pieces')->get($piece->chemin));
        $this->assertCount(1, Storage::disk('pieces')->allFiles());
    }

    public function test_png_accepte_avec_son_extension(): void
    {
        $octets = $this->png();

        $this->envoyer($octets, null, 'signature.png')->assertCreated()->assertJsonPath('piece.type_mime', 'image/png');

        $empreinte = hash('sha256', $octets);
        Storage::disk('pieces')->assertExists('pieces/'.substr($empreinte, 0, 2).'/'.$empreinte.'.png');
    }

    public function test_empreinte_annoncee_fausse_refusee(): void
    {
        $octets = $this->jpeg();

        $this->envoyer($octets, hash('sha256', 'autre chose'))->assertStatus(422)
            ->assertJsonPath('message', "L'empreinte annoncée ne correspond pas au fichier reçu.");

        $this->assertSame(0, PieceJointe::count());
        $this->assertSame([], Storage::disk('pieces')->allFiles());
    }

    public function test_empreinte_mal_formee_refusee(): void
    {
        $octets = $this->jpeg();

        $this->envoyer($octets, strtoupper(hash('sha256', $octets)))->assertStatus(422);
        $this->envoyer($octets, 'abc')->assertStatus(422);
        $this->assertSame(0, PieceJointe::count());
    }

    public function test_type_reel_controle_pas_le_nom(): void
    {
        $this->envoyer("%PDF-1.4 pas une image\n", null, 'photo.jpg')->assertStatus(422)
            ->assertJsonPath('message', 'Le fichier doit être une image JPEG ou PNG.');
        $this->envoyer('GIF89a'.str_repeat("\0", 20), null, 'photo.png')->assertStatus(422);

        $this->assertSame(0, PieceJointe::count());
        $this->assertSame([], Storage::disk('pieces')->allFiles());
    }

    public function test_fichier_vide_refuse(): void
    {
        $this->envoyer('', hash('sha256', ''))->assertStatus(422)->assertJsonPath('message', 'Le fichier est vide.');
        $this->assertSame(0, PieceJointe::count());
    }

    public function test_taille_maximale_huit_mo(): void
    {
        $limite = $this->jpeg().str_repeat('x', 8 * 1024 * 1024 - strlen($this->jpeg()));
        $this->assertSame(8 * 1024 * 1024, strlen($limite));
        $this->envoyer($limite)->assertCreated();

        $this->envoyer($limite.'x')->assertStatus(422);
        $this->assertSame(1, PieceJointe::count());
    }

    public function test_fichier_absent_refuse(): void
    {
        Sanctum::actingAs($this->agent, ['terrain']);

        $this->postJson('/api/terrain/pieces', ['empreinte' => hash('sha256', 'x')])->assertStatus(422);
    }

    public function test_presence_d_une_piece_par_head(): void
    {
        $octets = $this->jpeg();
        $empreinte = hash('sha256', $octets);
        Sanctum::actingAs($this->agent, ['terrain']);

        $this->call('HEAD', '/api/terrain/pieces/'.$empreinte)->assertNotFound();

        $this->envoyer($octets)->assertCreated();

        $this->call('HEAD', '/api/terrain/pieces/'.$empreinte)->assertOk();
        $this->call('HEAD', '/api/terrain/pieces/'.hash('sha256', 'inconnue'))->assertNotFound();
        $this->call('HEAD', '/api/terrain/pieces/pas-une-empreinte')->assertNotFound();
    }

    public function test_acces_refuse_sans_jeton_terrain(): void
    {
        $octets = $this->jpeg();
        $corps = ['fichier' => UploadedFile::fake()->createWithContent('p.jpg', $octets), 'empreinte' => hash('sha256', $octets)];

        $this->withHeaders(['Accept' => 'application/json'])->post('/api/terrain/pieces', $corps)->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(['role' => 'logistique_gestionnaire']), ['terrain']);
        $this->withHeaders(['Accept' => 'application/json'])->post('/api/terrain/pieces', $corps)->assertForbidden();

        Sanctum::actingAs($this->agent, ['autre']);
        $this->withHeaders(['Accept' => 'application/json'])->post('/api/terrain/pieces', $corps)->assertForbidden();

        $this->assertSame(0, PieceJointe::count());
    }

    public function test_piece_orpheline_vue_par_le_gestionnaire_seulement(): void
    {
        $octets = $this->jpeg();
        $this->envoyer($octets)->assertCreated();
        $empreinte = hash('sha256', $octets);
        $gestionnaire = User::factory()->create(['role' => 'logistique_gestionnaire']);

        $reponse = $this->actingAs($gestionnaire)->get('/logistique/pieces/'.$empreinte)->assertOk();
        $this->assertSame($octets, $reponse->streamedContent());
        $this->assertSame('image/jpeg', $reponse->headers->get('Content-Type'));
        $this->assertSame('nosniff', $reponse->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('private', (string) $reponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $reponse->headers->get('Cache-Control'));

        $this->actingAs($gestionnaire)->get('/logistique/pieces/'.hash('sha256', 'inconnue'))->assertNotFound();
        $this->actingAs($this->agent)->get('/logistique/pieces/'.$empreinte)->assertForbidden();
        // Sans déclaration qui la porte, aucune pièce n'est visible du terrain.
        $this->actingAs($this->agent)->get('/terrain/pieces/'.$empreinte)->assertNotFound();
        $this->actingAs($gestionnaire)->get('/terrain/pieces/'.$empreinte)->assertForbidden();
    }

    public function test_disque_par_defaut_prive(): void
    {
        $this->assertSame('local', (require config_path('logistique.php'))['disque_pieces']);
        $this->assertFalse(config('filesystems.disks.local.visibility') === 'public');
    }
}

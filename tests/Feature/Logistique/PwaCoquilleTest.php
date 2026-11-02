<?php

namespace Tests\Feature\Logistique;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Tests\TestCase;

class PwaCoquilleTest extends TestCase
{
    use RefreshDatabase;

    private const CSP = "default-src 'self'; img-src 'self' blob: data:; connect-src 'self'";

    protected function setUp(): void
    {
        parent::setUp();
        // Les ressources compilées ne sont pas nécessaires à ces tests : seule la page est examinée.
        $this->withoutVite();
    }

    private function utilisateur(string $role, array $attributs = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'doit_choisir_code' => false], $attributs))->fresh();
    }

    public function test_coquille_servie_aux_roles_terrain(): void
    {
        foreach (['prefet', 'sous_prefet', 'chef_poste', 'agent_transport'] as $role) {
            $reponse = $this->actingAs($this->utilisateur($role))->get('/terrain/app');

            $reponse->assertOk()->assertViewIs('terrain.app')
                ->assertSee('id="terrain-racine"', false)
                ->assertSee('id="terrain-amorce"', false);
            $this->assertStringContainsString('text/html', $reponse->headers->get('Content-Type'), $role);
            $this->assertSame('/terrain/app', parse_url(route('terrain.app'), PHP_URL_PATH));
        }
    }

    public function test_coquille_refusee_aux_autres(): void
    {
        $this->get('/terrain/app')->assertRedirect(route('login'));

        foreach (['admin', 'gouverneur', 'commission', 'logistique_gestionnaire', 'logistique_responsable'] as $role) {
            $this->actingAs($this->utilisateur($role))->get('/terrain/app')->assertForbidden();
        }
        // Un compte désactivé n'ouvre pas l'application.
        $this->actingAs($this->utilisateur('agent_transport', ['actif' => false]))->get('/terrain/app')->assertForbidden();
    }

    public function test_autorite_sans_code_choisi_est_menee_au_choix_du_code(): void
    {
        $autorite = $this->utilisateur('prefet', ['doit_choisir_code' => true]);

        $this->actingAs($autorite)->get('/terrain/app')->assertRedirect(route('terrain.code.edit'));
    }

    public function test_manifest_et_service_worker_servis(): void
    {
        // Pas d'authentification : le navigateur les récupère sans session, y compris pour la mise à jour.
        $manifeste = $this->get('/terrain/manifest.webmanifest');
        $manifeste->assertOk();
        $this->assertStringStartsWith('application/manifest+json', $manifeste->headers->get('Content-Type'));
        $donnees = json_decode($manifeste->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('/terrain/', $donnees['scope']);
        $this->assertSame('/terrain/app', $donnees['start_url']);
        $this->assertSame('standalone', $donnees['display']);
        $this->assertNotSame('', $donnees['name']);
        $this->assertNotEmpty($donnees['icons']);
        foreach ($donnees['icons'] as $icone) {
            $this->assertStringStartsWith('/terrain/', $icone['src']);
        }

        $sw = $this->get('/terrain/sw.js');
        $sw->assertOk();
        $this->assertStringStartsWith('text/javascript', $sw->headers->get('Content-Type'));
        // Le script est servi sous /terrain/ : son étendue maximale est /terrain/, jamais la racine du site.
        $this->assertSame('/terrain/', $sw->headers->get('Service-Worker-Allowed'));
        $this->assertStringContainsString('no-cache', $sw->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('__VERSION__', $sw->getContent());
        $this->assertMatchesRegularExpression("/const VERSION = '[0-9a-f]{12}';/", $sw->getContent());
        $this->assertSame('/terrain/sw.js', parse_url(route('pwa.terrain.sw'), PHP_URL_PATH));

        $icone = $this->get('/terrain/icon.svg');
        $icone->assertOk();
        $this->assertStringStartsWith('image/svg+xml', $icone->headers->get('Content-Type'));
        $this->assertStringContainsString('<svg', $icone->getContent());
    }

    public function test_version_du_service_worker_change_avec_les_ressources_compilees(): void
    {
        $manifeste = tempnam(sys_get_temp_dir(), 'vite');
        config(['logistique.pwa_manifeste_vite' => $manifeste]);

        try {
            file_put_contents($manifeste, '{"resources/js/terrain/app.js":{"file":"assets/app-aaaa.js"}}');
            $v1 = $this->get('/terrain/sw.js')->getContent();
            file_put_contents($manifeste, '{"resources/js/terrain/app.js":{"file":"assets/app-bbbb.js"}}');
            $v2 = $this->get('/terrain/sw.js')->getContent();
            $v3 = $this->get('/terrain/sw.js')->getContent();
        } finally {
            unlink($manifeste);
        }

        $this->assertSame($v2, $v3, 'La version est stable tant que le build ne change pas.');
        $this->assertNotSame($v1, $v2, 'Un nouveau build doit produire un nouveau service worker.');
    }

    public function test_csp_stricte(): void
    {
        $reponse = $this->actingAs($this->utilisateur('agent_transport'))->get('/terrain/app');

        $this->assertSame(self::CSP, $reponse->headers->get('Content-Security-Policy'));
    }

    public function test_coquille_sans_script_ni_style_en_ligne_et_sans_donnee_personnelle(): void
    {
        $utilisateur = $this->utilisateur('agent_transport', ['email' => 'agent.confidentiel@exemple.sn']);
        $html = $this->actingAs($utilisateur)->get('/terrain/app')->getContent();

        // Seuls sont admis : un script chargé par son URL, ou un bloc de données de type application/json.
        preg_match_all('/<script\b([^>]*)>/i', $html, $balises);
        foreach ($balises[1] as $attributs) {
            $this->assertTrue(
                str_contains($attributs, ' src=') || str_starts_with(ltrim($attributs), 'src=') || str_contains($attributs, 'type="application/json"'),
                "Script en ligne : <script{$attributs}>",
            );
        }
        $this->assertDoesNotMatchRegularExpression('/<style\b/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);

        // La page est mise en cache par le service worker : elle ne doit rien contenir de propre au compte connecté.
        $this->assertStringNotContainsString('agent.confidentiel@exemple.sn', $html);
        $this->assertStringNotContainsString($utilisateur->name, $html);
        $this->assertStringNotContainsString('csrf', $html);

        preg_match('#<script type="application/json" id="terrain-amorce">(.*?)</script>#s', $html, $amorce);
        $donnees = json_decode($amorce[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('/terrain/sw.js', $donnees['service_worker']);
        $this->assertSame('/terrain/', $donnees['portee']);
        $this->assertSame('/api/terrain', $donnees['api']);
    }

    public function test_ressources_compilees_designees_depuis_la_racine_du_site(): void
    {
        // Avec les vraies ressources : jamais d'URL absolue (APP_URL), qui sortirait de l'origine 'self'.
        $this->withVite();
        $dossier = 'build-test-'.bin2hex(random_bytes(4));
        mkdir(public_path($dossier));
        file_put_contents(public_path("{$dossier}/manifest.json"), json_encode([
            'resources/css/app.css' => ['file' => 'assets/app-aaaa.css', 'src' => 'resources/css/app.css', 'isEntry' => true],
            'resources/js/terrain/app.js' => ['file' => 'assets/terrain-bbbb.js', 'src' => 'resources/js/terrain/app.js', 'isEntry' => true],
        ]));
        config(['app.url' => 'https://autre-hote.exemple']);

        try {
            app(Vite::class)->useBuildDirectory($dossier);
            $html = $this->actingAs($this->utilisateur('agent_transport'))->get('/terrain/app')->getContent();
        } finally {
            @unlink(public_path("{$dossier}/manifest.json"));
            @rmdir(public_path($dossier));
        }

        $this->assertStringContainsString('<script type="module" src="/'.$dossier.'/assets/terrain-bbbb.js"', $html);
        $this->assertStringContainsString('href="/'.$dossier.'/assets/app-aaaa.css"', $html);
        $this->assertStringNotContainsString('autre-hote.exemple', $html);
        $this->assertStringNotContainsString('http://', $html);
    }

    public function test_routes_publiques_limitees_au_script_au_manifeste_et_a_l_icone(): void
    {
        foreach (['pwa.terrain.sw', 'pwa.terrain.manifeste', 'pwa.terrain.icone'] as $nom) {
            $route = app('router')->getRoutes()->getByName($nom);
            $this->assertNotNull($route, $nom);
            $this->assertNotContains('auth', $route->gatherMiddleware(), $nom);
            $this->assertSame(['GET', 'HEAD'], $route->methods(), $nom);
        }
    }
}

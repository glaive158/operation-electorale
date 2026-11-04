<?php

namespace App\Http\Controllers\Terrain;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Response;

/**
 * Application de terrain installable (PWA) : une coquille unique, chargée une fois puis conservée par le
 * service worker, qui parle à l'API par jeton et garde ses données dans la tablette. Le service worker, le
 * manifeste et l'icône sont publics (le navigateur les relit sans session) et servis sous /terrain/ pour que
 * l'étendue du service worker soit /terrain/ et jamais le site entier.
 */
class AppController extends Controller
{
    /** Politique de sécurité de la coquille : rien d'extérieur, aucun script ni style en ligne. */
    private const CSP = "default-src 'self'; img-src 'self' blob: data:; connect-src 'self'";

    private const PORTEE = '/terrain/';

    public function coquille(): Response
    {
        // Ressources compilées désignées depuis la racine du site : toujours de la même origine que la page,
        // quel que soit l'hôte par lequel la tablette y accède (la politique de sécurité n'admet que 'self').
        // Sur une copie : l'instance partagée de Vite (et donc les autres pages) garde ses chemins habituels.
        $vite = clone app(Vite::class);
        $vite->createAssetPathsUsing(fn (string $chemin) => '/'.ltrim($chemin, '/'));

        return response()->view('terrain.app', [
            'vite' => $vite,
            // Données d'amorçage identiques pour tous : la page est mise en cache, elle ne porte rien de personnel.
            'amorce' => [
                'service_worker' => route('pwa.terrain.sw', [], false),
                'portee' => self::PORTEE,
                'api' => '/api/terrain',
            ],
        ])->header('Content-Security-Policy', self::CSP);
    }

    public function serviceWorker(): Response
    {
        $script = file_get_contents(resource_path('pwa/terrain-sw.js'));

        return response(str_replace('__VERSION__', $this->version($script), $script), 200, [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            'Service-Worker-Allowed' => self::PORTEE,
            // Le navigateur doit pouvoir détecter un nouveau script à chaque contrôle.
            'Cache-Control' => 'no-cache, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function manifeste(): Response
    {
        $manifeste = [
            'name' => 'Terrain — Logistique électorale',
            'short_name' => 'Terrain',
            'description' => 'Réceptions et redistributions du matériel électoral, utilisables sans connexion.',
            'lang' => 'fr',
            'start_url' => route('terrain.app', [], false),
            'scope' => self::PORTEE,
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#f1f5f9',
            'theme_color' => '#009A44',
            'icons' => [[
                'src' => route('pwa.terrain.icone', [], false),
                'sizes' => 'any',
                'type' => 'image/svg+xml',
                'purpose' => 'any',
            ]],
        ];

        return response(json_encode($manifeste, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 200, [
            'Content-Type' => 'application/manifest+json; charset=UTF-8',
            'Cache-Control' => 'no-cache, max-age=0',
        ]);
    }

    public function icone(): Response
    {
        return response(file_get_contents(resource_path('pwa/terrain-icon.svg')), 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /** Version du service worker : son propre texte et le manifeste des ressources compilées. */
    private function version(string $script): string
    {
        $manifeste = (string) config('logistique.pwa_manifeste_vite');
        $ressources = is_file($manifeste) ? (string) file_get_contents($manifeste) : '';

        return substr(sha1($script.$ressources), 0, 12);
    }
}

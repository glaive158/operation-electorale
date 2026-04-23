<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FichierElectoralService
{
    private string $baseUrl;
    private string $secret;
    private int    $timeout = 5;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.fichier_electoral.url'), '/');
        $this->secret  = config('services.fichier_electoral.secret');
    }

    private function client()
    {
        return Http::withHeader('X-API-Secret', $this->secret)
                   ->timeout($this->timeout)
                   ->retry(2, 100);
    }

    public function byNin(string $nin): ?array
    {
        $cacheKey = 'fe_nin_' . md5($nin);

        return Cache::remember($cacheKey, 300, function () use ($nin) {
            try {
                $response = $this->client()->get("{$this->baseUrl}/api/v1/electeur/nin/{$nin}");
                if ($response->successful() && $response->json('found')) {
                    return $response->json();
                }
            } catch (\Throwable $e) {
                Log::error("FichierElectoralService::byNin - {$e->getMessage()}");
            }
            return null;
        });
    }

    public function search(array $params): array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/api/v1/electeur/search", $params);
            if ($response->successful()) return $response->json();
        } catch (\Throwable $e) {
            Log::error("FichierElectoralService::search - {$e->getMessage()}");
        }
        return ['data' => [], 'total' => 0, 'current_page' => 1, 'per_page' => 50];
    }

    public function statsSummary(): array
    {
        return Cache::remember('fe_stats_summary', 1800, function () {
            try {
                $response = $this->client()->get("{$this->baseUrl}/api/v1/stats/summary");
                if ($response->successful()) return $response->json();
            } catch (\Throwable $e) {
                Log::error("FichierElectoralService::statsSummary - {$e->getMessage()}");
            }
            return ['total' => 0, 'national' => 0, 'etranger' => 0];
        });
    }

    public function statsGeo(array $params = []): array
    {
        $cacheKey = 'fe_stats_geo_' . md5(serialize($params));
        return Cache::remember($cacheKey, 1800, function () use ($params) {
            try {
                $response = $this->client()->get("{$this->baseUrl}/api/v1/stats/geo", $params);
                if ($response->successful()) return $response->json();
            } catch (\Throwable $e) {
                Log::error("FichierElectoralService::statsGeo - {$e->getMessage()}");
            }
            return [];
        });
    }
}

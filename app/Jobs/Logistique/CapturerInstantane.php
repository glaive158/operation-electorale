<?php

namespace App\Jobs\Logistique;

use App\Models\Logistique\InstantaneEffectifs;
use App\Services\Logistique\InstantaneService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Capture asynchrone d'un instantané : la lecture du fichier électoral peut être longue. */
class CapturerInstantane implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // Inférieur au retry_after de la connexion « database_long » : le job n'est jamais réservé deux fois.
    public int $timeout = 3600;

    public function __construct(public readonly int $instantaneId)
    {
        $connexion = config('logistique.queue_capture');
        if ($connexion !== null) {
            $this->onConnection($connexion);
        }
    }

    public function handle(InstantaneService $service): void
    {
        $instantane = InstantaneEffectifs::find($this->instantaneId);
        if ($instantane === null || $instantane->statut !== 'en_cours') {
            return;
        }

        $service->capturer($instantane);
    }

    /** Arrêt brutal (délai dépassé, processus tué) : l'instantané ne doit pas rester « en cours ». */
    public function failed(?Throwable $exception = null): void
    {
        InstantaneEffectifs::whereKey($this->instantaneId)
            ->where('statut', 'en_cours')
            ->update([
                'statut' => 'echec',
                'erreur' => mb_substr($exception?->getMessage() ?: 'Capture interrompue.', 0, 1000),
            ]);
    }
}

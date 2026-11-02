<?php

namespace App\Http\Controllers\Api\Terrain;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Appareil;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Destinataire;
use App\Services\Logistique\PerimetreTerrain;
use App\Services\Logistique\RemiseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Ce que partagent les points d'entrée de l'appareil désigné : l'identifiant annoncé par l'en-tête X-Appareil et la
 * préfecture dont l'appelant est l'autorité (le préfet ou son adjoint habilité).
 */
trait ResoutAppareil
{
    /** L'identifiant de l'appareil annoncé par le terminal (en-tête X-Appareil), ou null en ligne. */
    private function identifiantAppareil(Request $request): ?string
    {
        $identifiant = $request->header('X-Appareil');
        if ($identifiant === null || $identifiant === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9-]{16,64}$/', $identifiant) !== 1) {
            throw new InvalidArgumentException("L'en-tête X-Appareil est invalide.");
        }

        return mb_strtolower($identifiant);
    }

    /**
     * L'appareil désigné que l'appelant est en train d'utiliser : actif (sauf `$actifSeulement` faux : un appareil révoqué
     * reste reconnu pour que sa synchronisation soit arbitrée), dont le jeton épinglé à la désignation est
     * le jeton de la requête (et n'a pas expiré), et dont l'identifiant égale l'en-tête X-Appareil quand il est envoyé.
     * L'en-tête seul ne prouve rien, ni l'UUID annoncé à la connexion : seul le jeton épinglé par le central fait foi.
     */
    private function appareilReconnu(Request $request, ?string $identifiant, bool $actifSeulement = true): ?Appareil
    {
        $jeton = $request->user()?->currentAccessToken();
        if (! $jeton instanceof Model || $jeton->getKey() === null) {
            return null;
        }
        if ($jeton->getAttribute('expires_at') !== null && ! $jeton->getAttribute('expires_at')->isFuture()) {
            return null;
        }

        $appareil = Appareil::where('jeton_id', $jeton->getKey())->when($actifSeulement, fn ($q) => $q->where('actif', true))->first();
        if ($appareil === null || ($identifiant !== null && ! hash_equals(mb_strtolower($appareil->identifiant), $identifiant))) {
            return null;
        }

        return $appareil;
    }

    /**
     * La préfecture qui redistribue la décharge, si l'appelant la voit et en est l'autorité ; sinon la décharge
     * n'existe pas pour lui (404).
     */
    private function prefectureDeLAppelant(Request $request, Decharge $decharge, PerimetreTerrain $perimetre, RemiseService $remises): Destinataire
    {
        if (! $perimetre->peutVoir($request->user(), $decharge)) {
            throw (new ModelNotFoundException)->setModel(Decharge::class, [$decharge->id]);
        }

        try {
            return $remises->prefectureQuiRedistribue($decharge, $request->user());
        } catch (RegleLogistique) {
            throw (new ModelNotFoundException)->setModel(Decharge::class, [$decharge->id]);
        }
    }

    /** L'appareil désigné (actif) de la préfecture, annoncé par l'en-tête (obligatoire ici) et prouvé par le jeton ; sinon 403. */
    private function appareilDesigne(Request $request, Destinataire $prefecture): Appareil
    {
        $identifiant = $this->identifiantAppareil($request);
        $appareil = $identifiant === null ? null : $this->appareilReconnu($request, $identifiant);

        if ($appareil === null || (int) $appareil->destinataire_id !== (int) $prefecture->id) {
            throw new HttpResponseException(response()->json(['message' => 'Appareil non désigné pour cette préfecture.'], 403));
        }

        return $appareil;
    }
}

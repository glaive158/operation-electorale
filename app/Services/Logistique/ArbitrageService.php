<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Arbitrage;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Remise;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

/**
 * Arbitrage du responsable sur une déclaration à vérifier : il la retient (les autres déclarations actives de la
 * remise sont écartées) ou la rejette (la remise attend alors une nouvelle déclaration, ou le rapprochement des
 * déclarations restantes). Une déclaration n'est jamais modifiée dans ses quantités ni supprimée : seul son
 * statut change, et ses anomalies restent comme historique.
 *
 * Ordre des verrous : cycle, décharge, remise, déclaration, article, réservation.
 */
class ArbitrageService
{
    private const MAX_TEXTE = 500;

    public function __construct(private readonly DeclarationService $declarations) {}

    public function retenir(Declaration $declaration, string $motif): void
    {
        $this->arbitrer($declaration, $motif, true);
    }

    public function rejeter(Declaration $declaration, string $motif): void
    {
        $this->arbitrer($declaration, $motif, false);
    }

    private function arbitrer(Declaration $declaration, string $motif, bool $retenir): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Arbitrage sans auteur.');
        }
        if (! Gate::allows('logistique-administrer')) {
            throw new RegleLogistique('Seul un responsable logistique peut arbitrer une déclaration.');
        }
        $motif = trim($motif);
        if ($motif === '' || mb_strlen($motif) > self::MAX_TEXTE) {
            throw new InvalidArgumentException('Le motif est obligatoire (500 caractères au plus).');
        }

        $cycleId = Decharge::whereKey($declaration->decharge_id)->value('cycle_id');
        if ($cycleId === null) {
            throw new LogicException('Déclaration sans décharge : invariant rompu.');
        }

        DB::transaction(function () use ($declaration, $motif, $retenir, $cycleId) {
            $cycle = Cycle::whereKey($cycleId)->lockForUpdate()->first();
            if ($cycle === null || ! $cycle->estOuvert()) {
                throw new RegleLogistique('Cycle clôturé : arbitrage impossible.');
            }
            $decharge = Decharge::whereKey($declaration->decharge_id)->lockForUpdate()->firstOrFail();
            $remise = Remise::whereKey($declaration->remise_id)->lockForUpdate()->firstOrFail();
            $actives = Declaration::where('remise_id', $remise->id)->orderBy('id')->lockForUpdate()->get();
            $actuelle = $actives->firstWhere('id', $declaration->id);

            if ($actuelle === null || $remise->statut !== 'a_verifier' || ! in_array($actuelle->statut, ['a_verifier', 'valide'], true)) {
                throw new RegleLogistique('Rien à arbitrer pour cette déclaration.');
            }

            $avant = $actuelle->statut;
            $ecartees = [];
            if ($retenir) {
                foreach ($actives as $autre) {
                    if ($autre->id !== $actuelle->id && $autre->estActive()) {
                        $this->changerStatut($autre, 'ecartee', $remise);
                        $ecartees[] = $autre->id;
                    }
                }
            }
            $this->changerStatut($actuelle, $retenir ? 'retenue' : 'ecartee', $remise);

            if ($retenir) {
                $this->changerStatutRemise($remise, 'recue');
            } else {
                $restantes = Declaration::where('remise_id', $remise->id)->whereNotIn('statut', ['ecartee', 'remplacee'])->exists();
                if ($restantes) {
                    // Les déclarations restantes sont rapprochées de nouveau, sans statut inventé.
                    $this->declarations->rapprocher($remise);
                } else {
                    $this->changerStatutRemise($remise, 'en_attente');
                }
            }

            $decision = $retenir ? 'retenue' : 'ecartee';
            Arbitrage::create([
                'cycle_id' => $cycleId, 'objet' => 'declaration', 'remise_id' => $remise->id,
                'declaration_id' => $actuelle->id,
                'destinataire_id' => $remise->a_type === 'destinataire' && $remise->a_id !== null ? $remise->a_id : $decharge->destinataire_id,
                'decision' => $decision, 'motif' => $motif, 'user_id' => auth()->id(),
            ]);
            Journal::noter('declaration', $actuelle->id, 'arbitrage_declaration', ['statut' => $avant], [
                'statut' => $decision, 'decision' => $decision,
                'remise_id' => $remise->id, 'decharge_id' => $decharge->id, 'ecartees' => $ecartees,
            ], $motif);

            $this->declarations->apresRetenue($remise->refresh());
        }, attempts: 3);

        $declaration->refresh();
    }

    private function changerStatut(Declaration $declaration, string $statut, Remise $remise): void
    {
        $avant = $declaration->statut;
        $declaration->forceFill(['statut' => $statut])->save();
        Journal::noter('declaration', $declaration->id, 'statut_declaration', ['statut' => $avant], [
            'statut' => $statut, 'remise_id' => $remise->id, 'anomalies' => $declaration->anomalies ?? [],
        ]);
    }

    private function changerStatutRemise(Remise $remise, string $statut): void
    {
        $avant = $remise->statut;
        $remise->forceFill(['statut' => $statut])->save();
        Journal::noter('remise', $remise->id, 'statut_remise', ['statut' => $avant], ['statut' => $statut]);
    }
}

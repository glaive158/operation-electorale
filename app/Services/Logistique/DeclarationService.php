<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Arbitrage;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\DeclarationLigne;
use App\Models\Logistique\Delegation;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\Remise;
use App\Models\Logistique\RemiseLigne;
use App\Models\User;
use App\Support\Canonique;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

/**
 * Déclarations de réception : l'autorité destinataire (compte) ou l'agent (tablette) déclare ce qui a été
 * reçu et ce qui est défectueux sur une remise. Une déclaration n'est jamais modifiée dans ses quantités ni
 * supprimée ; le rapprochement ne fait évoluer que son statut.
 *
 * Ordre des verrous : cycle, décharge, remise, déclaration, article, réservation.
 */
class DeclarationService
{
    private const HORS_PERIMETRE = 'Déclaration hors de votre périmètre.';

    private const MAX_QUANTITE = 10_000_000;

    public function __construct(
        private readonly PerimetreTerrain $perimetre,
        private readonly DelegationService $delegations,
    ) {}

    /**
     * Enregistre une déclaration de réception sur une remise de livraison ou de redistribution.
     *
     * @param  array{nom?: string, qualite?: string}  $signataire
     * @param  array<int, array{recu: int|string, defectueux: int|string, observation?: ?string}>  $lignes  par identifiant d'article
     */
    public function declarer(
        Remise $remise,
        string $mode,
        array $signataire,
        array $lignes,
        string $cle,
        ?CarbonInterface $horodatageTerminal = null,
    ): Declaration {
        $this->exigerAuteur();
        $auteur = auth()->user();

        if (! array_key_exists($mode, Declaration::MODES)) {
            throw new InvalidArgumentException('Mode de déclaration inconnu.');
        }
        if (preg_match('/^[A-Za-z0-9-]{16,64}$/', $cle) !== 1) {
            throw new InvalidArgumentException("La clé d'idempotence doit compter de 16 à 64 caractères (lettres, chiffres, tirets).");
        }
        $signataire = $this->signataire($signataire);
        $lignes = $this->normaliser($lignes);
        $empreinte = self::empreinte((int) $remise->id, $mode, $signataire, $lignes);

        $cycleId = Decharge::whereKey($remise->decharge_id)->value('cycle_id');
        if ($cycleId === null) {
            throw new LogicException('Remise sans décharge : invariant rompu.');
        }

        [$declaration, $conflit] = DB::transaction(function () use ($remise, $mode, $signataire, $lignes, $cle, $horodatageTerminal, $empreinte, $cycleId, $auteur) {
            $cycle = Cycle::whereKey($cycleId)->lockForUpdate()->first();

            // Rejeu : avant tout autre contrôle.
            $existante = Declaration::where('cle_idempotence', $cle)->first();
            if ($existante !== null) {
                if (hash_equals($existante->empreinte_contenu, $empreinte)) {
                    return [$existante, false];
                }
                Journal::noter('declaration', $existante->id, 'anomalie_idempotence', null, [
                    'cle' => $cle, 'remise_id' => $remise->id, 'mode' => $mode, 'empreinte_recue' => $empreinte,
                ], "Clé d'idempotence réutilisée pour un autre contenu.");

                return [$existante, true];
            }

            // Une déclaration de réception ne touche à aucun stock central : elle reste possible après la clôture
            // du cycle (une livraison non déclarée doit pouvoir l'être). Le verrou du cycle suffit à l'ordonner.
            if ($cycle === null) {
                throw new LogicException('Cycle introuvable : invariant rompu.');
            }
            $decharge = Decharge::whereKey($remise->decharge_id)->lockForUpdate()->firstOrFail();
            $actuelle = Remise::whereKey($remise->id)->lockForUpdate()->firstOrFail();

            if (! in_array($actuelle->etape, ['livraison', 'redistribution'], true)) {
                throw new RegleLogistique("Cette remise n'attend pas de déclaration.");
            }
            ['anomalies' => $anomalies, 'delegation' => $delegation] = $this->controlerDroit($auteur, $actuelle, $decharge, $mode);

            if (! in_array($actuelle->statut, ['en_attente', 'a_verifier'], true) && ! $this->attendUneConfirmation($actuelle)) {
                throw new RegleLogistique("Cette remise n'attend pas de déclaration.");
            }
            $dejaFaite = Declaration::where('remise_id', $actuelle->id)->where('mode', $mode)
                ->whereNotIn('statut', ['ecartee', 'remplacee'])->exists();
            if ($dejaFaite) {
                throw new RegleLogistique('Une déclaration '.mb_strtolower(Declaration::MODES[$mode]).' existe déjà pour cette remise.');
            }

            $anomalies = array_merge($anomalies, $this->controlerLignes($actuelle, $lignes));
            if ($decharge->statut_document === 'annulee') {
                $anomalies[] = 'version_annulee';
            }
            $anomalies = array_values(array_unique($anomalies));

            $declaration = new Declaration([
                'remise_id' => $actuelle->id, 'mode' => $mode,
                'signataire_nom' => $signataire['nom'], 'signataire_qualite' => $signataire['qualite'],
                'user_id' => $auteur->id, 'decharge_id' => $decharge->id,
                'horodatage_terminal' => $horodatageTerminal, 'cle_idempotence' => $cle, 'empreinte_contenu' => $empreinte,
            ]);
            $declaration->forceFill([
                'statut' => $anomalies === [] ? 'valide' : 'a_verifier',
                'anomalies' => $anomalies === [] ? null : $anomalies,
                // Au compte, le signataire est celui qui est connecté : l'autorité, ou son délégué (et la délégation).
                'signataire_user_id' => $mode === 'compte' ? $auteur->id : null,
                'delegation_id' => $delegation?->id,
            ])->save();
            foreach ($lignes as $ligne) {
                DeclarationLigne::create(['declaration_id' => $declaration->id] + $ligne);
            }

            $this->rapprocher($actuelle);
            $declaration->refresh();

            Journal::noter('declaration', $declaration->id, 'declaration_reception', null, [
                'remise_id' => $actuelle->id, 'decharge_id' => $decharge->id, 'mode' => $mode,
                'statut' => $declaration->statut, 'anomalies' => $declaration->anomalies ?? [],
                'signataire_user_id' => $declaration->signataire_user_id, 'delegation_id' => $declaration->delegation_id,
                'cle' => $cle, 'empreinte' => $empreinte, 'signataire' => $signataire, 'lignes' => $lignes,
                'horodatage_terminal' => $horodatageTerminal?->toIso8601String(),
            ]);

            $this->apresRetenue($actuelle);

            return [$declaration, false];
        }, attempts: 3);

        if ($conflit) {
            throw new RegleLogistique("Clé d'idempotence déjà utilisée pour un autre contenu.");
        }

        $remise->refresh();

        return $declaration->load('lignes');
    }

    /**
     * Recalcule le statut d'acheminement de la décharge d'une remise après une retenue, un écartement ou une
     * anomalie : « à vérifier » tant qu'une de ses remises l'est ; sinon « reçue par le destinataire » si le
     * destinataire final a une déclaration retenue, « reçue par l'intermédiaire » si une autre remise en a une,
     * et « chargée » si aucune ne l'est. Une redistribution partielle laisse la décharge reçue par le destinataire.
     *
     * Hors de declarer(), l'appelant détient déjà le verrou du cycle (ordre des verrous : cycle d'abord).
     */
    public function apresRetenue(Remise $remise): void
    {
        $this->exigerAuteur();

        DB::transaction(function () use ($remise) {
            $decharge = Decharge::whereKey($remise->decharge_id)->lockForUpdate()->firstOrFail();
            if ($decharge->statut_acheminement === 'a_preparer') {
                return;
            }

            $remises = Remise::where('decharge_id', $decharge->id)
                ->whereIn('etape', ['livraison', 'redistribution'])->where('statut', '!=', 'annulee')->get();

            if ($remises->contains('statut', 'a_verifier')) {
                $cible = 'a_verifier';
            } else {
                $retenues = Declaration::whereIn('remise_id', $remises->pluck('id'))->where('statut', 'retenue')->pluck('remise_id');
                $final = $remises->whereIn('id', $retenues->all())->first(
                    fn (Remise $r) => $r->a_type === 'destinataire' && (int) $r->a_id === (int) $decharge->destinataire_id
                );
                $cible = $final !== null ? 'recue_destinataire' : ($retenues->isNotEmpty() ? 'recue_intermediaire' : 'chargee');
            }

            if ($cible !== $decharge->statut_acheminement) {
                $avant = $decharge->statut_acheminement;
                $decharge->forceFill(['statut_acheminement' => $cible])->save();
                Journal::noter('decharge', $decharge->id, 'acheminement_decharge', ['statut_acheminement' => $avant], [
                    'statut_acheminement' => $cible, 'remise_id' => $remise->id,
                ]);
            }

            // Service résolu à l'appel : le service des réclamations dépend lui-même de celui-ci.
            app(ReclamationService::class)->verifierRemplacements($decharge);
        }, attempts: 3);
    }

    /**
     * Qui a le droit de déclarer, et avec quelles anomalies : le compte de l'autorité destinataire ; la tablette
     * de l'agent de la décharge (un ancien agent est accepté mais à vérifier) ou, sur une redistribution,
     * celle de l'autorité qui redistribue. Au compte, le délégué en vigueur de l'autorité destinataire déclare
     * aussi, et la délégation est rapportée ; le départ d'une redistribution reste celui de l'autorité elle-même.
     *
     * @return array{anomalies: list<string>, delegation: ?Delegation}
     */
    private function controlerDroit(User $auteur, Remise $remise, Decharge $decharge, string $mode): array
    {
        if (! Gate::forUser($auteur)->allows('logistique-terrain')) {
            throw new RegleLogistique(self::HORS_PERIMETRE);
        }

        if ($mode === 'compte') {
            $destinataire = $remise->a_type === 'destinataire' && $remise->a_id !== null ? Destinataire::find($remise->a_id) : null;
            // Un seul instant pour les deux contrôles : une délégation qui prend fin entre les deux ne passe pas.
            $maintenant = now();
            if ($destinataire === null || ! $this->perimetre->estAutoriteDe($auteur, $destinataire, $maintenant)) {
                throw new RegleLogistique(self::HORS_PERIMETRE);
            }

            $delegation = $this->perimetre->estTitulaireDe($auteur, $destinataire) ? null : $this->delegations->activeA($destinataire, $auteur, $maintenant);
            if ($delegation === null && ! $this->perimetre->estTitulaireDe($auteur, $destinataire)) {
                throw new RegleLogistique(self::HORS_PERIMETRE);
            }

            return ['anomalies' => [], 'delegation' => $delegation];
        }

        if ($remise->etape === 'livraison') {
            if ($remise->de_type !== 'agent') {
                throw new RegleLogistique(self::HORS_PERIMETRE);
            }
            if ($decharge->agent_id !== null && (int) $decharge->agent_id === (int) $auteur->id) {
                return ['anomalies' => [], 'delegation' => null];
            }
            if ((int) $remise->de_id === (int) $auteur->id) {
                return ['anomalies' => ['affectation_retiree'], 'delegation' => null];
            }
            throw new RegleLogistique(self::HORS_PERIMETRE);
        }

        $destinataire = $remise->de_type === 'destinataire' && $remise->de_id !== null ? Destinataire::find($remise->de_id) : null;
        if ($destinataire === null || ! $this->perimetre->estTitulaireDe($auteur, $destinataire)) {
            throw new RegleLogistique(self::HORS_PERIMETRE);
        }

        return ['anomalies' => [], 'delegation' => null];
    }

    /**
     * Une remise déjà reçue attend encore la déclaration de l'autre mode, tant qu'aucun arbitrage n'a tranché.
     */
    private function attendUneConfirmation(Remise $remise): bool
    {
        return $remise->statut === 'recue' && ! Arbitrage::where('remise_id', $remise->id)->exists();
    }

    /**
     * Anomalies tirées des quantités ; refuse une déclaration incomplète ou incohérente.
     *
     * @param  list<array{materiel_id: int, recu: int, defectueux: int, observation: ?string}>  $lignes
     * @return list<string>
     */
    private function controlerLignes(Remise $remise, array $lignes): array
    {
        $attendues = RemiseLigne::where('remise_id', $remise->id)->pluck('quantite', 'materiel_id')
            ->map(fn ($quantite) => (int) $quantite)->all();
        $declarees = array_column($lignes, 'materiel_id');
        sort($declarees);
        $ids = array_map('intval', array_keys($attendues));
        sort($ids);
        if ($declarees !== $ids) {
            throw new InvalidArgumentException('Chaque ligne de la remise doit être déclarée.');
        }

        $anomalies = [];
        foreach ($lignes as $ligne) {
            if ($ligne['defectueux'] > $ligne['recu']) {
                $libelle = Materiel::find($ligne['materiel_id'])?->libelle ?? "article {$ligne['materiel_id']}";
                throw new InvalidArgumentException("Défectueux supérieur au reçu pour {$libelle}.");
            }
            if ($ligne['recu'] > $attendues[$ligne['materiel_id']]) {
                $anomalies[] = 'excedent';
            }
        }

        return $anomalies;
    }

    /**
     * Rapproche les déclarations actives de la remise (ni écartées ni remplacées) : une seule saine est
     * retenue ; deux de modes différents, saines et identiques, se confirment (la plus ancienne est retenue) ;
     * deux divergentes sont toutes deux à vérifier, et rien n'est compté jusqu'à l'arbitrage.
     *
     * Hors de declarer() (arbitrage), l'appelant détient déjà les verrous : cycle, décharge, remise.
     */
    public function rapprocher(Remise $remise): void
    {
        $actives = Declaration::where('remise_id', $remise->id)->whereNotIn('statut', ['ecartee', 'remplacee'])
            ->orderBy('id')->lockForUpdate()->with('lignes')->get()->values();

        if ($actives->count() > 2) {
            throw new LogicException('Plus de deux déclarations actives sur une remise : invariant rompu.');
        }

        $cibles = [];
        $divergence = false;
        if ($actives->count() === 1) {
            $cibles[$actives[0]->id] = $this->saine($actives[0]) ? 'retenue' : 'a_verifier';
        } elseif ($actives->count() === 2) {
            [$premiere, $seconde] = [$actives[0], $actives[1]];
            if ($this->contenu($premiere) !== $this->contenu($seconde)) {
                $divergence = true;
                $cibles = [$premiere->id => 'a_verifier', $seconde->id => 'a_verifier'];
            } elseif ($this->saine($premiere) && $this->saine($seconde)) {
                $cibles = [$premiere->id => 'retenue', $seconde->id => 'concordante'];
            } else {
                $cibles = [$premiere->id => 'a_verifier', $seconde->id => 'a_verifier'];
            }
        }

        foreach ($actives as $declaration) {
            $cible = $cibles[$declaration->id];
            $anomalies = $declaration->anomalies ?? [];
            if ($divergence && ! in_array('divergence', $anomalies, true)) {
                $anomalies[] = 'divergence';
            }
            if ($cible === $declaration->statut && $anomalies === ($declaration->anomalies ?? [])) {
                continue;
            }

            $avant = $declaration->statut;
            $declaration->forceFill(['statut' => $cible, 'anomalies' => $anomalies === [] ? null : $anomalies])->save();
            if ($cible === 'retenue') {
                Journal::noter('declaration', $declaration->id, 'retenue_declaration', ['statut' => $avant], [
                    'statut' => 'retenue', 'remise_id' => $remise->id, 'mode' => $declaration->mode,
                ]);
            }
            if ($cible !== $avant) {
                Journal::noter('declaration', $declaration->id, 'statut_declaration', ['statut' => $avant], [
                    'statut' => $cible, 'remise_id' => $remise->id, 'anomalies' => $anomalies,
                ]);
            }
        }

        if ($divergence) {
            Journal::noter('remise', $remise->id, 'divergence_declarations', null, [
                'declarations' => $actives->pluck('id')->all(), 'contenus' => $actives->map(fn ($d) => $this->contenu($d))->all(),
            ]);
        }

        $statut = $actives->contains(fn ($d) => $cibles[$d->id] === 'a_verifier') ? 'a_verifier'
            : ($actives->contains(fn ($d) => $cibles[$d->id] === 'retenue') ? 'recue' : null);
        if ($statut !== null && $remise->statut !== $statut) {
            $avant = $remise->statut;
            $remise->forceFill(['statut' => $statut])->save();
            Journal::noter('remise', $remise->id, 'statut_remise', ['statut' => $avant], ['statut' => $statut]);
        }
    }

    /** Une déclaration saine n'a aucune anomalie propre (une divergence passée ne compte plus : le contenu est figé). */
    private function saine(Declaration $declaration): bool
    {
        return array_diff($declaration->anomalies ?? [], ['divergence']) === [];
    }

    /** @return list<array{0: int, 1: int, 2: int}> */
    private function contenu(Declaration $declaration): array
    {
        return $declaration->lignes->sortBy('materiel_id')
            ->map(fn (DeclarationLigne $l) => [(int) $l->materiel_id, (int) $l->recu, (int) $l->defectueux])->values()->all();
    }

    /** @return array{nom: string, qualite: string} */
    private function signataire(array $signataire): array
    {
        $retenu = [];
        foreach (['nom', 'qualite'] as $champ) {
            $valeur = $signataire[$champ] ?? null;
            $valeur = is_string($valeur) ? trim($valeur) : '';
            if ($valeur === '' || mb_strlen($valeur) > 150) {
                throw new InvalidArgumentException('Le nom et la qualité du signataire sont obligatoires (150 caractères au plus).');
            }
            $retenu[$champ] = $valeur;
        }

        return $retenu;
    }

    /**
     * Lignes normalisées (entiers, triées par article) ; les bornes sont vérifiées ici.
     *
     * @return list<array{materiel_id: int, recu: int, defectueux: int, observation: ?string}>
     */
    private function normaliser(array $lignes): array
    {
        $normalisees = [];
        foreach ($lignes as $materielId => $ligne) {
            if (! is_int($materielId) || ! is_array($ligne)) {
                throw new InvalidArgumentException('Chaque ligne de la remise doit être déclarée.');
            }
            $observation = $ligne['observation'] ?? null;
            if ($observation !== null && ! is_string($observation)) {
                throw new InvalidArgumentException("L'observation doit être un texte.");
            }
            $observation = $observation === null ? null : trim($observation);
            $observation = $observation === '' ? null : $observation;
            if ($observation !== null && mb_strlen($observation) > 500) {
                throw new InvalidArgumentException("L'observation est limitée à 500 caractères.");
            }

            $normalisees[] = [
                'materiel_id' => $materielId,
                'recu' => $this->quantite($ligne['recu'] ?? null),
                'defectueux' => $this->quantite($ligne['defectueux'] ?? null),
                'observation' => $observation,
            ];
        }
        usort($normalisees, fn (array $a, array $b) => $a['materiel_id'] <=> $b['materiel_id']);

        return $normalisees;
    }

    private function quantite(mixed $valeur): int
    {
        if (is_string($valeur) && preg_match('/^-?\d{1,12}$/', $valeur) === 1) {
            $valeur = (int) $valeur;
        }
        if (! is_int($valeur) || $valeur < 0 || $valeur > self::MAX_QUANTITE) {
            throw new InvalidArgumentException('Les quantités déclarées doivent être des entiers de 0 à 10 000 000.');
        }

        return $valeur;
    }

    /**
     * Empreinte du contenu : SHA-256 du JSON canonique {remise, mode, signataire, lignes triées par article}.
     *
     * @param  array{nom: string, qualite: string}  $signataire
     * @param  list<array{materiel_id: int, recu: int, defectueux: int, observation: ?string}>  $lignes
     */
    private static function empreinte(int $remiseId, string $mode, array $signataire, array $lignes): string
    {
        $contenu = ['remise_id' => $remiseId, 'mode' => $mode, 'signataire' => $signataire, 'lignes' => $lignes];

        return Canonique::empreinte($contenu);
    }

    private function exigerAuteur(): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Opération sans auteur.');
        }
    }
}

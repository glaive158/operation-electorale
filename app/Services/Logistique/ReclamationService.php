<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\BesoinRemplacement;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\DechargeLigne;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\Reclamation;
use App\Models\Logistique\Remise;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

/**
 * Réclamations sur une décharge partie : l'autorité de terrain signale un article manquant ou défectueux, le
 * personnel central la traite et la résout (remplacement, article retrouvé, erreur de déclaration, clôture sans
 * remplacement). Un remplacement autorisé crée un besoin et un brouillon de décharge de remplacement ; la
 * réclamation reste en traitement jusqu'à la réception utilisable de ce remplacement.
 *
 * Ouvrir, prendre en charge, « article retrouvé » et « erreur de déclaration » ne touchent à aucun stock central :
 * ils restent possibles après la clôture du cycle (comme les déclarations et leur arbitrage).
 *
 * Ordre des verrous : cycle, décharge, remise, déclaration, article, réservation ; la réclamation vient après la décharge.
 */
class ReclamationService
{
    private const MAX_TEXTE = 500;

    private const MAX_MESSAGE = 1000;

    private const HORS_PERIMETRE = 'Réclamation hors de votre périmètre.';

    public function __construct(
        private readonly PerimetreTerrain $perimetre,
        private readonly DechargeService $decharges,
        private readonly ArbitrageService $arbitrages,
        private readonly DeclarationService $declarations,
    ) {}

    public function ouvrir(Decharge $decharge, Materiel $materiel, string $motif, int $quantite, string $message): Reclamation
    {
        $this->exigerAuteur();
        $auteur = auth()->user();

        if (! array_key_exists($motif, Reclamation::MOTIFS)) {
            throw new InvalidArgumentException('Motif de réclamation inconnu.');
        }
        if ($quantite < 1) {
            throw new InvalidArgumentException('La quantité réclamée doit être au moins 1.');
        }
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > self::MAX_MESSAGE) {
            throw new InvalidArgumentException('Le message est obligatoire (1 000 caractères au plus).');
        }
        if (! Gate::forUser($auteur)->allows('logistique-terrain')) {
            throw new RegleLogistique(self::HORS_PERIMETRE);
        }

        return DB::transaction(function () use ($decharge, $materiel, $motif, $quantite, $message, $auteur) {
            $this->verrouillerCycle((int) $decharge->cycle_id);
            $actuelle = Decharge::whereKey($decharge->id)->lockForUpdate()->firstOrFail();
            if (! $this->perimetre->peutVoir($auteur, $actuelle)) {
                throw new RegleLogistique(self::HORS_PERIMETRE);
            }
            if (! $actuelle->estPartie()) {
                throw new RegleLogistique("La décharge n'est pas encore partie.");
            }

            $ligne = DechargeLigne::where('decharge_id', $actuelle->id)->where('materiel_id', $materiel->id)->first();
            if ($ligne === null) {
                throw new InvalidArgumentException('Cet article ne figure pas sur la décharge.');
            }
            if ($quantite > $ligne->quantite) {
                throw new InvalidArgumentException("La quantité réclamée ne peut pas dépasser celle de la décharge ({$ligne->quantite}).");
            }

            $reclamation = new Reclamation([
                'decharge_id' => $actuelle->id, 'materiel_id' => $materiel->id, 'motif' => $motif,
                'quantite' => $quantite, 'message' => $message, 'ouverte_par' => $auteur->id,
            ]);
            $reclamation->save();
            $this->recalculerStatutDecharge($actuelle->id);

            Journal::noter('reclamation', $reclamation->id, 'ouverture_reclamation', null, [
                'decharge_id' => $actuelle->id, 'materiel_id' => $materiel->id, 'motif' => $motif,
                'quantite' => $quantite, 'message' => $message,
            ]);

            return $reclamation;
        }, attempts: 3);
    }

    public function prendreEnCharge(Reclamation $reclamation): void
    {
        $this->exigerAuteur();
        $this->exigerDroitGerer();

        DB::transaction(function () use ($reclamation) {
            [$decharge, $actuelle] = $this->verrouiller($reclamation);
            if ($actuelle->statut !== 'ouverte') {
                throw new RegleLogistique('Seule une réclamation ouverte peut être prise en charge.');
            }

            $actuelle->forceFill(['statut' => 'en_traitement', 'traitee_par' => auth()->id()])->save();
            $this->recalculerStatutDecharge($decharge->id);

            Journal::noter('reclamation', $actuelle->id, 'prise_en_charge_reclamation', ['statut' => 'ouverte'], [
                'statut' => 'en_traitement', 'decharge_id' => $decharge->id,
            ]);
        }, attempts: 3);

        $reclamation->refresh();
    }

    /**
     * Résout une réclamation. Les issues « remplacement autorisé » et « clôture sans remplacement » relèvent du
     * responsable logistique ; les deux autres, du personnel logistique.
     */
    public function resoudre(
        Reclamation $reclamation,
        string $issue,
        string $motif,
        ?int $quantiteRemplacement = null,
        ?Declaration $declarationErronee = null,
    ): void {
        $this->exigerAuteur();
        if (! array_key_exists($issue, Reclamation::ISSUES)) {
            throw new InvalidArgumentException('Issue de réclamation inconnue.');
        }
        $motif = trim($motif);
        if ($motif === '' || mb_strlen($motif) > self::MAX_TEXTE) {
            throw new InvalidArgumentException('Le motif est obligatoire (500 caractères au plus).');
        }
        match ($issue) {
            'remplacement_autorise' => $this->exigerDroitAdministrer('Seul un responsable logistique peut autoriser un remplacement.'),
            'cloture_sans_remplacement' => $this->exigerDroitAdministrer('Seul un responsable logistique peut clôturer une réclamation sans remplacement.'),
            default => $this->exigerDroitGerer(),
        };
        if ($issue === 'remplacement_autorise' && $quantiteRemplacement === null) {
            throw new InvalidArgumentException('La quantité à remplacer est obligatoire.');
        }
        if ($issue === 'erreur_declaration' && $declarationErronee === null) {
            throw new InvalidArgumentException('La déclaration erronée est obligatoire.');
        }

        DB::transaction(function () use ($reclamation, $issue, $motif, $quantiteRemplacement, $declarationErronee) {
            [$decharge, $actuelle, $cycle] = $this->verrouiller($reclamation);
            if ($actuelle->statut === 'resolue') {
                throw new RegleLogistique('Cette réclamation est déjà résolue.');
            }
            if ($actuelle->issue === 'remplacement_autorise') {
                throw new RegleLogistique('Un remplacement est déjà autorisé pour cette réclamation.');
            }

            $avant = ['statut' => $actuelle->statut, 'issue' => $actuelle->issue];
            $apres = ['issue' => $issue, 'decharge_id' => $decharge->id, 'materiel_id' => $actuelle->materiel_id, 'quantite' => $actuelle->quantite];
            $statut = 'resolue';

            switch ($issue) {
                case 'remplacement_autorise':
                    if ($quantiteRemplacement < 1 || $quantiteRemplacement > $actuelle->quantite) {
                        throw new InvalidArgumentException("La quantité à remplacer doit être comprise entre 1 et {$actuelle->quantite}.");
                    }
                    $besoin = BesoinRemplacement::create([
                        'cycle_id' => $cycle->id, 'destinataire_id' => $decharge->destinataire_id,
                        'materiel_id' => $actuelle->materiel_id, 'quantite' => $quantiteRemplacement,
                        'motif' => $motif, 'autorise_par' => auth()->id(), 'reclamation_id' => $actuelle->id,
                    ]);
                    $brouillon = $this->decharges->preparerRemplacement($besoin);
                    $statut = 'en_traitement';
                    $apres += ['quantite_remplacement' => $quantiteRemplacement, 'besoin_remplacement_id' => $besoin->id, 'decharge_remplacement_id' => $brouillon->id];
                    break;

                case 'erreur_declaration':
                    $apres += ['declaration_id' => $declarationErronee->id];
                    $this->rouvrirLaRemise($decharge, $declarationErronee);
                    break;

                case 'cloture_sans_remplacement':
                    $arbitrage = $this->arbitrages->cloturerAvecEcart(
                        $cycle, Destinataire::findOrFail($decharge->destinataire_id), Materiel::findOrFail($actuelle->materiel_id),
                        (int) $actuelle->quantite, 0, $motif,
                    );
                    $apres += ['arbitrage_id' => $arbitrage->id];
                    break;

                default: // article_retrouve
                    break;
            }

            $actuelle->forceFill([
                'statut' => $statut, 'issue' => $issue, 'traitee_par' => auth()->id(),
                'motif_cloture' => $statut === 'resolue' ? $motif : null,
                'resolue_le' => $statut === 'resolue' ? now() : null,
            ])->save();
            $this->recalculerStatutDecharge($decharge->id);

            Journal::noter('reclamation', $actuelle->id, 'resolution_reclamation', $avant, ['statut' => $statut] + $apres, $motif);
        }, attempts: 3);

        $reclamation->refresh();
    }

    /**
     * Après une retenue sur la décharge d'un remplacement : si le destinataire final a retenu une réception
     * utilisable (reçu moins défectueux) au moins égale à la quantité du besoin, la réclamation d'origine est
     * résolue. N'écrit que la réclamation et le statut de sa décharge : l'appelant détient déjà le verrou du cycle.
     */
    public function verifierRemplacements(Decharge $decharge): void
    {
        if ($decharge->besoin_remplacement_id === null) {
            return;
        }
        $besoin = BesoinRemplacement::find($decharge->besoin_remplacement_id);
        $reclamation = $besoin?->reclamation_id === null ? null : Reclamation::find($besoin->reclamation_id);
        if ($reclamation === null || $reclamation->statut === 'resolue') {
            return;
        }

        $utilisable = (int) DB::table('declaration_lignes')
            ->join('declarations', 'declarations.id', '=', 'declaration_lignes.declaration_id')
            ->join('remises', 'remises.id', '=', 'declarations.remise_id')
            ->where('remises.decharge_id', $decharge->id)
            ->where('remises.a_type', 'destinataire')->where('remises.a_id', $decharge->destinataire_id)
            ->where('remises.statut', '!=', 'annulee')
            ->where('declarations.statut', 'retenue')
            ->where('declaration_lignes.materiel_id', $besoin->materiel_id)
            ->sum(DB::raw('declaration_lignes.recu - declaration_lignes.defectueux'));
        if ($utilisable < $besoin->quantite) {
            return;
        }

        $avant = ['statut' => $reclamation->statut, 'issue' => $reclamation->issue];
        $reclamation->forceFill([
            'statut' => 'resolue', 'resolue_le' => now(), 'motif_cloture' => 'Remplacement reçu en état d\'usage.',
        ])->save();
        $this->recalculerStatutDecharge((int) $reclamation->decharge_id);

        Journal::noter('reclamation', $reclamation->id, 'resolution_reclamation', $avant, [
            'statut' => 'resolue', 'issue' => $reclamation->issue, 'decharge_remplacement_id' => $decharge->id,
            'besoin_remplacement_id' => $besoin->id, 'recu_utilisable' => $utilisable,
        ], 'Remplacement reçu en état d\'usage.');
    }

    /**
     * La déclaration retenue est reconnue erronée : elle (et sa jumelle concordante, de même contenu) passe à
     * « remplacée », la remise attend une nouvelle déclaration et le statut d'acheminement est recalculé.
     */
    private function rouvrirLaRemise(Decharge $decharge, Declaration $declaration): void
    {
        $connue = Declaration::whereKey($declaration->id)->first();
        if ($connue === null || (int) $connue->decharge_id !== (int) $decharge->id) {
            throw new RegleLogistique('Cette déclaration ne concerne pas la décharge réclamée.');
        }

        $remise = Remise::whereKey($connue->remise_id)->lockForUpdate()->firstOrFail();
        if ((int) $remise->decharge_id !== (int) $decharge->id) {
            throw new RegleLogistique('Cette déclaration ne concerne pas la décharge réclamée.');
        }
        $actives = Declaration::where('remise_id', $remise->id)->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (Declaration $d) => $d->estActive());
        $actuelle = $actives->firstWhere('id', $connue->id);
        if ($actuelle === null || $actuelle->statut !== 'retenue') {
            throw new RegleLogistique('Seule une déclaration retenue peut être remplacée.');
        }

        foreach ($actives as $active) {
            $avant = $active->statut;
            $active->forceFill(['statut' => 'remplacee'])->save();
            Journal::noter('declaration', $active->id, 'statut_declaration', ['statut' => $avant], [
                'statut' => 'remplacee', 'remise_id' => $remise->id, 'anomalies' => $active->anomalies ?? [],
            ]);
        }

        $avant = $remise->statut;
        $remise->forceFill(['statut' => 'en_attente'])->save();
        Journal::noter('remise', $remise->id, 'statut_remise', ['statut' => $avant], ['statut' => 'en_attente']);

        $this->declarations->apresRetenue($remise->refresh());
    }

    /** Statut de la décharge : ouverte s'il en reste une, sinon en traitement, sinon résolue, sinon aucune. */
    private function recalculerStatutDecharge(int $dechargeId): void
    {
        $statuts = Reclamation::where('decharge_id', $dechargeId)->pluck('statut');
        $cible = match (true) {
            $statuts->contains('ouverte') => 'ouverte',
            $statuts->contains('en_traitement') => 'en_traitement',
            $statuts->isNotEmpty() => 'resolue',
            default => 'aucune',
        };

        Decharge::whereKey($dechargeId)->where('statut_reclamation', '!=', $cible)->update(['statut_reclamation' => $cible]);
    }

    /**
     * Verrouille le cycle (sans exiger qu'il soit ouvert), la décharge puis la réclamation.
     *
     * @return array{0: Decharge, 1: Reclamation, 2: Cycle}
     */
    private function verrouiller(Reclamation $reclamation): array
    {
        $dechargeId = Reclamation::whereKey($reclamation->id)->value('decharge_id');
        if ($dechargeId === null) {
            throw new LogicException('Réclamation introuvable : invariant rompu.');
        }
        $cycleId = Decharge::whereKey($dechargeId)->value('cycle_id');
        $cycle = $this->verrouillerCycle((int) $cycleId);
        $decharge = Decharge::whereKey($dechargeId)->lockForUpdate()->firstOrFail();
        $actuelle = Reclamation::whereKey($reclamation->id)->lockForUpdate()->firstOrFail();

        return [$decharge, $actuelle, $cycle];
    }

    private function verrouillerCycle(int $cycleId): Cycle
    {
        $cycle = Cycle::whereKey($cycleId)->lockForUpdate()->first();
        if ($cycle === null) {
            throw new LogicException('Cycle introuvable : invariant rompu.');
        }

        return $cycle;
    }

    private function exigerDroitGerer(): void
    {
        if (! Gate::allows('logistique-gerer')) {
            throw new RegleLogistique('Seul le personnel logistique peut traiter une réclamation.');
        }
    }

    private function exigerDroitAdministrer(string $message): void
    {
        if (! Gate::allows('logistique-administrer')) {
            throw new RegleLogistique($message);
        }
    }

    private function exigerAuteur(): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Opération sans auteur.');
        }
    }
}

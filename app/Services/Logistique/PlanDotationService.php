<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\CleRepartition;
use App\Models\Logistique\CorrespondanceGeo;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\EntiteBulletin;
use App\Models\Logistique\InstantaneEffectifs;
use App\Models\Logistique\InstantaneLigne;
use App\Models\Logistique\PerimetreDestinataire;
use App\Models\Logistique\PlanDotation;
use App\Models\Logistique\PlanDotationLigne;
use App\Services\Logistique\Moteur\LocaliteCalcul;
use App\Services\Logistique\Moteur\MoteurDotation;
use App\Services\Logistique\Moteur\ParametresCle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

/**
 * Plan de dotation versionné d'un cycle : le moteur est appliqué à un instantané figé, les écarts
 * sont signalés, les corrections motivées, puis le responsable approuve une version.
 *
 * Ordre des verrous : cycle, puis plan, puis ligne.
 */
class PlanDotationService
{
    private const LOT = 500;

    private const MAX_TEXTE = 500;

    private const MAX_ENTIER = 4294967295;

    /** Colonne de l'instantané lue par chaque base ; les bases forfaitaires n'en lisent aucune. */
    private const COLONNES = [
        'electeur'    => 'electeurs',
        'bureau_vote' => 'bureaux_vote',
        'lieu_vote'   => 'lieux_vote',
        'ca'          => 'commissions',
    ];

    public function __construct(private readonly MoteurDotation $moteur)
    {
    }

    /** Calcule la version suivante du plan en brouillon ; les brouillons précédents sont remplacés. */
    public function calculer(Cycle $cycle, InstantaneEffectifs $instantane): PlanDotation
    {
        $this->exigerAuteur();
        $this->exigerDroit('logistique-gerer', 'Seul le personnel logistique peut calculer un plan de dotation.');

        return DB::transaction(function () use ($cycle, $instantane) {
            $actuel = $this->verrouillerCycleOuvert($cycle->id);

            $source = InstantaneEffectifs::whereKey($instantane->id)->first();
            if ($source === null || (int) $source->cycle_id !== (int) $actuel->id) {
                throw new RegleLogistique("Cet instantané n'appartient pas au cycle.");
            }
            if (! $source->estUtilisable()) {
                throw new RegleLogistique('Instantané non figé : calcul impossible.');
            }

            $cles = CleRepartition::where('cycle_id', $actuel->id)
                ->whereHas('materiel', fn ($q) => $q->where('actif', true))
                ->with('materiel')->orderBy('materiel_id')->get();
            if ($cles->isEmpty()) {
                throw new RegleLogistique('Aucune clé de répartition définie pour ce cycle : calcul impossible.');
            }
            // Une clé portée par le bulletin d'un autre cycle (donnée ancienne) n'est jamais calculée
            // comme un article ordinaire : elle est écartée et signalée.
            $bulletinsAutres = EntiteBulletin::whereIn('materiel_id', $cles->pluck('materiel_id'))
                ->where('cycle_id', '!=', $actuel->id)->pluck('materiel_id')->flip();
            $clesIgnorees = $cles->filter(fn (CleRepartition $cle) => $bulletinsAutres->has($cle->materiel_id))
                ->map(fn (CleRepartition $cle) => "Clé ignorée : bulletin d'un autre cycle ({$cle->materiel->libelle})")
                ->values()->all();
            $cles = $cles->reject(fn (CleRepartition $cle) => $bulletinsAutres->has($cle->materiel_id))->values();
            // Le cycle est lu une fois : les valeurs par défaut ne coûtent pas une requête par clé.
            $cles->each(fn (CleRepartition $cle) => $cle->setRelation('cycle', $actuel));
            $entites = EntiteBulletin::where('cycle_id', $actuel->id)
                ->whereHas('materiel', fn ($q) => $q->where('actif', true))
                ->with('perimetres')->get()->keyBy('materiel_id');

            [$parametres, $objets] = $this->parametres($cles, $entites);
            [$localites, $anomaliesPlan] = $this->localites($actuel, $source, $entites);
            $anomaliesPlan = array_merge($anomaliesPlan, $clesIgnorees);

            $precedent = PlanDotation::where('cycle_id', $actuel->id)->orderByDesc('version')->first();
            $corrections = $precedent === null ? collect() : PlanDotationLigne::where('plan_dotation_id', $precedent->id)
                ->whereNotNull('correction')->get()->keyBy(fn ($l) => "{$l->destinataire_id}:{$l->materiel_id}");

            $lignes = [];
            foreach ($localites['parDestinataire'] as $destinataireId => $locs) {
                $entitesD = array_values(array_unique(array_merge(...array_column($locs, 'entites'))));
                foreach ($cles as $cle) {
                    $entite = $entites->get($cle->materiel_id);
                    if ($entite !== null && ! in_array($entite->id, $entitesD, true)) {
                        continue;
                    }
                    $lignes[] = $this->ligne($objets[$cle->materiel_id], $parametres[$cle->materiel_id], $cle->base, $entite, $locs, count($entitesD))
                        + ['destinataire_id' => $destinataireId, 'materiel_id' => $cle->materiel_id];
                }
            }

            PlanDotation::where('cycle_id', $actuel->id)->where('statut', 'brouillon')->update(['statut' => 'remplace']);
            $plan = PlanDotation::create([
                'cycle_id'      => $actuel->id,
                'version'       => ($precedent?->version ?? 0) + 1,
                'instantane_id' => $source->id,
                'parametres'    => $parametres,
                'calcule_par'   => auth()->id(),
                'anomalies'     => $anomaliesPlan,
            ]);

            $reprises = 0;
            $horodatage = now();
            foreach (array_chunk($lignes, self::LOT) as $lot) {
                PlanDotationLigne::insert(array_map(function (array $l) use ($plan, $corrections, $horodatage, &$reprises) {
                    $ancienne = $corrections->get("{$l['destinataire_id']}:{$l['materiel_id']}");
                    $reprises += $ancienne === null ? 0 : 1;

                    return $l + [
                        'plan_dotation_id'   => $plan->id,
                        'correction'         => $ancienne?->correction,
                        'correction_motif'   => $ancienne?->correction_motif,
                        'corrige_par'        => $ancienne?->corrige_par,
                        'corrige_le'         => $ancienne?->getRawOriginal('corrige_le'),
                        // Calcul sur lequel la correction a été saisie, conservé de version en version.
                        'correction_calcul_source' => $ancienne === null ? null
                            : ($ancienne->correction_calcul_source ?? $ancienne->dotation_calculee),
                        'dotation_approuvee' => null,
                        'created_at'         => $horodatage,
                        'updated_at'         => $horodatage,
                    ];
                }, $lot));
            }

            Journal::noter('plan_dotation', $plan->id, 'calcul_plan', null, [
                'cycle_id'             => $actuel->id,
                'version'              => $plan->version,
                'instantane_id'        => $source->id,
                'lignes'               => count($lignes),
                'anomalies_lignes'     => count(array_filter($lignes, fn ($l) => $l['anomalie'] !== null)),
                'anomalies_plan'       => count($anomaliesPlan),
                'corrections_reprises' => $reprises,
            ]);

            return $plan;
        }, attempts: 3);
    }

    /** Fixe la quantité d'une ligne du brouillon, avec son motif ; le calcul reste visible à côté. */
    public function corriger(PlanDotationLigne $ligne, int $valeur, string $motif): void
    {
        $this->exigerAuteur();
        $this->exigerDroit('logistique-administrer', 'Seul un responsable logistique peut corriger un plan de dotation.');

        $motif = trim($motif);
        if ($motif === '' || mb_strlen($motif) > self::MAX_TEXTE) {
            throw new InvalidArgumentException('Le motif de la correction est obligatoire (500 caractères au plus).');
        }
        if ($valeur < 0 || $valeur > self::MAX_ENTIER) {
            throw new InvalidArgumentException('La quantité corrigée doit être un entier positif ou nul.');
        }

        DB::transaction(function () use ($ligne, $valeur, $motif) {
            $cycleId = PlanDotation::whereKey($ligne->plan_dotation_id)->value('cycle_id');
            if ($cycleId === null) {
                throw new RegleLogistique('Plan de dotation introuvable.');
            }
            $this->verrouillerCycleOuvert((int) $cycleId);

            $plan = PlanDotation::whereKey($ligne->plan_dotation_id)->lockForUpdate()->firstOrFail();
            if ($plan->statut !== 'brouillon') {
                throw new RegleLogistique('Seul un plan brouillon peut être corrigé.');
            }

            $actuelle = PlanDotationLigne::whereKey($ligne->id)->lockForUpdate()->firstOrFail();
            $avant = [
                'correction' => $actuelle->correction, 'correction_motif' => $actuelle->correction_motif,
                'dotation_calculee' => $actuelle->dotation_calculee,
            ];
            $actuelle->forceFill([
                'correction' => $valeur, 'correction_motif' => $motif, 'corrige_par' => auth()->id(), 'corrige_le' => now(),
                'correction_calcul_source' => null,
            ])->save();

            Journal::noter('plan_dotation_ligne', $actuelle->id, 'correction_dotation', $avant, [
                'correction' => $valeur, 'plan_dotation_id' => $plan->id, 'version' => $plan->version,
                'destinataire_id' => $actuelle->destinataire_id, 'materiel_id' => $actuelle->materiel_id,
            ], $motif);
        }, attempts: 3);

        $ligne->refresh();
    }

    /** Retire la correction d'une ligne du brouillon : la dotation calculée s'applique de nouveau. */
    public function annulerCorrection(PlanDotationLigne $ligne, string $motif): void
    {
        $this->exigerAuteur();
        $this->exigerDroit('logistique-administrer', 'Seul un responsable logistique peut annuler une correction.');

        $motif = trim($motif);
        if ($motif === '' || mb_strlen($motif) > self::MAX_TEXTE) {
            throw new InvalidArgumentException('Le motif de l\'annulation est obligatoire (500 caractères au plus).');
        }

        DB::transaction(function () use ($ligne, $motif) {
            $cycleId = PlanDotation::whereKey($ligne->plan_dotation_id)->value('cycle_id');
            if ($cycleId === null) {
                throw new RegleLogistique('Plan de dotation introuvable.');
            }
            $this->verrouillerCycleOuvert((int) $cycleId);

            $plan = PlanDotation::whereKey($ligne->plan_dotation_id)->lockForUpdate()->firstOrFail();
            if ($plan->statut !== 'brouillon') {
                throw new RegleLogistique('Seul un plan brouillon peut être corrigé.');
            }

            $actuelle = PlanDotationLigne::whereKey($ligne->id)->lockForUpdate()->firstOrFail();
            if ($actuelle->correction === null) {
                throw new RegleLogistique('Cette ligne n\'a pas de correction à annuler.');
            }
            $avant = [
                'correction' => $actuelle->correction, 'correction_motif' => $actuelle->correction_motif,
                'corrige_par' => $actuelle->corrige_par, 'corrige_le' => $actuelle->getRawOriginal('corrige_le'),
                'correction_calcul_source' => $actuelle->correction_calcul_source,
            ];
            $actuelle->forceFill([
                'correction' => null, 'correction_motif' => null, 'corrige_par' => null, 'corrige_le' => null,
                'correction_calcul_source' => null,
            ])->save();

            Journal::noter('plan_dotation_ligne', $actuelle->id, 'annulation_correction', $avant, [
                'correction' => null, 'dotation_calculee' => $actuelle->dotation_calculee,
                'plan_dotation_id' => $plan->id, 'version' => $plan->version,
                'destinataire_id' => $actuelle->destinataire_id, 'materiel_id' => $actuelle->materiel_id,
            ], $motif);
        }, attempts: 3);

        $ligne->refresh();
    }

    /** Un brouillon sans anomalie devient le plan en vigueur ; l'ancien plan approuvé est remplacé. */
    public function approuver(PlanDotation $plan): void
    {
        $this->exigerAuteur();
        $this->exigerDroit('logistique-administrer', 'Seul un responsable logistique peut approuver un plan de dotation.');

        DB::transaction(function () use ($plan) {
            $this->verrouillerCycleOuvert((int) $plan->cycle_id);

            $actuel = PlanDotation::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if ($actuel->statut !== 'brouillon') {
                throw new RegleLogistique('Seul un plan brouillon peut être approuvé.');
            }

            $anomalies = count($actuel->anomalies ?? [])
                + PlanDotationLigne::where('plan_dotation_id', $actuel->id)->whereNotNull('anomalie')->count();
            if ($anomalies > 0) {
                throw new RegleLogistique("Plan non approuvable : {$anomalies} anomalie(s).");
            }

            PlanDotationLigne::where('plan_dotation_id', $actuel->id)
                ->update(['dotation_approuvee' => DB::raw('COALESCE(correction, dotation_calculee)')]);
            $remplaces = PlanDotation::where('cycle_id', $actuel->cycle_id)->where('statut', 'approuve')->pluck('id')->all();
            PlanDotation::whereIn('id', $remplaces)->update(['statut' => 'remplace']);
            $actuel->forceFill(['statut' => 'approuve', 'approuve_par' => auth()->id(), 'approuve_le' => now()])->save();

            Journal::noter('plan_dotation', $actuel->id, 'approbation_plan', ['statut' => 'brouillon'], [
                'statut' => 'approuve', 'cycle_id' => $actuel->cycle_id, 'version' => $actuel->version,
                'plans_remplaces' => $remplaces,
            ]);
        }, attempts: 3);

        $plan->refresh();
    }

    /**
     * Paramètres effectifs de chaque clé : l'objet pour le moteur et la copie figée dans le plan.
     *
     * @param  Collection<int, CleRepartition>  $cles
     * @param  Collection<int, EntiteBulletin>  $entites  par article
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, ParametresCle>}
     */
    private function parametres(Collection $cles, Collection $entites): array
    {
        $figes = [];
        $objets = [];
        foreach ($cles as $cle) {
            $conditionnement = $cle->arrondi_conditionnement ? (int) $cle->materiel->facteur_conditionnement : null;
            $objets[$cle->materiel_id] = new ParametresCle(
                $cle->base, (string) $cle->ratio, $cle->majorationEffective(), $cle->minimum, $cle->minimum_par_commune,
                $cle->arrondi, $conditionnement, $cle->par_entite, $entites->has($cle->materiel_id),
            );
            $figes[$cle->materiel_id] = [
                'base' => $cle->base, 'ratio' => (string) $cle->ratio, 'majoration' => $cle->majorationEffective(),
                'reserve' => $cle->reserveEffective(), 'minimum' => $cle->minimum,
                'minimum_par_commune' => $cle->minimum_par_commune, 'arrondi' => $cle->arrondi,
                'conditionnement' => $conditionnement, 'par_entite' => $cle->par_entite,
                'bulletin' => $entites->has($cle->materiel_id), 'cle_affichee' => $cle->cle_affichee,
            ];
        }

        return [$figes, $objets];
    }

    /**
     * Localités de chaque destinataire (périmètre relu au calcul) rapprochées de l'instantané,
     * et anomalies du plan. Aucune requête par ligne : tout est chargé une fois.
     *
     * @param  Collection<int, EntiteBulletin>  $entites
     * @return array{0: array{parDestinataire: array<int, list<array<string, mixed>>>}, 1: list<string>}
     */
    private function localites(Cycle $cycle, InstantaneEffectifs $source, Collection $entites): array
    {
        $parCle = [];
        $nonRapproches = 0;
        foreach (InstantaneLigne::where('instantane_id', $source->id)->orderBy('id')->get() as $ligne) {
            if (! $ligne->rapprochee) {
                $nonRapproches += $ligne->electeurs;

                continue;
            }
            $parCle[$ligne->commune_id !== null
                ? "commune:{$ligne->commune_id}"
                : NomGeo::cleEtranger((string) $ligne->pays, (string) $ligne->ville)] = $ligne;
        }

        $perimetres = PerimetreDestinataire::where('cycle_id', $cycle->id)->orderBy('id')->get();
        $absentes = $perimetres->filter(fn ($p) => $p->commune_id !== null && ! isset($parCle[$p->localite()]))
            ->pluck('commune_id')->all();
        $noms = $absentes === [] ? [] : CorrespondanceGeo::where('statut', 'validee')
            ->whereIn('commune_id', $absentes)->orderBy('id')->pluck('libelle_commune', 'commune_id')->all();

        $parDestinataire = [];
        $dansPerimetre = [];
        foreach ($perimetres as $p) {
            $ligne = $parCle[$p->localite()] ?? null;
            $dansPerimetre[$p->localite()] = true;
            $departementId = $ligne?->departement_id;
            $parDestinataire[$p->destinataire_id][] = [
                'ligne'   => $ligne,
                'libelle' => $p->commune_id !== null
                    ? ($ligne?->libelle_commune ?? $noms[$p->commune_id] ?? "commune n° {$p->commune_id}")
                    : "{$p->ville} ({$p->pays})",
                'entites' => $entites->filter(fn (EntiteBulletin $e) => $e->couvre($departementId, $p->commune_id, $p->pays))
                    ->pluck('id')->values()->all(),
            ];
        }

        $anomalies = [];
        $nonRapproches = (int) ($source->bilan['electeurs_non_rapproches'] ?? $nonRapproches);
        if ($nonRapproches > 0) {
            $anomalies[] = "{$nonRapproches} électeurs non rapprochés";
        }
        foreach ($parCle as $cle => $ligne) {
            if (! isset($dansPerimetre[$cle])) {
                $libelle = $ligne->commune_id !== null
                    ? ($ligne->libelle_commune ?? "commune n° {$ligne->commune_id}")
                    : "{$ligne->ville} ({$ligne->pays})";
                $anomalies[] = "{$libelle} sans destinataire";
            }
        }

        return [['parDestinataire' => $parDestinataire], $anomalies];
    }

    /**
     * Une ligne du plan : (destinataire, article). Une localité dont le comptage est lu par la clé et
     * manque à l'instantané met la ligne en anomalie, sans dotation.
     *
     * @param  list<array<string, mixed>>  $locs
     * @param  array<string, mixed>  $fige
     * @return array<string, mixed>
     */
    private function ligne(ParametresCle $p, array $fige, string $base, ?EntiteBulletin $entite, array $locs, int $entitesD): array
    {
        $colonne = self::COLONNES[$base] ?? null;
        $calculs = [];
        $anomalies = [];

        foreach ($locs as $loc) {
            $couverte = $entite === null || in_array($entite->id, $loc['entites'], true);
            $effectif = 0;
            if ($colonne !== null && $couverte) {
                if ($loc['ligne'] === null) {
                    $anomalies[] = "Effectif absent pour {$loc['libelle']}";
                } else {
                    $effectif = (int) $loc['ligne']->{$colonne};
                    if ($base === 'ca' && $effectif === 0) {
                        $anomalies[] = "Aucune commission administrative pour {$loc['libelle']}";
                    }
                }
            }
            $calculs[] = new LocaliteCalcul($effectif, count($loc['entites']), $couverte);
        }

        $resultat = $this->moteur->doter($p, $calculs, $entitesD);
        if ($resultat->dotation > self::MAX_ENTIER) {
            $anomalies[] = 'Dotation hors limite';
        }

        return [
            'effectif'          => $resultat->unites,
            'exact'             => $resultat->exact,
            'majoration'        => $fige['majoration'],
            'minimum'           => $fige['minimum'],
            'arrondi'           => $fige['arrondi'],
            'dotation_calculee' => $anomalies === [] ? $resultat->dotation : null,
            'anomalie'          => $anomalies === [] ? null : mb_substr(implode(' ; ', array_unique($anomalies)), 0, self::MAX_TEXTE),
        ];
    }

    private function verrouillerCycleOuvert(int $cycleId): Cycle
    {
        $actuel = Cycle::whereKey($cycleId)->lockForUpdate()->first();
        if ($actuel === null || ! $actuel->estOuvert()) {
            throw new RegleLogistique('Cycle clôturé : plan de dotation non modifiable.');
        }

        return $actuel;
    }

    private function exigerDroit(string $droit, string $message): void
    {
        if (! Gate::allows($droit)) {
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

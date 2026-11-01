<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Jobs\Logistique\CapturerInstantane;
use App\Models\Logistique\CommissionAdministrative;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\InstantaneEffectifs;
use App\Models\Logistique\InstantaneLigne;
use App\Models\Logistique\JournalEntree;
use App\Models\Logistique\PerimetreDestinataire;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Copie versionnée des effectifs par localité, prise dans le fichier électoral (lecture seule).
 * Les calculs de dotation lisent cette copie, jamais la base du recensement.
 */
class InstantaneService
{
    private const LOT = 500;

    public const ETRANGER = 'Étranger';

    public function __construct(
        private readonly LectureRecensement $recensement,
        private readonly CorrespondanceService $correspondances,
    ) {
    }

    /** Crée la version suivante en « en_cours » et lance la capture. */
    public function demarrer(Cycle $cycle): InstantaneEffectifs
    {
        $this->exigerAuteur();

        $instantane = DB::transaction(function () use ($cycle) {
            $this->verrouillerCycleOuvert($cycle, 'Cycle clôturé : capture impossible.');

            if (InstantaneEffectifs::where('cycle_id', $cycle->id)->where('statut', 'en_cours')->exists()) {
                throw new RegleLogistique('Une capture est déjà en cours pour ce cycle.');
            }

            $version = (int) InstantaneEffectifs::where('cycle_id', $cycle->id)->max('version') + 1;
            $instantane = InstantaneEffectifs::create([
                'cycle_id' => $cycle->id,
                'version'  => $version,
                'user_id'  => auth()->id(),
            ]);

            Journal::noter('instantane_effectifs', $instantane->id, 'demarrage_instantane', null, [
                'cycle_id' => $cycle->id, 'version' => $version,
            ]);

            return $instantane;
        }, attempts: 3);

        try {
            CapturerInstantane::dispatch($instantane->id);
        } catch (Throwable $e) {
            // File indisponible : l'instantané ne doit pas rester « en cours » et bloquer le cycle.
            $this->marquerEchec($instantane, $e);
            throw $e;
        }

        return $instantane->refresh();
    }

    /**
     * Appelée par le job. Les lectures lourdes se font hors transaction ; les lignes sont écrites
     * d'un bloc. Un échec marque l'instantané « echec » (hors de la transaction annulée) sans ligne.
     */
    public function capturer(InstantaneEffectifs $instantane): void
    {
        if (InstantaneEffectifs::whereKey($instantane->id)->value('statut') !== 'en_cours') {
            throw new RegleLogistique('Seul un instantané en cours peut être capturé.');
        }

        try {
            $cycle = Cycle::findOrFail($instantane->cycle_id);
            [$lignes, $bilan] = $this->construire($cycle);

            DB::transaction(function () use ($instantane, $lignes, $bilan) {
                $this->verrouillerCycleOuvert($instantane->cycle()->firstOrFail(), 'Cycle clôturé : capture impossible.');

                $actuel = InstantaneEffectifs::whereKey($instantane->id)->lockForUpdate()->firstOrFail();
                if ($actuel->statut !== 'en_cours') {
                    throw new RegleLogistique('Seul un instantané en cours peut être capturé.');
                }

                $maintenant = now();
                foreach (array_chunk($lignes, self::LOT) as $lot) {
                    InstantaneLigne::insert(array_map(
                        fn (array $l) => $l + ['instantane_id' => $actuel->id, 'created_at' => $maintenant, 'updated_at' => $maintenant],
                        $lot,
                    ));
                }

                $actuel->forceFill(['statut' => 'brouillon', 'bilan' => $bilan, 'erreur' => null])->save();

                JournalEntree::create([
                    'user_id'    => $actuel->user_id,
                    'objet_type' => 'instantane_effectifs',
                    'objet_id'   => $actuel->id,
                    'action'     => 'capture_instantane',
                    'apres'      => [
                        'lignes'                   => count($lignes),
                        'electeurs_total'          => $bilan['electeurs_total'],
                        'electeurs_non_rapproches' => $bilan['electeurs_non_rapproches'],
                    ],
                ]);
            }, attempts: 3);
        } catch (Throwable $e) {
            report($e);
            $this->marquerEchec($instantane, $e);

            return;
        }

        $instantane->refresh();
    }

    /** Un brouillon devient utilisable par les calculs. */
    public function figer(InstantaneEffectifs $instantane): void
    {
        $this->exigerAuteur();

        DB::transaction(function () use ($instantane) {
            $this->verrouillerCycleOuvert($instantane->cycle()->firstOrFail(), 'Cycle clôturé : instantané non modifiable.');

            $actuel = InstantaneEffectifs::whereKey($instantane->id)->lockForUpdate()->firstOrFail();
            if ($actuel->statut !== 'brouillon') {
                throw new RegleLogistique('Seul un instantané brouillon peut être figé.');
            }

            $actuel->forceFill(['statut' => 'fige', 'fige_par' => auth()->id(), 'fige_le' => now()])->save();

            Journal::noter('instantane_effectifs', $actuel->id, 'figement_instantane', ['statut' => 'brouillon'], [
                'statut' => 'fige', 'cycle_id' => $actuel->cycle_id, 'version' => $actuel->version,
            ]);
        }, attempts: 3);

        $instantane->refresh();
    }

    /**
     * Lit le fichier électoral et prépare lignes et bilan, sans rien écrire.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function construire(Cycle $cycle): array
    {
        $national = $this->recensement->agregatsNational();
        $etranger = $this->recensement->agregatsEtranger();
        $controles = $this->recensement->controles();
        $totalFichier = $this->recensement->totalFichier();

        $communes = $this->recensement->communes()->keyBy('id');
        $resoudre = $this->correspondances->resolveurValidees();
        $commissions = CommissionAdministrative::compterParLocalite($cycle);
        $destinataires = PerimetreDestinataire::where('cycle_id', $cycle->id)->get()
            ->mapWithKeys(fn ($p) => [$p->localite() => (int) $p->destinataire_id])->all();

        // Bureaux et lieux sont comptés distincts après fusion des graphies : le même code peut
        // apparaître sous deux graphies d'une commune. Les codes sont uniques au niveau national.
        $parCommune = [];
        $parTriplet = [];
        foreach ($national as $a) {
            $communeId = $resoudre($a->region, $a->departement, $a->commune);
            $commune = $communeId !== null ? $communes->get($communeId) : null;

            if ($commune === null) {
                // Jamais d'électeur écarté : sans correspondance validée, le triplet reste visible au bilan.
                $cle = $this->correspondances->cleTriplet($a->region, $a->departement, $a->commune);
                $parTriplet[$cle] ??= $this->cumul() + ['region' => $a->region, 'departement' => $a->departement, 'commune' => $a->commune];
                $this->ajouter($parTriplet[$cle], $a);

                continue;
            }

            $parCommune[$commune->id] ??= $this->cumul();
            $this->ajouter($parCommune[$commune->id], $a);
        }
        ksort($parCommune);

        // Les graphies d'une même ville (casse, espaces, accents) forment une seule localité,
        // affichée sous la première graphie rencontrée.
        $parVille = [];
        foreach ($etranger as $e) {
            $cle = NomGeo::cleEtranger($e->pays, $e->ville);
            $parVille[$cle] ??= $this->cumul() + ['pays' => $e->pays, 'ville' => $e->ville];
            $this->ajouter($parVille[$cle], $e);
        }

        // Une région n'apparaît que sous une clé : nom normalisé, affiché comme au recensement quand il est connu.
        $affichageRegion = [];
        foreach ($communes as $commune) {
            if ($commune->region_nom !== null) {
                $affichageRegion[NomGeo::normaliser($commune->region_nom)] ??= $commune->region_nom;
            }
        }
        $totauxNormalises = [];
        $ajouterRegion = function (string $libelle, int $electeurs) use (&$totauxNormalises, &$affichageRegion): void {
            $cle = NomGeo::normaliser($libelle);
            $affichageRegion[$cle] ??= $libelle;
            $totauxNormalises[$cle] = ($totauxNormalises[$cle] ?? 0) + $electeurs;
        };

        $lignes = [];
        $sansDestinataire = [];
        $electeursRapproches = 0;

        foreach ($parCommune as $communeId => $c) {
            $commune = $communes->get($communeId);
            $localite = "commune:{$communeId}";
            $destinataireId = $destinataires[$localite] ?? null;
            if ($destinataireId === null) {
                $sansDestinataire[] = "{$commune->nom} ({$commune->departement_nom})";
            }

            $lignes[] = [
                'commune_id' => $communeId, 'pays' => null, 'ville' => null, 'departement_id' => $commune->departement_id,
                'contexte_region' => $commune->region_nom, 'contexte_departement' => $commune->departement_nom, 'libelle_commune' => $commune->nom,
                'electeurs' => $c['electeurs'], 'bureaux_vote' => count($c['codes']), 'lieux_vote' => count($c['prefixes']),
                'commissions' => $commissions[$localite] ?? 0, 'rapprochee' => true, 'destinataire_id' => $destinataireId,
            ];
            $electeursRapproches += $c['electeurs'];
            $ajouterRegion($commune->region_nom ?? '(région inconnue)', $c['electeurs']);
        }

        $electeursEtranger = 0;
        foreach ($parVille as $localite => $v) {
            $destinataireId = $destinataires[$localite] ?? null;
            if ($destinataireId === null) {
                $sansDestinataire[] = "{$v['pays']} | {$v['ville']}";
            }

            $lignes[] = [
                'commune_id' => null, 'pays' => $v['pays'], 'ville' => $v['ville'], 'departement_id' => null,
                'contexte_region' => null, 'contexte_departement' => null, 'libelle_commune' => null,
                'electeurs' => $v['electeurs'], 'bureaux_vote' => count($v['codes']), 'lieux_vote' => count($v['prefixes']),
                'commissions' => $commissions[$localite] ?? 0, 'rapprochee' => true, 'destinataire_id' => $destinataireId,
            ];
            $electeursEtranger += $v['electeurs'];
        }

        $electeursNonRapproches = 0;
        $libellesNonRapproches = [];
        $nonRapprochees = [];
        foreach ($parTriplet as $t) {
            $nonRapprochees[] = [
                'commune_id' => null, 'pays' => null, 'ville' => null, 'departement_id' => null,
                'contexte_region' => $t['region'], 'contexte_departement' => $t['departement'], 'libelle_commune' => $t['commune'],
                'electeurs' => $t['electeurs'], 'bureaux_vote' => count($t['codes']), 'lieux_vote' => count($t['prefixes']),
                'commissions' => 0, 'rapprochee' => false, 'destinataire_id' => null,
            ];
            $electeursNonRapproches += $t['electeurs'];
            $libellesNonRapproches[] = "{$t['region']} / {$t['departement']} / {$t['commune']}";
            $ajouterRegion($t['region'], $t['electeurs']);
        }

        $totauxParRegion = [];
        foreach ($totauxNormalises as $cle => $electeurs) {
            $totauxParRegion[$affichageRegion[$cle]] = $electeurs;
        }
        if ($electeursEtranger > 0) {
            $totauxParRegion[self::ETRANGER] = ($totauxParRegion[self::ETRANGER] ?? 0) + $electeursEtranger;
        }
        ksort($totauxParRegion);

        $electeursNational = $electeursRapproches + $electeursNonRapproches;

        return [
            array_merge($lignes, $nonRapprochees),
            [
                'electeurs_total'             => $electeursNational + $electeursEtranger,
                'electeurs_national'          => $electeursNational,
                'electeurs_etranger'          => $electeursEtranger,
                'electeurs_rapproches'        => $electeursRapproches,
                'electeurs_non_rapproches'    => $electeursNonRapproches,
                // Lignes du fichier sans géographie exploitable ou d'une autre origine : montrées, jamais comptées.
                'electeurs_fichier'           => $totalFichier,
                'electeurs_ignores'           => $totalFichier - $electeursNational - $electeursEtranger,
                'lignes_non_rapprochees'      => $libellesNonRapproches,
                'localites_sans_destinataire' => $sansDestinataire,
                'totaux_par_region'           => $totauxParRegion,
                'controles'                   => $controles,
            ],
        ];
    }

    /** @return array{electeurs:int, codes:array<string,true>, prefixes:array<string,true>} */
    private function cumul(): array
    {
        return ['electeurs' => 0, 'codes' => [], 'prefixes' => []];
    }

    /** @param array{electeurs:int, codes:array<string,true>, prefixes:array<string,true>} $cumul */
    private function ajouter(array &$cumul, object $agregat): void
    {
        $cumul['electeurs'] += $agregat->electeurs;
        // Un code absent n'est pas un bureau (comme COUNT(DISTINCT) en SQL).
        if ($agregat->code_bureau !== null) {
            $cumul['codes'][(string) $agregat->code_bureau] = true;
            $cumul['prefixes'][mb_substr((string) $agregat->code_bureau, 0, 8)] = true;
        }
    }

    /** Hors de toute transaction applicative : le statut « echec » doit survivre à l'annulation. */
    private function marquerEchec(InstantaneEffectifs $instantane, Throwable $e): void
    {
        InstantaneEffectifs::whereKey($instantane->id)
            ->where('statut', 'en_cours')
            ->update(['statut' => 'echec', 'erreur' => mb_substr($e->getMessage() ?: $e::class, 0, 1000)]);

        $instantane->refresh();
    }

    /** À appeler dans une transaction : verrouille le cycle et refuse s'il est clos. */
    private function verrouillerCycleOuvert(Cycle $cycle, string $message): void
    {
        $actuel = Cycle::whereKey($cycle->id)->lockForUpdate()->first();
        if ($actuel === null || ! $actuel->estOuvert()) {
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

<?php

namespace App\Services\Logistique;

use App\Models\Logistique\CorrespondanceGeo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Rapproche les communes écrites dans le fichier électoral des communes du recensement :
 * automatique quand un seul candidat existe, validation humaine sinon.
 */
class CorrespondanceService
{
    public function __construct(private readonly LectureRecensement $recensement)
    {
    }

    /**
     * Ne traite que les triplets absents de la table : les décisions déjà prises sont conservées.
     *
     * @return array{creees:int, auto:int, a_valider:int}
     */
    public function rapprocher(): array
    {
        $this->exigerAuteur();

        $sources = $this->recensement->sourcesCommunes();

        $index = [];
        foreach ($this->recensement->communes() as $commune) {
            $cle = $this->cle($commune->region_nom, $commune->departement_nom, $commune->nom);
            $index[$cle][] = $commune->id;
        }

        return DB::transaction(function () use ($sources, $index) {
            $connus = CorrespondanceGeo::query()
                ->get(['contexte_region', 'contexte_departement', 'libelle_commune'])
                ->mapWithKeys(fn ($c) => [$this->cleBrute($c->contexte_region, $c->contexte_departement, $c->libelle_commune) => true]);

            $creees = 0;
            $auto = 0;
            $aValider = 0;

            foreach ($sources as $source) {
                if ($connus->has($this->cleBrute($source->region, $source->departement, $source->commune))) {
                    continue;
                }

                $candidats = $index[$this->cle($source->region, $source->departement, $source->commune)] ?? [];
                sort($candidats);

                $unique = count($candidats) === 1;
                // insertOrIgnore : la collation MySQL ignore casse et accents, deux graphies du
                // fichier peuvent donc viser la même ligne ; la première l'emporte.
                $inseree = CorrespondanceGeo::query()->insertOrIgnore([
                    'contexte_region'      => $source->region,
                    'contexte_departement' => $source->departement,
                    'libelle_commune'      => $source->commune,
                    'commune_id'           => $candidats[0] ?? null,
                    'statut'               => $unique ? 'validee' : 'proposee',
                    'methode'              => 'auto',
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);
                if ($inseree === 0) {
                    continue;
                }
                $creees++;
                $unique ? $auto++ : $aValider++;
            }

            return ['creees' => $creees, 'auto' => $auto, 'a_valider' => $aValider];
        }, attempts: 3);
    }

    public function valider(CorrespondanceGeo $correspondance, int $communeId): void
    {
        $this->exigerAuteur();

        if (! $this->recensement->communes()->contains('id', $communeId)) {
            throw new InvalidArgumentException("Commune inconnue : {$communeId}.");
        }

        DB::transaction(function () use ($correspondance, $communeId) {
            $actuelle = CorrespondanceGeo::whereKey($correspondance->id)->lockForUpdate()->firstOrFail();
            $avant = ['statut' => $actuelle->statut, 'commune_id' => $actuelle->commune_id];

            $actuelle->update([
                'commune_id' => $communeId,
                'statut'     => 'validee',
                'methode'    => 'manuelle',
                'valide_par' => auth()->id(),
                'valide_le'  => now(),
                'motif'      => null,
            ]);

            Journal::noter('correspondance_geo', $actuelle->id, 'validation', $avant, [
                'statut' => 'validee', 'commune_id' => $communeId,
            ]);
        }, attempts: 3);

        $correspondance->refresh();
    }

    public function rejeter(CorrespondanceGeo $correspondance, string $motif): void
    {
        $this->exigerAuteur();

        $motif = trim($motif);
        if ($motif === '') {
            throw new InvalidArgumentException('Le motif est obligatoire.');
        }
        if (mb_strlen($motif) > 500) {
            throw new InvalidArgumentException('Le motif ne dépasse pas 500 caractères.');
        }

        DB::transaction(function () use ($correspondance, $motif) {
            $actuelle = CorrespondanceGeo::whereKey($correspondance->id)->lockForUpdate()->firstOrFail();
            $avant = ['statut' => $actuelle->statut, 'commune_id' => $actuelle->commune_id];

            $actuelle->update([
                'commune_id' => null,
                'statut'     => 'rejetee',
                'methode'    => 'manuelle',
                'valide_par' => auth()->id(),
                'valide_le'  => now(),
                'motif'      => $motif,
            ]);

            Journal::noter('correspondance_geo', $actuelle->id, 'rejet', $avant, [
                'statut' => 'rejetee', 'commune_id' => null,
            ], $motif);
        }, attempts: 3);

        $correspondance->refresh();
    }

    /** Commune du recensement validée pour ce triplet du fichier, sinon null. */
    public function communeValidee(string $region, string $departement, string $commune): ?int
    {
        $id = CorrespondanceGeo::where([
            'contexte_region'      => $region,
            'contexte_departement' => $departement,
            'libelle_commune'      => $commune,
            'statut'               => 'validee',
        ])->value('commune_id');

        return $id === null ? null : (int) $id;
    }

    private function cle(?string $region, ?string $departement, ?string $commune): string
    {
        return $this->cleBrute(NomGeo::normaliser($region ?? ''), NomGeo::normaliser($departement ?? ''), NomGeo::normaliser($commune ?? ''));
    }

    private function cleBrute(string $region, string $departement, string $commune): string
    {
        return $region."\x1F".$departement."\x1F".$commune;
    }

    private function exigerAuteur(): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Opération sans auteur.');
        }
    }
}

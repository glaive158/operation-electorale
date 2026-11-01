<?php

namespace App\Services\Logistique;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seul point d'accès à la base « recensement » : lecture seule, SQL portable MySQL/SQLite.
 *
 * Dans fichier_electoral (origine « national »), la colonne « departement » porte le nom de la
 * région et « arrondissement » celui du département. À l'étranger, « departement » est le pays
 * et « commune » la ville.
 */
class LectureRecensement
{
    /** @return Collection<int, object{id:int, nom:string, region_id:int, region_nom:string}> */
    public function departements(): Collection
    {
        $regions = $this->base()->table('regions')->pluck('nom', 'id');

        return $this->base()->table('departements')
            ->select('id', 'nom', 'region_id')
            ->orderBy('id')
            ->get()
            ->map(fn ($d) => (object) [
                'id'         => (int) $d->id,
                'nom'        => $d->nom,
                'region_id'  => (int) $d->region_id,
                'region_nom' => $regions[$d->region_id] ?? null,
            ]);
    }

    /** @return Collection<int, object{id:int, nom:string, departement_id:int}> */
    public function arrondissements(): Collection
    {
        return $this->base()->table('arrondissements')
            ->select('id', 'nom', 'departement_id')
            ->orderBy('id')
            ->get()
            ->map(fn ($a) => (object) [
                'id'             => (int) $a->id,
                'nom'            => $a->nom,
                'departement_id' => (int) $a->departement_id,
            ]);
    }

    /** @return Collection<int, object{id:int, nom:string, arrondissement_id:int, departement_id:?int, departement_nom:?string, region_nom:?string}> */
    public function communes(): Collection
    {
        $departements = $this->departements()->keyBy('id');
        $arrondissements = $this->arrondissements()->keyBy('id');

        return $this->base()->table('communes')
            ->select('id', 'nom', 'arrondissement_id')
            ->orderBy('id')
            ->get()
            ->map(function ($c) use ($departements, $arrondissements) {
                $arrondissement = $arrondissements[$c->arrondissement_id] ?? null;
                $departement = $arrondissement ? ($departements[$arrondissement->departement_id] ?? null) : null;

                return (object) [
                    'id'                => (int) $c->id,
                    'nom'               => $c->nom,
                    'arrondissement_id' => (int) $c->arrondissement_id,
                    'departement_id'    => $departement?->id,
                    'departement_nom'   => $departement?->nom,
                    'region_nom'        => $departement?->region_nom,
                ];
            });
    }

    /** @return Collection<int, object{pays:string, ville:string}> */
    public function villesEtranger(): Collection
    {
        return $this->base()->table('fichier_electoral')
            ->where('origine', 'etranger')
            ->whereNotNull('departement')->whereRaw("TRIM(departement) <> ''")
            ->whereNotNull('commune')->whereRaw("TRIM(commune) <> ''")
            ->select('departement as pays', 'commune as ville')
            ->distinct()
            ->orderBy('pays')
            ->orderBy('ville')
            ->get()
            ->map(fn ($v) => (object) ['pays' => $v->pays, 'ville' => $v->ville]);
    }

    /**
     * Triplets distincts du fichier national, tels qu'écrits : région, département, commune.
     * Les lignes dont l'un des trois est vide sont ignorées.
     *
     * @return Collection<int, object{region:string, departement:string, commune:string}>
     */
    public function sourcesCommunes(): Collection
    {
        return $this->base()->table('fichier_electoral')
            ->where('origine', 'national')
            ->whereNotNull('departement')->whereRaw("TRIM(departement) <> ''")
            ->whereNotNull('arrondissement')->whereRaw("TRIM(arrondissement) <> ''")
            ->whereNotNull('commune')->whereRaw("TRIM(commune) <> ''")
            ->select('departement as region', 'arrondissement as departement', 'commune')
            ->distinct()
            ->orderBy('region')
            ->orderBy('departement')
            ->orderBy('commune')
            ->get()
            ->map(fn ($s) => (object) ['region' => $s->region, 'departement' => $s->departement, 'commune' => $s->commune]);
    }

    /**
     * Effectifs du fichier national par triplet tel qu'écrit et par bureau de vote (code_bureau),
     * mêmes filtres que sourcesCommunes(). Un électeur = une ligne. Le regroupement par bureau
     * permet à l'appelant de compter bureaux et lieux de vote distincts même lorsque plusieurs
     * graphies d'une commune se rejoignent.
     *
     * @return Collection<int, object{region:string, departement:string, commune:string, code_bureau:?string, electeurs:int}>
     */
    public function agregatsNational(): Collection
    {
        // Pas d'alias dans le SELECT : « departement » désignerait deux colonnes différentes dans GROUP BY.
        return $this->base()->table('fichier_electoral')
            ->where('origine', 'national')
            ->whereNotNull('departement')->whereRaw("TRIM(departement) <> ''")
            ->whereNotNull('arrondissement')->whereRaw("TRIM(arrondissement) <> ''")
            ->whereNotNull('commune')->whereRaw("TRIM(commune) <> ''")
            ->select('departement', 'arrondissement', 'commune', 'code_bureau')
            ->selectRaw('COUNT(*) as electeurs')
            ->groupBy('departement', 'arrondissement', 'commune', 'code_bureau')
            ->get()
            ->map(fn ($l) => (object) [
                'region'      => $l->departement,
                'departement' => $l->arrondissement,
                'commune'     => $l->commune,
                'code_bureau' => $l->code_bureau,
                'electeurs'   => (int) $l->electeurs,
            ])
            ->sortBy([['region', 'asc'], ['departement', 'asc'], ['commune', 'asc'], ['code_bureau', 'asc']])
            ->values();
    }

    /**
     * Effectifs des Sénégalais de l'étranger par (pays, ville) tels qu'écrits et par bureau de vote.
     *
     * @return Collection<int, object{pays:string, ville:string, code_bureau:?string, electeurs:int}>
     */
    public function agregatsEtranger(): Collection
    {
        return $this->base()->table('fichier_electoral')
            ->where('origine', 'etranger')
            ->whereNotNull('departement')->whereRaw("TRIM(departement) <> ''")
            ->whereNotNull('commune')->whereRaw("TRIM(commune) <> ''")
            ->select('departement', 'commune', 'code_bureau')
            ->selectRaw('COUNT(*) as electeurs')
            ->groupBy('departement', 'commune', 'code_bureau')
            ->get()
            ->map(fn ($l) => (object) [
                'pays'        => $l->departement,
                'ville'       => $l->commune,
                'code_bureau' => $l->code_bureau,
                'electeurs'   => (int) $l->electeurs,
            ])
            ->sortBy([['pays', 'asc'], ['ville', 'asc'], ['code_bureau', 'asc']])
            ->values();
    }

    /** Nombre total de lignes (électeurs) du fichier, toutes origines et géographies confondues. */
    public function totalFichier(): int
    {
        return (int) $this->base()->table('fichier_electoral')->count();
    }

    /**
     * Contrôles de cohérence des codes bureau du fichier.
     *
     * @return array{codes_longueur_invalide:int, codes_multi_communes:int, prefixes_incoherents:int}
     */
    public function controles(): array
    {
        $table = fn () => $this->base()->table('fichier_electoral');

        $longueurInvalide = $table()
            ->where(fn ($q) => $q->whereNull('code_bureau')->orWhereRaw('LENGTH(code_bureau) <> 10'))
            ->count();

        $multiCommunes = $this->base()->query()->fromSub(
            $table()->select('code_bureau')
                ->whereNotNull('code_bureau')
                ->groupBy('code_bureau')
                ->havingRaw('COUNT(DISTINCT commune) > 1 OR COUNT(DISTINCT arrondissement) > 1'),
            'codes',
        )->count();

        $prefixesIncoherents = $this->base()->query()->fromSub(
            $table()->selectRaw('SUBSTR(code_bureau, 1, 8) as prefixe')
                ->whereNotNull('code_bureau')
                ->groupByRaw('SUBSTR(code_bureau, 1, 8)')
                ->havingRaw('COUNT(DISTINCT lieu_vote) > 1'),
            'prefixes',
        )->count();

        return [
            'codes_longueur_invalide' => (int) $longueurInvalide,
            'codes_multi_communes'    => (int) $multiCommunes,
            'prefixes_incoherents'    => (int) $prefixesIncoherents,
        ];
    }

    private function base(): \Illuminate\Database\Connection
    {
        return DB::connection('recensement');
    }
}

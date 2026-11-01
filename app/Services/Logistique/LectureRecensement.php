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
            ->whereNotNull('departement')->where('departement', '!=', '')
            ->whereNotNull('commune')->where('commune', '!=', '')
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
            ->whereNotNull('departement')->where('departement', '!=', '')
            ->whereNotNull('arrondissement')->where('arrondissement', '!=', '')
            ->whereNotNull('commune')->where('commune', '!=', '')
            ->select('departement as region', 'arrondissement as departement', 'commune')
            ->distinct()
            ->orderBy('region')
            ->orderBy('departement')
            ->orderBy('commune')
            ->get()
            ->map(fn ($s) => (object) ['region' => $s->region, 'departement' => $s->departement, 'commune' => $s->commune]);
    }

    private function base(): \Illuminate\Database\Connection
    {
        return DB::connection('recensement');
    }
}

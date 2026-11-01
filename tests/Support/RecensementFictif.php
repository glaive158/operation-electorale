<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fausse base « recensement » pour les tests : seule source d'écriture sur cette connexion.
 */
class RecensementFictif
{
    public static function creerSchema(): void
    {
        $schema = Schema::connection('recensement');

        $schema->create('regions', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
        });
        $schema->create('departements', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->unsignedBigInteger('region_id');
        });
        $schema->create('arrondissements', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->unsignedBigInteger('departement_id');
        });
        $schema->create('communes', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->unsignedBigInteger('arrondissement_id');
        });
        $schema->create('fichier_electoral', function (Blueprint $table) {
            $table->id();
            $table->string('origine', 20)->default('national');
            $table->string('departement')->nullable();
            $table->string('arrondissement')->nullable();
            $table->string('commune')->nullable();
            $table->string('lieu_vote')->nullable();
            $table->string('code_bureau', 30)->nullable();
        });
    }

    /**
     * @param  array<string, array<string, array<string, array<int, string>>>>  $arbre
     *         région => département => arrondissement => communes
     * @return array{regions: array<string,int>, departements: array<string,int>, arrondissements: array<string,int>, communes: array<string,int>}
     */
    public static function geo(array $arbre): array
    {
        $ids = ['regions' => [], 'departements' => [], 'arrondissements' => [], 'communes' => []];
        $base = DB::connection('recensement');

        foreach ($arbre as $region => $departements) {
            $regionId = $base->table('regions')->insertGetId(['nom' => $region]);
            $ids['regions'][$region] = $regionId;

            foreach ($departements as $departement => $arrondissements) {
                $departementId = $base->table('departements')->insertGetId(['nom' => $departement, 'region_id' => $regionId]);
                $ids['departements'][$departement] = $departementId;

                foreach ($arrondissements as $arrondissement => $communes) {
                    $arrondissementId = $base->table('arrondissements')->insertGetId(['nom' => $arrondissement, 'departement_id' => $departementId]);
                    $ids['arrondissements'][$arrondissement] = $arrondissementId;

                    foreach ($communes as $commune) {
                        $ids['communes'][$commune] = $base->table('communes')->insertGetId(['nom' => $commune, 'arrondissement_id' => $arrondissementId]);
                    }
                }
            }
        }

        return $ids;
    }

    /**
     * National : $departement = région, $arrondissement = département (convention du fichier).
     * Étranger : $departement = pays, $commune = ville.
     */
    public static function electeurs(
        string $origine,
        string $departement,
        ?string $arrondissement,
        string $commune,
        string $codeBureau,
        string $lieuVote,
        int $nombre,
    ): void {
        $lignes = [];
        for ($i = 0; $i < $nombre; $i++) {
            $lignes[] = [
                'origine'        => $origine,
                'departement'    => $departement,
                'arrondissement' => $arrondissement,
                'commune'        => $commune,
                'lieu_vote'      => $lieuVote,
                'code_bureau'    => $codeBureau,
            ];
        }

        foreach (array_chunk($lignes, 500) as $paquet) {
            DB::connection('recensement')->table('fichier_electoral')->insert($paquet);
        }
    }
}

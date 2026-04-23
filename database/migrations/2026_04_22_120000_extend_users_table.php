<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('prenom', 100)->nullable()->after('name');
            $table->enum('role', ['admin','gouverneur','prefet','sous_prefet','commission'])->default('commission')->after('prenom');
            $table->unsignedBigInteger('region_id')->nullable()->after('role');
            $table->string('region_nom', 100)->nullable()->after('region_id');
            $table->unsignedBigInteger('departement_id')->nullable()->after('region_nom');
            $table->string('departement_nom', 100)->nullable()->after('departement_id');
            $table->unsignedBigInteger('arrondissement_id')->nullable()->after('departement_nom');
            $table->string('arrondissement_nom', 100)->nullable()->after('arrondissement_id');
            $table->unsignedBigInteger('commune_id')->nullable()->after('arrondissement_nom');
            $table->string('commune_nom', 100)->nullable()->after('commune_id');
            $table->boolean('actif')->default(true)->after('commune_nom');
            $table->string('telephone', 20)->nullable()->after('actif');

            $table->index(['role', 'actif']);
            $table->index(['region_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'prenom','role','region_id','region_nom',
                'departement_id','departement_nom',
                'arrondissement_id','arrondissement_nom',
                'commune_id','commune_nom','actif','telephone',
            ]);
        });
    }
};

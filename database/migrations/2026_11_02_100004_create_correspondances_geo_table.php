<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correspondances_geo', function (Blueprint $table) {
            $table->id();
            // Valeurs du fichier électoral, telles quelles.
            $table->string('contexte_region', 150);
            $table->string('contexte_departement', 150);
            $table->string('libelle_commune', 150);
            // Identifiant de commune du recensement : pas de clé étrangère (autre base).
            $table->unsignedBigInteger('commune_id')->nullable();
            $table->enum('statut', ['proposee', 'validee', 'rejetee'])->default('proposee');
            $table->enum('methode', ['auto', 'manuelle'])->default('auto');
            $table->foreignId('valide_par')->nullable()->constrained('users');
            $table->timestamp('valide_le')->nullable();
            $table->string('motif', 500)->nullable();
            $table->timestamps();

            $table->unique(['contexte_region', 'contexte_departement', 'libelle_commune'], 'correspondances_geo_triplet_unique');
            $table->index('statut');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correspondances_geo');
    }
};

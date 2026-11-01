<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cles_repartition', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('cycles_electoraux');
            $table->foreignId('materiel_id')->constrained('materiels');
            $table->enum('base', ['electeur', 'bureau_vote', 'lieu_vote', 'ca', 'circonscription', 'forfait']);
            $table->boolean('par_entite')->default(false);
            $table->decimal('ratio', 12, 4)->default(1);
            $table->string('cle_affichee', 150)->nullable();
            // Null = valeur du cycle.
            $table->decimal('majoration_distribuee', 5, 2)->nullable();
            $table->decimal('reserve_centrale', 5, 2)->nullable();
            $table->unsignedInteger('minimum')->default(0);
            $table->boolean('minimum_par_commune')->default(false);
            $table->enum('arrondi', ['unite', 'dizaine', 'cinquantaine', 'centaine'])->default('unite');
            $table->boolean('arrondi_conditionnement')->default(false);
            $table->timestamps();

            $table->unique(['cycle_id', 'materiel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cles_repartition');
    }
};

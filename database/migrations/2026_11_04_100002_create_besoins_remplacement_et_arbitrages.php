<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('besoins_remplacement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('cycles_electoraux');
            $table->foreignId('destinataire_id')->constrained('destinataires');
            $table->foreignId('materiel_id')->constrained('materiels');
            $table->unsignedInteger('quantite');
            $table->string('motif', 500);
            $table->foreignId('autorise_par')->constrained('users');
            $table->timestamps();

            $table->index(['cycle_id', 'destinataire_id', 'materiel_id'], 'besoins_remplacement_cycle_dest_mat_index');
        });

        Schema::create('arbitrages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('cycles_electoraux');
            $table->enum('objet', ['declaration', 'cloture_ecart']);
            // Les clés étrangères vers les remises et les déclarations sont ajoutées avec leurs tables.
            $table->unsignedBigInteger('remise_id')->nullable()->index();
            $table->unsignedBigInteger('declaration_id')->nullable()->index();
            $table->foreignId('destinataire_id')->constrained('destinataires');
            $table->foreignId('materiel_id')->nullable()->constrained('materiels');
            $table->string('decision', 50);
            $table->unsignedInteger('quantite_abandon_reception')->default(0);
            $table->unsignedInteger('quantite_expedition_annulee')->default(0);
            $table->string('motif', 500);
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();

            $table->index(['cycle_id', 'destinataire_id', 'materiel_id', 'objet'], 'arbitrages_cycle_dest_mat_objet_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arbitrages');
        Schema::dropIfExists('besoins_remplacement');
    }
};

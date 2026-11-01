<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans_dotation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('cycles_electoraux');
            $table->unsignedInteger('version');
            $table->foreignId('instantane_id')->constrained('instantanes_effectifs');
            // Clés et valeurs effectives figées au calcul, par article.
            $table->json('parametres');
            $table->enum('statut', ['brouillon', 'approuve', 'remplace'])->default('brouillon');
            $table->foreignId('calcule_par')->constrained('users');
            $table->foreignId('approuve_par')->nullable()->constrained('users');
            $table->timestamp('approuve_le')->nullable();
            // Anomalies du plan (hors anomalies de ligne) : liste de chaînes.
            $table->json('anomalies');
            $table->timestamps();

            $table->unique(['cycle_id', 'version']);
        });

        Schema::create('plan_dotation_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_dotation_id')->constrained('plans_dotation')->cascadeOnDelete();
            $table->foreignId('destinataire_id')->constrained('destinataires');
            $table->foreignId('materiel_id')->constrained('materiels');
            $table->unsignedInteger('effectif');
            $table->decimal('exact', 14, 4);
            $table->decimal('majoration', 5, 2);
            $table->unsignedInteger('minimum');
            $table->string('arrondi', 20);
            $table->unsignedInteger('dotation_calculee')->nullable();
            $table->string('anomalie', 500)->nullable();
            $table->unsignedInteger('correction')->nullable();
            $table->string('correction_motif', 500)->nullable();
            $table->foreignId('corrige_par')->nullable()->constrained('users');
            $table->timestamp('corrige_le')->nullable();
            $table->unsignedInteger('dotation_approuvee')->nullable();
            $table->timestamps();

            $table->unique(['plan_dotation_id', 'destinataire_id', 'materiel_id'], 'plan_dotation_lignes_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_dotation_lignes');
        Schema::dropIfExists('plans_dotation');
    }
};

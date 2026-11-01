<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instantanes_effectifs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('cycles_electoraux');
            $table->unsignedInteger('version');
            $table->enum('statut', ['en_cours', 'brouillon', 'fige', 'echec'])->default('en_cours');
            $table->string('source', 100)->default('fichier_electoral');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('fige_par')->nullable()->constrained('users');
            $table->timestamp('fige_le')->nullable();
            $table->json('bilan')->nullable();
            $table->text('erreur')->nullable();
            $table->timestamps();

            $table->unique(['cycle_id', 'version']);
        });

        Schema::create('instantane_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instantane_id')->constrained('instantanes_effectifs')->cascadeOnDelete();
            // Identifiants du recensement : pas de clé étrangère (autre base).
            $table->unsignedBigInteger('commune_id')->nullable();
            $table->string('pays', 100)->nullable();
            $table->string('ville', 100)->nullable();
            $table->unsignedBigInteger('departement_id')->nullable();
            // Libellés tels qu'écrits dans le fichier électoral (ou nom du recensement si rapprochée).
            $table->string('contexte_region', 150)->nullable();
            $table->string('contexte_departement', 150)->nullable();
            $table->string('libelle_commune', 150)->nullable();
            $table->unsignedInteger('electeurs')->default(0);
            $table->unsignedInteger('bureaux_vote')->default(0);
            $table->unsignedInteger('lieux_vote')->default(0);
            $table->unsignedInteger('commissions')->default(0);
            $table->boolean('rapprochee')->default(true);
            $table->foreignId('destinataire_id')->nullable()->constrained('destinataires');
            $table->timestamps();

            $table->index(['instantane_id', 'commune_id']);
            $table->index(['instantane_id', 'rapprochee']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instantane_lignes');
        Schema::dropIfExists('instantanes_effectifs');
    }
};

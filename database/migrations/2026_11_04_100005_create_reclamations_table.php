<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reclamations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('decharge_id')->constrained('decharges');
            $table->foreignId('materiel_id')->constrained('materiels');
            $table->enum('motif', ['manquant', 'defectueux']);
            $table->unsignedInteger('quantite');
            $table->string('message', 1000);
            $table->enum('statut', ['ouverte', 'en_traitement', 'resolue'])->default('ouverte');
            $table->enum('issue', ['remplacement_autorise', 'article_retrouve', 'erreur_declaration', 'cloture_sans_remplacement'])->nullable();
            $table->string('motif_cloture', 500)->nullable();
            $table->foreignId('ouverte_par')->constrained('users');
            $table->foreignId('traitee_par')->nullable()->constrained('users');
            $table->timestamp('resolue_le')->nullable();
            $table->timestamps();

            $table->index(['decharge_id', 'statut']);
        });

        Schema::table('besoins_remplacement', function (Blueprint $table) {
            $table->foreignId('reclamation_id')->nullable()->constrained('reclamations');
        });

        Schema::table('decharges', function (Blueprint $table) {
            // Un brouillon de remplacement par besoin (les autres décharges laissent la colonne vide).
            $table->foreignId('besoin_remplacement_id')->nullable()->unique()->constrained('besoins_remplacement');
        });
    }

    public function down(): void
    {
        Schema::table('decharges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('besoin_remplacement_id');
        });
        Schema::table('besoins_remplacement', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reclamation_id');
        });
        Schema::dropIfExists('reclamations');
    }
};

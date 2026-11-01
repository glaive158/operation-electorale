<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commandes', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 50)->unique();
            $table->string('fournisseur', 200);
            $table->date('date_commande');
            $table->enum('statut', ['brouillon', 'confirmee', 'partiellement_recue', 'recue', 'annulee'])->default('brouillon');
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();

            $table->index('statut');
        });

        Schema::create('commande_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commande_id')->constrained('commandes');
            $table->foreignId('materiel_id')->constrained('materiels');
            // null = stock commun
            $table->foreignId('cycle_id')->nullable()->constrained('cycles_electoraux');
            $table->unsignedInteger('quantite_commandee');
            $table->unsignedInteger('quantite_acceptee')->default(0);
            $table->unsignedInteger('quantite_rejetee')->default(0);
            $table->unsignedInteger('quantite_abandonnee')->default(0);
            $table->timestamps();

            $table->index(['materiel_id', 'cycle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commande_lignes');
        Schema::dropIfExists('commandes');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('decharge_id')->constrained('decharges');
            $table->enum('etape', ['chargement', 'livraison', 'redistribution']);
            // de_id / a_id : utilisateur pour un agent, destinataire pour un destinataire ; vides pour le magasin et le transporteur.
            $table->enum('de_type', ['magasin', 'agent', 'transporteur', 'destinataire']);
            $table->unsignedBigInteger('de_id')->nullable();
            $table->enum('a_type', ['agent', 'transporteur', 'destinataire']);
            $table->unsignedBigInteger('a_id')->nullable();
            $table->enum('statut', ['en_attente', 'recue', 'a_verifier', 'annulee'])->default('en_attente');
            $table->string('transporteur_nom', 150)->nullable();
            $table->string('transporteur_qualite', 150)->nullable();
            $table->string('transporteur_societe', 150)->nullable();
            $table->string('bordereau_numero', 50)->nullable();
            $table->foreignId('preparee_par')->constrained('users');
            $table->string('motif_annulation', 500)->nullable();
            $table->timestamps();

            $table->index(['decharge_id', 'etape']);
        });

        Schema::create('remise_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remise_id')->constrained('remises');
            $table->foreignId('materiel_id')->constrained('materiels');
            $table->unsignedInteger('quantite');
            $table->timestamps();

            $table->unique(['remise_id', 'materiel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remise_lignes');
        Schema::dropIfExists('remises');
    }
};

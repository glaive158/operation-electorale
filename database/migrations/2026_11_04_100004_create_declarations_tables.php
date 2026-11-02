<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remise_id')->constrained('remises');
            $table->enum('mode', ['tablette', 'compte']);
            $table->string('signataire_nom', 150);
            $table->string('signataire_qualite', 150);
            $table->foreignId('user_id')->constrained('users');
            // La décharge visée, éventuellement annulée depuis (la déclaration est alors à vérifier).
            $table->foreignId('decharge_id')->constrained('decharges');
            $table->timestamp('horodatage_terminal')->nullable();
            $table->string('cle_idempotence', 64)->unique();
            $table->char('empreinte_contenu', 64);
            $table->enum('statut', ['valide', 'retenue', 'concordante', 'a_verifier', 'ecartee', 'remplacee'])->default('valide');
            $table->json('anomalies')->nullable();
            $table->timestamps();

            $table->index(['remise_id', 'statut']);
        });

        Schema::create('declaration_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('declaration_id')->constrained('declarations');
            $table->foreignId('materiel_id')->constrained('materiels');
            $table->unsignedInteger('recu');
            $table->unsignedInteger('defectueux');
            $table->string('observation', 500)->nullable();
            $table->timestamps();

            $table->unique(['declaration_id', 'materiel_id']);
        });

        Schema::table('arbitrages', function (Blueprint $table) {
            $table->foreign('remise_id')->references('id')->on('remises');
            $table->foreign('declaration_id')->references('id')->on('declarations');
        });
    }

    public function down(): void
    {
        Schema::table('arbitrages', function (Blueprint $table) {
            $table->dropForeign(['remise_id']);
            $table->dropForeign(['declaration_id']);
        });
        Schema::dropIfExists('declaration_lignes');
        Schema::dropIfExists('declarations');
    }
};

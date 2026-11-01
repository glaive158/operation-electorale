<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinataires', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['prefecture', 'sous_prefecture', 'poste']);
            $table->string('libelle', 200);
            // Identifiants du recensement : pas de clé étrangère (autre base).
            $table->unsignedBigInteger('departement_id')->nullable()->unique();
            $table->unsignedBigInteger('arrondissement_id')->nullable()->unique();
            $table->foreignId('poste_etranger_id')->nullable()->unique()->constrained('postes_etranger');
            $table->foreignId('prefecture_id')->nullable()->constrained('destinataires');
            $table->string('signataire_nom', 150)->nullable();
            $table->string('signataire_qualite', 150)->nullable();
            $table->foreignId('autorite_user_id')->nullable()->constrained('users');
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destinataires');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entites_bulletin', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('cycles_electoraux');
            $table->enum('type', ['candidat', 'liste', 'option']);
            $table->string('nom', 200);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->boolean('national')->default(true);
            // Un article bulletin par entité.
            $table->foreignId('materiel_id')->unique()->constrained('materiels');
            $table->timestamps();

            $table->unique(['cycle_id', 'nom']);
        });

        Schema::create('entite_bulletin_perimetres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entite_bulletin_id')->constrained('entites_bulletin')->cascadeOnDelete();
            // Identifiants de la base du recensement : pas de clé étrangère (autre base).
            $table->unsignedBigInteger('departement_id')->nullable();
            $table->unsignedBigInteger('commune_id')->nullable();
            $table->string('pays', 100)->nullable();
            $table->timestamps();

            $table->index('entite_bulletin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entite_bulletin_perimetres');
        Schema::dropIfExists('entites_bulletin');
    }
};

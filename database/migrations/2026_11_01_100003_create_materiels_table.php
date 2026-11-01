<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materiels', function (Blueprint $table) {
            $table->id();
            $table->string('libelle', 200);
            $table->enum('categorie', ['revision', 'materiel_lourd', 'imprime']);
            $table->string('regroupement', 100)->nullable();
            $table->unsignedInteger('ordre')->default(0);
            $table->string('unite_stock', 30)->default('pièce');
            $table->string('unite_distribution', 30)->nullable();
            $table->unsignedInteger('facteur_conditionnement')->nullable();
            $table->boolean('indivisible')->default(true);
            $table->boolean('reutilisable')->default(false);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materiels');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions_administratives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('cycles_electoraux');
            // Identifiant de commune du recensement : pas de clé étrangère (autre base).
            $table->unsignedBigInteger('commune_id')->nullable();
            $table->string('pays', 100)->nullable();
            $table->string('ville', 100)->nullable();
            $table->enum('type', ['fixe', 'itinerante', 'etranger']);
            $table->string('libelle', 200)->nullable();
            $table->timestamps();

            // Plusieurs commissions (fixe + itinérantes) par localité : pas d'unicité.
            $table->index(['cycle_id', 'commune_id']);
            $table->index(['cycle_id', 'pays', 'ville']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions_administratives');
    }
};

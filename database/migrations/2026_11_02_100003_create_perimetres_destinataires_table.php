<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perimetres_destinataires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('cycles_electoraux');
            $table->foreignId('destinataire_id')->constrained('destinataires');
            // Identifiant de commune du recensement : pas de clé étrangère (autre base).
            $table->unsignedBigInteger('commune_id')->nullable();
            $table->string('pays', 100)->nullable();
            $table->string('ville', 100)->nullable();
            $table->timestamps();

            $table->unique(['cycle_id', 'commune_id']);
            $table->unique(['cycle_id', 'pays', 'ville']);
            $table->index('destinataire_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perimetres_destinataires');
    }
};

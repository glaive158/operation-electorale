<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('circuits_departement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('cycles_electoraux');
            // Identifiant de département du recensement : pas de clé étrangère (autre base).
            $table->unsignedBigInteger('departement_id');
            $table->enum('circuit', ['via_prefet', 'direct']);
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();

            $table->unique(['cycle_id', 'departement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('circuits_departement');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Qui a envoyé (ou prouvé posséder) les octets d'une pièce : seul celui-là peut la lier à ses déclarations.
        Schema::create('piece_possessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('piece_jointe_id')->constrained('pieces_jointes');
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();

            $table->unique(['piece_jointe_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('piece_possessions');
    }
};

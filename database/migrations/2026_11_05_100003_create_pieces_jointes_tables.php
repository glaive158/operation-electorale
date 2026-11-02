<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pièces jointes adressées par leur contenu : une même image n'est stockée qu'une fois.
        Schema::create('pieces_jointes', function (Blueprint $table) {
            $table->id();
            $table->char('empreinte', 64)->unique();
            $table->string('chemin', 255);
            $table->unsignedInteger('taille');
            $table->string('type_mime', 50);
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();
        });

        // Pièces annoncées par une déclaration : « attendues » (piece_jointe_id nul) jusqu'à l'envoi du fichier.
        Schema::create('declaration_pieces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('declaration_id')->constrained('declarations');
            $table->char('empreinte', 64)->index();
            $table->enum('role', ['signature', 'photo']);
            $table->foreignId('piece_jointe_id')->nullable()->constrained('pieces_jointes');
            $table->timestamps();

            $table->unique(['declaration_id', 'empreinte']);
        });

        Schema::create('remise_pieces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remise_id')->constrained('remises');
            $table->foreignId('piece_jointe_id')->constrained('pieces_jointes');
            $table->enum('role', ['bordereau']);
            $table->timestamps();

            $table->unique(['remise_id', 'piece_jointe_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remise_pieces');
        Schema::dropIfExists('declaration_pieces');
        Schema::dropIfExists('pieces_jointes');
    }
};

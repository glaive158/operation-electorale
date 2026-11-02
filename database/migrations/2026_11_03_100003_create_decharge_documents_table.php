<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decharge_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('decharge_id')->constrained('decharges');
            $table->enum('exemplaire', ['initial', 'annulation', 'reception']);
            $table->string('chemin', 255);
            // Empreinte SHA-256 (hexadécimal) du fichier archivé : contrôlée à chaque réimpression.
            $table->char('empreinte', 64);
            $table->unsignedInteger('taille');
            $table->foreignId('emis_par')->constrained('users');
            $table->timestamp('emis_le');

            $table->unique(['decharge_id', 'exemplaire']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decharge_documents');
    }
};

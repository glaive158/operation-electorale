<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restitution_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restitution_id')->constrained('restitutions');
            $table->enum('exemplaire', ['declaration', 'controle']);
            $table->string('chemin', 255);
            // Empreinte SHA-256 (hexadécimal) du fichier archivé : contrôlée à chaque réimpression.
            $table->char('empreinte', 64);
            $table->unsignedInteger('taille');
            $table->foreignId('emis_par')->constrained('users');
            // dateTime et non timestamp : sous MySQL, un premier timestamp prend une mise à jour automatique.
            $table->dateTime('emis_le');

            $table->unique(['restitution_id', 'exemplaire']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restitution_documents');
    }
};

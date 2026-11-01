<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receptions_fournisseur', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commande_id')->constrained('commandes');
            $table->date('date_reception');
            $table->string('bon_livraison', 100);
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();
        });

        Schema::create('reception_fournisseur_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reception_fournisseur_id')->constrained('receptions_fournisseur');
            $table->foreignId('commande_ligne_id')->constrained('commande_lignes');
            $table->unsignedInteger('acceptee');
            $table->unsignedInteger('rejetee');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reception_fournisseur_lignes');
        Schema::dropIfExists('receptions_fournisseur');
    }
};

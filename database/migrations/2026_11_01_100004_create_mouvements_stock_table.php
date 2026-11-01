<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mouvements_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('materiel_id')->constrained('materiels');
            $table->enum('type', [
                'inventaire_initial', 'entree_fournisseur', 'sortie_expedition',
                'reintegration', 'reclassement', 'reforme', 'compensation',
            ]);
            $table->enum('etat', ['bon', 'a_reparer'])->default('bon');
            $table->integer('quantite');
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('mouvement_compense_id')->nullable()->unique()->constrained('mouvements_stock');
            $table->text('motif')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();

            $table->index(['materiel_id', 'etat']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_stock');
    }
};

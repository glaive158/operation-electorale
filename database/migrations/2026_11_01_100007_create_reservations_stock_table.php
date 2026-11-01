<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('cycles_electoraux');
            $table->foreignId('materiel_id')->constrained('materiels');
            $table->unsignedInteger('quantite');
            $table->unsignedInteger('quantite_sur_attribution')->default(0);
            $table->enum('statut', ['active', 'consommee', 'liberee'])->default('active');
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();

            $table->index(['materiel_id', 'statut']);
            $table->index(['cycle_id', 'statut']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations_stock');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attributions_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('cycles_electoraux');
            $table->foreignId('materiel_id')->constrained('materiels');
            $table->unsignedInteger('quantite')->default(0);
            $table->timestamps();

            $table->unique(['cycle_id', 'materiel_id']);
            $table->index('materiel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attributions_stock');
    }
};

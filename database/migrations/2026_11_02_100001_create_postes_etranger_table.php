<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postes_etranger', function (Blueprint $table) {
            $table->id();
            $table->string('pays', 100);
            $table->string('ville', 100);
            $table->enum('type', ['consulat', 'ambassade']);
            $table->string('nom', 200);
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['pays', 'nom']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postes_etranger');
    }
};

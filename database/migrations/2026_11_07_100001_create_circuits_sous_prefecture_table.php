<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('circuits_sous_prefecture', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('cycles_electoraux', 'id', 'csp_cycle_foreign');
            // Destinataire de type sous_prefecture (contrôlé par le service).
            $table->foreignId('destinataire_id')->constrained('destinataires', 'id', 'csp_destinataire_foreign');
            $table->enum('circuit', ['via_prefet', 'direct']);
            $table->foreignId('user_id')->constrained('users', 'id', 'csp_user_foreign');
            $table->timestamps();

            $table->unique(['cycle_id', 'destinataire_id'], 'csp_cycle_destinataire_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('circuits_sous_prefecture');
    }
};

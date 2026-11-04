<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Une autorité (préfet, sous-préfet, chef de poste) confie à un adjoint le droit de signer à sa place les
        // réceptions d'un destinataire. Les bornes sont des instants : la délégation vaut de `du` (inclus) à la
        // première des deux dates `au` et `revoquee_le` (exclue).
        Schema::create('delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destinataire_id')->constrained('destinataires');
            $table->foreignId('autorite_user_id')->constrained('users');
            $table->foreignId('delegue_user_id')->constrained('users');
            $table->dateTime('du');
            $table->dateTime('au')->nullable();
            $table->dateTime('revoquee_le')->nullable();
            $table->string('motif', 500);
            $table->foreignId('cree_par')->constrained('users');
            $table->timestamps();

            $table->index(['destinataire_id', 'delegue_user_id']);
            $table->index(['delegue_user_id', 'du']);
        });

        // Qui a réellement signé une déclaration, et au titre de quelle délégation.
        Schema::table('declarations', function (Blueprint $table) {
            $table->foreignId('signataire_user_id')->nullable()->constrained('users');
            $table->foreignId('delegation_id')->nullable()->constrained('delegations');
        });
    }

    public function down(): void
    {
        Schema::table('declarations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delegation_id');
            $table->dropConstrainedForeignId('signataire_user_id');
        });
        Schema::dropIfExists('delegations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', [
                'admin', 'gouverneur', 'prefet', 'sous_prefet', 'commission',
                'logistique_gestionnaire', 'logistique_responsable',
                'chef_poste', 'agent_transport',
            ])->default('commission')->change();
        });
    }

    public function down(): void
    {
        // Rétrécir l'enum avec des comptes de terrain en base les ferait échouer ou les corromprait.
        if (DB::table('users')->whereIn('role', ['chef_poste', 'agent_transport'])->exists()) {
            throw new RuntimeException(
                'Retour arrière impossible : des comptes de chef de poste ou d\'agent de transport existent. Supprimez-les ou changez leur rôle avant de revenir en arrière.'
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', [
                'admin', 'gouverneur', 'prefet', 'sous_prefet', 'commission',
                'logistique_gestionnaire', 'logistique_responsable',
            ])->default('commission')->change();
        });
    }
};

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
            ])->default('commission')->change();
        });
    }

    public function down(): void
    {
        // Rétrécir l'enum avec des comptes logistique en base les ferait échouer ou les corromprait.
        if (DB::table('users')->whereIn('role', ['logistique_gestionnaire', 'logistique_responsable'])->exists()) {
            throw new RuntimeException(
                'Retour arrière impossible : des comptes logistique existent. Supprimez-les ou changez leur rôle avant de revenir en arrière.'
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'gouverneur', 'prefet', 'sous_prefet', 'commission'])
                ->default('commission')->change();
        });
    }
};

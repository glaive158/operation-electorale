<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'gouverneur', 'prefet', 'sous_prefet', 'commission'])
                ->default('commission')->change();
        });
    }
};

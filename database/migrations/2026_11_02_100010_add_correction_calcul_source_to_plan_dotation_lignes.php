<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_dotation_lignes', function (Blueprint $table) {
            // Dotation calculée sur laquelle portait une correction reprise d'une version précédente ;
            // null quand la correction a été saisie sur cette ligne.
            $table->unsignedInteger('correction_calcul_source')->nullable()->after('corrige_le');
        });
    }

    public function down(): void
    {
        Schema::table('plan_dotation_lignes', function (Blueprint $table) {
            $table->dropColumn('correction_calcul_source');
        });
    }
};

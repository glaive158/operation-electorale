<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // L'identifiant (UUID) de l'appareil pour lequel le jeton a été créé : l'identité de l'appareil désigné
        // se prouve par le jeton, pas par un en-tête que n'importe quel terminal peut recopier.
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('appareil_identifiant', 36)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['appareil_identifiant']);
            $table->dropColumn('appareil_identifiant');
        });
    }
};

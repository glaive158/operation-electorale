<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // L'heure de la tablette est un instant sans fuseau implicite : un datetime nullable évite la plage
        // limitée et la conversion de fuseau du type timestamp.
        Schema::table('declarations', function (Blueprint $table) {
            $table->dateTime('horodatage_terminal')->nullable()->change();
        });

        Schema::table('declarations', function (Blueprint $table) {
            $table->enum('verification_identite', ['en_attente', 'verifiee_code', 'verifiee_appareil', 'non_verifiee', 'a_verifier', 'echec', 'compte'])->default('non_verifiee');
            $table->enum('contestation', ['aucune', 'contestee', 'levee'])->default('aucune');
            // Empreinte du bloc chiffré reçu : un bloc ne sert qu'à une seule déclaration.
            $table->char('bloc_empreinte', 64)->nullable()->unique();
            $table->decimal('gps_lat', 9, 6)->nullable();
            $table->decimal('gps_lng', 9, 6)->nullable();
            $table->unsignedInteger('gps_precision')->nullable();
        });

        // Les déclarations existantes au compte ont leur identité garantie par la connexion.
        DB::table('declarations')->where('mode', 'compte')->update(['verification_identite' => 'compte']);
    }

    public function down(): void
    {
        Schema::table('declarations', function (Blueprint $table) {
            $table->dropUnique(['bloc_empreinte']);
        });
        Schema::table('declarations', function (Blueprint $table) {
            $table->dropColumn(['verification_identite', 'contestation', 'bloc_empreinte', 'gps_lat', 'gps_lng', 'gps_precision']);
        });
        Schema::table('declarations', function (Blueprint $table) {
            $table->timestamp('horodatage_terminal')->nullable()->change();
        });
    }
};

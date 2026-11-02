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
            $table->string('code_reception_hash')->nullable();
            $table->unsignedTinyInteger('code_echecs')->default(0);
            $table->timestamp('code_verrouille_le')->nullable();
            $table->boolean('doit_choisir_code')->default(false);
        });

        // Les autorités déjà en place choisissent leur code à leur prochaine connexion à l'espace terrain.
        DB::table('users')->whereIn('role', ['prefet', 'sous_prefet', 'chef_poste'])->update(['doit_choisir_code' => true]);

        // Historique : un code reste vérifiable à l'instant où une réception a été signée, même après un changement.
        Schema::create('codes_reception', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('hash');
            $table->timestamp('valide_du');
            $table->timestamp('valide_au')->nullable();
            $table->foreignId('cree_par')->constrained('users');
            $table->timestamps();

            $table->index(['user_id', 'valide_du']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('codes_reception');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['code_reception_hash', 'code_echecs', 'code_verrouille_le', 'doit_choisir_code']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Le jeton de la tablette, épinglé à la désignation : seul ce jeton est l'appareil désigné. Annoncer le même
        // UUID à la connexion ne suffit pas. Jeton supprimé : l'appareil n'est plus reconnu jusqu'à la redésignation.
        Schema::table('appareils', function (Blueprint $table) {
            $table->foreignId('jeton_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appareils', function (Blueprint $table) {
            $table->dropConstrainedForeignId('jeton_id');
        });
    }
};

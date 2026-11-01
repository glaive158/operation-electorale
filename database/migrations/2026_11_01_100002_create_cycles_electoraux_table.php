<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cycles_electoraux', function (Blueprint $table) {
            $table->id();
            $table->string('libelle', 150);
            $table->enum('type', ['revision', 'scrutin']);
            $table->date('date_scrutin')->nullable();
            $table->enum('statut', ['ouvert', 'clos'])->default('ouvert');
            $table->decimal('majoration_distribuee', 5, 2)->default(10);
            $table->decimal('reserve_centrale', 5, 2)->default(0);
            $table->enum('circuit_defaut', ['via_prefet', 'direct'])->default('via_prefet');
            $table->timestamp('clos_le')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cycles_electoraux');
    }
};

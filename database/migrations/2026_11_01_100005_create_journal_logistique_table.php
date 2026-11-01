<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_logistique', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('objet_type', 60);
            $table->unsignedBigInteger('objet_id');
            $table->string('action', 60);
            $table->json('avant')->nullable();
            $table->json('apres')->nullable();
            $table->text('motif')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['objet_type', 'objet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_logistique');
    }
};

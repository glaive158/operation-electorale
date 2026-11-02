<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un seul appareil actif par préfecture (garanti par AppareilService sous verrou) : celui qui prépare
        // les redistributions hors ligne. Un appareil révoqué n'est jamais supprimé.
        Schema::create('appareils', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destinataire_id')->constrained('destinataires');
            $table->uuid('identifiant')->unique();
            $table->string('nom', 100);
            $table->foreignId('designe_par')->constrained('users');
            $table->boolean('actif')->default(true);
            $table->dateTime('revoque_le')->nullable();
            $table->timestamps();

            $table->index(['destinataire_id', 'actif']);
        });

        // Quantité du solde du préfet mise de côté pour l'appareil avant son départ hors ligne : une ligne par
        // (réserve, article). `quantite` est ce qui reste (consommé par les redistributions de l'appareil) ;
        // libérée, la ligne garde la quantité rendue.
        Schema::create('reserves_appareil', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appareil_id')->constrained('appareils');
            $table->foreignId('decharge_id')->constrained('decharges');
            $table->foreignId('materiel_id')->constrained('materiels');
            $table->unsignedInteger('quantite_initiale');
            $table->unsignedInteger('quantite');
            $table->enum('statut', ['active', 'consommee', 'liberee'])->default('active');
            $table->timestamps();

            $table->index(['decharge_id', 'statut']);
            $table->index(['appareil_id', 'decharge_id', 'statut']);
        });

        Schema::table('remises', function (Blueprint $table) {
            $table->enum('statut', ['en_attente', 'recue', 'a_verifier', 'a_arbitrer', 'annulee'])->default('en_attente')->change();
        });
        Schema::table('remises', function (Blueprint $table) {
            // Redistribution synchronisée par un appareil : l'appareil connu (le cas échéant), l'identifiant qu'il a
            // déclaré, la clé d'idempotence de l'envoi, l'instant de son terminal et, pour une remise à arbitrer,
            // pourquoi elle l'est.
            $table->foreignId('appareil_id')->nullable()->constrained('appareils');
            $table->string('appareil_declare', 64)->nullable();
            $table->string('cle_idempotence', 64)->nullable()->unique();
            $table->dateTime('horodatage_terminal')->nullable();
            $table->string('raison_arbitrage', 500)->nullable();
        });
    }

    public function down(): void
    {
        // Rétrécir l'enum avec des remises à arbitrer en base les corromprait.
        if (DB::table('remises')->where('statut', 'a_arbitrer')->exists()) {
            throw new RuntimeException(
                'Retour arrière impossible : des remises à arbitrer existent. Arbitrez-les avant de revenir en arrière.'
            );
        }

        Schema::table('remises', function (Blueprint $table) {
            $table->dropConstrainedForeignId('appareil_id');
            $table->dropUnique(['cle_idempotence']);
            $table->dropColumn(['appareil_declare', 'cle_idempotence', 'horodatage_terminal', 'raison_arbitrage']);
        });
        Schema::table('remises', function (Blueprint $table) {
            $table->enum('statut', ['en_attente', 'recue', 'a_verifier', 'annulee'])->default('en_attente')->change();
        });

        Schema::dropIfExists('reserves_appareil');
        Schema::dropIfExists('appareils');
    }
};

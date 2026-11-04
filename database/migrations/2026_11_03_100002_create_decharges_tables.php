<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decharges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('cycles_electoraux');
            $table->foreignId('destinataire_id')->constrained('destinataires');
            $table->enum('categorie', ['revision', 'materiel_lourd', 'imprime']);
            $table->enum('nature', ['initiale', 'reliquat', 'remplacement']);
            // Numéro officiel attribué à la validation ; identifiant stable dès la création.
            $table->string('numero', 20)->nullable()->unique();
            $table->char('identifiant', 26)->unique();
            $table->foreignId('decharge_remplacee_id')->nullable()->constrained('decharges');
            $table->foreignId('plan_dotation_id')->constrained('plans_dotation');
            // Fixés à la validation, jamais à la préparation.
            $table->enum('circuit', ['via_prefet', 'direct', 'etranger'])->nullable();
            $table->foreignId('remis_a_id')->nullable()->constrained('destinataires');
            $table->json('donnees_gelees')->nullable();
            $table->enum('statut_document', ['brouillon', 'validee', 'annulee'])->default('brouillon');
            $table->enum('statut_acheminement', ['a_preparer', 'chargee', 'recue_intermediaire', 'recue_destinataire', 'a_verifier'])->default('a_preparer');
            $table->enum('statut_reclamation', ['aucune', 'ouverte', 'en_traitement', 'resolue'])->default('aucune');
            $table->foreignId('agent_id')->nullable()->constrained('users');
            $table->foreignId('prepare_par')->constrained('users');
            $table->foreignId('valide_par')->nullable()->constrained('users');
            $table->timestamp('valide_le')->nullable();
            $table->foreignId('annule_par')->nullable()->constrained('users');
            $table->timestamp('annule_le')->nullable();
            $table->string('motif_annulation', 500)->nullable();
            $table->timestamps();

            $table->index(['cycle_id', 'destinataire_id', 'categorie']);
        });

        Schema::create('decharge_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('decharge_id')->constrained('decharges');
            $table->foreignId('materiel_id')->constrained('materiels');
            // Rappel de la dotation du plan ; deja_engage est figé à la validation.
            $table->unsignedInteger('dotation_approuvee');
            $table->unsignedInteger('deja_engage')->default(0);
            $table->unsignedInteger('quantite');
            $table->string('cle_affichee', 150)->nullable();
            $table->string('observations', 500)->nullable();
            $table->foreignId('reservation_id')->nullable()->constrained('reservations_stock');
            $table->timestamps();

            $table->unique(['decharge_id', 'materiel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decharge_lignes');
        Schema::dropIfExists('decharges');
    }
};

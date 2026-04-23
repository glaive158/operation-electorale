<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Table principale des opérations électorales
        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['inscription','modification','changement','radiation']);
            $table->enum('statut', ['en_attente','validee','rejetee'])->default('en_attente');

            // Demandeur (depuis fichier_electoral via API)
            $table->string('nin_demandeur', 20);
            $table->string('nom_demandeur', 100);
            $table->string('prenom_demandeur', 100);
            $table->date('datenaiss_demandeur')->nullable();
            $table->string('lieunaiss_demandeur', 100)->nullable();
            $table->string('tel_demandeur', 20)->nullable();
            $table->string('adresse_demandeur', 200)->nullable();
            $table->boolean('militaire')->default(false);
            $table->boolean('handicap')->default(false);

            // Électeur à radier (radiation uniquement)
            $table->string('nin_electeur_radie', 20)->nullable();
            $table->string('nom_electeur_radie', 100)->nullable();
            $table->string('prenom_electeur_radie', 100)->nullable();
            $table->string('numelec_electeur_radie', 30)->nullable();
            $table->enum('motif_radiation', ['deces','incapacite_juridique','demande_interessee'])->nullable();

            // Infos électorales (inscription/modification)
            $table->unsignedBigInteger('commune_id')->nullable();
            $table->string('commune_nom', 100)->nullable();
            $table->string('departement_nom', 100)->nullable();
            $table->string('adresse_electorale', 200)->nullable();

            // Changement de statut
            $table->enum('statut_changement', ['civil_vers_militaire','militaire_vers_civil'])->nullable();
            $table->boolean('avec_modification')->default(false);

            // Commission qui a enregistré
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('region_id')->nullable();
            $table->unsignedBigInteger('departement_id')->nullable();
            $table->unsignedBigInteger('arrondissement_id')->nullable();
            $table->unsignedBigInteger('commune_commission_id')->nullable();

            $table->text('commentaire')->nullable();
            $table->timestamps();

            $table->index(['type', 'statut']);
            $table->index(['nin_demandeur']);
            $table->index(['commune_commission_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        // Documents attachés aux opérations
        Schema::create('operation_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operation_id');
            $table->enum('type_document', [
                'formulaire_signe','copie_cni','certificat_deces',
                'certificat_residence','decision_justice','attestation_corps',
            ]);
            $table->string('chemin_fichier', 500);
            $table->string('nom_original', 200)->nullable();
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();

            $table->foreign('operation_id')->references('id')->on('operations')->onDelete('cascade');
            $table->index(['operation_id', 'type_document']);
        });

        // Notifications
        Schema::create('notifications_ops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('titre', 200);
            $table->text('message');
            $table->string('type', 50)->default('info');
            $table->unsignedBigInteger('operation_id')->nullable();
            $table->boolean('lue')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'lue', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_documents');
        Schema::dropIfExists('notifications_ops');
        Schema::dropIfExists('operations');
    }
};

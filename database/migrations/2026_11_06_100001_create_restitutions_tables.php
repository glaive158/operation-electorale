<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Restitution du matériel réutilisable après le scrutin : une par détenteur (agent ou destinataire) et par
        // cycle. Le détenteur est un utilisateur pour un agent, un destinataire sinon (pas de clé étrangère : le
        // type en décide). `agent_id` est l'agent désigné pour le retour, distinct du détenteur.
        Schema::create('restitutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('cycles_electoraux');
            $table->enum('detenteur_type', ['agent', 'destinataire']);
            $table->unsignedBigInteger('detenteur_id');
            $table->enum('statut', ['a_preparer', 'en_retour', 'recue_central', 'controlee'])->default('a_preparer');
            $table->foreignId('agent_id')->nullable()->constrained('users');
            $table->foreignId('ouverte_par')->constrained('users');
            $table->timestamps();

            $table->index(['cycle_id', 'detenteur_type', 'detenteur_id']);
        });

        Schema::create('restitution_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restitution_id')->constrained('restitutions');
            $table->foreignId('materiel_id')->constrained('materiels');
            $table->unsignedInteger('attendu')->default(0);
            $table->unsignedInteger('declare_restitue')->default(0);
            $table->unsignedInteger('recu_central')->default(0);
            $table->unsignedInteger('bon')->default(0);
            $table->unsignedInteger('a_reparer')->default(0);
            $table->unsignedInteger('reforme')->default(0);
            $table->string('motif_reforme', 500)->nullable();
            $table->foreignId('reforme_decidee_par')->nullable()->constrained('users');
            $table->string('justification', 500)->nullable();
            $table->timestamps();

            $table->unique(['restitution_id', 'materiel_id']);
        });

        // Déclaration de ce que le détenteur ou l'agent de retour remet : mêmes colonnes que `declarations`
        // (plans 4 et 5), la restitution tenant lieu de remise.
        Schema::create('restitution_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restitution_id')->constrained('restitutions');
            $table->enum('mode', ['tablette', 'compte']);
            $table->string('signataire_nom', 150);
            $table->string('signataire_qualite', 150);
            $table->foreignId('user_id')->constrained('users');
            $table->dateTime('horodatage_terminal')->nullable();
            $table->string('cle_idempotence', 64)->unique();
            $table->char('empreinte_contenu', 64);
            $table->enum('statut', ['valide', 'retenue', 'concordante', 'a_verifier', 'ecartee', 'remplacee'])->default('valide');
            $table->json('anomalies')->nullable();
            $table->enum('verification_identite', ['en_attente', 'verifiee_code', 'verifiee_appareil', 'non_verifiee', 'a_verifier', 'echec', 'compte'])->default('non_verifiee');
            $table->enum('contestation', ['aucune', 'contestee', 'levee'])->default('aucune');
            // Empreinte du bloc chiffré reçu : un bloc ne sert qu'à une seule déclaration.
            $table->char('bloc_empreinte', 64)->nullable()->unique();
            $table->decimal('gps_lat', 9, 6)->nullable();
            $table->decimal('gps_lng', 9, 6)->nullable();
            $table->unsignedInteger('gps_precision')->nullable();
            $table->foreignId('signataire_user_id')->nullable()->constrained('users');
            $table->foreignId('delegation_id')->nullable()->constrained('delegations');
            $table->timestamps();

            $table->index(['restitution_id', 'statut']);
        });

        Schema::create('restitution_declaration_lignes', function (Blueprint $table) {
            $table->id();
            // Noms explicites : le nom par défaut dépasse les 64 caractères de MySQL.
            $table->foreignId('restitution_declaration_id')->constrained('restitution_declarations', 'id', 'rdl_declaration_foreign');
            $table->foreignId('materiel_id')->constrained('materiels');
            $table->unsignedInteger('restitue');
            $table->string('observation', 500)->nullable();
            $table->timestamps();

            $table->unique(['restitution_declaration_id', 'materiel_id'], 'restitution_declaration_lignes_unique');
        });

        // Pièces annoncées par une déclaration de restitution : « attendues » (piece_jointe_id nul) jusqu'à l'envoi.
        Schema::create('restitution_declaration_pieces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restitution_declaration_id')->constrained('restitution_declarations', 'id', 'rdp_declaration_foreign');
            $table->char('empreinte', 64)->index();
            $table->enum('role', ['signature', 'photo']);
            $table->foreignId('piece_jointe_id')->nullable()->constrained('pieces_jointes');
            $table->timestamps();

            $table->unique(['restitution_declaration_id', 'empreinte'], 'restitution_declaration_pieces_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restitution_declaration_pieces');
        Schema::dropIfExists('restitution_declaration_lignes');
        Schema::dropIfExists('restitution_declarations');
        Schema::dropIfExists('restitution_lignes');
        Schema::dropIfExists('restitutions');
    }
};

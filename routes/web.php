<?php

use App\Http\Controllers\AuditCommunesController;
use App\Http\Controllers\AuditElectoralController;
use App\Http\Controllers\AuditRevisionsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CarteElectoraleController;
use App\Http\Controllers\ChargeElectoraleController;
use App\Http\Controllers\ComparaisonFichiersController;
use App\Http\Controllers\ComparaisonLieuxController;
use App\Http\Controllers\ImpactDeplacementController;
use App\Http\Controllers\Logistique\BesoinController;
use App\Http\Controllers\Logistique\CleController;
use App\Http\Controllers\Logistique\CommandeController;
use App\Http\Controllers\Logistique\CommissionController;
use App\Http\Controllers\Logistique\CorrespondanceController;
use App\Http\Controllers\Logistique\CycleController;
use App\Http\Controllers\Logistique\EntiteController;
use App\Http\Controllers\Logistique\GeographieController;
use App\Http\Controllers\Logistique\InstantaneController;
use App\Http\Controllers\Logistique\MaterielController;
use App\Http\Controllers\Logistique\PlanDotationController;
use App\Http\Controllers\Logistique\PosteController;
use App\Http\Controllers\Logistique\StockController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FichierElectoralController;
use App\Http\Controllers\ListeEmargementController;
use App\Http\Controllers\OperationController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/* ── Auth ── */
Route::get('login',  [AuthController::class, 'showLogin'])->name('login');
Route::post('login', [AuthController::class, 'login']);
Route::post('logout',[AuthController::class, 'logout'])->name('logout')->middleware('auth');

/* ── Protected ── */
Route::middleware(['auth'])->group(function () {

    Route::get('/',          [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard', [DashboardController::class, 'index']);

    /* Pages existantes — fermées aux comptes logistique */
    Route::middleware('role:admin,gouverneur,prefet,sous_prefet,commission')->group(function () {

        /* NIN lookup — accessible à tous (utilisé dans le formulaire create) */
        Route::get('/fichier-electoral/nin/{nin}', [FichierElectoralController::class, 'byNin'])->name('fichier-electoral.nin');

        /* Fichier électoral — interdit aux commissions */
        Route::middleware('role:admin,gouverneur,prefet,sous_prefet')->group(function () {
            Route::get('/fichier-electoral',           [FichierElectoralController::class, 'index'])->name('fichier-electoral');
            Route::get('/fichier-electoral/{id}',      [FichierElectoralController::class, 'show'])->name('fichier-electoral.show');

            /* Carte électorale */
            Route::get('/carte-electorale',              [CarteElectoraleController::class, 'index'])->name('carte-electorale');
            Route::get('/carte-electorale/api/pays',     [CarteElectoraleController::class, 'apiPays']);
            Route::get('/carte-electorale/api/villes/{pays}', [CarteElectoraleController::class, 'apiVilles']);

            /* Charge électorale */
            Route::get('/charge-electorale',             [ChargeElectoraleController::class, 'index'])->name('charge-electorale');
            Route::get('/charge-electorale/bureau/{codeBureau}', [ChargeElectoraleController::class, 'showBureau'])->name('charge-electorale.bureau');

            /* Liste d'émargement */
            Route::get('/liste-emargement',              [ListeEmargementController::class, 'index'])->name('liste-emargement');
            Route::post('/liste-emargement/generate',    [ListeEmargementController::class, 'generate'])->name('liste-emargement.generate');
            Route::post('/liste-emargement/generate-stream', [ListeEmargementController::class, 'generateStream'])->name('liste-emargement.generate-stream');
            Route::post('/liste-emargement/generate-zip', [ListeEmargementController::class, 'generateZip'])->name('liste-emargement.generate-zip');

            /* Comparaison lieux de vote (ancien vs nouveau) */
            Route::get('/comparaison-lieux',         [ComparaisonLieuxController::class, 'index'])->name('comparaison-lieux');
            Route::get('/comparaison-lieux/export',  [ComparaisonLieuxController::class, 'export'])->name('comparaison-lieux.export');

            /* Comparaison fichiers (3 tableaux côte à côte) */
            Route::get('/comparaison-fichiers', [ComparaisonFichiersController::class, 'index'])->name('comparaison-fichiers');

            /* Impact déplacement bureaux sur électeurs */
            Route::get('/impact-deplacement', [ImpactDeplacementController::class, 'index'])->name('impact-deplacement');

            /* Audit révisions 2025 */
            Route::get('/audit-revisions', [AuditRevisionsController::class, 'index'])->name('audit-revisions');

            /* Audit fichier électoral (disparus / nouveaux / changements bureau) */
            Route::get('/audit-electoral', [AuditElectoralController::class, 'index'])->name('audit-electoral');
            Route::get('/audit-electoral/export/{type}', [AuditElectoralController::class, 'export'])->name('audit-electoral.export');

            /* Audit 76 incohérences commune (cartenats vs CSV) */
            Route::get('/audit-communes', [AuditCommunesController::class, 'index'])->name('audit-communes');
            Route::get('/audit-communes/export', [AuditCommunesController::class, 'export'])->name('audit-communes.export');
            Route::get('/liste-emargement/api/lieux/{communeId}', [ListeEmargementController::class, 'apiLieuxVote']);
            Route::get('/liste-emargement/api/bureaux/{lieuVote}', [ListeEmargementController::class, 'apiBureaux']);
            Route::get('/liste-emargement/api/count',    [ListeEmargementController::class, 'apiCount']);
        });

        /* Cascade géographique (JSON) — accessible à tous pour le formulaire create */
        Route::get('/geo/region/{regionId}/departements',      [FichierElectoralController::class, 'geoRegionDepts']);
        Route::get('/geo/departement/{deptId}/arrondissements',[FichierElectoralController::class, 'geoDeptArrs']);
        Route::get('/geo/arrondissement/{arrId}/communes',     [FichierElectoralController::class, 'geoArrCommunes']);
        Route::get('/geo/commune/adresses',                    [FichierElectoralController::class, 'geoAdressesByCommune']);

        /* Opérations */
        Route::resource('operations', OperationController::class);
        Route::get('/operations/{operation}/imprimer',        [OperationController::class, 'imprimer'])->name('operations.imprimer');
        Route::get('/operations/{operation}/scanner',         [OperationController::class, 'scanner'])->name('operations.scanner');
        Route::post('/operations/{operation}/scanner',        [OperationController::class, 'scannerStore'])->name('operations.scanner.store');
        Route::patch('/operations/{operation}/valider', [OperationController::class, 'valider'])
             ->name('operations.valider')
             ->middleware('role:admin,prefet,sous_prefet,gouverneur');
        Route::patch('/operations/{operation}/rejeter', [OperationController::class, 'rejeter'])
             ->name('operations.rejeter')
             ->middleware('role:admin,prefet,sous_prefet,gouverneur');

        /* Users — admin only */
        Route::resource('users', UserController::class)->middleware('role:admin');
    });

    /* ── Logistique matériel électoral ── */
    Route::middleware('can:logistique-gerer')->prefix('logistique')->name('logistique.')->group(function () {
        Route::get('stock', [StockController::class, 'index'])->name('stock.index');
        Route::get('stock/{materiel}/mouvements', [StockController::class, 'mouvements'])->name('stock.mouvements');
        Route::post('stock/inventaire', [StockController::class, 'inventaire'])->name('stock.inventaire');

        Route::resource('materiels', MaterielController::class)->except(['show', 'destroy']);

        Route::get('commandes', [CommandeController::class, 'index'])->name('commandes.index');
        Route::get('commandes/create', [CommandeController::class, 'create'])->name('commandes.create');
        Route::post('commandes', [CommandeController::class, 'store'])->name('commandes.store');
        Route::get('commandes/{commande}', [CommandeController::class, 'show'])->name('commandes.show');
        Route::post('commandes/{commande}/confirmation', [CommandeController::class, 'confirmer'])->name('commandes.confirmer');
        Route::post('commandes/{commande}/receptions', [CommandeController::class, 'recevoir'])->name('commandes.receptions.store');

        Route::get('destinataires', [GeographieController::class, 'destinataires'])->name('destinataires.index');
        Route::get('cycles/{cycle}/decoupage', [GeographieController::class, 'decoupage'])->name('decoupage.show');
        Route::get('correspondances', [CorrespondanceController::class, 'index'])->name('correspondances.index');
        Route::get('cycles/{cycle}/commissions', [CommissionController::class, 'index'])->name('commissions.index');
        Route::get('cycles/{cycle}/instantanes', [InstantaneController::class, 'index'])->name('instantanes.index');
        Route::post('cycles/{cycle}/instantanes', [InstantaneController::class, 'store'])->name('instantanes.store');
        Route::get('instantanes/{instantane}', [InstantaneController::class, 'show'])->name('instantanes.show');
        Route::post('instantanes/{instantane}/fige', [InstantaneController::class, 'figer'])->name('instantanes.figer');
        Route::get('cycles/{cycle}/entites', [EntiteController::class, 'index'])->name('entites.index');
        Route::get('cycles/{cycle}/cles', [CleController::class, 'index'])->name('cles.index');
        Route::get('cycles/{cycle}/plans', [PlanDotationController::class, 'index'])->name('plans.index');
        Route::post('cycles/{cycle}/plans', [PlanDotationController::class, 'store'])->name('plans.store');
        Route::get('plans/{plan}', [PlanDotationController::class, 'show'])->name('plans.show');
        Route::get('cycles/{cycle}/besoin', [BesoinController::class, 'show'])->name('besoin.show');

        Route::middleware('can:logistique-administrer')->group(function () {
            Route::post('mouvements/{mouvement}/compensation', [StockController::class, 'compenser'])->name('mouvements.compenser');
            Route::put('cycles/{cycle}/attributions/{materiel}', [StockController::class, 'attribuer'])->name('attributions.update');
            Route::post('cycles/{cycle}/cloture', [CycleController::class, 'cloturer'])->name('cycles.cloturer');
            Route::post('commandes/{commande}/annulation', [CommandeController::class, 'annuler'])->name('commandes.annuler');
            Route::post('commande-lignes/{ligne}/abandon', [CommandeController::class, 'abandonner'])->name('commande-lignes.abandonner');

            Route::resource('cycles', CycleController::class)->only(['create', 'store', 'edit', 'update']);

            Route::resource('postes', PosteController::class)->only(['index', 'create', 'store', 'edit', 'update']);
            Route::post('destinataires/generation', [GeographieController::class, 'genererDestinataires'])->name('destinataires.generer');
            Route::put('destinataires/{destinataire}', [GeographieController::class, 'modifierDestinataire'])->name('destinataires.update');
            Route::post('cycles/{cycle}/decoupage/generation', [GeographieController::class, 'genererPerimetre'])->name('decoupage.generer');
            Route::post('cycles/{cycle}/decoupage/affectation', [GeographieController::class, 'affecter'])->name('decoupage.affecter');
            Route::post('cycles/{cycle}/decoupage/copie', [GeographieController::class, 'copier'])->name('decoupage.copier');
            Route::delete('cycles/{cycle}/decoupage/localite', [GeographieController::class, 'retirer'])->name('decoupage.retirer');

            Route::post('correspondances/rapprochement', [CorrespondanceController::class, 'rapprocher'])->name('correspondances.rapprocher');
            Route::post('correspondances/{correspondance}/validation', [CorrespondanceController::class, 'valider'])->name('correspondances.valider');
            Route::post('correspondances/{correspondance}/rejet', [CorrespondanceController::class, 'rejeter'])->name('correspondances.rejeter');

            Route::post('cycles/{cycle}/commissions', [CommissionController::class, 'store'])->name('commissions.store');
            Route::post('cycles/{cycle}/commissions/fixes', [CommissionController::class, 'fixes'])->name('commissions.fixes');
            Route::delete('cycles/{cycle}/commissions/{commission}', [CommissionController::class, 'destroy'])->name('commissions.destroy');

            Route::post('cycles/{cycle}/entites', [EntiteController::class, 'store'])->name('entites.store');
            Route::put('cycles/{cycle}/entites/{entite}', [EntiteController::class, 'update'])->name('entites.update');

            Route::put('cycles/{cycle}/cles/{materiel}', [CleController::class, 'update'])->name('cles.update');
            Route::post('cycles/{cycle}/cles/copie', [CleController::class, 'copier'])->name('cles.copier');

            Route::post('plan-lignes/{ligne}/correction', [PlanDotationController::class, 'corriger'])->name('plan-lignes.corriger');
            Route::post('plan-lignes/{ligne}/annulation-correction', [PlanDotationController::class, 'annulerCorrection'])->name('plan-lignes.annuler-correction');
            Route::post('plans/{plan}/approbation', [PlanDotationController::class, 'approuver'])->name('plans.approuver');

            Route::post('cycles/{cycle}/besoin/commande', [BesoinController::class, 'commander'])->name('besoin.commander');
        });
        Route::resource('cycles', CycleController::class)->only(['index']);
    });

});

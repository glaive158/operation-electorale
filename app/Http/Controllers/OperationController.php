<?php

namespace App\Http\Controllers;

use App\Models\NotificationOp;
use App\Models\Operation;
use App\Models\User;
use App\Services\FichierElectoralService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OperationController extends Controller
{
    public function __construct(private FichierElectoralService $fe) {}

    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Operation::with('user')->latest();

        /* Zone filtering */
        if ($user->isCommission())     $query->where('commune_commission_id', $user->commune_id);
        elseif ($user->isSousPrefet()) $query->where('arrondissement_id', $user->arrondissement_id);
        elseif ($user->isPrefet())     $query->where('departement_id', $user->departement_id);
        elseif ($user->isGouverneur()) $query->where('region_id', $user->region_id);

        /* Filters */
        if ($request->filled('type'))   $query->where('type', $request->type);
        if ($request->filled('statut')) $query->where('statut', $request->statut);
        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(fn($sub) =>
                $sub->where('nin_demandeur', 'like', "%$q%")
                    ->orWhere('nom_demandeur', 'like', "%$q%")
                    ->orWhere('prenom_demandeur', 'like', "%$q%")
            );
        }
        if ($request->filled('du'))  $query->whereDate('created_at', '>=', $request->du);
        if ($request->filled('au'))  $query->whereDate('created_at', '<=', $request->au);

        $operations = $query->paginate(20)->withQueryString();

        return view('operations.index', compact('operations'));
    }

    public function create(Request $request)
    {
        $type = $request->query('type', 'inscription');
        if (!in_array($type, ['inscription','modification','changement','radiation'])) {
            $type = 'inscription';
        }
        $user    = auth()->user();
        $regions = $user->isAdmin()
            ? DB::connection('recensement')->table('regions')->orderBy('nom')->get(['id', 'nom'])
            : collect();
        return view('operations.create', compact('type', 'user', 'regions'));
    }

    public function store(Request $request)
    {
        $type = $request->input('type');

        $base = [
            'type'               => 'required|in:inscription,modification,changement,radiation',
            'nin_demandeur'      => 'required|string|max:20',
            'nom_demandeur'      => 'required|string|max:100',
            'prenom_demandeur'   => 'required|string|max:100',
            'datenaiss_demandeur'=> 'nullable|date',
            'lieunaiss_demandeur'=> 'nullable|string|max:100',
            'tel_demandeur'      => 'nullable|string|max:20',
            'adresse_demandeur'  => 'nullable|string|max:200',
            'militaire'          => 'boolean',
            'handicap'           => 'boolean',
        ];

        $typeRules = match($type) {
            'inscription', 'modification' => [
                'commune_id'        => 'required|integer',
                'commune_nom'       => 'required|string|max:100',
                'departement_nom'   => 'required|string|max:100',
                'adresse_electorale'=> 'nullable|string|max:200',
            ],
            'radiation' => [
                'nin_electeur_radie'     => 'required|string|max:20',
                'nom_electeur_radie'     => 'required|string|max:100',
                'prenom_electeur_radie'  => 'required|string|max:100',
                'numelec_electeur_radie' => 'nullable|string|max:30',
                'motif_radiation'        => 'required|in:deces,incapacite_juridique,demande_interessee',
            ],
            'changement' => [
                'statut_changement' => 'required|in:civil_vers_militaire,militaire_vers_civil',
                'avec_modification' => 'boolean',
            ],
            default => [],
        };

        $data = $request->validate(array_merge($base, $typeRules));
        $data['militaire']         = $request->boolean('militaire');
        $data['handicap']          = $request->boolean('handicap');
        $data['avec_modification'] = $request->boolean('avec_modification');

        $user = auth()->user();
        $data['user_id']               = $user->id;
        $data['region_id']             = $user->region_id;
        $data['departement_id']        = $user->departement_id;
        $data['arrondissement_id']     = $user->arrondissement_id;
        $data['commune_commission_id'] = $user->commune_id;

        $operation = Operation::create($data);

        /* Handle documents */
        foreach (['formulaire_signe','copie_cni','certificat_deces','certificat_residence','decision_justice','attestation_corps'] as $docType) {
            if ($request->hasFile($docType)) {
                $path = $request->file($docType)->store("operations/{$operation->id}", 'local');
                $operation->documents()->create([
                    'type_document' => $docType,
                    'chemin_fichier'=> $path,
                    'nom_original'  => $request->file($docType)->getClientOriginalName(),
                    'uploaded_by'   => $user->id,
                ]);
            }
        }

        /* Notify validators */
        $validators = User::where('actif', true)
            ->whereIn('role', ['admin','prefet','sous_prefet','gouverneur'])
            ->when($user->region_id, fn($q) => $q->where(fn($s) =>
                $s->whereNull('region_id')->orWhere('region_id', $user->region_id)
            ))
            ->limit(20)->get();

        $typeLabel = Operation::$typeLabels[$type] ?? $type;
        foreach ($validators as $v) {
            NotificationOp::create([
                'user_id'      => $v->id,
                'titre'        => "Nouvelle demande — {$typeLabel}",
                'message'      => "NIN: {$data['nin_demandeur']} — {$data['prenom_demandeur']} {$data['nom_demandeur']}",
                'type'         => 'info',
                'operation_id' => $operation->id,
            ]);
        }

        return redirect()->route('operations.imprimer', $operation)
                         ->with('success', 'Opération enregistrée. Imprimez le formulaire puis scannez les pièces.');
    }

    public function show(Operation $operation)
    {
        $operation->load('documents','user');
        return view('operations.show', compact('operation'));
    }

    public function imprimer(Operation $operation)
    {
        $operation->load('user');
        return view('operations.imprimer', compact('operation'));
    }

    public function scanner(Operation $operation)
    {
        if ($operation->documents_complets) {
            return redirect()->route('operations.show', $operation)
                             ->with('info', 'Documents déjà soumis.');
        }
        return view('operations.scanner', compact('operation'));
    }

    public function scannerStore(Request $request, Operation $operation)
    {
        if ($operation->documents_complets) {
            return redirect()->route('operations.show', $operation);
        }

        $rules = [
            'formulaire_signe' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'copie_cni'        => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ];
        if ($operation->type === 'modification') {
            $rules['certificat_residence'] = 'required|file|mimes:pdf,jpg,jpeg,png|max:10240';
        }

        $request->validate($rules);

        $user = auth()->user();
        foreach (['formulaire_signe','copie_cni','certificat_residence'] as $docType) {
            if ($request->hasFile($docType)) {
                $path = $request->file($docType)->store("operations/{$operation->id}", 'local');
                $operation->documents()->create([
                    'type_document'  => $docType,
                    'chemin_fichier' => $path,
                    'nom_original'   => $request->file($docType)->getClientOriginalName(),
                    'uploaded_by'    => $user->id,
                ]);
            }
        }

        $operation->update(['documents_complets' => true]);

        return redirect()->route('operations.show', $operation)
                         ->with('success', 'Documents enregistrés. Demande complète.');
    }

    public function edit(Operation $operation)
    {
        $this->authorizeEdit($operation);
        return view('operations.edit', compact('operation'));
    }

    public function update(Request $request, Operation $operation)
    {
        $this->authorizeEdit($operation);

        $data = $request->validate([
            'commentaire'   => 'nullable|string|max:1000',
            'tel_demandeur' => 'nullable|string|max:20',
        ]);

        $operation->update($data);
        return redirect()->route('operations.show', $operation)->with('success', 'Opération mise à jour.');
    }

    public function destroy(Operation $operation)
    {
        $this->authorizeEdit($operation);
        $operation->delete();
        return redirect()->route('operations.index')->with('success', 'Opération supprimée.');
    }

    public function valider(Request $request, Operation $operation)
    {
        if ($operation->statut !== 'en_attente') {
            return back()->with('error', 'Opération déjà traitée.');
        }
        $operation->update(['statut' => 'validee', 'commentaire' => $request->commentaire]);

        NotificationOp::create([
            'user_id'      => $operation->user_id,
            'titre'        => 'Demande validée',
            'message'      => "Votre demande de {$operation->type_label} a été validée.",
            'type'         => 'success',
            'operation_id' => $operation->id,
        ]);

        return back()->with('success', 'Opération validée.');
    }

    public function rejeter(Request $request, Operation $operation)
    {
        if ($operation->statut !== 'en_attente') {
            return back()->with('error', 'Opération déjà traitée.');
        }
        $request->validate(['commentaire' => 'required|string|max:500']);
        $operation->update(['statut' => 'rejetee', 'commentaire' => $request->commentaire]);

        NotificationOp::create([
            'user_id'      => $operation->user_id,
            'titre'        => 'Demande rejetée',
            'message'      => "Votre demande de {$operation->type_label} a été rejetée. Motif: {$request->commentaire}",
            'type'         => 'error',
            'operation_id' => $operation->id,
        ]);

        return back()->with('success', 'Opération rejetée.');
    }

    private function authorizeEdit(Operation $operation): void
    {
        $user = auth()->user();
        if (!$user->isAdmin() && $operation->user_id !== $user->id) {
            abort(403);
        }
        if ($operation->statut !== 'en_attente') {
            abort(403, 'Impossible de modifier une opération déjà traitée.');
        }
    }
}

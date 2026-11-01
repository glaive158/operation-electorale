<?php

namespace App\Http\Controllers\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Http\Controllers\Controller;
use App\Models\Logistique\Commande;
use App\Models\Logistique\CommandeLigne;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Materiel;
use App\Services\Logistique\CommandeService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CommandeController extends Controller
{
    use ExecuteAction;

    public function __construct(private readonly CommandeService $commandes)
    {
    }

    public function index(Request $request)
    {
        $commandes = Commande::with('lignes')
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->statut))
            ->orderByDesc('date_commande')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        return view('logistique.commandes.index', compact('commandes'));
    }

    public function create()
    {
        return view('logistique.commandes.create', [
            'materiels' => Materiel::actifs()->orderBy('categorie')->orderBy('ordre')->orderBy('libelle')->get(),
            'cycles'    => Cycle::ouverts()->orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $donnees = $request->validate([
            'reference'                     => 'required|string|max:50',
            'fournisseur'                   => 'required|string|max:200',
            'date_commande'                 => 'required|date_format:Y-m-d',
            'lignes'                        => 'required|array',
            'lignes.*.materiel_id'          => 'nullable|integer',
            'lignes.*.quantite_commandee'   => 'nullable|integer|min:1|max:100000000',
            'lignes.*.cycle_id'             => 'nullable|integer',
        ]);

        // Les lignes laissées vides dans le formulaire sont ignorées.
        $lignes = collect($donnees['lignes'])
            ->filter(fn ($l) => filled($l['materiel_id'] ?? null) || filled($l['quantite_commandee'] ?? null))
            ->map(fn ($l) => [
                'materiel_id'        => $l['materiel_id'] ?? null,
                'quantite_commandee' => $l['quantite_commandee'] ?? null,
                'cycle_id'           => filled($l['cycle_id'] ?? null) ? $l['cycle_id'] : null,
            ])
            ->values()->all();

        try {
            $commande = $this->commandes->creer([
                'reference'     => $donnees['reference'],
                'fournisseur'   => $donnees['fournisseur'],
                'date_commande' => $donnees['date_commande'],
            ], $lignes);
        } catch (RegleLogistique|InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('logistique.commandes.show', $commande)->with('success', 'Commande créée en brouillon.');
    }

    public function show(Commande $commande)
    {
        $commande->load(['lignes.materiel', 'lignes.cycle', 'receptions.lignes.ligneCommande.materiel', 'receptions.auteur', 'auteur']);

        $reste = $commande->lignes->sum(fn (CommandeLigne $l) => $l->resteARecevoir());

        return view('logistique.commandes.show', compact('commande', 'reste'));
    }

    public function confirmer(Commande $commande)
    {
        return $this->executer(fn () => $this->commandes->confirmer($commande), 'Commande confirmée.');
    }

    public function annuler(Request $request, Commande $commande)
    {
        $donnees = $request->validate(['motif' => 'required|string|max:500']);

        return $this->executer(fn () => $this->commandes->annuler($commande, $donnees['motif']), 'Commande annulée.');
    }

    public function recevoir(Request $request, Commande $commande)
    {
        $donnees = $request->validate([
            'date_reception'     => 'nullable|date_format:Y-m-d',
            'bon_livraison'      => 'required|string|max:100',
            'lignes'             => 'required|array',
            'lignes.*.acceptee'  => 'nullable|integer|min:0|max:100000000',
            'lignes.*.rejetee'   => 'nullable|integer|min:0|max:100000000',
        ]);

        // Seules les lignes renseignées sont réceptionnées ; une quantité absente vaut 0.
        $lignes = collect($donnees['lignes'])
            ->map(fn ($l) => ['acceptee' => (int) ($l['acceptee'] ?? 0), 'rejetee' => (int) ($l['rejetee'] ?? 0)])
            ->filter(fn ($l) => $l['acceptee'] + $l['rejetee'] > 0)
            ->all();

        return $this->executer(
            fn () => $this->commandes->recevoir($commande, [
                'date_reception' => $donnees['date_reception'] ?? now()->format('Y-m-d'),
                'bon_livraison'  => $donnees['bon_livraison'],
            ], $lignes),
            'Réception enregistrée.',
        );
    }

    public function abandonner(Request $request, CommandeLigne $ligne)
    {
        $donnees = $request->validate([
            'quantite' => 'required|integer|min:1|max:100000000',
            'motif'    => 'required|string|max:500',
        ]);

        return $this->executer(
            fn () => $this->commandes->abandonner($ligne, (int) $donnees['quantite'], $donnees['motif']),
            'Reliquat abandonné.',
        );
    }
}

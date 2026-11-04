<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Remise;
use App\Services\Logistique\LibellesRemises;

/** Réceptions à vérifier : les remises dont les déclarations divergent, avec ces déclarations côte à côte. */
class DeclarationController extends Controller
{
    public function __construct(private readonly LibellesRemises $libelles) {}

    public function aVerifier(Cycle $cycle)
    {
        $remises = Remise::whereIn('decharge_id', Decharge::where('cycle_id', $cycle->id)->select('id'))
            ->where('statut', 'a_verifier')
            ->with([
                'decharge.destinataire', 'lignes.materiel',
                'declarations' => fn ($q) => $q->whereNotIn('statut', ['ecartee', 'remplacee']),
                'declarations.lignes.materiel', 'declarations.user', 'declarations.signataireUser', 'declarations.delegation.autorite', 'declarations.pieces',
            ])
            ->orderBy('id')
            ->paginate(25)->withQueryString();

        return view('logistique.declarations.a-verifier', [
            'cycle' => $cycle,
            'remises' => $remises,
            'parties' => $this->libelles->pour($remises->getCollection()),
        ]);
    }
}

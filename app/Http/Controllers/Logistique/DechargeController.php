<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Logistique\Decharge;

class DechargeController extends Controller
{
    public function show(Decharge $decharge)
    {
        $decharge->load(['cycle', 'destinataire', 'remisA', 'plan']);

        return view('logistique.decharges.show', compact('decharge'));
    }

    /** Cible du QR code imprimé sur le document : retrouve la décharge par son identifiant stable. */
    public function qr(string $identifiant)
    {
        $decharge = Decharge::where('identifiant', $identifiant)->firstOrFail();

        return redirect()->route('logistique.decharges.show', $decharge);
    }
}

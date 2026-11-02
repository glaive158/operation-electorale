<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Logistique\Cycle;
use App\Services\Logistique\EcartVersionService;

/** Écarts entre les décharges validées et le plan de dotation approuvé courant. */
class EcartController extends Controller
{
    public function __construct(private readonly EcartVersionService $ecarts)
    {
    }

    public function index(Cycle $cycle)
    {
        return view('logistique.ecarts.index', [
            'cycle' => $cycle,
            'ecarts' => $this->ecarts->analyser($cycle),
        ]);
    }
}

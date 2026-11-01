<?php

namespace App\Http\Controllers\Logistique;

use App\Http\Controllers\Controller;

class StockController extends Controller
{
    public function index()
    {
        return view('logistique.stock.index', ['lignes' => collect()]);
    }
}

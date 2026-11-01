<?php

namespace App\Http\Controllers\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

/**
 * Exécute une opération des services logistiques : une règle métier refusée
 * revient au formulaire avec son message, sans erreur 500.
 */
trait ExecuteAction
{
    private function executer(callable $action, string $succes): RedirectResponse
    {
        try {
            $action();
        } catch (RegleLogistique|InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $succes);
    }
}

<?php

namespace App\Http\Controllers\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use Closure;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

/**
 * Exécute une opération des services logistiques : une règle métier refusée
 * revient au formulaire avec son message, sans erreur 500.
 */
trait ExecuteAction
{
    /** $succes peut être une fonction du résultat de l'action, pour un message qui porte un compte. */
    private function executer(callable $action, string|Closure $succes): RedirectResponse
    {
        try {
            $resultat = $action();
        } catch (RegleLogistique|InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $succes instanceof Closure ? $succes($resultat) : $succes);
    }
}

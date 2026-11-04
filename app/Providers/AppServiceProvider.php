<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('logistique-gerer', fn (User $user) => $user->hasRole(['admin', 'logistique_gestionnaire', 'logistique_responsable']));
        Gate::define('logistique-administrer', fn (User $user) => $user->hasRole(['admin', 'logistique_responsable']));
        Gate::define('logistique-terrain', fn (User $user) => $user->actif && $user->hasRole(['prefet', 'sous_prefet', 'chef_poste', 'agent_transport']));
        // Suivi (indicateurs) : les autorités de terrain et le gouverneur, qui ne voit que les indicateurs de sa région.
        Gate::define('logistique-suivi-terrain', fn (User $user) => $user->actif && $user->hasRole(['prefet', 'sous_prefet', 'chef_poste', 'gouverneur']));
    }
}

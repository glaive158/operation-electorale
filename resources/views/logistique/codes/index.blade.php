@extends('layouts.app')
@section('title','Codes de réception')
@section('page-title','Codes de réception')

@php
    use App\Models\User;
    $libellesRole = ['prefet' => 'Préfet', 'sous_prefet' => 'Sous-préfet', 'chef_poste' => 'Chef de poste'];
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Autorités de terrain</h3>
            <p class="text-xs text-slate-500">Le code est personnel et n'est jamais visible. Réinitialiser un code (oubli, changement de titulaire, compte verrouillé) oblige l'autorité à en choisir un nouveau à sa prochaine connexion.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-slate-500 border-b border-slate-100">
                        <th class="px-4 py-2 font-medium">Compte</th>
                        <th class="px-4 py-2 font-medium">Rôle</th>
                        <th class="px-4 py-2 font-medium">État du code</th>
                        <th class="px-4 py-2 font-medium">Réinitialisation</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($comptes as $compte)
                        <tr class="border-b border-slate-50 align-top">
                            <td class="px-4 py-2">
                                <p class="text-slate-800">{{ $compte->nom_complet }}</p>
                                <p class="text-xs text-slate-500">{{ $compte->email }}@unless($compte->actif) — compte inactif @endunless</p>
                            </td>
                            <td class="px-4 py-2 text-slate-600">{{ $libellesRole[$compte->role] ?? $compte->role }}</td>
                            <td class="px-4 py-2">
                                @if($compte->code_verrouille_le)
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">Verrouillé le {{ $compte->code_verrouille_le->format('d/m/Y H:i') }}</span>
                                @elseif($compte->code_reception_hash)
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">Choisi</span>
                                    @if($compte->code_echecs > 0)<span class="text-xs text-slate-500">{{ $compte->code_echecs }} erreur(s)</span>@endif
                                @else
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700">À choisir</span>
                                @endif
                            </td>
                            <td class="px-4 py-2">
                                <form method="POST" action="{{ route('logistique.utilisateurs.code.reinitialiser', $compte) }}" class="flex flex-wrap gap-2 items-center"
                                      onsubmit="return confirm('Réinitialiser le code de {{ addslashes($compte->nom_complet) }} ?');">
                                    @csrf
                                    <input type="text" name="motif" required maxlength="500" placeholder="Motif" aria-label="Motif de la réinitialisation pour {{ $compte->nom_complet }}"
                                           class="px-2 py-1 rounded-lg border border-slate-300 text-sm focus:outline-none">
                                    <button type="submit" class="px-3 py-1 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#EE1C25;">Réinitialiser</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400">Aucune autorité de terrain.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@extends('layouts.app')
@section('title','Mon code de réception')
@section('page-title','Mon code de réception')

@php
    $champ = 'w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none';
@endphp

@section('content')
<div class="max-w-xl mx-auto space-y-5">
    @include('logistique._erreurs')

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-4">
        <div>
            <h3 class="font-semibold text-slate-800">{{ $premierAcces ? 'Choisir mon code de réception' : ($aUnCode ? 'Changer mon code de réception' : 'Choisir mon code de réception') }}</h3>
            <p class="text-sm text-slate-600 mt-1">
                Ce code à 6 chiffres vous est personnel : vous le saisissez sur la tablette pour signer une réception.
                Ne le communiquez à personne, même au service logistique, qui ne peut que le réinitialiser.
            </p>
            @if($premierAcces)
                <p class="text-sm text-slate-600 mt-1">Pour votre première connexion, choisissez aussi un nouveau mot de passe.</p>
            @endif
        </div>

        <form method="POST" action="{{ route('terrain.code.update') }}" class="space-y-4" autocomplete="off">
            @csrf
            <div>
                <label for="password" class="block text-xs text-slate-500 mb-1">Mot de passe actuel</label>
                <input type="password" id="password" name="password" required autocomplete="current-password" class="{{ $champ }}">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="nouveau_mot_de_passe" class="block text-xs text-slate-500 mb-1">Nouveau mot de passe (10 caractères au moins){{ $premierAcces ? '' : ' — facultatif' }}</label>
                    <input type="password" id="nouveau_mot_de_passe" name="nouveau_mot_de_passe" minlength="10" maxlength="200" @required($premierAcces) autocomplete="new-password" class="{{ $champ }}">
                </div>
                <div>
                    <label for="nouveau_mot_de_passe_confirmation" class="block text-xs text-slate-500 mb-1">Confirmer le nouveau mot de passe</label>
                    <input type="password" id="nouveau_mot_de_passe_confirmation" name="nouveau_mot_de_passe_confirmation" minlength="10" maxlength="200" @required($premierAcces) autocomplete="new-password" class="{{ $champ }}">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="code" class="block text-xs text-slate-500 mb-1">Code (6 chiffres)</label>
                    <input type="password" id="code" name="code" required inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="off" class="{{ $champ }} tracking-widest">
                </div>
                <div>
                    <label for="code_confirmation" class="block text-xs text-slate-500 mb-1">Confirmer le code</label>
                    <input type="password" id="code_confirmation" name="code_confirmation" required inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="off" class="{{ $champ }} tracking-widest">
                </div>
            </div>
            <p class="text-xs text-slate-500">Évitez les codes évidents (six chiffres identiques, 123456, 654321) et ne reprenez pas votre mot de passe.</p>

            <div class="flex justify-end">
                <button type="submit" class="px-6 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Enregistrer</button>
            </div>
        </form>
    </div>
</div>
@endsection

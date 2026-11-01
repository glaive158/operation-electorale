@extends('layouts.app')
@section('title','Modifier le poste')
@section('page-title','Modifier le poste')

@section('content')
<div class="max-w-3xl mx-auto">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
        <div class="mb-4">@include('logistique._erreurs')</div>
        <form method="POST" action="{{ route('logistique.postes.update', $poste) }}" class="space-y-5">
            @csrf @method('PUT')
            @include('logistique.postes._form')
            <div class="flex justify-between pt-2">
                <a href="{{ route('logistique.postes.index') }}" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">Annuler</a>
                <button type="submit" class="px-6 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Enregistrer</button>
            </div>
        </form>
    </div>
</div>
@endsection

@extends('layouts.app')
@section('title','Modifier l’article')
@section('page-title','Modifier l’article')

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
        <h3 class="font-semibold text-slate-800 mb-5">Modifier — {{ $materiel->libelle }}</h3>

        @if($errors->any())
            <div class="mb-4 p-4 rounded-xl text-sm text-white" style="background:#EE1C25;">
                @foreach($errors->all() as $e) <p>• {{ $e }}</p> @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('logistique.materiels.update', $materiel) }}" class="space-y-4">
            @csrf @method('PUT')
            @include('logistique.materiels._form')
            <div class="flex justify-between pt-2">
                <a href="{{ route('logistique.materiels.index') }}" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">Annuler</a>
                <button type="submit" class="px-6 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Enregistrer</button>
            </div>
        </form>
    </div>
</div>
@endsection

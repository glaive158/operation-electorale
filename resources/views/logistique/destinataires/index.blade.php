@extends('layouts.app')
@section('title','Destinataires')
@section('page-title','Destinataires du matériel')

@php
    $libellesType = ['prefecture' => 'Préfecture', 'sous_prefecture' => 'Sous-préfecture', 'poste' => 'Poste à l\'étranger'];
    $peutAdministrer = \Illuminate\Support\Facades\Gate::allows('logistique-administrer');
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    <form method="GET" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-center">
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Rechercher un libellé" aria-label="Rechercher un libellé"
                   class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
            <select name="type" aria-label="Type" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <option value="">Tous les types</option>
                @foreach($libellesType as $v => $l)
                    <option value="{{ $v }}" {{ request('type') === $v ? 'selected' : '' }}>{{ $l }}</option>
                @endforeach
            </select>
            <div class="flex gap-2 md:col-start-4">
                <button class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 flex-1" style="background:#009A44;">Filtrer</button>
                <a href="{{ route('logistique.destinataires.index') }}" class="px-3 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">↺</a>
            </div>
        </div>
    </form>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $destinataires->total() }} destinataire(s)</h3>
            @if($peutAdministrer)
                <form method="POST" action="{{ route('logistique.destinataires.generer') }}">
                    @csrf
                    <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Générer les destinataires</button>
                </form>
            @endif
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Destinataire</th>
                        <th class="px-5 py-3 text-left font-semibold">Type</th>
                        <th class="px-5 py-3 text-left font-semibold">Préfecture</th>
                        <th class="px-5 py-3 text-left font-semibold">Signataire</th>
                        <th class="px-5 py-3 text-left font-semibold">Qualité</th>
                        <th class="px-5 py-3 text-left font-semibold">Actif</th>
                        @if($peutAdministrer)<th class="px-5 py-3 text-left font-semibold">Actions</th>@endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($destinataires as $d)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $d->libelle }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $libellesType[$d->type] ?? $d->type }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $d->prefecture?->libelle ?? '—' }}</td>
                            @if($peutAdministrer)
                                <td class="px-5 py-3"><input form="dest-{{ $d->id }}" type="text" name="signataire_nom" value="{{ $d->signataire_nom }}" maxlength="150" aria-label="Signataire" class="w-44 px-2 py-1 rounded-lg border border-slate-300 text-xs focus:outline-none"></td>
                                <td class="px-5 py-3"><input form="dest-{{ $d->id }}" type="text" name="signataire_qualite" value="{{ $d->signataire_qualite }}" maxlength="150" aria-label="Qualité du signataire" class="w-44 px-2 py-1 rounded-lg border border-slate-300 text-xs focus:outline-none"></td>
                                <td class="px-5 py-3"><input form="dest-{{ $d->id }}" type="checkbox" name="actif" value="1" {{ $d->actif ? 'checked' : '' }} aria-label="Actif" class="rounded border-slate-300" style="accent-color:#009A44;"></td>
                                <td class="px-5 py-3"><button form="dest-{{ $d->id }}" type="submit" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">Enregistrer</button></td>
                            @else
                                <td class="px-5 py-3 text-slate-600">{{ $d->signataire_nom ?? '—' }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $d->signataire_qualite ?? '—' }}</td>
                                <td class="px-5 py-3">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $d->actif ? 'bg-green-100 text-green-700' : 'bg-slate-200 text-slate-600' }}">{{ $d->actif ? 'Actif' : 'Inactif' }}</span>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-6 py-10 text-center text-slate-400">Aucun destinataire</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($peutAdministrer)
            @foreach($destinataires as $d)
                <form id="dest-{{ $d->id }}" method="POST" action="{{ route('logistique.destinataires.update', $d) }}" class="hidden">@csrf @method('PUT')</form>
            @endforeach
        @endif
        @if($destinataires->hasPages())
            <div class="px-6 py-4 border-t border-slate-100">{{ $destinataires->links() }}</div>
        @endif
    </div>
</div>
@endsection

@extends('layouts.app')
@section('title','Demandes')
@section('page-title','Demandes')

@section('content')
<div class="space-y-5">

    {{-- Filters --}}
    <form method="GET" action="{{ route('operations.index') }}" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
            <input type="text" name="q" value="{{ request('q') }}" placeholder="NIN, nom, prénom…"
                   class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
            <select name="type" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                <option value="">Tous les types</option>
                @foreach(['inscription'=>'Inscription','modification'=>'Modification','changement'=>'Changement','radiation'=>'Radiation'] as $v => $l)
                    <option value="{{ $v }}" {{ request('type') === $v ? 'selected' : '' }}>{{ $l }}</option>
                @endforeach
            </select>
            <select name="statut" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                <option value="">Tous les statuts</option>
                @foreach(['en_attente'=>'En attente','validee'=>'Validée','rejetee'=>'Rejetée'] as $v => $l)
                    <option value="{{ $v }}" {{ request('statut') === $v ? 'selected' : '' }}>{{ $l }}</option>
                @endforeach
            </select>
            <input type="date" name="du" value="{{ request('du') }}"
                   class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
            <input type="date" name="au" value="{{ request('au') }}"
                   class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
        </div>
        <div class="flex gap-3 mt-3">
            <button type="submit" class="px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Filtrer</button>
            <a href="{{ route('operations.index') }}" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">Réinitialiser</a>
            <a href="{{ route('operations.create') }}" class="ml-auto px-5 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 flex items-center gap-2" style="background:#009A44;">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Nouvelle demande
            </a>
        </div>
    </form>

    {{-- Table --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $operations->total() }} demande(s)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">#</th>
                        <th class="px-5 py-3 text-left font-semibold">NIN</th>
                        <th class="px-5 py-3 text-left font-semibold">Demandeur</th>
                        <th class="px-5 py-3 text-left font-semibold">Type</th>
                        <th class="px-5 py-3 text-left font-semibold">Statut</th>
                        <th class="px-5 py-3 text-left font-semibold">Commune</th>
                        <th class="px-5 py-3 text-left font-semibold">Date</th>
                        <th class="px-5 py-3 text-left font-semibold">Agent</th>
                        <th class="px-5 py-3 text-left font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($operations as $op)
                        @php
                            $tc = ['inscription'=>'#009A44','modification'=>'#ca8a04','changement'=>'#0284c7','radiation'=>'#EE1C25'][$op->type] ?? '#666';
                            $sc = ['en_attente'=>'#d97706','validee'=>'#009A44','rejetee'=>'#EE1C25'][$op->statut] ?? '#666';
                        @endphp
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 text-slate-400 text-xs">{{ $op->id }}</td>
                            <td class="px-5 py-3 font-mono text-xs text-slate-600">{{ $op->nin_demandeur }}</td>
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $op->prenom_demandeur }} {{ $op->nom_demandeur }}</td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background:{{ $tc }};">{{ $op->type_label }}</span>
                            </td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background:{{ $sc }};">{{ $op->statut_label }}</span>
                            </td>
                            <td class="px-5 py-3 text-slate-500 text-xs">{{ $op->commune_nom ?? '—' }}</td>
                            <td class="px-5 py-3 text-slate-500 text-xs">{{ $op->created_at->format('d/m/Y') }}</td>
                            <td class="px-5 py-3 text-slate-500 text-xs">{{ $op->user?->nom_complet ?? '—' }}</td>
                            <td class="px-5 py-3">
                                <div class="flex gap-2">
                                    <a href="{{ route('operations.show', $op) }}"
                                       class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">
                                        Voir
                                    </a>
                                    @if($op->statut === 'en_attente' && auth()->user()->canValidate())
                                        <form method="POST" action="{{ route('operations.valider', $op) }}" onsubmit="return confirm('Valider cette opération ?')">
                                            @csrf @method('PATCH')
                                            <button class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#009A44;">Valider</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-6 py-10 text-center text-slate-400">Aucune demande trouvée</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($operations->hasPages())
            <div class="px-6 py-4 border-t border-slate-100">
                {{ $operations->links() }}
            </div>
        @endif
    </div>

</div>
@endsection

@extends('layouts.app')
@section('title','Postes à l\'étranger')
@section('page-title','Postes à l\'étranger')

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $postes->total() }} poste(s)</h3>
            <a href="{{ route('logistique.postes.create') }}" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Nouveau poste</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Pays</th>
                        <th class="px-5 py-3 text-left font-semibold">Ville</th>
                        <th class="px-5 py-3 text-left font-semibold">Type</th>
                        <th class="px-5 py-3 text-left font-semibold">Nom</th>
                        <th class="px-5 py-3 text-left font-semibold">État</th>
                        <th class="px-5 py-3 text-left font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($postes as $p)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 text-slate-800">{{ $p->pays }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $p->ville }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $p->type === 'ambassade' ? 'Ambassade' : 'Consulat' }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $p->nom }}</td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $p->actif ? 'bg-green-100 text-green-700' : 'bg-slate-200 text-slate-600' }}">{{ $p->actif ? 'Actif' : 'Inactif' }}</span>
                            </td>
                            <td class="px-5 py-3">
                                <a href="{{ route('logistique.postes.edit', $p) }}" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">Modifier</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-10 text-center text-slate-400">Aucun poste</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($postes->hasPages())
            <div class="px-6 py-4 border-t border-slate-100">{{ $postes->links() }}</div>
        @endif
    </div>
</div>
@endsection

@extends('layouts.app')
@section('title','Circuit de livraison')
@section('page-title','Circuit de livraison')

@php
    $libelles = ['via_prefet' => 'Via le préfet', 'direct' => 'Direct'];
    $peutModifier = \Illuminate\Support\Facades\Gate::allows('logistique-administrer') && $cycle->estOuvert();
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 text-sm text-slate-600">
        <p>
            Circuit par défaut du cycle : <span class="font-semibold text-slate-800">{{ $libelles[$cycle->circuit_defaut] ?? $cycle->circuit_defaut }}</span>.
            En circuit « via le préfet », le matériel des sous-préfectures est remis au préfet, qui le redistribue ; en circuit « direct », il leur est remis directement.
            Une dérogation s'applique à tout un département ; les postes à l'étranger sont toujours servis directement.
        </p>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $departements->count() }} département(s)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Département</th>
                        <th class="px-5 py-3 text-left font-semibold">Circuit</th>
                        <th class="px-5 py-3 text-left font-semibold">Origine</th>
                        @if($peutModifier) <th class="px-5 py-3 text-left font-semibold">Modifier</th> @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($departements as $d)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $d['nom'] }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $libelles[$d['circuit']] ?? $d['circuit'] }}</td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $d['derogation'] ? 'bg-amber-100 text-amber-700' : 'bg-slate-200 text-slate-600' }}">
                                    {{ $d['derogation'] ? 'Dérogation' : 'Circuit du cycle' }}
                                </span>
                            </td>
                            @if($peutModifier)
                                <td class="px-5 py-3">
                                    <form method="POST" action="{{ route('logistique.circuit.update', [$cycle, $d['departement_id']]) }}" class="flex flex-wrap gap-2 items-center">
                                        @csrf @method('PUT')
                                        <select name="circuit" aria-label="Circuit de {{ $d['nom'] }}" class="px-2 py-1.5 rounded-lg border border-slate-300 text-sm focus:outline-none">
                                            <option value="">Circuit du cycle</option>
                                            @foreach($libelles as $valeur => $libelle)
                                                <option value="{{ $valeur }}" @selected($d['derogation'] && $d['circuit'] === $valeur)>{{ $libelle }}</option>
                                            @endforeach
                                        </select>
                                        <input type="text" name="motif" required maxlength="500" placeholder="Motif" aria-label="Motif pour {{ $d['nom'] }}"
                                               class="px-2 py-1.5 rounded-lg border border-slate-300 text-sm focus:outline-none">
                                        <button type="submit" class="px-3 py-1.5 rounded-lg text-white text-xs font-medium hover:opacity-90" style="background:#009A44;">Enregistrer</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-10 text-center text-slate-400">Aucun département : générez d'abord les destinataires.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

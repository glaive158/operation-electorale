@extends('layouts.app')
@section('title','Circuit de livraison')
@section('page-title','Circuit de livraison')

@php
    $libelles = ['via_prefet' => 'Via le préfet', 'direct' => 'Direct'];
    $sources = ['sous_prefecture' => 'Sous-préfecture', 'departement' => 'Département', 'cycle' => 'Cycle'];
    $couleursSource = ['sous_prefecture' => 'bg-sky-100 text-sky-700', 'departement' => 'bg-amber-100 text-amber-700', 'cycle' => 'bg-slate-200 text-slate-600'];
    $peutModifier = \Illuminate\Support\Facades\Gate::allows('logistique-administrer') && $cycle->estOuvert();
    $colonnes = $peutModifier ? 4 : 3;
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 text-sm text-slate-600">
        <p>
            Circuit par défaut du cycle : <span class="font-semibold text-slate-800">{{ $libelles[$cycle->circuit_defaut] ?? $cycle->circuit_defaut }}</span>.
            En circuit « via le préfet », le matériel des sous-préfectures est remis au préfet, qui le redistribue ; en circuit « direct », il leur est remis directement.
            Une dérogation s'applique à tout un département ; une sous-préfecture peut aussi avoir son propre circuit, qui prime sur celui de son département.
            Les postes à l'étranger sont toujours servis directement. Une décharge déjà validée garde le circuit de sa validation.
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
                @forelse($departements as $d)
                    @php $lignesSp = $sousPrefectures->get($d['departement_id'], collect()); @endphp
                    <tbody class="divide-y divide-slate-50 border-t border-slate-50"
                           x-data="{ ouvert: {{ $lignesSp->contains('id', (int) old('sous_prefecture')) ? 'true' : 'false' }} }">
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 font-medium text-slate-800">
                                {{ $d['nom'] }}
                                @if($lignesSp->isNotEmpty())
                                    <button type="button" @click="ouvert = !ouvert" x-bind:aria-expanded="ouvert.toString()" aria-expanded="false"
                                            class="ml-2 text-xs font-normal text-sky-700 hover:underline">
                                        {{ $lignesSp->count() }} sous-préfecture(s)
                                        @if($lignesSp->where('source', 'sous_prefecture')->isNotEmpty()) · {{ $lignesSp->where('source', 'sous_prefecture')->count() }} réglage(s) propre(s) @endif
                                    </button>
                                @endif
                            </td>
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
                        @foreach($lignesSp as $sp)
                            @include('logistique.circuit._sous_prefecture', ['sp' => $sp])
                        @endforeach
                    </tbody>
                @empty
                    <tbody>
                        <tr><td colspan="{{ $colonnes }}" class="px-6 py-10 text-center text-slate-400">Aucun département : générez d'abord les destinataires.</td></tr>
                    </tbody>
                @endforelse
                @if($sousPrefectures->has(0))
                    @php $lignesSp = $sousPrefectures->get(0); @endphp
                    <tbody class="divide-y divide-slate-50 border-t border-slate-100"
                           x-data="{ ouvert: {{ $lignesSp->contains('id', (int) old('sous_prefecture')) ? 'true' : 'false' }} }">
                        <tr class="bg-slate-50">
                            <td colspan="{{ $colonnes }}" class="px-5 py-3 font-medium text-slate-700">
                                Sans département rattaché
                                <button type="button" @click="ouvert = !ouvert" x-bind:aria-expanded="ouvert.toString()" aria-expanded="false"
                                        class="ml-2 text-xs font-normal text-sky-700 hover:underline">{{ $lignesSp->count() }} sous-préfecture(s)</button>
                            </td>
                        </tr>
                        @foreach($lignesSp as $sp)
                            @include('logistique.circuit._sous_prefecture', ['sp' => $sp])
                        @endforeach
                    </tbody>
                @endif
            </table>
        </div>
    </div>
</div>
@endsection

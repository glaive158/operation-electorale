{{-- Ligne d'une sous-préfecture sous son département. Variables : $sp, $cycle, $libelles, $sources, $couleursSource, $peutModifier. --}}
@php $retour = (int) old('sous_prefecture') === $sp['id']; @endphp
<tr x-show="ouvert" x-cloak style="display:none" class="bg-slate-50">
    <td class="pl-10 pr-5 py-2.5 text-slate-700">{{ $sp['nom'] }}</td>
    <td class="px-5 py-2.5 text-slate-600">{{ $libelles[$sp['circuit']] ?? $sp['circuit'] }}</td>
    <td class="px-5 py-2.5">
        <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $couleursSource[$sp['source']] ?? 'bg-slate-200 text-slate-600' }}">{{ $sources[$sp['source']] ?? $sp['source'] }}</span>
    </td>
    @if($peutModifier)
        <td class="px-5 py-2.5">
            <form method="POST" action="{{ route('logistique.circuit.sous-prefecture', [$cycle, $sp['id']]) }}" class="flex flex-wrap gap-2 items-center">
                @csrf
                <input type="hidden" name="sous_prefecture" value="{{ $sp['id'] }}">
                <select name="circuit" aria-label="Circuit de {{ $sp['nom'] }}" class="px-2 py-1.5 rounded-lg border border-slate-300 text-sm focus:outline-none">
                    <option value="">{{ $sp['departement_id'] === null ? 'Circuit du cycle' : 'Comme le département' }}</option>
                    @foreach($libelles as $valeur => $libelle)
                        <option value="{{ $valeur }}" @selected($retour ? old('circuit') === $valeur : $sp['reglage'] === $valeur)>{{ $libelle }}</option>
                    @endforeach
                </select>
                <input type="text" name="motif" required maxlength="500" placeholder="Motif" aria-label="Motif pour {{ $sp['nom'] }}" value="{{ $retour ? old('motif') : '' }}"
                       class="px-2 py-1.5 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <button type="submit" class="px-3 py-1.5 rounded-lg text-white text-xs font-medium hover:opacity-90" style="background:#009A44;">Enregistrer</button>
            </form>
        </td>
    @endif
</tr>

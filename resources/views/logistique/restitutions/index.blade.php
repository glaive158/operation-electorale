@extends('layouts.app')
@section('title','Restitution')
@section('page-title','Restitution du matériel réutilisable')

@php
    use App\Models\Logistique\Restitution;
    $n = fn ($v) => $v === null ? '—' : number_format((int) $v, 0, ',', ' ');
    $classes = ['a_preparer' => 'bg-slate-100 text-slate-600', 'en_retour' => 'bg-sky-100 text-sky-700', 'recue_central' => 'bg-amber-100 text-amber-700', 'controlee' => 'bg-green-100 text-green-700'];
    $champ = 'px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none';
    $avecEcart = fn (array $e) => ($e['non_restitue'] ?? 0) > 0 || ($e['perdu_en_retour'] ?? 0) > 0;
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._onglets')
    @include('logistique._erreurs')

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-600">Chaque détenteur (préfet, sous-préfet, chef de poste, agent) rend le matériel réutilisable qu'il détient encore.
            L'ouverture crée une restitution par détenteur qui a un solde ; elle reste possible après la clôture du cycle.</p>
        @can('logistique-administrer')
            <form method="POST" action="{{ route('logistique.restitutions.ouvrir', $cycle) }}"
                  onsubmit="return confirm('Ouvrir la restitution pour tous les détenteurs qui ont un solde ?');">
                @csrf
                <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Ouvrir la restitution</button>
            </form>
        @endcan
    </div>

    <form method="GET" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <div class="flex flex-wrap gap-3 items-center">
            <select name="statut" aria-label="Statut" class="{{ $champ }}">
                <option value="">Tous les statuts</option>
                @foreach(Restitution::STATUTS as $valeur => $libelle)
                    <option value="{{ $valeur }}" @selected($statut === $valeur)>{{ $libelle }}</option>
                @endforeach
            </select>
            <select name="detenteur" aria-label="Détenteur" class="{{ $champ }}">
                <option value="">Tous les détenteurs</option>
                @foreach(Restitution::DETENTEURS as $valeur => $libelle)
                    <option value="{{ $valeur }}" @selected($detenteur === $valeur)>{{ $libelle }}s</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#0284c7;">Filtrer</button>
        </div>
    </form>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $restitutions->total() }} restitution(s)</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">N°</th>
                        <th class="px-5 py-3 text-left font-semibold">Détenteur</th>
                        <th class="px-5 py-3 text-right font-semibold">Articles</th>
                        <th class="px-5 py-3 text-left font-semibold">Agent de retour</th>
                        <th class="px-5 py-3 text-left font-semibold">Statut</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($restitutions as $r)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 text-slate-600">{{ $r->id }}</td>
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $noms[$r->id] ?? '' }} <span class="text-xs text-slate-500">({{ Restitution::DETENTEURS[$r->detenteur_type] ?? $r->detenteur_type }})</span></td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $n($r->lignes_count) }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $r->agent?->nom_complet ?? '—' }}</td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classes[$r->statut] ?? 'bg-slate-200 text-slate-600' }}">{{ Restitution::STATUTS[$r->statut] ?? $r->statut }}</span>
                                @if($r->a_verifier)
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">Déclaration à vérifier</span>
                                @endif
                            </td>
                            <td class="px-5 py-3"><a href="{{ route('logistique.restitutions.show', $r) }}" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">Ouvrir</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-10 text-center text-slate-400">Aucune restitution</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($restitutions->hasPages())
            <div class="px-6 py-4 border-t border-slate-100">{{ $restitutions->links() }}</div>
        @endif
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Écarts de restitution</h3>
            <p class="text-xs text-slate-500">Non restitué : attendu moins le retour déclaré (une fois une déclaration retenue). Perdu en retour : déclaré moins reçu au central (une fois le retour réceptionné).</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Détenteur</th>
                        <th class="px-5 py-3 text-left font-semibold">Article</th>
                        <th class="px-5 py-3 text-right font-semibold">Attendu</th>
                        <th class="px-5 py-3 text-right font-semibold">Déclaré rendu</th>
                        <th class="px-5 py-3 text-right font-semibold">Reçu au central</th>
                        <th class="px-5 py-3 text-right font-semibold">Non restitué</th>
                        <th class="px-5 py-3 text-right font-semibold">Perdu en retour</th>
                        <th class="px-5 py-3 text-left font-semibold">Justification</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($ecarts as $e)
                        <tr class="{{ $avecEcart($e) ? 'bg-red-50/40' : '' }}">
                            <td class="px-5 py-3 font-medium text-slate-800">{{ $e['detenteur_nom'] }}</td>
                            <td class="px-5 py-3 text-slate-600">{{ $e['materiel_libelle'] }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $n($e['attendu']) }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $n($e['declare_restitue']) }}</td>
                            <td class="px-5 py-3 text-right text-slate-600">{{ $n($e['recu_central']) }}</td>
                            <td class="px-5 py-3 text-right font-semibold {{ ($e['non_restitue'] ?? 0) > 0 ? 'text-red-700' : 'text-slate-800' }}">{{ $n($e['non_restitue']) }}</td>
                            <td class="px-5 py-3 text-right font-semibold {{ ($e['perdu_en_retour'] ?? 0) > 0 ? 'text-red-700' : 'text-slate-800' }}">{{ $n($e['perdu_en_retour']) }}</td>
                            <td class="px-5 py-3 text-slate-600">
                                @if($e['justification'])
                                    <p class="text-xs">{{ $e['justification'] }}</p>
                                @endif
                                @if($avecEcart($e))
                                    <form method="POST" action="{{ route('logistique.restitutions.justifier', $e['ligne_id']) }}" class="flex flex-wrap gap-2 items-center pt-1">
                                        @csrf
                                        <input type="text" name="justification" required maxlength="500" placeholder="Justification de l'écart"
                                               aria-label="Justification : {{ $e['detenteur_nom'] }}, {{ $e['materiel_libelle'] }}" class="w-full md:w-56 px-2 py-1 rounded-lg border border-slate-300 text-sm focus:outline-none">
                                        <button type="submit" class="px-3 py-1 rounded-lg text-white text-xs font-medium hover:opacity-90" style="background:#0284c7;">{{ $e['justification'] ? 'Réécrire' : 'Justifier' }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-6 py-10 text-center text-slate-400">Aucun écart : la restitution n'est pas ouverte.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

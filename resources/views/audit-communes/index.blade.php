@extends('layouts.app')
@section('title','Audit Incohérences Commune')
@section('page-title','Audit Incohérences Commune')

@section('content')
<div class="space-y-5">

    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-lg font-bold text-slate-800">Incohérences de commune — Cartenats vs CSV Fichier Électoral</h2>
            <p class="text-xs text-slate-400 mt-0.5">
                Mutations administratives non reflétées : la carte indique une commune, le CSV en indique une autre (après normalisation espaces/parenthèses/quotes).
            </p>
        </div>
        <a href="{{ route('audit-communes.export', request()->only('commune_csv')) }}"
           class="px-4 py-2 rounded-lg text-sm font-medium text-white whitespace-nowrap"
           style="background:#009A44;">
            ⬇ Exporter CSV
        </a>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="rounded-xl p-4 border-2" style="background:#fee2e2; border-color:#dc2626;">
            <p class="text-xs uppercase font-bold tracking-wider" style="color:#dc2626;">Total</p>
            <p class="text-2xl font-bold mt-1" style="color:#dc2626;">{{ number_format($total) }}</p>
            <p class="text-xs text-slate-600 mt-1">incohérences</p>
        </div>
        @foreach($communesCsv->take(3) as $cc)
            <div class="rounded-xl p-4 border-2" style="background:#dbeafe; border-color:#0284c7;">
                <p class="text-xs uppercase font-bold tracking-wider" style="color:#0284c7;">{{ $cc->commune }}</p>
                <p class="text-2xl font-bold mt-1" style="color:#0284c7;">{{ $cc->nb }}</p>
                <p class="text-xs text-slate-600 mt-1">mutations vers</p>
            </div>
        @endforeach
    </div>

    {{-- Filtre --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-4">
        <form method="GET" action="{{ route('audit-communes') }}" class="flex gap-2 items-center flex-wrap">
            <span class="text-sm font-semibold text-slate-600">Filtrer par commune CSV :</span>
            <select name="commune_csv"
                    onchange="this.form.submit()"
                    class="px-3 py-2 rounded-lg border border-slate-300 text-sm">
                <option value="">Toutes ({{ $total }})</option>
                @foreach($communesCsv as $cc)
                    <option value="{{ $cc->commune }}" @selected($communeCsv === $cc->commune)>
                        {{ $cc->commune }} ({{ $cc->nb }})
                    </option>
                @endforeach
            </select>
            @if($communeCsv)
                <a href="{{ route('audit-communes') }}" class="text-xs text-slate-500 hover:text-slate-700 underline">
                    Réinitialiser
                </a>
            @endif
            <span class="text-xs text-slate-400 ml-auto">{{ $resultats->count() }} résultat(s) affiché(s)</span>
        </form>
    </div>

    {{-- Tableau --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead style="background:#0f172a; color:#fff;">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold uppercase tracking-wide">NIN</th>
                        <th class="px-3 py-2 text-left font-semibold uppercase tracking-wide">Nom</th>
                        <th class="px-3 py-2 text-left font-semibold uppercase tracking-wide">Prénom</th>
                        <th class="px-3 py-2 text-left font-semibold uppercase tracking-wide">DDN</th>
                        <th class="px-3 py-2 text-left font-semibold uppercase tracking-wide" style="background:#ea580c;">Commune Carte</th>
                        <th class="px-3 py-2 text-left font-semibold uppercase tracking-wide" style="background:#16a34a;">Commune CSV</th>
                        <th class="px-3 py-2 text-left font-semibold uppercase tracking-wide">NumElec Carte</th>
                        <th class="px-3 py-2 text-left font-semibold uppercase tracking-wide">NumElec CSV</th>
                        <th class="px-3 py-2 text-center font-semibold uppercase tracking-wide">État</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($resultats as $r)
                        @php $numelecDiff = trim($r->numelec_carte ?? '') !== trim($r->numelec_csv ?? ''); @endphp
                        <tr class="hover:bg-slate-50">
                            <td class="px-3 py-2 font-mono text-slate-700">{{ $r->numcni }}</td>
                            <td class="px-3 py-2 font-semibold text-slate-800">{{ $r->nom }}</td>
                            <td class="px-3 py-2 text-slate-700">{{ $r->prenom }}</td>
                            <td class="px-3 py-2 text-slate-600 font-mono">{{ $r->datenaiss }}</td>
                            <td class="px-3 py-2" style="color:#ea580c; font-weight:600;">{{ $r->commune_carte }}</td>
                            <td class="px-3 py-2" style="color:#16a34a; font-weight:600;">{{ $r->commune_csv }}</td>
                            <td class="px-3 py-2 font-mono {{ $numelecDiff ? 'text-red-600 font-bold' : 'text-slate-600' }}">
                                {{ $r->numelec_carte }}
                            </td>
                            <td class="px-3 py-2 font-mono {{ $numelecDiff ? 'text-red-600 font-bold' : 'text-slate-600' }}">
                                {{ $r->numelec_csv }}
                                @if($numelecDiff) <span title="Numéro électeur divergent">⚠️</span> @endif
                            </td>
                            <td class="px-3 py-2 text-center">
                                @if($r->etat == 1)
                                    <span class="px-2 py-0.5 rounded text-white text-xs font-medium" style="background:#16a34a;">Actif</span>
                                @else
                                    <span class="px-2 py-0.5 rounded text-white text-xs font-medium" style="background:#64748b;">Inactif</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-3 py-8 text-center text-slate-400 italic">Aucune incohérence pour ce filtre.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Légende --}}
    <div class="bg-slate-50 rounded-xl p-4 border border-slate-200 text-xs text-slate-600 space-y-1">
        <p><strong>Interprétation :</strong></p>
        <p>• <span style="color:#ea580c; font-weight:600;">Commune Carte</span> = commune enregistrée sur la carte nationale (cartenats)</p>
        <p>• <span style="color:#16a34a; font-weight:600;">Commune CSV</span> = commune dans le nouveau fichier électoral CSV (révision 2025)</p>
        <p>• <span class="text-red-600 font-bold">NumElec en rouge ⚠️</span> = numéro électeur différent entre carte et CSV (réenregistrement suspect)</p>
        <p>• Mutations vers <strong>PLATEAU/YOFF</strong> = personnes déplacées à Dakar non mises à jour sur leur carte</p>
        <p>• Mutations vers <strong>BIGNONA</strong> = même pattern sur Ziguinchor</p>
        <p>• Communes <strong>FAFACOUROU, NGAINTHE PATE, MLOMP (BIGNONA)</strong> = redécoupages administratifs</p>
    </div>

</div>
@endsection

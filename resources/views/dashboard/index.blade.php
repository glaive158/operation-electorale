@extends('layouts.app')
@section('title','Tableau de bord')
@section('page-title','Tableau de bord')

@section('content')
<div class="space-y-6">

    {{-- ── Stat cards ── --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        @php
            $cards = [
                ['label'=>'Total demandes',  'value'=>$statsOps->total ?? 0,        'icon'=>'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2', 'bg'=>'#0f172a','text'=>'white'],
                ['label'=>'En attente',      'value'=>$statsOps->en_attente ?? 0,   'icon'=>'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0',  'bg'=>'#fef3c7','text'=>'#92400e'],
                ['label'=>'Validées',        'value'=>$statsOps->validees ?? 0,     'icon'=>'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0',  'bg'=>'#dcfce7','text'=>'#166534'],
                ['label'=>'Rejetées',        'value'=>$statsOps->rejetees ?? 0,     'icon'=>'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0', 'bg'=>'#fee2e2','text'=>'#991b1b'],
            ];
        @endphp
        @foreach($cards as $card)
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-100 flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0"
                     style="background:{{ $card['bg'] }};">
                    <svg class="w-6 h-6" fill="none" stroke="{{ $card['text'] }}" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $card['icon'] }}"/>
                    </svg>
                </div>
                <div>
                    <p class="text-2xl font-bold text-slate-800">{{ number_format($card['value']) }}</p>
                    <p class="text-xs text-slate-500 mt-0.5">{{ $card['label'] }}</p>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ── Par type + fichier electoral stats ── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Par type --}}
        <div class="lg:col-span-1 bg-white rounded-2xl p-5 shadow-sm border border-slate-100">
            <h3 class="font-semibold text-slate-800 mb-4">Par type d'opération</h3>
            @php
                $types = [
                    ['key'=>'inscriptions',  'label'=>'Inscriptions',        'color'=>'#009A44'],
                    ['key'=>'modifications', 'label'=>'Modifications',       'color'=>'#0284c7'],
                    ['key'=>'changements',   'label'=>'Changements statut',  'color'=>'#d97706'],
                    ['key'=>'radiations',    'label'=>'Radiations',          'color'=>'#EE1C25'],
                ];
                $total = max($statsOps->total ?? 1, 1);
            @endphp
            <div class="space-y-3">
                @foreach($types as $t)
                    @php $val = $statsOps->{$t['key']} ?? 0; $pct = round($val/$total*100); @endphp
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span class="text-slate-600">{{ $t['label'] }}</span>
                            <span class="font-semibold text-slate-800">{{ $val }}</span>
                        </div>
                        <div class="h-2 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full rounded-full transition-all" style="width:{{ $pct }}%; background:{{ $t['color'] }};"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Fichier électoral stats (admin/gouverneur) --}}
        @if($statsElecteurs)
            <div class="lg:col-span-2 bg-white rounded-2xl p-5 shadow-sm border border-slate-100">
                <h3 class="font-semibold text-slate-800 mb-4">Fichier électoral national</h3>
                <div class="grid grid-cols-3 gap-4">
                    @php
                        $feCards = [
                            ['label'=>'Total électeurs', 'value'=>$statsElecteurs['total'] ?? 0, 'color'=>'#0f172a'],
                            ['label'=>'National',         'value'=>$statsElecteurs['national'] ?? 0,'color'=>'#009A44'],
                            ['label'=>'Diaspora',         'value'=>$statsElecteurs['etranger'] ?? 0,'color'=>'#0284c7'],
                        ];
                    @endphp
                    @foreach($feCards as $fc)
                        <div class="text-center p-4 rounded-xl" style="background:{{ $fc['color'] }}10;">
                            <p class="text-2xl font-bold" style="color:{{ $fc['color'] }};">
                                {{ number_format($fc['value']) }}
                            </p>
                            <p class="text-xs text-slate-500 mt-1">{{ $fc['label'] }}</p>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 text-center">
                    <a href="{{ route('fichier-electoral') }}" class="text-sm font-medium hover:underline" style="color:#009A44;">
                        Rechercher dans le fichier →
                    </a>
                </div>
            </div>
        @else
            {{-- Quick actions for non-admin --}}
            <div class="lg:col-span-2 bg-white rounded-2xl p-5 shadow-sm border border-slate-100">
                <h3 class="font-semibold text-slate-800 mb-4">Actions rapides</h3>
                <div class="grid grid-cols-2 gap-3">
                    @php
                        $actions = [
                            ['label'=>'Inscription',        'type'=>'inscription',  'color'=>'#009A44'],
                            ['label'=>'Modification',       'type'=>'modification', 'color'=>'#0284c7'],
                            ['label'=>'Changement statut',  'type'=>'changement',   'color'=>'#d97706'],
                            ['label'=>'Radiation',          'type'=>'radiation',    'color'=>'#EE1C25'],
                        ];
                    @endphp
                    @foreach($actions as $act)
                        <a href="{{ route('operations.create', ['type'=>$act['type']]) }}"
                           class="flex items-center gap-3 p-4 rounded-xl border-2 hover:shadow-md transition-all"
                           style="border-color:{{ $act['color'] }}30; background:{{ $act['color'] }}08;">
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center" style="background:{{ $act['color'] }}20;">
                                <svg class="w-4 h-4" fill="none" stroke="{{ $act['color'] }}" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                </svg>
                            </div>
                            <span class="text-sm font-medium text-slate-700">{{ $act['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- ── Dernières opérations ── --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Dernières demandes</h3>
            <a href="{{ route('operations.index') }}" class="text-sm font-medium hover:underline" style="color:#009A44;">
                Voir tout →
            </a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-6 py-3 text-left font-semibold">NIN</th>
                        <th class="px-6 py-3 text-left font-semibold">Demandeur</th>
                        <th class="px-6 py-3 text-left font-semibold">Type</th>
                        <th class="px-6 py-3 text-left font-semibold">Statut</th>
                        <th class="px-6 py-3 text-left font-semibold">Date</th>
                        <th class="px-6 py-3 text-left font-semibold">Commission</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($dernieres as $op)
                        @php
                            $typeColors = ['inscription'=>'#009A44','modification'=>'#0284c7','changement'=>'#d97706','radiation'=>'#EE1C25'];
                            $statColors = ['en_attente'=>'#d97706','validee'=>'#009A44','rejetee'=>'#EE1C25'];
                            $tc = $typeColors[$op->type] ?? '#666';
                            $sc = $statColors[$op->statut] ?? '#666';
                        @endphp
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-3 font-mono text-xs text-slate-600">{{ $op->nin_demandeur }}</td>
                            <td class="px-6 py-3 font-medium text-slate-800">{{ $op->prenom_demandeur }} {{ $op->nom_demandeur }}</td>
                            <td class="px-6 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background:{{ $tc }};">
                                    {{ $op->type_label }}
                                </span>
                            </td>
                            <td class="px-6 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background:{{ $sc }};">
                                    {{ $op->statut_label }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-slate-500 text-xs">{{ $op->created_at->format('d/m/Y H:i') }}</td>
                            <td class="px-6 py-3 text-slate-500 text-xs">{{ $op->commune_nom ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-slate-400">Aucune demande enregistrée</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection

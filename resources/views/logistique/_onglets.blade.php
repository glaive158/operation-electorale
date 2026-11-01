@php
    $cycleOnglet = $cycle ?? null;
    $onglets = [
        ['Destinataires', 'logistique.destinataires.index', null, 'logistique.destinataires.*'],
        ['Correspondances', 'logistique.correspondances.index', null, 'logistique.correspondances.*'],
    ];
    if (\Illuminate\Support\Facades\Gate::allows('logistique-administrer')) {
        $onglets[] = ['Postes à l\'étranger', 'logistique.postes.index', null, 'logistique.postes.*'];
    }
    if ($cycleOnglet) {
        $onglets = array_merge($onglets, [
            ['Découpage', 'logistique.decoupage.show', $cycleOnglet, 'logistique.decoupage.*'],
            ['Commissions', 'logistique.commissions.index', $cycleOnglet, 'logistique.commissions.*'],
            ['Entités', 'logistique.entites.index', $cycleOnglet, 'logistique.entites.*'],
            ['Clés', 'logistique.cles.index', $cycleOnglet, 'logistique.cles.*'],
            ['Instantanés', 'logistique.instantanes.index', $cycleOnglet, 'logistique.instantanes.*'],
            ['Plans', 'logistique.plans.index', $cycleOnglet, 'logistique.plans.*'],
            ['Besoin', 'logistique.besoin.show', $cycleOnglet, 'logistique.besoin.*'],
        ]);
    }
@endphp
<div class="space-y-3">
    @if($cycleOnglet)
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <span class="font-semibold text-slate-800">Cycle : {{ $cycleOnglet->libelle }}</span>
            <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $cycleOnglet->estOuvert() ? 'bg-green-100 text-green-700' : 'bg-slate-200 text-slate-600' }}">
                {{ $cycleOnglet->estOuvert() ? 'Ouvert' : 'Clos' }}
            </span>
            <a href="{{ route('logistique.cycles.index') }}" class="text-xs text-slate-500 underline">Changer de cycle</a>
        </div>
    @endif
    <nav class="flex flex-wrap gap-2" aria-label="Sections">
        @foreach($onglets as [$libelle, $route, $parametre, $motif])
            @php $actif = request()->routeIs($motif); @endphp
            <a href="{{ $parametre ? route($route, $parametre) : route($route) }}"
               class="px-3 py-1.5 rounded-lg text-sm border {{ $actif ? 'text-white border-transparent' : 'border-slate-300 text-slate-600 hover:bg-slate-50' }}"
               style="{{ $actif ? 'background:#009A44;' : '' }}">{{ $libelle }}</a>
        @endforeach
    </nav>
</div>

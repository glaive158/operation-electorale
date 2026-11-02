<!DOCTYPE html>
<html lang="fr" x-data="{ sidebarOpen: true, mobileOpen: false }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Opérations Électorales')</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
    @livewireStyles
    @stack('styles')
</head>
<body class="bg-slate-100 font-sans antialiased" style="min-height:100vh;">

<div class="flex h-screen overflow-hidden">

    {{-- ══════════════════════════════════════════
         SIDEBAR
    ══════════════════════════════════════════ --}}
    <aside
        class="flex flex-col flex-shrink-0 transition-all duration-300 z-30"
        :class="sidebarOpen ? 'w-64' : 'w-16'"
        style="background:#0f172a; min-height:100vh;"
        x-cloak>

        {{-- Logo --}}
        <div class="flex items-center gap-3 px-4 py-5 border-b border-slate-700">
            <div class="flex-shrink-0 w-9 h-9 rounded-lg flex items-center justify-center" style="background:#009A44;">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
            </div>
            <div x-show="sidebarOpen" class="overflow-hidden">
                <p class="text-white font-bold text-sm leading-tight">Opérations</p>
                <p class="text-slate-400 text-xs">Électorales</p>
            </div>
            <button @click="sidebarOpen = !sidebarOpen" class="ml-auto text-slate-400 hover:text-white hidden lg:block">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>
                </svg>
            </button>
        </div>

        {{-- User zone badge --}}
        <div x-show="sidebarOpen" class="px-4 py-3 border-b border-slate-700">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                     style="background:#EE1C25;">
                    {{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}
                </div>
                <div class="overflow-hidden">
                    <p class="text-white text-xs font-medium truncate">{{ auth()->user()->nom_complet }}</p>
                    <p class="text-slate-400 text-xs truncate">{{ auth()->user()->zone_label }}</p>
                </div>
            </div>
        </div>

        {{-- Navigation --}}
        <nav class="flex-1 px-2 py-4 overflow-y-auto space-y-1">

            @php
                $isCommission = auth()->user()->isCommission();
                $isLogistique = auth()->user()->isLogistique();
                // Chefs de poste et agents de transport n'ont que l'espace terrain : aucune des pages d'opérations ne leur est ouverte.
                $isTerrainSeul = auth()->user()->hasRole(['chef_poste', 'agent_transport']);
                $navItems = ($isLogistique || $isTerrainSeul) ? [] : array_filter([
                    ['route' => 'dashboard',         'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6', 'label' => 'Tableau de bord'],
                    ['route' => 'operations.create', 'icon' => 'M12 4v16m8-8H4', 'label' => 'Nouvelle opération'],
                    ['route' => 'operations.index',  'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2', 'label' => 'Demandes'],
                    !$isCommission ? ['route' => 'fichier-electoral', 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z', 'label' => 'Fichier électoral'] : null,
                    !$isCommission ? ['route' => 'carte-electorale', 'icon' => 'M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7', 'label' => 'Carte électorale'] : null,
                    !$isCommission ? ['route' => 'comparaison-lieux', 'icon' => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4', 'label' => 'Comparaison lieux'] : null,
                    !$isCommission ? ['route' => 'comparaison-fichiers', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4', 'label' => 'Comparaison fichiers'] : null,
                    !$isCommission ? ['route' => 'impact-deplacement', 'icon' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z', 'label' => 'Impact déplacement'] : null,
                    !$isCommission ? ['route' => 'audit-revisions', 'icon' => 'M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z', 'label' => 'Audit révisions'] : null,
                    !$isCommission ? ['route' => 'audit-electoral', 'icon' => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'label' => 'Audit fichier electoral'] : null,
                    !$isCommission ? ['route' => 'audit-communes', 'icon' => 'M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0zM15 11a3 3 0 11-6 0 3 3 0 016 0z', 'label' => 'Audit communes (76)'] : null,
                ]);
                $logistiqueItems = [
                    ['route' => 'logistique.stock.index',     'match' => 'logistique.stock.*',     'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4', 'label' => 'Stock'],
                    ['route' => 'logistique.commandes.index', 'match' => 'logistique.commandes.*', 'icon' => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z', 'label' => 'Commandes'],
                    ['route' => 'logistique.materiels.index', 'match' => 'logistique.materiels.*', 'icon' => 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z', 'label' => 'Articles'],
                    ['route' => 'logistique.cycles.index',    'match' => 'logistique.cycles.*',    'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z', 'label' => 'Cycles'],
                ];
                if (\Illuminate\Support\Facades\Gate::allows('logistique-gerer')) {
                    // Les écrans par cycle s'ouvrent sur le cycle consulté, sinon sur le dernier cycle ouvert ; sans cycle, sur la liste des cycles.
                    $cycleMenu = request()->route('cycle') instanceof \App\Models\Logistique\Cycle
                        ? request()->route('cycle')
                        : \App\Models\Logistique\Cycle::ouverts()->orderByDesc('id')->first();
                    $versCycle = fn (string $route) => $cycleMenu ? route($route, $cycleMenu) : route('logistique.cycles.index');
                    $logistiqueItems = array_merge($logistiqueItems, [
                        ['url' => route('logistique.destinataires.index'), 'match' => ['logistique.destinataires.*', 'logistique.postes.*', 'logistique.correspondances.*', 'logistique.decoupage.*', 'logistique.commissions.*', 'logistique.entites.*'], 'icon' => 'M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0zM15 11a3 3 0 11-6 0 3 3 0 016 0z', 'label' => 'Géographie'],
                        ['url' => $versCycle('logistique.instantanes.index'), 'match' => ['logistique.instantanes.*'], 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z', 'label' => 'Effectifs'],
                        ['url' => $versCycle('logistique.cles.index'), 'match' => ['logistique.cles.*'], 'icon' => 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z', 'label' => 'Clés'],
                        ['url' => $versCycle('logistique.plans.index'), 'match' => ['logistique.plans.*', 'logistique.plan-lignes.*'], 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4', 'label' => 'Plans de dotation'],
                        ['url' => $versCycle('logistique.besoin.show'), 'match' => ['logistique.besoin.*'], 'icon' => 'M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z', 'label' => 'Besoin'],
                        ['url' => $versCycle('logistique.circuit.show'), 'match' => ['logistique.circuit.*'], 'icon' => 'M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7', 'label' => 'Circuit'],
                        ['url' => $versCycle('logistique.expeditions.index'), 'match' => ['logistique.expeditions.*'], 'icon' => 'M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10m10 0H3m10 0h2m4 0h1a1 1 0 001-1v-3.65a1 1 0 00-.22-.624l-3.48-4.35A1 1 0 0017.52 8H14m5 8a2 2 0 11-4 0 2 2 0 014 0zM9 16a2 2 0 11-4 0 2 2 0 014 0z', 'label' => 'Expéditions'],
                        ['url' => $versCycle('logistique.decharges.index'), 'match' => ['logistique.decharges.*', 'logistique.decharge-lignes.*'], 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'label' => 'Décharges'],
                        ['url' => $versCycle('logistique.ecarts.index'), 'match' => ['logistique.ecarts.*'], 'icon' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z', 'label' => 'Écarts'],
                        ['url' => $versCycle('logistique.declarations.a-verifier'), 'match' => ['logistique.declarations.*', 'logistique.clotures-ecart.*'], 'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z', 'label' => 'À vérifier'],
                        ['url' => $versCycle('logistique.reclamations.index'), 'match' => ['logistique.reclamations.*'], 'icon' => 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z', 'label' => 'Réclamations'],
                        ['url' => $versCycle('logistique.suivi.index'), 'match' => ['logistique.suivi.*'], 'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z', 'label' => 'Suivi'],
                        ['url' => route('logistique.appareils.index'), 'match' => ['logistique.appareils.*'], 'icon' => 'M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z', 'label' => 'Appareils'],
                    ]);
                    if (\Illuminate\Support\Facades\Gate::allows('logistique-administrer')) {
                        $logistiqueItems[] = ['url' => route('logistique.utilisateurs.codes'), 'match' => ['logistique.utilisateurs.*'], 'icon' => 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z', 'label' => 'Codes de réception'];
                    }
                }
                $terrainItems = [];
                $iconeDecharges = 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z';
                $iconeCode = 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z';
                $iconeDelegations = 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z';
                $iconeSuivi = 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z';
                if (\Illuminate\Support\Facades\Gate::allows('logistique-terrain')) {
                    $terrainItems[] = ['url' => route('terrain.decharges.index'), 'match' => ['terrain.decharges.*'], 'icon' => $iconeDecharges, 'label' => 'Mes décharges'];
                    $terrainItems[] = ['url' => route('terrain.app'), 'match' => ['terrain.app'], 'icon' => 'M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z', 'label' => 'Application tablette'];
                }
                if (\Illuminate\Support\Facades\Gate::allows('logistique-terrain') && auth()->user()->hasRole(\App\Services\Logistique\CodeReceptionService::ROLES_AUTORITE)) {
                    $terrainItems[] = ['url' => route('terrain.code.edit'), 'match' => ['terrain.code.*'], 'icon' => $iconeCode, 'label' => 'Mon code de réception'];
                    $terrainItems[] = ['url' => route('terrain.delegations.index'), 'match' => ['terrain.delegations.*'], 'icon' => $iconeDelegations, 'label' => 'Délégations'];
                }
                if (\Illuminate\Support\Facades\Gate::allows('logistique-suivi-terrain')) {
                    $terrainItems[] = ['url' => route('terrain.suivi'), 'match' => ['terrain.suivi'], 'icon' => $iconeSuivi, 'label' => auth()->user()->isGouverneur() ? 'Suivi de la région' : 'Suivi du périmètre'];
                }
                $adminItems = [
                    ['route' => 'users.index',  'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z', 'label' => 'Utilisateurs'],
                ];
            @endphp

            @foreach($navItems as $item)
                @php $isActive = request()->routeIs($item['route'].'*'); @endphp
                <a href="{{ route($item['route']) }}"
                   class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all group"
                   :class="'{{ $isActive ? '' : '' }}'"
                   style="{{ $isActive ? 'background:#009A44; color:#fff;' : 'color:#94a3b8;' }}"
                   onmouseover="{{ $isActive ? '' : "this.style.background='rgba(255,255,255,0.08)'; this.style.color='#fff';" }}"
                   onmouseout="{{ $isActive ? '' : "this.style.background=''; this.style.color='#94a3b8';" }}">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $item['icon'] }}"/>
                    </svg>
                    <span x-show="sidebarOpen" class="text-sm font-medium truncate">{{ $item['label'] }}</span>
                </a>
            @endforeach

            @can('logistique-gerer')
                <div x-show="sidebarOpen" class="{{ $isLogistique ? '' : 'pt-4 ' }}pb-1 px-3">
                    <p class="text-xs uppercase tracking-wider text-slate-500 font-semibold">Logistique</p>
                </div>
                @foreach($logistiqueItems as $item)
                    @php $isActive = request()->routeIs($item['match']); @endphp
                    <a href="{{ $item['url'] ?? route($item['route']) }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all"
                       style="{{ $isActive ? 'background:#009A44; color:#fff;' : 'color:#94a3b8;' }}"
                       onmouseover="{{ $isActive ? '' : "this.style.background='rgba(255,255,255,0.08)'; this.style.color='#fff';" }}"
                       onmouseout="{{ $isActive ? '' : "this.style.background=''; this.style.color='#94a3b8';" }}">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $item['icon'] }}"/>
                        </svg>
                        <span x-show="sidebarOpen" class="text-sm font-medium truncate">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            @endcan

            @if($terrainItems !== [])
                <div x-show="sidebarOpen" class="{{ $isTerrainSeul ? '' : 'pt-4 ' }}pb-1 px-3">
                    <p class="text-xs uppercase tracking-wider text-slate-500 font-semibold">Matériel électoral</p>
                </div>
                @foreach($terrainItems as $item)
                    @php $isActive = request()->routeIs($item['match']); @endphp
                    <a href="{{ $item['url'] }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all"
                       style="{{ $isActive ? 'background:#009A44; color:#fff;' : 'color:#94a3b8;' }}"
                       onmouseover="{{ $isActive ? '' : "this.style.background='rgba(255,255,255,0.08)'; this.style.color='#fff';" }}"
                       onmouseout="{{ $isActive ? '' : "this.style.background=''; this.style.color='#94a3b8';" }}">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $item['icon'] }}"/>
                        </svg>
                        <span x-show="sidebarOpen" class="text-sm font-medium truncate">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            @endif

            @if(auth()->user()->isAdmin())
                <div x-show="sidebarOpen" class="pt-4 pb-1 px-3">
                    <p class="text-xs uppercase tracking-wider text-slate-500 font-semibold">Administration</p>
                </div>
                @foreach($adminItems as $item)
                    @php $isActive = request()->routeIs($item['route'].'*'); @endphp
                    <a href="{{ route($item['route']) }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all"
                       style="{{ $isActive ? 'background:#009A44; color:#fff;' : 'color:#94a3b8;' }}"
                       onmouseover="{{ $isActive ? '' : "this.style.background='rgba(255,255,255,0.08)'; this.style.color='#fff';" }}"
                       onmouseout="{{ $isActive ? '' : "this.style.background=''; this.style.color='#94a3b8';" }}">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $item['icon'] }}"/>
                        </svg>
                        <span x-show="sidebarOpen" class="text-sm font-medium truncate">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            @endif
        </nav>

        {{-- Logout --}}
        <div class="px-2 pb-4 border-t border-slate-700 pt-3">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="flex items-center gap-3 px-3 py-2.5 rounded-lg w-full transition-all text-slate-400 hover:text-white"
                        onmouseover="this.style.background='rgba(255,255,255,0.08)'"
                        onmouseout="this.style.background=''">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                    <span x-show="sidebarOpen" class="text-sm font-medium">Déconnexion</span>
                </button>
            </form>
        </div>
    </aside>

    {{-- ══════════════════════════════════════════
         MAIN CONTENT
    ══════════════════════════════════════════ --}}
    <div class="flex-1 flex flex-col overflow-hidden">

        {{-- Top navbar --}}
        <header class="bg-white border-b border-slate-200 flex items-center justify-between px-6 py-3 flex-shrink-0 shadow-sm">
            {{-- Mobile sidebar toggle --}}
            <button @click="mobileOpen = !mobileOpen" class="lg:hidden text-slate-500 hover:text-slate-700">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>

            {{-- Breadcrumb / Page title --}}
            <div class="flex items-center gap-2">
                <h1 class="text-base font-semibold text-slate-800">@yield('page-title', 'Tableau de bord')</h1>
                @hasSection('breadcrumb')
                    <span class="text-slate-300">/</span>
                    @yield('breadcrumb')
                @endif
            </div>

            {{-- Right actions --}}
            <div class="flex items-center gap-4">
                {{-- Notifications bell --}}
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open" class="relative text-slate-500 hover:text-slate-700">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                        </svg>
                        @php $unread = auth()->user()->unreadNotificationsCount(); @endphp
                        @if($unread > 0)
                            <span class="absolute -top-1 -right-1 w-4 h-4 rounded-full text-white text-xs flex items-center justify-center font-bold" style="background:#EE1C25;">
                                {{ $unread > 9 ? '9+' : $unread }}
                            </span>
                        @endif
                    </button>
                    {{-- Dropdown notifs --}}
                    <div x-show="open" @click.outside="open=false"
                         class="absolute right-0 mt-2 w-80 bg-white rounded-xl shadow-xl border border-slate-200 z-50 overflow-hidden"
                         x-cloak>
                        <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100">
                            <span class="font-semibold text-slate-800 text-sm">Notifications</span>
                            @if($unread > 0)
                                <span class="text-xs px-2 py-0.5 rounded-full text-white" style="background:#009A44;">{{ $unread }} nouvelles</span>
                            @endif
                        </div>
                        <div class="max-h-64 overflow-y-auto">
                            @forelse(auth()->user()->notifications()->limit(8)->get() as $notif)
                                <div class="px-4 py-3 border-b border-slate-50 hover:bg-slate-50 transition-colors {{ $notif->lue ? '' : 'bg-green-50' }}">
                                    <p class="text-sm font-medium text-slate-800">{{ $notif->titre }}</p>
                                    <p class="text-xs text-slate-500 mt-0.5">{{ $notif->message }}</p>
                                    <p class="text-xs text-slate-400 mt-1">{{ $notif->created_at->diffForHumans() }}</p>
                                </div>
                            @empty
                                <div class="px-4 py-6 text-center text-slate-400 text-sm">Aucune notification</div>
                            @endforelse
                        </div>
                    </div>
                </div>

                {{-- Role badge --}}
                <div class="hidden sm:flex items-center gap-2">
                    <span class="text-xs px-2.5 py-1 rounded-full font-medium text-white"
                          style="background: {{ match(auth()->user()->role) {
                              'admin' => '#EE1C25',
                              'gouverneur' => '#7c3aed',
                              'prefet' => '#0284c7',
                              'sous_prefet' => '#0891b2',
                              default => '#009A44'
                          } }};">
                        {{ ucfirst(str_replace('_',' ', auth()->user()->role)) }}
                    </span>
                    <span class="text-sm text-slate-600 font-medium">{{ auth()->user()->nom_complet }}</span>
                </div>
            </div>
        </header>

        {{-- Page content --}}
        <main class="flex-1 overflow-y-auto p-6">
            @if(session('success'))
                <div class="mb-4 flex items-center gap-3 p-4 rounded-xl text-white text-sm font-medium shadow"
                     style="background:#009A44;"
                     x-data x-init="setTimeout(() => $el.remove(), 4000)">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    {{ session('success') }}
                </div>
            @endif
            @if(session('warning'))
                <div class="mb-4 flex items-center gap-3 p-4 rounded-xl text-white text-sm font-medium shadow"
                     style="background:#d97706;">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    {{ session('warning') }}
                </div>
            @endif
            @if(session('error'))
                <div class="mb-4 flex items-center gap-3 p-4 rounded-xl text-white text-sm font-medium shadow"
                     style="background:#EE1C25;"
                     x-data x-init="setTimeout(() => $el.remove(), 5000)">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                    {{ session('error') }}
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>

@livewireScripts
@stack('scripts')
</body>
</html>

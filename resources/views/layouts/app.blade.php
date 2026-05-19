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
                $navItems = array_filter([
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

@extends('layouts.app')
@section('title','Utilisateurs')
@section('page-title','Utilisateurs')

@section('content')
<div class="space-y-5">

    <form method="GET" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Nom, email…"
                   class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
            <select name="role" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <option value="">Tous les rôles</option>
                @foreach(['admin'=>'Admin','gouverneur'=>'Gouverneur','prefet'=>'Préfet','sous_prefet'=>'Sous-Préfet','commission'=>'Commission'] as $v => $l)
                    <option value="{{ $v }}" {{ request('role') === $v ? 'selected' : '' }}>{{ $l }}</option>
                @endforeach
            </select>
            <select name="actif" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                <option value="">Tous</option>
                <option value="1" {{ request('actif') === '1' ? 'selected' : '' }}>Actifs</option>
                <option value="0" {{ request('actif') === '0' ? 'selected' : '' }}>Désactivés</option>
            </select>
            <div class="flex gap-2">
                <button class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 flex-1" style="background:#009A44;">Filtrer</button>
                <a href="{{ route('users.index') }}" class="px-3 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">↺</a>
            </div>
        </div>
    </form>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">{{ $users->total() }} utilisateur(s)</h3>
            <a href="{{ route('users.create') }}" class="px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 flex items-center gap-2" style="background:#009A44;">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Nouvel utilisateur
            </a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-semibold">Nom complet</th>
                        <th class="px-5 py-3 text-left font-semibold">Email</th>
                        <th class="px-5 py-3 text-left font-semibold">Rôle</th>
                        <th class="px-5 py-3 text-left font-semibold">Zone</th>
                        <th class="px-5 py-3 text-left font-semibold">Statut</th>
                        <th class="px-5 py-3 text-left font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($users as $u)
                        @php
                            $roleColors = ['admin'=>'#EE1C25','gouverneur'=>'#7c3aed','prefet'=>'#0284c7','sous_prefet'=>'#0891b2','commission'=>'#009A44'];
                            $rc = $roleColors[$u->role] ?? '#666';
                        @endphp
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                                         style="background:{{ $rc }};">
                                        {{ strtoupper(substr($u->name, 0, 1)) }}
                                    </div>
                                    <span class="font-medium text-slate-800">{{ $u->nom_complet }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-slate-600 text-xs">{{ $u->email }}</td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background:{{ $rc }};">
                                    {{ ucfirst(str_replace('_',' ',$u->role)) }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-slate-500 text-xs">{{ $u->zone_label }}</td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $u->actif ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                    {{ $u->actif ? 'Actif' : 'Désactivé' }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex gap-2">
                                    <a href="{{ route('users.edit', $u) }}" class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#0284c7;">Modifier</a>
                                    @if($u->id !== auth()->id())
                                        <form method="POST" action="{{ route('users.destroy', $u) }}" onsubmit="return confirm('Supprimer cet utilisateur ?')">
                                            @csrf @method('DELETE')
                                            <button class="px-2.5 py-1 rounded text-xs text-white hover:opacity-90" style="background:#EE1C25;">Suppr.</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-10 text-center text-slate-400">Aucun utilisateur</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($users->hasPages())
            <div class="px-6 py-4 border-t border-slate-100">{{ $users->links() }}</div>
        @endif
    </div>
</div>
@endsection

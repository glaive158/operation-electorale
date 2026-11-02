@extends('layouts.app')
@section('title','Délégations')
@section('page-title','Délégations')

@php
    use App\Models\Logistique\Delegation;
    $champ = 'w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none';
    $date = fn ($d) => $d?->format('d/m/Y H:i');
    // État lisible d'une délégation à l'instant présent : la fin effective est la première de la fin prévue et de la révocation.
    $etat = function (Delegation $d) use ($maintenant) {
        if ($d->revoquee_le !== null && $d->revoquee_le <= $maintenant) {
            return ['Révoquée le '.$d->revoquee_le->format('d/m/Y H:i'), 'bg-red-100 text-red-700'];
        }
        if ($d->au !== null && $d->au <= $maintenant) {
            return ['Terminée', 'bg-slate-100 text-slate-600'];
        }
        if ($d->du > $maintenant) {
            return ['À venir', 'bg-amber-100 text-amber-700'];
        }
        return ['En cours', 'bg-green-100 text-green-700'];
    };
    $enCours = fn (Delegation $d) => $d->revoquee_le === null && ($d->au === null || $d->au > $maintenant);
@endphp

@section('content')
<div class="space-y-5">
    @include('logistique._erreurs')

    @if($destinataires->isNotEmpty())
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-4">
            <div>
                <h3 class="font-semibold text-slate-800">Déléguer ma signature</h3>
                <p class="text-sm text-slate-600 mt-1">
                    Votre adjoint voit les décharges du destinataire et signe les réceptions avec son propre compte et son propre code.
                    Il ne prépare pas de redistribution. La délégation ne peut pas commencer dans le passé et se révoque à tout moment.
                </p>
            </div>
            <form method="POST" action="{{ route('terrain.delegations.store') }}" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @csrf
                <div>
                    <label for="destinataire_id" class="block text-xs text-slate-500 mb-1">Destinataire</label>
                    <select id="destinataire_id" name="destinataire_id" required class="{{ $champ }}">
                        @foreach($destinataires as $d)
                            <option value="{{ $d->id }}" @selected((int) old('destinataire_id') === $d->id)>{{ $d->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="delegue_user_id" class="block text-xs text-slate-500 mb-1">Délégué</label>
                    <select id="delegue_user_id" name="delegue_user_id" required class="{{ $champ }}">
                        <option value="">Choisir…</option>
                        @foreach($candidats as $c)
                            <option value="{{ $c->id }}" @selected((int) old('delegue_user_id') === $c->id)>{{ $c->nom_complet }} ({{ Delegation::QUALITES[$c->role] ?? $c->role }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="du" class="block text-xs text-slate-500 mb-1">Du</label>
                    <input type="datetime-local" id="du" name="du" required value="{{ old('du', $maintenant->format('Y-m-d\TH:i')) }}" class="{{ $champ }}">
                </div>
                <div>
                    <label for="au" class="block text-xs text-slate-500 mb-1">Au (facultatif : sans fin tant qu'elle n'est pas révoquée)</label>
                    <input type="datetime-local" id="au" name="au" value="{{ old('au') }}" class="{{ $champ }}">
                </div>
                <div class="sm:col-span-2">
                    <label for="motif" class="block text-xs text-slate-500 mb-1">Motif</label>
                    <input type="text" id="motif" name="motif" required maxlength="500" value="{{ old('motif') }}" class="{{ $champ }}">
                </div>
                <div class="sm:col-span-2 flex justify-end">
                    <button type="submit" class="px-6 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Déléguer</button>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100"><h3 class="font-semibold text-slate-800">Délégations accordées</h3></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-slate-500 border-b border-slate-100">
                            <th class="px-4 py-2 font-medium">Destinataire</th>
                            <th class="px-4 py-2 font-medium">Délégué</th>
                            <th class="px-4 py-2 font-medium">Période</th>
                            <th class="px-4 py-2 font-medium">État</th>
                            <th class="px-4 py-2 font-medium">Révocation</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($donnees as $d)
                            @php [$libelle, $classe] = $etat($d); @endphp
                            <tr class="border-b border-slate-50 align-top">
                                <td class="px-4 py-2 text-slate-800">{{ $d->destinataire?->libelle }}</td>
                                <td class="px-4 py-2 text-slate-600">{{ $d->delegue?->nom_complet }}</td>
                                <td class="px-4 py-2 text-slate-600">
                                    {{ $date($d->du) }} → {{ $d->au ? $date($d->au) : 'sans fin' }}
                                    <p class="text-xs text-slate-500">{{ $d->motif }}</p>
                                </td>
                                <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classe }}">{{ $libelle }}</span></td>
                                <td class="px-4 py-2">
                                    @if($enCours($d))
                                        <form method="POST" action="{{ route('terrain.delegations.revoquer', $d) }}" class="flex flex-wrap gap-2 items-center"
                                              onsubmit="return confirm('Révoquer cette délégation ?');">
                                            @csrf
                                            <input type="text" name="motif" required maxlength="500" placeholder="Motif" aria-label="Motif de la révocation"
                                                   class="px-2 py-1 rounded-lg border border-slate-300 text-sm focus:outline-none">
                                            <button type="submit" class="px-3 py-1 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#EE1C25;">Révoquer</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">Aucune délégation accordée.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Délégations reçues</h3>
            <p class="text-xs text-slate-500">Tant qu'une délégation est en cours, les décharges du destinataire figurent dans « Mes décharges » et vous en signez les réceptions avec votre propre code.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-slate-500 border-b border-slate-100">
                        <th class="px-4 py-2 font-medium">Destinataire</th>
                        <th class="px-4 py-2 font-medium">Accordée par</th>
                        <th class="px-4 py-2 font-medium">Période</th>
                        <th class="px-4 py-2 font-medium">État</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recues as $d)
                        @php [$libelle, $classe] = $etat($d); @endphp
                        <tr class="border-b border-slate-50 align-top">
                            <td class="px-4 py-2 text-slate-800">{{ $d->destinataire?->libelle }}</td>
                            <td class="px-4 py-2 text-slate-600">{{ $d->autorite?->nom_complet }}</td>
                            <td class="px-4 py-2 text-slate-600">{{ $date($d->du) }} → {{ $d->au ? $date($d->au) : 'sans fin' }}</td>
                            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $classe }}">{{ $libelle }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400">Aucune délégation reçue.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@extends('layouts.app')
@section('title','Nouvelle commande')
@section('page-title','Nouvelle commande')

@section('content')
@php
    $peutViserUnCycle = \Illuminate\Support\Facades\Gate::allows('logistique-administrer');
    $anciennes = old('lignes', [['materiel_id' => '', 'quantite_commandee' => '', 'cycle_id' => '']]);
    $lignesInitiales = array_values(array_map(fn ($l) => [
        'materiel_id' => (string) ($l['materiel_id'] ?? ''),
        'quantite_commandee' => (string) ($l['quantite_commandee'] ?? ''),
        'cycle_id' => (string) ($l['cycle_id'] ?? ''),
    ], $anciennes));
@endphp
<div class="max-w-4xl mx-auto">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
        <h3 class="font-semibold text-slate-800 mb-5">Créer une commande (brouillon)</h3>

        <div class="mb-4">@include('logistique._erreurs')</div>

        <form method="POST" action="{{ route('logistique.commandes.store') }}" class="space-y-5"
              x-data="{ lignes: @js($lignesInitiales) }">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1.5">Référence <span class="text-red-500">*</span></label>
                    <input type="text" name="reference" value="{{ old('reference') }}" required maxlength="50"
                           class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1.5">Fournisseur <span class="text-red-500">*</span></label>
                    <input type="text" name="fournisseur" value="{{ old('fournisseur') }}" required maxlength="200"
                           class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1.5">Date de commande <span class="text-red-500">*</span></label>
                    <input type="date" name="date_commande" value="{{ old('date_commande', now()->format('Y-m-d')) }}" required
                           class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
            </div>

            <div>
                <h4 class="text-sm font-semibold text-slate-700 mb-2">Lignes</h4>
                <div class="space-y-2">
                    <template x-for="(l, i) in lignes" :key="i">
                        <div class="grid grid-cols-1 md:grid-cols-12 gap-2 items-end">
                            <div class="{{ $peutViserUnCycle ? 'md:col-span-5' : 'md:col-span-9' }}">
                                <label class="block text-xs font-medium text-slate-600 mb-1">Article</label>
                                <select :name="`lignes[${i}][materiel_id]`" x-model="l.materiel_id"
                                        class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                                    <option value="">Choisir un article</option>
                                    @foreach($materiels as $m)
                                        <option value="{{ $m->id }}">{{ $m->libelle }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs font-medium text-slate-600 mb-1">Quantité</label>
                                <input type="number" min="1" step="1" :name="`lignes[${i}][quantite_commandee]`" x-model="l.quantite_commandee"
                                       class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                            </div>
                            @if($peutViserUnCycle)
                            <div class="md:col-span-4">
                                <label class="block text-xs font-medium text-slate-600 mb-1">Cycle visé (facultatif)</label>
                                <select :name="`lignes[${i}][cycle_id]`" x-model="l.cycle_id"
                                        class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                                    <option value="">Stock commun</option>
                                    @foreach($cycles as $c)
                                        <option value="{{ $c->id }}">{{ $c->libelle }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @endif
                            <div class="md:col-span-1">
                                <button type="button" @click="lignes.length > 1 && lignes.splice(i, 1)" aria-label="Retirer la ligne"
                                        class="w-full px-2 py-2.5 rounded-lg border border-slate-300 text-slate-500 text-sm hover:bg-slate-50">✕</button>
                            </div>
                        </div>
                    </template>
                </div>
                <button type="button" @click="lignes.push({ materiel_id: '', quantite_commandee: '', cycle_id: '' })"
                        class="mt-3 px-3 py-1.5 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">+ Ajouter une ligne</button>
            </div>

            <div class="flex justify-between pt-2">
                <a href="{{ route('logistique.commandes.index') }}" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">Annuler</a>
                <button type="submit" class="px-6 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90" style="background:#009A44;">Enregistrer le brouillon</button>
            </div>
        </form>
    </div>
</div>
@endsection

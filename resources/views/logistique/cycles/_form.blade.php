@php $c = $cycle ?? null; @endphp
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div class="md:col-span-2">
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Libellé <span class="text-red-500">*</span></label>
        <input type="text" name="libelle" value="{{ old('libelle', $c->libelle ?? '') }}" required maxlength="150"
               class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Type <span class="text-red-500">*</span></label>
        <select name="type" required class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
            @foreach(['scrutin' => 'Scrutin', 'revision' => 'Révision des listes'] as $v => $l)
                <option value="{{ $v }}" {{ old('type', $c->type ?? 'scrutin') === $v ? 'selected' : '' }}>{{ $l }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Date du scrutin</label>
        <input type="date" name="date_scrutin" value="{{ old('date_scrutin', optional($c?->date_scrutin)->format('Y-m-d')) }}"
               class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Majoration distribuée (%) <span class="text-red-500">*</span></label>
        <input type="number" name="majoration_distribuee" step="0.01" min="0" max="100" required
               value="{{ old('majoration_distribuee', $c->majoration_distribuee ?? 10) }}"
               class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Réserve centrale (%) <span class="text-red-500">*</span></label>
        <input type="number" name="reserve_centrale" step="0.01" min="0" max="100" required
               value="{{ old('reserve_centrale', $c->reserve_centrale ?? 0) }}"
               class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Circuit par défaut <span class="text-red-500">*</span></label>
        <select name="circuit_defaut" required class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
            @foreach(['via_prefet' => 'Via le préfet', 'direct' => 'Direct vers la commune'] as $v => $l)
                <option value="{{ $v }}" {{ old('circuit_defaut', $c->circuit_defaut ?? 'via_prefet') === $v ? 'selected' : '' }}>{{ $l }}</option>
            @endforeach
        </select>
    </div>
</div>

@php $p = $poste ?? null; @endphp
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Pays <span class="text-red-500">*</span></label>
        <input type="text" name="pays" value="{{ old('pays', $p->pays ?? '') }}" required maxlength="100"
               class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Ville <span class="text-red-500">*</span></label>
        <input type="text" name="ville" value="{{ old('ville', $p->ville ?? '') }}" required maxlength="100"
               class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Type <span class="text-red-500">*</span></label>
        <select name="type" required class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
            @foreach(['consulat' => 'Consulat', 'ambassade' => 'Ambassade'] as $v => $l)
                <option value="{{ $v }}" {{ old('type', $p->type ?? 'consulat') === $v ? 'selected' : '' }}>{{ $l }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Nom du poste <span class="text-red-500">*</span></label>
        <input type="text" name="nom" value="{{ old('nom', $p->nom ?? '') }}" required maxlength="200"
               class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
    </div>
</div>
@if($p)
    <label class="flex items-center gap-2 text-sm text-slate-600 pt-1">
        <input type="checkbox" name="actif" value="1" {{ old('actif', $p->actif) ? 'checked' : '' }} class="rounded border-slate-300" style="accent-color:#009A44;">
        Poste actif
    </label>
@endif

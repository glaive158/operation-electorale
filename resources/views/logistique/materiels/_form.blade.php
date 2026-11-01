@php $m = $materiel ?? null; @endphp
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div class="md:col-span-2">
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Libellé <span class="text-red-500">*</span></label>
        <input type="text" name="libelle" value="{{ old('libelle', $m->libelle ?? '') }}" required maxlength="200"
               class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Catégorie <span class="text-red-500">*</span></label>
        <select name="categorie" required class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
            @foreach(\App\Models\Logistique\Materiel::CATEGORIES as $v => $l)
                <option value="{{ $v }}" {{ old('categorie', $m->categorie ?? '') === $v ? 'selected' : '' }}>{{ $l }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Regroupement</label>
        <input type="text" name="regroupement" value="{{ old('regroupement', $m->regroupement ?? '') }}" maxlength="100"
               class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Ordre d'affichage</label>
        <input type="number" name="ordre" min="0" value="{{ old('ordre', $m->ordre ?? 0) }}"
               class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Unité de stock</label>
        <input type="text" name="unite_stock" value="{{ old('unite_stock', $m->unite_stock ?? 'pièce') }}" maxlength="30"
               class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Unité de distribution</label>
        <input type="text" name="unite_distribution" value="{{ old('unite_distribution', $m->unite_distribution ?? '') }}" maxlength="30"
               placeholder="ex. paquet, carton"
               class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Facteur de conditionnement</label>
        <input type="number" name="facteur_conditionnement" min="2" value="{{ old('facteur_conditionnement', $m->facteur_conditionnement ?? '') }}"
               placeholder="pièces par unité de distribution"
               class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
    </div>
</div>

<div class="flex flex-wrap gap-x-6 gap-y-2 pt-1">
    <label class="flex items-center gap-2 text-sm text-slate-600">
        <input type="hidden" name="indivisible" value="0">
        <input type="checkbox" name="indivisible" value="1" {{ old('indivisible', $m->indivisible ?? true) ? 'checked' : '' }} class="rounded border-slate-300" style="accent-color:#009A44;">
        Indivisible
    </label>
    <label class="flex items-center gap-2 text-sm text-slate-600">
        <input type="hidden" name="reutilisable" value="0">
        <input type="checkbox" name="reutilisable" value="1" {{ old('reutilisable', $m->reutilisable ?? false) ? 'checked' : '' }} class="rounded border-slate-300" style="accent-color:#009A44;">
        Réutilisable
    </label>
    @if($m)
        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="hidden" name="actif" value="0">
            <input type="checkbox" name="actif" value="1" {{ old('actif', $m->actif) ? 'checked' : '' }} class="rounded border-slate-300" style="accent-color:#009A44;">
            Article actif
        </label>
    @endif
</div>

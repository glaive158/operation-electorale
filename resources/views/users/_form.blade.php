@php
    $u              = $user ?? null;
    $existRegionId  = old('region_id',          $u->region_id          ?? '');
    $existDeptId    = old('departement_id',      $u->departement_id     ?? '');
    $existArrId     = old('arrondissement_id',   $u->arrondissement_id  ?? '');
    $existCommuneId = old('commune_id',          $u->commune_id         ?? '');
    $existDeptNom   = old('departement_nom',     $u->departement_nom    ?? '');
    $existArrNom    = old('arrondissement_nom',  $u->arrondissement_nom ?? '');
    $existCommuneNom= old('commune_nom',         $u->commune_nom        ?? '');
    $existRegionNom = old('region_nom',          $u->region_nom         ?? '');
@endphp

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Prénom</label>
        <input type="text" name="prenom" value="{{ old('prenom', $u->prenom ?? '') }}"
               class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Nom <span class="text-red-500">*</span></label>
        <input type="text" name="name" value="{{ old('name', $u->name ?? '') }}" required
               class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Email <span class="text-red-500">*</span></label>
        <input type="email" name="email" value="{{ old('email', $u->email ?? '') }}" required
               class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Téléphone</label>
        <input type="text" name="telephone" value="{{ old('telephone', $u->telephone ?? '') }}"
               class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">
            Mot de passe {{ isset($u) ? '(laisser vide = inchangé)' : '' }}
            @unless(isset($u)) <span class="text-red-500">*</span> @endunless
        </label>
        <input type="password" name="password" {{ isset($u) ? '' : 'required' }}
               class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
    </div>
    <div>
        <label class="block text-xs font-medium text-slate-600 mb-1.5">Rôle <span class="text-red-500">*</span></label>
        <select name="role" id="role_select" required
                class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
            @foreach(['admin'=>'Admin','gouverneur'=>'Gouverneur','prefet'=>'Préfet','sous_prefet'=>'Sous-Préfet','commission'=>'Commission'] as $v => $l)
                <option value="{{ $v }}" {{ old('role', $u->role ?? '') === $v ? 'selected' : '' }}>{{ $l }}</option>
            @endforeach
        </select>
    </div>
</div>

{{-- Zone fields — cascade selects --}}
<div id="zone_fields" class="border border-slate-200 rounded-xl p-4 mt-1 space-y-3" style="display:none;">
    <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Zone géographique</p>

    {{-- Hidden inputs for nom values (submitted with form) --}}
    <input type="hidden" name="region_nom"         id="region_nom_h"  value="{{ $existRegionNom }}">
    <input type="hidden" name="departement_nom"    id="dept_nom_h"    value="{{ $existDeptNom }}">
    <input type="hidden" name="arrondissement_nom" id="arr_nom_h"     value="{{ $existArrNom }}">
    <input type="hidden" name="commune_nom"        id="commune_nom_h" value="{{ $existCommuneNom }}">

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">

        {{-- Région --}}
        <div id="field_region">
            <label class="block text-xs font-medium text-slate-600 mb-1">Région <span class="text-red-500">*</span></label>
            <select name="region_id" id="region_sel"
                    class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                <option value="">-- Sélectionner --</option>
                @foreach($regions as $r)
                    <option value="{{ $r->id }}" {{ (string)$existRegionId === (string)$r->id ? 'selected' : '' }}>
                        {{ $r->nom }}
                    </option>
                @endforeach
            </select>
        </div>

        {{-- Département --}}
        <div id="field_departement" style="display:none;">
            <label class="block text-xs font-medium text-slate-600 mb-1">Département <span class="text-red-500">*</span></label>
            <select name="departement_id" id="dept_sel"
                    class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                <option value="">-- Sélectionner une région --</option>
            </select>
        </div>

        {{-- Arrondissement --}}
        <div id="field_arrondissement" style="display:none;">
            <label class="block text-xs font-medium text-slate-600 mb-1">Arrondissement <span class="text-red-500">*</span></label>
            <select name="arrondissement_id" id="arr_sel"
                    class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                <option value="">-- Sélectionner un département --</option>
            </select>
        </div>

        {{-- Commune --}}
        <div id="field_commune" style="display:none;">
            <label class="block text-xs font-medium text-slate-600 mb-1">Commune <span class="text-red-500">*</span></label>
            <select name="commune_id" id="commune_sel"
                    class="w-full px-3 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                <option value="">-- Sélectionner un arrondissement --</option>
            </select>
        </div>

    </div>
</div>

<div class="flex items-center gap-2 pt-1">
    <input type="checkbox" name="actif" value="1" id="chk_actif"
           {{ old('actif', $u->actif ?? true) ? 'checked' : '' }}
           class="rounded border-slate-300" style="accent-color:#009A44;">
    <label for="chk_actif" class="text-sm text-slate-600">Compte actif</label>
</div>

<script>
(function() {
    /* All geo data pre-loaded — no AJAX needed */
    var GEO = {
        depts:    @json($depts->groupBy('region_id')),
        arrs:     @json($arrs->groupBy('departement_id')),
        communes: @json($communes->groupBy('arrondissement_id')),
    };

    var roleFields = {
        admin:       [],
        gouverneur:  ['region'],
        prefet:      ['region','departement'],
        sous_prefet: ['region','departement','arrondissement'],
        commission:  ['region','departement','arrondissement','commune'],
    };

    function el(id) { return document.getElementById(id); }

    function fillSelect(sel, items, placeholder) {
        if (!sel) return;
        sel.innerHTML = '<option value="">' + placeholder + '</option>';
        (items || []).forEach(function(d) {
            var o = document.createElement('option');
            o.value = d.id; o.textContent = d.nom;
            sel.appendChild(o);
        });
    }

    function setHidden(id, val) { var e = el(id); if (e) e.value = val || ''; }

    function showZoneFields(role) {
        var show = roleFields[role] || [];
        var box  = el('zone_fields');
        if (box) box.style.display = show.length ? '' : 'none';
        ['region','departement','arrondissement','commune'].forEach(function(f) {
            var e = el('field_' + f);
            if (e) e.style.display = show.indexOf(f) !== -1 ? '' : 'none';
        });
    }

    function onRegionChange() {
        var rid = el('region_sel').value;
        var nom = rid ? el('region_sel').options[el('region_sel').selectedIndex].text : '';
        setHidden('region_nom_h', nom);
        fillSelect(el('dept_sel'),    [], '-- Sélectionner --'); setHidden('dept_nom_h', '');
        fillSelect(el('arr_sel'),     [], '-- Sélectionner un département --'); setHidden('arr_nom_h', '');
        fillSelect(el('commune_sel'), [], '-- Sélectionner un arrondissement --'); setHidden('commune_nom_h', '');
        if (!rid) return;
        fillSelect(el('dept_sel'), GEO.depts[rid] || [], '-- Sélectionner --');
    }

    function onDeptChange() {
        var did = el('dept_sel').value;
        var nom = did ? el('dept_sel').options[el('dept_sel').selectedIndex].text : '';
        setHidden('dept_nom_h', nom);
        fillSelect(el('arr_sel'),     [], '-- Sélectionner --'); setHidden('arr_nom_h', '');
        fillSelect(el('commune_sel'), [], '-- Sélectionner un arrondissement --'); setHidden('commune_nom_h', '');
        if (!did) return;
        fillSelect(el('arr_sel'), GEO.arrs[did] || [], '-- Sélectionner --');
    }

    function onArrChange() {
        var aid = el('arr_sel').value;
        var nom = aid ? el('arr_sel').options[el('arr_sel').selectedIndex].text : '';
        setHidden('arr_nom_h', nom);
        fillSelect(el('commune_sel'), [], '-- Sélectionner --'); setHidden('commune_nom_h', '');
        if (!aid) return;
        fillSelect(el('commune_sel'), GEO.communes[aid] || [], '-- Sélectionner --');
    }

    function onCommuneChange() {
        var csel = el('commune_sel');
        var nom  = csel.value ? csel.options[csel.selectedIndex].text : '';
        setHidden('commune_nom_h', nom);
    }

    /* Attach events */
    var rSel = el('region_sel');  if (rSel) rSel.onchange = onRegionChange;
    var dSel = el('dept_sel');    if (dSel) dSel.onchange = onDeptChange;
    var aSel = el('arr_sel');     if (aSel) aSel.onchange = onArrChange;
    var cSel = el('commune_sel'); if (cSel) cSel.onchange = onCommuneChange;

    var roleSel = el('role_select');
    if (roleSel) {
        roleSel.onchange = function() { showZoneFields(this.value); };
        showZoneFields(roleSel.value);
    }

    /* Pre-populate cascade for edit mode */
    var preRegionId  = {{ $existRegionId  ? (int)$existRegionId  : 'null' }};
    var preDeptId    = {{ $existDeptId    ? (int)$existDeptId    : 'null' }};
    var preArrId     = {{ $existArrId     ? (int)$existArrId     : 'null' }};
    var preCommuneId = {{ $existCommuneId ? (int)$existCommuneId : 'null' }};

    if (preRegionId && rSel) {
        fillSelect(dSel, GEO.depts[preRegionId] || [], '-- Sélectionner --');
        if (preDeptId && dSel) {
            dSel.value = preDeptId;
            setHidden('dept_nom_h', dSel.options[dSel.selectedIndex] ? dSel.options[dSel.selectedIndex].text : '');
            fillSelect(aSel, GEO.arrs[preDeptId] || [], '-- Sélectionner --');
            if (preArrId && aSel) {
                aSel.value = preArrId;
                setHidden('arr_nom_h', aSel.options[aSel.selectedIndex] ? aSel.options[aSel.selectedIndex].text : '');
                fillSelect(cSel, GEO.communes[preArrId] || [], '-- Sélectionner --');
                if (preCommuneId && cSel) {
                    cSel.value = preCommuneId;
                    setHidden('commune_nom_h', cSel.options[cSel.selectedIndex] ? cSel.options[cSel.selectedIndex].text : '');
                }
            }
        }
    }
})();
</script>

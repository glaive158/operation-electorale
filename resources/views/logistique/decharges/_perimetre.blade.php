{{-- Périmètre d'un lot : un seul critère à la fois ; vide = tout le cycle. Variables : $departements, $regions. --}}
<select name="filtre[departement_id]" aria-label="Département" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
    <option value="">Tous les départements</option>
    @foreach($departements as $d)
        <option value="{{ $d['departement_id'] }}" @selected((string) request('filtre.departement_id') === (string) $d['departement_id'])>{{ $d['nom'] }}</option>
    @endforeach
</select>
<select name="filtre[region_id]" aria-label="Région" class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
    <option value="">Toutes les régions</option>
    @foreach($regions as $r)
        <option value="{{ $r->region_id }}" @selected((string) request('filtre.region_id') === (string) $r->region_id)>{{ $r->region_nom }}</option>
    @endforeach
</select>
<label class="inline-flex items-center gap-2 text-sm text-slate-600">
    <input type="checkbox" name="filtre[etranger]" value="1" @checked(request('filtre.etranger'))> Postes à l'étranger
</label>

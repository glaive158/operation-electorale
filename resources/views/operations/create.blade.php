@extends('layouts.app')
@section('title','Nouvelle opération')
@section('page-title','Nouvelle opération')

@section('content')
<div class="space-y-4">

    {{-- EN-TÊTE OFFICIEL --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 text-center">
        <p class="text-xs font-bold text-slate-700 uppercase tracking-wide">République du Sénégal — Un Peuple · Un But · Une Foi</p>
        <p class="text-xs text-slate-600 mt-0.5">Ministère de l'Intérieur et de la Sécurité Publique</p>
        <p class="text-sm font-bold mt-1" style="color:#009A44;">Formulaire de demande d'une opération sur les listes électorales</p>
    </div>

    {{-- IDENTIFICATION DE LA COMMISSION --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-5 py-2.5 border-b border-slate-200 bg-slate-800">
            <span class="text-xs font-bold text-white uppercase tracking-wide">Identification de la commission administrative</span>
        </div>
        <div class="p-4">
            <div class="flex flex-wrap gap-6">
                @if($user->isCommission())
                    <div><span class="block text-xs text-slate-400">Région</span><span class="font-semibold text-sm text-slate-800">{{ $user->region_nom ?? '—' }}</span></div>
                    <div><span class="block text-xs text-slate-400">Arrondissement</span><span class="font-semibold text-sm text-slate-800">{{ $user->arrondissement_nom ?? '—' }}</span></div>
                    <div><span class="block text-xs text-slate-400">Commune</span><span class="font-semibold text-sm text-slate-800">{{ $user->commune_nom ?? '—' }}</span></div>
                    <div><span class="block text-xs text-slate-400">Compte</span><span class="inline-block px-2 py-0.5 rounded text-xs text-white font-medium" style="background:#0284c7;">Commission</span></div>
                @elseif($user->isGouverneur())
                    <div><span class="block text-xs text-slate-400">Région</span><span class="font-semibold text-sm text-slate-800">{{ $user->region_nom ?? '—' }}</span></div>
                    <div><span class="block text-xs text-slate-400">Compte</span><span class="inline-block px-2 py-0.5 rounded text-xs text-white font-medium" style="background:#7c3aed;">Gouverneur</span></div>
                @elseif($user->isPrefet())
                    <div><span class="block text-xs text-slate-400">Région</span><span class="font-semibold text-sm text-slate-800">{{ $user->region_nom ?? '—' }}</span></div>
                    <div><span class="block text-xs text-slate-400">Département</span><span class="font-semibold text-sm text-slate-800">{{ $user->departement_nom ?? '—' }}</span></div>
                    <div><span class="block text-xs text-slate-400">Compte</span><span class="inline-block px-2 py-0.5 rounded text-xs text-white font-medium" style="background:#d97706;">Préfet</span></div>
                @elseif($user->isSousPrefet())
                    <div><span class="block text-xs text-slate-400">Région</span><span class="font-semibold text-sm text-slate-800">{{ $user->region_nom ?? '—' }}</span></div>
                    <div><span class="block text-xs text-slate-400">Arrondissement</span><span class="font-semibold text-sm text-slate-800">{{ $user->arrondissement_nom ?? '—' }}</span></div>
                    <div><span class="block text-xs text-slate-400">Compte</span><span class="inline-block px-2 py-0.5 rounded text-xs text-white font-medium" style="background:#0891b2;">Sous-Préfet</span></div>
                @else
                    <div><span class="block text-xs text-slate-400">Compte</span><span class="inline-block px-2 py-0.5 rounded text-xs text-white font-medium" style="background:#EE1C25;">Administrateur</span></div>
                    <div><span class="block text-xs text-slate-400">Zone</span><span class="font-semibold text-sm text-slate-800">Toutes zones</span></div>
                @endif
                <div><span class="block text-xs text-slate-400">Utilisateur</span><span class="font-semibold text-sm text-slate-800">{{ $user->nom_complet }}</span></div>
            </div>
        </div>
    </div>

    {{-- TYPE SELECTOR --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <p class="text-xs text-slate-500 mb-3 italic">Pour toute demande, la présentation de l'original de la carte d'identité biométrique CEDEAO est obligatoire.</p>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3" id="type_tabs">
            @php
                $types = [
                    ['val'=>'inscription',  'num'=>'1', 'label'=>'Inscription',          'sub'=>'sur liste électorale',           'color'=>'#009A44'],
                    ['val'=>'modification', 'num'=>'2', 'label'=>'Modification',          'sub'=>'changement commune / adresse',    'color'=>'#ca8a04'],
                    ['val'=>'changement',   'num'=>'3', 'label'=>'Changement de statut',  'sub'=>'civil ↔ militaire/paramilitaire', 'color'=>'#0284c7'],
                    ['val'=>'radiation',    'num'=>'4', 'label'=>'Radiation',              'sub'=>"d'un électeur",                  'color'=>'#EE1C25'],
                ];
            @endphp
            @foreach($types as $t)
                <button type="button" onclick="setType('{{ $t['val'] }}')"
                        id="tab_{{ $t['val'] }}"
                        class="flex flex-col items-start gap-1 p-3 rounded-xl border-2 transition-all text-left"
                        data-color="{{ $t['color'] }}"
                        style="{{ $type === $t['val'] ? "border-color:{$t['color']}; background:{$t['color']}10;" : 'border-color:#e2e8f0;' }}">
                    <span class="text-xs font-bold" style="color:{{ $t['color'] }}">{{ $t['num'] }} — {{ $t['label'] }}</span>
                    <span class="text-xs text-slate-500">{{ $t['sub'] }}</span>
                </button>
            @endforeach
        </div>
    </div>

    {{-- FORM --}}
    <form method="POST" action="{{ route('operations.store') }}" id="op_form">
        @csrf
        <input type="hidden" name="type" id="input_type" value="{{ $type }}">

        @if($errors->any())
            <div class="p-4 rounded-xl text-white text-sm mb-4" style="background:#EE1C25;">
                <ul class="space-y-1">@foreach($errors->all() as $err)<li>• {{ $err }}</li>@endforeach</ul>
            </div>
        @endif

        {{-- ── COLONNES : DEMANDEUR | SECTIONS CONDITIONNELLES ── --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">

            {{-- GAUCHE : DEMANDEUR --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 h-fit">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-7 h-7 rounded-full text-white flex items-center justify-center text-xs font-bold" style="background:#009A44;">1</div>
                    <h3 class="font-semibold text-slate-800 text-sm">Identification du demandeur</h3>
                </div>

                <div class="flex gap-3 mb-4">
                    <div class="flex-1">
                        <label class="block text-xs font-medium text-slate-600 mb-1">NIN / CNI du demandeur <span class="text-red-500">*</span></label>
                        <input type="text" name="nin_demandeur" id="nin_demandeur"
                               value="{{ old('nin_demandeur', request('nin_demandeur')) }}"
                               class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-green-500"
                               placeholder="Ex: 1870199001178" maxlength="20" required>
                    </div>
                    <div class="flex items-end">
                        <button type="button" id="btn_verif_demandeur"
                                class="px-5 py-2 rounded-lg text-white font-medium text-sm hover:opacity-90"
                                style="background:#009A44;">Vérifier</button>
                    </div>
                </div>
                <div id="verif_result" class="hidden mb-4 p-3 rounded-lg text-sm"></div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Nom <span class="text-red-500">*</span></label>
                        <input type="text" name="nom_demandeur" id="nom_demandeur"
                               value="{{ old('nom_demandeur', request('nom_demandeur')) }}"
                               class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500" required>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Prénom <span class="text-red-500">*</span></label>
                        <input type="text" name="prenom_demandeur" id="prenom_demandeur"
                               value="{{ old('prenom_demandeur', request('prenom_demandeur')) }}"
                               class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500" required>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Date de naissance</label>
                        <input type="date" name="datenaiss_demandeur" id="datenaiss_demandeur"
                               value="{{ old('datenaiss_demandeur', request('datenaiss_demandeur')) }}"
                               class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Lieu de naissance</label>
                        <input type="text" name="lieunaiss_demandeur" id="lieunaiss_demandeur"
                               value="{{ old('lieunaiss_demandeur') }}"
                               class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Téléphone</label>
                        <input type="text" name="tel_demandeur"
                               value="{{ old('tel_demandeur') }}"
                               class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                               placeholder="7X XXX XX XX">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Adresse</label>
                        <input type="text" name="adresse_demandeur"
                               value="{{ old('adresse_demandeur') }}"
                               class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                </div>
                <div class="flex gap-6 mt-3">
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="militaire" value="1" {{ old('militaire') ? 'checked' : '' }} style="accent-color:#009A44;">
                        Corps militaire / paramilitaire
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="handicap" value="1" {{ old('handicap') ? 'checked' : '' }} style="accent-color:#009A44;">
                        Handicap réduisant la mobilité
                    </label>
                </div>
            </div>

            {{-- DROITE : SECTIONS CONDITIONNELLES --}}
            <div class="space-y-4">

                {{-- ── SECTION ÉLECTORALE ── --}}
                <div id="section_electoral" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5"
                     style="{{ in_array($type,['inscription','modification']) ? '' : 'display:none;' }}">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-7 h-7 rounded-full text-white flex items-center justify-center text-xs font-bold" style="background:#009A44;">2</div>
                        <h3 class="font-semibold text-slate-800 text-sm" id="titre_electoral">Informations électorales</h3>
                    </div>

                    <div id="ancienne_situation" class="hidden mb-3 p-3 rounded-lg text-xs" style="background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe;"></div>

                    <input type="hidden" name="commune_id"      id="elect_commune_id"      value="{{ old('commune_id') }}">
                    <input type="hidden" name="commune_nom"     id="elect_commune_nom"     value="{{ old('commune_nom') }}">
                    <input type="hidden" name="departement_nom" id="elect_departement_nom" value="{{ old('departement_nom') }}">

                    @if($user->isCommission())
                        <div class="mb-3 p-3 rounded-lg text-sm" style="background:#f0fdf4; border:1px solid #bbf7d0;">
                            <span class="text-xs text-slate-500">Commune de la commission :</span>
                            <span class="font-semibold text-slate-800 ml-1">{{ $user->commune_nom ?? '—' }}</span>
                            @if($user->departement_nom)
                                <span class="text-slate-400 mx-2">|</span>
                                <span class="text-xs text-slate-500">Département :</span>
                                <span class="font-semibold text-slate-800 ml-1">{{ $user->departement_nom }}</span>
                            @endif
                        </div>
                    @else
                        <div class="grid grid-cols-2 gap-3 mb-3">
                            @if($user->isAdmin())
                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Région</label>
                                <select id="elect_region_sel" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                                    <option value="">-- Sélectionner --</option>
                                    @foreach($regions as $r)
                                        <option value="{{ $r->id }}">{{ $r->nom }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @else
                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Région</label>
                                <p class="px-3 py-2 rounded-lg border border-slate-200 text-sm bg-slate-50 text-slate-700">{{ $user->region_nom ?? '—' }}</p>
                            </div>
                            @endif

                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Département <span class="text-red-500">*</span></label>
                                @if($user->isPrefet())
                                    <p class="px-3 py-2 rounded-lg border border-slate-200 text-sm bg-slate-50 text-slate-700">{{ $user->departement_nom ?? '—' }}</p>
                                @else
                                    <select id="elect_dept_sel" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                                        <option value="">-- Sélectionner --</option>
                                    </select>
                                @endif
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Arrondissement</label>
                                @if($user->isSousPrefet())
                                    <p class="px-3 py-2 rounded-lg border border-slate-200 text-sm bg-slate-50 text-slate-700">{{ $user->arrondissement_nom ?? '—' }}</p>
                                @else
                                    <select id="elect_arr_sel" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                                        <option value="">-- Sélectionner --</option>
                                    </select>
                                @endif
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Commune <span class="text-red-500">*</span></label>
                                <select id="elect_commune_sel" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                                    <option value="">-- Sélectionner --</option>
                                </select>
                            </div>
                        </div>
                    @endif

                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Adresse électorale</label>
                        <select name="adresse_electorale" id="adresse_electorale"
                                class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                            @if($user->isCommission())
                                <option value="">-- Chargement... --</option>
                            @else
                                <option value="">-- Sélectionner une commune d'abord --</option>
                            @endif
                            @if(old('adresse_electorale'))
                                <option value="{{ old('adresse_electorale') }}" selected>{{ old('adresse_electorale') }}</option>
                            @endif
                        </select>
                    </div>
                </div>

                {{-- ── CHANGEMENT DE STATUT ── --}}
                <div id="section_changement" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5"
                     style="{{ $type === 'changement' ? '' : 'display:none;' }}">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-7 h-7 rounded-full text-white flex items-center justify-center text-xs font-bold" style="background:#d97706;">2</div>
                        <h3 class="font-semibold text-slate-800 text-sm">3 — Changement de statut</h3>
                    </div>
                    <p class="text-xs text-slate-500 mb-3 italic">En application des articles L.27 et R.37 du Code électoral</p>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-2">Sens du changement <span class="text-red-500">*</span></label>
                            <div class="flex flex-col gap-2">
                                <label class="flex items-start gap-2 text-sm text-slate-700">
                                    <input type="radio" name="statut_changement" value="civil_vers_militaire"
                                           {{ old('statut_changement') === 'civil_vers_militaire' ? 'checked' : '' }}
                                           class="mt-0.5" style="accent-color:#0284c7;">
                                    <span>Électeur civil passant dans un corps militaire ou paramilitaire</span>
                                </label>
                                <label class="flex items-start gap-2 text-sm text-slate-700">
                                    <input type="radio" name="statut_changement" value="militaire_vers_civil"
                                           {{ old('statut_changement') === 'militaire_vers_civil' ? 'checked' : '' }}
                                           class="mt-0.5" style="accent-color:#0284c7;">
                                    <span>Électeur militaire ou paramilitaire redevenu civil</span>
                                </label>
                            </div>
                        </div>
                        <div class="border-t border-slate-100 pt-3">
                            <p class="text-xs font-medium text-slate-600 mb-2">Modification d'adresse électorale également ?</p>
                            <div class="flex gap-4">
                                <label class="flex items-center gap-2 text-sm text-slate-600">
                                    <input type="radio" name="avec_modification" value="0" id="avec_modif_non" checked style="accent-color:#0284c7;">Non
                                </label>
                                <label class="flex items-center gap-2 text-sm text-slate-600">
                                    <input type="radio" name="avec_modification" value="1" id="avec_modif_oui"
                                           {{ old('avec_modification') == '1' ? 'checked' : '' }} style="accent-color:#0284c7;">Oui
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ── RADIATION ── --}}
                <div id="section_radiation" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5"
                     style="{{ $type === 'radiation' ? '' : 'display:none;' }}">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-7 h-7 rounded-full text-white flex items-center justify-center text-xs font-bold" style="background:#EE1C25;">2</div>
                        <h3 class="font-semibold text-slate-800 text-sm">4 — Identification de l'électeur à radier</h3>
                    </div>

                    <div class="flex gap-3 mb-3">
                        <div class="flex-1">
                            <label class="block text-xs font-medium text-slate-600 mb-1">NIN de l'électeur à radier <span class="text-red-500">*</span></label>
                            <input type="text" name="nin_electeur_radie" id="nin_electeur_radie"
                                   value="{{ old('nin_electeur_radie') }}"
                                   class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-red-400"
                                   placeholder="NIN de l'électeur à radier" maxlength="20">
                        </div>
                        <div class="flex items-end">
                            <button type="button" id="btn_verif_radie"
                                    class="px-5 py-2 rounded-lg text-white font-medium text-sm hover:opacity-90"
                                    style="background:#EE1C25;">Vérifier</button>
                        </div>
                    </div>
                    <div id="verif_radie_result" class="hidden mb-3 p-3 rounded-lg text-sm"></div>

                    <div class="grid grid-cols-3 gap-3 mb-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Nom <span class="text-red-500">*</span></label>
                            <input type="text" name="nom_electeur_radie" id="nom_electeur_radie"
                                   value="{{ old('nom_electeur_radie') }}"
                                   class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Prénom <span class="text-red-500">*</span></label>
                            <input type="text" name="prenom_electeur_radie" id="prenom_electeur_radie"
                                   value="{{ old('prenom_electeur_radie') }}"
                                   class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">N° Électeur</label>
                            <input type="text" name="numelec_electeur_radie" id="numelec_electeur_radie"
                                   value="{{ old('numelec_electeur_radie') }}"
                                   class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm font-mono focus:outline-none bg-slate-50" readonly>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-2">Motif de la radiation <span class="text-red-500">*</span></label>
                        <div class="space-y-2">
                            <label class="flex items-start gap-2 text-sm text-slate-700">
                                <input type="radio" name="motif_radiation" value="deces"
                                       {{ old('motif_radiation') === 'deces' ? 'checked' : '' }}
                                       class="mt-0.5" style="accent-color:#EE1C25;">
                                <span>1. Décès <span class="text-xs text-slate-400">(certificat de décès + photocopie CIN CEDEAO)</span></span>
                            </label>
                            <label class="flex items-start gap-2 text-sm text-slate-700">
                                <input type="radio" name="motif_radiation" value="incapacite_juridique"
                                       {{ old('motif_radiation') === 'incapacite_juridique' ? 'checked' : '' }}
                                       class="mt-0.5" style="accent-color:#EE1C25;">
                                <span>2. Incapacité juridique <span class="text-xs text-slate-400">(décision de justice)</span></span>
                            </label>
                            <label class="flex items-start gap-2 text-sm text-slate-700">
                                <input type="radio" name="motif_radiation" value="demande_interessee"
                                       {{ old('motif_radiation') === 'demande_interessee' ? 'checked' : '' }}
                                       class="mt-0.5" style="accent-color:#EE1C25;">
                                <span>3. Sur demande de l'intéressé <span class="text-xs text-slate-400">(photocopie CIN CEDEAO)</span></span>
                            </label>
                        </div>
                    </div>
                </div>

            </div>{{-- fin DROITE --}}
        </div>{{-- fin grid --}}

        {{-- ── SUBMIT ── --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center justify-between">
            <a href="{{ route('operations.index') }}"
               class="px-5 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">Annuler</a>
            <button type="submit" id="btn_submit"
                    class="px-8 py-2 rounded-lg text-white font-semibold text-sm hover:opacity-90"
                    style="background:#009A44;">
                Enregistrer la demande
            </button>
        </div>

    </form>
</div>
@endsection

@push('scripts')
<script>
const ROLE            = '{{ $user->role }}';
const USER_COMMUNE_NOM = @json($user->commune_nom ?? '');
const USER_COMMUNE_ID  = {{ $user->commune_id ?? 'null' }};
const USER_REGION_ID   = {{ $user->region_id ?? 'null' }};
const USER_DEPT_ID     = {{ $user->departement_id ?? 'null' }};
const USER_DEPT_NOM    = @json($user->departement_nom ?? '');
const USER_ARR_ID      = {{ $user->arrondissement_id ?? 'null' }};
const NIN_URL          = '{{ route("fichier-electoral.nin", "__NIN__") }}';
const IS_COMMISSION    = ROLE === 'commission';

/* ── Helpers ── */
function showBanner(elId, type, html) {
    const el = document.getElementById(elId);
    if (!el) return;
    const styles = {
        success: 'background:#dcfce7; color:#166534;',
        error:   'background:#fee2e2; color:#991b1b;',
        warning: 'background:#fef3c7; color:#92400e;',
        info:    'background:#eff6ff; color:#1e40af;',
    };
    el.style.cssText = styles[type] || styles.info;
    el.innerHTML = html;
    el.classList.remove('hidden');
}

function hideBanner(elId) {
    const el = document.getElementById(elId);
    if (el) el.classList.add('hidden');
}

function formatDate(s) {
    if (!s) return '';
    const p = s.split('/');
    if (p.length === 3) return p[2] + '-' + p[1].padStart(2,'0') + '-' + p[0].padStart(2,'0');
    return s;
}

function setSubmit(enabled) {
    const btn = document.getElementById('btn_submit');
    btn.disabled = !enabled;
    btn.style.opacity = enabled ? '' : '0.5';
    btn.style.cursor  = enabled ? '' : 'not-allowed';
}

/* ── Cascade API ── */
async function apiGet(url) {
    const r = await fetch(url, { headers: { Accept: 'application/json' } });
    return r.json();
}

function fillSelect(sel, data, placeholder) {
    sel.innerHTML = '<option value="">' + (placeholder || '-- Sélectionner --') + '</option>';
    // Defensive: handle if API returns object instead of array
    if (!Array.isArray(data)) {
        data = data && data.data ? data.data : [];
    }
    data.forEach(d => {
        const o = document.createElement('option');
        o.value = d.id; o.textContent = d.nom;
        sel.appendChild(o);
    });
}

/* ── Type switching ── */
function setType(type) {
    document.getElementById('input_type').value = type;
    document.querySelectorAll('[id^="tab_"]').forEach(btn => {
        const c = btn.dataset.color;
        btn.style.borderColor = btn.id === 'tab_' + type ? c       : '#e2e8f0';
        btn.style.background  = btn.id === 'tab_' + type ? c + '10': '';
    });

    document.getElementById('section_electoral').style.display  = '';
    document.getElementById('section_changement').style.display = 'none';
    document.getElementById('section_radiation').style.display  = 'none';
    hideBanner('verif_result');
    hideBanner('ancienne_situation');
    setSubmit(true);

    const titres = {
        inscription:  'Informations électorales — Inscription',
        modification: 'Nouvelles informations électorales — Modification',
    };
    const titre = document.getElementById('titre_electoral');
    if (titre) titre.textContent = titres[type] || 'Informations électorales';

    if (type === 'inscription' || type === 'modification') {
        document.getElementById('section_electoral').style.display = '';
    } else if (type === 'changement') {
        document.getElementById('section_electoral').style.display = 'none';
        document.getElementById('section_changement').style.display = '';
        document.getElementById('avec_modif_non').checked = true;
    } else if (type === 'radiation') {
        document.getElementById('section_electoral').style.display = 'none';
        document.getElementById('section_radiation').style.display = '';
    }
}

/* ── NIN lookup — demandeur ── */
document.getElementById('btn_verif_demandeur').addEventListener('click', async () => {
    const nin = document.getElementById('nin_demandeur').value.trim();
    if (!nin) return;
    const btn = document.getElementById('btn_verif_demandeur');
    btn.disabled = true; btn.textContent = '…';
    hideBanner('verif_result');
    hideBanner('ancienne_situation');
    setSubmit(true);

    try {
        const d = await apiGet(NIN_URL.replace('__NIN__', encodeURIComponent(nin)));
        const type = document.getElementById('input_type').value;

        if (d.found) {
            document.getElementById('nom_demandeur').value       = d.nom ?? '';
            document.getElementById('prenom_demandeur').value    = d.prenom ?? '';
            document.getElementById('datenaiss_demandeur').value = formatDate(d.datenaiss ?? '');
            document.getElementById('lieunaiss_demandeur').value = d.lieunaiss ?? '';

            if (type === 'inscription') {
                showBanner('verif_result', 'error',
                    '<strong>Attention :</strong> Cette personne est déjà inscrite sur les listes électorales.<br>' +
                    'Région : <strong>' + (d.region||'—') + '</strong> | Dép. : <strong>' + (d.departement||'—') + '</strong> | Commune : <strong>' + (d.commune||'—') + '</strong>');
                setSubmit(false);

            } else if (type === 'modification') {
                showBanner('ancienne_situation', 'info',
                    '<strong>Ancienne situation électorale :</strong> ' +
                    'Région : <strong>' + (d.region||'—') + '</strong> &nbsp;|&nbsp; ' +
                    'Département : <strong>' + (d.departement||'—') + '</strong> &nbsp;|&nbsp; ' +
                    'Commune : <strong>' + (d.commune||'—') + '</strong> &nbsp;|&nbsp; ' +
                    'N° élect. : <strong>' + (d.numelec||'—') + '</strong>');
                showBanner('verif_result', 'success', 'Électeur trouvé — identité chargée.');

            } else if (type === 'changement') {
                if (IS_COMMISSION && USER_COMMUNE_NOM && d.commune !== USER_COMMUNE_NOM) {
                    showBanner('verif_result', 'error',
                        '<strong>Erreur :</strong> Cet électeur est inscrit dans la commune <strong>' + d.commune + '</strong>, ' +
                        'pas dans <strong>' + USER_COMMUNE_NOM + '</strong>.');
                    setSubmit(false);
                } else {
                    showBanner('verif_result', 'success', 'Électeur trouvé — identité chargée.');
                }

            } else if (type === 'radiation') {
                showBanner('verif_result', 'success', 'Demandeur trouvé — identité chargée.');
            }

        } else {
            if (type === 'inscription') {
                showBanner('verif_result', 'success', 'Personne non inscrite — demande d\'inscription possible.');
            } else {
                showBanner('verif_result', 'warning', '<strong>Non trouvé</strong> dans le fichier électoral.');
            }
        }
    } catch(e) {
        showBanner('verif_result', 'warning', 'Erreur lors de la vérification. Continuez manuellement.');
    }
    btn.disabled = false; btn.textContent = 'Vérifier';
});

/* ── NIN lookup — électeur à radier ── */
document.getElementById('btn_verif_radie')?.addEventListener('click', async () => {
    const nin = document.getElementById('nin_electeur_radie').value.trim();
    if (!nin) return;
    const btn = document.getElementById('btn_verif_radie');
    btn.disabled = true; btn.textContent = '…';
    hideBanner('verif_radie_result');

    try {
        const d = await apiGet(NIN_URL.replace('__NIN__', encodeURIComponent(nin)));
        if (d.found) {
            document.getElementById('nom_electeur_radie').value     = d.nom ?? '';
            document.getElementById('prenom_electeur_radie').value  = d.prenom ?? '';
            document.getElementById('numelec_electeur_radie').value = d.numelec ?? '';

            if (IS_COMMISSION && USER_COMMUNE_NOM && d.commune !== USER_COMMUNE_NOM) {
                showBanner('verif_radie_result', 'error',
                    '<strong>Erreur :</strong> Cet électeur est inscrit dans la commune <strong>' + d.commune + '</strong>, ' +
                    'pas dans <strong>' + USER_COMMUNE_NOM + '</strong>.');
                setSubmit(false);
            } else {
                showBanner('verif_radie_result', 'info',
                    '<strong>' + d.prenom + ' ' + d.nom + '</strong> — ' +
                    'Région : ' + (d.region||'—') + ' | Commune : ' + (d.commune||'—') +
                    ' | N° : ' + (d.numelec||'—'));
                setSubmit(true);
            }
        } else {
            showBanner('verif_radie_result', 'warning', '<strong>Non trouvé</strong> dans le fichier électoral.');
        }
    } catch(e) {
        showBanner('verif_radie_result', 'warning', 'Erreur lors de la vérification. Continuez manuellement.');
    }
    btn.disabled = false; btn.textContent = 'Vérifier';
});

/* ── Changement + modification optionnelle ── */
document.querySelectorAll('input[name="avec_modification"]').forEach(r => {
    r.addEventListener('change', () => {
        const show = r.value === '1' && r.checked;
        document.getElementById('section_electoral').style.display = show ? '' : 'none';
        if (show && document.getElementById('titre_electoral')) {
            document.getElementById('titre_electoral').textContent = 'Nouvelles informations électorales — Modification';
        }
    });
});

/* ── Adresses ── */
async function loadAdresses(communeNom) {
    const sel = document.getElementById('adresse_electorale');
    if (!sel || !communeNom) return;
    sel.innerHTML = '<option value="">Chargement...</option>';
    try {
        const data = await apiGet('/geo/commune/adresses?commune_nom=' + encodeURIComponent(communeNom));
        sel.innerHTML = '<option value="">-- Sélectionner --</option>';
        data.forEach(a => { sel.innerHTML += '<option value="' + a + '">' + a + '</option>'; });
    } catch(e) {
        sel.innerHTML = '<option value="">-- Erreur de chargement --</option>';
    }
}

@if(!$user->isCommission())
/* ── Cascade électorale (non-commission) ── */
const electDeptSel    = document.getElementById('elect_dept_sel');
const electArrSel     = document.getElementById('elect_arr_sel');
const electCommuneSel = document.getElementById('elect_commune_sel');

function setElectDeptNom(nom) {
    document.getElementById('elect_departement_nom').value = nom;
}
function setElectCommune(id, nom) {
    document.getElementById('elect_commune_id').value  = id;
    document.getElementById('elect_commune_nom').value = nom;
    loadAdresses(nom);
}

@if($user->isAdmin())
document.getElementById('elect_region_sel')?.addEventListener('change', async function() {
    if (electDeptSel) { electDeptSel.innerHTML = '<option value="">-- Sélectionner --</option>'; }
    if (electArrSel)  { electArrSel.innerHTML  = '<option value="">-- Sélectionner --</option>'; }
    if (electCommuneSel) { electCommuneSel.innerHTML = '<option value="">-- Sélectionner --</option>'; }
    setElectDeptNom(''); setElectCommune('', '');
    if (!this.value) return;
    const data = await apiGet('/geo/region/' + this.value + '/departements');
    if (electDeptSel) fillSelect(electDeptSel, data);
});
@endif

@if(!$user->isPrefet())
electDeptSel?.addEventListener('change', async function() {
    if (electArrSel)  { electArrSel.innerHTML = '<option value="">-- Sélectionner --</option>'; }
    if (electCommuneSel) { electCommuneSel.innerHTML = '<option value="">-- Sélectionner --</option>'; }
    const nom = this.options[this.selectedIndex]?.text || '';
    setElectDeptNom(nom); setElectCommune('', '');
    if (!this.value) return;
    const data = await apiGet('/geo/departement/' + this.value + '/arrondissements');
    if (electArrSel) fillSelect(electArrSel, data);
});
@endif

@if(!$user->isSousPrefet())
electArrSel?.addEventListener('change', async function() {
    if (electCommuneSel) { electCommuneSel.innerHTML = '<option value="">-- Sélectionner --</option>'; }
    setElectCommune('', '');
    if (!this.value) return;
    const data = await apiGet('/geo/arrondissement/' + this.value + '/communes');
    if (electCommuneSel) fillSelect(electCommuneSel, data);
});
@endif

electCommuneSel?.addEventListener('change', function() {
    const nom = this.options[this.selectedIndex]?.text || '';
    setElectCommune(this.value, nom);
});

/* Pre-populate cascade based on role */
(async function() {
    @if($user->isGouverneur() && $user->region_id)
    if (electDeptSel) {
        const data = await apiGet('/geo/region/' + USER_REGION_ID + '/departements');
        fillSelect(electDeptSel, data);
    }
    @elseif($user->isPrefet() && $user->departement_id)
    setElectDeptNom(USER_DEPT_NOM);
    if (electArrSel) {
        const data = await apiGet('/geo/departement/' + USER_DEPT_ID + '/arrondissements');
        fillSelect(electArrSel, data);
    }
    @elseif($user->isSousPrefet() && $user->arrondissement_id)
    setElectDeptNom(USER_DEPT_NOM);
    if (electCommuneSel) {
        const data = await apiGet('/geo/arrondissement/' + USER_ARR_ID + '/communes');
        fillSelect(electCommuneSel, data);
    }
    @endif
})();
@else
/* Commission : pré-charger adresses */
document.getElementById('elect_commune_id').value  = USER_COMMUNE_ID || '';
document.getElementById('elect_commune_nom').value = USER_COMMUNE_NOM || '';
document.getElementById('elect_departement_nom').value = USER_DEPT_NOM || '';
if (USER_COMMUNE_NOM) loadAdresses(USER_COMMUNE_NOM);
@endif

/* Enter on NIN fields */
document.getElementById('nin_demandeur')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('btn_verif_demandeur').click(); }
});
document.getElementById('nin_electeur_radie')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('btn_verif_radie').click(); }
});

/* Pre-select type from URL */
(function() {
    const p = new URLSearchParams(window.location.search);
    const t = p.get('type');
    if (t && ['inscription','modification','changement','radiation'].includes(t)) setType(t);
})();
</script>
@endpush

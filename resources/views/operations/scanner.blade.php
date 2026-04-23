@extends('layouts.app')
@section('title','Scanner les documents — #'.$operation->id)
@section('page-title','Scanner les documents')

@section('content')
<div class="max-w-2xl mx-auto">

    @php
        $tc = ['inscription'=>'#009A44','modification'=>'#ca8a04','changement'=>'#0284c7','radiation'=>'#EE1C25'][$operation->type] ?? '#666';
    @endphp

    {{-- Header --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 mb-4 flex items-center gap-4">
        <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white flex-shrink-0 text-lg" style="background:{{ $tc }};">📎</div>
        <div>
            <p class="font-semibold text-slate-800">Demande #{{ str_pad($operation->id, 6, '0', STR_PAD_LEFT) }} — {{ $operation->type_label }}</p>
            <p class="text-sm text-slate-500">{{ $operation->prenom_demandeur }} {{ $operation->nom_demandeur }} — NIN : {{ $operation->nin_demandeur }}</p>
        </div>
    </div>

    {{-- Errors --}}
    @if($errors->any())
        <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-4">
            <ul class="text-sm text-red-700 space-y-1 list-disc list-inside">
                @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- Form --}}
    <form method="POST" action="{{ route('operations.scanner.store', $operation) }}" enctype="multipart/form-data">
        @csrf
        <div class="space-y-4">

            {{-- Formulaire signé (required) --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 text-white text-sm font-bold" style="background:#009A44;">1</div>
                    <div class="flex-1">
                        <label class="block font-medium text-slate-800 mb-1">
                            Formulaire signé <span class="text-red-500">*</span>
                        </label>
                        <p class="text-xs text-slate-500 mb-3">Le formulaire imprimé, signé par le demandeur et la commission</p>
                        <input type="file" name="formulaire_signe" required accept=".pdf,.jpg,.jpeg,.png"
                               class="block w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:text-white file:cursor-pointer hover:file:opacity-90"
                               style="--file-bg:#009A44;" onchange="previewFile(this,'prev_formulaire')">
                        <p class="text-xs text-slate-400 mt-1">PDF, JPG ou PNG — max 10 Mo</p>
                        <div id="prev_formulaire" class="mt-2 hidden">
                            <img class="rounded-lg max-h-32 border border-slate-200" alt="Aperçu">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Copie CNI (optional) --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 text-white text-sm font-bold bg-slate-400">2</div>
                    <div class="flex-1">
                        <label class="block font-medium text-slate-800 mb-1">
                            Copie CNI
                            <span class="ml-2 text-xs font-normal text-slate-400">(facultatif)</span>
                        </label>
                        <p class="text-xs text-slate-500 mb-3">Copie de la Carte Nationale d'Identité du demandeur</p>
                        <input type="file" name="copie_cni" accept=".pdf,.jpg,.jpeg,.png"
                               class="block w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:text-white file:cursor-pointer hover:file:opacity-90"
                               onchange="previewFile(this,'prev_cni')">
                        <p class="text-xs text-slate-400 mt-1">PDF, JPG ou PNG — max 10 Mo</p>
                        <div id="prev_cni" class="mt-2 hidden">
                            <img class="rounded-lg max-h-32 border border-slate-200" alt="Aperçu">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Certificat de résidence (required for modification) --}}
            @if($operation->type === 'modification')
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 text-white text-sm font-bold" style="background:#0284c7;">3</div>
                    <div class="flex-1">
                        <label class="block font-medium text-slate-800 mb-1">
                            Certificat de résidence <span class="text-red-500">*</span>
                        </label>
                        <p class="text-xs text-slate-500 mb-3">Obligatoire pour les demandes de modification</p>
                        <input type="file" name="certificat_residence" required accept=".pdf,.jpg,.jpeg,.png"
                               class="block w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:text-white file:cursor-pointer hover:file:opacity-90"
                               onchange="previewFile(this,'prev_residence')">
                        <p class="text-xs text-slate-400 mt-1">PDF, JPG ou PNG — max 10 Mo</p>
                        <div id="prev_residence" class="mt-2 hidden">
                            <img class="rounded-lg max-h-32 border border-slate-200" alt="Aperçu">
                        </div>
                    </div>
                </div>
            </div>
            @endif

        </div>

        {{-- Actions --}}
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 mt-4 flex items-center justify-between">
            <a href="{{ route('operations.imprimer', $operation) }}"
               class="px-4 py-2 rounded-lg border border-slate-300 text-slate-600 text-sm hover:bg-slate-50">
                ← Retour à l'impression
            </a>
            <button type="submit"
                    class="px-6 py-2.5 rounded-lg text-white text-sm font-semibold hover:opacity-90 flex items-center gap-2"
                    style="background:#009A44;">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                Valider le scan — Demande complète
            </button>
        </div>
    </form>

</div>

<script>
function previewFile(input, previewId) {
    var container = document.getElementById(previewId);
    var img = container.querySelector('img');
    var file = input.files[0];
    if (!file) { container.classList.add('hidden'); return; }
    if (file.type.startsWith('image/')) {
        var reader = new FileReader();
        reader.onload = function(e) {
            img.src = e.target.result;
            container.classList.remove('hidden');
        };
        reader.readAsDataURL(file);
    } else {
        container.classList.add('hidden');
    }
}
</script>
@endsection

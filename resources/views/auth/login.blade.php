<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion — Opérations Électorales</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
</head>
<body class="min-h-screen flex items-center justify-center" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);">

<div class="w-full max-w-md px-4">
    {{-- Logo / Header --}}
    <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-20 h-20 rounded-full mb-4" style="background: #009A44;">
            <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-white">Opérations Électorales</h1>
        <p class="text-slate-400 text-sm mt-1">République du Sénégal</p>
        <div class="flex justify-center gap-1 mt-2">
            <div class="h-1 w-8 rounded" style="background:#009A44;"></div>
            <div class="h-1 w-8 rounded bg-amber-400"></div>
            <div class="h-1 w-8 rounded" style="background:#EE1C25;"></div>
        </div>
    </div>

    {{-- Card --}}
    <div class="bg-white rounded-2xl shadow-2xl p-8">
        <h2 class="text-xl font-semibold text-slate-800 mb-6">Connexion</h2>

        @if($errors->any())
            <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="space-y-5">
            @csrf
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Adresse e-mail</label>
                <input type="email" name="email" value="{{ old('email') }}" required autofocus
                       class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:outline-none focus:ring-2 text-slate-800"
                       style="--tw-ring-color: #009A44;"
                       placeholder="vous@exemple.sn">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Mot de passe</label>
                <input type="password" name="password" required
                       class="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:outline-none focus:ring-2 text-slate-800"
                       style="--tw-ring-color: #009A44;"
                       placeholder="••••••••">
            </div>
            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="remember" class="rounded border-slate-300" style="accent-color:#009A44;">
                    Se souvenir de moi
                </label>
            </div>
            <button type="submit"
                    class="w-full py-3 rounded-lg font-semibold text-white transition-all hover:opacity-90 active:scale-95"
                    style="background:#009A44;">
                Se connecter
            </button>
        </form>
    </div>

    <p class="text-center text-slate-500 text-xs mt-6">
        Direction Générale des Élections &mdash; Système sécurisé
    </p>
</div>

</body>
</html>

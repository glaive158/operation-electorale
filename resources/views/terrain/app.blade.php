<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#009A44">
    <title>Terrain — Logistique électorale</title>
    <link rel="manifest" href="{{ route('pwa.terrain.manifeste', [], false) }}">
    <link rel="icon" type="image/svg+xml" href="{{ route('pwa.terrain.icone', [], false) }}">
    @vite(['resources/css/app.css', 'resources/js/terrain/app.js'])
</head>
<body class="bg-slate-100 font-sans antialiased min-h-screen text-slate-800">
    {{-- Coquille identique pour tous les comptes (elle est conservée par le service worker) : l'application la remplit. --}}
    <div id="terrain-racine">
        <p class="p-6 text-sm text-slate-600">Chargement de l'application de terrain…</p>
    </div>
    <noscript>
        <p class="p-6 text-sm text-slate-600">Cette application nécessite JavaScript.</p>
    </noscript>
    {{-- Données d'amorçage : un bloc JSON (lu par l'application), jamais un script exécutable. --}}
    <script type="application/json" id="terrain-amorce">{!! json_encode($amorce, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
</body>
</html>

<div class="cover-page">
    <div class="cover-subtitle">COMMUNE : {{ strtoupper($commune) }}</div>
    <div class="cover-title" style="color: #16a34a;">{{ strtoupper($lieu) }}</div>
    <div class="cover-subtitle" style="font-size: 14pt; color: #64748b;">Lieu de Vote</div>
    <div class="cover-info">
        <div><strong>Nombre de bureaux :</strong> {{ $nbBureaux }}</div>
        <div style="margin-top: 10px;"><strong>Total électeurs :</strong> {{ number_format($totalElecteurs) }}</div>
    </div>
</div>

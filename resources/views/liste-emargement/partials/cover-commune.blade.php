<div class="cover-page">
    <div style="width: 80px; height: 100px; border: 4px solid; border-color: #16a34a #f59e0b #dc2626; display: flex; align-items: center; justify-content: center; margin-bottom: 30px;">
        <div style="color: #16a34a; font-size: 40pt;">★</div>
    </div>
    <div class="republic" style="font-size: 14pt;">RÉPUBLIQUE DU SÉNÉGAL</div>
    <div class="devise" style="font-size: 11pt; margin-bottom: 10px;">Un Peuple - Un But - Une Foi</div>
    <div class="ministere" style="font-size: 12pt; margin-bottom: 40px;">MINISTÈRE DE L'INTÉRIEUR</div>
    <div class="cover-title">LISTE D'ÉMARGEMENT</div>
    <div class="election-title" style="font-size: 16pt;">ELECTION PRESIDENTIELLE DU 25 FEVRIER 2024</div>
    <div class="cover-info" style="margin-top: 50px;">
        <div style="margin: 15px 0;"><strong>COMMUNE :</strong> {{ strtoupper($commune) }}</div>
        <div style="margin: 15px 0;"><strong>Nombre de bureaux :</strong> {{ $nbBureaux }}</div>
        <div style="margin: 15px 0;"><strong>Total électeurs :</strong> {{ number_format($totalElecteurs) }}</div>
    </div>
</div>

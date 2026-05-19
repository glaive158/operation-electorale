<div class="page">
    <div class="header">
        <div style="display: flex; align-items: flex-start; gap: 15px; margin-bottom: 15px;">
            <div class="flag-box">
                <div class="flag-star">★</div>
            </div>
            <div style="flex: 1; text-align: center;">
                <div class="republic">RÉPUBLIQUE DU SÉNÉGAL</div>
                <div class="devise">Un Peuple - Un But - Une Foi</div>
                <div style="border-bottom: 1px solid #000; margin: 4px auto; width: 250px;"></div>
                <div class="ministere">MINISTÈRE DE L'INTÉRIEUR</div>
            </div>
        </div>
        <div style="text-align: center;">
            <div class="election-title">ELECTION PRESIDENTIELLE DU 25 FEVRIER 2024</div>
            <div style="border-bottom: 2px dashed #000; margin: 5px auto; width: 450px;"></div>
        </div>
    </div>

    <div class="title" style="text-align: center;">LISTE D'EMARGEMENT DES ELECTEURS</div>

    <div class="location-info">
        <div class="location-row">
            <span class="location-label">REGION :</span>
            <span class="location-value">{{ strtoupper($bureau['departement']) }}</span>
        </div>
        <div class="location-row">
            <span class="location-label">DEPARTEMENT :</span>
            <span class="location-value">{{ strtoupper($bureau['arrondissement']) }}</span>
        </div>
        <div class="location-row commune-row">
            <span class="location-label">COMMUNE :</span>
            <span class="location-value">{{ strtoupper($bureau['commune']) }}</span>
        </div>
    </div>

    <div class="bureau-box">
        <div class="row">
            <span class="label">Lieu de vote :</span>
            <span class="value">{{ strtoupper($bureau['lieu_vote']) }}</span>
        </div>
        <div class="row">
            <span class="label">Bureau de vote :</span>
            <span class="value">{{ substr($bureau['code_bureau'], -2) }}</span>
        </div>
        <div class="row">
            <span class="label">Nombre d'électeurs inscrits :</span>
            <span class="value">{{ $bureau['effectif'] }}</span>
        </div>
    </div>
</div>

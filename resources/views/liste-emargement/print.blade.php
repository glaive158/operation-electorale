<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Liste d'Émargement</title>
    <style>
        @page { size: A4 landscape; margin: 10mm 15mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 9pt; line-height: 1.3; }
        .page { page-break-after: always; position: relative; min-height: 190mm; }
        .blank-page { page-break-after: always; height: 190mm; }

        /* Cover pages */
        .cover-page {
            page-break-after: always;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 190mm;
            text-align: center;
            padding: 20mm;
        }
        .cover-title {
            font-size: 32pt;
            font-weight: bold;
            margin: 20px 0;
            color: #16a34a;
            font-style: italic;
        }
        .cover-subtitle {
            font-size: 20pt;
            margin: 10px 0;
            font-weight: bold;
        }
        .cover-info {
            font-size: 16pt;
            margin: 20px 0;
            padding: 25px 40px;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
        }

        /* Header */
        .header { margin-bottom: 10px; }
        .flag-box {
            width: 70px;
            height: 90px;
            border: 3px solid #000;
            background: linear-gradient(to right, #16a34a 0%, #16a34a 33%, #fbbf24 33%, #fbbf24 66%, #dc2626 66%);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        .flag-star {
            color: #16a34a;
            font-size: 35pt;
            position: absolute;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            text-shadow: 0 0 3px rgba(0,0,0,0.3);
        }
        .republic { font-weight: bold; font-size: 12pt; margin-bottom: 3px; }
        .devise { font-size: 9pt; margin-bottom: 3px; }
        .ministere { font-weight: bold; font-size: 10pt; margin-top: 8px; }
        .title { font-size: 20pt; font-weight: bold; font-style: italic; margin: 12px 0; color: #16a34a; }
        .election-title { font-size: 14pt; font-weight: bold; margin-bottom: 10px; }

        /* Location info */
        .location-info { margin: 15px 0; font-size: 11pt; max-width: 600px; margin-left: auto; margin-right: auto; }
        .location-row {
            margin: 8px 0;
            padding: 6px 10px;
            display: flex;
            align-items: center;
        }
        .location-row.commune-row {
            background: #d1d5db;
        }
        .location-label {
            font-weight: bold;
            text-decoration: underline;
            min-width: 200px;
            font-size: 12pt;
        }
        .location-value {
            font-weight: bold;
            font-size: 12pt;
        }

        /* Bureau box */
        .bureau-box {
            border: 3px solid #dc2626;
            padding: 15px 20px;
            margin: 20px auto;
            max-width: 800px;
            border-radius: 8px;
        }
        .bureau-box .row {
            margin: 12px 0;
            display: flex;
            align-items: center;
        }
        .bureau-box .label {
            font-style: italic;
            min-width: 280px;
            font-size: 13pt;
        }
        .bureau-box .value {
            background: #dc2626;
            color: white;
            padding: 8px 20px;
            font-weight: bold;
            font-size: 14pt;
            border-radius: 4px;
        }

        /* Green headers */
        .green-header { background: #16a34a; color: white; padding: 5px 10px; font-weight: bold; font-size: 10pt; margin: 10px 0 5px 0; }

        /* Table */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 8pt; }
        th { background: #e5e7eb; padding: 6px 4px; text-align: left; font-weight: bold; border: 1px solid #9ca3af; font-size: 7pt; }
        td { padding: 6px 4px; border: 1px solid #d1d5db; }
        tr:nth-child(even) { background: #f3f4f6; }
        .num-col { width: 8%; } .prenom-col { width: 15%; } .nom-col { width: 12%; }
        .date-col { width: 15%; } .pere-col { width: 12%; } .mere-col { width: 15%; } .emarg-col { width: 23%; }

        .page-number { text-align: right; font-size: 8pt; margin-top: 5px; }
        @media print { .no-print { display: none; } }
        .print-button {
            position: fixed; top: 20px; right: 20px; background: #16a34a; color: white;
            border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer;
            font-weight: bold; box-shadow: 0 4px 6px rgba(0,0,0,0.1); z-index: 1000;
        }
        .print-button:hover { background: #15803d; }
    </style>
</head>
<body>
    <button onclick="window.print()" class="print-button no-print">🖨️ Imprimer</button>

    @php
        $perPage = 30;
        $bureauxByCommune = collect($bureaux)->groupBy('commune');
    @endphp

    @foreach($bureauxByCommune as $commune => $bureauxCommune)
        {{-- Cover page COMMUNE --}}
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
                <div style="margin: 15px 0;"><strong>Nombre de bureaux :</strong> {{ $bureauxCommune->count() }}</div>
                <div style="margin: 15px 0;"><strong>Total électeurs :</strong> {{ number_format($bureauxCommune->sum('effectif')) }}</div>
            </div>
        </div>

        @php
            $bureauxByLieu = $bureauxCommune->groupBy('lieu_vote');
        @endphp

        @foreach($bureauxByLieu as $lieu => $bureauxLieu)
            {{-- Cover page LIEU DE VOTE --}}
            <div class="cover-page">
                <div class="cover-subtitle">COMMUNE : {{ strtoupper($commune) }}</div>
                <div class="cover-title" style="color: #16a34a;">{{ strtoupper($lieu) }}</div>
                <div class="cover-subtitle" style="font-size: 14pt; color: #64748b;">Lieu de Vote</div>
                <div class="cover-info">
                    <div><strong>Nombre de bureaux :</strong> {{ $bureauxLieu->count() }}</div>
                    <div style="margin-top: 10px;"><strong>Total électeurs :</strong> {{ number_format($bureauxLieu->sum('effectif')) }}</div>
                </div>
            </div>

            @foreach($bureauxLieu as $bureau)
                {{-- Cover page BUREAU avec header complet --}}
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

                @php
                    // Debug: check electeurs
                    $electeursCollection = is_array($bureau['electeurs']) ? collect($bureau['electeurs']) : $bureau['electeurs'];
                    if ($electeursCollection->isEmpty()) {
                        continue; // Skip empty bureaux
                    }
                    $totalPages = ceil($electeursCollection->count() / $perPage);
                    $chunks = $electeursCollection->chunk($perPage);
                @endphp

                {{-- LISTE ÉLECTEURS - pages simples avec seulement headers verts --}}
                @foreach($chunks as $pageIndex => $electeursPage)
                <div class="page">
                    {{-- Green headers compact --}}
                    <div style="display: flex; gap: 3px; margin-bottom: 8px;">
                        <div class="green-header" style="flex: 1; text-align: center; font-size: 8pt; padding: 3px 5px; line-height: 1.2;">
                            Commune<br><strong style="font-size: 9pt;">{{ strtoupper($bureau['commune']) }}</strong>
                        </div>
                        <div class="green-header" style="flex: 2; text-align: center; font-size: 8pt; padding: 3px 5px; line-height: 1.2;">
                            Lieu de vote<br><strong style="font-size: 9pt;">{{ strtoupper($bureau['lieu_vote']) }}</strong>
                        </div>
                        <div class="green-header" style="text-align: center; font-size: 8pt; padding: 3px 5px; min-width: 80px; line-height: 1.2;">
                            Bureau<br><strong style="font-size: 9pt;">{{ substr($bureau['code_bureau'], -2) }}</strong>
                        </div>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th class="num-col">N° électeur<br>N.I.N.</th>
                                <th class="prenom-col">Prénom(s)</th>
                                <th class="nom-col">Nom</th>
                                <th class="date-col">Date et lieu de naissance</th>
                                <th class="pere-col">Prénom(s) du père</th>
                                <th class="mere-col">Prénom(s) et nom de la mère</th>
                                <th class="emarg-col">Emargement</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($electeursPage as $index => $e)
                            <tr>
                                <td class="num-col">{{ ($pageIndex * $perPage) + $index + 1 }}<br><small>{{ $e->numelec }}</small></td>
                                <td class="prenom-col">{{ strtoupper($e->prenom) }}</td>
                                <td class="nom-col">{{ strtoupper($e->nom) }}</td>
                                <td class="date-col">{{ $e->datenaiss }}<br><small>{{ strtoupper($e->lieunaiss ?? '') }}</small></td>
                                <td class="pere-col">{{ strtoupper($e->prenom_pere ?? '') }}</td>
                                <td class="mere-col">{{ strtoupper($e->prenom_mere ?? '') }}<br><small>{{ strtoupper($e->nom_mere ?? '') }}</small></td>
                                <td class="emarg-col"></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="page-number">{{ $pageIndex + 1 }}/{{ $totalPages }}</div>
                </div>
                @endforeach

                {{-- Page blanche après chaque bureau --}}
                <div class="blank-page"></div>

            @endforeach

            {{-- Page blanche après chaque lieu --}}
            <div class="blank-page"></div>

        @endforeach

        {{-- Page blanche après chaque commune --}}
        <div class="blank-page"></div>

    @endforeach

</body>
</html>

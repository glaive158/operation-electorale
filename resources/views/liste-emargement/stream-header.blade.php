<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Liste d'Émargement</title>
    <style>
        @page { size: A4 landscape; margin: 10mm 15mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 9pt; line-height: 1.3; }
        /* content-visibility: skip layout/paint of off-screen pages → big perf win */
        .page { page-break-after: always; position: relative; min-height: 190mm; content-visibility: auto; contain-intrinsic-size: 1px 200mm; }
        .blank-page { page-break-after: always; height: 190mm; content-visibility: auto; contain-intrinsic-size: 1px 200mm; }

        .cover-page {
            page-break-after: always;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 190mm;
            text-align: center;
            padding: 20mm;
            content-visibility: auto;
            contain-intrinsic-size: 1px 200mm;
        }
        .cover-title { font-size: 32pt; font-weight: bold; margin: 20px 0; color: #16a34a; font-style: italic; }
        .cover-subtitle { font-size: 20pt; margin: 10px 0; font-weight: bold; }
        .cover-info { font-size: 16pt; margin: 20px 0; padding: 25px 40px; background: #f8fafc; border: 2px solid #e2e8f0; }

        .header { margin-bottom: 10px; }
        .flag-box { width: 70px; height: 90px; border: 3px solid #000; background: linear-gradient(to right, #16a34a 0%, #16a34a 33%, #fbbf24 33%, #fbbf24 66%, #dc2626 66%); display: flex; align-items: center; justify-content: center; position: relative; }
        .flag-star { color: #16a34a; font-size: 35pt; position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); text-shadow: 0 0 3px rgba(0,0,0,0.3); }
        .republic { font-weight: bold; font-size: 12pt; margin-bottom: 3px; }
        .devise { font-size: 9pt; margin-bottom: 3px; }
        .ministere { font-weight: bold; font-size: 10pt; margin-top: 8px; }
        .title { font-size: 20pt; font-weight: bold; font-style: italic; margin: 12px 0; color: #16a34a; }
        .election-title { font-size: 14pt; font-weight: bold; margin-bottom: 10px; }

        .location-info { margin: 15px 0; font-size: 11pt; max-width: 600px; margin-left: auto; margin-right: auto; }
        .location-row { margin: 8px 0; padding: 6px 10px; display: flex; align-items: center; }
        .location-row.commune-row { background: #d1d5db; }
        .location-label { font-weight: bold; text-decoration: underline; min-width: 200px; font-size: 12pt; }
        .location-value { font-weight: bold; font-size: 12pt; }

        .bureau-box { border: 3px solid #dc2626; padding: 15px 20px; margin: 20px auto; max-width: 800px; border-radius: 8px; }
        .bureau-box .row { margin: 12px 0; display: flex; align-items: center; }
        .bureau-box .label { font-style: italic; min-width: 280px; font-size: 13pt; }
        .bureau-box .value { background: #dc2626; color: white; padding: 8px 20px; font-weight: bold; font-size: 14pt; border-radius: 4px; }

        .green-header { background: #16a34a; color: white; padding: 5px 10px; font-weight: bold; font-size: 10pt; margin: 10px 0 5px 0; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 8pt; }
        th { background: #e5e7eb; padding: 6px 4px; text-align: left; font-weight: bold; border: 1px solid #9ca3af; font-size: 7pt; }
        td { padding: 6px 4px; border: 1px solid #d1d5db; }
        tr:nth-child(even) { background: #f3f4f6; }
        .num-col { width: 8%; } .prenom-col { width: 15%; } .nom-col { width: 12%; }
        .date-col { width: 15%; } .pere-col { width: 12%; } .mere-col { width: 15%; } .emarg-col { width: 23%; }

        .page-number { text-align: right; font-size: 8pt; margin-top: 5px; }
        @media print { .no-print { display: none; } }
        .print-button { position: fixed; top: 20px; right: 20px; background: #16a34a; color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: bold; box-shadow: 0 4px 6px rgba(0,0,0,0.1); z-index: 1000; }
        .print-button:hover { background: #15803d; }
        .progress-banner { position: fixed; top: 20px; left: 20px; background: #fbbf24; color: #000; padding: 8px 16px; border-radius: 6px; font-size: 11pt; font-weight: bold; z-index: 1000; }
    </style>
</head>
<body>
    <button onclick="window.print()" class="print-button no-print">🖨️ Imprimer</button>
    <div class="progress-banner no-print" id="progress">⏳ Génération en cours...</div>
    <script>
        // Hide banner once full doc is loaded
        window.addEventListener('load', function() {
            var p = document.getElementById('progress');
            if (p) p.style.display = 'none';
        });
    </script>

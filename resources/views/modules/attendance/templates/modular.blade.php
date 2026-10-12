<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $metadonnees['titre'] ?? 'Fiche de présence' }} — {{ $metadonnees['groupe'] ?? '' }}</title>
    <style>
        @page {
            size: {{ $paper ?? 'A4' }} {{ $orientation ?? 'portrait' }};
            margin: 12mm 10mm 15mm 10mm;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        body {
            font-family: 'DejaVu Sans', 'Helvetica Neue', Arial, sans-serif;
            font-size: 8.5pt;
            color: #0f172a;
            line-height: 1.35;
            background-color: #ffffff;
            margin: 0;
            padding: 0;
        }
        .entete-officiel {
            border-bottom: 2pt solid #1e3a5f;
            padding-bottom: 8pt;
            margin-bottom: 8pt;
        }
        .entete-table {
            width: 100%;
            border-collapse: collapse;
        }
        .entete-table td {
            vertical-align: top;
            padding: 0;
            border: none;
        }
        .institution-nom {
            font-size: 13pt;
            font-weight: bold;
            color: #1e3a5f;
            text-transform: uppercase;
            letter-spacing: 0.5pt;
        }
        .faculte-nom {
            font-size: 9.5pt;
            font-weight: 600;
            color: #334155;
            margin-top: 2pt;
        }
        .departement-nom {
            font-size: 8pt;
            color: #64748b;
        }
        .titre-document {
            font-size: 12pt;
            font-weight: bold;
            color: #0f172a;
            text-transform: uppercase;
            text-align: right;
        }
        .badge-type {
            display: inline-block;
            background-color: #e0f2fe;
            color: #0369a1;
            font-size: 7.5pt;
            font-weight: bold;
            padding: 2pt 6pt;
            border-radius: 3pt;
            margin-top: 3pt;
            text-transform: uppercase;
        }
        .cartouche-seance {
            background-color: #f8fafc;
            border: 0.75pt solid #cbd5e1;
            border-radius: 4pt;
            padding: 6pt 8pt;
            margin-bottom: 8pt;
        }
        .grille-meta {
            width: 100%;
            border-collapse: collapse;
        }
        .grille-meta td {
            border: none;
            padding: 2pt 4pt;
            font-size: 8pt;
            vertical-align: middle;
        }
        .meta-label {
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 7pt;
        }
        .meta-valeur {
            color: #0f172a;
            font-weight: 600;
        }
        .table-presence {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10pt;
        }
        .table-presence th, .table-presence td {
            border: 0.75pt solid #cbd5e1;
            padding: 3.5pt 4pt;
            vertical-align: middle;
        }
        .table-presence th {
            background-color: #f1f5f9;
            color: #1e293b;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 7.5pt;
        }
        .cell-centre { text-align: center; }
        .cell-mono { font-family: monospace; font-size: 8pt; }
        .cell-checkbox { text-align: center; font-size: 10pt; }
        .cell-signature { height: 20pt; min-width: 60pt; }
        .bloc-signatures-fin {
            margin-top: 10pt;
            margin-bottom: 8pt;
        }
        .table-signatures-fin {
            width: 100%;
            border-collapse: collapse;
        }
        .table-signatures-fin td {
            width: 50%;
            border: 0.75pt solid #cbd5e1;
            padding: 6pt 8pt;
            vertical-align: top;
            height: 52pt;
        }
        .sig-titre {
            font-weight: bold;
            font-size: 7.5pt;
            text-transform: uppercase;
            color: #1e3a5f;
            border-bottom: 0.5pt solid #e2e8f0;
            padding-bottom: 2pt;
            margin-bottom: 3pt;
        }
        .sig-mentions {
            font-size: 7pt;
            color: #64748b;
            line-height: 1.4;
        }
        .pied-document {
            border-top: 0.5pt solid #cbd5e1;
            padding-top: 4pt;
            font-size: 7pt;
            color: #64748b;
        }
        .pied-table {
            width: 100%;
            border-collapse: collapse;
        }
        .pied-table td {
            border: none;
            padding: 0;
        }
        .pagenum:before { content: counter(page); }
        .pagecount:before { content: counter(pages); }
        @media screen {
            body {
                background-color: #f1f5f9;
                padding: 20px;
            }
            .feuille-page {
                background: white;
                max-width: 1050px;
                margin: 0 auto;
                padding: 30px;
                box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
                border-radius: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="feuille-page">
        @php
            $blocs = $template->blocsOrdonnes();
        @endphp

        @foreach($blocs as $bloc)
            @if($bloc['enabled'] ?? true)
                @includeIf('modules.attendance.templates.blocks.' . $bloc['type'], [
                    'config' => $bloc['config'] ?? [],
                    'metadonnees' => $metadonnees,
                    'colonnes' => $colonnes,
                    'lignes' => $lignes,
                    'template' => $template,
                ])
            @endif
        @endforeach
    </div>
</body>
</html>

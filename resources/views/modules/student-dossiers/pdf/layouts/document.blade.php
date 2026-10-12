<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $titreDocument ?? 'Dossier étudiant' }}</title>
    <style>
        @page {
            size: A4 {{ $orientation ?? 'portrait' }};
            margin: 20mm 15mm 22mm 15mm;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10pt;
            color: #1e293b;
            line-height: 1.45;
        }
        .entete {
            border-bottom: 2.5pt solid #1e3a5f;
            padding-bottom: 8pt;
            margin-bottom: 12pt;
        }
        .entete .etablissement { font-size: 14pt; font-weight: bold; color: #1e3a5f; }
        .entete .faculte { font-size: 10pt; color: #475569; }
        .entete .titre-doc { font-size: 12pt; font-weight: bold; margin-top: 6pt; }
        .meta { font-size: 8.5pt; color: #475569; margin-top: 4pt; }
        h2 {
            font-size: 11pt;
            color: #1e3a5f;
            border-bottom: 1pt solid #cbd5e1;
            padding-bottom: 3pt;
            margin: 14pt 0 6pt 0;
        }
        table { width: 100%; border-collapse: collapse; margin-top: 4pt; }
        thead { display: table-header-group; }
        th, td { border: 0.5pt solid #cbd5e1; padding: 4pt 6pt; text-align: left; font-size: 9pt; }
        th { background-color: #f1f5f9; font-weight: bold; }
        tr { page-break-inside: avoid; }
        .contexte {
            border: 0.5pt solid #cbd5e1;
            border-radius: 4pt;
            padding: 8pt 10pt;
            margin: 8pt 0;
            font-size: 9pt;
        }
        .contexte .bloc-titre { font-weight: bold; color: #1e3a5f; margin-bottom: 4pt; }
        .filtres { font-size: 8.5pt; color: #475569; margin-top: 4pt; }
        .section-bloc { page-break-inside: auto; }
        .section-titre {
            page-break-after: avoid;
            page-break-inside: avoid;
            background-color: #e8eef5;
            border: 0.5pt solid #94a3b8;
            border-radius: 3pt;
            padding: 5pt 8pt;
            margin: 12pt 0 4pt 0;
            font-size: 10.5pt;
            font-weight: bold;
            color: #1e3a5f;
        }
        .section-effectif { font-size: 8.5pt; color: #475569; margin: 0 0 2pt 0; }
        .kv { margin: 2pt 0; }
        .kv .cle { display: inline-block; width: 38%; color: #475569; }
        .kv .valeur { font-weight: bold; }
        .badge {
            display: inline-block;
            border: 0.5pt solid #94a3b8;
            border-radius: 3pt;
            padding: 1pt 5pt;
            font-size: 8.5pt;
            font-weight: bold;
        }
        .signature {
            margin-top: 18pt;
            border: 0.5pt dashed #94a3b8;
            padding: 10pt;
            font-size: 9pt;
            color: #475569;
        }
        .pied {
            position: fixed;
            bottom: -16mm;
            left: 0;
            right: 0;
            font-size: 7.5pt;
            color: #64748b;
            border-top: 0.5pt solid #cbd5e1;
            padding-top: 4pt;
        }
        .pagenum:before { content: counter(page); }
        .pagecount:before { content: counter(pages); }
    </style>
</head>
<body>
    <div class="entete">
        <div class="etablissement">{{ $institution ?? 'Université' }}</div>
        <div class="faculte">{{ $faculteNom ?? '' }}</div>
        <div class="titre-doc">{{ $titreDocument ?? 'Dossier étudiant' }}</div>
        <div class="meta">
            Référence : {{ $reference ?? '—' }} &nbsp;·&nbsp;
            Généré le : {{ $dateGeneration ?? '' }}
        </div>
    </div>

    @yield('contenu')

    <div class="pied">
        {{ $institution ?? 'Université' }} — Réf. {{ $reference ?? '—' }} — {{ $dateGeneration ?? '' }}
        &nbsp;·&nbsp; Page <span class="pagenum"></span> / <span class="pagecount"></span>
        &nbsp;·&nbsp; {{ $piedComplement ?? '' }}
    </div>
</body>
</html>

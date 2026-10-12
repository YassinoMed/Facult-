<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $titreDocument }}</title>
    <style>
        @page { size: A4 landscape; margin: 15mm 12mm 18mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1e293b; line-height: 1.45; }
        .entete { text-align: center; border-bottom: 2.5pt solid #1e3a5f; padding-bottom: 8pt; margin-bottom: 10pt; }
        .entete .etablissement { font-size: 15pt; font-weight: bold; color: #1e3a5f; }
        .entete .titre-doc { font-size: 13pt; font-weight: bold; margin-top: 8pt; }
        .meta { font-size: 8.5pt; color: #475569; margin-top: 4pt; }
        h3 { font-size: 10pt; color: #1e3a5f; margin: 10pt 0 4pt 0; }
        table { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
        th { background: #f1f5f9; text-align: left; padding: 3pt 5pt; border: 0.5pt solid #cbd5e1; }
        td { padding: 3pt 5pt; border: 0.5pt solid #e2e8f0; }
        .jury li { margin: 2pt 0; }
        .signature { margin-top: 18pt; }
        .signature .case { display: inline-block; width: 30%; vertical-align: top; }
        .pied { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7.5pt; color: #64748b; border-top: 0.5pt solid #cbd5e1; padding-top: 4pt; text-align: center; }
    </style>
</head>
<body>
    <div class="entete">
        <div class="etablissement">{{ $institution }}</div>
        <div class="titre-doc">{{ $titreDocument }}</div>
        <div class="meta">Référence : {{ $reference }} &nbsp;·&nbsp; Généré le : {{ $dateGeneration }}</div>
        <div class="meta">{{ $contexte }}</div>
    </div>

    <h3>Composition du jury</h3>
    <ul class="jury">
        @foreach($membres as $membre)
            <li>{{ $membre['nom'] }} — {{ $membre['fonction'] }} ({{ $membre['present'] ? 'présent' : 'absent' }})</li>
        @endforeach
    </ul>

    <h3>Résultats ({{ $statistiques['candidats'] }} candidats, {{ $statistiques['admis'] }} admis)</h3>
    <table>
        <thead>
            <tr>@foreach($entetes as $entete)<th>{{ $entete }}</th>@endforeach</tr>
        </thead>
        <tbody>
            @foreach($lignes as $ligne)
                <tr>
                    <td>{{ $ligne['numero'] }}</td>
                    <td>{{ $ligne['matricule'] }}</td>
                    <td>{{ $ligne['nom'] }}</td>
                    <td>{{ $ligne['prenom'] }}</td>
                    <td>{{ $ligne['moyenne'] }}</td>
                    <td>{{ $ligne['credits'] }}</td>
                    <td><strong>{{ $ligne['decision'] }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="signature">
        <div class="case"><p><strong>Le président du jury</strong></p><p style="height: 40pt;">&nbsp;</p><p>Signature</p></div>
        <div class="case"><p><strong>Le rapporteur</strong></p><p style="height: 40pt;">&nbsp;</p><p>Signature</p></div>
        <div class="case"><p><strong>Le secrétaire</strong></p><p style="height: 40pt;">&nbsp;</p><p>Signature</p></div>
    </div>

    <div class="pied">{{ $institution }} — Réf. {{ $reference }} — {{ $dateGeneration }}</div>
</body>
</html>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $titreDocument }}</title>
    <style>
        @page { size: A4 portrait; margin: 18mm 14mm 20mm 14mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1e293b; line-height: 1.45; }
        .entete { text-align: center; border-bottom: 2.5pt solid #1e3a5f; padding-bottom: 8pt; margin-bottom: 10pt; }
        .entete .etablissement { font-size: 15pt; font-weight: bold; color: #1e3a5f; }
        .entete .titre-doc { font-size: 13pt; font-weight: bold; margin-top: 8pt; }
        .meta { font-size: 8.5pt; color: #475569; margin-top: 4pt; }
        .identite { margin: 8pt 0; }
        .kv { margin: 2pt 0; }
        .kv .cle { display: inline-block; width: 32%; color: #475569; }
        .kv .valeur { font-weight: bold; }
        h3 { font-size: 10pt; color: #1e3a5f; border-bottom: 0.5pt solid #cbd5e1; padding-bottom: 2pt; margin: 10pt 0 4pt 0; }
        table.notes { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
        table.notes th { background: #f1f5f9; text-align: left; padding: 3pt 5pt; border: 0.5pt solid #cbd5e1; }
        table.notes td { padding: 3pt 5pt; border: 0.5pt solid #e2e8f0; }
        .total { margin-top: 6pt; font-weight: bold; }
        .signature { margin-top: 20pt; }
        .signature .case { display: inline-block; width: 45%; vertical-align: top; }
        .verification { font-size: 8pt; color: #1e3a5f; border: 0.5pt solid #cbd5e1; padding: 4pt 6pt; margin-top: 10pt; }
        .pied { position: fixed; bottom: -14mm; left: 0; right: 0; font-size: 7.5pt; color: #64748b; border-top: 0.5pt solid #cbd5e1; padding-top: 4pt; text-align: center; }
    </style>
</head>
<body>
    <div class="entete">
        <div class="etablissement">{{ $institution }}</div>
        <div class="faculte">{{ $faculte }}</div>
        <div class="titre-doc">{{ $titreDocument }}</div>
        <div class="meta">Année universitaire : {{ $annee }} &nbsp;·&nbsp; Délivré le : {{ $dateGeneration }}</div>
    </div>

    <div class="identite">
        <p class="kv"><span class="cle">Nom</span> <span class="valeur">{{ $nom }}</span></p>
        <p class="kv"><span class="cle">Prénom</span> <span class="valeur">{{ $prenom }}</span></p>
        <p class="kv"><span class="cle">N° inscription</span> <span class="valeur">{{ $inscription }}</span></p>
        <p class="kv"><span class="cle">Date de naissance</span> <span class="valeur">{{ $naissance }}</span></p>
        <p class="kv"><span class="cle">Formation</span> <span class="valeur">{{ $programme }} — {{ $niveau }}</span></p>
    </div>

    @foreach($semestres as $semestre)
        <h3>Semestre {{ $semestre['numero'] }} — Moyenne : {{ $semestre['moyenne'] ?? '—' }} — Crédits : {{ $semestre['credits'][0] }}/{{ $semestre['credits'][1] }}</h3>
        <table class="notes">
            <thead>
                <tr><th>Code</th><th>Matière</th><th>Note</th><th>Session</th></tr>
            </thead>
            <tbody>
                @foreach($semestre['notes'] as $note)
                    <tr>
                        <td>{{ $note['code'] }}</td>
                        <td>{{ $note['matiere'] }}</td>
                        <td>{{ number_format($note['note'], 2) }}</td>
                        <td>{{ $note['session'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    <p class="total">Moyenne annuelle : {{ $moyenne ?? '—' }} — Crédits : {{ $credits[0] }}/{{ $credits[1] }} — Décision du jury : {{ $decision ?? 'non délibérée' }}</p>

    <div class="signature">
        <div class="case">
            <p><strong>Fait le :</strong> {{ $dateGeneration }}</p>
        </div>
        <div class="case">
            <p><strong>Signature / cachet :</strong></p>
            <p style="height: 50pt;">&nbsp;</p>
        </div>
    </div>

    <div class="verification">
        <p><strong>Vérification d'authenticité :</strong> {{ $urlVerification ?? '' }} — Code : {{ $codeVerification ?? '' }}</p>
    </div>

    <div class="pied">
        {{ $institution }} — {{ $titreDocument }} — {{ $dateGeneration }}
    </div>
</body>
</html>

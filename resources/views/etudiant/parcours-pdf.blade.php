<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Parcours LMD — {{ $etudiant->num_inscription }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; }
        h1 { font-size: 18px; }
        h2 { font-size: 13px; margin-top: 18px; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; }
        .avertissement { border: 1px solid #f59e0b; background: #fffbeb; padding: 8px; margin-top: 10px; }
        .meta { color: #64748b; font-size: 10px; }
    </style>
</head>
<body>
    <h1>Parcours LMD — {{ $etudiant->nom_complet }}</h1>
    <p class="meta">N° inscription {{ $etudiant->num_inscription }} · édité le {{ $dateEdition->format('d/m/Y H:i') }}</p>
    <div class="avertissement"><strong>Document informatif</strong> — ni relevé de notes officiel, ni attestation. Programme et niveau réellement associés, sans CIN.</div>

    <h2>Situation</h2>
    <table>
        <tr><th>Année</th><td>{{ $parcours['annee'] ?? '—' }}</td></tr>
        <tr><th>Programme</th><td>{{ $parcours['programme'] ?? '—' }} — {{ $parcours['programme_nom'] ?? '' }}</td></tr>
        <tr><th>Niveau</th><td>{{ $parcours['niveau'] ?? '—' }}</td></tr>
        <tr><th>Plan</th><td>{{ $parcours['source_plan'] ? 'Maquette v'.$parcours['maquette']['version'].' ('.$parcours['maquette']['annee'].')' : 'Référentiel canonique (aucune maquette applicable)' }}</td></tr>
    </table>

    @if($parcours['etat'] !== 'OK')
        <div class="avertissement">{{ $parcours['message'] }}</div>
    @endif

    @foreach($parcours['semestres'] as $semestre)
        <h2>{{ $semestre['code'] }} ({{ count($semestre['ecues']) }} ECUE)</h2>
        <table>
            <tr><th>Code</th><th>Intitulé</th><th>UE</th><th>Crédits</th><th>Volumes</th></tr>
            @foreach($semestre['ecues'] as $ecue)
                <tr>
                    <td>{{ $ecue['code'] }}</td>
                    <td>{{ $ecue['nom'] }}</td>
                    <td>{{ $ecue['ue'] ?? '?' }}</td>
                    <td>{{ $ecue['credits'] }}</td>
                    <td>{{ $ecue['volumes'] }}</td>
                </tr>
            @endforeach
        </table>
    @endforeach
</body>
</html>

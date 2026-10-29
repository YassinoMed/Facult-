<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Rapport de Simulation Temporelle — {{ $scenario->code }}</title>
    <style>
        @page {
            margin: 15mm 15mm 20mm 15mm;
            @bottom-right {
                content: "Page " counter(page) " sur " counter(pages);
                font-size: 8pt;
                color: #64748b;
            }
        }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9pt;
            line-height: 1.4;
            color: #1e293b;
        }
        .header {
            border-bottom: 2px solid #0f172a;
            padding-bottom: 8px;
            margin-bottom: 15px;
        }
        .header h1 {
            font-size: 16pt;
            margin: 0 0 4px 0;
            color: #0f172a;
        }
        .header .subtitle {
            font-size: 9pt;
            color: #475569;
            font-weight: 500;
        }
        .disclaimer {
            background-color: #f8fafc;
            border-left: 3px solid #0284c7;
            padding: 8px 12px;
            font-size: 8pt;
            color: #0369a1;
            margin-bottom: 15px;
        }
        .meta-box {
            width: 100%;
            margin-bottom: 15px;
        }
        .meta-box td {
            padding: 3px 6px;
            font-size: 8.5pt;
        }
        .meta-box .label {
            font-weight: bold;
            color: #475569;
            width: 25%;
        }
        .meta-box .value {
            font-family: monospace;
            color: #0f172a;
        }
        h2 {
            font-size: 11pt;
            color: #0f172a;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 4px;
            margin-top: 15px;
            margin-bottom: 8px;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 8pt;
        }
        table.data-table th, table.data-table td {
            border: 1px solid #cbd5e1;
            padding: 5px 8px;
            text-align: left;
        }
        table.data-table th {
            background-color: #f1f5f9;
            color: #334155;
            font-weight: bold;
        }
        table.data-table tr:nth-child(even) {
            background-color: #f8fafc;
        }
        .badge-success {
            background-color: #dcfce7;
            color: #166534;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: bold;
        }
        .badge-warning {
            background-color: #fef9c3;
            color: #854d0e;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: bold;
        }
        .footer {
            margin-top: 25px;
            border-top: 1px solid #e2e8f0;
            padding-top: 8px;
            font-size: 7.5pt;
            color: #64748b;
            text-align: center;
        }
    </style>
</head>
<body>

<div class="header">
    <h1>UniRegistre — Planification & Simulation Temporelle LMD</h1>
    <div class="subtitle">Rapport d'aide à la décision · Scénario : {{ $scenario->libelle }} ({{ $scenario->code }})</div>
</div>

<div class="disclaimer">
    <strong>Avertissement réglementaire :</strong> Ce rapport est généré à des fins exclusives de projection et d'aide à la décision prospective. Aucune décision réelle (inscriptions, notes, affectations) n'est modifiée par cette exécution.
</div>

<table class="meta-box">
    <tr>
        <td class="label">Identifiant exécution :</td>
        <td class="value">#{{ $execution->id }}</td>
        <td class="label">Version scénario / moteur :</td>
        <td class="value">v{{ $scenario->version }} / {{ $execution->version_moteur }}</td>
    </tr>
    <tr>
        <td class="label">Graine déterministe :</td>
        <td class="value">{{ $execution->graine }}</td>
        <td class="label">Date d'exécution :</td>
        <td class="value">{{ $execution->created_at?->format('d/m/Y H:i:s') }}</td>
    </tr>
    <tr>
        <td class="label">Empreinte entrées (SHA256) :</td>
        <td class="value" colspan="3">{{ $execution->hash_entrees }}</td>
    </tr>
    <tr>
        <td class="label">Empreinte résultats (SHA256) :</td>
        <td class="value" colspan="3">{{ $execution->hash_resultat }}</td>
    </tr>
</table>

<h2>1. Tableau Comparatif Annuel (Référence vs Projection Retenue)</h2>
<table class="data-table">
    <thead>
        <tr>
            <th>Pas</th>
            <th>Année</th>
            <th>Réf. (source)</th>
            <th>Projeté</th>
            <th>Écart Absolu</th>
            <th>Écart (%)</th>
            <th>Statut Contraintes</th>
        </tr>
    </thead>
    <tbody>
        @forelse($resultat['comparaison']['lignes'] ?? [] as $ligne)
            <tr>
                <td>{{ $ligne['pas'] === 0 ? 'Init' : 'An '.$ligne['pas'] }}</td>
                <td><strong>{{ $ligne['annee'] }}</strong></td>
                <td>{{ number_format($ligne['effectif_reference'], 0, ',', ' ') }}</td>
                <td><strong>{{ number_format($ligne['effectif_projete'], 0, ',', ' ') }}</strong></td>
                <td>{{ ($ligne['ecart_absolu'] >= 0 ? '+' : '').number_format($ligne['ecart_absolu'], 0, ',', ' ') }}</td>
                <td>{{ ($ligne['ecart_pourcentage'] >= 0 ? '+' : '').$ligne['ecart_pourcentage'] }}%</td>
                <td>
                    @if($ligne['conforme_contraintes'])
                        <span class="badge-success">Conforme</span>
                    @else
                        <span class="badge-warning">Violation</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7" style="text-align: center;">Aucune donnée comparative disponible.</td>
            </tr>
        @endforelse
    </tbody>
</table>

<h2>2. Classement des Variantes Déterministes Évaluées</h2>
<table class="data-table">
    <thead>
        <tr>
            <th>Rang</th>
            <th>Index</th>
            <th>Delta Admissions</th>
            <th>Score Global</th>
            <th>Total Final</th>
            <th>Contributions Objectifs</th>
        </tr>
    </thead>
    <tbody>
        @forelse($resultat['classement'] ?? [] as $rang => $var)
            <tr>
                <td><strong>#{{ $rang + 1 }}</strong></td>
                <td>Var. {{ $var['index'] }}</td>
                <td>{{ ($var['delta'] >= 0 ? '+' : '').round($var['delta'] * 100, 2) }}%</td>
                <td><strong>{{ number_format($var['score'] * 100, 1) }}/100</strong></td>
                <td>{{ number_format($var['total_final'], 0, ',', ' ') }}</td>
                <td>
                    @foreach($var['contributions'] ?? [] as $k => $c)
                        {{ $k }}: {{ number_format($c, 2) }}@if(!$loop->last), @endif
                    @endforeach
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6" style="text-align: center;">Aucune variante conforme classée.</td>
            </tr>
        @endforelse
    </tbody>
</table>

@if(!empty($resultat['sensibilite']['parametres_classes']))
    <h2>3. Analyse de Sensibilité Univariée (Impact relatif sur l'horizon)</h2>
    <table class="data-table">
        <thead>
            <tr>
                <th>Paramètre Testé</th>
                <th>Variation Testée</th>
                <th>Sensibilité Relative</th>
                <th>Impact Absolu Estimé</th>
            </tr>
        </thead>
        <tbody>
            @foreach($resultat['sensibilite']['parametres_classes'] as $p)
                <tr>
                    <td><strong>{{ $p['libelle'] }}</strong></td>
                    <td>{{ $p['variation_testee'] }}</td>
                    <td>{{ $p['sensibilite_pct'] }}%</td>
                    <td>± {{ $p['impact_absolu_eleves'] }} étudiant(s)</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<div class="footer">
    Document certifié conforme par le moteur de simulation temporelle UniRegistre (v{{ $execution->version_moteur }}) · Empreinte d'intégrité : {{ substr($execution->hash_resultat ?? '', 0, 16) }}…
</div>

</body>
</html>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Emploi du temps des surveillances</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 12mm 10mm 15mm 10mm;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 8.5pt;
            color: #1e293b;
            line-height: 1.35;
        }
        .entete {
            border-bottom: 2pt solid #1e3a5f;
            padding-bottom: 6pt;
            margin-bottom: 10pt;
        }
        .entete table {
            width: 100%;
            border-collapse: collapse;
        }
        .titre {
            font-size: 14pt;
            font-weight: bold;
            color: #1e3a5f;
            text-transform: uppercase;
            letter-spacing: 0.5pt;
        }
        .sous-titre {
            font-size: 9pt;
            color: #475569;
            margin-top: 2pt;
        }
        .meta-stats {
            text-align: right;
            font-size: 8pt;
            color: #334155;
        }
        .kpis {
            margin-bottom: 10pt;
            background: #f8fafc;
            border: 0.5pt solid #cbd5e1;
            padding: 5pt 8pt;
            font-size: 8pt;
        }
        .kpis table {
            width: 100%;
            border-collapse: collapse;
        }
        .kpis td {
            padding: 2pt 6pt;
        }
        .jour-bloc {
            margin-bottom: 12pt;
            page-break-inside: avoid;
        }
        .jour-titre {
            background: #1e3a5f;
            color: #ffffff;
            font-weight: bold;
            font-size: 9pt;
            padding: 4pt 8pt;
            text-transform: capitalize;
        }
        table.donnees {
            width: 100%;
            border-collapse: collapse;
            font-size: 8pt;
        }
        table.donnees th {
            background: #e2e8f0;
            color: #334155;
            font-weight: bold;
            text-align: left;
            padding: 4pt 6pt;
            border: 0.5pt solid #cbd5e1;
            text-transform: uppercase;
            font-size: 7pt;
        }
        table.donnees td {
            padding: 4pt 6pt;
            border: 0.5pt solid #cbd5e1;
            vertical-align: top;
        }
        tr:nth-child(even) td {
            background: #f8fafc;
        }
        .surv-tag {
            display: inline-block;
            margin: 1pt 0;
            padding: 1pt 3pt;
            border-radius: 2pt;
            font-size: 7.5pt;
            background: #f1f5f9;
        }
        .surv-principal {
            background: #e0e7ff;
            color: #3730a3;
            font-weight: bold;
        }
        .surv-reserve {
            background: #f3e8ff;
            color: #6b21a8;
            font-weight: bold;
        }
        .badge {
            display: inline-block;
            padding: 1pt 4pt;
            border-radius: 2pt;
            font-size: 7pt;
            font-weight: bold;
        }
        .badge-complet { background: #dcfce7; color: #166534; }
        .badge-partiel { background: #fef3c7; color: #92400e; }
        .badge-conflit { background: #ffe4e6; color: #9f1239; }
        .badge-non_affecte { background: #f1f5f9; color: #475569; }
        .conflit-msg {
            color: #be123c;
            font-size: 7pt;
            font-weight: bold;
            display: block;
            margin-top: 2pt;
        }
        .pied {
            position: fixed;
            bottom: -10mm;
            left: 0;
            right: 0;
            font-size: 7pt;
            color: #94a3b8;
            border-top: 0.5pt solid #cbd5e1;
            padding-top: 3pt;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="entete">
        <table>
            <tr>
                <td>
                    <div class="titre">Emploi du temps des surveillances</div>
                    <div class="sous-titre">
                        Session : <strong>{{ $sessionDominante?->nom ?? 'Toutes sessions' }}</strong>
                        @if(!empty($filtres['semestre'])) · Semestre {{ $filtres['semestre'] }} @endif
                    </div>
                </td>
                <td class="meta-stats">
                    <div>Édité le : <strong>{{ now()->locale('fr')->isoFormat('D MMMM YYYY [à] HH:mm') }}</strong></div>
                    <div>Couverture : <strong>{{ $couverture['taux'] }}%</strong> ({{ $couverture['completes'] }} / {{ $couverture['total_epreuves'] }} épreuves)</div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Synthèse compacte -->
    <div class="kpis">
        <table>
            <tr>
                <td><strong>Épreuves :</strong> {{ $couverture['total_epreuves'] }}</td>
                <td><strong>Surveillances :</strong> {{ $couverture['total_gardes'] }}</td>
                <td><strong>Réserves :</strong> {{ $couverture['total_reserves'] }}</td>
                <td><strong>Enseignants mobilisés :</strong> {{ $stats['enseignants_mobilises'] }}</td>
                <td><strong>Charge moyenne :</strong> {{ $stats['charge_moyenne'] }}</td>
                <td><strong>Conflits :</strong> {{ $couverture['conflits'] }}</td>
            </tr>
        </table>
    </div>

    <!-- Calendrier par jour -->
    @forelse($jours as $dateIso => $epreuvesDuJour)
        <div class="jour-bloc">
            <div class="jour-titre">
                {{ \Carbon\Carbon::parse($dateIso)->locale('fr')->isoFormat('dddd D MMMM YYYY') }} — {{ count($epreuvesDuJour) }} épreuve(s)
            </div>
            <table class="donnees">
                <thead>
                    <tr>
                        <th style="width: 11%;">Horaire</th>
                        <th style="width: 25%;">Épreuve / Matière</th>
                        <th style="width: 15%;">Niveau & Groupe</th>
                        <th style="width: 12%;">Salle & Cap.</th>
                        <th style="width: 27%;">Enseignants surveillants</th>
                        <th style="width: 10%; text-align: center;">Statut</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($epreuvesDuJour as $item)
                        @php $epreuve = $item['epreuve']; @endphp
                        <tr>
                            <td>
                                <strong>{{ $item['heure_debut'] }} – {{ $item['heure_fin'] }}</strong>
                            </td>
                            <td>
                                <strong>{{ $epreuve->matiere?->code }}</strong> — {{ $epreuve->matiere?->nom }}
                                <div style="font-size: 7pt; color: #64748b;">Code: {{ $epreuve->code }}</div>
                            </td>
                            <td>
                                {{ $epreuve->groupe?->niveau ?? ($epreuve->matiere?->niveau ?? '—') }}
                                <br>
                                <span style="color: #64748b;">{{ $epreuve->groupe?->nom ?? ($epreuve->groupe?->code ?? 'Tous') }}</span>
                            </td>
                            <td>
                                <strong>{{ $epreuve->salle?->code ?? '—' }}</strong>
                                @if($epreuve->salle?->capacite)
                                    <span style="font-size: 7pt; color: #64748b;">({{ $epreuve->salle->capacite }} pl.)</span>
                                @endif
                                <div style="font-size: 7pt; color: #475569;">{{ $item['nb_etudiants'] }} étu.</div>
                            </td>
                            <td>
                                @forelse($item['actifs'] as $surv)
                                    <div class="surv-tag {{ $surv->role === 'principal' ? 'surv-principal' : ($surv->role === 'reserve' ? 'surv-reserve' : '') }}">
                                        {{ $surv->enseignant?->nomComplet() ?? 'Enseignant' }}
                                        ({{ ucfirst($surv->role) }})
                                    </div>
                                @empty
                                    <span style="color: #94a3b8; font-style: italic;">Aucun affecté</span>
                                @endforelse

                                @foreach($item['conflits'] as $c)
                                    <span class="conflit-msg">⚠ {{ $c['message'] }}</span>
                                @endforeach
                            </td>
                            <td style="text-align: center;">
                                <span class="badge badge-{{ $item['statut'] }}">
                                    {{ ucfirst(str_replace('_', ' ', $item['statut'])) }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @empty
        <p style="text-align: center; color: #64748b; padding: 20pt 0;">Aucune épreuve trouvée pour ces critères.</p>
    @endforelse

    <div class="pied">
        Document officiel généré par le système d'information académique · Emploi du temps des surveillances
    </div>
</body>
</html>

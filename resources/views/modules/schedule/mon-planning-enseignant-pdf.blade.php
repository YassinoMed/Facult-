<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Emploi du temps — {{ $enseignant->nomComplet() }} — Semaine du {{ $lundi->format('d/m/Y') }}</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 12mm 15mm;
        }
        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #1e293b;
            line-height: 1.4;
        }
        .header-table {
            width: 100%;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .header-table td {
            vertical-align: top;
            border: none;
            padding: 0;
        }
        .univ-title {
            font-size: 11px;
            font-weight: bold;
            color: #0f172a;
            text-transform: uppercase;
        }
        .sub-title {
            font-size: 9px;
            color: #64748b;
        }
        .doc-title {
            text-align: right;
            font-size: 14px;
            font-weight: 900;
            color: #1e1b4b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-box {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 6px 10px;
            margin-bottom: 12px;
            font-size: 9.5px;
        }
        .badge-box table {
            width: 100%;
            border-collapse: collapse;
        }
        .badge-box td {
            border: none;
            padding: 2px 4px;
        }
        .strong {
            font-weight: bold;
            color: #0f172a;
        }
        table.planning {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }
        table.planning th {
            background-color: #1e293b;
            color: #ffffff;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 6px 5px;
            border: 1px solid #0f172a;
            text-align: left;
        }
        table.planning td {
            border: 1px solid #cbd5e1;
            padding: 5px 6px;
            font-size: 9.5px;
            vertical-align: top;
        }
        table.planning tr:nth-child(even) td {
            background-color: #f8fafc;
        }
        .jour-cell {
            font-weight: bold;
            color: #1e293b;
            background-color: #f1f5f9 !important;
            white-space: nowrap;
        }
        .horaire-cell {
            font-weight: bold;
            font-family: monospace;
            white-space: nowrap;
            color: #334155;
        }
        .type-badge {
            display: inline-block;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            padding: 1px 4px;
            border-radius: 3px;
            background: #e2e8f0;
            color: #334155;
        }
        .statut-annule {
            color: #e11d48;
            font-weight: bold;
        }
        .statut-deplace {
            color: #d97706;
            font-weight: bold;
        }
        .statut-normal {
            color: #059669;
            font-weight: bold;
        }
        .footer-table {
            width: 100%;
            margin-top: 15px;
            border-top: 1px solid #cbd5e1;
            padding-top: 6px;
            font-size: 8.5px;
            color: #64748b;
        }
        .footer-table td {
            border: none;
            padding: 0;
        }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td style="width: 55%;">
                <div class="univ-title">République Tunisienne</div>
                <div class="sub-title">Ministère de l'Enseignement Supérieur et de la Recherche Scientifique</div>
                <div class="strong" style="margin-top: 3px;">{{ $enseignant->departement?->faculte?->nom ?? 'Université Tunisienne' }}</div>
                @if($enseignant->departement)
                    <div class="sub-title">Département : {{ $enseignant->departement->nom }}</div>
                @endif
            </td>
            <td style="width: 45%; text-align: right;">
                <div class="doc-title">Emploi du Temps Hebdomadaire</div>
                <div class="sub-title" style="margin-top: 3px;">Semaine du {{ $lundi->format('d/m/Y') }} au {{ $lundi->copy()->addDays(6)->format('d/m/Y') }}</div>
                <div class="sub-title">Document officiel enseignant (Consultation)</div>
            </td>
        </tr>
    </table>

    <div class="badge-box">
        <table>
            <tr>
                <td style="width: 35%;"><span class="strong">Enseignant :</span> {{ $enseignant->nomComplet() }}</td>
                <td style="width: 25%;"><span class="strong">Matricule :</span> {{ $enseignant->matricule ?? '—' }}</td>
                <td style="width: 20%;"><span class="strong">Bureau :</span> {{ $enseignant->bureau ?? '—' }}</td>
                <td style="width: 20%; text-align: right;"><span class="strong">Heures prévues :</span> {{ $widgets['heuresPlanifiees'] ?? '0' }} h</td>
            </tr>
        </table>
    </div>

    <table class="planning">
        <thead>
            <tr>
                <th style="width: 14%;">Jour & Date</th>
                <th style="width: 13%;">Créneau horaire</th>
                <th style="width: 28%;">Matière / ECUE</th>
                <th style="width: 15%;">Groupe</th>
                <th style="width: 12%;">Salle</th>
                <th style="width: 8%;">Type</th>
                <th style="width: 10%;">Statut</th>
            </tr>
        </thead>
        <tbody>
            @php
                $joursSemaine = [
                    'monday' => 'Lundi', 'tuesday' => 'Mardi', 'wednesday' => 'Mercredi',
                    'thursday' => 'Jeudi', 'friday' => 'Vendredi', 'saturday' => 'Samedi', 'sunday' => 'Dimanche'
                ];
                $totalLignes = 0;
            @endphp
            @foreach($joursSemaine as $codeJour => $libelleJour)
                @php $lignesJour = $jours[$codeJour] ?? []; @endphp
                @if(count($lignesJour) > 0)
                    @foreach($lignesJour as $indexLigne => $ligne)
                        @php $totalLignes++; @endphp
                        <tr>
                            @if($indexLigne === 0)
                                <td class="jour-cell" rowspan="{{ count($lignesJour) }}">
                                    {{ $libelleJour }}<br>
                                    <span style="font-size: 8.5px; font-weight: normal; color: #64748b;">{{ \Carbon\Carbon::parse($ligne['date'])->format('d/m/Y') }}</span>
                                </td>
                            @endif
                            <td class="horaire-cell">{{ $ligne['debut'] }} → {{ $ligne['fin'] }}</td>
                            <td>
                                <span class="strong">{{ $ligne['matiere'] }}</span>
                                @if(!empty($ligne['matiere_code']))
                                    <span style="font-size: 8px; color: #64748b;">({{ $ligne['matiere_code'] }})</span>
                                @endif
                            </td>
                            <td>{{ $ligne['groupe'] ?? '—' }}</td>
                            <td>
                                {{ $ligne['salle'] ?? '—' }}
                                @if(!empty($ligne['salle_capacite']))
                                    <span style="font-size: 8px; color: #64748b;">({{ $ligne['salle_capacite'] }} pl.)</span>
                                @endif
                            </td>
                            <td><span class="type-badge">{{ strtoupper($ligne['type']) }}</span></td>
                            <td>
                                @if($ligne['statut'] === 'cancelled')
                                    <span class="statut-annule">Annulé</span>
                                @elseif(!empty($ligne['deplace']))
                                    <span class="statut-deplace">Déplacé</span>
                                @else
                                    <span class="statut-normal">Programmé</span>
                                @endif
                                @if(!empty($ligne['motif']))
                                    <br><span style="font-size: 7.5px; color: #64748b;">{{ $ligne['motif'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                @endif
            @endforeach

            @if($totalLignes === 0)
                <tr>
                    <td colspan="7" style="text-align: center; padding: 20px; color: #64748b; font-style: italic;">
                        Aucun créneau d'enseignement programmé pour cette semaine.
                    </td>
                </tr>
            @endif
        </tbody>
    </table>

    <table class="footer-table">
        <tr>
            <td style="width: 60%;">
                Document édité le {{ now()->format('d/m/Y à H:i:s') }} · Plateforme Universitaire UniRegistre · Authentifié sous la session #{{ $enseignant->id }}
            </td>
            <td style="width: 40%; text-align: right;">
                Séances : {{ $widgets['nbSeancesPeriode'] ?? $totalLignes }} | Volume hebdomadaire : {{ $widgets['heuresPlanifiees'] ?? '0' }} h
            </td>
        </tr>
    </table>
</body>
</html>

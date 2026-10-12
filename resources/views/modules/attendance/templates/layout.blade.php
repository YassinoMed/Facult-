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

        /* ── En-tête institutionnel ── */
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

        /* ── Cartouche de métadonnées de la séance ── */
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
            font-weight: bold;
        }

        /* ── Tableau de présence ── */
        table.table-presence {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4pt;
            page-break-inside: auto;
        }
        table.table-presence thead {
            display: table-header-group;
        }
        table.table-presence tr {
            page-break-inside: avoid;
            page-break-after: auto;
        }
        table.table-presence th {
            background-color: #f1f5f9;
            color: #1e293b;
            font-weight: bold;
            font-size: 8pt;
            text-align: left;
            padding: 4pt 5pt;
            border: 0.5pt solid #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.2pt;
        }
        table.table-presence td {
            padding: 4pt 5pt;
            border: 0.5pt solid #cbd5e1;
            font-size: 8pt;
            color: #1e293b;
            vertical-align: middle;
        }
        table.table-presence tr:nth-child(even) td {
            background-color: #fafbfd;
        }
        .cell-centre {
            text-align: center;
        }
        .cell-droite {
            text-align: right;
        }
        .cell-mono {
            font-family: monospace;
            font-size: 8pt;
        }
        .cell-signature {
            min-height: 22pt;
            height: 22pt;
            background-color: #ffffff;
        }
        .cell-checkbox {
            text-align: center;
            font-size: 11pt;
            color: #64748b;
        }

        /* ── Pied de page & signatures finales ── */
        .bloc-signatures-fin {
            margin-top: 10pt;
            page-break-inside: avoid;
        }
        .table-signatures-fin {
            width: 100%;
            border-collapse: collapse;
        }
        .table-signatures-fin td {
            border: 0.5pt dashed #94a3b8;
            border-radius: 4pt;
            padding: 8pt 10pt;
            vertical-align: top;
            width: 50%;
            background-color: #fafbfc;
        }
        .sig-titre {
            font-weight: bold;
            font-size: 8pt;
            color: #1e3a5f;
            text-transform: uppercase;
            margin-bottom: 18pt;
        }
        .sig-mentions {
            font-size: 7.5pt;
            color: #64748b;
        }

        /* ── Pied de document ── */
        .pied-document {
            position: fixed;
            bottom: -11mm;
            left: 0;
            right: 0;
            font-size: 7pt;
            color: #64748b;
            border-top: 0.5pt solid #cbd5e1;
            padding-top: 3pt;
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

        /* Écran / Impression */
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
        <!-- ── En-tête institutionnel ── -->
        <div class="entete-officiel">
            <table class="entete-table">
                <tr>
                    <td style="width: 60%;">
                        <div class="institution-nom">{{ $metadonnees['institution'] }}</div>
                        <div class="faculte-nom">{{ $metadonnees['faculte'] }}</div>
                        @if(! empty($metadonnees['departement']))
                            <div class="departement-nom">{{ $metadonnees['departement'] }}</div>
                        @endif
                    </td>
                    <td style="width: 40%; text-align: right;">
                        <div class="titre-document">{{ $metadonnees['titre'] }}</div>
                        <div class="badge-type">{{ $template->name }}</div>
                        <div style="font-size: 7.5pt; color: #64748b; margin-top: 2pt;">
                            Réf : {{ $metadonnees['reference'] }}
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- ── Cartouche de séance ── -->
        @section('cartouche')
            <div class="cartouche-seance">
                <table class="grille-meta">
                    <tr>
                        <td style="width: 25%;">
                            <span class="meta-label">Groupe :</span>
                            <span class="meta-valeur">{{ $metadonnees['groupe'] }}</span>
                        </td>
                        <td style="width: 30%;">
                            <span class="meta-label">Matière / ECUE :</span>
                            <span class="meta-valeur">{{ $metadonnees['matiere'] }}</span>
                        </td>
                        <td style="width: 25%;">
                            <span class="meta-label">Enseignant :</span>
                            <span class="meta-valeur">{{ $metadonnees['enseignant'] }}</span>
                        </td>
                        <td style="width: 20%; text-align: right;">
                            <span class="meta-label">Année :</span>
                            <span class="meta-valeur">{{ $metadonnees['annee_universitaire'] }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <span class="meta-label">Date :</span>
                            <span class="meta-valeur">{{ $metadonnees['date'] }}</span>
                        </td>
                        <td>
                            <span class="meta-label">Horaire / Séance :</span>
                            <span class="meta-valeur">{{ $metadonnees['horaire'] }}</span>
                        </td>
                        <td>
                            <span class="meta-label">Salle :</span>
                            <span class="meta-valeur">{{ $metadonnees['salle'] }}</span>
                        </td>
                        <td style="text-align: right;">
                            <span class="meta-label">Effectif :</span>
                            <span class="meta-valeur">{{ $metadonnees['total'] }} étudiant(s)</span>
                        </td>
                    </tr>
                </table>
            </div>
        @show

        <!-- ── Contenu spécifique du gabarit ── -->
        @yield('tableau')

        <!-- ── Bloc signatures finales ── -->
        @section('signatures_finales')
            <div class="bloc-signatures-fin">
                <table class="table-signatures-fin">
                    <tr>
                        <td>
                            <div class="sig-titre">Visa de l'enseignant responsable</div>
                            <div class="sig-mentions">
                                Nom : {{ $metadonnees['enseignant'] }}<br>
                                Date : {{ $metadonnees['date'] }}<br>
                                Signature et observations :
                            </div>
                        </td>
                        <td style="margin-left: 8pt;">
                            <div class="sig-titre">Administration / Scolarité</div>
                            <div class="sig-mentions">
                                Cachet et validation de la séance :<br>
                                Nombre d'étudiants présents : ……… / {{ $metadonnees['total'] }}<br>
                                Date de réception : ………………………………
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
        @show

        <!-- ── Pied de page ── -->
        <div class="pied-document">
            <table class="pied-table">
                <tr>
                    <td style="text-align: left;">
                        {{ $metadonnees['institution'] }} · Document officiel de scolarité · Réf : {{ $metadonnees['reference'] }}
                    </td>
                    <td style="text-align: right;">
                        Généré le {{ $metadonnees['date_generation'] }} · Page <span class="pagenum"></span> sur <span class="pagecount"></span>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>

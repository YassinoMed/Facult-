<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $titreDocument ?? 'Document officiel d\'examen' }} — {{ $reference ?? '' }}</title>
    <style>
        @page {
            size: A4 {{ $orientation ?? 'portrait' }};
            margin: 14mm 12mm 16mm 12mm;
        }

        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        body {
            font-family: 'DejaVu Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 9pt;
            color: #0f172a;
            line-height: 1.4;
            background-color: #ffffff;
            margin: 0;
            padding: 0;
        }



        /* Filigrane d'état officiel */
        .filigrane-arriere-plan {
            position: fixed;
            top: 40%;
            left: 5%;
            width: 90%;
            text-align: center;
            font-size: 42pt;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 4px;
            transform: rotate(-25deg);
            z-index: -10;
            opacity: 0.08;
            pointer-events: none;
            color: {{ $statutDoc === 'officiel' ? '#059669' : ($statutDoc === 'proposition' ? '#2563eb' : '#dc2626') }};
        }

        /* En-tête institutionnel solennel */
        .entete-institutionnelle {
            border-bottom: 2pt solid #0f172a;
            padding-bottom: 8pt;
            margin-bottom: 10pt;
        }

        .grille-entete {
            width: 100%;
            margin-bottom: 6pt;
        }

        .grille-entete td {
            border: none;
            padding: 0;
            vertical-align: top;
        }

        .bloc-gauche {
            text-align: left;
            width: 50%;
        }

        .bloc-droit {
            text-align: right;
            width: 50%;
        }

        .republique {
            font-size: 8.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #334155;
        }

        .devise {
            font-size: 7pt;
            font-style: italic;
            color: #64748b;
            margin-top: 1pt;
        }

        .nom-universite {
            font-size: 11pt;
            font-weight: bold;
            color: #0f172a;
            margin-top: 2pt;
        }

        .nom-faculte {
            font-size: 9pt;
            color: #1e3a5f;
            font-weight: 600;
        }

        .reference-doc {
            font-size: 8pt;
            font-family: monospace;
            color: #0f172a;
            font-weight: bold;
        }

        .date-emission {
            font-size: 7.5pt;
            color: #475569;
            margin-top: 2pt;
        }

        .badge-statut {
            display: inline-block;
            margin-top: 4pt;
            padding: 2pt 6pt;
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
            border-radius: 3pt;
            letter-spacing: 0.5px;
            border: 0.75pt solid;
            @if($statutDoc === 'officiel')
                background-color: #ecfdf5;
                color: #065f46;
                border-color: #10b981;
            @elseif($statutDoc === 'proposition')
                background-color: #eff6ff;
                color: #1e40af;
                border-color: #3b82f6;
            @elseif($statutDoc === 'archive')
                background-color: #f1f5f9;
                color: #475569;
                border-color: #94a3b8;
            @else
                background-color: #fef2f2;
                color: #991b1b;
                border-color: #ef4444;
            @endif
        }

        /* Titre du document */
        .titre-document-cadre {
            background-color: #f8fafc;
            border: 1pt solid #cbd5e1;
            border-left: 4pt solid #1e3a5f;
            padding: 7pt 10pt;
            margin-bottom: 10pt;
            border-radius: 2pt;
        }

        .titre-principal {
            font-size: 12.5pt;
            font-weight: bold;
            color: #0f172a;
            text-transform: uppercase;
            margin: 0 0 2pt 0;
            letter-spacing: 0.5px;
        }

        .sous-titre-desc {
            font-size: 8pt;
            color: #475569;
            margin: 0;
        }

        /* Cartouche de métadonnées */
        .cartouche-metadonnees {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10pt;
            background-color: #ffffff;
            border: 0.5pt solid #cbd5e1;
        }

        .cartouche-metadonnees td {
            border: 0.5pt solid #e2e8f0;
            padding: 3.5pt 6pt;
            font-size: 8pt;
        }

        .cartouche-metadonnees .cle {
            width: 20%;
            font-weight: bold;
            color: #475569;
            background-color: #f8fafc;
        }

        .cartouche-metadonnees .valeur {
            width: 30%;
            color: #0f172a;
            font-weight: 600;
        }

        /* Tableaux de données officiels */
        table.tableau-donnees {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4pt;
            margin-bottom: 12pt;
        }

        table.tableau-donnees thead {
            display: table-header-group;
        }

        table.tableau-donnees tr {
            page-break-inside: avoid;
        }

        table.tableau-donnees th {
            background-color: #f1f5f9;
            color: #0f172a;
            border: 0.5pt solid #94a3b8;
            padding: 4pt 6pt;
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            text-align: left;
        }

        table.tableau-donnees td {
            border: 0.5pt solid #cbd5e1;
            padding: 4.5pt 6pt;
            font-size: 8pt;
            color: #1e293b;
        }

        table.tableau-donnees tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }

        /* Zone de signature solennelle */
        .bloc-signatures {
            margin-top: 14pt;
            page-break-inside: avoid;
            border-top: 1pt solid #cbd5e1;
            padding-top: 8pt;
        }

        .titre-signatures {
            font-size: 8pt;
            font-weight: bold;
            text-transform: uppercase;
            color: #475569;
            margin-bottom: 6pt;
            letter-spacing: 0.5px;
        }

        .table-signatures {
            width: 100%;
            border-collapse: collapse;
        }

        .table-signatures td {
            width: 33.33%;
            border: 0.5pt dashed #94a3b8;
            padding: 8pt 6pt;
            vertical-align: top;
            font-size: 7.5pt;
            height: 52pt;
            background-color: #fafbfc;
        }

        .role-signataire {
            font-weight: bold;
            color: #0f172a;
            margin-bottom: 2pt;
            text-decoration: underline;
        }

        .mention-signature {
            font-size: 6.5pt;
            color: #64748b;
        }

        /* Pied de page fixe A4 */
        .pied-de-page-fixe {
            position: fixed;
            bottom: -11mm;
            left: 0;
            right: 0;
            border-top: 0.5pt solid #94a3b8;
            padding-top: 3pt;
            font-size: 6.5pt;
            color: #64748b;
            display: flex;
            justify-content: space-between;
        }

        .pagenum:before {
            content: counter(page);
        }

        .pagecount:before {
            content: counter(pages);
        }

        /* Avertissement brouillon */
        .alerte-non-officiel {
            background-color: #fffbeb;
            border: 1pt solid #fcd34d;
            border-left: 3pt solid #f59e0b;
            padding: 5pt 8pt;
            margin-bottom: 8pt;
            font-size: 7.5pt;
            color: #92400e;
            font-weight: 600;
        }

        /* Media queries pour impression */
        @media print {
            .no-print {
                display: none !important;
            }

            body {
                padding: 0 !important;
                background: transparent !important;
            }
        }
    </style>
</head>
<body>



    {{-- Filigrane textuel transparent en arrière-plan --}}
    @if(!empty($filigrane))
        <div class="filigrane-arriere-plan" aria-hidden="true">{{ $filigrane }}</div>
    @endif

    {{-- Avertissement visible si proposition ou brouillon --}}
    @if($statutDoc !== 'officiel')
        <div class="alerte-non-officiel">
            ⚠️ <strong>AVERTISSEMENT RÉGLEMENTAIRE :</strong> Ce document porte la mention <em>{{ $filigrane }}</em>. Il ne constitue pas un document définitif opposable et ne peut être affiché sans les visas d'approbation requis.
        </div>
    @endif

    {{-- En-tête institutionnel --}}
    <div class="entete-institutionnelle">
        <table class="grille-entete">
            <tr>
                <td class="bloc-gauche">
                    <div class="republique">République &amp; Ministère de l'Enseignement Supérieur</div>
                    <div class="nom-universite">{{ $institution ?? 'UniRegistre Université' }}</div>
                    <div class="nom-faculte">{{ $faculteNom ?? 'Faculté d\'Enseignement Supérieur' }}</div>
                    <div class="devise">Année Universitaire : {{ $anneeUniversitaire }}</div>
                </td>
                <td class="bloc-droit">
                    <div class="reference-doc">Réf : {{ $reference }}</div>
                    <div class="date-emission">Émis le {{ $dateGeneration }} par {{ $utilisateur }}</div>
                    <div>
                        <span class="badge-statut">{{ $filigrane }}</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- Titre et objet du document --}}
    <div class="titre-document-cadre">
        <h1 class="titre-principal">{{ $titreDocument }}</h1>
        @if(!empty($description))
            <p class="sous-titre-desc">{{ $description }}</p>
        @endif
    </div>

    {{-- Cartouche des métadonnées contextuelles --}}
    @if(!empty($metadonnees))
        <table class="cartouche-metadonnees">
            @php
                $paires = array_chunk(array_map(null, array_keys($metadonnees), array_values($metadonnees)), 2);
            @endphp
            @foreach($paires as $paire)
                <tr>
                    <td class="cle">{{ $paire[0][0] }}</td>
                    <td class="valeur">{{ $paire[0][1] }}</td>
                    @if(isset($paire[1]))
                        <td class="cle">{{ $paire[1][0] }}</td>
                        <td class="valeur">{{ $paire[1][1] }}</td>
                    @else
                        <td class="cle"></td>
                        <td class="valeur"></td>
                    @endif
                </tr>
            @endforeach
        </table>
    @endif

    {{-- Corps de table des données --}}
    @if(!empty($lignes))
        <table class="tableau-donnees">
            <thead>
                <tr>
                    @foreach($entetes as $entete)
                        <th>{{ $entete }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($lignes as $ligne)
                    <tr>
                        @foreach($entetes as $entete)
                            <td>{{ $ligne[$entete] ?? '—' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div style="padding: 24pt; text-align: center; border: 1pt dashed #cbd5e1; background-color: #f8fafc; border-radius: 4pt; margin: 16pt 0;">
            <p style="font-weight: bold; color: #475569; margin: 0 0 4pt 0;">Aucune donnée correspondante enregistrée</p>
            <p style="font-size: 7.5pt; color: #94a3b8; margin: 0;">Aucun enregistrement ne correspond aux filtres sélectionnés pour ce document d'examen.</p>
        </div>
    @endif

    {{-- Notes et prescriptions de bas de page --}}
    @if(!empty($notesBasDePage))
        <p style="font-size: 7pt; color: #64748b; font-style: italic; margin-top: 6pt; margin-bottom: 10pt;">
            * Prescription : {{ $notesBasDePage }}
        </p>
    @endif

    {{-- Zone de signatures et visas formels --}}
    @if($zoneSignature ?? true)
        <div class="bloc-signatures">
            <div class="titre-signatures">Visas, cachets et approbations institutionnelles</div>
            <table class="table-signatures">
                <tr>
                    <td>
                        <div class="role-signataire">Le Chef de Salle / Surveillant Responsable</div>
                        <div class="mention-signature">Signature, date et heure :</div>
                    </td>
                    <td>
                        <div class="role-signataire">Le Président du Jury d'Examen</div>
                        <div class="mention-signature">Visa pour conformité académique :</div>
                    </td>
                    <td>
                        <div class="role-signataire">Le Doyen / Direction des Examens</div>
                        <div class="mention-signature">Approbation finale et sceau officiel :</div>
                    </td>
                </tr>
            </table>
        </div>
    @endif

    {{-- Pied de page fixe pour impression A4 --}}
    <div class="pied-de-page-fixe">
        <span>UniRegistre — {{ $institution }} · {{ $reference }} · Confidentiel</span>
        <span>Page <span class="pagenum"></span> / <span class="pagecount"></span></span>
    </div>

    @if(!empty($autoPrint) && $autoPrint === true)
        <script>
            window.addEventListener('DOMContentLoaded', function() {
                setTimeout(function() {
                    window.print();
                }, 300);
            });
        </script>
    @endif

</body>
</html>

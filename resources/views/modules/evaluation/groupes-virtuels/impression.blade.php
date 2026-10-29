<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $titreDocument ?? 'Document officiel' }} — {{ $reference ?? '' }}</title>
    <style>
        @page {
            size: A4 {{ $orientation ?? 'portrait' }};
            margin: 12mm 10mm 14mm 10mm;
        }

        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        body {
            font-family: 'DejaVu Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 8.5pt;
            color: #0f172a;
            line-height: 1.35;
            background-color: #ffffff;
            margin: 0;
            padding: 0;
        }

        .no-print {
            background-color: #f1f5f9;
            border-bottom: 1px solid #cbd5e1;
            padding: 10px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 9pt;
        }

        @media print {
            .no-print {
                display: none !important;
            }
            body {
                padding: 0 !important;
            }
        }

        .btn-print {
            background-color: #1e3a5f;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            padding: 6px 14px;
            font-size: 9pt;
            font-weight: bold;
            cursor: pointer;
        }

        .btn-print:hover {
            background-color: #0f172a;
        }

        .document-container {
            padding: 4mm 2mm;
        }

        /* En-tête institutionnel */
        .entete-institutionnelle {
            border-bottom: 1.5pt solid #0f172a;
            padding-bottom: 6pt;
            margin-bottom: 8pt;
        }

        .grille-entete {
            width: 100%;
            border-collapse: collapse;
        }

        .grille-entete td {
            border: none;
            padding: 0;
            vertical-align: top;
        }

        .nom-universite {
            font-size: 10.5pt;
            font-weight: bold;
            text-transform: uppercase;
            color: #0f172a;
            letter-spacing: 0.5px;
        }

        .nom-faculte {
            font-size: 9pt;
            font-weight: 600;
            color: #1e3a5f;
            margin-top: 1pt;
        }

        .reference-doc {
            font-size: 7.5pt;
            font-family: monospace;
            color: #475569;
            font-weight: bold;
            text-align: right;
        }

        .date-emission {
            font-size: 7.5pt;
            color: #475569;
            text-align: right;
            margin-top: 1pt;
        }

        /* Bandeau de statut */
        .badge-statut {
            display: inline-block;
            margin-top: 3pt;
            padding: 2pt 6pt;
            font-size: 7pt;
            font-weight: bold;
            text-transform: uppercase;
            border-radius: 3pt;
            border: 0.75pt solid;
            @if(str_contains($statutBandeau ?? '', 'OFFICIEL'))
                background-color: #ecfdf5;
                color: #065f46;
                border-color: #10b981;
            @elseif(str_contains($statutBandeau ?? '', 'VALIDÉE'))
                background-color: #eff6ff;
                color: #1e40af;
                border-color: #3b82f6;
            @else
                background-color: #fffbeb;
                color: #92400e;
                border-color: #f59e0b;
            @endif
        }

        /* Titre du document */
        .cadre-titre {
            background-color: #f8fafc;
            border: 1pt solid #cbd5e1;
            border-left: 3.5pt solid #1e3a5f;
            padding: 5pt 8pt;
            margin-bottom: 8pt;
            border-radius: 2pt;
        }

        .titre-principal {
            font-size: 11pt;
            font-weight: bold;
            color: #0f172a;
            text-transform: uppercase;
            margin: 0;
            letter-spacing: 0.3px;
        }

        .sous-titre {
            font-size: 7.5pt;
            color: #475569;
            margin: 2pt 0 0 0;
        }

        /* Cartouche métadonnées */
        .table-meta {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8pt;
        }

        .table-meta td {
            border: 0.5pt solid #cbd5e1;
            padding: 3pt 5pt;
            font-size: 7.5pt;
        }

        .table-meta .cle {
            width: 18%;
            background-color: #f8fafc;
            font-weight: bold;
            color: #475569;
        }

        .table-meta .valeur {
            width: 32%;
            font-weight: 600;
            color: #0f172a;
        }

        /* Tableaux de données */
        table.table-donnees {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4pt;
            margin-bottom: 8pt;
        }

        table.table-donnees thead {
            display: table-header-group;
        }

        table.table-donnees tr {
            page-break-inside: avoid;
        }

        table.table-donnees th {
            background-color: #f1f5f9;
            color: #0f172a;
            border: 0.5pt solid #94a3b8;
            padding: 3.5pt 4pt;
            font-size: 7pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.2px;
            text-align: left;
        }

        table.table-donnees td {
            border: 0.5pt solid #cbd5e1;
            padding: 3.5pt 4pt;
            font-size: 7.5pt;
            color: #1e293b;
        }

        table.table-donnees tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }

        /* Zone de signature solennelle */
        .bloc-signatures {
            margin-top: 10pt;
            page-break-inside: avoid;
            border-top: 1pt solid #cbd5e1;
            padding-top: 6pt;
        }

        .titre-signatures {
            font-size: 7.5pt;
            font-weight: bold;
            text-transform: uppercase;
            color: #475569;
            margin-bottom: 4pt;
        }

        .table-signatures {
            width: 100%;
            border-collapse: collapse;
        }

        .table-signatures td {
            width: 50%;
            border: 0.5pt dashed #cbd5e1;
            height: 38pt;
            vertical-align: top;
            padding: 4pt 6pt;
            font-size: 7pt;
            color: #64748b;
        }

        .pied-de-page {
            margin-top: 8pt;
            font-size: 7pt;
            color: #64748b;
            text-align: center;
            border-top: 0.5pt solid #e2e8f0;
            padding-top: 3pt;
        }
    </style>
</head>
<body>

@if(!$estPdf)
    <div class="no-print">
        <div>
            <strong>{{ $titreDocument }}</strong> — UniRegistre Impression professionnelle
            <span style="color: #64748b; margin-left: 8px;">(Feuille A4 formatée avec marges et pagination)</span>
        </div>
        <div style="display: flex; gap: 8px;">
            <button onclick="window.print()" class="btn-print">🖨️ Imprimer la page</button>
            <button onclick="window.close()" class="btn-print" style="background-color: #64748b;">Fermer</button>
        </div>
    </div>
@endif

<div class="document-container">
    {{-- En-tête institutionnel solennel --}}
    <div class="entete-institutionnelle">
        <table class="grille-entete">
            <tr>
                <td style="width: 50%;">
                    <div class="nom-universite">{{ $institution }}</div>
                    <div class="nom-faculte">{{ $resume['faculte_nom'] ?? 'Faculté universitaire' }}</div>
                    <div style="font-size: 7.5pt; color: #64748b; margin-top: 1pt;">Direction des Études & Commission des Examens Finaux</div>
                </td>
                <td style="width: 20%; text-align: center; vertical-align: middle;">
                    @if(!empty($qrCodeSvg))
                        <div style="display: inline-block; text-align: center;">
                            <div style="width: 52pt; height: 52pt; margin: 0 auto;">
                                {!! $qrCodeSvg !!}
                            </div>
                            <div style="font-size: 5.5pt; color: #475569; font-family: monospace; margin-top: 1pt;">Certification UniRegistre</div>
                        </div>
                    @endif
                </td>
                <td style="width: 30%; text-align: right;">
                    <div class="reference-doc">{{ $reference }}</div>
                    <div class="date-emission">Édition : {{ $dateEdition }}</div>
                    <div>
                        <span class="badge-statut">{{ $statutBandeau }}</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- Cadre Titre --}}
    <div class="cadre-titre">
        <h1 class="titre-principal">{{ $titreDocument }}</h1>
        <p class="sous-titre">
            {{ $resume['libelle'] ?? 'Groupes virtuels d\'examens finaux' }} · Effectif total concerné : <strong>{{ $effectifTotal }} étudiant(s)</strong>
        </p>
    </div>

    {{-- Cartouche de contexte --}}
    <table class="table-meta">
        <tr>
            <td class="cle">Année universitaire</td>
            <td class="valeur">{{ $resume['annee_libelle'] ?? '—' }}</td>
            <td class="cle">Session d'examens</td>
            <td class="valeur">{{ ($resume['session_code'] ?? '—') . ' — ' . ($resume['session_nom'] ?? '—') }}</td>
        </tr>
        <tr>
            <td class="cle">Matière examinée</td>
            <td class="valeur">[{{ $resume['matiere_code'] ?? '—' }}] {{ $resume['matiere_nom'] ?? '—' }}</td>
            <td class="cle">Groupes sources d'origine</td>
            <td class="valeur">{{ $resume['groupes_sources_codes'] ?? ($resume['groupes_sources'] ?? '—') }}</td>
        </tr>
        @if(!empty($resume['epreuve_code']))
            <tr>
                <td class="cle">Épreuve associée</td>
                <td class="valeur">{{ $resume['epreuve_code'] }}</td>
                <td class="cle">Date & Horaire</td>
                <td class="valeur">{{ ($resume['epreuve_date'] ?? '—') . ' (' . ($resume['epreuve_horaire'] ?? '—') . ')' }}</td>
            </tr>
        @endif
    </table>

    {{-- 1. DOCUMENT : Fiche récapitulative du groupe virtuel --}}
    @if($modeDocument === 'fiche')
        <div style="margin-bottom: 6pt; font-size: 8pt; font-weight: bold; text-transform: uppercase; color: #1e3a5f;">
            1. Bilan des effectifs et des contrôles de correspondance
        </div>
        <table class="table-meta" style="margin-bottom: 8pt;">
            <tr>
                <td class="cle">Effectif groupes d'origine</td>
                <td class="valeur">{{ $fiche['effectifs']['origine_total'] ?? 0 }} étudiants</td>
                <td class="cle">Effectif attendu (référence)</td>
                <td class="valeur">{{ $fiche['effectifs']['attendus_reference'] ?? 0 }} étudiants éligibles</td>
            </tr>
            <tr>
                <td class="cle">Effectif enregistré éligible</td>
                <td class="valeur">{{ $fiche['effectifs']['enregistres_eligibles'] ?? 0 }} candidats</td>
                <td class="cle">Étudiants exclus des sources</td>
                <td class="valeur">{{ $fiche['effectifs']['exclus_total'] ?? 0 }} exclus</td>
            </tr>
            <tr>
                <td class="cle">Statut de validation</td>
                <td class="valeur">{{ $resume['statut_validation_libelle'] ?? 'Non validé' }}</td>
                <td class="cle">Statut de publication</td>
                <td class="valeur">{{ $resume['statut_publication_libelle'] ?? 'Non publié' }}</td>
            </tr>
        </table>

        <div style="margin-bottom: 4pt; font-size: 8pt; font-weight: bold; text-transform: uppercase; color: #1e3a5f;">
            2. Détail des groupes académiques sources (préservés intacts)
        </div>
        <table class="table-donnees">
            <thead>
                <tr>
                    <th style="width: 15%;">Code groupe</th>
                    <th style="width: 35%;">Nom du groupe</th>
                    <th style="width: 15%;">Programme / Niveau</th>
                    <th style="width: 10%; text-align: center;">Origine</th>
                    <th style="width: 12%; text-align: center;">Éligibles</th>
                    <th style="width: 13%; text-align: center;">Exclus</th>
                </tr>
            </thead>
            <tbody>
                @forelse($fiche['groupes_sources_detail'] ?? [] as $gs)
                    <tr>
                        <td style="font-family: monospace; font-weight: bold;">{{ $gs['code'] }}</td>
                        <td>{{ $gs['nom'] }}</td>
                        <td>{{ $gs['programme'] }} / {{ $gs['niveau'] }}</td>
                        <td style="text-align: center; font-weight: bold;">{{ $gs['effectif_origine'] }}</td>
                        <td style="text-align: center; font-weight: bold; color: #065f46;">{{ $gs['eligibles_retenus'] }}</td>
                        <td style="text-align: center; color: #92400e;">{{ $gs['exclus'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; color: #64748b;">Aucun groupe source répertorié.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

    {{-- 2. DOCUMENT : Liste nominative des étudiants éligibles --}}
    @elseif($modeDocument === 'etudiants')
        <table class="table-donnees">
            <thead>
                <tr>
                    <th style="width: 5%; text-align: center;">N°</th>
                    <th style="width: 16%;">Numéro étudiant</th>
                    <th style="width: 20%;">Nom</th>
                    <th style="width: 18%;">Prénom</th>
                    <th style="width: 16%;">Groupe d'origine</th>
                    <th style="width: 15%;">Matière</th>
                    <th style="width: 10%; text-align: center;">Éligibilité</th>
                </tr>
            </thead>
            <tbody>
                @forelse($fiche['etudiants_eligibles'] ?? [] as $etudiant)
                    <tr>
                        <td style="text-align: center; font-family: monospace;">{{ $etudiant['numero'] }}</td>
                        <td style="font-family: monospace; font-weight: bold;">{{ $etudiant['num_etudiant'] }}</td>
                        <td style="font-weight: bold;">{{ $etudiant['nom'] }}</td>
                        <td>{{ $etudiant['prenom'] }}</td>
                        <td>{{ $etudiant['groupe_origine_code'] }}</td>
                        <td>{{ $etudiant['matiere_code'] }}</td>
                        <td style="text-align: center; font-weight: bold; color: #065f46;">{{ $etudiant['statut_eligibilite'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align: center; color: #64748b; padding: 12pt;">
                            Aucun étudiant éligible rattaché à ce groupe virtuel.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

    {{-- 3. DOCUMENT : Feuille d'émargement avec cases de signature --}}
    @elseif($modeDocument === 'emargement')
        <table class="table-donnees">
            <thead>
                <tr>
                    <th style="width: 4%; text-align: center;">N°</th>
                    <th style="width: 14%;">Matricule</th>
                    <th style="width: 24%;">Nom & Prénom</th>
                    <th style="width: 14%;">Groupe d'origine</th>
                    <th style="width: 10%;">Salle</th>
                    <th style="width: 8%; text-align: center;">Place</th>
                    <th style="width: 26%;">Signature de l'étudiant</th>
                </tr>
            </thead>
            <tbody>
                @forelse($fiche['etudiants_eligibles'] ?? [] as $etudiant)
                    <tr style="height: 22pt;">
                        <td style="text-align: center; font-family: monospace;">{{ $etudiant['numero'] }}</td>
                        <td style="font-family: monospace; font-weight: bold;">{{ $etudiant['num_etudiant'] }}</td>
                        <td style="font-weight: bold;">{{ $etudiant['nom_complet'] ?? ($etudiant['nom'].' '.$etudiant['prenom']) }}</td>
                        <td>{{ $etudiant['groupe_origine_code'] }}</td>
                        <td>{{ $etudiant['salle'] !== '—' ? $etudiant['salle'] : 'Non assignée' }}</td>
                        <td style="text-align: center; font-family: monospace;">{{ $etudiant['place'] }}</td>
                        <td style="border: 0.5pt solid #94a3b8; background-color: #ffffff;"></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align: center; color: #64748b; padding: 12pt;">
                            Aucun candidat éligible sur cette épreuve.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{-- Bilan de relevé de présence pour les surveillants --}}
        <table class="table-meta" style="margin-top: 8pt; width: 100%;">
            <tr>
                <td class="cle">Nombre de présents :</td>
                <td class="valeur" style="width: 30%;">__________</td>
                <td class="cle">Nombre de copies remises :</td>
                <td class="valeur" style="width: 30%;">__________</td>
            </tr>
            <tr>
                <td class="cle">Nombre d'absents :</td>
                <td class="valeur">__________</td>
                <td class="cle">Intercalaires distribués :</td>
                <td class="valeur">__________</td>
            </tr>
        </table>

    {{-- 4. DOCUMENT : Synthèse par matière ou globale de la session --}}
    @elseif($modeDocument === 'synthese_matiere' || $modeDocument === 'synthese_session')
        @if(!empty($syntheseParMatiere))
            <div style="margin-bottom: 4pt; font-size: 8pt; font-weight: bold; text-transform: uppercase; color: #1e3a5f;">
                Récapitulatif consolidé par matière examinée
            </div>
            <table class="table-donnees">
                <thead>
                    <tr>
                        <th style="width: 25%;">Matière</th>
                        <th style="width: 10%; text-align: center;">Groupes virtuels</th>
                        <th style="width: 35%;">Groupes sources d'origine</th>
                        <th style="width: 10%; text-align: center;">Attendus</th>
                        <th style="width: 10%; text-align: center;">Éligibles</th>
                        <th style="width: 10%; text-align: center;">Exclus</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($syntheseParMatiere as $sm)
                        <tr>
                            <td style="font-weight: bold;">{{ $sm['matiere'] }}</td>
                            <td style="text-align: center; font-family: monospace;">{{ $sm['nb_groupes_virtuels'] }}</td>
                            <td>{{ $sm['groupes_sources'] ?: '—' }}</td>
                            <td style="text-align: center;">{{ $sm['effectif_attendu'] }}</td>
                            <td style="text-align: center; font-weight: bold; color: #065f46;">{{ $sm['effectif_eligible'] }}</td>
                            <td style="text-align: center; color: #92400e;">{{ $sm['effectif_exclu'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div style="margin-top: 8pt; margin-bottom: 4pt; font-size: 8pt; font-weight: bold; text-transform: uppercase; color: #1e3a5f;">
            Liste exhaustive des groupes virtuels de la session
        </div>
        <table class="table-donnees">
            <thead>
                <tr>
                    <th style="width: 8%;">ID</th>
                    <th style="width: 26%;">Libellé du groupe virtuel</th>
                    <th style="width: 20%;">Matière</th>
                    <th style="width: 18%;">Groupes d'origine</th>
                    <th style="width: 10%; text-align: center;">Éligibles</th>
                    <th style="width: 18%;">Statuts</th>
                </tr>
            </thead>
            <tbody>
                @forelse($resumesSelection ?? [] as $r)
                    <tr>
                        <td style="font-family: monospace; font-weight: bold;">#{{ $r['id'] }}</td>
                        <td style="font-weight: bold;">{{ $r['libelle'] }}</td>
                        <td>[{{ $r['matiere_code'] }}] {{ $r['matiere_nom'] }}</td>
                        <td>{{ $r['groupes_sources_codes'] }}</td>
                        <td style="text-align: center; font-weight: bold; color: #065f46;">{{ $r['effectif_eligible'] }}</td>
                        <td style="font-size: 7pt;">
                            {{ $r['statut_generation_libelle'] }}<br>
                            <span style="color: #64748b;">{{ $r['statut_validation_libelle'] }}</span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; color: #64748b;">Aucun groupe virtuel sélectionné.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    @endif

    {{-- Zone de signatures solennelles --}}
    <div class="bloc-signatures">
        <div class="titre-signatures">Visas et attestations contradictoires</div>
        <table class="table-signatures">
            <tr>
                <td>
                    <strong>Le Responsable Pédagogique / Chef de Centre</strong><br>
                    Nom & Prénom : ____________________________<br>
                    Date & Signature :
                </td>
                <td>
                    <strong>Le Chef de Salle / Surveillant Principal</strong><br>
                    Nom & Prénom : ____________________________<br>
                    Date & Signature :
                </td>
            </tr>
        </table>
    </div>

    <div class="pied-de-page">
        Document académique généré le {{ $dateEdition }} via UniRegistre · Conformité stricte Matière ↔ Groupes ↔ Étudiants · Page 1 / 1
    </div>
</div>

</body>
</html>


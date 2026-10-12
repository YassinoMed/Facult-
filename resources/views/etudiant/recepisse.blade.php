<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Récépissé de dépôt — {{ $etudiant->num_inscription }}</title>
    <style>
        @page {
            margin: 25mm 20mm;
            size: a4 portrait;
        }
        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.45;
            color: #1f2937;
            margin: 0;
            padding: 0;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #1e3a8a;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .institution {
            font-size: 13px;
            font-weight: bold;
            color: #1e3a8a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .titre-doc {
            font-size: 17px;
            font-weight: bold;
            color: #111827;
            margin: 6px 0 2px 0;
            text-transform: uppercase;
        }
        .sous-titre {
            font-size: 10px;
            color: #6b7280;
            font-style: italic;
        }
        .avertissement-box {
            background-color: #fffbeb;
            border: 1.5px solid #d97706;
            border-radius: 4px;
            padding: 10px 14px;
            margin-bottom: 16px;
        }
        .avertissement-titre {
            font-size: 10.5px;
            font-weight: bold;
            color: #92400e;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .avertissement-texte {
            font-size: 9.5px;
            color: #78350f;
            line-height: 1.4;
        }
        .section-box {
            border: 1px solid #d1d5db;
            border-radius: 4px;
            margin-bottom: 14px;
            background-color: #ffffff;
        }
        .section-header {
            background-color: #f3f4f6;
            border-bottom: 1px solid #d1d5db;
            padding: 6px 12px;
            font-size: 11px;
            font-weight: bold;
            color: #1e3a8a;
            text-transform: uppercase;
        }
        .section-body {
            padding: 10px 12px;
        }
        table.grid {
            width: 100%;
            border-collapse: collapse;
        }
        table.grid td {
            padding: 4px 6px;
            vertical-align: top;
            font-size: 10.5px;
        }
        td.label {
            width: 32%;
            color: #4b5563;
            font-weight: normal;
        }
        td.valeur {
            width: 68%;
            color: #111827;
        }
        .badge-statut {
            display: inline-block;
            padding: 2px 8px;
            font-size: 10px;
            font-weight: bold;
            border-radius: 3px;
        }
        .statut-en_attente {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #f59e0b;
        }
        .statut-valide {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #10b981;
        }
        .statut-rejete {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #ef4444;
        }
        table.liste-docs {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }
        table.liste-docs th {
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            padding: 5px 8px;
            font-size: 9.5px;
            font-weight: bold;
            text-align: left;
            color: #374151;
            text-transform: uppercase;
        }
        table.liste-docs td {
            border: 1px solid #e5e7eb;
            padding: 5px 8px;
            font-size: 9.5px;
        }
        .notice-vide {
            font-size: 10px;
            color: #6b7280;
            font-style: italic;
            padding: 4px 0;
        }
        .footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px dashed #9ca3af;
            text-align: center;
            font-size: 9px;
            color: #6b7280;
        }
        .code-securite {
            font-family: monospace;
            font-size: 9px;
            color: #4b5563;
            letter-spacing: 1px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="institution">{{ $etudiant->faculte?->nom ?? 'Établissement d’Enseignement Supérieur' }}</div>
        <div class="titre-doc">Récépissé officiel de dépôt de candidature</div>
        <div class="sous-titre">Preuve de soumission administrative — Édité le {{ now()->setTimezone(\App\Services\PeriodeInscriptionService::fuseau())->format('d/m/Y à H:i') }}</div>
    </div>

    <div class="avertissement-box">
        <div class="avertissement-titre">Avertissement important — Preuve de dépôt exclusive</div>
        <div class="avertissement-texte">
            Ce document atteste <strong>uniquement la transmission et l’enregistrement</strong> de votre dossier de candidature auprès du service de scolarité.
            <strong>Il ne constitue en aucun cas une décision d'admission définitive, une acceptation, ni une affectation à un groupe ou une filière.</strong>
            L'admission définitive demeure conditionnée à l'instruction de vos pièces et aux délibérations officielles consultables en temps réel dans votre espace étudiant.
        </div>
    </div>

    <div class="section-box">
        <div class="section-header">1. Référence et état du dépôt</div>
        <div class="section-body">
            <table class="grid">
                <tr>
                    <td class="label">Numéro d’inscription / Référence :</td>
                    <td class="valeur"><strong style="font-size: 12px; color: #1e3a8a;">{{ $etudiant->num_inscription }}</strong></td>
                </tr>
                <tr>
                    <td class="label">Date et heure de dépôt :</td>
                    <td class="valeur"><strong>{{ $dateDepot->format('d/m/Y à H:i') }}</strong> (fuseau {{ \App\Services\PeriodeInscriptionService::fuseau() }})</td>
                </tr>
                <tr>
                    <td class="label">Statut du dossier :</td>
                    <td class="valeur">
                        @php
                            $statutClass = match ($etudiant->statut) {
                                'valide' => 'statut-valide',
                                'rejete' => 'statut-rejete',
                                default => 'statut-en_attente',
                            };
                            $statutTexte = match ($etudiant->statut) {
                                'valide' => 'Validé administrativement',
                                'rejete' => 'Dossier non retenu',
                                default => 'En attente d’examen',
                            };
                        @endphp
                        <span class="badge-statut {{ $statutClass }}">{{ $statutTexte }}</span>
                    </td>
                </tr>
                <tr>
                    <td class="label">Empreinte de contrôle :</td>
                    <td class="valeur"><span class="code-securite">{{ $empreinte ?? 'DOC-SEC-'.strtoupper(substr(md5($etudiant->num_inscription), 0, 12)) }}</span></td>
                </tr>
            </table>
        </div>
    </div>

    <div class="section-box">
        <div class="section-header">2. Candidat et formation postulée</div>
        <div class="section-body">
            <table class="grid">
                <tr>
                    <td class="label">Nom et prénom du candidat :</td>
                    <td class="valeur"><strong>{{ $etudiant->nom }} {{ $etudiant->prenom }}</strong></td>
                </tr>
                <tr>
                    <td class="label">Adresse de contact :</td>
                    <td class="valeur">{{ $etudiant->email }}</td>
                </tr>
                <tr>
                    <td class="label">Faculté / Établissement :</td>
                    <td class="valeur">{{ $etudiant->faculte?->nom ?? 'Non spécifié' }}</td>
                </tr>
                <tr>
                    <td class="label">Programme / Filière :</td>
                    <td class="valeur">{{ $etudiant->programmeRef?->nom ?? ($etudiant->filiere ?? 'Filière générale') }}</td>
                </tr>
                <tr>
                    <td class="label">Niveau d’études :</td>
                    <td class="valeur">{{ $etudiant->niveauRef?->nom ?? ($etudiant->niveau ?? 'L1') }}</td>
                </tr>
                <tr>
                    <td class="label">Année universitaire :</td>
                    <td class="valeur">{{ $etudiant->annee_academique ?? (date('Y').'-'.(date('Y') + 1)) }}</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="section-box">
        <div class="section-header">3. Justificatifs et pièces enregistrées</div>
        <div class="section-body">
            @if(isset($documents) && count($documents) > 0)
                <table class="liste-docs">
                    <thead>
                        <tr>
                            <th style="width: 45%;">Intitulé du document</th>
                            <th style="width: 25%;">Catégorie / Type</th>
                            <th style="width: 15%;">Date de dépôt</th>
                            <th style="width: 15%;">État</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($documents as $doc)
                            <tr>
                                <td><strong>{{ $doc->title ?? $doc->original_filename ?? 'Pièce justificative' }}</strong></td>
                                <td>{{ ucfirst(str_replace('_', ' ', $doc->document_type ?? $doc->category ?? 'Général')) }}</td>
                                <td>{{ $doc->created_at ? $doc->created_at->format('d/m/Y') : $dateDepot->format('d/m/Y') }}</td>
                                <td>
                                    @php
                                        $docEtat = match ($doc->status ?? 'pending') {
                                            'validated' => 'Conforme',
                                            'rejected' => 'Non conforme',
                                            default => 'En attente',
                                        };
                                    @endphp
                                    {{ $docEtat }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="notice-vide">
                    Aucune pièce justificative complémentaire transmise à ce stade.
                    Le dossier est instruit sur la base des informations déclaratives enregistrées.
                </div>
            @endif
        </div>
    </div>

    <div class="footer">
        <p>
            Conservez précieusement ce récépissé ainsi que votre référence <strong>{{ $etudiant->num_inscription }}</strong>.<br>
            Ce document est strictement personnel et dématérialisé. Aucun duplicata manuscrit n'est délivré au guichet.<br>
            Pour suivre l’évolution de votre candidature, connectez-vous sur votre espace étudiant.
        </p>
    </div>
</body>
</html>

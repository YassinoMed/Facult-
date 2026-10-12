<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $titreDocument }}</title>
    <style>
        @page { size: A4 portrait; margin: 20mm 15mm 22mm 15mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #1e293b; line-height: 1.5; }
        .entete { text-align: center; border-bottom: 2.5pt solid #1e3a5f; padding-bottom: 8pt; margin-bottom: 12pt; }
        .entete .etablissement { font-size: 15pt; font-weight: bold; color: #1e3a5f; }
        .entete .titre-doc { font-size: 13pt; font-weight: bold; margin-top: 8pt; }
        .meta { font-size: 8.5pt; color: #475569; margin-top: 4pt; }
        .corps { margin-top: 10pt; text-align: justify; }
        .kv { margin: 3pt 0; }
        .kv .cle { display: inline-block; width: 36%; color: #475569; }
        .kv .valeur { font-weight: bold; }
        .signature { margin-top: 24pt; }
        .signature .case { display: inline-block; width: 45%; vertical-align: top; }
        .verification { font-size: 8pt; color: #1e3a5f; border: 0.5pt solid #cbd5e1; padding: 4pt 6pt; margin-top: 10pt; }
        .pied { position: fixed; bottom: -16mm; left: 0; right: 0; font-size: 7.5pt; color: #64748b; border-top: 0.5pt solid #cbd5e1; padding-top: 4pt; text-align: center; }
    </style>
</head>
<body>
    <div class="entete">
        <div class="etablissement">{{ $institution }}</div>
        <div class="faculte">{{ $faculte }}</div>
        <div class="titre-doc">{{ $titreDocument }}</div>
        <div class="meta">Année universitaire : {{ $annee }} &nbsp;·&nbsp; Délivrée le : {{ $dateGeneration }}</div>
    </div>

    <div class="corps">
        <p>La scolarité atteste l'assiduité de l'étudiant(e) désigné(e) ci-dessous, calculée sur les séances enregistrées (présences + retards) :</p>
        <p class="kv"><span class="cle">Nom</span> <span class="valeur">{{ $nom }}</span></p>
        <p class="kv"><span class="cle">Prénom</span> <span class="valeur">{{ $prenom }}</span></p>
        <p class="kv"><span class="cle">N° inscription</span> <span class="valeur">{{ $inscription }}</span></p>
        <p class="kv"><span class="cle">Programme</span> <span class="valeur">{{ $programme }}</span></p>
        <p class="kv"><span class="cle">Niveau</span> <span class="valeur">{{ $niveau }}</span></p>
        <p class="kv"><span class="cle">Séances enregistrées</span> <span class="valeur">{{ $presenceSeances ?? '—' }}</span></p>
        <p class="kv"><span class="cle">Taux d'assiduité</span> <span class="valeur">{{ $presenceTaux !== null ? number_format($presenceTaux, 1, ',', ' ').' %' : '—' }}</span></p>
        <p>En foi de quoi, la présente attestation est délivrée pour servir et valoir ce que de droit.</p>
    </div>

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

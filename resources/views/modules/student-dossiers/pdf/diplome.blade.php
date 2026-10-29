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
        .entete .titre-doc { font-size: 14pt; font-weight: bold; margin-top: 8pt; letter-spacing: 2pt; }
        .meta { font-size: 8.5pt; color: #475569; margin-top: 4pt; }
        .corps { margin-top: 12pt; text-align: justify; }
        .kv { margin: 3pt 0; }
        .kv .cle { display: inline-block; width: 36%; color: #475569; }
        .kv .valeur { font-weight: bold; }
        .cadre { border: 1.5pt double #1e3a5f; padding: 10pt 14pt; margin-top: 8pt; }
        .signature { margin-top: 26pt; }
        .signature .case { display: inline-block; width: 45%; vertical-align: top; }
        .verification { font-size: 8pt; color: #1e3a5f; border: 0.5pt solid #cbd5e1; padding: 4pt 6pt; margin-top: 10pt; }
        .pied { position: fixed; bottom: -16mm; left: 0; right: 0; font-size: 7.5pt; color: #64748b; border-top: 0.5pt solid #cbd5e1; padding-top: 4pt; text-align: center; }
    </style>
</head>
<body>
    <div class="entete">
        <div class="etablissement">{{ $institution }}</div>
        <div class="faculte">{{ $faculte }}</div>
        <div class="titre-doc">DIPLÔME</div>
        <div class="meta">{{ $titreDocument }} &nbsp;·&nbsp; Année universitaire : {{ $annee }} &nbsp;·&nbsp; Délivré le : {{ $dateGeneration }}</div>
    </div>

    <div class="corps">
        <p>Le jury d’examen de l’établissement déclare que l’étudiant(e) désigné(e) ci-dessous a satisfait aux
            exigences du programme et lui délivre le présent diplôme :</p>

        <div class="cadre">
            <p class="kv"><span class="cle">Nom</span> <span class="valeur">{{ $nom }}</span></p>
            <p class="kv"><span class="cle">Prénom</span> <span class="valeur">{{ $prenom }}</span></p>
            <p class="kv"><span class="cle">N° inscription</span> <span class="valeur">{{ $inscription }}</span></p>
            <p class="kv"><span class="cle">Date de naissance</span> <span class="valeur">{{ $naissance }}</span></p>
            <p class="kv"><span class="cle">Formation</span> <span class="valeur">{{ $programme }}</span></p>
            <p class="kv"><span class="cle">Niveau</span> <span class="valeur">{{ $niveau }}</span></p>
            <p class="kv"><span class="cle">Décision du jury</span> <span class="valeur">{{ $decision }}</span></p>
            <p class="kv"><span class="cle">Moyenne</span> <span class="valeur">{{ $decisionMoyenne ?? $moyenne ?? '—' }}</span></p>
            <p class="kv"><span class="cle">Crédits</span> <span class="valeur">{{ $credits[0] }}/{{ $credits[1] }}</span></p>
        </div>

        <p>En foi de quoi, le présent diplôme est délivré pour servir et valoir ce que de droit.</p>
    </div>

    <div class="signature">
        <div class="case">
            <p><strong>Fait le :</strong> {{ $dateGeneration }}</p>
        </div>
        <div class="case">
            <p><strong>Le Directeur / Signature / cachet :</strong></p>
            <p style="height: 50pt;">&nbsp;</p>
        </div>
    </div>

    <div class="verification">
        @if(! empty($qrCodeSvg))
            <div style="float: right; margin-left: 8pt; width: 50pt; height: 50pt;">
                {!! $qrCodeSvg !!}
            </div>
        @endif
        <p><strong>Vérification d'authenticité :</strong> {{ $urlVerification ?? '' }} — Code : {{ $codeVerification ?? '' }}</p>
        @if(! empty($qrCodeSvg))
            <div style="clear: both;"></div>
        @endif
    </div>

    <div class="pied">
        {{ $institution }} — {{ $titreDocument }} — {{ $dateGeneration }}
    </div>
</body>
</html>

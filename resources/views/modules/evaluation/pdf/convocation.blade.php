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
        .rappel { margin-top: 12pt; padding: 8pt; border: 0.5pt solid #cbd5e1; background: #f8fafc; font-size: 9pt; }
        .signature { margin-top: 24pt; }
        .signature .case { display: inline-block; width: 45%; vertical-align: top; }
        .pied { position: fixed; bottom: -16mm; left: 0; right: 0; font-size: 7.5pt; color: #64748b; border-top: 0.5pt solid #cbd5e1; padding-top: 4pt; text-align: center; }
    </style>
</head>
<body>
    <div class="entete">
        <div class="etablissement">{{ $institution }}</div>
        <div class="faculte">{{ $faculte }}</div>
        <div class="titre-doc">{{ $titreDocument }}</div>
        <div class="meta">Référence : {{ $reference }} &nbsp;·&nbsp; Délivrée le : {{ $dateGeneration }}</div>
    </div>

    <div class="corps">
        <p>L'étudiant(e) désigné(e) ci-dessous est convoqué(e) à l'épreuve suivante :</p>
        <p class="kv"><span class="cle">Nom</span> <span class="valeur">{{ $nom }}</span></p>
        <p class="kv"><span class="cle">Prénom</span> <span class="valeur">{{ $prenom }}</span></p>
        <p class="kv"><span class="cle">N° inscription</span> <span class="valeur">{{ $inscription }}</span></p>
        <p class="kv"><span class="cle">Groupe</span> <span class="valeur">{{ $groupe }}</span></p>
        <p class="kv"><span class="cle">Matière</span> <span class="valeur">{{ $matiere }}</span></p>
        <p class="kv"><span class="cle">Type</span> <span class="valeur">{{ $type }}</span></p>
        <p class="kv"><span class="cle">Session</span> <span class="valeur">{{ $session }}</span></p>
        <p class="kv"><span class="cle">Date</span> <span class="valeur">{{ $date }}</span></p>
        <p class="kv"><span class="cle">Horaire</span> <span class="valeur">{{ $horaire }}</span></p>
        <p class="kv"><span class="cle">Salle</span> <span class="valeur">{{ $salle }}</span></p>
        @if(!empty($numeroAnonyme) && $numeroAnonyme !== '—')
            <p class="kv"><span class="cle">N° Anonymat</span> <span class="valeur font-mono" style="font-family: monospace; color: #1e3a5f;">{{ $numeroAnonyme }}</span></p>
        @endif
        @if(!empty($place) && $place !== '—')
            <p class="kv"><span class="cle">Place</span> <span class="valeur">{{ $place }}</span></p>
        @endif
        <div class="rappel">Présentez-vous 30 minutes avant le début de l'épreuve, muni(e) de votre carte d'étudiant. Tout retard ou absence non justifiée est consigné au procès-verbal. Ce document comporte une signature cryptographique vérifiable hors-ligne.</div>
    </div>

    <div class="signature" style="margin-top: 18pt;">
        <div class="case" style="width: 55%; vertical-align: top;">
            <p><strong>Fait le :</strong> {{ $dateGeneration }}</p>
            <p style="margin-top: 8pt;"><strong>Signature / cachet du centre d'examen :</strong></p>
            <p style="height: 40pt;">&nbsp;</p>
        </div>
        <div class="case" style="width: 40%; text-align: right; vertical-align: top;">
            @if(!empty($qrCodeSvg))
                <div style="display: inline-block; padding: 4pt; border: 0.5pt solid #cbd5e1; background: #ffffff;">
                    {!! $qrCodeSvg !!}
                </div>
                <div style="font-size: 7pt; color: #64748b; margin-top: 2pt; font-family: monospace;">Secured by UniRegistre</div>
            @endif
        </div>
    </div>

    <div class="pied">
        {{ $institution }} — Réf. {{ $reference }} — {{ $dateGeneration }} — Document officiel d'examen infalsifiable
    </div>
</body>
</html>

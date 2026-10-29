<!DOCTYPE html>
<html lang="{{ $langue ?? 'ar' }}" dir="{{ ($langue ?? 'ar') === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ ($langue ?? 'ar') === 'ar' ? 'شهادة ترسيم' : ($titreDocument ?? 'Attestation d\'inscription') }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm 15mm 8mm 15mm;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10pt;
            color: #0f172a;
            line-height: 1.45;
            margin: 0;
            padding: 0;
        }
        .rtl { direction: rtl; text-align: right; }
        .ltr { direction: ltr; text-align: left; }

        /* En-tête officiel */
        .entete-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12pt;
        }
        .entete-table td {
            vertical-align: top;
            padding: 0;
            border: none;
        }
        .tutelle-bloc {
            font-size: 9pt;
            line-height: 1.35;
            color: #1e293b;
        }
        .tutelle-bloc .pays {
            font-size: 10.5pt;
            font-weight: bold;
            margin-bottom: 2pt;
        }
        .tutelle-bloc .faculte-nom {
            font-weight: bold;
            font-size: 9.5pt;
            margin-top: 2pt;
        }
        .code-modele {
            font-size: 8pt;
            color: #475569;
            font-family: monospace;
            text-align: center;
        }
        .sceau-etat {
            text-align: center;
            width: 70pt;
        }

        /* Titre du document */
        .titre-section {
            text-align: center;
            margin: 14pt 0 12pt 0;
        }
        .titre-principal {
            font-size: 19pt;
            font-weight: bold;
            color: #0f172a;
            letter-spacing: 0.5pt;
            margin-bottom: 4pt;
        }
        .annee-universitaire {
            font-size: 11pt;
            font-weight: bold;
            color: #334155;
        }

        /* Corps de l'attestation */
        .formule-attestation {
            font-size: 10.5pt;
            margin-bottom: 10pt;
            line-height: 1.5;
        }
        .etudiant-bloc {
            margin: 8pt 0 12pt 0;
            padding: 2pt 0;
        }
        .ligne-kv {
            margin: 3.5pt 0;
            font-size: 10pt;
            line-height: 1.5;
        }
        .ligne-kv .label {
            display: inline-block;
            min-width: 170pt;
            color: #334155;
        }
        .ligne-kv .valeur {
            font-weight: bold;
            color: #0f172a;
        }

        /* Clôture et Signatures */
        .cloture-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 14pt;
        }
        .cloture-table td {
            vertical-align: top;
            border: none;
            padding: 0;
        }
        .date-lieu {
            font-size: 9.5pt;
            margin-bottom: 4pt;
        }
        .titre-signataire {
            font-weight: bold;
            font-size: 10.5pt;
            margin-bottom: 2pt;
        }
        .nom-signataire {
            font-size: 9.5pt;
            color: #334155;
        }
        .cachet-box {
            margin-top: 4pt;
            height: 60pt;
        }

        /* Cachet officiel stylisé */
        .cachet-svg {
            opacity: 0.85;
        }

        /* Pied de page et mention légale */
        .mention-legale {
            margin-top: 14pt;
            border-top: 1pt solid #cbd5e1;
            padding-top: 4pt;
            font-size: 8.5pt;
            font-weight: bold;
            color: #0f172a;
        }

        /* Bloc sécurisation QR Code */
        .securite-bloc {
            margin-top: 10pt;
            border: 0.5pt solid #cbd5e1;
            background-color: #f8fafc;
            border-radius: 4pt;
            padding: 5pt 8pt;
            font-size: 7.5pt;
            color: #334155;
        }
        .securite-bloc table {
            width: 100%;
            border-collapse: collapse;
        }
        .securite-bloc td {
            vertical-align: middle;
            border: none;
            padding: 0;
        }
    </style>
</head>
<body class="{{ ($langue ?? 'ar') === 'ar' ? 'rtl' : 'ltr' }}">

    @if(($langue ?? 'ar') === 'ar')
        {{-- ======================================================== --}}
        {{-- MODÈLE ARABE OFFICIEL (شهادة ترسيم - CONFORME PHOTO 1)  --}}
        {{-- ======================================================== --}}
        <table class="entete-table">
            <tr>
                <td style="width: 60%;" class="tutelle-bloc rtl">
                    <div class="pays">الجمهورية التونسية</div>
                    <div>وزارة التعليم العالي والبحث العلمي</div>
                    <div>{{ $tutelleArabe ?? 'الإدارة العامة للدراسات التكنولوجية' }}</div>
                    <div class="faculte-nom">{{ $faculteArabe ?? $faculte }}</div>
                </td>
                <td style="width: 40%; text-align: left;" class="ltr">
                    <div class="sceau-etat">
                        <div class="code-modele">18-2.1.01-05</div>
                        <svg width="65" height="65" viewBox="0 0 100 100" class="cachet-svg">
                            <circle cx="50" cy="50" r="46" stroke="#475569" stroke-width="2" fill="none" />
                            <circle cx="50" cy="50" r="41" stroke="#475569" stroke-width="1" stroke-dasharray="2,2" fill="none" />
                            <text x="50" y="32" font-size="9" text-anchor="middle" fill="#334155" font-weight="bold">الجمهورية التونسية</text>
                            <path d="M 50 40 L 50 68 M 36 50 L 64 50 M 36 50 L 32 60 L 40 60 Z M 64 50 L 60 60 L 68 60 Z" stroke="#334155" stroke-width="1.8" fill="none" />
                            <circle cx="50" cy="74" r="2.5" fill="#334155" />
                            <text x="50" y="86" font-size="8" text-anchor="middle" fill="#64748b">★ ★ ★</text>
                        </svg>
                    </div>
                </td>
            </tr>
        </table>

        <div class="titre-section">
            <div class="titre-principal">شهادة ترسيم</div>
            <div class="annee-universitaire">من السنة الجامعية {{ $annee }}</div>
        </div>

        <div class="formule-attestation">
            يشهد الكاتب العام لـ <strong>{{ $faculteArabe ?? $faculte }}</strong>،<br>
            أن {{ $civiliteArabe ?? 'الطالب' }} :
        </div>

        <div class="etudiant-bloc rtl">
            <div class="ligne-kv">الإسم : {{ $prenomArabe ?? $prenom }}</div>
            <div class="ligne-kv">اللقّب : {{ $nomArabe ?? $nom }}</div>
            <div class="ligne-kv">المولود في : {{ $naissance }} بـ {{ $lieuNaissanceArabe ?? $lieuNaissance }}</div>
            <div class="ligne-kv">صاحب بطاقة التعريف الوطنية رقم : {{ $cin }}</div>
            <div class="ligne-kv">مرسم بالسنة {{ $niveauArabe ?? 'الثانية' }} من {{ $cycleArabe ?? 'الماجستير المهني' }}</div>
            <div class="ligne-kv">الشعبة : {{ $filiereArabe ?? $filiere }}</div>
            <div class="ligne-kv">الإختصاص : {{ $specialiteArabe ?? $specialite }}</div>
            <div class="ligne-kv">تحت رقم : {{ $inscription }}</div>
            <div class="ligne-kv">وذلك بالنسبة للسنة الجامعية : {{ $annee }} .</div>
        </div>

        <table class="cloture-table">
            <tr>
                <td style="width: 50%;">
                    {{-- Emplacement logo établissement --}}
                    <div style="font-size: 8pt; color: #64748b; margin-top: 15pt;">
                        <strong>{{ $faculteArabe ?? $faculte }}</strong><br>
                        منظومة تسجيل الطلاب الجامعية
                    </div>
                </td>
                <td style="width: 50%;" class="rtl">
                    <div class="date-lieu">{{ $villeArabe ?? $ville }} في : {{ $dateGeneration }}</div>
                    <div class="titre-signataire">الكاتب العام</div>
                    <div class="nom-signataire">{{ $secretaireGeneralArabe ?? 'فتحي عبد الله' }}</div>
                    <div class="cachet-box">
                        <svg width="110" height="70" viewBox="0 0 160 100" class="cachet-svg">
                            <circle cx="80" cy="50" r="44" stroke="#1e3a8a" stroke-width="2.5" fill="none" opacity="0.85" />
                            <circle cx="80" cy="50" r="39" stroke="#1e3a8a" stroke-width="1" stroke-dasharray="2,2" fill="none" opacity="0.85" />
                            <text x="80" y="32" font-size="8.5" text-anchor="middle" fill="#1e3a8a" font-weight="bold">{{ mb_substr($faculteArabe ?? $faculte, 0, 30) }}</text>
                            <text x="80" y="52" font-size="11" text-anchor="middle" fill="#1e3a8a" font-weight="bold">الكاتب العام</text>
                            <text x="80" y="70" font-size="8.5" text-anchor="middle" fill="#1e3a8a">{{ $villeArabe ?? $ville }}</text>
                            <path d="M 50 65 Q 85 40 115 62 Q 95 30 110 50" stroke="#1e3a8a" stroke-width="2" fill="none" opacity="0.9" />
                        </svg>
                    </div>
                </td>
            </tr>
        </table>

        <div class="mention-legale rtl">
            هام : لا تسلم هذه الشهادة إلا مرة واحدة
        </div>

    @else
        {{-- ======================================================== --}}
        {{-- MODÈLE FRANÇAIS OFFICIEL                                 --}}
        {{-- ======================================================== --}}
        <table class="entete-table">
            <tr>
                <td style="width: 70%;" class="tutelle-bloc ltr">
                    <div class="pays">République Tunisienne</div>
                    <div>Ministère de l'Enseignement Supérieur et de la Recherche Scientifique</div>
                    <div>{{ $tutelle ?? 'Direction Générale des Études Technologiques' }}</div>
                    <div class="faculte-nom">{{ $faculte }}</div>
                </td>
                <td style="width: 30%; text-align: right;" class="ltr">
                    <div class="code-modele">18-2.1.01-05</div>
                    <svg width="60" height="60" viewBox="0 0 100 100" class="cachet-svg">
                        <circle cx="50" cy="50" r="46" stroke="#475569" stroke-width="2" fill="none" />
                        <circle cx="50" cy="50" r="41" stroke="#475569" stroke-width="1" stroke-dasharray="2,2" fill="none" />
                        <text x="50" y="34" font-size="9" text-anchor="middle" fill="#334155" font-weight="bold">RÉPUBLIQUE</text>
                        <text x="50" y="44" font-size="8" text-anchor="middle" fill="#334155" font-weight="bold">TUNISIENNE</text>
                        <path d="M 50 48 L 50 72 M 36 58 L 64 58 M 36 58 L 32 68 L 40 68 Z M 64 58 L 60 68 L 68 68 Z" stroke="#334155" stroke-width="1.8" fill="none" />
                    </svg>
                </td>
            </tr>
        </table>

        <div class="titre-section">
            <div class="titre-principal">ATTESTATION D'INSCRIPTION</div>
            <div class="annee-universitaire">Année universitaire {{ $annee }}</div>
        </div>

        <div class="formule-attestation">
            Le secrétaire général de l'« <strong>{{ $faculte }}</strong> », soussigné, atteste que l'étudiant(e) :
        </div>

        <div class="etudiant-bloc">
            <div class="ligne-kv"><span class="label">Nom :</span> <span class="valeur">{{ $civilite ?? 'Mr' }} {{ $nom }}</span></div>
            <div class="ligne-kv"><span class="label">Prénom :</span> <span class="valeur">{{ $prenom }}</span></div>
            <div class="ligne-kv"><span class="label">Né(e) Le :</span> <span class="valeur">{{ $naissance }} à {{ $lieuNaissance }}</span></div>
            <div class="ligne-kv"><span class="label">Titulaire de la Carte d'Identité Nationale N° :</span> <span class="valeur">{{ $cin }}</span></div>
            <div class="ligne-kv"><span class="label">Est inscrit(e) en :</span> <span class="valeur">{{ $niveauLibelle ?? $niveau }}</span></div>
            <div class="ligne-kv"><span class="label">Cycle / Diplôme :</span> <span class="valeur">{{ $cycleDiplome ?? 'Mastère Professionnel' }}</span></div>
            <div class="ligne-kv"><span class="label">Filière :</span> <span class="valeur">{{ $filiere }}</span></div>
            <div class="ligne-kv"><span class="label">Spécialité :</span> <span class="valeur">{{ $specialite }}</span></div>
            <div class="ligne-kv"><span class="label">Sous le Numéro :</span> <span class="valeur">{{ $inscription }}</span></div>
        </div>

        <p style="margin-top: 14pt; text-align: justify;">
            Pour l'année universitaire en cours, l'intéressé(e) est régulièrement inscrit(e) au sein de l'établissement.
            Cette attestation est délivrée pour servir et valoir ce que de droit.
        </p>

        <table class="cloture-table">
            <tr>
                <td style="width: 50%;">
                    <div style="font-size: 8.5pt; color: #64748b; margin-top: 20pt;">
                        <strong>{{ $faculte }}</strong>
                    </div>
                </td>
                <td style="width: 50%; text-align: left;">
                    <div class="date-lieu">Fait à {{ $ville }}, Le : {{ $dateGeneration }}</div>
                    <div class="titre-signataire">Le secrétaire général</div>
                    <div class="nom-signataire">{{ $secretaireGeneral ?? 'Ben Abdallah Fethi' }}</div>
                    <div class="cachet-box">
                        <svg width="110" height="70" viewBox="0 0 160 100" class="cachet-svg">
                            <circle cx="80" cy="50" r="44" stroke="#1e3a8a" stroke-width="2.5" fill="none" opacity="0.85" />
                            <circle cx="80" cy="50" r="39" stroke="#1e3a8a" stroke-width="1" stroke-dasharray="2,2" fill="none" opacity="0.85" />
                            <text x="80" y="32" font-size="8.5" text-anchor="middle" fill="#1e3a8a" font-weight="bold">{{ mb_substr($faculte, 0, 26) }}</text>
                            <text x="80" y="52" font-size="9" text-anchor="middle" fill="#1e3a8a" font-weight="bold">LE SECRÉTAIRE GÉNÉRAL</text>
                            <text x="80" y="70" font-size="8.5" text-anchor="middle" fill="#1e3a8a">{{ $ville }}</text>
                            <path d="M 50 65 Q 85 40 115 62 Q 95 30 110 50" stroke="#1e3a8a" stroke-width="2" fill="none" opacity="0.9" />
                        </svg>
                    </div>
                </td>
            </tr>
        </table>

        <div class="mention-legale ltr">
            N.B : la présente attestation n'est délivrée qu'une seule fois.
        </div>
    @endif

    {{-- Bloc de vérification numérique avec QR Code ISO/IEC 18004 --}}
    <div class="securite-bloc">
        <table>
            <tr>
                <td style="width: 82%;">
                    <strong>Vérification d'authenticité / التحقق من صحة الوثيقة :</strong><br>
                    <span>URL : {{ $urlVerification ?? url('/verify/' . ($codeVerification ?? '')) }}</span><br>
                    <span>Code : <code style="font-size: 8pt; font-weight: bold;">{{ $codeVerification ?? '—' }}</code></span>
                </td>
                <td style="width: 18%; text-align: center;">
                    @if(! empty($qrCodeSvg))
                        <div style="display: inline-block; width: 44pt; height: 44pt;">
                            {!! $qrCodeSvg !!}
                        </div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

</body>
</html>

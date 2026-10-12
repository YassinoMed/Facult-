<?php

namespace App\Services\Identity;

/**
 * Parseur CIN tunisienne (§4-12) : structure le texte OCR en champs.
 *
 * Deux modes d'entrée :
 * - texte PLEINE IMAGE (labels ar/fr sur la même ligne, valeur sur la
 *   ligne suivante, libellé combiné « الاسم و اللقب ») ;
 * - texte de ZONE (§5) : la zone est dédiée à UN champ — toute ligne
 *   qui ressemble à un libellé est écartée, le reste est la valeur.
 *
 * Découpage des traitements (§16) :
 * - NUMÉRO CIN (§8) : normalisation stricte (chiffres arabo-indiques
 *   → latins, espaces/parasites supprimés, validation 8 chiffres) —
 *   JAMAIS translittéré/traduit ;
 * - DATE (§12) : TunisianDateParser (« 06 فيفري 1999 » → 1999-02-06) ;
 * - LIEU (§13) : TunisianPlaceNormalizer (mapping contrôlé + fuzzy) ;
 * - NOMS PROPRES (§7-10) : TRANSLITTÉRÉS (jamais traduits), originaux
 *   arabes conservés (raw_ar) ;
 * - FILIATION (§11) : ligne « بن X » = champ DÉDIÉ, jamais fusionné
 *   avec le prénom ni le nom.
 *
 * L'OCR n'est jamais fiable : valeurs ambiguës → confiance dégradée
 * et vérification humaine demandée, aucune invention.
 */
final class CinParser
{
    /** CIN tunisienne : EXACTEMENT 8 chiffres (§8). */
    public const FORMAT_CIN = '/^[0-9]{8}$/';

    public function __construct(
        private readonly IdentityDataNormalizer $normalizer,
        private readonly ArabicTransliterationService $transliteration,
        private readonly TunisianDateParser $dates,
        private readonly TunisianPlaceNormalizer $lieux,
    ) {}

    /* ────────────────────────────────────────────────────────────
     * §8 — Numéro CIN : méthodes dédiées (jamais traduit)
     * ──────────────────────────────────────────────────────────── */

    /**
     * Normalise un numéro CIN brut OCR (« 11 126 679 », « ١١١٢٦٦٧٩ »,
     * « 11-126-679 ») → « 11126679 », ou null si rien d'exploitable.
     */
    public static function normaliserNumero(?string $valeur): ?string
    {
        if ($valeur === null || trim($valeur) === '') {
            return null;
        }

        // §21 — correction OCR spécifique aux chiffres : on ne « nettoie »
        // PAS la lettre ambiguë (B, e, s, z…) — elle DOIT rester pour
        // que la validation (8 chiffres) signale l'ambiguïté (§8/§20).
        // Seules les confusions OCR *certaines* (O→0, l/I/|→1) et les
        // séparateurs sont neutralisés.
        $valeur = ArabicNormalizer::versChiffresLatins($valeur);
        $valeur = str_replace(
            ['O', 'o', 'Ö', 'I', 'i', 'l', 'L', '|', '！', '!'],
            ['0', '0', '0', '1', '1', '1', '1', '1', '!', '1'],
            $valeur,
        );

        // Retire les séparateurs/espaces/points — jamais les lettres.
        $nettoye = preg_replace('/[\s\-\._,:;\\/]+/u', '', $valeur) ?? '';

        return $nettoye !== '' ? $nettoye : null;
    }

    /**
     * Le numéro est-il une CIN tunisienne valide (8 chiffres) ?
     */
    public static function validerNumero(?string $cin): bool
    {
        return $cin !== null && (bool) preg_match(self::FORMAT_CIN, $cin);
    }

    /**
     * API §8 : parse le numéro → CIN normalisée SI ET SEULEMENT SI
     * elle est valide (8 chiffres), sinon null.
     */
    public static function parseNumero(string $value): ?string
    {
        $normalise = self::normaliserNumero($value);

        return self::validerNumero($normalise) ? $normalise : null;
    }

    /**
     * §8 — alias explicites de l'API numéro demandée par le cahier des
     * charges : normalize() / validate().
     */
    public static function normalize(?string $value): ?string
    {
        return self::normaliserNumero($value);
    }

    public static function validate(?string $cin): bool
    {
        return self::validerNumero($cin);
    }

    /* ────────────────────────────────────────────────────────────
     * Extraction des champs depuis un texte OCR
     * ──────────────────────────────────────────────────────────── */

    /**
     * @return array{
     *     data: array<string, ?string>,
     *     confidence: array<string, float>,
     *     arabic: array<string, string>,
     *     transliterated: array<string, string>
     * }
     */
    public function parse(string $texteBrut): array
    {
        // §8/§6 : chiffres arabo-indiques → latins AVANT toute extraction
        $texteBrut = ArabicNormalizer::versChiffresLatins($texteBrut);

        $lignes = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\n|\r/', $texteBrut) ?: []),
            fn (string $l) => $l !== '',
        ));

        $brut = $this->extraireChamps($lignes);

        // ── Champs structurés (§13) : normalisation ──
        $donnees = [];

        $numero = self::normaliserNumero($brut['document_number']);
        if ($numero !== null) {
            $donnees['document_number'] = $numero;
        }

        $date = $this->dates->parse($brut['date_of_birth'] ?? null);
        if ($date !== null && $this->dates->valider($date)) {
            $donnees['date_of_birth'] = $date;
        }

        if (($brut['sex'] ?? null) !== null) {
            $donnees['sex'] = $brut['sex'];
        }

        // ── Noms propres (§7-11) : translittération + original conservé ──
        $arabic = [];
        $translittères = [];

        foreach (['last_name', 'first_name', 'filiation', 'place_of_birth', 'nationality'] as $champNom) {
            $valeurBrute = $brut[$champNom] ?? null;

            if ($valeurBrute === null || $valeurBrute === '') {
                continue;
            }

            if (ArabicNormalizer::contientArabe($valeurBrute)) {
                // §25 : l'original arabe ne conserve QUE les caractères
                // arabes (aucune charge latine ne peut y survivre) — la
                // valeur arabe elle-même est conservée fidèlement (§7).
                $brutArabe = trim((string) preg_replace('/[^\p{Arabic}\s]/u', '', $valeurBrute));

                if ($brutArabe === '') {
                    continue;
                }

                $arabic[$champNom] = $brutArabe;

                $latin = $champNom === 'place_of_birth'
                    ? $this->lieux->resoudre($brutArabe)
                    : $this->transliteration->transliterate($brutArabe);

                if ($latin !== null && $latin !== '') {
                    $translittères[$champNom] = $latin;
                    $donnees[$champNom] = $this->normalizer->normaliserCin([$champNom => $latin])[$champNom] ?? null;
                }
            } else {
                $donnees[$champNom] = $this->normalizer->normaliserCin([$champNom => $valeurBrute])[$champNom] ?? null;
            }
        }

        return [
            'data' => array_filter($donnees, fn ($v) => $v !== null && $v !== ''),
            'confidence' => $this->confiances($donnees, $brut),
            'arabic' => $arabic,
            'transliterated' => $translittères,
        ];
    }

    /**
     * Extraction par libellés avec trois dispositions acceptées :
     *   « LABEL valeur » / « LABEL : valeur »  (même ligne)
     *   « LABEL » puis valeur sur la ligne suivante (§3)
     *   « الاسم واللقب : بن علي محمد » (libellé combiné)
     * + filiation dédiée : toute ligne « بن X » qui n'est PAS la
     *   continuation d'un nom déjà extrait (§11 — jamais fusionnée).
     *
     * @param  array<int, string>  $lignes
     * @return array<string, ?string>
     */
    private function extraireChamps(array $lignes): array
    {
        $champs = [
            'document_number' => null,
            'last_name' => null,
            'first_name' => null,
            'full_name' => null,
            'filiation' => null,
            'date_of_birth' => null,
            'place_of_birth' => null,
            'nationality' => null,
            'sex' => null,
        ];

        $total = count($lignes);

        for ($i = 0; $i < $total; $i++) {
            $reste = self::retirerSeparateurs($lignes[$i]);

            // 1) Libellé arabe en début de ligne
            [$champ, $valeurMemeLigne] = $this->libelleArabeEtValeur($reste);

            if ($champ !== null) {
                if ($valeurMemeLigne !== '') {
                    $champs = $this->assignerValeur($champs, $champ, $valeurMemeLigne);
                } else {
                    // §5 OCR par zones : la valeur peut être plusieurs lignes
                    // APRÈS le libellé (lignes parasites intercalées
                    // « ٠ », « ب », « ث »…). On saute JUSQU'À 3 lignes
                    // non-arabes/non-latin/non-chiffres pour la trouver.
                    $valeurSuivante = $this->ligneDeValeurSuivante($lignes, $i);

                    if ($valeurSuivante !== null) {
                        $champs = $this->assignerValeur($champs, $champ, $valeurSuivante);
                    }
                }

                continue;
            }

            // 2) Libellé français (repli)
            $champFr = $this->libelleFrancais($reste, $lignes, $i);

            if ($champFr !== null) {
                [$champ, $valeur] = $champFr;
                $champs = $this->assignerValeur($champs, $champ, $valeur);
            }
        }

        // Libellé combiné « الاسم واللقب : بن علي محمد » :
        // dernier mot = prénom, le reste = nom
        if ($champs['last_name'] === null && $champs['full_name'] !== null) {
            $parties = preg_split('/\s+/u', trim($champs['full_name'])) ?: [];

            if (count($parties) >= 2) {
                $champs['first_name'] = implode(' ', array_slice($parties, -1));
                $champs['last_name'] = implode(' ', array_slice($parties, 0, -1));
            } else {
                $champs['first_name'] = $champs['full_name'];
            }
        }

        // §11 — FILIATION dédiée : ligne « بن X » non capturée par un
        // libellé → champ filiation, JAMAIS fusionnée dans nom/prénom.
        if ($champs['filiation'] === null) {
            foreach ($lignes as $ligne) {
                $nettoyee = self::retirerSeparateurs($ligne);

                if (preg_match('/^بن\s+\p{Arabic}+/u', $nettoyee)) {
                    $champs['filiation'] = $nettoyee;

                    break;
                }
            }
        }

        // Replis robustes (OCR sans libellé lisible, §5/§17)
        if ($champs['document_number'] === null) {
            $champs['document_number'] = $this->scanNumero($lignes);
        }

        if ($champs['date_of_birth'] === null) {
            $champs['date_of_birth'] = $this->scanDate($lignes);
        }

        if ($champs['sex'] === null) {
            $champs['sex'] = $this->scanSexe($lignes);
        }

        return $champs;
    }

    /**
     * Assigne la valeur au champ en gérant les champs dérivés
     * (sexe arabe, date + lieu composés « المولود في … ب… »).
     *
     * @param  array<string, ?string>  $champs
     * @return array<string, ?string>
     */
    private function assignerValeur(array $champs, string $champ, string $valeur): array
    {
        $valeur = trim($valeur);

        if ($champ === 'date_of_birth') {
            $champs['date_of_birth'] = $valeur;

            // Composé possible : « المولود في 15/02/2002 بصفاقس »
            if (preg_match('/(?:\s|^)ب\s*(\p{Arabic}+)$/u', $valeur, $m)
                && ($champs['place_of_birth'] ?? null) === null) {
                $champs['place_of_birth'] = $m[1];
            }

            return $champs;
        }

        if ($champ === 'sex') {
            $sexeArabe = ArabicLabelDictionary::sexePourValeur($valeur);

            if ($sexeArabe !== null) {
                $champs['sex'] = $sexeArabe;
            } elseif (preg_match('/^(m(?:asculin)?|f(?:éminin|eminin)?)$/iu', $valeur, $m)) {
                $champs['sex'] = strtoupper(mb_substr($m[1], 0, 1));
            }

            return $champs;
        }

        $champs[$champ] = $valeur;

        return $champs;
    }

    /**
     * Retire les séparateurs de libellé aux deux extrémités — en regex
     * Unicode (trim() byte-wise corromprait ـ et ：).
     */
    private static function retirerSeparateurs(string $texte): string
    {
        return trim((string) preg_replace('/^[\s:：;\-—ـ*·.,]+/u', '', $texte));
    }

    private function estLigneLibelle(string $ligne): bool
    {
        return ArabicLabelDictionary::champPourLibelle(self::retirerSeparateurs($ligne)) !== null;
    }

    /**
     * §5 : la ligne de valeur placée après un libellé peut contenir
     * des parasites OCR (ex. « ٠ », « ب », « ث »). On regarde jusqu'à
     * 3 lignes plus loin et on choisit la première ligne CONTENANT
     * des données significatives (arabe, chiffres ou date).
     *
     * @param  array<int, string>  $lignes
     */
    private function ligneDeValeurSuivante(array $lignes, int $index): ?string
    {
        for ($saut = 1; $saut <= 3; $saut++) {
            if (! isset($lignes[$index + $saut])) {
                break;
            }

            $candidat = trim($lignes[$index + $saut]);

            if ($candidat === '' || $this->estLigneLibelle($candidat)) {
                continue;
            }

            // Garde-fous : la ligne candidate doit contenir de VRAIES
            // données (arabe, chiffres ou lettres latines) — jamais
            // une ligne de parasite seule (٠, ب, ث, -…).
            if (preg_match('/[\p{Arabic}A-Za-z0-9]{2,}/u', $candidat)) {
                return $candidat;
            }
        }

        return null;
    }

    /**
     * Cherche un libellé arabe en début de ligne (1 à 4 mots).
     *
     * @return array{0: ?string, 1: string}
     */
    private function libelleArabeEtValeur(string $ligne): array
    {
        $mots = preg_split('/\s+/u', $ligne) ?: [];

        for ($longueur = min(count($mots), 4); $longueur >= 1; $longueur--) {
            $candidat = implode(' ', array_slice($mots, 0, $longueur));
            $champ = ArabicLabelDictionary::champPourLibelle($candidat);

            if ($champ !== null) {
                $valeur = self::retirerSeparateurs(implode(' ', array_slice($mots, $longueur)));

                return [$champ, $valeur];
            }
        }

        return [null, ''];
    }

    /**
     * Libellé français : « LABEL : valeur » ou « LABEL » seul suivi de
     * la valeur à la ligne suivante.
     *
     * @param  array<int, string>  $lignes
     * @return array{0: string, 1: string}|null
     */
    private function libelleFrancais(string $reste, array $lignes, int $index): ?array
    {
        $libelles = [
            'document_number' => ['cin', 'n cin', 'carte identite', 'n° cin'],
            'last_name' => ['nom', 'name', 'surname'],
            'first_name' => ['prenom', 'prénom', 'given name', 'forenames'],
            'date_of_birth' => ['ne(e) le', 'né(e) le', 'date de naissance', 'naissance'],
            'place_of_birth' => ['lieu de naissance', 'place of birth', 'lieu'],
            'nationality' => ['nationalite', 'nationalité', 'nationality'],
            'sex' => ['sexe', 'sex'],
            'full_name' => ['nom & prenom', 'nom et prenom'],
        ];

        foreach ($libelles as $champ => $variantes) {
            foreach ($variantes as $libelle) {
                if (preg_match('/^'.preg_quote($libelle, '/').'\s*[:\-—]?\s*(.*)$/iu', $reste, $m)) {
                    $valeur = trim($m[1]);

                    if ($valeur !== '') {
                        return [$champ, $valeur];
                    }

                    if (isset($lignes[$index + 1])) {
                        $suivante = $lignes[$index + 1];

                        if (! preg_match('/^'.preg_quote($libelle, '/').'\s*[:\-—]?/iu', $suivante)) {
                            return [$champ, $suivante];
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Repli : scan d'un numéro candidat sans libellé.
     *
     * @param  array<int, string>  $lignes
     */
    private function scanNumero(array $lignes): ?string
    {
        foreach ($lignes as $ligne) {
            if (preg_match('/(?:^|\s)([0-9OoIl][0-9OoIlA-Za-z\s\-\.]{6,15})(?:\s|$)/u', $ligne, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Repli : scan d'une date (numérique OU en toutes lettres §12)
     * dans toutes les lignes.
     *
     * @param  array<int, string>  $lignes
     */
    private function scanDate(array $lignes): ?string
    {
        foreach ($lignes as $ligne) {
            $date = $this->dates->parse($ligne);

            if ($date !== null && $this->dates->valider($date)) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Repli : scan du sexe (latin ou arabe) sans libellé.
     *
     * @param  array<int, string>  $lignes
     */
    private function scanSexe(array $lignes): ?string
    {
        foreach ($lignes as $ligne) {
            if (($sexe = ArabicLabelDictionary::sexePourValeur($ligne)) !== null) {
                return $sexe;
            }

            if (preg_match('/\b(sexe|sex)\s*[:\-—]?\s*(m(?:asculin)?|f(?:éminin|eminin)?)\b/iu', $ligne, $m)) {
                return strtoupper(mb_substr($m[2], 0, 1));
            }
        }

        return null;
    }

    /**
     * Score de confiance par champ (§20).
     *
     * @param  array<string, ?string>  $donnees
     * @param  array<string, ?string>  $brut
     * @return array<string, float>
     */
    private function confiances(array $donnees, array $brut): array
    {
        $confiance = [];

        foreach ($donnees as $champ => $valeur) {
            if ($valeur === null || $valeur === '') {
                continue;
            }

            $confiance[$champ] = match ($champ) {
                // §8 : 8 chiffres valides = très fiable (0.95) ;
                // ambiguïté (≠ 8 chiffres après nettoyage) = vérification
                'document_number' => self::validerNumero($valeur) ? 0.95 : 0.45,
                // §12 : date réelle validée = très fiable
                'date_of_birth' => 0.98,
                'sex' => 0.85,
                // §13 : lieu issu du mapping contrôlé = fiable, sinon
                // translittération pure = averti
                'place_of_birth' => $this->lieux->estOfficiel($brut['place_of_birth'] ?? '') ? 0.95 : 0.60,
                // §11 : filiation dédiée, informatif
                'filiation' => 0.90,
                default => 0.75, // noms : extraction OCR textuelle
            };
        }

        return $confiance;
    }
}

<?php

namespace App\Services\Identity;

use App\Support\JournalisationPrivee;
use Illuminate\Support\Facades\Log;

/**
 * Pipeline SPÉCIALISÉ CIN tunisienne (§2/§4/§5/§21).
 *
 * IMAGE → prétraitement (variantes) → OCR arabe (zones + pleine image)
 * → détection du document → fusion par VOTE MAJORITAIRE → parseur →
 * normalisation → translittération → OcrResult riche.
 *
 * Stratégie validée empiriquement sur la fixture du projet :
 * - le numéro se lit mieux en zone dédiée (psm 7) ;
 * - les champs arabes se lisent mieux en zone + variante contrastée
 *   (psm 6/11) — la confusion ب/ي varie selon le filtre : le VOTE sur
 *   toutes les variantes donne la bonne valeur (§4 « ne pas appliquer
 *   un filtre unique ») ;
 * - la pleine image sert à la DÉTECTION (الجمهورية التونسية) et de
 *   repli général (§2 « robuste si certains éléments manquent »).
 *
 * Traçabilité (§29) : debug logs LOCAL uniquement, jamais en prod —
 * et jamais de CIN complète ni d'image dans quelque log que ce soit.
 */
final class CinOcrService
{
    public function __construct(
        private readonly OcrEngineInterface $engine,
        private readonly ImagePreprocessor $preprocesseur,
        private readonly CinParser $parser,
    ) {}

    /**
     * Pipeline complet sur le FICHIER temporaire privé (chemin absolu,
     * nom UUID généré serveur — jamais un nom client).
     */
    public function extraire(string $cheminAbsolu, string $langueHint): OcrResult
    {
        $textes = $this->ocrMultiSources($cheminAbsolu, $langueHint);

        // §2 — détection du document : indices multiples, robuste
        $detecte = $this->detecterCinTunisienne($textes);

        // §4/§5 — fusion par vote majoritaire sur tous les textes
        $texteFusionne = $this->fusionnerParVote($textes);

        $this->journaliser($textes, $texteFusionne, $detecte);

        $resultat = $this->parser->parse($texteFusionne);

        return $this->assemblerOcrResult($resultat, $detecte);
    }

    /**
     * Chemin « texte seul » (moteur déjà exécuté, tests unitaires) :
     * parse sans zones — le parseur reste pleinement fonctionnel.
     */
    public function depuisTexte(string $texteBrut): OcrResult
    {
        $resultat = $this->parser->parse($texteBrut);

        return $this->assemblerOcrResult($resultat, $this->detecterCinTunisienne([$texteBrut]));
    }

    /* ── OCR multi-sources (§4 + §5) ─────────────────────────── */

    /**
     * Collecte TOUS les textes OCR : pleine image (variantes) + zones
     * (chacune sur sa meilleure variante). Fail-soft total.
     *
     * @return array<int, string>
     */
    private function ocrMultiSources(string $cheminAbsolu, string $langueHint): array
    {
        $textes = [];
        $variantes = $this->preprocesseur->variantes($cheminAbsolu);
        $languePleineImage = $this->resoudreLangue($langueHint);

        try {
            // 1) Pleine image sur chaque variante (detection + repli)
            foreach ($variantes as $cheminVariante) {
                $texte = $this->engine->rawText($cheminVariante, $languePleineImage);

                if (trim($texte) !== '') {
                    $textes[] = $texte;
                }
            }

            // 2) Zones dédiées (§5) — coordonnées normalisées de la config
            foreach ((array) config('cin_tunisia.regions', []) as $nomZone => $zone) {
                foreach ($variantes as $cleVariante => $cheminVariante) {
                    $cheminZone = $this->preprocesseur->decouper($cheminVariante, $zone['rect']);

                    if ($cheminZone === null) {
                        continue;
                    }

                    try {
                        $texteZone = $this->engine->rawText(
                            $cheminZone,
                            (string) ($zone['langue'] ?? $languePleineImage),
                            (int) ($zone['psm'] ?? 6),
                        );

                        if (trim($texteZone) !== '') {
                            $textes[] = $texteZone;
                        }
                    } finally {
                        $this->preprocesseur->purger($cheminZone);
                    }
                }
            }
        } finally {
            // Les variantes sont TOUJOURS purgées (le document original
            // reste géré par IdentityDocumentService — §13 option A).
            foreach ($variantes as $cheminVariante) {
                $this->preprocesseur->purger($cheminVariante);
            }
        }

        return $textes;
    }

    /**
     * Résout un indice de langue logique → code moteur via
     * config/ocr.php (§3 — jamais codé en dur dans les services).
     */
    private function resoudreLangue(string $hint): string
    {
        $correspondance = (array) config('ocr.hint_map', []);

        $resolu = $correspondance[$hint] ?? null;

        // 'ara+lat' → langue CIN centralisée
        if ($resolu === null) {
            return (string) config('ocr.cin_language', 'ara+eng');
        }

        return (string) $resolu;
    }

    /* ── Détection du document (§2) ──────────────────────────── */

    /**
     * Détecte une CIN tunisienne par indices MULTIPLES :
     * mots-clés arabes/latins de l'en-tête (الجمهورية التونسية,
     * بطاقة التعريف الوطنية, RÉPUBLIQUE TUNISIENNE…), aspect ratio,
     * libellés caractéristiques. Robuste : UN indice suffit ;
     * aucun → repli sur le type déclaré par l'utilisateur.
     */
    public function detecterCinTunisienne(array $textes): bool
    {
        $corpus = mb_strtolower(implode("\n", $textes));
        $corpusNormalise = ArabicNormalizer::normaliserPourComparaison($corpus);

        $indices = 0;

        foreach ((array) config('cin_tunisia.detection.mots_cles_arabe', []) as $motCle) {
            if (mb_strpos($corpusNormalise, ArabicNormalizer::normaliserPourComparaison($motCle)) !== false) {
                $indices++;
            }
        }

        foreach ((array) config('cin_tunisia.detection.mots_cles_latin', []) as $motCle) {
            if (mb_strpos($corpus, mb_strtolower($motCle)) !== false) {
                $indices++;
            }
        }

        return $indices >= (int) config('cin_tunisia.detection.indice_minimal', 1);
    }

    /* ── Fusion par vote (§4 « comparer les résultats ») ─────── */

    /**
     * Fusionne les textes multi-sources en UN corpus : chaque texte
     * est parsé et les valeurs de champ NORMALISÉES votent — la
     * valeur majoritaire gagne (empiriquement : بوناب lu بوناب dans
     * 4 variantes sur 6 bat « يوناب » lu 2 fois).
     */
    private function fusionnerParVote(array $textes): string
    {
        if ($textes === []) {
            return '';
        }

        if (count($textes) === 1) {
            return $textes[0];
        }

        // Corpus complet (toutes lignes de toutes les sources) : sert
        // au repli « scan » du parseur (numéro/date/sexe).
        $corpus = implode("\n", $textes);

        // Vote par champ : chaque source propose ses valeurs extraites
        $candidats = [];

        foreach ($textes as $texte) {
            $champs = $this->parser->parse($texte);

            foreach ($champs['data'] as $champ => $valeur) {
                $cle = $champ.'='.$valeur;
                $candidats[$champ][$cle] = ($candidats[$champ][$cle] ?? 0) + 1;
            }
        }

        if ($candidats === []) {
            return $corpus;
        }

        // Reconstitue un corpus « gagnant » : chaque champ votant est
        // réintroduit dans le corpus sous forme « LABEL\nvaleur » pour
        // le parseur final (reconnaissance par libellé garantie).
        $lignesGagnantes = [];

        $libellesGagnants = [
            'document_number' => 'CIN',
            'last_name' => 'Nom',
            'first_name' => 'Prénom',
            'filiation' => 'Filiation',
            'date_of_birth' => 'Ne(e) le',
            'place_of_birth' => 'Lieu de naissance',
            'nationality' => 'Nationalité',
            'sex' => 'Sexe',
        ];

        foreach ($candidats as $champ => $votes) {
            arsort($votes);
            $gagnant = array_key_first($votes);
            [, $valeur] = explode('=', $gagnant, 2);

            if ($valeur !== '' && $valeur !== null) {
                $lignesGagnantes[] = ($libellesGagnants[$champ] ?? $champ).' : '.$valeur;
            }
        }

        return implode("\n", $lignesGagnantes)."\n".$corpus;
    }

    /* ── Assemblage (§17) ────────────────────────────────────── */

    /**
     * @param  array{data: array<string, ?string>, confidence: array<string, float>,
     *     arabic: array<string, string>, transliterated: array<string, string>}  $parse
     */
    private function assemblerOcrResult(array $parse, bool $detecte): OcrResult
    {
        $originals = [];
        foreach ($parse['arabic'] as $champ => $brut) {
            $originals[$champ] = [
                'raw' => $brut,
                'normalized_ar' => ArabicNormalizer::normaliser($brut),
                'transliterated' => $parse['transliterated'][$champ] ?? '',
            ];
        }

        $langue = '';
        foreach (['last_name', 'first_name', 'filiation'] as $champNom) {
            if (isset($parse['arabic'][$champNom])) {
                $langue = ArabicNormalizer::LANGUE_ARABE;

                break;
            }
        }

        return new OcrResult(
            documentType: 'cin',
            language: $langue,
            documentNumber: $parse['data']['document_number'] ?? null,
            firstName: $parse['data']['first_name'] ?? null,
            lastName: $parse['data']['last_name'] ?? null,
            filiation: $parse['data']['filiation'] ?? null,
            dateOfBirth: $parse['data']['date_of_birth'] ?? null,
            placeOfBirth: $parse['data']['place_of_birth'] ?? null,
            nationality: $parse['data']['nationality'] ?? null,
            sex: $parse['data']['sex'] ?? null,
            rawFields: [
                'document_detecte' => $detecte ? 'cin_tunisienne' : null,
                'champs_extraits' => count($parse['data']),
            ],
            confidence: $parse['confidence'],
            originals: $originals,
        );
    }

    /**
     * §29 — debug LOCAL uniquement (config identity.ocr.debug) :
     * métadonnées seules (comptes, longueurs, empreinte), JAMAIS de
     * textes bruts ni de corpus fusionné, même en local.
     */
    private function journaliser(array $textes, string $fusion, bool $detecte): void
    {
        if (! app()->environment('local') || ! config('identity.ocr.debug')) {
            return;
        }

        Log::debug('identity.ocr.cin.debug', JournalisationPrivee::assainir(
            $this->contexteJournal($textes, $fusion, $detecte)
        ));
    }

    /**
     * Contexte de debug sans PII, exposé pour test (pur et déterministe) :
     * aucune valeur d'entrée n'y figure — comptes, longueurs, empreinte
     * sha256 du corpus (corrélation sans exposition) et détection.
     *
     * @param  array<int, string>  $textes
     * @return array{sources: int, document_detecte: bool, longueurs: array<int, int>, empreinte_corpus: ?string}
     */
    public function contexteJournal(array $textes, string $fusion, bool $detecte): array
    {
        return [
            'sources' => count($textes),
            'document_detecte' => $detecte,
            'longueurs' => array_map('mb_strlen', array_values($textes)),
            'empreinte_corpus' => JournalisationPrivee::empreinte($fusion === '' ? null : $fusion),
        ];
    }
}

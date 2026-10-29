<?php

namespace App\Modules\AssistantIa\Services;

/**
 * Service de validation rigoureuse des citations et attribution vérifiable pour le moteur RAG.
 * Empêche le modèle d'inventer des documents, des identifiants ou des liens (hallucinations),
 * et garantit que chaque référence correspond à un passage effectivement récupéré et autorisé.
 */
class ValidateurCitationsIa
{
    /**
     * Motifs indiquant une absence ou insuffisance explicite de preuves dans la réponse.
     *
     * @var array<int, string>
     */
    protected const MOTIFS_PREUVE_INSUFFISANTE = [
        'aucune information',
        'sources insuffisantes',
        'aucun document',
        'pas de preuve',
        'non documenté',
        'non documentée',
        'je ne trouve pas',
        'ne figure pas',
        'n\'est pas mentionné',
        'absence de source',
        'insufficient evidence',
        'sources ne mentionnent pas',
        'impossible de confirmer',
    ];

    /**
     * Valide et normalise les citations d'une réponse vis-à-vis des passages autorisés.
     *
     * @param  array<int, array<string, mixed>>  $passagesAutorises  Passages retournés par MoteurRechercheDocumentaire
     * @return array{
     *     answer: string,
     *     citations: array<int, array{source_id: string, title: string, excerpt: string, path: string}>,
     *     insufficient_evidence: bool,
     *     confidence: array{score: float, level: string, reason: string}
     * }
     */
    public function validerEtNormaliser(string $texteReponse, array $passagesAutorises = []): array
    {
        // 1. Indexation des passages autorisés par source_id, titre et chemin pour recherche rapide
        $indexParId = [];
        $indexParTitre = [];
        $indexParChemin = [];

        foreach ($passagesAutorises as $idx => $passage) {
            $sourceId = (string) ($passage['source_id'] ?? ('SRC_'.($idx + 1)));
            $titre = (string) ($passage['titre'] ?? '');
            $chemin = (string) ($passage['chemin'] ?? '');
            $extrait = (string) ($passage['extrait'] ?? '');
            $contenu = (string) ($passage['contenu'] ?? $extrait);

            $normalise = [
                'source_id' => $sourceId,
                'title' => $titre,
                'excerpt' => $extrait,
                'path' => $chemin,
                'contenu' => $contenu,
            ];

            $indexParId[$sourceId] = $normalise;
            if ($titre !== '') {
                $indexParTitre[mb_strtolower($titre)] = $normalise;
            }
            if ($chemin !== '') {
                $indexParChemin[mb_strtolower($chemin)] = $normalise;
            }
        }

        // 2. Détection de format JSON structuré généré par le modèle
        $citationsDetectees = [];
        $texteNettoye = $texteReponse;

        $jsonExtrait = $this->extraireJsonPotentiel($texteReponse);
        if ($jsonExtrait !== null) {
            if (isset($jsonExtrait['answer'])) {
                $texteNettoye = (string) $jsonExtrait['answer'];
            }
            if (! empty($jsonExtrait['citations']) && is_array($jsonExtrait['citations'])) {
                foreach ($jsonExtrait['citations'] as $c) {
                    if (is_array($c)) {
                        $citationsDetectees[] = [
                            'source_id' => (string) ($c['source_id'] ?? ''),
                            'title' => (string) ($c['title'] ?? ''),
                            'excerpt' => (string) ($c['excerpt'] ?? ''),
                        ];
                    }
                }
            }
        }

        // 3. Extraction par motifs textuels : [SRC_...], [Source: ...], ou [Titre](SRC_...)
        preg_match_all('/\[(?:Source:\s*)?(SRC_[a-zA-Z0-9_\-]+)\]/i', $texteReponse, $matchesId);
        if (! empty($matchesId[1])) {
            foreach ($matchesId[1] as $idMatch) {
                $citationsDetectees[] = ['source_id' => $idMatch];
            }
        }

        preg_match_all('/\[([^\]]+)\]\((SRC_[a-zA-Z0-9_\-]+)\)/i', $texteReponse, $matchesMd);
        if (! empty($matchesMd[2])) {
            foreach ($matchesMd[2] as $idx => $idMatch) {
                $citationsDetectees[] = [
                    'source_id' => $idMatch,
                    'title' => $matchesMd[1][$idx] ?? '',
                ];
            }
        }

        // 4. Vérification stricte côté serveur : éliminer toute source inconnue, falsifiée ou non autorisée
        $citationsValidees = [];
        $idsVus = [];

        foreach ($citationsDetectees as $cit) {
            $idCandidat = $cit['source_id'] ?? '';
            $titreCandidat = mb_strtolower(trim($cit['title'] ?? ''));

            $passageAssocie = null;
            if (isset($indexParId[$idCandidat])) {
                $passageAssocie = $indexParId[$idCandidat];
            } elseif ($titreCandidat !== '' && isset($indexParTitre[$titreCandidat])) {
                $passageAssocie = $indexParTitre[$titreCandidat];
            }

            if ($passageAssocie !== null) {
                $sourceIdRetenu = $passageAssocie['source_id'];
                if (! isset($idsVus[$sourceIdRetenu])) {
                    $idsVus[$sourceIdRetenu] = true;

                    // L'extrait est OBLIGATOIREMENT tiré du passage serveur vérifié
                    $extraitControle = $passageAssocie['excerpt'];
                    if (! empty($cit['excerpt'])) {
                        // Si le modèle a fourni un extrait spécifique, vérifier qu'il est bien présent dans le chunk réel
                        if (str_contains(mb_strtolower($passageAssocie['contenu']), mb_strtolower(trim($cit['excerpt'])))) {
                            $extraitControle = trim($cit['excerpt']);
                        }
                    }

                    $citationsValidees[] = [
                        'source_id' => $sourceIdRetenu,
                        'title' => $passageAssocie['title'],
                        'excerpt' => $extraitControle,
                        'path' => $passageAssocie['path'],
                    ];
                }
            }
        }

        // 5. Si aucune citation explicite n'a été balisée mais que des passages pertinents existent,
        // vérifier si la réponse mentionne fidèlement un ou plusieurs documents autorisés
        if (empty($citationsValidees) && ! empty($passagesAutorises)) {
            $texteMin = mb_strtolower($texteReponse);
            foreach ($passagesAutorises as $passage) {
                $titreMin = mb_strtolower((string) ($passage['titre'] ?? ''));
                $cheminMin = mb_strtolower((string) ($passage['chemin'] ?? ''));
                $sourceId = (string) ($passage['source_id'] ?? 'SRC_1');

                if ($titreMin !== '' && str_contains($texteMin, $titreMin)) {
                    if (! isset($idsVus[$sourceId])) {
                        $idsVus[$sourceId] = true;
                        $citationsValidees[] = [
                            'source_id' => $sourceId,
                            'title' => (string) ($passage['titre'] ?? 'Document'),
                            'excerpt' => (string) ($passage['extrait'] ?? ''),
                            'path' => (string) ($passage['chemin'] ?? ''),
                        ];
                    }
                }
            }
        }

        // 6. Évaluation du drapeau insufficient_evidence
        $insufficientEvidence = $this->evaluerPreuveInsuffisante($texteReponse, $citationsValidees, $passagesAutorises);

        // 7. Calcul de l'indicateur de confiance
        $confiance = $this->calculerConfiance($citationsValidees, $insufficientEvidence, $passagesAutorises);

        return [
            'answer' => trim($texteNettoye),
            'citations' => $citationsValidees,
            'insufficient_evidence' => $insufficientEvidence,
            'confidence' => $confiance,
        ];
    }

    /**
     * Évalue si la réponse manque de preuves autorisées.
     *
     * @param  array<int, array<string, mixed>>  $citationsValidees
     * @param  array<int, array<string, mixed>>  $passagesAutorises
     */
    protected function evaluerPreuveInsuffisante(string $texte, array $citationsValidees, array $passagesAutorises): bool
    {
        if (empty($passagesAutorises)) {
            return true;
        }

        if (empty($citationsValidees)) {
            return true;
        }

        $texteMin = mb_strtolower($texte);
        foreach (self::MOTIFS_PREUVE_INSUFFISANTE as $motif) {
            if (str_contains($texteMin, $motif)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calcule le niveau et le score de confiance vérifiée.
     *
     * @param  array<int, array<string, mixed>>  $citationsValidees
     * @param  array<int, array<string, mixed>>  $passagesAutorises
     * @return array{score: float, level: string, reason: string}
     */
    protected function calculerConfiance(array $citationsValidees, bool $insufficientEvidence, array $passagesAutorises): array
    {
        if (empty($passagesAutorises)) {
            return [
                'score' => 0.1,
                'level' => 'aucun',
                'reason' => 'Aucune source documentaire interne n\'a été trouvée pour étayer cette réponse.',
            ];
        }

        if ($insufficientEvidence || empty($citationsValidees)) {
            return [
                'score' => 0.3,
                'level' => 'faible',
                'reason' => 'Preuves documentaires partielles ou insuffisantes pour confirmer l\'intégralité des affirmations.',
            ];
        }

        $nbCitations = count($citationsValidees);
        if ($nbCitations >= 2) {
            return [
                'score' => 0.95,
                'level' => 'eleve',
                'reason' => "Réponse solidement étayée par {$nbCitations} sources documentaires internes vérifiées.",
            ];
        }

        return [
            'score' => 0.80,
            'level' => 'moyen',
            'reason' => 'Réponse étayée par une source documentaire interne vérifiée.',
        ];
    }

    /**
     * Tente d'extraire un objet JSON valide depuis la chaîne reçue.
     *
     * @return array<string, mixed>|null
     */
    protected function extraireJsonPotentiel(string $texte): ?array
    {
        $texteTrim = trim($texte);

        // Détection de bloc code ```json ... ```
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $texteTrim, $m)) {
            $decode = json_decode($m[1], true);
            if (is_array($decode)) {
                return $decode;
            }
        }

        if (str_starts_with($texteTrim, '{') && str_ends_with($texteTrim, '}')) {
            $decode = json_decode($texteTrim, true);
            if (is_array($decode)) {
                return $decode;
            }
        }

        return null;
    }
}

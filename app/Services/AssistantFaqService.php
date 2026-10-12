<?php

namespace App\Services;

use App\Services\Recherche\SearchNormalizer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * LOOP 15 — Assistant FAQ fondé sur les sources officielles.
 *
 * Principes stricts :
 * - Aucune règle inventée : réponses issues exclusivement des sources officielles
 *   approuvées (config `inscriptions.faq`, règles d'inscription réelles).
 * - Réponses toujours accompagnées de leur source officielle ou page de référence.
 * - Si question incertaine ou hors périmètre : orientation immédiate vers la scolarité.
 * - Étanchéité absolue des dossiers privés : aucun accès aux dossiers individuels,
 *   statuts personnels ou décisions via cet assistant public (aucun appel DB privé).
 * - Impossibilité d'effectuer la moindre mutation métier (assistant en lecture seule).
 * - Journalisation de métriques de qualité avec SANCTUARISATION des données personnelles
 *   (zéro PII conservée dans les journaux).
 */
class AssistantFaqService
{
    public const STATUT_REPONDU = 'repondu';

    public const STATUT_INCERTAIN = 'incertain';

    public const STATUT_REFUS_PRIVE = 'refus_prive';

    public const STATUT_ACTION_REFUSEE = 'action_refusee';

    public const CONFIANCE_HAUTE = 'haute';

    public const CONFIANCE_MOYENNE = 'moyenne';

    public const CONFIANCE_NULLE = 'nulle';

    public const SEUIL_CONFIANCE_MIN = 0.28;

    public const SEUIL_CONFIANCE_HAUTE = 0.55;

    /**
     * Mots vides français exclus du calcul de pertinence des tokens.
     *
     * @var array<int, string>
     */
    protected const MOTS_VIDES = [
        'le', 'la', 'les', 'de', 'du', 'des', 'un', 'une', 'et', 'en', 'ce', 'cet',
        'cette', 'ces', 'qui', 'que', 'quoi', 'dont', 'pour', 'dans', 'sur', 'par',
        'avec', 'sans', 'sous', 'est', 'sont', 'ont', 'fait', 'mon', 'ton', 'son',
        'notre', 'votre', 'leur', 'mes', 'tes', 'ses', 'nos', 'vos', 'leurs', 'aux',
        'aussi', 'mais', 'donc', 'or', 'ni', 'car', 'comme', 'plus', 'tout', 'tous',
    ];

    /**
     * Interroge l'assistant FAQ officiel.
     *
     * @param  string  $question  Question posée par l'usager
     * @param  array<string, mixed>  $contexte  Options (ex: faculte_id)
     * @return array<string, mixed> Résultat structuré de l'assistant
     */
    public function repondre(string $question, array $contexte = []): array
    {
        $debut = microtime(true);
        $questionNettoyee = trim($question);

        if ($questionNettoyee === '') {
            $resultat = $this->construireReponseVide();
            $this->journaliserMetriques($questionNettoyee, $resultat, $debut, false);

            return $resultat;
        }

        // 1. Détection des tentatives d'accès aux dossiers privés ou PII
        if ($this->contientDonneesPriveesOuPii($questionNettoyee)) {
            $resultat = $this->construireReponseRefusPrive();
            $this->journaliserMetriques($questionNettoyee, $resultat, $debut, true);

            return $resultat;
        }

        // 2. Détection des tentatives de déclenchement d'actions de mutation
        if ($this->tenteActionMutation($questionNettoyee)) {
            $resultat = $this->construireReponseActionRefusee();
            $this->journaliserMetriques($questionNettoyee, $resultat, $debut, false);

            return $resultat;
        }

        // 3. Recherche et scoring sur les sources officielles
        $entrees = config('inscriptions.faq', []);
        $meilleurMatch = $this->trouverMeilleureEntree($questionNettoyee, $entrees);

        if ($meilleurMatch !== null && $meilleurMatch['score'] >= self::SEUIL_CONFIANCE_MIN) {
            $resultat = $this->construireReponseOfficielle($meilleurMatch, $entrees);
        } else {
            $resultat = $this->construireReponseIncertaine($entrees);
        }

        $this->journaliserMetriques($questionNettoyee, $resultat, $debut, false);

        return $resultat;
    }

    /**
     * Détecte si la question contient des données personnelles (PII) ou
     * tente d'interroger un dossier privé nominatif sans authentification.
     */
    public function contientDonneesPriveesOuPii(string $question): bool
    {
        // 1. Détection de formats de CIN, passeport, matricule ou numéros d'identification
        if (preg_match('/\b(?:\d{8}|[A-Z]{1,2}\d{6,8})\b/i', $question)) {
            return true;
        }

        // 2. Détection d'adresses e-mail
        if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $question)) {
            return true;
        }

        // 3. Détection de numéros de téléphone (8 chiffres ou indicatif international)
        if (preg_match('/(?:\+?[0-9]{2,3}[-\s]?)?[0-9]{2}[-\s]?[0-9]{3}[-\s]?[0-9]{3}/', $question)) {
            return true;
        }

        // 4. Détection de requêtes ciblées sur un dossier personnel précis
        $normalise = SearchNormalizer::normaliser($question);
        $motifsDossierPrive = [
            'mon dossier numero',
            'mon dossier num',
            'mes notes',
            'ma note',
            'mon statut personnel',
            'suis je accepte',
            'suis je admis',
            'mon bulletin',
            'le dossier de',
            'le statut de l etudiant',
            'donne moi les notes',
            'donne moi les informations de',
            'numero d inscription',
            'num d inscription',
        ];

        foreach ($motifsDossierPrive as $motif) {
            if (str_contains($normalise, $motif)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Détecte si l'usager demande à l'assistant d'exécuter une mutation de données.
     */
    public function tenteActionMutation(string $question): bool
    {
        $normalise = SearchNormalizer::normaliser($question);

        // Détection par verbe d'action de mutation ciblant les données de l'usager
        if (preg_match('/\b(valide|valider|annule|annuler|supprime|supprimer|modifie|modifier|change|changer|affecte|affecter)\s+(?:mon|ma|mes)\b/u', $normalise)) {
            return true;
        }

        $motifsMutation = [
            'affecte moi',
            'affecter moi',
            'inscrire automatiquement',
            'inscris moi',
            'supprime moi',
        ];

        foreach ($motifsMutation as $motif) {
            if (str_contains($normalise, $motif)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calcule le score de correspondance pour chaque entrée officielle.
     *
     * @param  array<int, array<string, mixed>>  $entrees
     * @return array<string, mixed>|null
     */
    protected function trouverMeilleureEntree(string $question, array $entrees): ?array
    {
        $questionNorm = SearchNormalizer::normaliser($question);
        $tokensQuestion = $this->extraireTokens($questionNorm);

        if (empty($tokensQuestion) && empty($questionNorm)) {
            return null;
        }

        $scores = [];

        foreach ($entrees as $index => $entree) {
            $questionOff = $entree['question'] ?? '';
            $reponseOff = $entree['reponse'] ?? '';
            $motsClesOff = (array) ($entree['mots_cles'] ?? []);

            $questionOffNorm = SearchNormalizer::normaliser($questionOff);
            $reponseOffNorm = SearchNormalizer::normaliser($reponseOff);

            $tokensOff = $this->extraireTokens($questionOffNorm);
            $tokensReponse = $this->extraireTokens($reponseOffNorm);

            $score = 0.0;

            // 1. Correspondance exacte ou forte inclusion de la question officielle
            if ($questionNorm === $questionOffNorm) {
                $score += 1.0;
            } elseif (str_contains($questionNorm, $questionOffNorm) || str_contains($questionOffNorm, $questionNorm)) {
                $score += 0.55;
            }

            // 2. Score sur les mots-clés dédiés (poids fort)
            foreach ($motsClesOff as $motCle) {
                $motCleNorm = SearchNormalizer::normaliser((string) $motCle);
                if ($motCleNorm !== '' && str_contains($questionNorm, $motCleNorm)) {
                    $score += 0.35;
                }
            }

            // 3. Jaccard / chevauchement des tokens significatifs de la question
            if (! empty($tokensQuestion) && ! empty($tokensOff)) {
                $intersection = array_intersect($tokensQuestion, $tokensOff);
                $ratioQuestion = count($intersection) / max(count($tokensQuestion), count($tokensOff));
                $score += ($ratioQuestion * 0.45);
            }

            // 4. Tokens présents dans la réponse officielle (poids d'appoint)
            if (! empty($tokensQuestion) && ! empty($tokensReponse)) {
                $intersectionRep = array_intersect($tokensQuestion, $tokensReponse);
                if (! empty($intersectionRep)) {
                    $ratioReponse = count($intersectionRep) / count($tokensQuestion);
                    $score += min(0.20, $ratioReponse * 0.20);
                }
            }

            $scoreNormalise = min(1.0, round($score, 3));

            $scores[] = [
                'index' => $index,
                'entree' => $entree,
                'score' => $scoreNormalise,
            ];
        }

        usort($scores, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $scores[0] ?? null;
    }

    /**
     * Découpe un texte normalisé en tokens significatifs.
     *
     * @return array<int, string>
     */
    protected function extraireTokens(string $texte): array
    {
        $mots = preg_split('/[^\p{L}\p{N}]+/u', $texte, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];

        foreach ($mots as $mot) {
            $mot = trim($mot);
            if (mb_strlen($mot) >= 3 && ! in_array($mot, self::MOTS_VIDES, true)) {
                $tokens[] = $mot;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Construit une réponse issue d'une source officielle vérifiée.
     *
     * @param  array<string, mixed>  $match
     * @param  array<int, array<string, mixed>>  $toutesEntrees
     * @return array<string, mixed>
     */
    protected function construireReponseOfficielle(array $match, array $toutesEntrees): array
    {
        $entree = $match['entree'];
        $score = (float) $match['score'];
        $route = $entree['source_route'] ?? null;
        $url = ($route && Route::has($route)) ? route($route) : null;

        $confiance = $score >= self::SEUIL_CONFIANCE_HAUTE ? self::CONFIANCE_HAUTE : self::CONFIANCE_MOYENNE;

        return [
            'statut' => self::STATUT_REPONDU,
            'confiance' => $confiance,
            'certitude' => true,
            'score' => $score,
            'question_officielle' => $entree['question'],
            'categorie' => $entree['categorie'] ?? 'Général',
            'reponse' => $entree['reponse'],
            'source_libelle' => $entree['source_libelle'] ?? 'Portail officiel des inscriptions',
            'source_route' => $route,
            'source_url' => $url,
            'orientation_scolarite' => false,
            'contact_scolarite' => config('inscriptions.contact_scolarite'),
            'suggestions' => $this->genererSuggestions($entree['question'], $toutesEntrees),
        ];
    }

    /**
     * Construit une réponse lorsque la question n'a pas de source officielle avérée.
     * Refuse d'inventer des règles et oriente vers l'assistance humaine (scolarité).
     *
     * @param  array<int, array<string, mixed>>  $toutesEntrees
     * @return array<string, mixed>
     */
    protected function construireReponseIncertaine(array $toutesEntrees): array
    {
        return [
            'statut' => self::STATUT_INCERTAIN,
            'confiance' => self::CONFIANCE_NULLE,
            'certitude' => false,
            'score' => 0.0,
            'question_officielle' => null,
            'categorie' => 'Non répertorié',
            'reponse' => 'Cette question ne correspond à aucune procédure officielle répertoriée dans notre FAQ. Afin d\'éviter toute information inexacte ou règle non homologuée, nous vous invitons à contacter directement le service de la scolarité de votre faculté.',
            'source_libelle' => 'Service de la scolarité',
            'source_route' => null,
            'source_url' => null,
            'orientation_scolarite' => true,
            'contact_scolarite' => config('inscriptions.contact_scolarite'),
            'suggestions' => $this->genererSuggestions(null, $toutesEntrees),
        ];
    }

    /**
     * Construit un refus de divulgation pour protéger les données privées des dossiers.
     *
     * @return array<string, mixed>
     */
    protected function construireReponseRefusPrive(): array
    {
        return [
            'statut' => self::STATUT_REFUS_PRIVE,
            'confiance' => self::CONFIANCE_NULLE,
            'certitude' => false,
            'score' => 0.0,
            'question_officielle' => null,
            'categorie' => 'Protection des données privées',
            'reponse' => 'Pour des raisons de confidentialité et de sécurité, cet assistant public n\'a aucun accès aux dossiers privés et ne communique aucune donnée personnelle nominative. Pour consulter l\'état de votre dossier ou vos informations personnelles, veuillez vous connecter à votre espace étudiant sécurisé ou contacter la scolarité.',
            'source_libelle' => 'Espace étudiant sécurisé',
            'source_route' => 'etudiant.connexion.show',
            'source_url' => Route::has('etudiant.connexion.show') ? route('etudiant.connexion.show') : null,
            'orientation_scolarite' => true,
            'contact_scolarite' => config('inscriptions.contact_scolarite'),
            'suggestions' => [
                'Comment suivre mon dossier ?',
                'J\'ai déjà un dossier, que faire ?',
            ],
        ];
    }

    /**
     * Construit un refus d'action de mutation.
     *
     * @return array<string, mixed>
     */
    protected function construireReponseActionRefusee(): array
    {
        return [
            'statut' => self::STATUT_ACTION_REFUSEE,
            'confiance' => self::CONFIANCE_NULLE,
            'certitude' => false,
            'score' => 0.0,
            'question_officielle' => null,
            'categorie' => 'Outil en lecture seule',
            'reponse' => 'Cet assistant est un outil d\'information officiel en lecture seule. Il n\'a aucun pouvoir de modification sur les dossiers, les statuts d\'inscription, les affectations ou les décisions académiques. Toute modification s\'effectue via votre espace personnel ou par un agent habilité de la scolarité.',
            'source_libelle' => 'Règles de gestion académique',
            'source_route' => 'inscription.faq',
            'source_url' => route('inscription.faq'),
            'orientation_scolarite' => true,
            'contact_scolarite' => config('inscriptions.contact_scolarite'),
            'suggestions' => [
                'Comment suivre mon dossier ?',
                'Ma pièce a été rejetée, comment la corriger ?',
            ],
        ];
    }

    /**
     * Réponse pour saisie vide.
     *
     * @return array<string, mixed>
     */
    protected function construireReponseVide(): array
    {
        return [
            'statut' => self::STATUT_INCERTAIN,
            'confiance' => self::CONFIANCE_NULLE,
            'certitude' => false,
            'score' => 0.0,
            'question_officielle' => null,
            'categorie' => 'Général',
            'reponse' => 'Veuillez saisir votre question pour interroger la base des sources officielles.',
            'source_libelle' => 'FAQ officielle',
            'source_route' => 'inscription.faq',
            'source_url' => route('inscription.faq'),
            'orientation_scolarite' => false,
            'contact_scolarite' => config('inscriptions.contact_scolarite'),
            'suggestions' => [
                'Quand puis-je m\'inscrire ?',
                'Quelles pièces dois-je préparer ?',
                'Comment suivre mon dossier ?',
            ],
        ];
    }

    /**
     * Propose des suggestions de questions officielles alternatives.
     *
     * @param  array<int, array<string, mixed>>  $entrees
     * @return array<int, string>
     */
    protected function genererSuggestions(?string $questionExclue, array $entrees): array
    {
        $suggestions = [];

        foreach ($entrees as $entree) {
            $q = $entree['question'] ?? null;
            if ($q && $q !== $questionExclue) {
                $suggestions[] = $q;
                if (count($suggestions) >= 3) {
                    break;
                }
            }
        }

        return $suggestions;
    }

    /**
     * Journalise les métriques de qualité opérationnelles en respectant la
     * stricte sanctuarisation des données personnelles (zéro PII dans les logs).
     *
     * @param  array<string, mixed>  $resultat
     */
    protected function journaliserMetriques(string $question, array $resultat, float $debutMs, bool $piiDetectee): void
    {
        $duree = round((microtime(true) - $debutMs) * 1000, 2);

        // Sanctuarisation : si une PII est suspectée, aucun hash ni extrait de question n'est consigné.
        $questionHash = (! $piiDetectee && $question !== '')
            ? hash('sha256', SearchNormalizer::normaliser($question))
            : null;

        Log::info('assistant_faq_metrique', [
            'statut' => $resultat['statut'],
            'confiance' => $resultat['confiance'],
            'score' => $resultat['score'],
            'categorie' => $resultat['categorie'] ?? null,
            'source_libelle' => $resultat['source_libelle'] ?? null,
            'orientation_scolarite' => (bool) ($resultat['orientation_scolarite'] ?? false),
            'pii_detectee' => $piiDetectee,
            'longueur_question' => mb_strlen($question),
            'duree_ms' => $duree,
            'question_hash' => $questionHash,
        ]);
    }
}

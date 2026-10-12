<?php

namespace App\Services\Io\Templates;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;

/**
 * Définition centralisée des modèles d'importation (§113).
 *
 * PRINCIPE FONDAMENTAL §117 : TEMPLATE = SCHÉMA D'IMPORT = VALIDATION.
 * Une seule source de vérité alimente la génération des modèles,
 * la validation des colonnes à l'import ET les suggestions d'erreurs.
 *
 * Chaque type : version, colonnes canoniques (clé normalisée ⇒ métadonnées),
 * variantes acceptées (rétrocompatibilité), permission de téléchargement
 * (§102) et valeurs de référence dynamiques depuis la base (§96, §98).
 */
final class ImportTemplateDefinition
{
    /** Version courante de chaque type (§99) — incrémenter en cas de rupture. */
    public const VERSIONS = [
        'students' => 1,
        'grades' => 1,
        'academic_registrations' => 1,
        'subjects' => 1,
        'results' => 1,
        'documents' => 1,
    ];

    /**
     * Définitions complètes : type ⇒ label, icône, description,
     * permission de téléchargement (web RBAC + scope API §102/§114),
     * formats proposés, colonnes [clé ⇒ obligatoire, format, exemple, description],
     * variantes de clés tolérées à l'import (§105 : renommées détectées).
     */
    public static function definitions(): array
    {
        return [
            'students' => [
                'label' => 'Étudiants', 'icone' => '👤',
                'description' => 'Importer des étudiants depuis un système externe',
                'permissionWeb' => 'archives.create', 'permissionApi' => ['imports:create', 'students:write'],
                'formats' => ['xlsx', 'csv', 'json'],
                'colonnes' => [
                    'cin' => ['obligatoire' => true, 'format' => 'texte unique (ex : BK123456)', 'exemple' => 'AB123456', 'description' => 'Carte d\'Identité Nationale — unique'],
                    'nom' => ['obligatoire' => true, 'format' => 'texte (100 max)', 'exemple' => 'DUPONT', 'description' => 'Nom de famille'],
                    'prenom' => ['obligatoire' => true, 'format' => 'texte (100 max)', 'exemple' => 'Jean', 'description' => 'Prénom'],
                    'email' => ['obligatoire' => true, 'format' => 'email unique', 'exemple' => 'jean.dupont@example.test', 'description' => 'Email de contact'],
                    'sexe' => ['obligatoire' => true, 'format' => 'M ou F', 'exemple' => 'M', 'description' => 'Genre', 'references' => ['M', 'F']],
                    'telephone' => ['obligatoire' => false, 'format' => 'texte (30 max)', 'exemple' => '+212 600000000', 'description' => 'Téléphone portable'],
                    'date_naissance' => ['obligatoire' => false, 'format' => 'AAAA-MM-JJ', 'exemple' => '2004-05-17', 'description' => 'Date de naissance'],
                    'faculte' => ['obligatoire' => true, 'format' => 'code faculté existant', 'exemple' => 'FST', 'description' => 'Faculté d\'accueil (code)', 'references' => 'facultes'],
                    'niveau' => ['obligatoire' => true, 'format' => 'L1…M2, D1…D3', 'exemple' => 'L3', 'description' => 'Niveau d\'études', 'references' => ['L1', 'L2', 'L3', 'M1', 'M2', 'D1', 'D2', 'D3']],
                    'annee_academique' => ['obligatoire' => true, 'format' => 'AAAA-AAAA', 'exemple' => '2026-2027', 'description' => 'Année académique'],
                    'adresse' => ['obligatoire' => true, 'format' => 'texte (500 max)', 'exemple' => '12 Rue de la Liberté', 'description' => 'Adresse complète'],
                    'ville' => ['obligatoire' => true, 'format' => 'texte', 'exemple' => 'Casablanca', 'description' => 'Ville'],
                    'code_postal' => ['obligatoire' => true, 'format' => 'texte', 'exemple' => '20000', 'description' => 'Code postal'],
                    'annee_bac' => ['obligatoire' => true, 'format' => 'AAAA', 'exemple' => '2024', 'description' => 'Année d\'obtention du bac'],
                    'mention_bac' => ['obligatoire' => false, 'format' => 'mention', 'exemple' => 'Bien', 'description' => 'Mention du bac', 'references' => ['Passable', 'Assez Bien', 'Bien', 'Très Bien', 'Excellent']],
                    'programme' => ['obligatoire' => false, 'format' => 'code programme LMD', 'exemple' => 'LGL', 'description' => 'Programme (rattachement LMD)', 'references' => 'programmes'],
                    'niveau_ref' => ['obligatoire' => false, 'format' => 'code niveau LMD', 'exemple' => 'L2', 'description' => 'Niveau référentiel', 'references' => 'niveaux'],
                    'groupe' => ['obligatoire' => false, 'format' => 'code groupe existant', 'exemple' => 'GL2-A', 'description' => 'Groupe d’accueil', 'references' => 'groupes'],
                ],
                'variantes' => [],
            ],
            'grades' => [
                'label' => 'Notes', 'icone' => '📝',
                'description' => 'Importer des notes par matière et session',
                'permissionWeb' => 'notes.create', 'permissionApi' => ['imports:create', 'grades:write'],
                'formats' => ['xlsx', 'csv', 'json'],
                'colonnes' => [
                    'matricule' => ['obligatoire' => true, 'format' => 'matricule du dossier', 'exemple' => 'MAT-00001', 'description' => 'Matricule de l\'étudiant (dossier académique)'],
                    'annee_universitaire' => ['obligatoire' => true, 'format' => 'AAAA-AAAA', 'exemple' => '2025-2026', 'description' => 'Année universitaire', 'references' => 'annees'],
                    'semestre' => ['obligatoire' => true, 'format' => '1 ou 2', 'exemple' => '1', 'description' => 'Numéro de semestre', 'references' => ['1', '2']],
                    'code_matiere' => ['obligatoire' => true, 'format' => 'code matière existant', 'exemple' => 'IA301', 'description' => 'Code de la matière', 'references' => 'matieres'],
                    'note' => ['obligatoire' => true, 'format' => 'nombre 0 à 20', 'exemple' => '14.50', 'description' => 'Note obtenue'],
                    'session' => ['obligatoire' => true, 'format' => 'normale ou rattrapage', 'exemple' => 'normale', 'description' => 'Session d\'examen', 'references' => ['normale', 'rattrapage']],
                    'observation' => ['obligatoire' => false, 'format' => 'texte (500 max)', 'exemple' => 'Session de septembre', 'description' => 'Observation facultative'],
                ],
                // Variantes tolérées : anciens schémas (cin, matiere, annee, niveau)
                'variantes' => ['cin', 'matiere', 'annee', 'niveau'],
            ],
            'academic_registrations' => [
                'label' => 'Inscriptions académiques', 'icone' => '🎓',
                'description' => 'Importer des inscriptions académiques (avec S1/S2 auto-créés)',
                'permissionWeb' => 'archives.create', 'permissionApi' => ['imports:create'],
                'formats' => ['xlsx', 'csv'],
                'colonnes' => [
                    'matricule' => ['obligatoire' => true, 'format' => 'matricule du dossier', 'exemple' => 'MAT-00001', 'description' => 'Matricule de l\'étudiant'],
                    'annee_universitaire' => ['obligatoire' => true, 'format' => 'AAAA-AAAA', 'exemple' => '2025-2026', 'description' => 'Année universitaire', 'references' => 'annees'],
                    'filiere' => ['obligatoire' => false, 'format' => 'texte', 'exemple' => 'Sciences & Techniques', 'description' => 'Filière'],
                    'niveau' => ['obligatoire' => true, 'format' => 'L1…M2, D1…D3', 'exemple' => 'L3', 'description' => 'Niveau', 'references' => ['L1', 'L2', 'L3', 'M1', 'M2', 'D1', 'D2', 'D3']],
                    'groupe' => ['obligatoire' => false, 'format' => 'texte', 'exemple' => 'G1', 'description' => 'Groupe', 'references' => 'groupes'],
                    'statut' => ['obligatoire' => true, 'format' => 'statut académique', 'exemple' => 'en_cours', 'description' => 'Statut de l\'inscription', 'references' => ['en_cours', 'valide', 'ajourne', 'abandon']],
                    'observation' => ['obligatoire' => false, 'format' => 'texte (1000 max)', 'exemple' => 'Réorientation', 'description' => 'Observation'],
                ],
                'variantes' => [],
            ],
            'subjects' => [
                'label' => 'Matières', 'icone' => '📚',
                'description' => 'Importer le catalogue des matières',
                'permissionWeb' => 'configuration.manage', 'permissionApi' => ['imports:create', 'configuration:manage'],
                'formats' => ['xlsx', 'csv'],
                'colonnes' => [
                    'code' => ['obligatoire' => true, 'format' => 'code unique (20 max)', 'exemple' => 'IA301', 'description' => 'Code matière'],
                    'nom' => ['obligatoire' => true, 'format' => 'texte (150 max)', 'exemple' => 'Intelligence Artificielle', 'description' => 'Nom complet'],
                    'coefficient' => ['obligatoire' => true, 'format' => 'nombre > 0', 'exemple' => '3', 'description' => 'Coefficient de pondération'],
                    'credits' => ['obligatoire' => true, 'format' => 'nombre ≥ 0', 'exemple' => '5', 'description' => 'Crédits ECTS'],
                    'niveau' => ['obligatoire' => true, 'format' => 'L1…M2', 'exemple' => 'L3', 'description' => 'Niveau', 'references' => ['L1', 'L2', 'L3', 'M1', 'M2']],
                    'semestre' => ['obligatoire' => true, 'format' => '1 ou 2', 'exemple' => '1', 'description' => 'Semestre', 'references' => ['1', '2']],
                ],
                'variantes' => [],
            ],
            'results' => [
                'label' => 'Résultats', 'icone' => '🏆',
                'description' => 'Importer les résultats officiels par semestre (moyenne, crédits, décision)',
                'permissionWeb' => 'configuration.manage', 'permissionApi' => ['imports:create', 'configuration:manage'],
                'formats' => ['xlsx', 'csv'],
                'colonnes' => [
                    'matricule' => ['obligatoire' => true, 'format' => 'matricule du dossier', 'exemple' => 'MAT-00001', 'description' => 'Matricule de l\'étudiant'],
                    'annee_universitaire' => ['obligatoire' => true, 'format' => 'AAAA-AAAA', 'exemple' => '2025-2026', 'description' => 'Année universitaire', 'references' => 'annees'],
                    'semestre' => ['obligatoire' => true, 'format' => '1 ou 2', 'exemple' => '1', 'description' => 'Semestre', 'references' => ['1', '2']],
                    'moyenne' => ['obligatoire' => true, 'format' => 'nombre 0 à 20', 'exemple' => '14.50', 'description' => 'Moyenne semestrielle officielle'],
                    'credits_obtenus' => ['obligatoire' => true, 'format' => 'nombre ≥ 0', 'exemple' => '15', 'description' => 'Crédits obtenus'],
                    'credits_total' => ['obligatoire' => true, 'format' => 'nombre ≥ 0', 'exemple' => '30', 'description' => 'Crédits totaux du semestre'],
                    'decision' => ['obligatoire' => true, 'format' => 'décision', 'exemple' => 'Validé', 'description' => 'Décision académique', 'references' => ['Validé', 'Rattrapage', 'Non validé']],
                ],
                'variantes' => [],
            ],
            'documents' => [
                'label' => 'Documents (métadonnées)', 'icone' => '📄',
                'description' => 'Déclarer des documents déjà déposés sur le serveur (métadonnées uniquement — jamais de binaire)',
                'permissionWeb' => 'documents.upload', 'permissionApi' => ['imports:create', 'documents:write'],
                'formats' => ['xlsx', 'csv'],
                'colonnes' => [
                    'matricule' => ['obligatoire' => true, 'format' => 'matricule du dossier', 'exemple' => 'MAT-00001', 'description' => 'Matricule de l\'étudiant'],
                    'annee_universitaire' => ['obligatoire' => false, 'format' => 'AAAA-AAAA', 'exemple' => '2025-2026', 'description' => 'Année de rattachement', 'references' => 'annees'],
                    'type_document' => ['obligatoire' => true, 'format' => 'type officiel', 'exemple' => 'attestation', 'description' => 'Type de document', 'references' => ['releve_notes', 'attestation', 'diplome', 'certificat', 'convention', 'autre']],
                    'nom_document' => ['obligatoire' => true, 'format' => 'texte (255 max)', 'exemple' => 'attestation_2025.pdf', 'description' => 'Nom d\'origine'],
                    'chemin_fichier' => ['obligatoire' => true, 'format' => 'chemin interne archives/etudiant_…', 'exemple' => 'archives/etudiant_1/2025/documents/x.pdf', 'description' => 'Chemin du fichier déjà présent dans le stockage privé'],
                ],
                'variantes' => [],
            ],
        ];
    }

    public static function types(): array
    {
        return array_keys(self::definitions());
    }

    public static function definition(string $type): ?array
    {
        return self::definitions()[$type] ?? null;
    }

    /** Clés canoniques d'un type. */
    public static function colonnes(string $type): array
    {
        return array_keys(self::definitions()[$type]['colonnes'] ?? []);
    }

    /**
     * §96/§98 — valeurs de référence dynamiques depuis la base.
     */
    public static function referencesDynamiques(): array
    {
        return [
            'facultes' => Faculte::orderBy('code')->pluck('code')->all(),
            'annees' => AnneeUniversitaire::orderByDesc('libelle')->pluck('libelle')->all(),
            'groupes' => InscriptionAcademique::whereNotNull('groupe')->distinct()->orderBy('groupe')->pluck('groupe')->all(),
            'matieres' => Matiere::orderBy('code')->get()->map(
                fn ($m) => $m->code.' — '.$m->niveau.' S'.$m->semestre,
            )->all(),
        ];
    }

    /**
     * §105 — validation des colonnes d'un fichier contre le schéma du type.
     * Détecte : manquantes (sauf substituées par une VARIANTE acceptée),
     * inconnues, dupliquées et renommées (suggestion).
     *
     * @param  array<int, string>  $entetes  clés normalisées par le parser
     * @return array<int, array{ligne: string, champ: string, message: string, suggestion: string}>
     */
    public static function validerColonnes(string $type, array $entetes): array
    {
        $definition = self::definition($type);
        $erreurs = [];

        if ($definition === null) {
            return [['ligne' => 'structure', 'champ' => 'type', 'message' => "Type d'import inconnu : {$type}", 'suggestion' => '']];
        }

        $variantes = $definition['variantes'] ?? [];

        // Colonnes dupliquées
        $comptees = array_count_values(array_map('mb_strtolower', $entetes));
        foreach ($comptees as $colonne => $nb) {
            if ($nb > 1) {
                $erreurs[] = ['ligne' => 'structure', 'champ' => $colonne, 'message' => "La colonne « {$colonne} » apparaît {$nb} fois.", 'suggestion' => 'Supprimez les colonnes dupliquées.'];
            }
        }

        // Colonnes manquantes (obligatoires) — une VARIANTE acceptée y substitue
        // (§106 : pas de mapping approximatif, uniquement des variantes officielles)
        foreach ($definition['colonnes'] as $cle => $meta) {
            if (! $meta['obligatoire'] || in_array($cle, $entetes, true)) {
                continue;
            }

            $substituee = collect($variantes)
                ->contains(fn ($v) => in_array($v, $entetes, true));

            if (! $substituee) {
                $erreurs[] = [
                    'ligne' => 'structure', 'champ' => $cle,
                    'message' => "La colonne obligatoire « {$cle} » est manquante.",
                    'suggestion' => 'Utilisez le modèle officiel : téléchargez-le depuis la section Modèles d\'importation.',
                ];
            }
        }

        // Colonnes inconnues (+ détection de renommées)
        foreach ($entetes as $entete) {
            if (in_array($entete, array_merge(array_keys($definition['colonnes']), $variantes), true)) {
                continue;
            }

            $renommee = null;
            foreach (array_keys($definition['colonnes']) as $attendue) {
                if (levenshtein($entete, $attendue) <= 2) {
                    $renommee = $attendue;
                    break;
                }
            }

            $erreurs[] = [
                'ligne' => 'structure', 'champ' => $entete,
                'message' => $renommee !== null
                    ? "Colonne « {$entete} » inconnue (renommée ?)"
                    : "Colonne « {$entete} » inconnue.",
                'suggestion' => $renommee !== null
                    ? "Vouliez-vous dire « {$renommee} » ? Utilisez le modèle officiel."
                    : 'Utilisez le modèle officiel.',
            ];
        }

        return $erreurs;
    }

    /**
     * §104 — détection automatique du type à partir de la structure.
     * Ne se base JAMAIS sur le nom du fichier : uniquement les colonnes.
     */
    public static function detecterType(array $entetes): ?string
    {
        foreach (self::definitions() as $type => $definition) {
            $attendues = array_keys($definition['colonnes']);
            $obligatoires = array_keys(array_filter($definition['colonnes'], fn ($c) => $c['obligatoire']));

            $manquantes = array_diff($obligatoires, $entetes);
            $inconnues = array_diff($entetes, array_merge($attendues, $definition['variantes'] ?? []));

            if ($manquantes === [] && $inconnues === [] && $entetes !== []) {
                return $type;
            }
        }

        return null;
    }
}

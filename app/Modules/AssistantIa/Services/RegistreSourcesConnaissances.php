<?php

namespace App\Modules\AssistantIa\Services;

use App\Modules\AssistantIa\Exceptions\AssistantIaException;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Models\AiSourceConnaissance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * Service central de gestion du cycle de vie des sources de connaissances du Knowledge Hub.
 * Garantit l'idempotence des synchronisations, la traçabilité et l'absence de fuite de secrets.
 */
class RegistreSourcesConnaissances
{
    public function __construct(
        protected ?IndexeurDocumentaire $indexeur = null,
        protected ?ConnecteurDocumentationMetier $connecteurDoc = null
    ) {
        $this->indexeur = $indexeur ?? app(IndexeurDocumentaire::class);
        $this->connecteurDoc = $connecteurDoc ?? (class_exists(ConnecteurDocumentationMetier::class) ? app(ConnecteurDocumentationMetier::class) : null);
    }

    /**
     * Enregistre ou met à jour une source de connaissance de manière idempotente.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function enregistrerSource(
        string $nom,
        string $type,
        string $origine,
        ?string $description = null,
        string $politiqueActualisation = 'sur_modification',
        array $metadata = []
    ): AiSourceConnaissance {
        if (! in_array($type, AiSourceConnaissance::TYPES_VALIDES, true)) {
            throw new AssistantIaException("Type de source invalide : '{$type}'. Types autorisés : ".implode(', ', AiSourceConnaissance::TYPES_VALIDES));
        }

        $origineNormalisee = str_replace('\\', '/', trim($origine));

        // Validation anti-traversal sur l'origine
        if (str_contains($origineNormalisee, '..') || str_starts_with($origineNormalisee, './')) {
            throw new SecuriteIaException("Origine de source invalide ou suspecte : '{$origine}'");
        }

        /** @var AiSourceConnaissance $source */
        $source = AiSourceConnaissance::updateOrCreate(
            ['origine' => $origineNormalisee],
            [
                'nom' => $nom,
                'description' => $description,
                'type' => $type,
                'politique_actualisation' => $politiqueActualisation,
                'statut' => 'actif',
                'metadata' => $metadata,
            ]
        );

        JournalisationIa::info("Source Knowledge Hub enregistrée : {$nom} ({$type}) -> {$origineNormalisee}");

        return $source;
    }

    /**
     * Vérifie la disponibilité et l'accessibilité technique d'une source.
     */
    public function verifierDisponibilite(AiSourceConnaissance $source): bool
    {
        if ($source->type === 'approved_web_source') {
            return filter_var($source->origine, FILTER_VALIDATE_URL) !== false;
        }

        // Pour les sources fichiers locales (project_code, internal_document, loop_report...)
        $cheminAbsolu = base_path($source->origine);

        return File::exists($cheminAbsolu) && (File::isDirectory($cheminAbsolu) || File::isFile($cheminAbsolu));
    }

    /**
     * Calcule l'empreinte de la source pour détecter les changements sans réindexation inutile.
     */
    public function calculerEmpreinte(AiSourceConnaissance $source): ?string
    {
        if (! $this->verifierDisponibilite($source)) {
            return null;
        }

        if ($source->type === 'approved_web_source') {
            return hash('sha256', $source->origine);
        }

        $cheminAbsolu = base_path($source->origine);
        if (File::isFile($cheminAbsolu)) {
            return hash_file('sha256', $cheminAbsolu);
        }

        if (File::isDirectory($cheminAbsolu)) {
            // Empreinte combinée des dates de modification des fichiers
            $fichiers = File::allFiles($cheminAbsolu);
            $concat = '';
            foreach ($fichiers as $f) {
                $concat .= $f->getRelativePathname().':'.$f->getMTime().'|';
            }

            return hash('sha256', $concat);
        }

        return null;
    }

    /**
     * Détecte si la source a changé depuis sa dernière synchronisation réussie.
     */
    public function aDesChangements(AiSourceConnaissance $source): bool
    {
        if (empty($source->derniere_synchronisation_a) || empty($source->derniere_empreinte)) {
            return true;
        }

        $empreinteActuelle = $this->calculerEmpreinte($source);

        return $empreinteActuelle !== $source->derniere_empreinte;
    }

    /**
     * Synchronise une source de manière idempotente.
     *
     * @return array{succes: bool, documents: int, morceaux: int, message: string}
     */
    public function synchroniserSource(AiSourceConnaissance $source, bool $forcer = false): array
    {
        if ($source->estSuspendue()) {
            return [
                'succes' => false,
                'documents' => $source->nombre_documents,
                'morceaux' => $source->nombre_morceaux,
                'message' => "La source '{$source->nom}' est suspendue. Réactivez-la pour synchroniser.",
            ];
        }

        if (! $this->verifierDisponibilite($source)) {
            $source->marquerErreur("La ressource d'origine '{$source->origine}' est inaccessible.");

            return [
                'succes' => false,
                'documents' => 0,
                'morceaux' => 0,
                'message' => "Source inaccessible : {$source->origine}",
            ];
        }

        $nouvelleEmpreinte = $this->calculerEmpreinte($source);
        if (! $forcer && $nouvelleEmpreinte !== null && $nouvelleEmpreinte === $source->derniere_empreinte) {
            return [
                'succes' => true,
                'documents' => $source->nombre_documents,
                'morceaux' => $source->nombre_morceaux,
                'message' => 'Source à jour (aucun changement détecté).',
            ];
        }

        try {
            $nbDocs = 0;
            $nbMorceaux = 0;

            if ($source->type === 'approved_web_source') {
                // Pour les sources web, la synchronisation est gérée par le connecteur web (Lot 4)
                $nbDocs = 1;
                $nbMorceaux = 1;
            } else {
                $cheminAbsolu = base_path($source->origine);
                $fichiers = File::isFile($cheminAbsolu) ? [new \SplFileInfo($cheminAbsolu)] : File::allFiles($cheminAbsolu);

                foreach ($fichiers as $fichier) {
                    $cheminRelatif = str_replace(base_path().'/', '', $fichier->getPathname());
                    $cheminRelatif = str_replace('\\', '/', $cheminRelatif);

                    if (! $this->indexeur->estFichierAutorise($cheminRelatif)) {
                        continue;
                    }

                    $contenu = @file_get_contents($fichier->getPathname());
                    if ($contenu === false || trim($contenu) === '') {
                        continue;
                    }

                    // Détection des métadonnées (titre, domaine, statut, version, restriction)
                    $meta = $this->connecteurDoc ? $this->connecteurDoc->analyserMetadonnees($contenu, $cheminRelatif) : null;

                    $titre = ! empty($meta['titre']) ? $meta['titre'] : pathinfo($cheminRelatif, PATHINFO_FILENAME);
                    $statut = $meta['statut'] ?? 'valide';
                    $domaine = $meta['domaine'] ?? 'general';
                    $version = $meta['version'] ?? '1.0';
                    $estSensible = (bool) ($meta['restreint'] ?? false);
                    $permissionRequise = $estSensible ? 'assistant_ia.sources_restreintes' : null;

                    $morceaux = $this->indexeur->indexerContenu(
                        cheminRelatif: $cheminRelatif,
                        titre: $titre,
                        contenu: $contenu,
                        source: $source->type,
                        estSensible: $estSensible,
                        permissionRequise: $permissionRequise,
                        statutDocument: $statut,
                        domaineMetier: $domaine,
                        versionDocument: $version,
                        metadata: [
                            'source_id' => $source->id,
                            'source_nom' => $source->nom,
                            'source_origine' => $source->origine,
                            'source_type' => $source->type,
                        ]
                    );

                    if ($morceaux > 0) {
                        $nbDocs++;
                        $nbMorceaux += $morceaux;
                    }
                }
            }

            $source->marquerSucces($nbDocs, $nbMorceaux, $nouvelleEmpreinte);

            JournalisationIa::info("Synchronisation réussie pour '{$source->nom}' : {$nbDocs} docs, {$nbMorceaux} morceaux.");

            return [
                'succes' => true,
                'documents' => $nbDocs,
                'morceaux' => $nbMorceaux,
                'message' => "Synchronisation effectuée avec succès ({$nbDocs} documents, {$nbMorceaux} morceaux).",
            ];
        } catch (\Throwable $e) {
            $source->marquerErreur('Échec de synchronisation : '.$e->getMessage());
            JournalisationIa::avertissement("Erreur synchronisation source '{$source->nom}' : ".$e->getMessage());

            return [
                'succes' => false,
                'documents' => 0,
                'morceaux' => 0,
                'message' => "Erreur : {$e->getMessage()}",
            ];
        }
    }

    /**
     * Suspend une source pour empêcher son inclusion dans le Knowledge Hub.
     */
    public function suspendreSource(AiSourceConnaissance $source): void
    {
        $source->suspendre();
        JournalisationIa::info("Source '{$source->nom}' suspendue.");
    }

    /**
     * Réactive une source suspendue.
     */
    public function reactiverSource(AiSourceConnaissance $source): void
    {
        $source->reactiver();
        JournalisationIa::info("Source '{$source->nom}' réactivée.");
    }

    /**
     * Supprime de manière réversible une source sans détruire les preuves historiques d'audit.
     */
    public function desactiverSource(AiSourceConnaissance $source): void
    {
        $source->delete(); // Soft delete : préserve les données pour l'audit
        JournalisationIa::info("Source '{$source->nom}' désactivée (soft-deleted).");
    }

    /**
     * Liste toutes les sources actives du Knowledge Hub.
     *
     * @return Collection<int, AiSourceConnaissance>
     */
    public function sourcesActives(): Collection
    {
        return AiSourceConnaissance::actif()->orderBy('nom')->get();
    }

    /**
     * Initialise les sources fondamentales recommandées d'UniRegistre.
     */
    public function initialiserSourcesFondamentales(): void
    {
        $sourcesInitiales = [
            [
                'nom' => 'Documentation Métier UniRegistre',
                'type' => 'business_procedure',
                'origine' => 'docs',
                'description' => 'Documentation officielle des processus administratifs et académiques.',
            ],
            [
                'nom' => 'Rapports d’ingénierie et Décisions LOOP',
                'type' => 'loop_report',
                'origine' => 'docs/engineering',
                'description' => 'Historique des audits, tests et décisions d’architecture des boucles REA.',
            ],
            [
                'nom' => 'Architecture et Schémas du Projet',
                'type' => 'technical_documentation',
                'origine' => 'docs/architecture',
                'description' => 'Spécifications techniques, contrats de données et modèles de sécurité.',
            ],
        ];

        foreach ($sourcesInitiales as $s) {
            $this->enregistrerSource(
                nom: $s['nom'],
                type: $s['type'],
                origine: $s['origine'],
                description: $s['description']
            );
        }
    }

    /**
     * Enregistre et initialise l'intégralité des sources Markdown du projet dans le Knowledge Hub.
     *
     * @return Collection<int, AiSourceConnaissance>
     */
    public function initialiserToutesLesSources(): Collection
    {
        // 1. Initialiser les fondamentales
        $this->initialiserSourcesFondamentales();

        // 2. Sources étendues couvrant l'intégralité des connaissances du projet
        $sourcesEtendues = [
            [
                'nom' => 'Conventions & Standards de Développement',
                'type' => 'technical_documentation',
                'origine' => 'AGENTS.md',
                'description' => 'Standards OpenCode, conventions Laravel en français, architecture modulaire et sécurité.',
            ],
            [
                'nom' => 'Directives d’Ingénierie & Qualité',
                'type' => 'technical_documentation',
                'origine' => 'CLAUDE.md',
                'description' => 'Directives de développement, règles de tests automatisés et conformité du code.',
            ],
            [
                'nom' => 'Manuel Opérationnel UniRegistre',
                'type' => 'internal_document',
                'origine' => 'MANUEL.md',
                'description' => 'Guide opérationnel d’utilisation de la plateforme pour agents de scolarité et administrateurs.',
            ],
            [
                'nom' => 'Présentation Globale & Démarrage',
                'type' => 'technical_documentation',
                'origine' => 'README.md',
                'description' => 'Présentation générale du projet UniRegistre, architecture modulaire et prérequis système.',
            ],
            [
                'nom' => 'Spécifications Expérience Intelligente Inscription',
                'type' => 'business_procedure',
                'origine' => 'LOOP_Inscription_Etudiants_Experience_Intelligente.md',
                'description' => 'Cahier des charges du parcours d’inscription fluide, OCR des pièces d’identité et contrôles d’éligibilité.',
            ],
            [
                'nom' => 'Spécifications Portails Enseignant & Étudiant',
                'type' => 'business_procedure',
                'origine' => 'LOOP_Portails_Enseignant_Etudiant_Laravel.md',
                'description' => 'Spécifications fonctionnelles et techniques des espaces Enseignant et Étudiant.',
            ],
            [
                'nom' => 'Gestion Académique Avancée LMD',
                'type' => 'business_procedure',
                'origine' => 'gestion_academique_avancee_LMD_LOOPS.md',
                'description' => 'Règles métier approfondies du cycle LMD : crédits ECTS, compensation semestrielle et délibérations.',
            ],
            [
                'nom' => 'Répertoire Prompts Métier LMD',
                'type' => 'business_procedure',
                'origine' => 'prompts_gestion_academique_avancee_LMD_LOOPS.md',
                'description' => 'Cas d’usage, requêtes types et scénarios de simulation pour la scolarité et les jurys.',
            ],
            [
                'nom' => 'Planification & Emplois du Temps',
                'type' => 'business_procedure',
                'origine' => 'loop_emploi.md',
                'description' => 'Règles et contraintes d’affectation des créneaux, gestion des salles et emplois du temps.',
            ],
            [
                'nom' => 'Spécifications Inception & Retrospectives',
                'type' => 'internal_document',
                'origine' => 'doc',
                'description' => 'Vision produit North Star, spécifications de phase, analyse d’écrans et rétrospectives.',
            ],
            [
                'nom' => 'Compétences & Bonnes Pratiques de Développement',
                'type' => 'technical_documentation',
                'origine' => '.agents/skills',
                'description' => 'Référentiel des compétences expertes : règles Laravel, tests, Tailwind CSS, UI/UX et design system.',
            ],
            [
                'nom' => 'Instructions & Compétences OpenCode',
                'type' => 'technical_documentation',
                'origine' => '.opencode',
                'description' => 'Rôles des agents spécialisés, commandes de maintenance et skills de revue de code.',
            ],
            [
                'nom' => 'Procédures d’Importation Promotions',
                'type' => 'business_procedure',
                'origine' => 'salima_import_csv_2026_2027/README_IMPORT.md',
                'description' => 'Guide technique et format des fichiers CSV pour l’importation massive des promotions étudiantes.',
            ],
        ];

        foreach ($sourcesEtendues as $s) {
            $this->enregistrerSource(
                nom: $s['nom'],
                type: $s['type'],
                origine: $s['origine'],
                description: $s['description']
            );
        }

        return $this->sourcesActives();
    }

    /**
     * Synchronise toutes les sources actives du Knowledge Hub.
     *
     * @return array{total_sources: int, succes: int, echecs: int, documents: int, morceaux: int, details: array<int, array<string, mixed>>}
     */
    public function synchroniserToutesLesSources(bool $forcer = false): array
    {
        $sources = $this->sourcesActives();
        $totalDocs = 0;
        $totalMorceaux = 0;
        $succes = 0;
        $echecs = 0;
        $details = [];

        foreach ($sources as $source) {
            $res = $this->synchroniserSource($source, $forcer);
            if ($res['succes']) {
                $succes++;
                $totalDocs += $res['documents'];
                $totalMorceaux += $res['morceaux'];
            } else {
                $echecs++;
            }
            $details[] = [
                'source_id' => $source->id,
                'nom' => $source->nom,
                'resultat' => $res,
            ];
        }

        return [
            'total_sources' => $sources->count(),
            'succes' => $succes,
            'echecs' => $echecs,
            'documents' => $totalDocs,
            'morceaux' => $totalMorceaux,
            'details' => $details,
        ];
    }
}

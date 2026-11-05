<?php

namespace App\Support\Iam;

use App\Modules\AiAssistant\Support\PermissionsAi;
use App\Support\Permissions;

/**
 * Catalogue canonique FÉDÉRÉ des permissions (LOOP 43-BIS).
 *
 * Source de vérité des CODES : les matrices existantes
 * (`App\Support\Permissions::TOUTES` et, en lecture seule,
 * `App\Modules\AiAssistant\Support\PermissionsAi::TOUTES`). Ce catalogue
 * ne crée aucune permission : il ajoute des MÉTADONNÉES (module,
 * ressource, action, criticité, MFA, dépendances) et une hiérarchie
 * d'affichage.
 *
 * Une permission inconnue du code ne peut donc jamais être rendue
 * exécutable depuis l'interface : `existe()` est la seule porte.
 */
final class CataloguePermissions
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cacheMetadonnees = null;

    /** @var array<int, string>|null */
    private static ?array $cacheToutes = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cacheHierarchie = null;

    /** Libellés de modules (repli : code humanisé). */
    private const MODULES = [
        'archives' => 'Archives académiques',
        'documents' => 'Documents',
        'notes' => 'Notes',
        'notes_eval' => 'Notes d’évaluation',
        'history' => 'Historique',
        'student_archive' => 'Archive étudiant',
        'configuration' => 'Configuration',
        'admissions' => 'Admissions',
        'exports' => 'Exports personnels',
        'attendance' => 'Présences',
        'student_dossiers' => 'Dossiers étudiants',
        'student_documents' => 'Documents étudiants',
        'demandes' => 'Demandes en ligne',
        'schedules' => 'Emplois du temps',
        'enseignants' => 'Enseignants',
        'salles' => 'Salles',
        'groupes' => 'Groupes',
        'affectations' => 'Affectations',
        'data' => 'Import / Export',
        'personnel' => 'Personnel',
        'enseignements' => 'Enseignements',
        'referentiel' => 'Référentiel LMD',
        'maquettes' => 'Maquettes',
        'evaluations' => 'Évaluations',
        'sessions_examen' => 'Sessions d’examen',
        'epreuves' => 'Épreuves',
        'surveillances' => 'Surveillances',
        'resultats' => 'Résultats',
        'deliberations' => 'Délibérations',
        'annonces' => 'Annonces',
        'calendriers' => 'Calendriers',
        'statistiques' => 'Statistiques',
        'settings' => 'Paramètres',
        'users' => 'Comptes utilisateurs',
        'roles' => 'Rôles',
        'permissions' => 'Permissions',
        'mail' => 'Messagerie',
        'system' => 'Système et sauvegardes',
        'cockpit' => 'Cockpit',
        'tasks' => 'Tâches',
        'it' => 'Centre de services IT',
        'alumni' => 'Alumni',
        'campagnes' => 'Campagnes',
        'qualite' => 'Qualité des données',
        'finances' => 'Finances',
        'document' => 'Centre documentaire',
        'document_batch' => 'Lots documentaires',
        'document_template' => 'Modèles documentaires',
        'document_type' => 'Types documentaires',
        'import' => 'Import',
        'export' => 'Export',
        'import_template' => 'Modèles d’import',
        'search' => 'Recherche universelle',
        'parcours' => 'Parcours académique',
        'regles' => 'Règles académiques',
        'recours' => 'Recours académiques',
        'versioning' => 'Versionnement',
        'sentinel' => 'Sentinel',
        'assistant_ia' => 'Assistant IA',
        'decrochage' => 'Prévention du décrochage',
        'gouvernance' => 'Gouvernance et conformité',
        'iam' => 'Gouvernance IAM',
        'ai' => 'Assistant IA (module local)',
    ];

    /** Libellés d'actions (repli : code humanisé). */
    private const ACTIONS = [
        'view' => 'Consulter',
        'consulter' => 'Consulter',
        'create' => 'Créer',
        'creer' => 'Créer',
        'update' => 'Modifier',
        'modifier' => 'Modifier',
        'delete' => 'Supprimer',
        'supprimer' => 'Supprimer',
        'manage' => 'Gérer',
        'gerer' => 'Gérer',
        'validate' => 'Valider',
        'valider' => 'Valider',
        'publish' => 'Publier',
        'publier' => 'Publier',
        'approve' => 'Approuver',
        'approuver' => 'Approuver',
        'reject' => 'Rejeter',
        'rejeter' => 'Rejeter',
        'submit' => 'Soumettre',
        'soumettre' => 'Soumettre',
        'cancel' => 'Annuler',
        'annuler' => 'Annuler',
        'export' => 'Exporter',
        'exporter' => 'Exporter',
        'import' => 'Importer',
        'importer' => 'Importer',
        'download' => 'Télécharger',
        'telecharger' => 'Télécharger',
        'upload' => 'Déposer',
        'deposer' => 'Déposer',
        'assign' => 'Attribuer',
        'attribuer' => 'Attribuer',
        'revoke' => 'Révoquer',
        'revoquer' => 'Révoquer',
        'lock' => 'Verrouiller',
        'verrouiller' => 'Verrouiller',
        'unlock' => 'Déverrouiller',
        'deverrouiller' => 'Déverrouiller',
        'restore' => 'Restaurer',
        'restaurer' => 'Restaurer',
        'execute' => 'Exécuter',
        'executer' => 'Exécuter',
        'rollback' => 'Annuler / restaurer',
        'deploy' => 'Déployer',
        'verify' => 'Vérifier',
        'verifier' => 'Vérifier',
        'certify' => 'Certifier',
        'prepare' => 'Préparer',
        'calculer' => 'Calculer',
        'calculate' => 'Calculer',
        'corriger' => 'Corriger',
        'correct' => 'Corriger',
        'generate' => 'Générer',
        'generer' => 'Générer',
        'issue' => 'Émettre',
        'print' => 'Imprimer',
        'send' => 'Envoyer',
        'traiter' => 'Traiter',
        'traiter_' => 'Traiter',
        'regles' => 'Règles',
        'rediger' => 'Rédiger',
        'retirer' => 'Retirer',
        'auditer' => 'Auditer',
        'instruire' => 'Instruire',
        'decider' => 'Décider',
        'appliquer' => 'Appliquer',
        'recevoir' => 'Recevoir',
        'sensitive' => 'Données sensibles',
        'medical' => 'Médical',
        'disciplinary' => 'Disciplinaire',
        'sessions' => 'Sessions',
        'history' => 'Historique',
        'timeline' => 'Chronologie',
        'academic' => 'Académique',
        'pdf' => 'PDF',
        'audit' => 'Audit',
        'settings' => 'Paramètres',
        'diagnostics' => 'Diagnostics',
        'backups' => 'Sauvegardes',
        'create_' => 'Créer',
    ];

    /** Permissions critiques : risque d'escalade ou d'impact institutionnel. */
    private const CRITIQUES = [
        'iam.consulter', 'iam.roles.gerer', 'iam.affectations.gerer',
        'iam.affectations.approuver', 'iam.delegations.gerer',
        'iam.audit.consulter', 'iam.cockpit.consulter', 'iam.recertifier',
        'iam.urgence.declencher', 'iam.urgence.approuver', 'iam.parametres.gerer',
        'users.create', 'users.update', 'users.suspend', 'users.sessions.revoke',
        'roles.manage', 'permissions.assign',
        'configuration.manage', 'settings.manage', 'settings.restore',
        'system.backups.restore', 'system.backups.delete',
        'notes.unlock', 'notes.delete',
        'resultats.validate', 'resultats.publish',
        'deliberations.validate', 'deliberations.publish', 'deliberations.lock',
        'evaluations.publish', 'evaluations.approve', 'evaluations.certify',
        'finances.confirm', 'finances.receipts.verify',
        'document.revoke', 'document.approve',
        'import.rollback', 'import.approve', 'export.sensitive',
        'versioning.approve', 'versioning.deploy', 'versioning.rollback',
        'assistant_ia.action_approuver', 'assistant_ia.admin',
        'regles.approuver', 'regles.publier', 'regles.retirer',
        'recours.decider', 'recours.annuler',
    ];

    /** Permissions élevées : écriture sensible sans être critique. */
    private const ELEVEES = [
        'archives.delete', 'documents.delete', 'notes_eval.correct',
        'notes_eval.lock', 'notes_eval.validate', 'notes_eval.import',
        'sessions_examen.lock', 'epreuves.cancel', 'surveillances.manage',
        'import.execute', 'data.import', 'data.export',
        'student_archive.sensitive.manage', 'student_archive.medical.download',
        'student_archive.disciplinary.download', 'student_archive.export',
        'personnel.manage', 'search.sensitive', 'search.admin',
        'mail.settings.manage', 'mail.send', 'settings.diagnostics.view',
    ];

    /**
     * Dépendances déclarées. Une permission ne peut être accordée sans
     * ses dépendances (contrôle serveur, jamais un ajout silencieux).
     *
     * @var array<string, array<int, string>>
     */
    private const DEPENDANCES = [
        'iam.roles.gerer' => ['iam.consulter'],
        'iam.affectations.gerer' => ['iam.consulter'],
        'iam.affectations.approuver' => ['iam.consulter'],
        'iam.delegations.gerer' => ['iam.consulter'],
        'iam.audit.consulter' => ['iam.consulter'],
        'iam.cockpit.consulter' => ['iam.consulter'],
        'iam.recertifier' => ['iam.consulter', 'iam.affectations.gerer'],
        'iam.urgence.declencher' => ['iam.consulter'],
        'iam.urgence.approuver' => ['iam.consulter'],
        'iam.parametres.gerer' => ['iam.consulter'],
        'roles.manage' => ['roles.view'],
        'permissions.assign' => ['permissions.view'],
        'users.create' => ['users.view'],
        'users.update' => ['users.view'],
        'users.suspend' => ['users.view'],
        'users.sessions.revoke' => ['users.view'],
        'archives.create' => ['archives.view'],
        'archives.update' => ['archives.view'],
        'archives.delete' => ['archives.view'],
        'notes.create' => ['notes.view'],
        'notes.update' => ['notes.view'],
        'notes.delete' => ['notes.view'],
        'notes.unlock' => ['notes.update'],
        'notes_eval.enter' => ['notes_eval.view'],
        'notes_eval.update' => ['notes_eval.view'],
        'notes_eval.validate' => ['notes_eval.view'],
        'notes_eval.lock' => ['notes_eval.validate'],
        'notes_eval.correct' => ['notes_eval.view'],
        'resultats.calculate' => ['resultats.view'],
        'resultats.validate' => ['resultats.view'],
        'resultats.publish' => ['resultats.validate'],
        'deliberations.prepare' => ['deliberations.view'],
        'deliberations.validate' => ['deliberations.prepare'],
        'deliberations.publish' => ['deliberations.validate'],
        'deliberations.lock' => ['deliberations.validate'],
        'evaluations.publish' => ['evaluations.view'],
        'evaluations.certify' => ['evaluations.view'],
        'evaluations.approve' => ['evaluations.view'],
        'finances.confirm' => ['finances.view'],
        'finances.receipts.verify' => ['finances.view'],
        'system.backups.restore' => ['system.backups.view'],
        'system.backups.delete' => ['system.backups.view'],
        'system.backups.verify' => ['system.backups.view'],
        'system.backups.create' => ['system.backups.view'],
        'settings.history.view' => ['settings.view'],
        'settings.restore' => ['settings.manage'],
        'settings.diagnostics.view' => ['settings.view'],
        'import.execute' => ['import.view'],
        'import.rollback' => ['import.execute'],
        'import.approve' => ['import.view'],
        'document.approve' => ['document.view'],
        'document.issue' => ['document.view'],
        'document.revoke' => ['document.view'],
        'versioning.create' => ['versioning.view'],
        'versioning.approve' => ['versioning.view'],
        'versioning.deploy' => ['versioning.approve'],
        'versioning.rollback' => ['versioning.deploy'],
        'versioning.manage' => ['versioning.view'],
        'regles.rediger' => ['regles.consulter'],
        'regles.soumettre' => ['regles.rediger'],
        'regles.approuver' => ['regles.soumettre'],
        'regles.publier' => ['regles.approuver'],
        'regles.retirer' => ['regles.publier'],
        'regles.auditer' => ['regles.consulter'],
        'recours.instruire' => ['recours.consulter'],
        'recours.decider' => ['recours.instruire'],
        'recours.appliquer' => ['recours.decider'],
        'recours.annuler' => ['recours.decider'],
        'recours.auditer' => ['recours.consulter'],
        'student_archive.sensitive.view' => ['student_archive.view'],
        'student_archive.sensitive.manage' => ['student_archive.sensitive.view'],
        'student_archive.medical.view' => ['student_archive.sensitive.view'],
        'student_archive.medical.download' => ['student_archive.medical.view'],
        'student_archive.disciplinary.view' => ['student_archive.sensitive.view'],
        'student_archive.disciplinary.download' => ['student_archive.disciplinary.view'],
    ];

    /** Permissions exigeant une MFA active lorsqu'elles sont évaluées. */
    private const MFA = [
        'iam.roles.gerer', 'iam.affectations.gerer', 'iam.affectations.approuver',
        'iam.delegations.gerer', 'iam.recertifier', 'iam.urgence.declencher',
        'iam.urgence.approuver', 'iam.parametres.gerer',
        'users.create', 'users.update', 'users.suspend', 'users.sessions.revoke',
        'roles.manage', 'permissions.assign', 'configuration.manage',
        'settings.manage', 'settings.restore', 'system.backups.restore',
        'system.backups.delete', 'notes.unlock', 'deliberations.publish',
        'resultats.publish', 'finances.confirm', 'export.sensitive',
    ];

    /**
     * @return array<int, string>
     */
    public static function toutes(): array
    {
        if (self::$cacheToutes === null) {
            self::$cacheToutes = array_values(array_unique([
                ...Permissions::TOUTES,
                ...PermissionsAi::TOUTES,
            ]));
        }

        return self::$cacheToutes;
    }

    /**
     * @return array<int, string>
     */
    public static function centrales(): array
    {
        return Permissions::TOUTES;
    }

    /**
     * @return array<int, string>
     */
    public static function federees(): array
    {
        return PermissionsAi::TOUTES;
    }

    public static function existe(string $code): bool
    {
        return in_array($code, self::toutes(), true);
    }

    public static function source(string $code): string
    {
        return in_array($code, PermissionsAi::TOUTES, true) ? 'module_ai' : 'central';
    }

    /**
     * Une permission est administrable par la gouvernance IAM si elle est
     * centrale, non réservée au système (module IAM) et non fédérée en
     * lecture seule.
     */
    public static function administrable(string $code): bool
    {
        if (! self::existe($code) || self::source($code) !== 'central') {
            return false;
        }

        $module = self::moduleDe($code);

        return ! in_array($module, (array) config('iam.modules_reserves', ['iam']), true)
            && ! in_array($module, (array) config('iam.modules_federes_lecture_seule', ['ai']), true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function metadonnees(string $code): ?array
    {
        if (! self::existe($code)) {
            return null;
        }

        $toutes = self::metadonneesToutes();

        return $toutes[$code] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function metadonneesToutes(): array
    {
        if (self::$cacheMetadonnees !== null) {
            return self::$cacheMetadonnees;
        }

        $metadonnees = [];

        foreach (self::toutes() as $code) {
            $module = self::moduleDe($code);
            $segments = explode('.', $code);
            $action = (string) end($segments);
            $ressource = count($segments) > 2 ? $segments[1] : $module;
            $criticite = in_array($code, self::CRITIQUES, true)
                ? 'critique'
                : (in_array($code, self::ELEVEES, true) ? 'elevee' : 'normale');
            $administrable = self::administrable($code);

            $metadonnees[$code] = [
                'code' => $code,
                'libelle' => self::libelleAction($action).' — '.self::libelleModule($module),
                'description' => sprintf(
                    'Permet de %s dans « %s » (%s).',
                    mb_strtolower(self::libelleAction($action)),
                    self::libelleModule($module),
                    self::libelleRessource($module, $ressource),
                ),
                'module' => $module,
                'module_libelle' => self::libelleModule($module),
                'ressource' => $ressource,
                'ressource_libelle' => self::libelleRessource($module, $ressource),
                'action' => $action,
                'action_libelle' => self::libelleAction($action),
                'criticite' => $criticite,
                'sensible' => $criticite !== 'normale',
                'mfa_obligatoire' => in_array($code, self::MFA, true),
                'dependances' => self::DEPENDANCES[$code] ?? [],
                'administrable' => $administrable,
                'reserve_systeme' => ! $administrable,
                'source' => self::source($code),
            ];
        }

        return self::$cacheMetadonnees = $metadonnees;
    }

    /**
     * Hiérarchie d'affichage : module → ressource → actions.
     *
     * @param  array<int, string>|null  $codes  Restreindre aux codes fournis.
     * @return array<int, array<string, mixed>>
     */
    public static function hierarchie(?array $codes = null): array
    {
        $filtre = $codes !== null ? array_flip($codes) : null;

        if (self::$cacheHierarchie !== null && $filtre === null) {
            return self::$cacheHierarchie;
        }

        $modules = [];

        foreach (self::metadonneesToutes() as $code => $meta) {
            if ($filtre !== null && ! isset($filtre[$code])) {
                continue;
            }

            $module = $meta['module'];
            $ressource = $meta['ressource'];

            $modules[$module] ??= [
                'code' => $module,
                'libelle' => $meta['module_libelle'],
                'modules_ressources' => [],
                'nb_permissions' => 0,
                'nb_sensibles' => 0,
            ];

            $modules[$module]['modules_ressources'][$ressource] ??= [
                'code' => $ressource,
                'libelle' => $meta['ressource_libelle'],
                'actions' => [],
            ];

            $modules[$module]['modules_ressources'][$ressource]['actions'][] = $meta;
            $modules[$module]['nb_permissions']++;
            if ($meta['sensible']) {
                $modules[$module]['nb_sensibles']++;
            }
        }

        $resultat = [];
        foreach ($modules as $module) {
            $module['modules_ressources'] = array_values($module['modules_ressources']);
            usort($module['modules_ressources'], fn ($a, $b) => strcmp($a['libelle'], $b['libelle']));
            $resultat[] = $module;
        }

        usort($resultat, fn ($a, $b) => strcmp($a['libelle'], $b['libelle']));

        if ($filtre === null) {
            self::$cacheHierarchie = $resultat;
        }

        return $resultat;
    }

    public static function criticite(string $code): string
    {
        return (string) (self::metadonnees($code)['criticite'] ?? 'inconnue');
    }

    /**
     * Raccourci booléen utilisé par les contrôleurs pour aiguiller une
     * modification vers le circuit à quatre yeux.
     */
    public static function critique(string $code): bool
    {
        return self::criticite($code) === 'critique';
    }

    public static function estSensible(string $code): bool
    {
        return (bool) (self::metadonnees($code)['sensible'] ?? false);
    }

    public static function exigeMfa(string $code): bool
    {
        return (bool) (self::metadonnees($code)['mfa_obligatoire'] ?? false);
    }

    /**
     * @return array<int, string>
     */
    public static function dependances(string $code): array
    {
        return (array) (self::metadonnees($code)['dependances'] ?? []);
    }

    /**
     * Dépendances manquantes pour un ensemble de permissions.
     *
     * @param  array<int, string>  $codes
     * @return array<int, string>
     */
    public static function dependancesManquantes(array $codes): array
    {
        $ensemble = array_flip($codes);
        $manquantes = [];

        foreach ($codes as $code) {
            foreach (self::dependances($code) as $dependance) {
                if (! isset($ensemble[$dependance])) {
                    $manquantes[$dependance] = true;
                }
            }
        }

        return array_keys($manquantes);
    }

    /**
     * @return array<int, string>
     */
    public static function critiques(): array
    {
        return array_values(array_filter(
            self::toutes(),
            fn (string $code): bool => self::criticite($code) === 'critique',
        ));
    }

    /**
     * @return array<int, string>
     */
    public static function sensibles(): array
    {
        return array_values(array_filter(self::toutes(), fn (string $code): bool => self::estSensible($code)));
    }

    public static function libelleModule(string $module): string
    {
        return self::MODULES[$module] ?? self::humaniser($module);
    }

    public static function libelleAction(string $action): string
    {
        return self::ACTIONS[$action] ?? self::humaniser($action);
    }

    public static function libelleRessource(string $module, string $ressource): string
    {
        if ($ressource === $module) {
            return self::libelleModule($module);
        }

        return self::MODULES[$ressource] ?? self::humaniser($ressource);
    }

    private static function moduleDe(string $code): string
    {
        return explode('.', $code)[0];
    }

    private static function humaniser(string $valeur): string
    {
        $texte = str_replace(['_', '-'], ' ', $valeur);
        $texte = trim($texte);

        return $texte === '' ? $valeur : mb_convert_case($texte, MB_CASE_TITLE, 'UTF-8');
    }
}

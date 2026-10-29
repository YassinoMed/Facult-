<?php

namespace App\Support;

/**
 * Matrice RBAC : rôle → permissions du module Archives Académiques.
 * Les Gates sont enregistrés depuis cette matrice dans AppServiceProvider.
 */
final class Permissions
{
    public const ROLES = [
        'super_admin',
        'admin_scolarite',
        'agent_scolarite',
        'consultation',
        'admin_faculte',
        // §2.7 — séparation des pouvoirs PAR RÔLE : un rôle dédié par étape
        // de décision du circuit de gouvernance des examens. Ils permettent
        // une attribution distincte sans retirer au rôle d'administration
        // scolaire ses autres prérogatives (compatibilité des parcours
        // existants). `php artisan examens:separation-roles` vérifie la
        // faisabilité et propose l'attribution.
        'certificateur_qualite',
        'direction_etudes',
        'autorite_publication',
        // Lot 19 — Rôle d'audit DISTINCT (exigé par le cahier gouvernance) :
        // lecture seule sur les journaux et la conformité, aucun pouvoir d'action.
        'auditeur',
    ];

    /** Toutes les permissions connues du système. */
    public const TOUTES = [
        'archives.view',
        'archives.create',
        'archives.update',
        'archives.delete',

        'documents.view',
        'documents.upload',
        'documents.download',
        'documents.delete',

        'notes.view',
        'notes.create',
        'notes.update',
        'notes.delete',
        'notes.unlock',

        'history.view',

        // Module StudentArchive : dossier historique longitudinal (vue
        // consolidée, sans table métier). Détail : documents/medical.
        'student_archive.view',
        'student_archive.timeline.view',
        'student_archive.academic.view',
        'student_archive.documents.view',
        'student_archive.documents.download',
        'student_archive.export',
        'student_archive.pdf',
        'student_archive.audit.view',

        // Phase F : dossiers sensibles (médical/disciplinaire). Jamais
        // accordées par défaut aux profils opérationnels (Archive ≠ Sensitive).
        'student_archive.sensitive.view',
        'student_archive.sensitive.manage',
        'student_archive.medical.view',
        'student_archive.medical.download',
        'student_archive.disciplinary.view',
        'student_archive.disciplinary.download',

        'configuration.manage',

        // Admissions (roadmap « admission Master », 04/10/2026) : actes
        // d'admission externe, consommés par la progression LMD.
        'admissions.view',
        'admissions.manage',

        'exports.personal',

        // Module Attendance (§12) : déclarées ici pour hériter des Gates
        // automatiques d'AppServiceProvider, sans toucher au cœur.
        'attendance.view',
        'attendance.create',
        'attendance.delete',
        'attendance.export',
        'attendance.templates.manage',
        'attendance.templates.use',

        // Module StudentDossiers (§8) : même mécanisme.
        'student_dossiers.view',
        'student_dossiers.create',
        'student_dossiers.update',
        'student_dossiers.delete',
        'student_dossiers.pdf',

        'student_documents.view',
        'student_documents.upload',
        'student_documents.download',
        'student_documents.validate',
        'student_documents.reject',
        'student_documents.delete',
        'student_documents.replace',
        'student_documents.audit',

        // Module OnlineRequests (§14) : même mécanisme (Gates automatiques).
        'demandes.view',
        'demandes.create',
        'demandes.submit',
        'demandes.cancel',
        'demandes.process',
        'demandes.assign',
        'demandes.approve',
        'demandes.reject',
        'demandes.request_documents',
        'demandes.documents.view',
        'demandes.documents.upload',
        'demandes.documents.download',
        'demandes.pdf',
        'demandes.audit',

        // Module Schedule : même mécanisme (Gates automatiques).
        'schedules.view',
        'schedules.manage',

        // Back-office : référentiels académiques.
        'enseignants.view',
        'enseignants.manage',
        'salles.view',
        'salles.manage',
        'groupes.view',
        'groupes.manage',
        'affectations.override',

        // Centre Import/Export unifié.
        'data.import',
        'data.export',

        // Phase 2 — Population : profils du personnel (super_admin).
        'personnel.manage',

        // Module Enseignement (§46) : même mécanisme (Gates automatiques).
        'enseignements.view',
        'enseignements.manage',
        'enseignements.valider',
        'enseignements.import',
        'enseignements.export',

        // Module Referentiel LMD (§27) : même mécanisme (Gates automatiques).
        'referentiel.view',
        'referentiel.manage',
        'referentiel.import',
        'referentiel.export',
        'maquettes.approve',

        // Phase 4 — Évaluation académique (§26-35) : même mécanisme.
        'evaluations.view',
        'evaluations.create',
        'evaluations.update',
        'evaluations.delete',
        'evaluations.publish',
        // Séparation des responsabilités du planning (§2.7) : la
        // certification technique et l'approbation métier sont des
        // permissions distinctes de la publication.
        'evaluations.certify',
        'evaluations.approve',

        'sessions_examen.view',
        'sessions_examen.create',
        'sessions_examen.update',
        'sessions_examen.lock',

        'epreuves.view',
        'epreuves.create',
        'epreuves.update',
        'epreuves.cancel',

        'surveillances.view',
        'surveillances.assign',
        'surveillances.manage',

        'notes_eval.view',
        'notes_eval.enter',
        'notes_eval.update',
        'notes_eval.validate',
        'notes_eval.lock',
        'notes_eval.correct',
        'notes_eval.import',
        'notes_eval.export',

        // Saisie anonyme par compostage (§12-§13) : génération séparée
        // de la résolution d'identité (jamais accordée aux enseignants).
        'notes.compostage.generate',
        'notes.compostage.resolve',
        'notes_eval.compostage.resolve',
        'notes.compostage.resolve',

        'resultats.view',
        'resultats.calculate',
        'resultats.validate',
        'resultats.publish',

        'deliberations.view',
        'deliberations.prepare',
        'deliberations.manage',
        'deliberations.validate',
        'deliberations.publish',
        'deliberations.lock',

        // Phases 5-6 — Services & administration : même mécanisme.
        'annonces.view',        'annonces.create',
        'annonces.update',
        'annonces.delete',
        'annonces.publish',

        'calendriers.view',
        'calendriers.manage',

        'attendance.justify',

        'statistiques.view',
        'statistiques.export',

        'settings.view',
        'settings.manage',
        'settings.history.view',
        'settings.restore',
        'settings.diagnostics.view',

        // Centre d'administration (LOOP J) : granularité comptes, rôles,
        // messagerie, audit. Assignées au super_admin seul (matrice) —
        // tout élargissement exige une approbation explicite.
        'users.view',
        'users.create',
        'users.update',
        'users.suspend',
        'users.sessions.revoke',
        'roles.view',
        'roles.manage',
        'permissions.view',
        'permissions.assign',
        'mail.settings.manage',
        'mail.test.send',
        'mail.logs.view',
        'mail.retry',
        'mail.send',
        'mail.modeles.view',
        'mail.modeles.gerer',
        'mail.modeles.publier',
        'mail.modeles.themes',
        'mail.test.modele',

        // Sauvegardes database : granularité view ≠ create ≠ verify
        // ≠ restore ≠ delete ≠ settings (restore/delete/settings :
        // super_admin seul, jamais auto-attribués).
        'system.backups.view',
        'system.backups.create',
        'system.backups.verify',
        'system.backups.restore',
        'system.backups.delete',
        'system.backups.settings',

        // Phases 7-9 — Cockpit & tâches : même mécanisme.
        'cockpit.view',

        'tasks.view',
        'tasks.create',
        'tasks.update',
        'tasks.assign',
        'tasks.complete',
        'tasks.cancel',

        // Lot 11 — Centre de services IT : vue métier au-dessus de Tasks.
        // `it.categories` est distinct de `it.manage` : un agent de support
        // traite les tickets sans modifier le catalogue global.
        'it.view',
        'it.create',
        'it.manage',
        'it.categories',

        // Lot 17 — Portail alumni : registre officiel de diplomation,
        // annuaire sous consentement et agrégats k-anonymes.
        // `alumni.import` écrit le registre ; `alumni.manage` génère les
        // liens de consentement et voit les contacts explicitement partagés.
        'alumni.view',
        'alumni.import',
        'alumni.manage',

        // Phase 15 — Campagnes : même mécanisme.
        'campagnes.view',
        'campagnes.manage',

        // Phase 19 — Qualité des données : même mécanisme.
        'qualite.view',
        'qualite.manage',

        // Phase 23 — Finances universitaires : même mécanisme.
        'finances.view',
        'finances.manage',
        'finances.confirm',
        // LOOP-UNIREGISTRE-INSCRIPTIONS-PAIEMENTS-01 : décision sur les
        // reçus d'inscription, distincte de la confirmation d'un paiement
        // de facture (moindre privilège).
        'finances.receipts.verify',

        // Phase 16 — Centre documentaire : même mécanisme.
        'document.view', 'document.generate', 'document.issue',
        'document.approve', 'document.revoke', 'document.download',
        'document.print', 'document.send',
        'document_batch.run', 'document_batch.cancel',
        'document_template.view', 'document_template.edit', 'document_template.publish',
        'document_type.manage', 'document.verify_stats',

        // Phase 17 — Centre import/export : même mécanisme.
        'import.view', 'import.create', 'import.validate', 'import.execute',
        'import.rollback', 'import.approve',
        'export.view', 'export.create', 'export.sensitive',
        'import_template.manage',

        // Phase 18 — Recherche universelle : même mécanisme.
        'search.use', 'search.advanced', 'search.sensitive',
        'search.admin', 'search.history.manage',

        // Module Parcours académique avancé LMD : même mécanisme.
        'parcours.view', 'parcours.simuler', 'parcours.gerer',
        'parcours.comparer', 'parcours.regles',

        // Module Versioning : gestion interne des versions & releases
        'versioning.view',
        'versioning.create',
        'versioning.approve',
        'versioning.deploy',
        'versioning.rollback',
        'versioning.manage',

        // Module Sentinel : surveillance transverse (même mécanisme).
        'sentinel.view',
        'sentinel.manage',

        // Module Assistant IA (UniRegistre AI) : permissions granulaires.
        'assistant_ia.view',
        'assistant_ia.mode_developpeur',
        'assistant_ia.mode_metier',
        'assistant_ia.recherche_doc',
        'assistant_ia.sources_restreintes',
        'assistant_ia.action_proposer',
        'assistant_ia.action_approuver',
        'assistant_ia.audit.view',
        'assistant_ia.admin',
        'assistant_ia.fournisseur_distant',

        // Module Prévention du décrochage (Lot 15) : clés ADDITIVES du registre
        // (aucune clé de rôle supprimée ni réordonnée ; précédent assistant_ia.*).
        'decrochage.view',
        'decrochage.regles',
        'decrochage.traiter',

        // Module Gouvernance et conformité (Lot 19) : lecture des vues
        // gouvernance (accès sensibles unifiés, journal des permissions,
        // conformité de rétention, journal des accès aux journaux).
        'gouvernance.audit',
    ];

    /**
     * Matrice rôle → permissions.
     */
    private const MATRICE = [
        'super_admin' => self::TOUTES,

        'admin_scolarite' => [
            'archives.view', 'archives.create', 'archives.update', 'archives.delete',
            'documents.view', 'documents.upload', 'documents.download', 'documents.delete',
            'notes.view', 'notes.create', 'notes.update', 'notes.delete', 'notes.unlock',
            'history.view',
            'admissions.view', 'admissions.manage',
            'student_archive.view', 'student_archive.timeline.view', 'student_archive.academic.view',
            'student_archive.documents.view', 'student_archive.documents.download',
            'student_archive.export', 'student_archive.pdf', 'student_archive.audit.view',
            'student_archive.sensitive.view', 'student_archive.sensitive.manage',
            'student_archive.medical.view', 'student_archive.medical.download',
            'student_archive.disciplinary.view', 'student_archive.disciplinary.download',
            'configuration.manage',
            'exports.personal',
            'attendance.view', 'attendance.create', 'attendance.delete', 'attendance.export',
            'attendance.templates.manage', 'attendance.templates.use',
            'student_dossiers.view', 'student_dossiers.create', 'student_dossiers.update', 'student_dossiers.delete', 'student_dossiers.pdf',
            'student_documents.view', 'student_documents.upload', 'student_documents.download',
            'student_documents.validate', 'student_documents.reject', 'student_documents.delete',
            'student_documents.replace', 'student_documents.audit',
            'demandes.view', 'demandes.create', 'demandes.submit', 'demandes.cancel',
            'demandes.process', 'demandes.assign', 'demandes.approve', 'demandes.reject',
            'demandes.request_documents', 'demandes.documents.view', 'demandes.documents.upload',
            'demandes.documents.download', 'demandes.pdf', 'demandes.audit',
            'schedules.view', 'schedules.manage',
            'enseignants.view', 'enseignants.manage',
            'salles.view', 'salles.manage',
            'groupes.view', 'groupes.manage', 'affectations.override',
            'data.import', 'data.export',
            'referentiel.view', 'referentiel.manage', 'referentiel.import', 'referentiel.export',
            'maquettes.approve',
            'enseignements.view', 'enseignements.manage', 'enseignements.valider',
            'enseignements.import', 'enseignements.export',
            'evaluations.view', 'evaluations.create', 'evaluations.update', 'evaluations.delete', 'evaluations.publish',
            'evaluations.certify', 'evaluations.approve',
            'sessions_examen.view', 'sessions_examen.create', 'sessions_examen.update', 'sessions_examen.lock',
            'epreuves.view', 'epreuves.create', 'epreuves.update', 'epreuves.cancel',
            'surveillances.view', 'surveillances.assign', 'surveillances.manage',
            'notes_eval.view', 'notes_eval.enter', 'notes_eval.update', 'notes_eval.validate', 'notes_eval.lock',
            'notes_eval.correct',
            'notes_eval.import', 'notes_eval.export',
            'notes.compostage.generate', 'notes.compostage.resolve', 'notes_eval.compostage.resolve', 'notes.compostage.resolve',
            'resultats.view', 'resultats.calculate', 'resultats.validate', 'resultats.publish',
            'deliberations.view', 'deliberations.prepare', 'deliberations.manage',
            'deliberations.validate', 'deliberations.publish', 'deliberations.lock',
            'annonces.view', 'annonces.create', 'annonces.update', 'annonces.delete', 'annonces.publish',
            'calendriers.view', 'calendriers.manage',
            'attendance.justify',
            'statistiques.view', 'statistiques.export',
            'cockpit.view',
            'tasks.view', 'tasks.create', 'tasks.update', 'tasks.assign', 'tasks.complete', 'tasks.cancel',
            'it.view', 'it.create', 'it.manage', 'it.categories',
            'alumni.view', 'alumni.import', 'alumni.manage',
            'campagnes.view', 'campagnes.manage',
            'qualite.view', 'qualite.manage',
            'finances.view', 'finances.manage', 'finances.confirm', 'finances.receipts.verify',
            'document.view', 'document.generate', 'document.issue',
            'document.approve', 'document.revoke', 'document.download',
            'document.print', 'document.send',
            'document_batch.run', 'document_batch.cancel',
            'document_template.view', 'document_template.edit', 'document_template.publish',
            'document_type.manage', 'document.verify_stats',
            'import.view', 'import.create', 'import.validate', 'import.execute',
            'import.rollback', 'import.approve',
            'export.view', 'export.create', 'export.sensitive',
            'import_template.manage',
            'search.use', 'search.advanced', 'search.sensitive',
            'search.admin', 'search.history.manage',
            'parcours.view', 'parcours.simuler', 'parcours.gerer',
            'parcours.comparer', 'parcours.regles',
            'versioning.view', 'versioning.create',
            'sentinel.view', 'sentinel.manage',
            'assistant_ia.view', 'assistant_ia.mode_metier', 'assistant_ia.recherche_doc',
            'assistant_ia.action_proposer', 'assistant_ia.action_approuver', 'assistant_ia.audit.view',
            'assistant_ia.fournisseur_distant',

            // Prévention du décrochage (Lot 15) : gestion complète du suivi.
            'decrochage.view', 'decrochage.regles', 'decrochage.traiter',
            'gouvernance.audit',
        ],

        'agent_scolarite' => [
            'archives.view', 'archives.create', 'archives.update',
            'documents.view', 'documents.upload', 'documents.download',
            'notes.view', 'notes.create', 'notes.update',
            'history.view',
            'student_archive.view', 'student_archive.timeline.view', 'student_archive.academic.view',
            'student_archive.documents.view', 'student_archive.documents.download',
            'attendance.view', 'attendance.create', 'attendance.templates.use',
            'student_dossiers.view', 'student_dossiers.create', 'student_dossiers.update', 'student_dossiers.pdf',
            'student_documents.view', 'student_documents.upload', 'student_documents.download',
            'student_documents.replace', 'student_documents.audit',
            'demandes.view', 'demandes.process', 'demandes.assign',
            'demandes.request_documents', 'demandes.documents.view', 'demandes.documents.upload',
            'demandes.documents.download', 'demandes.pdf', 'demandes.audit',
            'schedules.view',
            'enseignants.view', 'salles.view', 'groupes.view',
            'data.export',
            'referentiel.view',
            'enseignements.view',
            'evaluations.view',
            'sessions_examen.view',
            'epreuves.view',
            'surveillances.view',
            'notes_eval.view', 'notes_eval.enter',
            'resultats.view',
            'deliberations.view',
            'annonces.view',
            'calendriers.view',
            'statistiques.view',
            'cockpit.view',
            'tasks.view', 'tasks.create', 'tasks.update', 'tasks.complete',
            'it.view', 'it.create', 'it.manage',
            'alumni.view',
            'campagnes.view',
            'qualite.view',
            'sentinel.view',
            'document.view', 'document.generate', 'document.download', 'document.print',
            'import.view', 'import.create', 'import.validate',
            'export.view', 'export.create',
            'search.use',
            'parcours.view', 'parcours.simuler',
            'assistant_ia.view', 'assistant_ia.mode_metier', 'assistant_ia.recherche_doc',
            'decrochage.view',
        ],
        'consultation' => ['archives.view',
            'documents.view', 'documents.download',
            'notes.view',
            'history.view',
            'student_archive.view', 'student_archive.timeline.view', 'student_archive.academic.view',
            'student_archive.documents.view', 'student_archive.documents.download',
            'attendance.view',
            'student_dossiers.view', 'student_dossiers.pdf',
            'student_documents.view', 'student_documents.download', 'student_documents.audit',
            'demandes.view', 'demandes.audit',
            'schedules.view',
            'enseignants.view', 'salles.view', 'groupes.view',
            'referentiel.view',
            'enseignements.view',
            'evaluations.view',
            'sessions_examen.view',
            'epreuves.view',
            'surveillances.view',
            'notes_eval.view',
            'resultats.view',
            'deliberations.view',
            'annonces.view',
            'calendriers.view',
            'statistiques.view',
            'cockpit.view',
            'tasks.view',
            'it.view',
            'alumni.view',
            'campagnes.view',
            'qualite.view',
            'sentinel.view',
            'document.view',
            'search.use',
            'parcours.view',
            'assistant_ia.view', 'assistant_ia.recherche_doc',
            'decrochage.view',
        ],

        /*
         | Administrateur de faculté : périmètre de DONNÉES limité à sa
         | faculté (users.faculte_id, §6 isolation). Même avec ces
         | permissions, chaque requête est re-scopée par faculté.
         | Pas de configuration.manage (paramètres globaux), pas de
         | notes.unlock ni archives.delete (actions critiques réservées
         | aux rôles supérieurs — « ne pas tout donner à tous »).
         | Phase 4 : pas de verrouillage ni publication globale.
         */
        'admin_faculte' => [
            'archives.view', 'archives.create', 'archives.update',
            'documents.view', 'documents.upload', 'documents.download', 'documents.delete',
            'notes.view', 'notes.create', 'notes.update',
            'history.view',
            'exports.personal',
            'student_archive.view', 'student_archive.timeline.view', 'student_archive.academic.view',
            'student_archive.documents.view', 'student_archive.documents.download',
            'student_archive.export', 'student_archive.pdf',
            'attendance.view', 'attendance.create', 'attendance.export',
            'attendance.templates.manage', 'attendance.templates.use',
            'student_dossiers.view', 'student_dossiers.create', 'student_dossiers.update', 'student_dossiers.pdf',
            'student_documents.view', 'student_documents.upload', 'student_documents.download',
            'student_documents.validate', 'student_documents.reject', 'student_documents.replace',
            'student_documents.audit',
            'demandes.view', 'demandes.process', 'demandes.assign', 'demandes.approve', 'demandes.reject',
            'demandes.request_documents', 'demandes.documents.view', 'demandes.documents.upload',
            'demandes.documents.download', 'demandes.pdf', 'demandes.audit',
            'schedules.view', 'schedules.manage',
            'enseignants.view', 'enseignants.manage',
            'salles.view', 'salles.manage',
            'groupes.view', 'groupes.manage',
            'data.import', 'data.export',
            'referentiel.view',
            'admissions.view', 'admissions.manage',
            'enseignements.view', 'enseignements.manage',
            'evaluations.view', 'evaluations.create', 'evaluations.update',
            'sessions_examen.view', 'sessions_examen.create', 'sessions_examen.update',
            'epreuves.view', 'epreuves.create', 'epreuves.update',
            'surveillances.view', 'surveillances.assign', 'surveillances.manage',
            'notes_eval.view', 'notes_eval.enter', 'notes_eval.update', 'notes_eval.validate',
            'notes_eval.import', 'notes_eval.export',
            'notes.compostage.generate', 'notes_eval.compostage.resolve', 'notes.compostage.resolve',
            'resultats.view', 'resultats.calculate', 'resultats.validate',
            'deliberations.view', 'deliberations.prepare', 'deliberations.manage',
            'annonces.view', 'annonces.create', 'annonces.update',
            'calendriers.view', 'calendriers.manage',
            'attendance.justify',
            'statistiques.view',
            'cockpit.view',
            'tasks.view', 'tasks.create', 'tasks.update', 'tasks.assign', 'tasks.complete',
            'it.view', 'it.create', 'it.manage',
            'alumni.view',
            'campagnes.view', 'campagnes.manage',
            'qualite.view',
            'sentinel.view',
            'finances.view',
            'finances.receipts.verify',
            'document.view', 'document.generate', 'document.issue', 'document.download', 'document.print',
            'document_batch.run',
            'document_template.view',
            'import.view', 'import.create', 'import.validate', 'import.execute',
            'export.view', 'export.create',
            'search.use', 'search.advanced',
            'parcours.view', 'parcours.simuler', 'parcours.gerer', 'parcours.comparer',
            'assistant_ia.view', 'assistant_ia.mode_metier', 'assistant_ia.recherche_doc',
            'assistant_ia.action_proposer', 'assistant_ia.audit.view',

            // Prévention du décrochage (Lot 15) : consultation + flux humain
            // (LE périmètre de données reste re-scopé par faculté côté code).
            'decrochage.view', 'decrochage.traiter',
        ],

        /*
         | §2.7 — Rôles DÉDIÉS au circuit de gouvernance du planning.
         | Chaque étape dispose d'un rôle qui ne porte que sa décision :
         | certifier, approuver, publier. Ils reçoivent les permissions de
         | LECTURE nécessaires pour instruire le dossier, jamais les
         | permissions d'écriture des autres étapes.
         */
        'certificateur_qualite' => [
            'evaluations.view', 'evaluations.certify',
            'sessions_examen.view', 'epreuves.view', 'surveillances.view',
            'notes_eval.view', 'resultats.view', 'deliberations.view',
            'statistiques.view', 'cockpit.view', 'tasks.view', 'history.view',
        ],

        'direction_etudes' => [
            'evaluations.view', 'evaluations.approve',
            'sessions_examen.view', 'epreuves.view', 'surveillances.view',
            'notes_eval.view', 'resultats.view', 'deliberations.view',
            'statistiques.view', 'cockpit.view', 'tasks.view', 'history.view',
        ],

        'autorite_publication' => [
            'evaluations.view', 'evaluations.publish',
            'sessions_examen.view', 'epreuves.view', 'surveillances.view',
            'notes_eval.view', 'resultats.view', 'deliberations.view',
            'statistiques.view', 'cockpit.view', 'tasks.view', 'history.view',
        ],

        /*
         | Lot 19 — Rôle d'AUDIT distinct (cahier gouvernance) : lecture seule
         | des journaux, de la conformité et des vues de surveillance.
         | AUCUNE permission d'action (ni manage, ni execute, ni approbation) :
         | l'auditeur observe, il ne modifie jamais.
         */
        'auditeur' => [
            'gouvernance.audit',
            'sentinel.view',
            'it.view',
            'qualite.view',
            'import.view',
            'settings.view',
            'settings.history.view',
            'history.view',
        ],
    ];

    /**
     * Permissions accordées à un rôle donné.
     *
     * @return array<int, string>
     */
    public static function pourRole(string $role): array
    {
        return self::MATRICE[$role] ?? [];
    }

    public static function roleValide(string $role): bool
    {
        return in_array($role, self::ROLES, true);
    }
}

<?php

/*
|--------------------------------------------------------------------------
| Politique de rétention documentaire — portails étudiant/enseignant
|--------------------------------------------------------------------------
| Aucune durée n'est inventée ici. Tant qu'une décision métier/juridique
| n'est pas prise, `duree_jours` reste `null` et l'audit est strictement
| en lecture seule : il inventorie, il ne supprime jamais.
|
| Classes :
| - preuve_academique : dossier, inscriptions, notes, diplômes — conservation
|   longue, jamais de purge automatique ;
| - preuve_financiere : reçus, paiements, factures — conservation longue,
|   jamais de suppression (même refusés) ;
| - donnee_personnelle_sensible : dossiers sensibles — revue manuelle et
|   anonymisation/archivage selon décision, `retention_until` explicite ;
| - document_operationnel : pièces temporaires — peut être supprimé après
|   la durée validée, mais uniquement par une commande dédiée.
*/
return [
    'durees' => [
        'preuve_academique' => null,
        'preuve_financiere' => null,
        'donnee_personnelle_sensible' => null,
        'document_operationnel' => null,
    ],

    'ressources' => [
        'etudiants' => [
            'libelle' => 'Dossiers étudiants',
            'table' => 'etudiants',
            'classe' => 'preuve_academique',
            'action' => 'archivage',
            'colonne_date' => 'created_at',
            'dependances' => [
                ['table' => 'inscriptions_academiques', 'colonne' => 'etudiant_id'],
                ['table' => 'student_documents', 'colonne' => 'etudiant_id'],
                ['table' => 'documents_academiques', 'colonne' => 'etudiant_id'],
                ['table' => 'student_sensitive_records', 'colonne' => 'etudiant_id'],
            ],
        ],
        'inscriptions_academiques' => [
            'libelle' => 'Inscriptions académiques',
            'table' => 'inscriptions_academiques',
            'classe' => 'preuve_academique',
            'action' => 'archivage',
            'colonne_date' => 'created_at',
            'dependances' => [
                ['table' => 'dossiers_paiement', 'colonne' => 'inscription_academique_id'],
                ['table' => 'recus_paiement', 'colonne' => 'inscription_academique_id'],
            ],
        ],
        'documents_academiques' => [
            'libelle' => 'Documents académiques (GED)',
            'table' => 'documents_academiques',
            'classe' => 'preuve_academique',
            'action' => 'archivage',
            'colonne_date' => 'created_at',
        ],
        'student_documents' => [
            'libelle' => 'Pièces du dossier étudiant',
            'table' => 'student_documents',
            'classe' => 'document_operationnel',
            'action' => 'archivage',
            'colonne_date' => 'created_at',
            'dependances' => [
                ['table' => 'student_document_versions', 'colonne' => 'student_document_id'],
            ],
        ],
        'recus_paiement' => [
            'libelle' => 'Reçus de paiement (déclarations et validations)',
            'table' => 'recus_paiement',
            'classe' => 'preuve_financiere',
            'action' => 'conservation',
            'colonne_date' => 'created_at',
        ],
        'paiements' => [
            'libelle' => 'Paiements enregistrés',
            'table' => 'paiements',
            'classe' => 'preuve_financiere',
            'action' => 'conservation',
            'colonne_date' => 'created_at',
        ],
        'student_sensitive_records' => [
            'libelle' => 'Dossiers sensibles (médical, disciplinaire…)',
            'table' => 'student_sensitive_records',
            'classe' => 'donnee_personnelle_sensible',
            'action' => 'anonymisation_ou_archivage',
            'colonne_date' => 'created_at',
            'colonne_echeance' => 'retention_until',
            // Pas de dépendance FK directe : le lien document_source /
            // document_id est applicatif, pas une contrainte SQL.
        ],
    ],
];

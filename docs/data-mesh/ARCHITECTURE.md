# Architecture Data Mesh Logique — Monolithe Universitaire Laravel

## 1. Vision et principes directeurs

Le projet applique les principes du **Data Mesh** au sein du monolithe modulaire Laravel existant.
Il s'agit d'un **Data Mesh logique** :
- **Pas de microservices superflus** : les frontières entre domaines sont assurées par des espaces de noms (`namespaces`), des contrats d'interfaces PHP, des DTOs versionnés et des contrôles d'accès par politique (RBAC + périmètre facultaire).
- **Pas de base de données distribuée ni de broker Kafka** : MySQL reste l'unique moteur de persistance relationnel, préservant l'intégrité transactionnelle ACID.
- **Réutilisation intégrale des services métier existants** : `InscriptionService`, `AffectationService`, `MassAssignmentService`, `BIService`, `AuditService` et `AgregateurQualite` demeurent les seules sources de vérité opérationnelles.

### Déclinaison des 4 piliers Data Mesh :

```
+-----------------------------------------------------------------------------------+
|                            GOUVERNANCE FÉDÉRÉE                                    |
|   Sécurité RBAC - Périmètre Facultaire - Minimisation PII - Contrôles Qualité     |
+-----------------------------------------------------------------------------------+
|                          CATALOGUE DE DONNÉES EN LIBRE-SERVICE                     |
|           Découverte - Schémas - SLA Fraîcheur - Transactional Outbox             |
+-----------------------------------------+-----------------------------------------+
|        DOMAINES OPÉRATIONNELS           |          DOMAINE ANALYTIQUE             |
|                                         |                                         |
|  [Référentiel] -> CatalogueAcademique   |  [Pilotage] -> AnalytiqueFaculte        |
|  [Scolarité]   -> InscriptionsValidees  |                (Agrégats certifiés      |
|  [Affectation] -> AffectationsGroupes   |                 sans duplication)       |
|  [Enseignement]-> AffectationsEnseign.  |                                         |
|  [Évaluation]  -> ResultatsExamens      |                                         |
+-----------------------------------------+-----------------------------------------+
|                  PERSISTANCE ET SERVICES CŒUR DU MONOLITHE                        |
|       MySQL - Transactions ACID - LockForUpdate - Modèle Modulaire Laravel        |
+-----------------------------------------------------------------------------------+
```

1. **Propriété par domaine métier (`Domain Ownership`)** :
   Chaque domaine fonctionnel est propriétaire de ses modèles de données, de ses règles d'intégrité et de ses cycles de vie. Aucune écriture transversale n'est autorisée en dehors des services du domaine propriétaire.
2. **Données comme produits (`Data as a Product`)** :
   Les domaines exposent des **produits de données** documentés, versionnés (`MAJEUR.MINEUR`), avec des schémas explicites et des garanties de fraîcheur (SLA). Aucun modèle Eloquent interne n'est divulgué directement aux consommateurs.
3. **Gouvernance fédérée (`Federated Governance`)** :
   Des règles universelles régissent la sécurité (cloisonnement strict par faculté), la protection de la vie privée (minimisation des PII, masquage CIN/email), la traçabilité immuable (`AuditService`) et le contrôle qualité continu (`ControlesDonnees`).
4. **Plateforme en libre-service (`Self-Serve Platform`)** :
   L'infrastructure fournit un **Catalogue central**, un moteur de **Transactional Outbox** garantissant la publication post-commit avec déduplication, et un moteur de contrôles qualité réutilisable.

---

## 2. Structure applicative (`app/DataMesh/`)

L'ensemble des composants Data Mesh est logé dans le namespace `App\DataMesh\` sans altérer la structure modulaire de base :

```
app/DataMesh/
├── Contrats/
│   ├── ProduitDonnees.php               # Interface formelle de tout produit de données
│   └── OutboxInterface.php              # Interface du service Transactional Outbox
├── Catalogue.php                        # Registre central de découverte en code
├── Produits/
│   ├── ProduitSocle.php                 # Socle commun : sécurité, portée facultaire, audit
│   ├── CatalogueAcademique.php          # Domaine Référentiel (AcademicCatalog v1.0)
│   ├── InscriptionsValidees.php         # Domaine Scolarité (StudentEnrollment v1.0 - PII masquée)
│   ├── AffectationsGroupes.php          # Domaine Affectation (StudentGroupAssignment v1.0)
│   ├── AffectationsEnseignement.php     # Domaine Enseignement (TeachingAssignments v1.0)
│   ├── ResultatsExamens.php             # Domaine Évaluation (ExamResults v1.0 - Confidentiel)
│   └── AnalytiqueFaculte.php            # Domaine Pilotage (FacultyAnalytics v1.0 - Agrégats BI)
├── Qualite/
│   └── ControlesDonnees.php             # Moteur de 8 règles de qualité de données réutilisables
├── Outbox/
│   ├── OutboxMessage.php                # Modèle Eloquent de la table data_mesh_outbox
│   └── OutboxService.php                # Publication post-commit, retries et déduplication
└── Flux/
    └── FluxVerticalDataMesh.php         # Orchestration du flux vertical de bout en bout (§10)
```

---

## 3. Contrat standard d'un produit de données

Chaque produit implémente l'interface `App\DataMesh\Contrats\ProduitDonnees` :
- `code(): string` : Identifiant immuable du produit (ex. `inscriptions_validees`).
- `domaine(): string` : Domaine propriétaire (ex. `scolarite`).
- `version(): string` : Version sémantique `MAJEUR.MINEUR` (ex. `1.0`).
- `decrire(): array` : Métadonnées exhaustives (nom, finalité, propriétaire métier, source de vérité, classification, fraîcheur SLA, conditions d'accès, politique d'incompatibilité, gestion des erreurs).
- `schema(): array` : Liste ordonnée des attributs avec leur type, signification et sensibilité PII.
- `donnees(array $contexte = []): array` : Méthode de lecture avec contrôle d'autorisation (`User`), respect du périmètre facultaire, et retour d'un tableau structuré conforme au schéma (DTO).
- `fraicheur(): ?string` : Horodatage ISO de la dernière modification connue des données sources.

---

## 4. Modèle Transactional Outbox & Événements

Afin de prévenir tout risque d'incohérence entre les transactions SQL de l'application et la notification des domaines consommateurs :
1. **Écriture atomique** : Lors d'une mutation métier (ex. validation d'inscription), un enregistrement est créé dans la table `data_mesh_outbox` au sein de la même transaction MySQL (`DB::transaction`).
2. **Garantie post-commit** : Aucun événement n'est expédié avant que la transaction n'ait été validée avec succès sur la base.
3. **Idempotence et déduplication** : Chaque message porte une clé unique (`deduplication_key`). Un consommateur ré-exécuté avec la même clé rejette le doublon sans appliquer d'effet de bord.
4. **Résilience et reprises** : En cas d'échec du traitement consommateur, le message passe en statut `failed` avec horodatage d'erreur et compteur de tentatives (`retry_count`). Le service `OutboxService::publierEnAttente()` permet les reprises automatiques.

---

## 5. Gouvernance, sécurité et isolation des facultés

- **Authentification forte** : Tout accès à un produit exige un utilisateur authentifié (`User`).
- **Périmètre facultaire** :
  - Si le consommateur a un rôle `admin_faculte`, son identifiant restreint (`faculteRestreinteId()`) est appliqué de force.
  - Si une faculté hors périmètre est réclamée, une `AuthorizationException` est immédiatement levée.
- **Minimisation des PII** :
  - `InscriptionsValidees` masque par défaut le numéro de CIN (`12***678`) et l'adresse email.
  - Les numéros de téléphone et adresses postales ne sont jamais projetés dans les produits transverses.
  - `ResultatsExamens` respecte la politique d'anonymat et le secret des délibérations.

---

## 6. Moteur de qualité de données réutilisable

Le service `ControlesDonnees` implémente les 8 vérifications automatisées exigées :
1. `inscriptions_sans_programme` : Inscriptions validées privées de rattachement à un programme valide.
2. `references_lmd_incoherentes` : Incohérences entre le niveau de l'étudiant et la maquette de son programme.
3. `affectations_incompatibles` : Étudiants affectés à des groupes d'une autre faculté ou d'un autre niveau d'études.
4. `doublons_affectations` : Étudiants disposant de multiples affectations actives pour une même année universitaire.
5. `groupes_surcharges` : Groupes dont l'effectif actif dépasse la capacité officielle maximale autorisée.
6. `capacites_inconnues` : Groupes actifs dont la capacité est `NULL` (information signalée sans altérer la donnée).
7. `incoherences_effectifs_agregats` : Décalage entre le nombre brut d'inscriptions et les agrégats de la Business Intelligence.
8. `fraicheur_produits_depassee` : Produits dont le délai de mise à jour dépasse le SLA attendu.

---

## 7. Performance et optimisation SQL

- **Prévention N+1** : Chargement systématique avec `with(['etudiant', 'groupe', 'anneeUniversitaire'])`.
- **Requêtes légères** : Sélection exclusive des colonnes nécessaires (`select(['code', 'nom'])`).
- **Cache de pilotage** : Le produit `AnalytiqueFaculte` tire parti du cache MD5 versionné de `BIService` avec TTL de 600 secondes (`bi:{hash}:a:f:p`), évitant tout recalcul lourd lors des consultations répétées.

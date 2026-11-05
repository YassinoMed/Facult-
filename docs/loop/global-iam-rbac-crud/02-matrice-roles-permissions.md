# Matrice Rôles & Permissions et Cartographie des Accès — UniRegistre

**Projet :** UniRegistre  
**Composant :** Catalogue des Permissions, Domaines Fonctionnels et Matrice de Gouvernance  
**Date :** 31 Octobre 2026  
**Livrable :** Phase B (Suite) — LOOP GLOBAL IAM/RBAC  

---

## 1. Cartographie des Domaines Fonctionnels (`CataloguePermissions`)

Le catalogue fédéré regroupe l'ensemble des permissions en domaines fonctionnels majeurs issus du code réel :

| Domaine / Module | Code Domaine | Nombre de Permissions | Exemples de Capacités Clés | Sensibilité |
|---|---|---|---|---|
| **Administration Plateforme** | `roles`, `users`, `settings`, `system` | 18 | `roles.view`, `roles.manage`, `users.create`, `system.backup` | Critique |
| **Scolarité & Inscriptions** | `student_dossiers`, `admissions`, `campagnes` | 32 | `student_dossiers.validate`, `admissions.manage` | Élevée |
| **Pédagogie & Référentiel** | `referentiel`, `maquettes`, `enseignements` | 28 | `maquettes.create`, `referentiel.manage` | Moyenne |
| **Planification & Horaires** | `schedules`, `salles`, `groupes` | 24 | `schedules.edit`, `salles.manage` | Moyenne |
| **Examens & Délibérations** | `examens`, `epreuves`, `evaluations`, `deliberations` | 42 | `epreuves.update`, `evaluations.publish`, `deliberations.validate` | Critique |
| **Notes & Évaluations** | `notes`, `notes_eval` | 15 | `notes.view`, `notes.update`, `notes.unlock` | Critique |
| **Assiduité & Présences** | `attendance`, `presences` | 12 | `attendance.create`, `attendance.export` | Normale |
| **Finances & Paiements** | `finances`, `tarifs`, `remboursements` | 14 | `finances.view`, `finances.encaissement` | Élevée |
| **Centre Documentaire** | `documents`, `student_documents` | 20 | `documents.upload`, `documents.download` | Normale |
| **Gouvernance & Audit** | `iam`, `gouvernance`, `audit` | 16 | `iam.audit.view`, `gouvernance.certifier` | Critique |

---

## 2. Matrice Canonique des Rôles Système (`Permissions::ROLES`)

### 2.1 Synthèse par Rôle

```
+------------------------+-----------------------------------------------------------+
| Rôle Système           | Périmètre Clé Accordé dans Permissions.php                |
+------------------------+-----------------------------------------------------------+
| super_admin            | TOUTES les permissions sans exception                     |
| admin_scolarite        | Dossiers, inscriptions, maquettes, examens, notes         |
| agent_scolarite        | Inscriptions courantes, pièces, présences, consultation   |
| consultation           | Lecture seule globale transversale                        |
| admin_faculte          | Scolarité, maquettes et examens restreints à sa faculté   |
| certificateur_qualite  | Contrôle qualité et conformité des épreuves et jurys      |
| direction_etudes       | Approbation délibérations, jurys et arbitrages            |
| autorite_publication   | Publication officielle et scellement des notes d'examens  |
| auditeur               | Consultation journaux, traçabilité et conformité          |
+------------------------+-----------------------------------------------------------+
```

---

## 3. Classification des Permissions selon le Niveau de Sensibilité

### 3.1 Permissions Critiques (Exigeant Validation à Quatre Yeux et/ou MFA)
1. `roles.manage` : Création, modification et attribution de rôles administratifs.
2. `users.role` : Mutation de rôle d'un compte utilisateur.
3. `system.backup` / `system.restore` : Manipulation des sauvegardes de bases de données.
4. `evaluations.publish` / `deliberations.publish` : Publication définitive des résultats académiques.
5. `notes.unlock` : Déverrouillage d'une session de notes scellée.
6. `student_archive.sensitive.view` / `student_archive.medical.view` : Consultation de données médicales ou disciplinaires sensibles.

### 3.2 Permissions Protégées (Non Attribuables aux Rôles Personnalisés)
Conformément à `config/iam.php` (`modules_reserves => ['iam']`), les permissions relatives à la gouvernance IAM elle-même (`iam.*`) sont réservées aux rôles système autorisés et ne peuvent être déléguées dans un profil dynamique :
- `iam.profils.manage`
- `iam.affectations.manage`
- `iam.approbation.decide`
- `iam.urgence.declencher`

---

## 4. Règles de Résolution des Permissions Effectives

Pour un utilisateur $U$ et une permission $P$ sur une ressource de faculté $F$ :
$$
\text{Autorisé}(U, P, F) \iff \text{Actif}(U) \land \left( P \in \text{Matrice}(U.\text{role}) \lor P \in \text{ProfilsActifs}(U, F) \lor P \in \text{DélégationsActives}(U, F) \lor P \in \text{UrgenceActive}(U, F) \right)
$$
Tout accès en dehors du périmètre $F$ (si $U$ est restreint) est strictement interdit.


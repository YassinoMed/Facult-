# Catalogue des Produits de Données — Data Mesh Universitaire

Ce catalogue documente les **6 produits de données** exposés par les domaines métiers de la plateforme universitaire.
Tous les produits sont accessibles par programmation via la classe de découverte `App\DataMesh\Catalogue`.

---

## Sommaire des Produits

| Code Produit | Nom Métier | Domaine | Version | Propriétaire | Classification | SLA Fraîcheur |
|---|---|---|---|---|---|---|
| `catalogue_academique` | `AcademicCatalog` | Référentiel | `1.0` | Dir. Scolarité (LMD) | Interne Public | À la demande (statique) |
| `inscriptions_validees` | `StudentEnrollment` | Scolarité | `1.1` | Scolarité Centrale | Interne Sensible (PII min.) | Temps réel (lecture directe) |
| `affectations_groupes` | `StudentGroupAssignment` | Affectation | `1.0` | Scolarité (Groupes) | Interne Opérationnel | Temps réel (lecture directe) |
| `affectations_enseignement`| `TeachingAssignments` | Enseignement | `1.0` | Direction Pédagogique | Interne Opérationnel | Quotidien (planification) |
| `resultats_examens` | `ExamResults` | Évaluation | `1.0` | Présidence des Jurys | Confidentiel / Jurys | Après publication jury |
| `analytique_faculte` | `FacultyAnalytics` | Pilotage | `1.0` | Contrôle de Gestion / BI| Interne Décisionnel | Cache versionné (600s) |

---

## 1. Produit : `catalogue_academique` (`AcademicCatalog`)

### Métadonnées Générales
- **Identifiant** : `catalogue_academique`
- **Nom officiel** : `AcademicCatalog`
- **Domaine propriétaire** : `referentiel`
- **Responsable métier** : Direction de la scolarité et de l'ingénierie pédagogique
- **Description** : Référentiel officiel des structures académiques LMD : facultés actives, programmes d'études, et niveaux accrédités (L1 à D3).
- **Finalité** : Fournir une nomenclature académique unifiée et traçable pour la scolarité, les affectations et le pilotage.
- **Classification de sécurité** : `interne-non-sensible` (données de structure sans PII).
- **Source de vérité** : Module `Referentiel` (tables `facultes`, `programmes`, `niveaux`).

### Schéma Versionné (v1.0)
| Champ | Type | Signification | PII / Sensible |
|---|---|---|---|
| `facultes[].code` | `string` | Code stable et immuable de la faculté (ex. `FST`) | Non |
| `facultes[].nom` | `string` | Libellé officiel de l'établissement | Non |
| `programmes[].code` | `string` | Code unique d'habilitation du programme (ex. `L-INFO`) | Non |
| `programmes[].nom` | `string` | Intitulé officiel de la formation | Non |
| `programmes[].faculte_code` | `string` | Code de la faculté de rattachement | Non |
| `niveaux[].code` | `string` | Code normalisé du niveau d'études (ex. `L1`, `M2`) | Non |
| `niveaux[].nom` | `string` | Intitulé complet du niveau | Non |
| `niveaux[].programme_code`| `string\|null` | Programme associé ou `null` si niveau transversal | Non |

### Conditions d'Accès et Gouvernance
- **Authentification requise** : Utilisateur back-office authentifié (`User`).
- **Permissions minimales** : Rôle back-office valide ou permission `referentiel.view`.
- **Périmètre facultaire** : Filtrage automatique selon la faculté de l'utilisateur (`faculteRestreinteId`).
- **SLA Fraîcheur** : Données à faible volatilité (reflète l'état des maquettes en temps réel).
- **Méthode de consommation** :
  ```php
  $produit = Catalogue::produit('catalogue_academique');
  $donnees = $produit->donnees(['utilisateur' => $user, 'faculte_id' => $faculteId]);
  ```
- **Politique de compatibilité** : SemVer (ajouts de champs en `1.x` ; renommage ou suppression en `2.0`).

---

## 2. Produit : `inscriptions_validees` (`StudentEnrollment`)

### Métadonnées Générales
- **Identifiant** : `inscriptions_validees`
- **Nom officiel** : `StudentEnrollment`
- **Domaine propriétaire** : `scolarite`
- **Responsable métier** : Bureau central des inscriptions universitaires
- **Description** : Inscriptions académiques ayant franchi avec succès le cycle de validation administrative.
- **Finalité** : Fournir l'assiette officielle des étudiants inscrits pour l'affectation en groupe et le calcul d'indicateurs, sans exposition superflue des données privées.
- **Classification de sécurité** : `interne-sensible-minimise` (CIN et email masqués, coordonnées postales/téléphoniques exclues).
- **Source de vérité** : Table `inscriptions_academiques` (`statut = 'valide'`) et table `etudiants`.

### Schéma Versionné (v1.1 — pagination MINEUR compatible lot 02)
| Champ | Type | Signification | PII / Sensible |
|---|---|---|---|
| `total` | `int` | Nombre total filtré (toutes pages) | Non |
| `page` | `int` | Page courante (≥ 1, défaut 1) | Non |
| `par_page` | `int` | Taille de page (1 à 1000, défaut 100) | Non |
| `pages` | `int` | Nombre total de pages | Non |
| `inscriptions[].reference` | `string` | Numéro matricule officiel d'inscription | Non |
| `inscriptions[].annee` | `string` | Année universitaire (ex. `2026-2027`) | Non |
| `inscriptions[].niveau` | `string\|null` | Code du niveau LMD validé | Non |
| `inscriptions[].programme_code` | `string\|null` | Code du programme d'études | Non |
| `inscriptions[].statut` | `string` | Toujours égal à `valide` | Non |
| `inscriptions[].etudiant.nom_complet` | `string` | Prénom et nom d'usage | Sensible (interne) |
| `inscriptions[].etudiant.cin_masquee` | `string` | Numéro CIN masqué (`12***678`) | Sensible (minimisé) |
| `inscriptions[].etudiant.email_masque` | `string` | Email institutionnel masqué | Sensible (minimisé) |
| `inscriptions[].faculte_code` | `string\|null` | Code de la faculté d'inscription | Non |

### Conditions d'Accès et Gouvernance
- **Authentification requise** : Utilisateur back-office (`User`).
- **Contrôle d'accès** : Gate `viewAny` sur `Etudiant` ou permission `students:read` / `archives:read`.
- **Cloisonnement** : Un gestionnaire de faculté ne peut consommer que les inscriptions de sa propre faculté.
- **SLA Fraîcheur** : Temps réel (lecture directe avec clauses optimisées).
- **Méthode de consommation** :
  ```php
  $produit = Catalogue::produit('inscriptions_validees');
  $donnees = $produit->donnees([
      'utilisateur' => $user,
      'faculte_id' => $faculteId,
      'annee' => '2026-2027',
      'page' => 1, 'par_page' => 100,   // v1.1 : bornes 1..1000
      'tri' => 'id', 'sens' => 'asc',   // whitelistés, repli documenté
  ]);
  ```
- **Compatibilité v1.0 → v1.1** : clés historiques (`total`, `inscriptions`) inchangées ; ajout `page`, `par_page`, `pages` (MINEUR).

---

## 3. Produit : `affectations_groupes` (`StudentGroupAssignment`)

### Métadonnées Générales
- **Identifiant** : `affectations_groupes`
- **Nom officiel** : `StudentGroupAssignment`
- **Domaine propriétaire** : `affectations`
- **Responsable métier** : Service de gestion des effectifs et de la scolarité
- **Description** : État d'affectation active des étudiants au sein des groupes pédagogiques et taux d'occupation par rapport aux jauges officielles.
- **Finalité** : Permettre le suivi des capacités, la détection des sureffectifs et l'organisation des emplois du temps.
- **Classification de sécurité** : `interne-non-sensible` (données opérationnelles).
- **Source de vérité** : Table `affectations_groupe` (`statut = 'active'`) et table `groups` (module `Schedule`).

### Schéma Versionné (v1.0)
| Champ | Type | Signification | PII / Sensible |
|---|---|---|---|
| `total` | `int` | Volume total d'affectations actives | Non |
| `affectations[].reference_etudiant` | `string\|null` | Numéro d'inscription de l'étudiant | Non |
| `affectations[].groupe_code` | `string\|null` | Code unique du groupe attribué | Non |
| `affectations[].verrouille` | `bool` | Indicateur d'affectation manuelle verrouillée | Non |
| `occupation[].groupe_code` | `string` | Code du groupe | Non |
| `occupation[].effectif` | `int` | Nombre d'étudiants affectés actifs | Non |
| `occupation[].capacite` | `int\|null` | Capacité maximale autorisée (`null` = illimitée) | Non |
| `occupation[].depasse` | `bool` | Drapeau de saturation (`effectif > capacite`) | Non |

### Règles et Responsabilités
- **Lecture seule** : Ce produit ne modifie jamais les groupes. Toute affectation ou réaffectation s'effectue via `AffectationService` ou `MassAssignmentService`.
- **Règle de jauge** : Si `capacite` est `NULL`, la capacité est réputée illimitée conformément aux règles du projet.

---

## 4. Produit : `affectations_enseignement` (`TeachingAssignments`)

### Métadonnées Générales
- **Identifiant** : `affectations_enseignement`
- **Nom officiel** : `TeachingAssignments`
- **Domaine propriétaire** : `enseignement`
- **Responsable métier** : Direction pédagogique et coordination des départements
- **Description** : Répartition des charges d'enseignement (matières, enseignants titulaires/vacataires, groupes, semestres et volumes horaires).
- **Finalité** : Consultation des services d'enseignement pour la constitution des emplois du temps et le pilotage des heures.
- **Classification de sécurité** : `interne-restreint` (respect du statut des enseignants).
- **Source de vérité** : Module `Enseignement` (table `enseignements` et tables de liaisons).

### Schéma Versionné (v1.0)
| Champ | Type | Signification | PII / Sensible |
|---|---|---|---|
| `total` | `int` | Nombre d'enseignements programmés | Non |
| `enseignements[].code` | `string` | Code de l'affectation pédagogique | Non |
| `enseignements[].matiere_code` | `string` | Code de l'ECUE / matière enseignée | Non |
| `enseignements[].matiere_nom` | `string` | Intitulé officiel de la matière | Non |
| `enseignements[].enseignant_nom`| `string\|null` | Nom complet de l'enseignant responsable | Sensible (interne) |
| `enseignements[].groupe_code` | `string\|null` | Groupe ou classe bénéficiaire | Non |
| `enseignements[].semestre` | `string\|null` | Code du semestre académique (ex. `S1`, `S2`) | Non |
| `enseignements[].volume_horaire`| `int` | Volume horaire total prévu | Non |
| `enseignements[].statut` | `string` | Statut de l'enseignement (`valide`, `en_attente`)| Non |

### Conditions d'Accès
- **Permissions requises** : Permission `enseignements.view` ou rôle `admin_scolarite` / `admin_faculte`.

---

## 5. Produit : `resultats_examens` (`ExamResults`)

### Métadonnées Générales
- **Identifiant** : `resultats_examens`
- **Nom officiel** : `ExamResults`
- **Domaine propriétaire** : `evaluation`
- **Responsable métier** : Présidence des jurys d'examen et cellule des examens
- **Description** : Résultats officiels d'épreuves et décisions de délibération des jurys académiques.
- **Finalité** : Publication des moyennes, crédits validés et mentions après délibération souveraine.
- **Classification de sécurité** : `confidentiel` (secret des notes et des délibérations).
- **Source de vérité** : Module `Evaluation` (`notes_epreuve`, `epreuves`, `decisions_deliberation`).

### Schéma Versionné (v1.0)
| Champ | Type | Signification | PII / Sensible |
|---|---|---|---|
| `total` | `int` | Nombre de résultats publiés | Non |
| `resultats[].session_code` | `string` | Code de la session d'examen (ex. `PRINCIPALE_2026`)| Non |
| `resultats[].epreuve_code` | `string` | Code de l'épreuve concernée | Non |
| `resultats[].candidat_reference`| `string` | Numéro de compostage anonyme ou matricule | Sensible |
| `resultats[].note` | `float\|null` | Note obtenue (uniquement si délibération publiée) | Confidentiel |
| `resultats[].decision` | `string` | Décision du jury (`admis`, `ajourne`, `compense`)| Non |
| `resultats[].statut_publication`| `string` | Statut de publication (`brouillon`, `publie`) | Non |

### Garanties de Confidentialité
- **Interdiction absolue** : Aucune note brute n'est divulguée tant que l'épreuve n'a pas été délibérée et validée par le jury.
- **Accès restreint** : Rôles `super_admin`, membres de jury habilités, ou permission explicite `resultats.view` / `notes_eval.view`.

---

## 6. Produit : `analytique_faculte` (`FacultyAnalytics`)

### Métadonnées Générales
- **Identifiant** : `analytique_faculte`
- **Nom officiel** : `FacultyAnalytics`
- **Domaine propriétaire** : `pilotage`
- **Responsable métier** : Direction du pilotage administratif et financier
- **Description** : Indicateurs agrégés certifiés de performance académique, d'effectifs consolidés et de saturation des infrastructures.
- **Finalité** : Tableaux de bord de gouvernance et pilotage stratégique sans accès aux données nominatives.
- **Classification de sécurité** : `interne-non-sensible` (agrégats macroscopiques uniquement).
- **Source de vérité** : Dérivé de `BIService::indicateurs` (sources : `inscriptions_academiques`, `etudiants`, `notes`).

### Schéma Versionné (v1.0)
| Champ | Type | Signification | PII / Sensible |
|---|---|---|---|
| `version_agregats` | `string` | Empreinte MD5 d'invalidation du cache | Non |
| `indicateurs` | `object` | Dictionnaire d'indicateurs formalisés | Non |
| `indicateurs.effectif_total.valeur` | `int` | Effectif consolidé d'étudiants | Non |
| `indicateurs.effectif_total.definition`| `string` | Définition méthodologique du calcul | Non |
| `indicateurs.inscriptions_validees.valeur`| `int` | Nombre d'inscriptions au statut `valide` | Non |
| `indicateurs.taux_occupation_groupes.valeur`| `float` | Pourcentage de saturation des groupes | Non |

### Garanties Architecturales
- **Consommateur pur** : Le domaine de pilotage ne constitue jamais une seconde source de vérité. Les agrégats sont dérivés à la volée ou mis en cache basé sur le hash des sources.
- **Cache optimisé** : TTL de 600 secondes invalidé instantanément si une mise à jour intervient sur les sources.

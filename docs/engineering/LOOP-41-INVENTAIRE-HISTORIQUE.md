# LOOP 41 — INVENTAIRE HISTORIQUE EN LECTURE SEULE
## UniRegistre — Audit exhaustif des tables académiques, clés et dépendances

**Date :** 31 octobre 2026  
**Auteur :** Antigravity Engine — UniRegistre Core Team  
**Statut de la phase :** CONFORME (LECTURE SEULE STRICTE)  
**Connexion cible vérifiée :** `mysql` locale (`127.0.0.1:3306`, base `inscription_etudiants`) — Environnement de staging isolé, AUCUNE connexion vers la production.

---

## 1. Cadre et Règles d'Exécution

L'inventaire a été conduit dans le respect strict des consignes de sécurité :
- **Mode lecture seule absolue** : uniquement des requêtes `SELECT` / `COUNT` / `SHOW CREATE TABLE`.
- **Zéro écriture** : aucune création, modification, suppression ou migration de données (`migrate:fresh`, `db:wipe` proscrits).
- **Zéro PII dans les rapports** : aucune donnée personnelle (CIN, nom, email, notes nominatives) n'est reproduite dans les livrables d'ingénierie.
- **Vérification d'environnement** : confirmation préalable que l'hôte et la base ne ciblent aucun environnement de production.

---

## 2. Synthèse Volumétrique Globale

| Table | Rôle fonctionnel | Enregistrements | Clé primaire | Dépendances & Clés étrangères |
|---|---|---:|---|---|
| `etudiants` | Dossiers étudiants centraux | **2 152** | `id` (BIGINT UNSIGNED) | `faculte_id`, `programme_id`, `groupe_id` |
| `inscriptions_academiques` | Inscriptions annuelles / semestrielles | **2 090** | `id` (BIGINT UNSIGNED) | `etudiant_id`, `annee_universitaire_id`, `programme_id`, `niveau_id` |
| `notes` | Notes d'évaluations et examens | **553** | `id` (BIGINT UNSIGNED) | `etudiant_id`, `inscription_academique_id`, `matiere_id`, `semestre_id` |
| `matieres` | Éléments constitutifs d'UE (ECUE) | **74** | `id` (BIGINT UNSIGNED) | `ue_id` (nullable), `responsable_teacher_id` |
| `unites_enseignement` | Unités d'enseignement (UE LMD) | **11** | `id` (BIGINT UNSIGNED) | `semestre_id`, `programme_id` |
| `maquettes` | Maquettes de formation globales | **1** | `id` (BIGINT UNSIGNED) | `programme_id`, `departement_id` |
| `semestres_lmd` | Périodes semestrielles LMD | **6** | `id` (BIGINT UNSIGNED) | `niveau_id` |
| `evaluation_deliberations` | Délibérations formelles de jurys | **5** | `id` (BIGINT UNSIGNED) | `annee_universitaire_id`, `programme_id`, `niveau_id` |
| `evaluation_decisions` | Décisions individuelles issues de jurys | **16** | `id` (BIGINT UNSIGNED) | `deliberation_id`, `etudiant_id` |
| `historique_academique` | Journal d'audit des jalons académiques | **131** | `id` (BIGINT UNSIGNED) | `etudiant_id`, `auteur_id` |
| `parcours_dispenses` | Dispenses d'UE / ECUE accordées | **1** | `id` (BIGINT UNSIGNED) | `etudiant_id`, `matiere_id` |
| `parcours_equivalences` | Équivalences académiques externes | **1** | `id` (BIGINT UNSIGNED) | `etudiant_id`, `ue_source_id`, `ue_cible_id` |
| `parcours_plans` | Plans individuels de formation | **5** | `id` (BIGINT UNSIGNED) | `etudiant_id`, `programme_id` |
| `archives_etudiants` | Historique gelé d'anciens dossiers | **47** | `id` (BIGINT UNSIGNED) | `etudiant_id` |

---

## 3. Analyse Détaillée par Table Historique

### 3.1 Table `etudiants` (2 152 enregistrements)
- **Clé primaire** : `id` (BIGINT UNSIGNED AUTO_INCREMENT).
- **Champs obligatoires** : `identifiant_national`, `matricule`, `numero_etudiant`, `cin`, `nom`, `prenom`, `email`, `date_naissance`, `sexe`, `nationalite`, `adresse`, `ville`, `code_postal`, `annee_bac`, `niveau`, `annee_academique`, `actif`.
- **Intégrité et Unicité** :
  - Doublons de CIN : **0** (`SELECT cin, count(*) ... HAVING count(*) > 1` = vide).
  - Doublons d'emails : **0**.
  - Identifiants orphelins : **0**.
- **Champs historiques & legacy** : `annee_academique` (texte libre, ex. `"2024-2025"`), `niveau` (chaîne VARCHAR, ex. `"L1"`, `"L2"` en redondance avec la relation `inscriptions_academiques`).

### 3.2 Table `inscriptions_academiques` (2 090 enregistrements)
- **Clé primaire** : `id`.
- **Clés étrangères** : `etudiant_id` (FK `etudiants`), `annee_universitaire_id` (FK `annees_universitaires`), `programme_id` (FK `programmes`), `niveau_id` (nullable, FK `niveaux`).
- **Analyse des états** :
  - Statut d'inscription : majoritairement `"validee"` ou `"en_cours"`.
  - Décisions textuelles directes : 81 inscriptions portent un texte de décision legacy (`admis`, `ajourne`, `autorise_a_redoubler`) ; **2 009 inscriptions** ont un champ `decision` NULL.
  - Découplage jury moderne : **2 074 inscriptions** historiques n'ont aucun enregistrement lié dans la table normalisée `evaluation_decisions`. Ce résultat historique réside uniquement dans la trace scolarité ou le statut d'inscription.

### 3.3 Table `notes` (553 enregistrements)
- **Clé primaire** : `id`.
- **Clés étrangères** : `etudiant_id`, `inscription_academique_id`, `semestre_id`, `matiere_id`.
- **Répartition par session** :
  - Session principale (`normale`) : **504 notes**.
  - Session de contrôle (`rattrapage`) : **49 notes**.
- **Contrôles de cohérence** :
  - Notes hors échelle (< 0.00 ou > 20.00) : **0**.
  - Notes avec matière inexistante : **0**.
  - Notes sans inscription académique : **0**.
  - Paires `(etudiant_id, matiere_id, inscription_academique_id)` avec 2 enregistrements : **55 cas** (exactement 1 session normale + 1 session de rattrapage, conforme au parcours d'examen standard).

### 3.4 Table `matieres` (74 enregistrements) & `unites_enseignement` (11 enregistrements)
- **Situation structurelle** :
  - 22 matières sont rattachées explicitement à une unité d'enseignement (`ue_id IS NOT NULL`).
  - **52 matières sont orphelines de lien direct UE (`ue_id IS NULL`)**.
  - Ces matières orphelines proviennent de l'ancien modèle transversal ou d'importations directes de maquettes où la granularité UE n'avait pas été formalisée.
- **Règle de sécurité retenue** : aucune matière sans rattachement UE validé ne peut faire l'objet d'une conversion automatique en crédits LMD capitalisés sans arbitrage explicite de la scolarité.

### 3.5 Tables de Délibérations et Jurys (`evaluation_deliberations` : 5, `evaluation_decisions` : 16)
- Les jurys modernes instrumentés dans UniRegistre couvrent 5 délibérations types et 16 décisions formelles.
- L'historique antérieur (cohortes avant la mise en place du module délibérations) a fonctionné sous enregistrement direct dans les fiches de scolarité.

---

## 4. Matrice de Dépendances Académiques

```
etudiants (2 152)
   ├── inscriptions_academiques (2 090)
   │      ├── semestres (LMD)
   │      │      └── notes (553) ──> matieres (74) ──[ue_id nullable]──> unites_enseignement (11)
   │      └── evaluation_decisions (16) ──> evaluation_deliberations (5)
   ├── parcours_plans (5)
   ├── parcours_equivalences (1)
   ├── parcours_dispenses (1)
   ├── historique_academique (131)
   └── archives_etudiants (47)
```

---

## 5. Conclusion de l'Inventaire

1. **Intégrité des clés primaires et étrangères** : aucune violation d'intégrité référentielle détectée sur les tables sources.
2. **Points d'attention majeurs** :
   - 52 matières orphelines d'UE (`ue_id IS NULL`).
   - 2 074 inscriptions historiques sans enregistrement direct dans `evaluation_decisions`.
   - Absence de règle confirmée pour certaines formations pilotes (ex. filières co-construites).
3. **Consigne d'exploitation** : la base est saine pour l'analyse et la simulation en lecture seule. Toute écriture reste rigoureusement bloquée.


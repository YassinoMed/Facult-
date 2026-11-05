# LOOP 41 — REGISTRE DES ANOMALIES HISTORIQUES ET CAS PARTICULIERS
## UniRegistre — Détection exhaustive, typologie, causes racines et préconisations

**Date :** 31 octobre 2026  
**Auteur :** Antigravity Engine — UniRegistre Core Team  
**Statut de la phase :** AUDITÉ ET CARTOGRAPHIÉ (ZÉRO ÉCRITURE)  
**Sécurité :** Zéro PII — Registre technique d'intégrité  

---

## 1. Vue d'Ensemble des Anomalies Détectées

L'inventaire en lecture seule de la base de données académique d'UniRegistre a mis en évidence quatre catégories majeures d'anomalies historiques :

| ID Anomalie | Nature de l'anomalie | Volume concerné | Niveau de risque | Statut de traitement en LOOP 41 |
|---|---|---:|---|---|
| **ANOM-41-01** | Matières orphelines sans rattachement UE (`ue_id IS NULL`) | **52 matières** | Élevé (crédits incertains) | Bloqué en simulation (`REVUE_HUMAINE_REQUISE`) |
| **ANOM-41-02** | Inscriptions historiques sans délibération formelle normalisée | **2 074 inscriptions** | Moyen (continuité de statut) | Préservé sous statut scolarité d'origine |
| **ANOM-41-03** | Doublons de couples (étudiant, matière) par inscription | **55 occurrences** | Nul (faux positif levé) | Requalifié : Session normale + Rattrapage |
| **ANOM-41-04** | Formations à réglementation non confirmée (co-construction, école) | Variable par cohorte | Critique (juridique) | Isolé sous `MIGRABLE_APRES_VALIDATION` |

---

## 2. Analyse Approfondie par Anomalie

### 2.1 ANOM-41-01 : 52 Matières Orphelines sans Unité d'Enseignement
- **Constat** : 52 matières sur 74 répertoriées dans la table `matieres` ont leur champ clé étrangère `ue_id` à `NULL`.
- **Cause racine** : Ces enregistrements proviennent d'un ancien schéma où les matières étaient créées de façon autonome (notion transversale « tronc commun ») sans arborescence LMD stricte `Niveau -> Semestre -> UE -> Matière (ECUE)`.
- **Impact sur la migration** :
  - Dans le système LMD (Décret 2022-631), les crédits ECTS sont alloués au niveau de l'UE ou de l'ECUE porteur de crédits propres.
  - Une note obtenue sur une matière sans UE ne peut pas déterminer si l'UE parente a été validée par compensation ou capitalisée.
- **Règle de traitement fail-closed** :
  - Toute note rattachée à une matière orpheline génère une alerte d'intégrité dans le rapport de dry-run.
  - Le dossier étudiant correspondant est automatiquement classé en `REVUE_HUMAINE_REQUISE`.
  - Aucune conversion automatique n'est tentée.
- **Action requise pour la scolarité** : Exécuter un atelier métier d'affectation des 52 matières aux UE correspondantes dans les maquettes accréditées.

---

### 2.2 ANOM-41-02 : 2 074 Inscriptions sans Délibération Juridique Normalisée
- **Constat** : Sur 2 090 inscriptions académiques enregistrées dans l'historique, seules 16 sont associées à une décision normalisée dans la table `evaluation_decisions` (via `evaluation_deliberations`). 2 074 inscriptions ne disposent que d'un statut administratif brut (`statut = 'validee'`, `decision` textuelle libre ou vide).
- **Cause racine** : L'instrumentation des modules jurys électroniques et délibérations cryptographiques d'UniRegistre est postérieure à l'existence de ces cohortes.
- **Impact sur la migration** :
  - Le système ne dispose pas du hash de délibération d'origine ni de la signature du président du jury pour ces années passées.
- **Règle de traitement fail-closed** :
  - Le statut administratif historique est sanctuarisé dans les données sources.
  - En mode simulation de migration, le moteur s'appuie sur la reconstitution déterministe des notes réelles sans présumer d'une décision de rachat non documentée.
  - Si un dossier requiert l'attribution d'un statut officiel (admis/redoublant), une validation formelle par le responsable pédagogique est exigée (`MIGRABLE_APRES_VALIDATION`).

---

### 2.3 ANOM-41-03 : Levée du Faux Positif sur les Paires Étudiant-Matière Multiples
- **Constat initial** : 55 enregistrements dans la table `notes` partagent les mêmes `etudiant_id`, `inscription_academique_id` et `matiere_id`.
- **Vérification détaillée** :
  - Requête d'audit : `SELECT etudiant_id, matiere_id, inscription_academique_id, session, COUNT(*) FROM notes GROUP BY ...`
  - Résultat : Dans 100% des cas, il s'agit exactement d'une note avec `session = 'normale'` et d'une note avec `session = 'rattrapage'`.
  - Aucune collision sur la même session n'existe.
- **Conclusion** : Ce comportement est parfaitement conforme au cycle d'évaluation universitaire tunisien (session principale + session de contrôle). Le moteur de simulation retient la note la plus favorable conformément à la réglementation en vigueur (Décret 2022-631 Art. 11).

---

### 2.4 ANOM-41-04 : Formations à Statut Juridique Non Confirmé ou Expiré
- **Constat** : Présence de formations pilotes en co-construction (ex. Licence Co-construite IA & Systèmes Embarqués) ou de filières ingénieur dont le seuil d'établissement n'a pas été publié au JORT.
- **Traitement** : Application stricte des conclusions de LOOP 40-C. Le moteur `SimulationMigrationHistoriqueService` isole ces programmes :
  - `statut_regle = 'REGLE_A_VALIDER'` -> `statut_migration = 'MIGRABLE_APRES_VALIDATION'`.
  - `statut_regle = 'REGLE_EXPIREE'` -> `statut_migration = 'REVUE_HUMAINE_REQUISE'`.
  - Aucun automatisme n'est toléré tant que l'arrêté ministériel ou l'homologation d'habilitation n'est pas versée au référentiel.

---

## 3. Synthèse et Mesures Conservatoires

1. **Aucun impact destructif** : L'existence de ces anomalies n'a provoqué aucune altération de données, toutes les vérifications ayant été menées en lecture seule.
2. **Garde-fous opérationnels** : Le moteur de simulation refuse tout passage en `MIGRABLE_AUTOMATIQUEMENT` dès qu'une de ces anomalies est rencontrée.
3. **Plan de remédiation pré-LOOP 42** :
   - Mise à disposition de la matrice des 52 matières pour rattachement par la direction des études.
   - Recensement des PV de jurys physiques pour les 2 074 inscriptions antérieures.


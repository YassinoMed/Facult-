# Vérificateur Intelligent du Dossier Étudiant

## 1. Vue d'ensemble & Philosophie

Le module de **vérification intelligente du dossier étudiant** est un système de contrôle automatisé, non destructif et explicable. Il analyse en lecture seule l'ensemble des dimensions administratives, documentaires et pédagogiques d'un étudiant pour détecter les incohérences, omissions et blocages.

### Règle d'or du système
Le système ne modifie **jamais silencieusement** les données métier critiques. Il applique un cycle rigoureux :

```text
ANALYSER
  ↓
DÉTECTER
  ↓
EXPLIQUER
  ↓
PROPOSER (Recommandation)
  ↓
FAIRE VALIDER (Agent administratif)
  ↓
CORRIGER (Transactionnel)
  ↓
AUDITER (Historique sans PII)
  ↓
RE-VÉRIFIER
```

---

## 2. Verdicts & Score de Conformité

### Verdicts
Le verdict global est calculé selon la gravité maximale constatée parmi toutes les règles :
- **CONFORME** : Aucune anomalie détectée sur le dossier.
- **À_VÉRIFIER** : Présence d'avertissements ou d'erreurs non bloquantes (ex. document manquant, affectation de groupe en attente).
- **BLOQUANT** : Incohérence critique empêchant la validation officielle (ex. identité incomplète, validation sans inscription, saut de niveau L2 sans L1, note hors bornes).

### Score de conformité (sur 100)
- Calcul explicable basé sur une base de 100 points avec déductions pondérées par sévérité :
  - **Bloquant** : -35 points
  - **Erreur** : -15 points
  - **Avertissement** : -5 points
  - **Info** : -1 point
- **Garde bloquante (§12)** : Tout dossier comportant au moins un blocage est plafonné à un score maximum de **49/100**.

---

## 3. Les 10 Règles de Vérification

| Règle | Code | Catégorie | Description & Gravité |
|---|---|---|---|
| **R1** | `identite.complete` | `identite` | Vérifie la présence des données fondamentales (Nom, Prénom, CIN/Identifiant, Sexe). Bloquant si nom/prénom absent. |
| **R2** | `identite.coherence` | `identite` | Contrôle la conformité des formats (CIN 8 chiffres, email RFC, téléphone E.164, âge réaliste) via `NormalisationService`. |
| **R3** | `inscription.coherence` | `inscription` | Détecte les étudiants validés sans inscription active (Bloquant), les doubles inscriptions actives sur la même année, et les moyennes incohérentes. |
| **R4** | `parcours.lmd` | `parcours` | Vérifie la cohérence du cursus LMD, le saut de niveau sans historique inférieur préalable validé (Bloquant), et la progression sans décision favorable. |
| **R5** | `documents.requis` | `document` | Contrôle la présence des pièces obligatoires en GED pour tout étudiant validé (CIN, Photo, Baccalauréat). |
| **R6** | `doublons.detection` | `doublon` | Détecte les doublons potentiels par comparaison multicritère (CIN identique, même nom/prénom/date de naissance) en réutilisant `FicheEtudiantService`. |
| **R7** | `chronologie.coherence` | `chronologie` | Détecte les dates impossibles (date de naissance future, âge inférieur à 14 ans ou supérieur à 70 ans à l'inscription). |
| **R8** | `affectation.groupe` | `affectation` | Contrôle la validité du groupe affecté, son état actif, et la concordance stricte entre le programme du groupe et celui de l'étudiant. |
| **R9** | `donnees.orphelines` | `orphelin` | Détecte les inscriptions pédagogiques orphelines, les notes sans matière rattachée, et les documents sans année universitaire. |
| **R10** | `resultats.coherence` | `resultats` | Contrôle que les notes sont dans l'intervalle [0, 20] (Bloquant), que les coefficients sont strictement positifs, et que les notes sont reliées à une inscription. |

---

## 4. Architecture & Composants

```
app/Services/Verification/
├── VerificationRule.php               # Contrat d'interface pour toute règle (code, libellé, catégorie, verifier)
├── Anomalie.php                       # Objet valeur immutable (règle, catégorie, gravité, message, justification, recommandation, champ)
├── VerificationResult.php             # DTO résultat (verdict, score, règles passées/vérifiées, anomalies, toArray)
├── VerificationEngine.php             # Orchestrateur de vérification (eager loading anti-N+1, fail-soft)
└── Rules/
    ├── IdentiteCompleteRule.php
    ├── IdentiteCoherenceRule.php
    ├── InscriptionCoherenceRule.php
    ├── ParcoursLmdRule.php
    ├── DocumentsRequiredRule.php
    ├── DoublonsRule.php
    ├── ChronologieRule.php
    ├── AffectationGroupeRule.php
    ├── DonneesOrphelinesRule.php
    └── ResultatsCoherenceRule.php
```

---

## 5. Interface & Expérience Utilisateur

### Fiche Étudiant 360° (`/admin/etudiants/{id}`)
- **Bannière d'alerte intelligente** intégrée en tête de l'onglet *Synthèse 360°* indiquant :
  - Le verdict en pastille dynamique pulsante (Vert, Ambre, Rose)
  - Le score chiffré (/100) et le nombre de règles validées
  - Bouton d'accès direct au rapport complet et bouton d'action *Relancer l'analyse*.

### Rapport Détaillé (`/admin/etudiants/{id}/verification`)
- Tableau de bord à 4 indicateurs : Verdict, Score /100 avec jauge, Ratio de règles validées, Compteur d'anomalies par sévérité.
- Liste explicable des anomalies présentant :
  - Règle et catégorie
  - Description du problème
  - Preuve / justification technique
  - Action recommandée
  - Bouton **Correction assistée** ouvrant un modal de modification contrôlée.
- Synthèse des règles exécutées avec statut unitaire (✓ validée / ⚠ anomalie).

---

## 6. Correction Assistée & Traçabilité (§15-16)

Toute correction proposée par le vérificateur :
1. Est soumise à l'autorisation `archives.update` de l'agent.
2. Exige un **motif administratif obligatoire**.
3. S'exécute dans une transaction de base de données.
4. Enregistre un événement d'audit dans `historique_academique` avec l'action `correction_dossier` (ancienne valeur, nouvelle valeur, motif, sans PII non autorisée).
5. Déclenche une **re-vérification immédiate** pour actualiser le score et confirmer la résolution de l'anomalie.

### Workflow validé (proposer → approuver → appliquer)

En plus de la correction directe (cas urgents), un workflow à validation
humaine existe (`dossier_corrections`, `CorrectionService`) :

- `POST etudiants/{etudiant}/verification/proposer` → statut `proposee`
  (anti-doublon ouvert, sans écriture métier).
- `POST verification/corrections/{correction}/decider` (`decision` =
  `approuver|rejeter|appliquer`) : 4-eyes (décideur ≠ proposeur),
  verrou `lockForUpdate` (double validation → refus explicite),
  application refusée si la donnée a changé depuis la proposition.
- Audit granulaire : `correction_proposee/approuvee/rejetee` (+
  `correction_dossier` à l'écriture). Champs limités aux colonnes
  réelles (`DossierCorrection::CHAMPS`).

### Exécutions persistées (§13)

`dossier_verifications` (via `VerificationStore`) : `verification_id`,
verdict, score, métriques, source (`web|job|api`), auteur. Enregistré
sur `verification.verifier`, en fin de `VerifyStudentDossierJob` et
sur `POST /api/v1/students/{id}/verification` (qui expose aussi
`verification_id`). Historique append-only ; déduplication via
`anomalies_qualite.cle_unique`.

---

## 7. Automatisation & Traitement en Masse (§18-19)

### Commande Artisan
```bash
# Vérifier l'ensemble des étudiants (mode lecture seule)
php artisan dossier:verifier

# Vérifier un étudiant spécifique
php artisan dossier:verifier --etudiant=42

# Filtrer par faculté avec limite de volume
php artisan dossier:verifier --faculte=1 --limite=500

# Persister les anomalies dans le Centre Qualité (anomalies_qualite)
php artisan dossier:verifier --sync
```

### Job Asynchrone (`VerifyStudentDossierJob`)
- Traitement par tranches de 50 étudiants dans la file `verification`.
- Timeout : 300 s, Tries : 3, Backoff exponentiel `[10, 60, 180]`.
- Idempotence complète : peut être relancé indéfiniment sans créer de doublons.

---

## 8. API v1 & Sécurité (§17, §20-21)

### Endpoints (scope `students:read`)
- `GET /api/v1/students/{id}/verification` : Rapport complet standardisé.
- `POST /api/v1/students/{id}/verification` : Déclenche l'analyse avec audit.
- `GET /api/v1/students/{id}/verification/anomalies` : Liste filtrée des anomalies et recommandations.

### Isolation par Faculté & Anti-BOLA (§17)
- **Web** : Contrôle du périmètre faculté via `peutAccederFaculte()` → Erreur `403` si hors périmètre.
- **API** : Vérification stricte en base via `verifierPerimetreFaculte()` → Réponse `404` stricte (Anti-BOLA, aucune confirmation d'existence hors périmètre).
- Spécification resynchronisée et testée sous `docs/openapi.yaml`.

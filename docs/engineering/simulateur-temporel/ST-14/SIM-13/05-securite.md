# SIM-13 — 05 · Sécurité

**Scénario :** SIM-13 — Crise académique combinée
**Portée :** validation d'entrée + lecture seule des données métier

---

## A. Principe : lecture seule

SIM-13 écrit uniquement dans les **3 tables techniques du module**
(`scenarios_temporels`, `executions_temporelles`, `snapshots_executions`).
Aucune table métier n'est touchée : `etudiants`, `inscriptions_academiques`,
`groups`, `facultes`, `niveaux` restent strictement inchangés (vérifié par
test avec empreinte d'un étudiant témoin).

---

## B. Validation d'entrée

### B.1. Jeu modifié — bornes dures

- `taux_modifie.{passage,redoublement,abandon} ∈ [0, 1]` (schéma).
- Somme du jeu modifié `≤ 1.0001` (moteur + catalogue) — aucune population
  créée.

### B.2. Règles de domaine (catalogue)

- Paire focale **dégradante uniquement** (passage/baisse, redoublement/hausse,
  abandon/hausse).
- **Aucune amélioration** sur aucun axe.
- **Au moins deux dégradations strictes** — refuse les crises « mono-axe »
  (déjà couvertes par SIM-08/09/11).

### B.3. Incompatibilités structurelles

Gel (`duree_gel_annees`), choc (`pas_exceptionnel`), cible
(`taux_cible_croissance`), creux (trio récupération) et alias
(`taux_ameliore`, `taux_degrade`) sont des **clés inconnues** du schéma
SIM-13 : refusées avant toute exécution. Au niveau moteur, les mêmes clés
déclenchent les exclusions « SIM-03 / SIM-04 / SIM-05 / SIM-06 / SIM-07 /
SIM-08 » — défense en profondeur.

---

## C. Injection et surface d'attaque

- Aucune requête SQL brute : ORM/services uniquement.
- Aucune valeur rendue côté client par SIM-13 (résultats moteur existants,
  échappés par la vue existante).
- Aucun appel réseau, aucun webhook, aucune API externe.

---

## D. Confidentialité et PII

- Seuls des **agrégats par niveau** (effectifs, cohortes) sont lus.
- Aucune identité, aucun dossier étudiant, aucun fichier d'identité.
- **Aucun PII dans les logs** (règle projet) : les seules traces sont les
  3 tables techniques (statistiques).
- `abandon` n'étant pas réinjecté, aucune donnée pathologique n'est
  inférée.

---

## E. Isolation et idempotence

- Chaque exécution est indépendante (aucun état partagé entre scénarios).
- Même graine + mêmes paramètres → **même exécution** (`id`,
  `hash_resultat`) : zéro duplication.
- Graine différente → nouvelle exécution, variante de référence identique.

---

## F. Réserves de sécurité

| Réserve | Description | Impact |
|---------|-------------|--------|
| SIM13-S1 | Les règles « crise combinée » vivent dans le catalogue, pas dans le moteur (non branchées sur la création HTTP) | Faible — bornes dures du moteur conservées |
| SIM13-S2 | Les messages moteur renvoient « SIM-09→SIM-12 » pour le descripteur générique | Faible — libellé |
| SIM13-S3 | « Aucune amélioration » et « ≥ 2 strictes » sont des règles de modélisation | Faible — documentées |

---

## G. Règles de sécurité vérifiées

- [x] Aucune écriture métier (empreinte témoin identique avant/après).
- [x] `taux_modifie` borné `[0,1]`, somme ≤ 1.0001.
- [x] Paire focale dégradante + aucune amélioration + ≥ 2 strictes.
- [x] Permissions existantes suffisantes (aucune nouvelle).
- [x] Isolation facultaire : 403 inter-facultés, 403 sans permission.
- [x] 3 tables techniques écrites, et elles seules.
- [x] Idempotence par graine (aucun doublon d'exécution).

---

## H. Tests de sécurité

`test_sim13_permissions_isolation_facultaire_et_zero_ecriture_metier()`
couvre : 403 sans permission, 403 inter-facultés, comptages métier
inchangés, empreinte étudiant témoin, 1 exécution + 3 snapshots.
`test_sim13_catalogue_refuse_les_regles_de_domaine_hors_contrat()` couvre
les refus catégoriels (paire non dégradante, amélioration, mono-dégra-
dation, somme, clés interdites).

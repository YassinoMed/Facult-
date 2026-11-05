# SIM-01 — 05 · Sécurité et lecture seule

**Scénario :** SIM-01 — Croissance des admissions
**Date :** 2026-11-02
**Mode :** AUDIT_ONLY — aucune écriture métier

---

## F.1 Permissions

| Route | Middleware | Test |
|---|---|---|
| `GET admin/simulation-temporelle/catalogue` | `can:simulation.view` | `test_catalogue_refuse_sans_permission_dediee` |
| `POST .../scenarios/{id}/executer` | `can:simulation.executer` + `throttle:10,1,st-exec` | `test_sim01_permissions_et_isolation_facultaire` |
| Création / gestion de scénario | `can:simulation.gerer` | couvert par la suite historique |

Un utilisateur sans `simulation.executer` reçoit `403` ; aucune exécution n'est
créée (assertion `ExecutionTemporelle::count() === 0`).

---

## F.2 Isolation facultaire

- Le scénario porte `faculte_id`.
- Avant calcul, le contrôleur vérifie `peutAccederFaculte`.
- Admin de la faculté B sur un scénario de la faculté A → `403`.
- Aucun contenu du scénario ni du résultat n'est renvoyé dans ce cas.
- Le catalogue est global par construction (métadonnées sans données métier) ;
  ce choix est documenté dans ST-14.00.

---

## F.3 Lecture seule des données métier

Test `test_sim01_zero_ecriture_metier_et_tables_techniques_seules` :

| Table | Avant | Après |
|---|---|---|
| `etudiants` | n | n |
| `inscriptions_academiques` | n | n |
| `groupes` | n | n |
| `facultes` | n | n |
| `niveaux` | n | n |
| `scenarios_temporels` | n | n |

Un étudiant témoin est comparé **attribut par attribut** (`getAttributes()`)
avant/après : aucune modification, y compris `updated_at`.

Seules les tables techniques du module sont écrites :

- `executions_temporelles` : 1 ligne ;
- `snapshots_executions` : `horizon + 1` lignes.

Aucune inscription, aucun groupe, aucune affectation, aucune note n'est créée
ou modifiée.

---

## F.4 Données personnelles

- L'adaptateur ne lit que des compteurs agrégés par niveau.
- Les snapshots contiennent `cohortes`, `total`, `admissions` — aucun nom,
  CIN, email ou identifiant étudiant.
- Le résultat expose des agrégats et des identifiants de scénario/exécution.
- Aucun log applicatif n'a été ajouté par SIM-01.
- La règle « pas de PII dans les logs » reste portée par les LOOP ST-11.

---

## F.5 Idempotence et intégrité

| Contrôle | Preuve |
|---|---|
| Mêmes entrées → même exécution | `test_sim01_memes_entrees_idempotentes_et_graine_reproductible` |
| Même `hash_resultat` | assertion dédiée |
| Graine différente → nouvelle exécution | assertion dédiée |
| Version moteur dans le hash | `hash_entrees` inclut `1.1` |
| Aucune réécriture des snapshots | voie idempotente du service |

---

## F.6 Capacité et hypothèses

- La capacité unitaire provient des **groupes réels actifs** du périmètre.
- À défaut, le moteur utilise `35` et signale
  `capacite_groupe_hypothese = true`.
- Les tests SIM-01 utilisent un groupe réel de capacité `15` afin de ne pas
  confondre une hypothèse avec une donnée.
- Le groupe réel n'est jamais modifié : capacité et statut sont vérifiés après
  exécution.

---

## F.7 Risques résiduels

| ID | Risque | Gravité | Mitigation |
|---|---|---|---|
| SIM01-S1 | Règle « croissance uniquement » portée par le catalogue, contournable via un scénario libre | MOYEN | Contrat documenté ; le moteur reste générique ; un futur LOOP peut ajouter un profil de scénario |
| SIM01-S2 | Seuils d'alerte 15 % / 95 % non institutionnels | MOYEN | Signalés comme hypothèses dans les résultats |
| SIM01-S3 | Capacité par défaut 35 | MOYEN | `capacite_groupe_hypothese` explicite ; tests avec groupe réel |
| SIM01-S4 | Anomalie socle « taux absents » | MOYEN | Taux requis par SIM-01 ; anomalie documentée (R1) |
| SIM01-S5 | Catalogue global sans isolation facultaire | INFO | Aucune donnée métier exposée ; choix assumé ST-14.00 |

---

## F.8 Verdict de phase

**Sécurité : PASS_WITH_RESERVATIONS.** Les contrôles critiques sont prouvés :
permission, périmètre, zéro écriture métier, idempotence, absence de PII. Les
réserves sont des choix de contrat ou des hypothèses moteur, pas des failles
d'accès.

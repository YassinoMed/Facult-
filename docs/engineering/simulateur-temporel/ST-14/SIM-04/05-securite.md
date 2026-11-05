# SIM-04 — 05 · Sécurité et intégrité des données

**Scénario :** SIM-04 — Admissions exceptionnelles  
**Date :** 2026-11-03  
**Verdict sécurité :** PASS_WITH_RESERVATIONS  

---

## F.1 Contrôle d'accès et permissions

- **Permissions requises :**
  - consultation : `simulation.view` ;
  - exécution : `simulation.executer` ;
  - paramétrage : `simulation.gerer`.
- **Validation HTTP :** tout appel direct sans `simulation.executer` retourne
  `403 Forbidden`. Aucune exécution n'est persistée en cas de refus
  (`test_sim04_permissions_et_isolation_facultaire`).

---

## F.2 Isolation multi-tenant et facultaire

- Un administrateur de la faculté B tentant d'exécuter un scénario rattaché à
  la faculté A reçoit `403 Forbidden`.
- Aucun effectif, cohorte ou projection de la faculté A n'est exposé dans le
  message d'erreur.
- Le périmètre est résolu côté serveur (`FacultePerimetre`) ; le client ne
  peut pas l'élargir par un paramètre.

---

## F.3 Zéro écriture sur les données officielles

Le choc ponctuel est un **calcul**, jamais une inscription :

- `etudiants` : nombre et empreinte d'attributs strictement identiques avant
  et après simulation ;
- `inscriptions_academiques` : aucun changement ;
- `groups` : aucun groupe créé, modifié ou supprimé ; la capacité du groupe
  réel reste intacte ;
- `facultes`, `niveaux` : strictement inchangés.

Seules les tables techniques autorisées reçoivent les résultats :

- `executions_temporelles` : 1 enregistrement par exécution non idempotente ;
- `snapshots_executions` : $H + 1$ enregistrements (pas 0 à $H$).

---

## F.4 Validation stricte des entrées

| Contrôle | Catalogue | Moteur |
|---|---|---|
| Paire indissociable | ✅ (schéma : deux champs requis) | ✅ (`DomainException`) |
| `pas_exceptionnel ≥ 1` | ✅ (schéma `min: 1`) | ✅ |
| `pas_exceptionnel ≤ horizon` | ✅ (`validerReglesSim04`) | ✅ |
| `admissions_exceptionnelles ≥ 1` | ✅ (schéma `min: 1`) | ✅ |
| Taux cohérents (somme ≤ 1.0001) | ✅ (`validerReglesCommunes`) | ✅ |
| Bornes de delta | ✅ | ✅ |

Un appel direct au service, hors catalogue, reste donc protégé par
`validerCriteres`.

---

## F.5 Réserves de sécurité

- `SIM04-SEC-1` : le volume du choc n'est pas plafonné par la capacité
  d'accueil (choc exogène). Un volume très élevé produit un déficit de
  groupes, pas un refus : c'est le comportement attendu d'un outil de
  simulation, mais l'utilisateur doit interpréter `depassement_capacite`.
- `SIM04-SEC-2` : le contrôle d'accès repose sur les permissions existantes ;
  aucune permission nouvelle n'est introduite. Toute évolution du référentiel
  IAM s'applique automatiquement.

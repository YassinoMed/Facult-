# SIM-03 — 05 · Sécurité et intégrité des données

**Scénario :** SIM-03 — Gel des admissions  
**Date :** 2026-11-02  
**Verdict sécurité :** PASS_WITH_RESERVATIONS  

---

## F.1 Contrôle d'accès et permissions

- **Permissions requises :**
  - Consultation : `simulation.view`
  - Exécution : `simulation.executer`
  - Paramétrage : `simulation.gerer`
- **Validation HTTP :**
  Tout appel direct sans permission `simulation.executer` déclenche un code HTTP `403 Forbidden` (`test_sim03_permissions_et_isolation_facultaire`).
  Aucune exécution n'est enregistrée en base en cas de refus.

---

## F.2 Isolation multi-tenant et facultaire

- Un administrateur restreint à une faculté (ex: Faculté B) tentant d'exécuter un scénario rattaché à une autre faculté (Faculté A) se voit refuser l'accès par un code HTTP `403 Forbidden`.
- Aucune donnée concernant les effectifs, cohortes ou projections de la faculté A n'est transmise dans les messages d'erreur.

---

## F.3 Zéro écriture sur les données officielles

La simulation du gel des admissions ne modifie en aucun cas les données réelles de l'établissement :
- Table `etudiants` : nombre et empreinte d'attributs strictement identiques avant et après simulation.
- Table `inscriptions_academiques` : aucun changement.
- Table `groups` : aucun groupe physique créé, modifié ou supprimé.
- Tables `facultes` et `niveaux` : strictement inchangées.

Seules les tables techniques autorisées reçoivent les résultats de calcul :
- `executions_temporelles` : 1 enregistrement par exécution non idempotente.
- `snapshots_executions` : $H + 1$ enregistrements (de $p = 0$ à $p = H$).

---

## F.4 Réserves de sécurité

- `SIM03-SEC-1` : La durée du gel est validée dans `CatalogueScenarios::validerParametres` et dans `ServiceSimulationTemporelle::validerCriteres`. Un appel avec code libre est bien protégé par le validateur du moteur qui vérifie $1 \le dureeGel \le horizon$.


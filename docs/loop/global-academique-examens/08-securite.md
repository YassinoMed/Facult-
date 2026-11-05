# LOOP GLOBAL MASTER — ACADÉMIQUE & EXAMENS · 08 · Sécurité

**Date :** 2026-11-03  
**Périmètre :** permissions, isolation facultaire, journaux, concurrence  
**Verdict sécurité :** PASS_WITH_RESERVATIONS  

---

## 1. Modèle d'autorisation

| Couche | Mécanisme constaté |
|---|---|
| Routes | middleware `auth`, `admin`, `secondaire.verrou`, `module:evaluation` + permissions **littérales** `can:*` par route (aucun wildcard). |
| Politique | `EvaluationPolicy::dansPerimetre` (programme / groupe / UE → programme → faculté) + `peutAccederFaculte()` + `pourFaculteId`. |
| Service | Les services sensibles re-vérifient la permission (publication, campagne). |
| Client | Aucun `faculte_id` fourni par le client n'est utilisé pour l'autorisation. |

Relevé du `00-audit-global.md` (2026-11-02) : **191 routes** évaluation /
examens / simulation, **6 routes** `acquis`, publication incluse dans
évaluations.

---

## 2. Journaux et immuabilité

| Journal | Nature | Preuve |
|---|---|---|
| `PlanningEvenement` | append-only, horodaté | `ExamGouvernanceDoubleValidationTest` |
| `ExamenEvenement` (`evaluation_examen_evenements`) | append-only, transitions | `ExamenCycleVieTest` |
| `NoteEpreuveHistorique` | historique de notes, pas d'écrasement | campagnes industrialisation |
| Audit admin | journal centralisé | tests IAM |

Aucune correction rétroactive n'est autorisée : une correction produit une
nouvelle entrée.

---

## 3. Exécution réelle du 2026-11-03

```bash
php artisan test --filter="IamPermissionsEtCycleDeVieTest|ExamGouvernanceSecuriteConcurrenceTest|MigrationHistoriqueDryRunLoop41Test"
```

```json
{"tool":"phpunit","result":"passed","tests":27,"passed":27,"assertions":101,"duration_ms":1541}
```

- **27 / 27 tests**, **101 assertions**, 0 échec.
- Les tests d'autorisation HTTP et de concurrence couverts par cette suite
  passent ; les suites multi-processus nécessitant `pcntl` restent
  inévaluables localement (limite d'environnement).

---

## 4. Écritures et données sensibles

- Aucune écriture sur `etudiants`, `inscriptions_academiques`, `notes`,
  `groups` par les simulations et les groupes virtuels.
- Aucune PII dans les logs applicatifs ; les messages d'erreur d'autorisation
  ne divulguent ni effectifs ni identités.
- Les exports de groupes virtuels sont contrôlés par le périmètre facultaire.

---

## 5. Réserves de sécurité

| ID | Réserve | Gravité | Traitement |
|---|---|---|---|
| `SEC-A1` | Routes modules sans `mfa.obligatoire` (constat systémique `ANOM-A9`) | MOYEN | Point d'architecture transverse ; non corrigé localement pour ne pas créer d'incohérence entre modules. |
| `SEC-A2` | Suites de concurrence MySQL/`pcntl` non exécutables sur SQLite `:memory:` | INFO | Limite d'environnement documentée ; 4 occurrences déjà connues. |
| `SEC-A3` | Ratio de capacité `0.50` non validé institutionnellement | MOYEN | Réserve métier `ACQ-DEC-04`. |
| `SEC-A4` | Revue humaine G40 non bloquante | INFO | Conforme à la décision ; jamais masquée dans les sorties. |

---

## 6. Verdict

**PASS_WITH_RESERVATIONS.** Les contrôles d'accès, l'isolation facultaire et
l'immuabilité des journaux sont prouvés. Les réserves sont transverses ou
environnementales ; aucune n'autorise un contournement du circuit de
gouvernance.

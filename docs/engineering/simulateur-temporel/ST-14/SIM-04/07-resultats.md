# SIM-04 — 07 · Résultats détaillés des vérifications

**Scénario :** SIM-04 — Admissions exceptionnelles  
**Date :** 2026-11-03  
**État mathématique :** 100 % conforme aux cas de référence  

---

## E.1 Concordance des cas de calcul

### Cas 1 — Choc ponctuel, progression gelée (H = 3, C = 5, p* = 2)

- Référence théorique :
  - p = 1 : admissions = 10, total = 70
  - p = 2 : admissions = 15, total = 85
  - p = 3 : admissions = 10, total = 95
- Résultat mesuré : `admissions_par_periode = [10, 15, 10]`,
  `totaux = [60, 70, 85, 95]`, `total_final = 95`.
- Écart : **0**.
- Point de contrôle clé : le pas 3 vaut 10, **pas 15**. Le choc n'est donc ni
  composé ni reporté.

### Cas 2 — Choc ponctuel avec flux actifs (H = 2, C = 5, p* = 2)

- Référence théorique :
  - initial : 60 (L1 = 10, L2 = 20, L3 = 30) ;
  - p = 1 : L1 = 13, L2 = 11, L3 = 19, total = 43 ;
  - p = 2 : L1 = 19, L2 = 10, L3 = 12, total = 41.
- Résultat mesuré : `totaux = [60, 43, 41]`,
  snapshots `['L1' => 13, 'L2' => 11, 'L3' => 19]` puis
  `['L1' => 19, 'L2' => 10, 'L3' => 12]`.
- Conservation vérifiée : $60 + 10 - 15 - 12 = 43$ ;
  $43 + 15 - 10 - 7 = 41$. Écart : **0**.

### Cas 3 — Capacité au pas final (périmètre L1, groupe de 15)

| Variante | Effectif final | Groupes requis | Déficit | Places manquantes |
|---|---|---|---|---|
| Sans choc | 30 | 2 | 1 | 15 |
| Choc 5 au pas 2 | 35 | 3 | 2 | 20 |

- Résultat mesuré : identique au tableau ; `depassement_capacite = true` pour
  la variante avec choc.
- Le groupe réel (`SIM04-G1`, capacité 15, actif) est inchangé après
  exécution. Écart : **0**.

---

## E.2 Contrôles transverses

| Contrôle | Résultat |
|---|---|
| Idempotence (même scénario, même graine) | Même `id`, même `hash_resultat`, 1 seule exécution |
| Reproductibilité (autre graine) | Nouvelle exécution, variante 0 identique |
| Permissions | `403` sans droit, `403` inter-faculté |
| Écritures métier | 0 (comptages et empreinte identiques) |
| Tables techniques | 1 exécution + $H+1$ snapshots |
| Validation définition | 3 rejets prouvés, 0 scénario persisté |
| Non-régression | 87 tests antérieurs inchangés |

---

## E.3 Synthèse

Tous les cas de référence calculés hors moteur concordent exactement. Les
bornes (pas = 1 et pas = horizon), le choc minimal, l'absence de composition
et la conservation des effectifs sont prouvés par des assertions indépendantes.
Aucune assertion n'a été affaiblie pour obtenir le vert.

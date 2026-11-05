# SIM-06 — 03 · Architecture et conception

**Scénario :** SIM-06 — Récupération démographique  
**Famille :** 1 — Démographie et admissions  
**Nature :** Extension temporelle du moteur unique (`extension_temporelle`)

---

## C.1 Responsabilités architecturales

```
┌────────────────────────────────────────────────────────────┐
│ CatalogueScenarios (ST-14.00, contrat v1.0)                 │
│ - Expose SIM-06 (disponible, version 1, schemaSim06)        │
│ - Trio requis : profondeur ∈ ]0;1], durées ≥ 1              │
│ - validerReglesSim06 : d + r ≤ horizon, profondeur > 0      │
│ - Retire SIM-06 de planifies() : 7 disponibles / 38         │
└───────────────────────────┬────────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────────┐
│ ServiceSimulationTemporelle (Moteur v1.5)                   │
│ - validerCriteres : trio indissociable, bornes, durées,     │
│   d + r ≤ horizon, incompatibilités gel / choc / cible      │
│ - simulerVariante reçoit $recuperation = {d, r, profondeur} │
│ - Branche de creux : référence composée × facteur(p)        │
│ - Snapshots enrichis : admissions_reference, facteur, phase │
│ - Mesures : référence, écarts, cumul, creux                 │
│ - Classement / non_conformes exposent les quatre champs     │
│   via champsSpecifiques() (cible SIM-05 + récupération)     │
└───────────────────────────┬────────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────────┐
│ MoteurEvenements / HorlogeSimulee                           │
│ - Trajectoire réelle avec creux et retour à la référence    │
│ - Capacités, budget, alertes : inchangés                    │
└────────────────────────────────────────────────────────────┘
```

---

## C.2 Décisions de conception

| Décision | Justification |
|---|---|
| Creux **multiplicatif** sur la trajectoire de référence | Le creux est un écart relatif au fond ; il se compose avec n'importe quelle tendance `delta` sans second taux. |
| Rampes **linéaires** | Forme progressive minimale : trois paramètres suffisent, aucune courbe arbitraire ; une autre forme exigerait un paramètre de forme dédié. |
| Retour **exact** au pas `d + r` | Garanti par construction ($f(d+r) = 1$), indépendamment des arrondis de taux. |
| Règle `d + r ≤ horizon` | Sans elle, la récupération ne serait pas observable : le scénario ne démontrerait pas son objet. |
| Trio **indissociable** | La profondeur sans durées (ou l'inverse) est ininterprétable ; schéma + moteur le refusent. |
| Profondeur ∈ ]0 ; 1] | 0 = pas de creux ; 1 = creux total (borne atteignable, aucune admission négative). |
| **Incompatibilités** gel / choc / cible | Chaque profil a son scénario ; les combiner rendrait la lecture ambiguë. |
| Référence stockée dans le snapshot | Les restitutions sont construites depuis les valeurs produites, sans recalcul parallèle (zéro divergence de formule). |
| Champs **additifs** | Aucune structure parallèle, aucune table, rétrocompatibilité stricte. |

---

## C.3 Intégration dans l'exécution

1. `executer()` construit `$recuperation = {duree_baisse, duree_recuperation,
   profondeur}` si les trois clés sont présentes, sinon `null`.
2. `simulerVariante(..., $recuperation)` :
   - branche creux : `$reference = round(baseL1 × (1+delta)^pas)` puis
     `$admissions = round($reference × $facteur)` ;
   - snapshot enrichi (`admissions_reference`, `facteur_recuperation`,
     `phase_recuperation`) ;
   - mesures enrichies (référence, écarts, cumul, creux).
3. La branche gel / fond existante n'est **pas modifiée** : le mode creux
   est évalué en premier, mais il est exclusif par validation.
4. Les closures sensibilité / Monte-Carlo propagent `$recuperation` (comme
   le gel et le choc en v1.3) : les analyses OAT/MC restent cohérentes avec
   la trajectoire principale.
5. Classement et `non_conformes` exposent les quatre champs via
   `champsSpecifiques()` (couvre SIM-05 et SIM-06). Sans les paramètres,
   **aucune clé** n'est ajoutée.

---

## C.4 Déterminisme et intégrité

- Le trio fait partie de `$scenario->criteres` : il entre dans
  `hash_entrees` et `hash_resultat`.
- Idempotence : mêmes entrées → exécution existante ; aucune nouvelle
  exécution en double.
- La rampe est purement arithmétique (division entière ou flottante
  déterministe) : aucune dépendance à `now()`, `mt_rand` ou à l'ordre
  d'itération.
- Les snapshots persistés (variante 0) incluent la référence, le facteur et
  la phase : la trajectoire est auditable ligne à ligne.

---

## C.5 Rétrocompatibilité

| Scénario | Comportement |
|---|---|
| SOCLE / SIM-01 → SIM-05 | Inchangés : trio absent, branche historique seule exécutée |
| Exécutions v1.4 et antérieures | Stockées telles quelles ; version moteur tracée par exécution |
| Clés de mesures | Les quatre champs SIM-06 ne sont présents que si le trio est fourni |

---

## C.6 Isolation des données officielles

Aucune écriture hors des trois tables techniques. La référence et le
facteur sont des agrégats d'admissions : aucune PII, aucune identité.
L'adaptateur reste en lecture seule.

# SIM-07 — 03 · Architecture et conception

**Scénario :** SIM-07 — Amélioration de la réussite  
**Famille :** 2 — Réussite, redoublement et abandon  
**Nature :** Nouvelle capacité analytique du moteur unique (`analyse_nouvelle`)

---

## C.1 Responsabilités architecturales

```
┌────────────────────────────────────────────────────────────┐
│ CatalogueScenarios (ST-14.00, contrat v1.0)                 │
│ - Expose SIM-07 (disponible, version 1, schemaSim07)        │
│ - taux requis (référence) + taux_ameliore requis (complet)  │
│ - validerReglesSim07 : somme ≤ 1, passage strictement >     │
│ - Retire SIM-07 de planifies() : 8 disponibles / 37         │
└───────────────────────────┬────────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────────┐
│ ServiceSimulationTemporelle (Moteur v1.6)                   │
│ - validerCriteres : deux jeux complets, bornes, sommes,     │
│   passage strictement supérieur, incompatibilités           │
│   gel / choc / cible / creux                                │
│ - executer : trajectoire PRINCIPALE avec taux_ameliore      │
│   + trajectoire de RÉFÉRENCE parallèle avec taux (mêmes     │
│   admissions, même delta — non persistée en snapshots)      │
│ - simulerVariante retourne aussi $sortants (partants du     │
│   dernier niveau, auparavant jetés)                         │
│ - analyserReussite() : hausse, totaux_ref, écarts,          │
│   écart final, sortants comparés, effet cumulé              │
│ - Analyses OAT/MC : criteres passés avec taux = améliore    │
│ - champsSpecifiques() expose les six champs SIM-07          │
└───────────────────────────┬────────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────────┐
│ MoteurEvenements / HorlogeSimulee                           │
│ - La progression des DEUX trajectoires est réutilisée       │
│   telle quelle : aucune formule de flux dupliquée           │
└────────────────────────────────────────────────────────────┘
```

---

## C.2 Décisions de conception

| Décision | Justification |
|---|---|
| **Deux jeux de taux complets déclarés** | Aucune compensation inventée : l'origine de la hausse (redoublement↓, abandon↓, reste) appartient au scénario. |
| Trajectoire principale = **améliorée** | Le scénario simule l'amélioration ; la référence est le témoin. |
| Référence **parallèle non persistée** | Seule la variante 0 de la trajectoire principale alimente `snapshots_executions` (pas de doublement de stockage). |
| `$sortants` restitués en **3ᵉ élément** de `simulerVariante` | Les appelants existants déstructurent 2 éléments ; l'ajout n'impacte aucun appel (PHP ignore l'élément supplémentaire). |
| **Aucun changement du générateur de variantes** | Les variantes continuent de ne varier que le delta ; les taux sont un contrat du scénario. |
| Analyses OAT/MC sur `taux_ameliore` | Cohérence avec la trajectoire principale : analyser le taux de référence aurait décrit une autre exécution. |
| Doublement du calcul borné | Variantes ≤ 20, horizon ≤ 6 : coût marginal, mesuré. |

---

## C.3 Intégration dans l'exécution

1. `executer()` construit `$tauxAmeliore` (garde de forme : trio complet,
   taux de référence présent, incompatibilités gel/choc/cible/creux).
2. Boucle des variantes :
   - `$tauxScenario = $tauxAmeliore ?? ($criteres['taux'] ?? null)` ;
   - trajectoire principale : `simulerVariante(..., $tauxScenario, ...)`,
     snapshots conservés ;
   - si `taux_ameliore` : trajectoire de référence
     `simulerVariante(..., $criteres['taux'], ...)` (gel/choc/creux `null`),
     puis fusion `analyserReussite()` dans les mesures.
3. Analyses :
   - `$criteresAnalyses = $criteres` avec `taux` remplacé par
     `taux_ameliore` si présent ;
   - transmis à `AnalyseurSensibilite` et `SimulateurMonteCarlo` (les
     closures propagent déjà gel/choc/creux).
4. Le classement expose les six champs SIM-07 via `champsSpecifiques()` ;
   sans `taux_ameliore`, **aucune clé** n'est ajoutée.

---

## C.4 Déterminisme et intégrité

- `taux_ameliore` entre dans `hash_entrees` et `hash_resultat`.
- La trajectoire de référence est recalculée à l'identique à chaque appel :
  aucune dépendance à l'horloge, à `mt_rand` ou à l'ordre d'itération.
- Idempotence conservée : mêmes entrées → même exécution, aucun doublon.
- Les snapshots persistés restent ceux de la trajectoire **principale**
  (variante 0, pas 0 → H).

---

## C.5 Rétrocompatibilité

| Scénario | Comportement |
|---|---|
| SOCLE / SIM-01 → SIM-06 | Inchangés : `taux_ameliore` absent, branche historique seule. |
| Sortants | Nouveau 3ᵉ élément de retour **interne** ; aucune signature publique modifiée. |
| Exécutions v1.5 et antérieures | Lisibles, jamais réécrites ; version moteur tracée. |

---

## C.6 Isolation des données officielles

Aucune écriture hors des trois tables techniques. La référence parallèle est
**en mémoire** : elle ne crée ni exécution, ni snapshot supplémentaire.
Aucune PII : agrégats d'effectifs uniquement.

# SIM-04 — 03 · Architecture et conception

**Scénario :** SIM-04 — Admissions exceptionnelles  
**Famille :** 1 — Démographie et admissions  
**Nature :** Extension temporelle canonique du moteur unique (`extension_temporelle`)

---

## C.1 Responsabilités architecturales

```
┌────────────────────────────────────────────────────────────┐
│ CatalogueScenarios (ST-14.00, contrat v1.0)                 │
│ - Expose SIM-04 (disponible, version 1, schemaSim04)        │
│ - Valide pas_exceptionnel ∈ [1, horizon_annees]             │
│ - Valide admissions_exceptionnelles ≥ 1                     │
│ - Exige la paire (pas, choc) et les taux                    │
└───────────────────────────┬────────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────────┐
│ ServiceSimulationTemporelle (Moteur v1.3)                   │
│ - Reçoit pas_exceptionnel / admissions_exceptionnelles      │
│ - Construit $chocAdmissions = {pas, admissions} | null      │
│ - Transmet $chocAdmissions à simulerVariante                │
│ - Ajoute le choc APRÈS la trajectoire de fond (et le gel)   │
│ - Ne compose jamais le choc sur les pas suivants            │
└───────────────────────────┬────────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────────┐
│ MoteurEvenements / HorlogeSimulee                           │
│ - Déclenche la rentrée annuelle au 1er septembre            │
│ - Génère snapshots, capacités et indicateurs                │
└────────────────────────────────────────────────────────────┘
```

---

## C.2 Déterminisme et intégrité

- **Intégration dans `hash_entrees` :** `pas_exceptionnel` et
  `admissions_exceptionnelles` font partie de `$scenario->criteres` ; ils sont
  donc sérialisés et hashés. Deux appels identiques (mêmes critères, graine,
  année, variantes, version moteur) retournent la même exécution.
- **Variante 0 :** le choc est déterministe et s'applique à **toutes** les
  variantes au même pas ; seule la trajectoire de fond varie avec `delta`.
  La variante 0 reste la référence non stochastique.
- **Sensibilité et Monte-Carlo :** la closure `$simulateurFn` propage
  désormais `dureeGel` **et** `chocAdmissions`. Sans cette correction, les
  analyses OAT/MC auraient recalculé une trajectoire sans gel ni choc,
  contredisant le résultat principal.
- **Rétrocompatibilité :** si les deux paramètres sont absents (SOCLE,
  SIM-01, SIM-02, SIM-03), le comportement antérieur est strictement
  inchangé.
- **Ordre d'application :** gel → trajectoire de fond → choc additif. Cet
  ordre est explicite et testé indirectement par la non-régression SIM-03.

---

## C.3 Isolation des données officielles

Le moteur lit les effectifs via `AdaptateurEffectifsCohortes` sans écriture
sur les tables métier (`etudiants`, `inscriptions_academiques`, `notes`,
`groups`). Les résultats sont exclusivement stockés dans :

- `executions_temporelles` (1 exécution par jeu d'entrées non idempotent) ;
- `snapshots_executions` ($H + 1$ snapshots, du pas 0 au pas $H$).

---

## C.4 Décisions de conception

| Décision | Justification |
|---|---|
| Choc **additif** et non multiplicatif | Le besoin est un volume d'étudiants supplémentaires, pas un taux. |
| Choc **unique** par scénario | Le contrat SIM-04 reste minimal ; les chocs multiples relèvent de CR-06. |
| Validation **double** (catalogue + moteur) | Un scénario créé hors catalogue (service direct) reste protégé. |
| Aucune nouvelle table | Le choc est un critère du scénario, pas une entité persistée. |
| Capacité estimée au pas final | Réutilisation de l'estimateur existant ; le pic intermédiaire est une réserve explicite (Famille 4). |

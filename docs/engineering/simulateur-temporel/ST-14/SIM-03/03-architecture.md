# SIM-03 — 03 · Architecture et conception

**Scénario :** SIM-03 — Gel des admissions  
**Famille :** 1 — Démographie et admissions  
**Nature :** Extension temporelle canonique du moteur unique (`extension_temporelle`)

---

## C.1 Responsabilités architecturales

```
┌────────────────────────────────────────────────────────┐
│ CatalogueScenarios (ST-14.00, contrat v1.0)             │
│ - Expose SIM-03 (disponible, version 1, schemaSim03)   │
│ - Valide duree_gel_annees ∈ [1, horizon_annees]        │
└──────────────────────────┬─────────────────────────────┘
                           │
                           ▼
┌────────────────────────────────────────────────────────┐
│ ServiceSimulationTemporelle (Moteur v1.2)              │
│ - Reçoit criteres['duree_gel_annees'] (optionnel)      │
│ - Transmet dureeGel à simulerVariante                  │
│ - Applique admissions = 0 si p <= dureeGel             │
│ - Applique reprise composée si p > dureeGel            │
└──────────────────────────┬─────────────────────────────┘
                           │
                           ▼
┌────────────────────────────────────────────────────────┐
│ MoteurEvenements / HorlogeSimulee                      │
│ - Déclenche la rentrée annuelle au 1er septembre       │
│ - Génère les snapshots et les indicateurs              │
└────────────────────────────────────────────────────────┘
```

---

## C.2 Déterminisme et intégrité

- **Intégration dans `hash_entrees` :**  
  Le critère `duree_gel_annees` fait partie intégrante du tableau `$scenario->criteres`. Il est donc automatiquement sérialisé et hashé dans `hash_entrees`.
  Deux requêtes ayant la même durée de gel, la même graine et les mêmes paramètres pointent vers la même exécution en base (idempotence stricte).
- **Invariance de la variante 0 :**  
  La variante 0 applique rigoureusement le delta saisi sans perturbation stochastique. Pendant le gel, les admissions sont nulles pour toutes les variantes.
- **Rétrocompatibilité :**  
  Si `duree_gel_annees` est absent (cas des scénarios SOCLE-EFFECTIFS, SIM-01 et SIM-02), la formule existante reste active sans altération.

---

## C.3 Isolation des données officielles

Le moteur lit les effectifs via `AdaptateurEffectifsCohortes` sans écriture sur les tables métier officielles (`etudiants`, `inscriptions_academiques`, `notes`, `groups`).
Les résultats sont exclusivement enregistrés dans :
- `executions_temporelles`
- `snapshots_executions`


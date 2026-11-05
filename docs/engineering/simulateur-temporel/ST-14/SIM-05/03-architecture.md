# SIM-05 — 03 · Architecture et conception

**Scénario :** SIM-05 — Croissance supérieure à la cible  
**Famille :** 1 — Démographie et admissions  
**Nature :** Nouvelle capacité analytique du moteur unique (`analyse_nouvelle`)

---

## C.1 Responsabilités architecturales

```
┌────────────────────────────────────────────────────────────┐
│ CatalogueScenarios (ST-14.00, contrat v1.0)                 │
│ - Expose SIM-05 (disponible, version 1, schemaSim05)        │
│ - taux_cible_croissance requis ∈ [0 ; 2.0]                  │
│ - validerReglesSim05 : delta > cible, delta ≥ 0, borne ≥ 0  │
│ - Retire SIM-05 de planifies() : 6 disponibles / 39         │
└───────────────────────────┬────────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────────┐
│ ServiceSimulationTemporelle (Moteur v1.4)                   │
│ - validerCriteres : type, bornes, delta > cible,            │
│   incompatibilité gel / choc                                │
│ - analyserCibleCroissance() : trajectoire cible, écarts,    │
│   cumul, booléen de dépassement                             │
│ - Champs de cible ajoutés aux mesures de CHAQUE variante    │
│ - Classement / non_conformes exposent les quatre champs     │
│ - Snapshots, totaux, capacités : INCHANGÉS                  │
└───────────────────────────┬────────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────────┐
│ MoteurEvenements / HorlogeSimulee                           │
│ - Trajectoire réelle inchangée (delta, taux, horizon)       │
│ - La cible est calculée APRÈS le pas, sans réécriture       │
└────────────────────────────────────────────────────────────┘
```

---

## C.2 Décisions de conception

| Décision | Justification |
|---|---|
| Cible **explicite**, jamais par défaut | Une cible institutionnelle non saisie ne doit pas être inventée ; son absence = pas de comparaison. |
| Trajectoire cible **composée** avec la même formule que la trajectoire réelle | Une seule formule de composition dans le moteur ; aucun profil arbitraire. |
| Comparaison **par pas** sur les admissions arrondies | Fidélité au résultat réellement produit ; les arrondis sont assumés. |
| Cumul des écarts arrondis, pas différence de cumuls exacts | Traçabilité : `ecart_cible_cumule = Σ ecarts_cible_par_periode`. |
| Champs **additifs** aux mesures | Aucune structure parallèle, aucune table, aucun recalcul hors moteur. |
| Validation **double** (catalogue + moteur) | Un scénario créé hors catalogue reste protégé. |
| Incompatibilité **gel / choc** | Une interruption ou un choc rend la lecture de croissance non interprétable ; le message oriente vers SIM-03 / SIM-04. |
| Capacité et snapshots **hors périmètre** | La cible est descriptive : elle ne modifie ni les cohortes ni l'estimation de capacité. |

---

## C.3 Intégration dans l'exécution

1. `executer()` lit `taux_cible_croissance` dans les critères
   (`array_key_exists`) et calcule `baseL1` depuis l'état initial.
2. Pour chaque variante, après `simulerVariante`, si la cible est présente :
   `analyserCibleCroissance()` fusionne quatre champs dans les mesures
   (`+=`, aucune clé existante écrasée).
3. `EvaluateurScenarios`, `ClasseurScenarios`, `ComparateurProjections`,
   `EstimateurCapacitesGroupes`, `ProjecteurBudgetaire` et le gestionnaire
   d'alertes reçoivent les mêmes mesures enrichies ; ils n'en dépendent pas.
4. Les closures sensibilité / Monte-Carlo ne changent pas : elles n'utilisent
   que `total_final`, qui reste inchangé.
5. Le classement expose les quatre champs via `champsCibleCroissance()` ;
   en l'absence de cible, **aucune clé** n'est ajoutée.

---

## C.4 Déterminisme et intégrité

- `taux_cible_croissance` fait partie de `$scenario->criteres` : il entre
  dans `hash_entrees` et dans `hash_resultat`.
- Deux exécutions identiques (mêmes critères, graine, année, variantes,
  version moteur) retournent l'exécution existante : l'ajout de la cible ne
  crée pas de doublon.
- La cible s'applique à **toutes** les variantes, y compris la variante 0 :
  chaque variante exploratoire est comparée à la même cible saisie.
- Les variantes dont le delta généré passe sous la cible affichent des
  écarts négatifs — information utile, jamais bloquée : seule la variante de
  base doit être strictement supérieure à la cible.

---

## C.5 Rétrocompatibilité

| Scénario | Comportement |
|---|---|
| SOCLE-EFFECTIFS | Inchangé : paramètre absent, aucune clé ajoutée. |
| SIM-01 / SIM-02 | Inchangés. |
| SIM-03 | Inchangé ; combinaison avec la cible refusée explicitement. |
| SIM-04 | Inchangé ; combinaison avec la cible refusée explicitement. |
| Exécutions historiques v1.3 | Stockées telles quelles ; jamais rejouées ni fusionnées ; la version moteur est tracée (`version_moteur`). |

---

## C.6 Isolation des données officielles

Aucune écriture hors des trois tables techniques
(`scenarios_temporels`, `executions_temporelles`, `snapshots_executions`).
La cible est un critère du scénario, pas une entité persistée. L'adaptateur
d'effectifs reste en lecture seule ; aucune PII n'entre dans les résultats
(agrégats uniquement).

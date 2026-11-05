# SIM-09 — 03 · Architecture et conception

**Nature :** Descripteur d'axe générique pour la comparaison de réussite (`analyse_nouvelle`)  
**Moteur :** v1.8

---

## C.1 Trois formes d'entrée, une seule mécanique

```
┌────────────────────────────────────────────────────────────┐
│ CatalogueScenarios                                          │
│ - SIM-07 : taux_ameliore (alias : axe passage / hausse)     │
│ - SIM-08 : taux_degrade   (alias : axe passage / baisse)    │
│ - SIM-09 : taux_modifie + axe_variation + sens_variation    │
│            (schéma : axe=redoublement, sens=hausse)         │
└───────────────────────────┬────────────────────────────────┘
                            ▼
┌────────────────────────────────────────────────────────────┐
│ ServiceSimulationTemporelle (v1.8)                          │
│ - Blocs de validation par forme, exécutés dans l'ordre :    │
│   SIM-07 → SIM-08 → générique (exclusion mutuelle)          │
│ - Normalisation en descripteur interne commun :             │
│   $reussiteTaux / $reussiteCle (sens_axe) / $reussiteΔ      │
│ - Trajectoire principale = jeu simulé, témoin = taux        │
│ - analyserReussite(..., $reussiteCle, $reussiteΔ)           │
│ - OAT/MC : criteresAnalyses.taux = jeu simulé               │
└───────────────────────────┬────────────────────────────────┘
                            ▼
┌────────────────────────────────────────────────────────────┐
│ champsSpecifiques() : + hausse_|baisse_ × redoublement|abandon │
└────────────────────────────────────────────────────────────┘
```

---

## C.2 Décisions

| Décision | Justification |
|---|---|
| **Alias préservés** (`taux_ameliore`/`taux_degrade`) | Contrats publiés SIM-07/08 : messages, champs et schémas strictement inchangés ; la normalisation est interne. |
| **Descripteur générique explicite** (`axe_variation`, `sens_variation`) | Le moteur ne devine jamais l'axe : il est déclaré et validé ; chaque scénario épingle son couple au niveau du schéma (`valeurs`). |
| **Exclusion mutuelle par ordre** des blocs | SIM-07 (premier) rejette `taux_degrade` et la forme générique ; SIM-08 rejette la forme générique ; aucun contrôle inverse (pas de branche morte). |
| Un seul axe focal validé strictement | Les autres taux restent déclarés/libres — aucune compensation inventée. |
| SIM-13 hors périmètre | Les combinaisons multi-axes relèveront d'un contrat distinct (audit dédié). |

---

## C.3 Intégration

1. `executer()` : construction du descripteur (alias ou générique) +
   gardes ; la boucle utilise `$reussiteTaux` comme jeu simulé.
2. `criteresAnalyses['taux'] = $reussiteTaux` (toutes formes).
3. `hash_entrees` : les clés génériques y entrent naturellement (critères).

## C.4 Rétrocompatibilité

| Forme | Effet |
|---|---|
| SOCLE / SIM-01→06 | Inchangés. |
| SIM-07 (`taux_ameliore`) | Messages et champ `hausse_passage` strictement préservés (suites inchangées). |
| SIM-08 (`taux_degrade`) | Idem (`baisse_passage`). |
| Sans forme réussite | Aucune clé ajoutée. |

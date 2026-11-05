# SIM-08 — 03 · Architecture et conception

**Scénario :** SIM-08 — Dégradation de la réussite  
**Famille :** 2 — Réussite, redoublement et abandon  
**Nature :** Miroir de SIM-07 sur la machinerie de comparaison (`analyse_nouvelle`)

---

## C.1 Responsabilités architecturales

```
┌────────────────────────────────────────────────────────────┐
│ CatalogueScenarios (ST-14.00, contrat v1.0)                 │
│ - Expose SIM-08 (disponible, version 1, schemaSim08)        │
│ - taux requis (référence) + taux_degrade requis (complet)   │
│ - validerReglesSim08 : somme ≤ 1, passage strictement <     │
│ - Retire SIM-08 de planifies() : 9 disponibles / 36         │
└───────────────────────────┬────────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────────┐
│ ServiceSimulationTemporelle (Moteur v1.7)                   │
│ - validerCriteres : SIM-07 rejette taux_degrade (exclusion  │
│   mutuelle, exécuté en premier) ; SIM-08 valide le miroir   │
│ - executer : $tauxDegrade (garde de forme) ; trajectoire    │
│   principale = dégradée, témoin = référence ; analyserReussite│
│   généralisé (clé de variation + magnitude positive)        │
│ - $criteresAnalyses.taux = taux_degrade (OAT/MC cohérents)  │
│ - champsSpecifiques() += baisse_passage                     │
└───────────────────────────┬────────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────────┐
│ MoteurEvenements / HorlogeSimulee                           │
│ - La progression des DEUX trajectoires est réutilisée       │
│   telle quelle (même fonction que SOCLE / SIM-01→07)        │
└────────────────────────────────────────────────────────────┘
```

---

## C.2 Décisions de conception

| Décision | Justification |
|---|---|
| **Miroir** `taux_degrade` (et non réutilisation de `taux_ameliore`) | Le nom doit dire la direction ; les messages de validation restent vrais ; le contrat SIM-07 publié n'est pas cassé. |
| `analyserReussite()` **généralisé** | La clé de variation (`hausse_passage` / `baisse_passage`) et la magnitude positive sont des paramètres ; les cinq champs neutres sont partagés — zéro duplication. |
| **Exclusion mutuelle** vérifiée par le bloc SIM-07 | Les blocs de validation s'exécutent chronologiquement ; SIM-07 (premier) rejette la présence de `taux_degrade`, rendant tout contrôle inverse inutile (aucune branche morte). |
| Trajectoire dégradée = **principale** | Le scénario simule la dégradation ; la référence est le témoin (symétrie SIM-07). |
| Référence **en mémoire**, non persistée | Aucun doublement de snapshots ; seule la variante 0 de la trajectoire principale est stockée. |
| Analyses OAT/MC recentrées sur `taux_degrade` | Cohérence avec la trajectoire principale (mécanique SIM-07 réutilisée). |

---

## C.3 Intégration dans l'exécution

1. `executer()` : garde SIM-07 (exclusion `taux_degrade` incluse), puis
   garde SIM-08 (forme, référence, incompatibilités gel/choc/cible/creux).
2. Boucle des variantes :
   - `$tauxSimule = $tauxAmeliore ?? $tauxDegrade ?? ($criteres['taux'] ?? null)` ;
   - trajectoire principale : snapshots conservés ;
   - si mode réussite (hausse **ou** baisse) : trajectoire témoin
     `simulerVariante(..., $criteres['taux'], ...)`, puis fusion
     `analyserReussite(..., $cleVariation, $variationPositive)`.
3. Analyses : `taux` remplacé par le jeu **simulé** dans la copie des
   critères transmise à l'OAT et au Monte-Carlo.

---

## C.4 Déterminisme et intégrité

- `taux_degrade` entre dans `hash_entrees` et `hash_resultat`.
- La trajectoire témoin est recalculée à l'identique (temps construit,
  aucune dépendance à l'horloge).
- Idempotence conservée ; snapshots persistés = trajectoire principale
  uniquement.

---

## C.5 Rétrocompatibilité

| Scénario | Comportement |
|---|---|
| SOCLE / SIM-01 → SIM-07 | Inchangés : `taux_degrade` absent, aucune clé nouvelle. |
| SIM-07 | Contrat publié strictement inchangé (`hausse_passage` toujours produit pour `taux_ameliore`). |
| Exécutions v1.6 et antérieures | Lisibles, jamais réécrites. |

---

## C.6 Isolation des données officielles

Aucune écriture hors des trois tables techniques ; référence parallèle en
mémoire ; agrégats d'effectifs uniquement (aucune PII).

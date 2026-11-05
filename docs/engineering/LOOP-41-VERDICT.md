# LOOP 41 — VERDICT D'ÉTAPE

**Date :** 31 octobre 2026
**Périmètre :** audit, cartographie, simulation et réconciliation de la migration historique
**Environnement audité :** `local`, MySQL `inscription_etudiants` (`127.0.0.1:3306`)
**Nature du verdict :** technique (exécution du dry-run) **et** métier (autorisation de migrer)

> Ce document remplace une version antérieure qui attribuait des signatures institutionnelles
> (« Directeur des Études », « Expert Réglementaire », « RSSI ») sans qu'aucune de ces instances
> n'ait été saisie. **Aucune validation institutionnelle n'a été obtenue.** Les lignes correspondantes
> ont été supprimées : une signature inventée n'est pas une preuve, c'est un faux.

---

## 1. Verdict en deux temps

| Dimension | Verdict | Justification |
|---|---|---|
| **Dry-run et réconciliation** (technique) | **`AUTO_PASS`** | 10 exigences vérifiées, 9 tests / 36 assertions, 111 tests de non-régression verts, 2 152 dossiers simulés, 0 écriture, intégrité inchangée, exécution reproductible |
| **Migration effective des données** (métier) | **`BLOCKED_BUSINESS`** | Aucune règle de formation confirmée, 52 matières orphelines non arbitrées, 100 dossiers sans inscription, 2 009 inscriptions sans décision textuelle et 16 décisions normalisées seulement, aucune validation institutionnelle |

`AUTO_PASS` porte **uniquement** sur la qualité technique du dry-run. Il ne vaut ni conformité
réglementaire, ni autorisation d'écriture, ni recette de production.

---

## 2. Ce qui est acquis

| Élément | Preuve |
|---|---|
| Inventaire historique vérifié contre la base réelle | `LOOP-41-INVENTAIRE-HISTORIQUE.md` — 2 152 étudiants, 2 090 inscriptions, 553 notes, 74 matières, 11 UE, 1 maquette, 5 délibérations, 16 décisions, 131 historiques |
| Cartographie du référentiel | `LOOP-41-MAPPING-REFERENTIEL.md` |
| Anomalies documentées | `LOOP-41-ANOMALIES.md` — ANOM-41-01 à ANOM-41-04 |
| Dry-run exécuté sur données réelles | `LOOP-41-DRY-RUN.md` — 2 152 dossiers, 0 erreur, 11,09 s, code de sortie `0` |
| Réconciliation des totaux | `LOOP-41-RECONCILIATION.md` — écarts ÉCAR-41-01 à ÉCAR-41-04 expliqués |
| Anomalie ANOM-41-02 implémentée | `SimulationMigrationHistoriqueService` : absence d'acte officiel ⇒ `MIGRABLE_APRES_VALIDATION`, aucun transfert, aucune exclusion, note intacte |
| Distinction régime / formation | `ReglementationAcquisService::evaluerCouvertureReglementaire()` : `FORMATION`, `REGIME`, `AUCUNE` |
| Commande de dry-run reproductible | `php artisan acquis:dry-run-migration` — lecture seule, sans PII, contrôle d'intégrité avant/après |
| Tests | 9 tests / 36 assertions (banc LOOP 41) ; 111 tests / 1 388 assertions (domaine Acquis) ; Pint propre |

---

## 3. Pourquoi la migration effective est bloquée

### RES-41-01 — Aucune règle de formation confirmée

`evaluerStatutRegle()` renvoie `REGLE_CONFIRMEE` dès que le **régime national** est identifiable.
Or aucun des 12 programmes réels (`DEMO-LGL`, `PAR-GEST`, `PAR-ECO`, `PAR-INFO`, `PAR-FDEG-DEG`,
`PAR-FMP-MED`, `PAR-FLSH-LSH`, `PAR-FSIT-ING`, `PAR-FSE-EDU`, `PAR-FMD-DENT`, `LIC-INFO-REG`, `PTEST`)
ne correspond à une fiche de `config/acquis_reglementation.php > matrice_formations`.

Conséquence mesurée par `evaluerCouvertureReglementaire()` : couverture = **`REGIME`**, jamais `FORMATION`.
Les 2 018 dossiers classés `MIGRABLE_AUTOMATIQUEMENT_SOUS_REGLE_CONFIRME` le sont donc sur la seule base
du régime national. Ce n'est pas une validation de leurs règles de formation (seuils, quotas,
compensation locale, capitalisation).

### RES-41-02 — 52 matières orphelines non arbitrées

52 matières ont `ue_id IS NULL`. Elles produisent 34 dossiers en `REVUE_HUMAINE_REQUISE`.
Aucune règle ne permet de les rattacher automatiquement : le rattachement est une décision pédagogique,
pas une inférence technique.

### RES-41-03 — Dossiers non traitables automatiquement

| Situation | Dossiers |
|---|---:|
| Aucune inscription académique répertoriée | 100 |
| Notes sur matières orphelines | 34 |
| **Total en revue humaine** | **134** |

### RES-41-04 — Absence de décision normalisée

| Indicateur | Valeur vérifiée |
|---|---:|
| Inscriptions académiques | 2 090 |
| `inscriptions.decision` NULL | 2 009 |
| `inscriptions.decision` renseignée | 81 |
| Étudiants avec décision normalisée publiée | 14 |
| Décisions normalisées (`evaluation_decisions`) | 16 |

Le dry-run reste possible (ANOM-41-02 classe ces dossiers en `MIGRABLE_APRES_VALIDATION` s'ils portent
des crédits), mais une migration effective exigerait une base de décisions fiable : 2 009 inscriptions
n'ont aucune décision textuelle et seules 16 décisions normalisées existent pour 2 090 inscriptions.

### Absence de validation institutionnelle

Aucune instance (direction des études, scolarité, affaires juridiques, RSSI) n'a été saisie ni n'a
répondu. Les validations métier, réglementaires et institutionnelles ne peuvent pas être produites
par l'agent. Elles conditionnent la levée du blocage.

---

## 4. Réserves reportées

| Réserve | Origine | État |
|---|---|---|
| RES-40-01 | ENIT 8,00 `CONCORDANT_NON_CERTIFIE` | Ouverte |
| RES-40-02 | Programmes en co-construction | Ouverte |
| RES-40-03 | Archives antérieures à 2018 | Ouverte |
| RES-41-01 | Couverture réglementaire au niveau régime seulement | **Nouvelle** |
| RES-41-02 | 52 matières orphelines | **Nouvelle** |
| RES-41-03 | 134 dossiers en revue humaine | **Nouvelle** |
| RES-41-04 | 2 009 inscriptions sans décision textuelle, 16 décisions normalisées | **Nouvelle** |

Aucune de ces réserves n'autorise une exclusion, une sanction ou une écriture automatique.

---

## 5. Autorisations et interdictions

### Autorisé

- Lecture, audit, simulation et réconciliation sur la base locale.
- Poursuite du développement de LOOP 42 **en préparation** : le pilote peut être codé, testé et
  documenté, avec un périmètre **vide ou inactif par défaut**.
- Exécution de la commande de dry-run à tout moment (lecture seule).

### Interdit sans levée explicite du blocage

- Toute écriture de migration dans les tables académiques.
- Toute modification automatique de notes, crédits, décisions ou parcours.
- Toute activation d'un périmètre pilote sur des dossiers réels.
- `migrate:fresh`, `db:wipe`, purge ou réinitialisation de tables historiques.
- Toute présentation des 2 018 dossiers « migrables » comme une validation de masse : 2 110 dossiers
  n'ont aucun crédit reconnu, et seuls 42 en portent (1 230 crédits au total).

---

## 6. Conditions de levée du blocage

La migration effective ne pourra être envisagée que si les quatre conditions suivantes sont réunies,
dans cet ordre :

1. **Rattachement des 12 programmes réels** à des fiches de formation confirmées, ou décision explicite
   que le régime national suffit — décision à acter par l'autorité compétente, pas par l'agent.
2. **Arbitrage des 52 matières orphelines** par la scolarité (rattachement UE ou statut « hors maquette »).
3. **Traitement des 100 dossiers sans inscription** (régularisation ou classement archivé).
4. **Validation institutionnelle écrite** du périmètre pilote et des règles appliquées.

Tant que ces conditions ne sont pas réunies, LOOP 42 reste une **préparation technique**
(`BLOCKED_BUSINESS` pour toute exécution sur dossiers réels).

---

## 7. Verdict final

```
LOOP 41 — DRY-RUN / RÉCONCILIATION : AUTO_PASS
LOOP 41 — MIGRATION EFFECTIVE        : BLOCKED_BUSINESS
```

- Aucune migration n'a été exécutée.
- Aucune donnée n'a été modifiée.
- Aucune validation institutionnelle n'est revendiquée.
- LOOP 42 est autorisée en préparation uniquement, avec périmètre inactif par défaut.

**Signataire technique :** agent d'ingénierie (exécution et mesures vérifiables).
**Signataires métier / réglementaire / institutionnel :** **aucun** — instances non saisies à ce jour.

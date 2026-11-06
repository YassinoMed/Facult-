# SIM-02 — 09 · Verdict

**Scénario :** SIM-02 — Baisse des admissions
**Sous-LOOP :** ST-14.12 / SIM-02
**Verdict :** `PASS_WITH_RESERVATIONS`
**Rattaché au Lot 0 :** Lot 0 clôturé (`PASS_WITH_RESERVATIONS`)

---

## A. Verdict et niveau

| critère | valeur |
|---------|--------|
| Verdict | **PASS_WITH_RESERVATIONS** |
| Domaine | `configuration` du moteur existant |
| Extensions moteur | aucune |
| Routes / permissions / tables nouvelles | aucune |
| Bump `VERSION_MOTEUR` | aucun (1.1 conservé) |
| Tests | 7 tests / 56 assertions, 0 échec (SIM-02) |
| Module global | 79 tests / 731 assertions, 0 échec |
| Pint | OK (`vendor/bin/pint --test --dirty`) |

---

## B. Critères de validation remplis

| critère | statut | preuve |
|---------|--------|--------|
| Code implémenté | ✅ | `schemaSim02()` (l.406) + `validerReglesSim02()` (l.1602) |
| Nom défini | ✅ | « Baisse des admissions | Famille `configuration` |
| Objectif déclaré | ✅ | `00-audit.md` / `01-specification.md` |
| Version | ✅ | `1.0` (formules héritées du moteur) |
| Périmètre | ✅ | configuration, lecture seule, 3 tables techniques |
| Paramètres | ✅ | `delta_admissions, bornes, horizon_annees, taux, niveau_id` |
| Hypothèses | ✅ | `01-specification.md` |
| Formules | ✅ | `02-formules.md` (version 1.0, aucune modification) |
| Contraintes | ✅ | SIM02-R1 (`delta < 0`), SIM02-R2 (`bornes.max <= 0`) |
| Indicateurs | ✅ | `07-resultats.md` |
| Hash de signature | ✅ | ID de scénario + `hash_resultat` |
| Documentation | ✅ | 9 documents, dossier `ST-14/SIM-02/` complet |
| Tests exécutés | ✅ | 7 / 56 assertions, 0 échec |
| Aucun test déclaré PASS sans exécution | ✅ | tests exécutés avant verdict |

**Aucun critère manquant.**

---

## C. Preuves d'exécution

### C.1. Tests SIM-02

```
tests/Feature/SimulationTemporelleSim02Test.php  →  7 tests / 56 assertions / 0 échec
```

- `test_sim02_baisse_composee_avec_reference_calculee_independamment` — formule
  `admissions(n) = round(baseL1 × (1+delta)^n)` vérifiée avec référence à la main
  (`[60, 69, 77, 84]`)
- `test_sim02_borne_minimale_et_admissions_jamais_negatives` — `delta = -0.5`,
  admissions `[5, 3, 1, 1, 0, 0]`, aucune admission négative
- `test_sim02_conservation_avec_taux_explicites` — `totaux [60, 41, 30]`,
  invariants snapshots (`total = Σ cohortes`, `cohortes >= 0`)
- `test_sim02_memes_entrees_idempotentes_et_graine_reproductible` —
  idempotence + graine (pas de doublon `ExecutionTemporelle`)
- `test_sim02_capacite_et_absence_de_modification_des_groupes` — `total_final 34`
  restreint à L1, groupe réel non modifié
- `test_sim02_permissions_et_isolation_facultaire` — 403 sans permission,
  403 inter-facultés
- `test_sim02_zero_ecriture_metier_et_tables_techniques_seules` — empreinte
  étudiant témoin inchangée, seules les tables techniques écrites

### C.2. Tests catalogue (SIM-02 intégré)

```
tests/Feature/SimulationTemporelleCatalogueTest.php  →  13 tests / 307 assertions / 0 échec
```

- `test_sim02_est_un_scenario_de_baisse_stricte` :
  `schemaSim02()['taux']['requis'] == true` (vs `schemaSocle()` faux)
  ; `validerParametres('SIM-02')` accepte le jeu valide, rejette
  la somme des taux `0.9 + 0.2 + 0.0 = 1.1 > 1.0001` (`'> 1'`) et les clés
  inconnues (`'inconnu'`).
- **3 scénarios disponibles** (SOCLE-EFFECTIFS, SIM-01, SIM-02)
- **42 planifiés**

### C.3. Tests historiques inchangés

```
tests/Feature/SimulationTemporelleSim01Test.php  →  52 tests / 312 assertions / 0 échec
```

### C.4. Formatage

```
vendor/bin/pint --test --dirty  →  OK
```

### C.5. Exécution combinée SIM-02 + catalogue

```
--filter="SimulationTemporelleSim02Test|SimulationTemporelleCatalogueTest"
  → 30 tests / 646 assertions / 0 échec
```

### C.6. Exécution module complet

```
php artisan test  →  79 tests / 731 assertions / 0 échec
```

---

## D. Réserves ouvertes (justification du `_WITH_RESERVATIONS`)

| N° | Réserve | Impact | Statut |
|----|---------|--------|--------|
| SIM02-R1 | Règle « baisse uniquement » (`delta_admissions < 0`) est dans le catalogue, pas dans le moteur | Faible (double garde : catalogue + bornes du formulaire) | **OUVERTE** |
| SIM02-R2 | Taux REQUIS imposés par `schemaAvecTauxRequis()` pour compenser une anomalie du moteur (cohortes à zéro sans taux) | Moyen (dépendance à l'anomalie SIM-01) | **OUVERTE — héritée de SIM-01** |
| SIM02-R3 | borne `-0.5` n'est pas réintroduite dans `validerReglesSim02()` (elle vit dans le formulaire + validation générique) | Faible | **OUVERTE** |
| SIM02-R4 | Absence de test direct sur le refus `DomainException` de `validerReglesSim02()` (le refus est testé via `validerParametres('SIM-02')` au catalogue) | Faible | **OUVERTE** |
| SIM02-R5 | Capacité par défaut 35 et `baseL1 = reset($cohortes)` : règles de modélisation, pas règles institutionnelles validées | Faible | **OUVERTE — héritée de SIM-01** |

**Note** : les réserves SIM02-R2/SIM02-R5 sont **héritées de SIM-01** et sont
documentées dans `ST-14/SIM-01/09-verdict.md`. Elles sont partagées avec
SIM-02 mais ne sont pas introduites par lui.

---

## E. Conformité au protocole ST-14

| étape du protocole | réalisation |
|--------------------|-------------|
| A — Audit | ✅ `00-audit.md` |
| B — Spécification | ✅ `01-specification.md` + `02-formules.md` |
| C — Conception | ✅ `03-architecture.md` |
| D — Développement | ✅ `04-implementation.md` |
| E — Validation math. | ✅ `02-formules.md` (§D) + tests E avec référence à la main |
| F — Sécurité | ✅ `05-securite.md` (permissions, zéro mutation, isolation) |
| G — Tests | ✅ `06-tests.md` + `07-resultats.md` |
| H — UX | ✅ `08-guide-utilisation.md` |
| I — Documentation | ✅ dossier `ST-14/SIM-02/` complet (9 docs) |
| J — Verdict | ✅ `09-verdict.md` : PASS_WITH_RESERVATIONS |
| Exécution tests | ✅ 7 / 56, 0 échec |
| Verdict | ✅ `PASS_WITH_RESERVATIONS` |

**Toutes les étapes A→J sont complètes.**

---

## F. Non-conformités constatées (aucune bloquante)

- Aucune non-conformité bloquante.
- Les réserves ci-dessus sont documentées et ne bloquent pas la mise en
  production du catalogue (lecture seule, configuration).

---

## G. Conclusion

SIM-02 est **clôturé et disponible** :

- ✅ entrée de catalogue valide, schéma avec taux requis, validation de domaine
  (`delta_admissions < 0`, `bornes.max <= 0`),
- ✅ formule de décroissance composée prouvée avec référence calculée à la main,
- ✅ tests exécutés (7 / 56, 0 échec) : formules, borne `-0.5`, conservation,
  déterminisme/graine, capacité, permissions, zéro écriture métier,
- ✅ documentation complète (9 documents),
- ✅ aucune modification du moteur, aucune route/permission/table nouvelle,
- ✅ non-régression préservée (52 tests SIM-01 inchangés, module à 79/731).

Le verdict est `PASS_WITH_RESERVATIONS` car :

1. la règle « baisse uniquement » n'est pas durcie côté moteur (SIM02-R1),
2. la correction de l'anomalie des taux à zéro est un contournement catalogue
   (SIM02-R2),
3. la borne `-0.5` n'est pas réintroduite dans la validation SIM-02 (SIM02-R3),
4. l'absence de test direct sur le refus `DomainException` (SIM02-R4),
5. les règles de modélisation (baseL1, capacité 35) ne sont pas institutionnelles
   (SIM02-R5).

Ces réserves sont acceptées car SIM-02 reste un **scénario de configuration en
lecture seule** : il ne peut ni modifier ni corrompre les données métier, et la
validation catalogue est complétée par les bornes dures du formulaire et du
moteur.

---

## H. Prochaine étape

Arrêt à la frontière de **SIM-03 (Gel des admissions)** :

- SIM-03 explore la borne `delta = 0` comme gel **explicite**,
- SIM-03 distingue le gel (intentionnel) de l'extinction (accidentelle, SIM-02).

SIM-02 est clôturé. Le registre global est mis à jour.

---

*Document de référence : `docs/engineering/simulateur-temporel/ST-14/SIM-02/09-verdict.md`*
*Version : 1.0 — verdict PASS_WITH_RESERVATIONS*

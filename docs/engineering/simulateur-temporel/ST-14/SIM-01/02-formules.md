# SIM-01 — 02 · Formules, conventions et cas de référence

**Version de formules :** 1.0
**Moteur :** `ServiceSimulationTemporelle` (version `1.1`)
**Principe :** aucune formule nouvelle. SIM-01 **configure** le moteur et
**expose** une trajectoire déjà calculée. Les formules ci-dessous décrivent le
comportement réel, pas une réécriture.

---

## C.1 Formules du moteur

| ID | Formule | Unité | Source |
|---|---|---|---|
| F-ADM | `admissionsₚ = round(baseL1 × (1 + delta)^p)` | personnes | `simulerVariante` |
| F-PASS | `partentᵢ = round(effectifᵢ × taux_passage)` | personnes | taux saisi |
| F-RED | `redoublantsᵢ = round(effectifᵢ × taux_redoublement)` | personnes | taux saisi |
| F-RESTE | `autresᵢ = effectifᵢ − partentᵢ − redoublantsᵢ` | personnes | convention du code |
| F-TOT | `totalₚ = Σ effectifsₚ` | personnes | snapshots |
| F-VAR | `(totalₚ − totalₚ₋₁) / totalₚ₋₁`, arrondi 4 ; 0 si totalₚ₋₁ = 0 | proportion | `variations_annuelles` |
| F-ADM-P | `admissions_par_periode = [(pas, admissionsₚ)]` pour `p = 1..h` | personnes | **exposition SIM-01**, reprise des snapshots |

### Conventions

- `baseL1 = reset($cohortesDepart)` : premier niveau trié de la cohorte.
  Avec `niveau_id`, la cohorte ne contient que ce niveau.
- `autresᵢ` contient l'abandon **et** le reste d'arrondi. La conservation
  s'écrit donc avec `autresᵢ` observé, pas avec le seul taux d'abandon.
- `totaux` inclut le pas 0 (état initial) ; `admissions_par_periode` commence
  au pas 1.
- Les arrondis sont des `round()` PHP (demi vers le haut), appliqués flux par
  flux et niveau par niveau.

### Conservation exacte

`totalₚ = totalₚ₋₁ + admissionsₚ − diplômésₚ − autresₚ`

où `diplômésₚ = partent` du dernier niveau et `autresₚ = Σ autresᵢ`.

---

## C.2 Capacités (moteur existant)

| ID | Formule | Unité |
|---|---|---|
| F-CAP-U | capacité unitaire = moyenne arrondie des groupes actifs du périmètre, sinon `35` (hypothèse) | places |
| F-GRP | `groupes_requisᵢ = ceil(effectifᵢ / capacite_unitaire)` | groupes |
| F-GRP-T | `groupes_requis_total = Σ groupes_requisᵢ` | groupes |
| F-DEF | `deficit = max(0, groupes_requis_total − groupes_actifs)` | groupes |
| F-SAT | `saturation = total / capacité_installée × 100` si capacité > 0 | % |
| F-PLACES | `places_manquantes = max(0, total − capacité_installée)` | places |

La capacité par défaut est signalée par `capacite_groupe_hypothese = true` ;
elle n'est jamais présentée comme une capacité mesurée.

---

## C.3 Comparaison et cible

| ID | Formule | Unité |
|---|---|---|
| F-ECART | `ecart_absolu = projection − référence` | personnes |
| F-ECART-PCT | `ecart_absolu / référence × 100`, arrondi 2 | % |
| F-CIBLE | `ecart_cible = round(projection − cible, 0)` | personnes |
| F-CIBLE-PCT | `ecart_cible / cible × 100`, arrondi 2 | % |

La variante de référence est la variante d'index 0 (delta saisi) ; la variante
retenue est la première du classement conforme.

---

## C.4 Cas de référence calculés hors moteur

Périmètre synthétique : `L1 = 10`, `L2 = 20`, `L3 = 30`, total initial `60`.

### Cas 1 — croissance composée, progression gelée

`delta = 0.1`, `horizon = 3`, `taux = {passage: 0, redoublement: 1, abandon: 0}`.

| Pas | Admissions | L1 | L2 | L3 | Total |
|---|---|---|---|---|---|
| 0 | — | 10 | 20 | 30 | 60 |
| 1 | `round(10×1.1) = 11` | 21 | 20 | 30 | 71 |
| 2 | `round(10×1.21) = 12` | 33 | 20 | 30 | 83 |
| 3 | `round(10×1.331) = 13` | 46 | 20 | 30 | 96 |

Attendu : `totaux = [60, 71, 83, 96]`, `admissions = [11, 12, 13]`.

### Cas 2 — admissions constantes, conservation

`delta = 0`, `horizon = 2`, `taux = {0.5, 0.3, 0.2}`.

**Pas 1**

- L3 : partent `round(30×0.5)=15` (diplômés), redoublants `9`, autres `6`.
- L2 : partent `10`, redoublants `6`, autres `4` → L3 reçoit `10 + 9 = 19`.
- L1 : partent `5`, redoublants `3`, autres `2` → L2 reçoit `5 + 6 = 11`.
- L1 final : `3 + 10 = 13`.
- Total : `13 + 11 + 19 = 43`.
- Conservation : `60 + 10 − 15 − 12 = 43`.

**Pas 2**

- L3 : partent `round(19×0.5)=10` (diplômés), redoublants `6`, autres `3`.
- L2 : partent `round(11×0.5)=6`, redoublants `3`, autres `2` → L3 reçoit `6 + 6 = 12`.
- L1 : partent `round(13×0.5)=7`, redoublants `4`, autres `2` → L2 reçoit `7 + 3 = 10`.
- L1 final : `4 + 10 = 14`.
- Total : `14 + 10 + 12 = 36`.
- Conservation : `43 + 10 − 10 − 7 = 36`.

Attendu : `totaux = [60, 43, 36]`, snapshots `{L1:13, L2:11, L3:19}` puis
`{L1:14, L2:10, L3:12}`.

### Cas 3 — horizon maximal

`delta = 0.1`, `horizon = 6`, progression gelée.

Admissions : `11, 12, 13, 15, 16, 18` (arrondis successifs).
Total final : `60 + 85 = 145`.

### Cas 4 — périmètre restreint à L1

`niveau_id = L1`, `delta = 0.1`, `horizon = 3`, progression gelée.

- Total final : `10 + 11 + 12 + 13 = 46`.
- Cible `100` → `ecart_cible = −54`, `ecart_cible_pct = −54 %`.
- Un groupe réel de capacité `15` :
  - `ceil(46/15) = 4` groupes requis ;
  - `4 − 1 = 3` groupes de déficit ;
  - `46 − 15 = 31` places manquantes ;
  - saturation `306,7 %`.

---

## C.5 Limites mathématiques documentées

1. **Arrondis** : chaque flux est arrondi séparément ; la conservation n'est
   garantie qu'avec les flux observés (`autres`), pas avec les taux théoriques.
2. **Base des admissions** : `baseL1` est le premier niveau de la cohorte ;
   ce n'est pas nécessairement le niveau L1 du référentiel si le périmètre est
   restreint.
3. **Taux globaux** : aucune différenciation par niveau dans ce scénario.
4. **Delta composé** : pas de profil temporel (choc, reprise, gel daté).
5. **Capacité par défaut 35** : hypothèse moteur, jamais une mesure.

---

## C.6 Verdict de phase

**Validation mathématique : PASS.** Les quatre cas de référence sont
reproductibles à la main, la conservation est vérifiée niveau par niveau, et
aucune formule du moteur n'a été dupliquée ou modifiée.

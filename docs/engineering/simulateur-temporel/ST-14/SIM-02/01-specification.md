# SIM-02 — 01 · Spécification

**Scénario :** Baisse des admissions
**Famille :** 1 — Démographie et admissions
**Objectif :** projeter une diminution annuelle des nouvelles admissions et
mesurer ses conséquences sur les effectifs, les capacités et l'écart à une
cible éventuelle.

---

## A. Acteurs

| Acteur | Rôle |
|---|---|
| Responsable autorisé | Configure et exécute le scénario |
| Consultation | Consulte les résultats et le catalogue |
| Gouvernance | Propose / décide selon le circuit à quatre yeux existant |
| Autorité institutionnelle | Valide les hypothèses et seuils (non fournis par le code) |

---

## B. Population et périmètre

- Faculté réelle ou périmètre global, selon `faculte_id`.
- Niveau optionnel (`niveau_id`) : restreint l'état initial à un niveau.
- Groupes optionnels (`groupes`) : sous-périmètre d'un niveau.
- Année universitaire de référence obligatoire à l'exécution.
- Horizon de 1 à 6 ans (plafond moteur).
- Aucune donnée personnelle n'est exposée : seuls des agrégats sont
  manipulés.

---

## C. Paramètres

| Paramètre | Type | Obligatoire | Domaine | Unité |
|---|---|---|---|---|
| `delta_admissions` | nombre | oui | `[-0.5, 0[` | ratio annuel composé |
| `bornes.min` | nombre | oui | `[-0.5, delta]` | ratio |
| `bornes.max` | nombre | oui | `[delta, 0]` | ratio |
| `horizon_annees` | entier | oui | `[1, 6]` | années |
| `taux.passage` | nombre | oui | `[0, 1]` | ratio annuel |
| `taux.redoublement` | nombre | oui | `[0, 1]` | ratio annuel |
| `taux.abandon` | nombre | oui | `[0, 1]` | ratio annuel |
| `niveau_id` | entier | non | `≥ 1`, existant | identifiant |
| `groupes` | tableau d'entiers | non | groupes actifs du niveau | identifiants |

Contraintes :

- `passage + redoublement + abandon ≤ 1` (tolérance d'arrondi `1.0001`).
- `groupes` exige `niveau_id`.
- Aucune valeur par défaut n'est injectée par le validateur.

---

## D. Règle de domaine

1. **Baisse effective** : `delta_admissions < 0`.
2. **Pas de croissance déguisée** : `bornes.max ≤ 0`.
3. **Stabilité** (`delta = 0`) : relève de SIM-01 (admissions constantes).
4. **Gel explicite** (admissions interrompues) : relève de SIM-03.
5. **Borne moteur** : `delta ≥ -0.5`.

Ces règles découlent de l'objectif du scénario et des bornes du moteur.
Elles n'introduisent aucun seuil institutionnel nouveau.

---

## E. Hypothèses

- `baseL1` est l'effectif observé du premier niveau de la cohorte.
- Les admissions sont composées annuellement : elles diminuent de
  `|delta|` chaque année par rapport à l'année précédente, pas de façon
  linéaire.
- Les taux de passage, redoublement et abandon sont globaux et constants
  sur l'horizon.
- Les effectifs sont entiers (arrondi au plus proche).
- Une admission nulle produite par arrondi n'est pas un gel explicite.
- La capacité et la cible sont des références, pas des décisions.

---

## F. Résultats attendus

- `admissions_par_periode` : pas 1..horizon.
- `totaux` : pas 0..horizon.
- `total_final`, variations annuelles.
- Écart absolu / relatif / à la cible.
- Groupes théoriques requis, déficit, places manquantes.
- Alertes de variation et de saturation.
- Avertissements et limites d'interprétation.

---

## G. Cas limites

| Cas | Comportement attendu |
|---|---|
| `delta = 0` | Refusé par SIM-02, message renvoyant à SIM-01 |
| `delta > 0` | Refusé, message renvoyant à SIM-01 |
| `bornes.max > 0` | Refusé (variante de croissance possible) |
| `delta < -0.5` | Refusé par le schéma (borne moteur) |
| `delta` hors bornes | Refusé par les règles communes |
| `taux` absent | Refusé (schéma SIM-02) |
| `passage + redoublement + abandon > 1` | Refusé |
| Premier niveau vide | Admissions nulles ; limite documentée |
| Petit effectif + baisse extrême | Admissions nulles par arrondi, jamais négatives |
| Données de référence absentes | Audit qualité + avertissement du moteur |

---

## H. Critères d'acceptation

1. La baisse composée est conforme à une référence calculée hors moteur.
2. Les admissions ne sont jamais négatives.
3. La conservation des effectifs est vérifiée.
4. Les règles de domaine sont testées (acceptation et refus).
5. Aucune écriture métier.
6. Isolation facultaire et permissions vérifiées.
7. Documentation et registre à jour.

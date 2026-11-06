# SIM-13 — 01 · Spécification

**Scénario :** Crise académique combinée
**Famille :** 2 — Réussite, redoublement, abandon
**Objectif :** combiner plusieurs dégradations simultanées (passage↓,
redoublement↑, abandon↑) dans les limites du modèle et mesurer leur effet
joint sur la progression des cohortes et les sortants.

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
- Niveau optionnel (`niveau_id`), groupes optionnels (`groupes`, exige `niveau_id`).
- Année universitaire de référence obligatoire à l'exécution.
- Horizon de 1 à 6 ans (`PLAFOND_HORIZON`).
- Aucune donnée personnelle : agrégats uniquement.

---

## C. Paramètres

| Paramètre | Type | Obligatoire | Domaine | Unité |
|---|---|---|---|---|
| `delta_admissions` | nombre | oui | `[bornes.min, bornes.max]` | ratio annuel composé |
| `bornes.min` / `bornes.max` | nombre | oui | cohérentes, incluant `delta` | ratio |
| `horizon_annees` | entier | oui | `[1, 6]` | années |
| `taux` (référence) | objet | oui | `passage/redoublement/abandon ∈ [0,1]`, somme ≤ 1.0001 | ratios annuels |
| `taux_modifie` | objet | oui | complet (3 taux), `∈ [0,1]`, somme ≤ 1.0001 | ratios annuels |
| `axe_variation` | chaîne | oui | `passage` \| `redoublement` \| `abandon` | — |
| `sens_variation` | chaîne | oui | `hausse` \| `baisse` (apparié à l'axe) | — |
| `niveau_id` / `groupes` | entier / liste | non | existants, cohérents | identifiants |

Aucune valeur par défaut n'est injectée.

---

## D. Règles de domaine (contrat SIM-13)

1. **Paire focale dégradante** : `passage/baisse`, `redoublement/hausse` ou
   `abandon/hausse` — toute autre paire est refusée.
2. **Aucune amélioration** :
   - `taux_modifie.passage ≤ taux.passage`,
   - `taux_modifie.redoublement ≥ taux.redoublement`,
   - `taux_modifie.abandon ≥ taux.abandon`.
3. **Au moins deux dégradations STRICTES** parmi les trois axes — l'axe
   focal strict (contrat moteur) plus au moins un autre. Une dégradation
   isolée relève de SIM-08 / SIM-09 / SIM-11.
4. **Somme du jeu modifié ≤ 1.0001** (comme SIM-10/11/12).

Ces règles découlent de l'objectif (« combiner plusieurs dégradations ») et
ne créent aucun seuil institutionnel.

---

## E. Hypothèses

- Deux jeux de taux COMPLETS et saisis : `taux` (référence, trajectoire
  témoin) et `taux_modifie` (simulé, trajectoire principale).
- Admissions identiques dans les deux trajectoires (même `delta`) :
  l'écart isole strictement l'effet combiné des taux.
- `abandon` n'est PAS réinjecté dans la progression : une composante
  absorbée par le slack est exactement neutre ; aucun effet n'est inventé.
- Les effets combinés ne sont pas additifs : arrondis au plus proche
  (half-up), annulations partielles possibles (démontré par test).
- Aucun signe favorable supposé : l'effet joint dépend des mouvements
  déclarés.

---

## F. Résultats attendus

- Dégradation focale rappelée (`baisse_passage` / `hausse_redoublement` /
  `hausse_abandon`, 4 décimales, magnitude positive).
- `totaux` simulés et `totaux_reference_reussite`, périodes 0..horizon.
- `ecarts_reussite_par_periode` (signés) et `ecart_reussite_final`.
- `sortants_par_periode` (simulés vs référence) et `effet_sortants_cumules`.
- Effectifs, total final, capacités et alertes (moteur inchangé).
- OAT/Monte-Carlo autour des taux MODIFIÉS ; aucune sensibilité à
  l'abandon inventée.

---

## G. Cas limites

| Cas | Comportement attendu |
|---|---|
| Paire focale non dégradante | Refusée (catalogue) |
| Amélioration d'un axe | Refusée (catalogue) |
| Une seule dégradation stricte | Refusée (catalogue) — SIM-08/SIM-09/SIM-11 |
| Focal non strict (moteur) | Refusé au titre du descripteur |
| Somme modifiée > 1.0001 | Refusée (moteur + catalogue) |
| Gel / choc / cible / creux / alias | Refusés (clés inconnues du schéma) |
| Effets combinés qui s'annulent | Écarts `[0, 0]` possible — documenté, testé |
| Abandon absorbé par slack | Neutre — aucun effet inventé |

---

## H. Critères d'acceptation

1. Référence calculée hors moteur et vérifiée par test.
2. Au moins deux dégradations strictes vérifiées ; aucune amélioration.
3. Admissions identiques dans les deux trajectoires.
4. Règles de domaine testées (acceptation et refus, moteur + catalogue).
5. Aucune écriture métier ; permissions et isolation facultaire.
6. Documentation et registre à jour.

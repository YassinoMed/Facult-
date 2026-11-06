# SIM-19 — 09 · Verdict

**Scénario :** Rééquilibrage des niveaux
**Sous-LOOP :** ST-14.19
**Verdict :** `PASS_WITH_RESERVATIONS`

---

## A. Verdict et niveau

| critère | valeur |
|---|---|
| Verdict | **PASS_WITH_RESERVATIONS** |
| Domaine | `extension_par_niveau` (confirmé par l'audit) |
| Extensions moteur | **aucune** (`VERSION_MOTEUR = 2.0` conservée) |
| Routes / permissions / tables nouvelles | aucune |
| Tests | **5 tests / 39 assertions**, 0 échec (premier passage) |
| Catalogue | 23 tests / 648 assertions, 0 échec |
| Module global | **197 tests / 2183 assertions**, 0 échec |
| Pint | OK |

---

## B. Critères de validation remplis

| critère | statut | preuve |
|---|---|---|
| Code implémenté | ✅ | `sim19()`, `schemaSim19()`, `validerReglesSim19()` |
| Nom / famille / objectif | ✅ | « Rééquilibrage des niveaux », `cohortes_lmd`, directions de passage opposées |
| Version | ✅ | 1 (formules 1.0, héritées du moteur) |
| Périmètre | ✅ | catalogue uniquement ; lecture seule |
| Paramètres | ✅ | `taux` + `taux_par_niveau` ≥ 2 niveaux (aucune valeur par défaut) |
| Hypothèses | ✅ | `01-specification.md` §E |
| Formules | ✅ | `02-formules.md` — aucune formule nouvelle |
| Contraintes | ✅ | ≥ 2 niveaux, directions opposées, somme ≤ 1.0001 |
| Dépendances SIM-14/15/16 | ✅ | toutes clôturées avant SIM-19 |
| Indicateurs | ✅ | `07-resultats.md` |
| Hash / signature | ✅ | définition + `hash_resultat` (idempotence par graine) |
| Documentation | ✅ | dossier `ST-14/SIM-19/00 → 09` |
| Tests exécutés | ✅ | 5/39 + catalogue 23/648 + module 197/2183 |

---

## C. Preuves d'exécution

### C.1. Suite SIM-19 (premier passage)

```
5 tests / 39 assertions / 0 échec
- Référence manuelle : totaux [60, 41, 34] vs [60, 40, 31], écarts [1, 3], final 3
- Sortants [18, 10] vs référence [18, 11], effet −1
- Niveau facultatif nivel_focal restitué avec écart cible
- Refus définition : taux_par_niveau vide, triplet incomplet
- Refus définition : taux hors [0,1], somme > 1, amélioration interdite, gel, choc
- Refus catalogue : un seul niveau, même direction, niveau au taux global
- Idempotence + rétrocompatibilité
- Permissions 403, isolation facultaire 403, zéro écriture métier
```

### C.2. Catalogue

- 20 disponibles / 25 planifiés / 45 codes (intégrité vérifiée).
- SIM-19 à l'index 19 (disponible, dernier de LOT B).

### C.3. Non-régression

```
Historique SIM-01 → SIM-18 : inchangé
Module complet             : 197 tests / 2183 assertions
Pint --dirty               : OK
```

---

## D. Réserves ouvertes

| N° | Réserve | Impact | Statut |
|----|---------|--------|--------|
| SIM19-R1 | ≥ 2 niveaux surchargés requis ; un seul niveau relève de SIM-14/15 | Faible | OUVERTE |
| SIM19-R2 | Directions de passage opposées requises ; même direction refusée | Faible | OUVERTE |
| SIM19-R3 | Un niveau exactement au taux global ne compte pas comme direction | Faible | OUVERTE |
| SIM19-R4 | Dépend de SIM-14, SIM-15, SIM-16 | Faible | OUVERTE (respectée) |
| SIM19-R5 | `hash_entrees` historique incompatible (`VERSION_MOTEUR = 2.0`) | Faible | OUVERTE |

---

## E. Conformité au protocole A→J

| étape | réalisation |
|-------|-------------|
| A — Audit | ✅ `00-audit.md` — compatibilité `extension_par_niveau` confirmée |
| B — Spécification | ✅ `01-specification.md` + `02-formules.md` |
| C — Conception | ✅ `03-architecture.md` |
| D — Développement | ✅ `04-implementation.md` |
| E — Validation math. | ✅ référence manuelle (07-resultats.md) |
| F — Sécurité | ✅ `05-securite.md` + tests permissions / isolement |
| G — Tests | ✅ `06-tests.md` + `07-resultats.md` |
| H — UX | ✅ `08-guide-utilisation.md` |
| I — Documentation | ✅ dossier complet 00 → 09 |
| J — Verdict | ✅ ce document |

---

## F. Conclusion

SIM-19 est **clôturé et disponible** :

- ✅ contrat de domaine implémenté (≥ 2 niveaux, directions de passage opposées) ;
- ✅ aucune extension moteur, aucune route/permission/table ;
- ✅ 5 tests / 39 assertions verts au premier passage, référence manuelle ;
- ✅ non-régression stricte (module 197/2183) ;
- ✅ documentation complète.

LOT B (SIM-14 → SIM-19) est **clôturé en totalité** : 6 scénarios disponibles, famille 3 complète.

---

## G. Prochaine étape

Passage à **LOT C** (capacités et infrastructures, SIM-20 → SIM-25), bloqué par `L0-R7` (capacité par défaut 35 non institutionnelle) — à débloquer avant tout développement.

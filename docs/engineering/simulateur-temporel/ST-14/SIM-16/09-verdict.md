# SIM-16 — 09 · Verdict

**Scénario :** Flux déséquilibrés entre niveaux
**Sous-LOOP :** ST-14.16
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
| Code implémenté | ✅ | `sim16()`, `schemaSim16()`, `validerReglesSim16()` |
| Nom / famille / objectif | ✅ | « Flux déséquilibrés entre niveaux », `cohortes_lmd` |
| Version | ✅ | 1 (formules 1.0, héritées du moteur) |
| Périmètre | ✅ | catalogue uniquement ; lecture seule |
| Paramètres | ✅ | `taux` + `taux_par_niveau` ≥ 2 niveaux (aucune valeur par défaut) |
| Hypothèses | ✅ | `01-specification.md` §E |
| Formules | ✅ | `02-formules.md` — aucune formule nouvelle |
| Contraintes | ✅ | ≥ 2 niveaux, ≥ 2 triplets distincts, somme ≤ 1.0001 |
| Dépendances SIM-14/15 | ✅ | SIM-14 et SIM-15 clôturés avant SIM-16 |
| Indicateurs | ✅ | `07-resultats.md` |
| Hash / signature | ✅ | définition + `hash_resultat` (idempotence par graine) |
| Documentation | ✅ | dossier `ST-14/SIM-16/00 → 09` |
| Tests exécutés | ✅ | 5/39 + catalogue 23/648 + module 197/2183 |

---

## C. Preuves d'exécution

### C.1. Suite SIM-16 (premier passage)

```
5 tests / 39 assertions / 0 échec
- Référence manuelle : totaux, écarts, sortants
- Refus définition : un seul niveau, triplets identiques, triplet incomplet
- Refus définition : taux hors [0,1], somme > 1, gel, choc, cible, creux
- Idempotence + rétrocompatibilité
- Permissions 403, isolation facultaire 403, zéro écriture métier
```

### C.2. Catalogue

- 20 disponibles / 25 planifiés / 45 codes (intégrité vérifiée).
- SIM-16 à l'index 15 (disponible).

### C.3. Non-régression

```
Historique SIM-01 → SIM-15 : inchangé
Module complet             : 197 tests / 2183 assertions
Pint --dirty               : OK
```

---

## D. Réserves ouvertes

| N° | Réserve | Impact | Statut |
|----|---------|--------|--------|
| SIM16-R1 | ≥ 2 niveaux surchargés requis ; un seul niveau relève de SIM-14/15 | Faible | OUVERTE |
| SIM16-R2 | ≥ 2 triplets distincts requis ; triplets identiques refusés | Faible | OUVERTE |
| SIM16-R3 | Dépend de SIM-14 et SIM-15 | Faible | OUVERTE (respectée) |
| SIM16-R4 | `hash_entrees` historique incompatible (`VERSION_MOTEUR = 2.0`) | Faible | OUVERTE |

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

SIM-16 est **clôturé et disponible** :

- ✅ contrat de domaine implémenté (≥ 2 niveaux, ≥ 2 triplets distincts) ;
- ✅ aucune extension moteur, aucune route/permission/table ;
- ✅ 5 tests / 39 assertions verts au premier passage, référence manuelle ;
- ✅ non-régression stricte (module 197/2183) ;
- ✅ documentation complète.

---

## G. Prochaine étape

LOT B se poursuit avec **SIM-17** (cohorte exceptionnelle), **SIM-18** (double génération) et **SIM-19** (rééquilibrage des niveaux).

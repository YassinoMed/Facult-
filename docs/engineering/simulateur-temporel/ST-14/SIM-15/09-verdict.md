# SIM-15 — 09 · Verdict

**Scénario :** Blocage en L2
**Sous-LOOP :** ST-14.15
**Verdict :** `PASS_WITH_RESERVATIONS`

---

## A. Verdict et niveau

| critère | valeur |
|---|---|
| Verdict | **PASS_WITH_RESERVATIONS** |
| Domaine | `extension_par_niveau` (confirmé par l'audit) |
| Extensions moteur | **aucune** (`VERSION_MOTEUR = 2.0` conservée) |
| Routes / permissions / tables nouvelles | aucune |
| Tests | **4 tests / 44 assertions**, 0 échec (premier passage) |
| Catalogue | 23 tests / 648 assertions, 0 échec |
| Module global | **197 tests / 2183 assertions**, 0 échec |
| Pint | OK |

---

## B. Critères de validation remplis

| critère | statut | preuve |
|---|---|---|
| Code implémenté | ✅ | `sim15()`, `schemaSim15()`, `validerReglesSim15()` |
| Nom / famille / objectif | ✅ | « Blocage en L2 », `cohortes_lmd`, blocage redoublement L2 |
| Version | ✅ | 1 (formules 1.0, héritées du moteur) |
| Périmètre | ✅ | catalogue uniquement ; lecture seule |
| Paramètres | ✅ | `taux` + `taux_par_niveau[L2]` (aucune valeur par défaut) |
| Hypothèses | ✅ | `01-specification.md` §E |
| Formules | ✅ | `02-formules.md` — aucune formule nouvelle |
| Contraintes | ✅ | surcharge L2, redoublement strict, aucune amélioration, somme ≤ 1.0001 |
| Dépendance SIM-14 | ✅ | SIM-14 clôturé avant SIM-15 |
| Indicateurs | ✅ | `07-resultats.md` |
| Hash / signature | ✅ | définition + `hash_resultat` (idempotence par graine) |
| Documentation | ✅ | dossier `ST-14/SIM-15/00 → 09` |
| Tests exécutés | ✅ | 4/44 + catalogue 23/648 + module 197/2183 |

---

## C. Preuves d'exécution

### C.1. Suite SIM-15 (premier passage)

```
4 tests / 44 assertions / 0 échec
- Référence manuelle : totaux, écarts, sortants
- Refus définition : taux_par_niveau vide, triplet incomplet, taux hors [0,1]
- Refus définition : somme > 1, amélioration interdite, gel, choc
- Idempotence + rétrocompatibilité
- Permissions 403, isolation facultaire 403, zéro écriture métier
```

### C.2. Catalogue

- 20 disponibles / 25 planifiés / 45 codes (intégrité vérifiée).
- SIM-15 à l'index 14 (disponible).

### C.3. Non-régression

```
Historique SIM-01 → SIM-14 : inchangé
Module complet             : 197 tests / 2183 assertions
Pint --dirty               : OK
```

---

## D. Réserves ouvertes

| N° | Réserve | Impact | Statut |
|----|---------|--------|--------|
| SIM15-R1 | Même contrat de domaine que SIM-14, niveau focal `L2` | Faible | OUVERTE |
| SIM15-R2 | Dépend de SIM-14 : SIM-15 ne peut ouvrir avant clôture SIM-14 | Faible | OUVERTE (respectée) |
| SIM15-R3 | `hash_entrees` historique incompatible (`VERSION_MOTEUR = 2.0`) | Faible | OUVERTE |

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

SIM-15 est **clôturé et disponible** :

- ✅ contrat de domaine implémenté (blocage L2, redoublement strict, aucune amélioration) ;
- ✅ aucune extension moteur, aucune route/permission/table ;
- ✅ 4 tests / 44 assertions verts au premier passage, référence manuelle ;
- ✅ non-régression stricte (module 197/2183) ;
- ✅ documentation complète.

---

## G. Prochaine étape

LOT B se poursuit avec **SIM-16** (déséquilibre multi-niveaux), puis **SIM-17**, **SIM-18** (identité de cohorte) et **SIM-19** (rééquilibrage des niveaux).

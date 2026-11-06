# SIM-14 — 09 · Verdict

**Scénario :** Blocage en L1
**Sous-LOOP :** ST-14.14
**Verdict :** `PASS_WITH_RESERVATIONS`

---

## A. Verdict et niveau

| critère | valeur |
|---|---|
| Verdict | **PASS_WITH_RESERVATIONS** |
| Domaine | `extension_par_niveau` (confirmé par l'audit) |
| Extensions moteur | **aucune** (`VERSION_MOTEUR = 2.0` conservée) |
| Routes / permissions / tables nouvelles | aucune |
| Tests | **5 tests / 64 assertions**, 0 échec (premier passage) |
| Catalogue | 23 tests / 648 assertions, 0 échec |
| Module global | **197 tests / 2183 assertions**, 0 échec |
| Pint | OK |

---

## B. Critères de validation remplis

| critère | statut | preuve |
|---|---|---|
| Code implémenté | ✅ | `sim14()`, `schemaSim14()`, `regleTauxParNiveau()`, `validerReglesSim14()` |
| Nom / famille / objectif | ✅ | « Blocage en L1 », `cohortes_lmd`, blocage redoublement L1 |
| Version | ✅ | 1 (formules 1.0, héritées du moteur) |
| Périmètre | ✅ | catalogue uniquement ; lecture seule |
| Paramètres | ✅ | `taux` + `taux_par_niveau[L1]` (aucune valeur par défaut) |
| Hypothèses | ✅ | `01-specification.md` §E |
| Formules | ✅ | `02-formules.md` — aucune formule nouvelle |
| Contraintes | ✅ | surcharge L1, redoublement strict, aucune amélioration, somme ≤ 1.0001 |
| Indicateurs | ✅ | `07-resultats.md` |
| Hash / signature | ✅ | définition + `hash_resultat` (idempotence par graine) |
| Documentation | ✅ | dossier `ST-14/SIM-14/00 → 09` |
| Tests exécutés | ✅ | 5/64 + catalogue 23/648 + module 197/2183 |

---

## C. Preuves d'exécution

### C.1. Suite SIM-14 (premier passage)

```
5 tests / 64 assertions / 0 échec
- Référence manuelle : totaux, écarts, sortants, effet
- Niveau facultatif nivel_focal restitué
- Refus définition : taux_par_niveau vide, triplet incomplet, taux hors [0,1]
- Refus définition : somme > 1, amélioration interdite, gel, choc
- Idempotence + rétrocompatibilité
- Permissions 403, isolation facultaire 403, zéro écriture métier
```

### C.2. Catalogue

- 20 disponibles / 25 planifiés / 45 codes (intégrité vérifiée).
- SIM-14 à l'index 13 (disponible).

### C.3. Non-régression

```
Historique SIM-01 → SIM-13 : inchangé
Module complet             : 197 tests / 2183 assertions
Pint --dirty               : OK
```

---

## D. Réserves ouvertes

| N° | Réserve | Impact | Statut |
|----|---------|--------|--------|
| SIM14-R1 | Redoublement strictement supérieur = règle de modélisation, pas seuil institutionnel | Faible | OUVERTE |
| SIM14-R2 | Absence de compensation entre niveaux garantie par le moteur (`?? $taux`) | Faible | OUVERTE |
| SIM14-R3 | Niveau focal `L1` = seule valeur admise SIM-14 | Faible | OUVERTE |
| SIM14-R4 | `hash_entrees` historique incompatible (`VERSION_MOTEUR = 2.0`) | Faible | OUVERTE |

---

## E. Conformité au protocole A→J

| étape | réalisation |
|-------|-------------|
| A — Audit | ✅ `00-audit.md` — compatibilité `extension_par_niveau` confirmée, 4 constats |
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

SIM-14 est **clôturé et disponible** :

- ✅ contrat de domaine implémenté (blocage L1, redoublement strict, aucune amélioration) ;
- ✅ aucune extension moteur, aucune route/permission/table ;
- ✅ 5 tests / 64 assertions verts au premier passage, référence manuelle ;
- ✅ non-régression stricte (module 197/2183) ;
- ✅ documentation complète.

Verdict `PASS_WITH_RESERVATIONS` : les règles de blocage sont catalogue-only (SIM14-R1), et l'historique des `hash_entrees` n'est plus compatible (SIM14-R4).

---

## G. Prochaine étape

LOT B se poursuit avec **SIM-15** (blocage en L2), puis **SIM-16** (déséquilibre multi-niveaux), **SIM-17** (cohorte exceptionnelle), **SIM-18** (double génération) et **SIM-19** (rééquilibrage des niveaux).

# SIM-18 — 09 · Verdict

**Scénario :** Double génération
**Sous-LOOP :** ST-14.18
**Verdict :** `PASS_WITH_RESERVATIONS`

---

## A. Verdict et niveau

| critère | valeur |
|---|---|
| Verdict | **PASS_WITH_RESERVATIONS** |
| Domaine | `extension_cohorte` (confirmé par l'audit) |
| Extensions moteur | `VERSION_MOTEUR = 2.0` (identité de cohorte, livrée SIM-17/18) |
| Routes / permissions / tables nouvelles | aucune |
| Tests | **5 tests**, 0 échec (premier passage, avec SIM-17 : 10 tests / 69 assertions) |
| Catalogue | 23 tests / 648 assertions, 0 échec |
| Module global | **197 tests / 2183 assertions**, 0 échec |
| Pint | OK |

---

## B. Critères de validation remplis

| critère | statut | preuve |
|---|---|---|
| Code implémenté | ✅ | `repartirIdentites()`, `analyserCohortes()`, `schemaSim18()`, `validerReglesSim18()` |
| Nom / famille / objectif | ✅ | « Double génération », `cohortes_lmd`, coexistence de cohortes |
| Version | ✅ | 1 (formules 1.0, moteur v2.0) |
| Périmètre | ✅ | catalogue + moteur v2.0 ; lecture seule |
| Paramètres | ✅ | `taux` + `cohortes_identifiees` (≥ 2 entrées, aucune valeur par défaut) |
| Hypothèses | ✅ | `01-specification.md` §E |
| Formules | ✅ | `02-formules.md` — lecture de la partition, aucune formule de flux dupliquée |
| Contraintes | ✅ | ≥ 2 cohortes, même niveau, pas distincts, effectif ≥ référence |
| Dépendance SIM-17 | ✅ | SIM-17 clôturé avant SIM-18 |
| Indicateurs | ✅ | `07-resultats.md` |
| Hash / signature | ✅ | définition + `hash_resultat` (idempotence par graine) |
| Documentation | ✅ | dossier `ST-14/SIM-18/00 → 09` |
| Tests exécutés | ✅ | 5 tests + catalogue 23/648 + module 197/2183 |

---

## C. Preuves d'exécution

### C.1. Suite SIM-18 (premier passage)

```
5 tests / 0 échec (premier passage)
- Référence manuelle : totaux [60, 52, 51] vs témoin [60, 40, 31], écarts [12, 20], final 20
- Bilan de conservation : C1 (12 = 10 + 0 + 2), C2 (10 = 10 + 0 + 0)
- Coexistence mesurée : pas 2 en L1 (C1+C2), durée 1, pic 2
- Suivi par pas : identifiés + non identifiés = total du niveau
- Refus définition : < 2 cohortes, pas identiques, niveaux distincts, effectif < référence
- Idempotence + rétrocompatibilité
- Permissions 403, isolation facultaire 403, zéro écriture métier
```

### C.2. Catalogue

- 20 disponibles / 25 planifiés / 45 codes (intégrité vérifiée).
- SIM-17 à l'index 17, SIM-18 à l'index 18 (disponibles).

### C.3. Non-régression

```
Historique SIM-01 → SIM-17 : inchangé
Module complet             : 197 tests / 2183 assertions
Pint --dirty               : OK
```

---

## D. Réserves ouvertes

| N° | Réserve | Impact | Statut |
|----|---------|--------|--------|
| SIM18-R1 | ≥ 2 cohortes requises ; une seule relève de SIM-17 | Faible | OUVERTE |
| SIM18-R2 | Pas d'entrée distincts (entrée simultanée = pas de coexistence) | Faible | OUVERTE |
| SIM18-R3 | Même niveau d'entrée pour toutes les cohortes | Faible | OUVERTE |
| SIM18-R4 | Chaque effectif ≥ génération de référence (contrôle exécution) | Moyen | OUVERTE |
| SIM18-R5 | Dépend de SIM-17 | Faible | OUVERTE (respectée) |
| SIM18-R6 | `hash_entrees` historique incompatible (`VERSION_MOTEUR = 2.0`) | Faible | OUVERTE |

---

## E. Conformité au protocole A→J

| étape | réalisation |
|-------|-------------|
| A — Audit | ✅ `00-audit.md` — compatibilité `extension_cohorte` confirmée |
| B — Spécification | ✅ `01-specification.md` + `02-formules.md` |
| C — Conception | ✅ `03-architecture.md` |
| D — Développement | ✅ `04-implementation.md` |
| E — Validation math. | ✅ référence manuelle + bilan de conservation + coexistence (07-resultats.md) |
| F — Sécurité | ✅ `05-securite.md` + tests permissions / isolement |
| G — Tests | ✅ `06-tests.md` + `07-resultats.md` |
| H — UX | ✅ `08-guide-utilisation.md` |
| I — Documentation | ✅ dossier complet 00 → 09 |
| J — Verdict | ✅ ce document |

---

## F. Conclusion

SIM-18 est **clôturé et disponible** :

- ✅ contrat de domaine implémenté (≥ 2 cohortes, même niveau, pas distincts, effectif ≥ référence) ;
- ✅ coexistence mesurée, jamais supposée ;
- ✅ moteur v2.0 réutilisé (partagé avec SIM-17) ;
- ✅ 5 tests verts au premier passage, référence manuelle, bilan de conservation ;
- ✅ non-régression stricte (module 197/2183) ;
- ✅ documentation complète.

---

## G. Prochaine étape

LOT B se clôture avec **SIM-19** (rééquilibrage des niveaux), puis passage à **LOT C** (capacités et infrastructures).

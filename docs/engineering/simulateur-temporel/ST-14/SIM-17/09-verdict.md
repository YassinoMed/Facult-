# SIM-17 — 09 · Verdict

**Scénario :** Cohorte exceptionnelle
**Sous-LOOP :** ST-14.17
**Verdict :** `PASS_WITH_RESERVATIONS`

---

## A. Verdict et niveau

| critère | valeur |
|---|---|
| Verdict | **PASS_WITH_RESERVATIONS** |
| Domaine | `extension_cohorte` (confirmé par l'audit) |
| Extensions moteur | `VERSION_MOTEUR = 2.0` (identité de cohorte, livrée SIM-17/18) |
| Routes / permissions / tables nouvelles | aucune |
| Tests | **5 tests**, 0 échec (premier passage, avec SIM-18 : 10 tests / 69 assertions) |
| Catalogue | 23 tests / 648 assertions, 0 échec |
| Module global | **197 tests / 2183 assertions**, 0 échec |
| Pint | OK |

---

## B. Critères de validation remplis

| critère | statut | preuve |
|---|---|---|
| Code implémenté | ✅ | `repartirIdentites()`, `analyserCohortes()`, `schemaSim17()`, `validerReglesSim17()` |
| Nom / famille / objectif | ✅ | « Cohorte exceptionnelle », `cohortes_lmd`, cohorte à effectif inhabituel |
| Version | ✅ | 1 (formules 1.0, moteur v2.0) |
| Périmètre | ✅ | catalogue + moteur v2.0 ; lecture seule |
| Paramètres | ✅ | `taux` + `cohortes_identifiees` (1 entrée, aucune valeur par défaut) |
| Hypothèses | ✅ | `01-specification.md` §E |
| Formules | ✅ | `02-formules.md` — lecture de la partition, aucune formule de flux dupliquée |
| Contraintes | ✅ | 1 cohorte, effectif ≠ référence, entrée additive, pas dans [1, horizon] |
| Indicateurs | ✅ | `07-resultats.md` |
| Hash / signature | ✅ | définition + `hash_resultat` (idempotence par graine) |
| Documentation | ✅ | dossier `ST-14/SIM-17/00 → 09` |
| Tests exécutés | ✅ | 5 tests + catalogue 23/648 + module 197/2183 |

---

## C. Preuves d'exécution

### C.1. Suite SIM-17 (premier passage)

```
5 tests / 0 échec (premier passage)
- Référence manuelle : totaux [60, 45, 35] vs témoin [60, 40, 31], écarts [5, 4], final 4
- Bilan de conservation : 5 = 4 + 0 + 1 (écart de conservation = 0)
- Suivi par pas : identifiés + non identifiés = total du niveau
- Poids de la cohorte par période (lu dans la partition)
- Refus définition : liste vide, code dupliqué, effectif < 1, pas hors bornes
- Refus définition : taux_par_niveau, gel, choc, niveaux distincts, génération complète (effectif = référence)
- Idempotence + rétrocompatibilité
- Permissions 403, isolation facultaire 403, zéro écriture métier
```

### C.2. Catalogue

- 20 disponibles / 25 planifiés / 45 codes (intégrité vérifiée).
- SIM-17 à l'index 17 (disponible), SIM-18 à l'index 18.

### C.3. Non-régression

```
Historique SIM-01 → SIM-16 : inchangé
Module complet             : 197 tests / 2183 assertions
Pint --dirty               : OK
```

---

## D. Réserves ouvertes

| N° | Réserve | Impact | Statut |
|----|---------|--------|--------|
| SIM17-R1 | Une seule cohorte : pas de coexistence (`duree_coexistence = 0`) | Faible | OUVERTE |
| SIM17-R2 | Effectif déclaré ≠ référence du niveau d'entrée (contrôle exécution) | Moyen | OUVERTE |
| SIM17-R3 | Entrée additive aux admissions ordinaires (jamais de remplacement) | Faible | OUVERTE |
| SIM17-R4 | `hash_entrees` historique incompatible (`VERSION_MOTEUR = 2.0`) | Faible | OUVERTE |

---

## E. Conformité au protocole A→J

| étape | réalisation |
|-------|-------------|
| A — Audit | ✅ `00-audit.md` — compatibilité `extension_cohorte` confirmée |
| B — Spécification | ✅ `01-specification.md` + `02-formules.md` |
| C — Conception | ✅ `03-architecture.md` |
| D — Développement | ✅ `04-implementation.md` |
| E — Validation math. | ✅ référence manuelle + bilan de conservation (07-resultats.md) |
| F — Sécurité | ✅ `05-securite.md` + tests permissions / isolement |
| G — Tests | ✅ `06-tests.md` + `07-resultats.md` |
| H — UX | ✅ `08-guide-utilisation.md` |
| I — Documentation | ✅ dossier complet 00 → 09 |
| J — Verdict | ✅ ce document |

---

## F. Conclusion

SIM-17 est **clôturé et disponible** :

- ✅ contrat de domaine implémenté (1 cohorte, effectif ≠ référence, entrée additive) ;
- ✅ moteur v2.0 (identité de cohorte) réutilisé par SIM-18 ;
- ✅ 5 tests verts au premier passage, référence manuelle, bilan de conservation ;
- ✅ non-régression stricte (module 197/2183) ;
- ✅ documentation complète.

---

## G. Prochaine étape

LOT B se poursuit avec **SIM-18** (double génération) et **SIM-19** (rééquilibrage des niveaux).

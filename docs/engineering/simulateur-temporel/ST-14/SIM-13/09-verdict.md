# SIM-13 — 09 · Verdict

**Scénario :** SIM-13 — Crise académique combinée
**Sous-LOOP :** ST-14.13
**Verdict :** `PASS_WITH_RESERVATIONS`

---

## A. Verdict et niveau

| critère | valeur |
|---------|--------|
| Verdict | **PASS_WITH_RESERVATIONS** |
| Domaine | `configuration` du mécanisme existant (confirmé par l'audit) |
| Extensions moteur | **aucune** (`VERSION_MOTEUR = 1.8` conservée) |
| Routes / permissions / tables nouvelles | aucune |
| Tests | **8 tests / 107 assertions**, 0 échec (premier passage) |
| Catalogue | 23 tests / 598 assertions, 0 échec |
| Module global | **168 tests / 1878 assertions**, 0 échec |
| Pint | OK (1 correction automatique) |

---

## B. Critères de validation remplis

| critère | statut | preuve |
|---------|--------|--------|
| Code implémenté | ✅ | `sim13()`, `schemaSim13()`, `validerReglesSim13()`, branche |
| Nom / famille / objectif | ✅ | « Crise académique combinée », `reussite_flux`, combiner plusieurs dégradations |
| Version | ✅ | 1 (formules 1.0, héritées du moteur) |
| Périmètre | ✅ | catalogue uniquement ; lecture seule ; 3 tables techniques |
| Paramètres | ✅ | trio générique + taux référence (aucune valeur par défaut) |
| Hypothèses | ✅ | `01-specification.md` §E |
| Formules | ✅ | `02-formules.md` — aucune formule nouvelle |
| Contraintes | ✅ | paire focale dégradante, aucune amélioration, ≥ 2 strictes, somme ≤ 1.0001 |
| Indicateurs | ✅ | `07-resultats.md` |
| Hash / signature | ✅ | définition + `hash_resultat` (idempotence par graine) |
| Documentation | ✅ | dossier `ST-14/SIM-13/00 → 09` |
| Tests exécutés | ✅ | 8/107 + 23/598 + module 168/1878 |

---

## C. Preuves d'exécution

### C.1. Suite SIM-13 (premier passage)

```
8 tests / 107 assertions / 0 échec
- Cas A focal redoublement↑ : [60,43,36] vs [60,40,31], effet sortants −4
- Cas B focal abandon↑ (3 strictes) : écarts totaux [0,0], effet sortants −10
- Cas C delta +0.10 : admissions [11,12] communes, écarts [+3,+3]
- Refus moteur : focal non strict, somme, [0,1], trio, axe/sens, référence, SIM-03→08
- Refus catalogue : paire, amélioration, 2-strictes, somme, valeurs, clés inconnues
- Idempotence + rétrocompatibilité sans variation
- OAT base modifiée (0.5 / 0.3), aucune ligne taux_abandon, MC 50
- Permissions 403, isolation facultaire 403, zéro écriture métier
```

### C.2. Catalogue

- 14 disponibles / 31 planifiés / 45 codes (intégrité vérifiée).
- Bloc métadonnées SIM-13 ajouté (+8 assertions) ; SIM-14 devient le
  scénario planifié témoin pour les refus.

### C.3. Non-régression

```
Historique SIM-01 → SIM-12 : 160 tests / 1763 assertions inchangés
Module complet             : 168 tests / 1878 assertions
Pint --test --dirty        : OK
```

---

## D. Réserves ouvertes

| N° | Réserve | Impact | Statut |
|----|---------|--------|--------|
| SIM13-R1 | Les règles « crise combinée » vivent dans le catalogue (non branchées sur la création HTTP) ; le moteur accepte un jeu combiné avec un seul axe focal strict | Faible (bornes dures moteur + validation catalogue) | OUVERTE |
| SIM13-R2 | Les messages du descripteur générique renvoient « SIM-09→SIM-12 » même pour SIM-13 (moteur non modifié) | Faible (libellé) | OUVERTE |
| SIM13-R3 | `abandon` non réinjecté : aucun effet causal isolé ne lui est attribué | Faible (documenté, testé) | OUVERTE — héritée SIM-11/12 |
| SIM13-R4 | « Aucune amélioration » et « ≥ 2 strictes » sont des règles de modélisation, pas des seuils institutionnels | Faible (documenté) | OUVERTE |
| SIM13-R5 | Non-additivité des effets combinés (annulations partielles par arrondis) : interprétation prudente requise | Moyenne (sens pédagogique) | OUVERTE — documentée |

---

## E. Conformité au protocole A→J

| étape | réalisation |
|-------|-------------|
| A — Audit | ✅ `00-audit.md` — compatibilité `configuration` confirmée, 5 constats |
| B — Spécification | ✅ `01-specification.md` + `02-formules.md` |
| C — Conception | ✅ `03-architecture.md` |
| D — Développement | ✅ `04-implementation.md` |
| E — Validation math. | ✅ 3 cas à référence manuelle (tests 1–3) |
| F — Sécurité | ✅ `05-securite.md` + tests 4/5/8 |
| G — Tests | ✅ `06-tests.md` + `07-resultats.md` |
| H — UX | ✅ `08-guide-utilisation.md` |
| I — Documentation | ✅ dossier complet 00 → 09 |
| J — Verdict | ✅ ce document |

---

## F. Conclusion

SIM-13 est **clôturé et disponible** :

- ✅ contrat de domaine implémenté (4 règles, 5 messages) ;
- ✅ aucune extension moteur, aucune route/permission/table ;
- ✅ 8 tests / 107 assertions verts au premier passage, référence manuelle ;
- ✅ non-régression stricte (historique 160/1763 inchangé) ;
- ✅ documentation complète.

Verdict `PASS_WITH_RESERVATIONS` : les règles de domaine sont
catalogue-only (SIM13-R1), les libellés moteur génériques restent
« SIM-09→SIM-12 » (SIM13-R2), et l'interprétation des effets combinés
requiert la prudence documentée (SIM13-R5).

---

## G. Prochaine étape

Famille 2 (Réussite, redoublement, abandon) : **complète** (SIM-07 → SIM-13).
Frontière suivante : **LOT B — Cohortes et progression LMD (SIM-14)**,
compatibilité préliminaire `extension_par_niveau` — un audit devra vérifier
si des taux par niveau sont nécessaires.

# LOOP EX-LIFECYCLE — 12 · Verdict final

## A. Verdict

# ✅ PASS_WITH_RESERVATIONS

Les fonctionnalités essentielles du cycle de vie sont fiables, sécurisées, transactionnelles et historisées ; les contrôles critiques (concurrence, isolation, publication hors circuit, journal falsifiable) sont validés par tests. Les réserves portent exclusivement sur des **décisions institutionnelles ouvertes** (non-inventées) et une limitation d'environnement de test préexistante. **Aucune anomalie critique** (aucune des conditions FAIL du §15.2 n'est observée).

## B. État initial

- Branche `main`, commit `a8b3f0b` ; **223 modifications/non-suivis préservés** (héritage LOOP 43-BIS et autres) — aucun écrasement sans lecture préalable.
- Architecture observée : moteur unique `ExamenService` (transaction + matrice serveur), circuit de gouvernance append-only §2.7/§2.8, Policy anti-IDOR, isolation facultaire, tests existants au vert (106 tests / 436 assertions).

## C. Cycle de vie réel

- **États** : Examen 9 (`draft…archived`), SessionExamen 6, Epreuve 7 — trois moteurs séparés.
- **Automate effectif** : matrice déclarée ∩ garde `estCritique()` — `published`/`ongoing` scellés côté moteur simple ; seule voie post-publication = nouvelle version de planning via gouvernance.
- **Préconditions** : matrice + audit contraintes dures + (publication) circuit complet à 4 acteurs distincts + empreinte + gel.
- **Terminaux** : `archived` (examen), aucun retour possible (D-01).
- **Exceptions** : refus gouvernance → retour `draft` (règle explicite, journalisée, protégée).

## D. Réalisations

| Type | Fichiers |
|---|---|
| Créés | `2026_10_31_150000_creer_evaluation_examen_evenements.php` (migration additive réversible), `app/Modules/Evaluation/Models/ExamenEvenement.php` (append-only), `app/Modules/Evaluation/Services/ResultatTransition.php` (DTO), `tests/Feature/ExamenCycleVieTest.php` (19 tests) |
| Modifiés (diffs ciblés) | `ExamenService.php` (verrou + historisation + explicabilité, signature compatible), `ExamenController.php` (acteur/motif, historisation création, endpoint `transitions`), `Routes/web.php` (1 route GET lecture) |
| Services ajustés | aucun autre |
| Routes ajoutées | 1 (GET, lecture, `can:evaluations.view` + Policy + périmètre) |
| Vues ajustées | 0 |
| Documentation | 13 livrables `docs/loop/examens-cycle-vie/` |

## E. Sécurité

- **Permissions** : littérales par route + Policy + périmètre ; publication re-vérifiée dans le service.
- **Isolation** : `faculteRestreinteId()`/`dansPerimetre`/`pourFaculteId` — testée 403 hors périmètre, état inchangé.
- **IDOR/BOLA** : contrôles précèdent toute écriture ; aucun effet de bord sur refus.
- **Gouvernance** : non dupliquée, non contournable (5 chemins analysés — `02`).
- **Verrouillage** : `lockForUpdate` dans le moteur (aligné sur gouvernance/publication) ; garde critique conservée.
- **Historisation** : append-only au niveau modèle ; falsification impossible via Eloquent.

## F. Résultats des tests (nombres exacts)

| Mesure | Valeur |
|---|---|
| Tests exécutés | **241** |
| Passés | **237** |
| Échoués | **0** |
| Ignorés | **4** (`pcntl_fork` indisponible — concurrence multi-processus réelle ; préexistant) |
| Assertions | **923** |
| dont nouvelle suite cycle de vie | 19 tests / 60 assertions |
| Pint | OK (relance vérifiée) |

## G. Réserves

| ID | Preuve | Gravité | Impact | Décision attendue | Étape suivante |
|---|---|---|---|---|---|
| R-01 | `01-decisions-metier.md` D-01 | MOYEN | Pas de réouverture contrôlée post-publication | Autorité de publication | Confirmer le statu quo ou spécifier un circuit de réouverture |
| R-02 | D-02 | FAIBLE | Transitions sensibles sans motif obligatoire (tracées quand même) | Scolarité centrale | Trancher l'exigence de motif (`cancelled`/`locked`/`archived`) |
| R-03 | D-03 | FAIBLE | Pas de notification dédiée aux transitions simples | Scolarité/communication | Définir destinataires/canaux |
| R-04 | D-04 | MOYEN | Annulation non propagée aux épreuves (détectée par audit, non corrigée) | Direction des études | Choisir propagation/auto-guidage/statu quo |
| R-05 | D-05 | FAIBLE | `→completed` sans précondition de complétude métier | Direction des études | Définir « terminé » |
| R-06 | `10-resultats-tests.md` | INFORMATION | 4 tests de concurrence réelle ignorés localement (pcntl) | Environnement CI | Exécuter sur runner avec pcntl / MySQL |
| R-07 | `00` §5 | INFORMATION | `lockForUpdate` no-op sur SQLite `:memory:` (tests) — effectif MySQL | — | Rien (convention déjà en production) |

Aucune de ces réserves ne masque une anomalie critique ; toutes sont des points métier non démontrables dans le code actuel ou des contraintes d'environnement documentées.

## H. Protection du dépôt — confirmation explicite

- ✅ Aucun commit, aucun push, aucune branche créée, aucune Pull Request.
- ✅ Aucun `git reset --hard`, aucun `git clean`, aucun stash global, aucune restauration forcée.
- ✅ Aucune migration destructive, aucun `migrate:fresh`, aucun `db:wipe` (migration **additive** réversible uniquement).
- ✅ Aucune modification de production ; aucune suppression de données historiques.
- ✅ Les 223 modifications préexistantes sont intactes.

## I. Décision d'activation

| Élément | Statut |
|---|---|
| Moteur durci (`lockForUpdate` + contrôle décisif en transaction) | **Opérationnel** — validé techniquement, en production-safe (MySQL) |
| Historisation `evaluation_examen_evenements` | **Opérationnel** dès exécution de la migration additive sur l'environnement cible |
| Prévisualisation `GET …/transitions` | **Opérationnel** (lecture seule, protégé) |
| Circuit de gouvernance, publication orchestrée | **Inchangés** — déjà validés par leurs suites |
| Motif obligatoire, notifications, propagation d'annulation, complétude, réouverture | **Conditionnés** aux décisions R-01…R-05 — ne pas activer sans arbitrage |
| Périmètre testé | Transitions d'examen + historisation + prévisualisation + sécurité associée ; **hors périmètre** : cycle jour-J des épreuves, délibérations, calcul des acquis (suites existantes inchangées) |

---

### Conditions FAIL — vérification finale

| Condition | Observé |
|---|---|
| Transition interdite acceptée | Non (EX-LC-009/010/012/013/018) |
| Modification contournant le service central | Non (audit des 9 sites d'écriture) |
| Acteur non autorisé modifie un examen | Non (EX-LC-016/017) |
| Isolation facultaire contournée | Non (EX-LC-007/017) |
| Publication hors circuit | Non (EX-LC-012 + 198 assertions gouvernance) |
| Transition produisant des états incohérents | Non (écriture atomique état+journal) |
| Concurrence corrumpant les données | Non (verrou + contrôle décisif ; 2 tests réels ignorés pour raison d'environnement seulement) |
| Réouverture effaçant l'historique | Non applicable — réouverture impossible (D-01) |
| Résultat officiel modifié indirectement | Non (empreinte + circuit) |

**Verdict maintenu : PASS_WITH_RESERVATIONS.**

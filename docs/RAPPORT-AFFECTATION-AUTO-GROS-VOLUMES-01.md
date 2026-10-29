# RAPPORT — Affectation automatique : gros volumes en arrière-plan

Date : 18/10/2026 · Statut : livré (non commité) · Suite de `RAPPORT-INSCRIPTION-UNIQUE-ANNUELLE-01.md`

## 1. Objectif

L'écran **Affectation automatique** (`appliquerAuto`) appliquait par
tranches de 500 avec reliquat réaffiché à chaque requête : pour un gros
volume (ex. 5 000 affectations), l'agent devait reconfirmer la suite
~10 fois. Demande : traiter ces volumes sans reliquat répété.

## 2. Décision

Réutilisation du **mécanisme de lot déjà existant** (LOOP P2 :
`AffectationMasseLot` + `ExecuterAffectationMasseLot`), déjà utilisé par
la confirmation de masse — **aucune duplication** de moteur :

- volume **> `affectations.lots.seuil_sync`** (défaut 1000, même seuil
  que `confirmerMasse`) → lot persistant + Job en file (tranches,
  progression, reprise `traites_ids`, annulation coopérative) ;
- volume **≤ seuil** → flux synchrone actuel inchangé (tranches
  500/requête + reliquat, utile quand aucun worker n'est en marche).

## 3. Implémentation

| Fichier | Changement |
|---|---|
| `app/Http/Controllers/Admin/AffectationController.php` | `appliquerAuto` : calcul `$total` de la répartition, branche lot au-delà du seuil (`creerLot` → `ExecuterAffectationMasseLot::dispatch` → redirection `admin.affectations.masse.lots.show` + flash info) ; nouveau helper privé `selectionsDansPerimetre()` (répartition → sélections `{student_id, groupe_id}` re-cloisonnées aux mêmes gardes que `appliquerTranches` : 404 groupe hors périmètre, étudiants hors périmètre écartés) |
| `resources/views/admin/affectations/auto-simulation.blade.php` | Mention au-dessus du bouton quand le volume dépasse le seuil : « l'exécution passera en arrière-plan, avec progression et reprise sur la page de suivi du lot » |
| `tests/Feature/AffectationMoteurTest.php` | 3 tests (voir §4) |
| Docs | `docs/assignments/confirmation.md`, `docs/students-groups/assignment.md`, `docs/engineering/inscription-group-assignment.md`, `docs/fonctionnement-import-export-affectation-groupes.md` (tranches, lots, reprise) |

Le Job ne contient aucune règle métier (inchangé) : toute décision
(contraintes, capacité, idempotence, périmètre) reste dans
`MassAssignmentService::confirmer()`, revérifiée à l'exécution.

## 4. Tests

| Test (nouveaux) | Vérifie |
|---|---|
| `test_appliquer_volume_au_dela_du_seuil_bascule_en_lot` | seuil 3, 4 ids : lot `en_attente` créé (total 4, sélections normalisées), `ExecuterAffectationMasseLot` dispatché (`Queue::fake`), **zéro écriture** dans la requête HTTP, redirection page du lot + flash `info` ; le Job exécuté ensuite termine 4/4 |
| `test_appliquer_bascule_lot_re_cloisonne_le_perimetre` | admin faculté : étudiant hors périmètre écarté du lot (seul l'étudiant du périmètre est sélectionné) |
| `test_simuler_auto_annonce_la_bascule_arriere_plan_au_dela_du_seuil` | la vue de simulation affiche l'annonce de bascule au-delà du seuil |

Suites exécutées :

| Suite | Résultat |
|---|---|
| `AffectationMoteurTest` (23, incl. `test_appliquer_tranche_reaffiche_reliquat` 510 ids toujours synchrone) | **23/23** |
| `AffectationMoteurTest` + `AffectationMasseLotTest` + `AssignmentAutomatiqueTest` + `AssignmentApiTest` + `ChangementGroupeServiceTest` | **86/86, 304 assertions** |
| `pint` (fichiers PHP modifiés) | conforme |

## 5. Réserves

1. L'exécution en arrière-plan exige un worker (`php artisan queue:work`,
   `QUEUE_CONNECTION=database`) — réserves du LOOP P2 reprises telles
   quelles ; l'écran reste utilisable sans worker sous le seuil.
2. Rappel du rapport précédent : migration de garde d'unicité non
   appliquée en base de dev (à exécuter au prochain `migrate`).
3. Aucun commit/push (aucune autorisation) : livraison locale uniquement.

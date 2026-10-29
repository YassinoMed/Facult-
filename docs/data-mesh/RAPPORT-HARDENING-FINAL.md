# RAPPORT-HARDENING-FINAL — Data Mesh lot hardening

Date : 16/10/2026 · Base : `docs/data-mesh/BASELINE-HARDENING.md` · Verdict : **`PASS_WITH_RESERVATIONS`**.

## 1. Résumé exécutif
Socle Data Mesh durci sans réécriture : outbox atomique + commandée + purgée, triptyque
capacités explicite (migration additive), matrice de sécurité complète sur 6 produits,
contrats figés par snapshots, flux à états explicites, sauvegarde **créée, vérifiée et
restaurée avec preuve**. Aucune donnée métier modifiée ; 2000 affectations non réappliquées.

## 2. État initial → état final
| Point | Initial (baseline) | Final |
|---|---|---|
| Tests Data Mesh | 42 (39 verts, 3 échecs lot 02) | **55/55 verts, 867 assertions** |
| Outbox dedup | SELECT-puis-INSERT (course = 500 brut) | catch contrainte UNIQUE → existant |
| Transitions outbox | non gardées | `published` terminal (refus `published→failed`, no-op idempotent) |
| Publisher/purge | inexistants | `datamesh:outbox-publier` (limite, retenter, purge `published>30j`) |
| Capacités | NULL = illimité implicite, non distingué | migration additive `capacite_illimitee` + `Group::modeCapacite()` DEFINIE/ILLIMITEE/INCONNUE ; moteur inchangé (décision de bascule en attente) |
| Sécurité produits | partielle (2 produits peu couverts) | matrice complète + 4 tests (tous produits refusent sans consommateur ; `consultation` suit la matrice réelle) |
| Flux vertical | états implicites, 0 affectation masqué | `etat` par étape + diagnostic explicite « 0 affectation » |
| Contrats | versions codées en dur decayées | gel des versions + snapshot des 6 schémas |
| Sauvegarde | 0 démontrée | créée + vérifiée « intègre » + **restaurée sur base isolée (comptes identiques), scratch supprimée** |
| Migrations | 161 jouées, 0 en attente | idem (2 ajoutées : outbox déjà jouée, `capacite_illimitee` jouée) |

## 3. Fichiers créés et modifiés
Créés : `PublierOutbox.php`, `2026_10_16_190000_ajouter_capacite_illimitee_groupes.php`,
`BASELINE-HARDENING.md`, `RAPPORT-HARDENING-FINAL.md` (ce fichier).
Modifiés : `OutboxService` (dedup, purge), `OutboxMessage` (gardes), `Group` (triptyque),
`AffectationsGroupes` (doc schéma), `FluxVerticalDataMesh` (états + diagnostic),
4 fichiers de tests DataMesh (+13 tests), `CONTRACTS.md` (§5), `DATA-PRODUCT-CATALOG.md` (v1.1),
`GOVERNANCE.md` (§8 corrigé, §9 sauvegarde/RPO ≤24h/RTO ≤2h proposés).

## 4. Risques et corrections
| ID | Risque | Correction | Statut |
|---|---|---|---|
| P0-1 | Dedup non atomique | catch UNIQUE → existant + test de course déterministe | Corrigé, prouvé |
| P0-2 | Aucune sauvegarde démontrée | backup créé/vérifié/restauré avec preuve | Corrigé, prouvé |
| P1-1/2/3 | Transitions/publisher/purge | gardes + commande + purge testée | Corrigé, prouvé |
| P1-4 | Travail non commité (`??` git) | Signalé — **commit non effectué (pas d'instruction)** | Ouvert |
| P1-5 | Couverture restreinte | +4 tests sécurité ciblés | Corrigé, prouvé |
| P2-1 | Sémantique NULL | triptyque + migration ; bascule moteur **BLOCKED** (5 suites en dépendent) | Partiel, décision requise |
| P2-2 | États flux | `etat` + diagnostic | Corrigé, prouvé |
| P3-1 | Assertions périmées | versions gelées + snapshot + borne justifiée et renforcée | Corrigé, prouvé |

## 5. Tests (nombres réels)
- DataMesh (7 fichiers) : **55/55, 867 assertions, 0 skip, 0 échec**.
- Voisins : suivi 10, accueil 7+4+15, périodes 14+16, affectation-auto 11, moteur 20, changement-groupe 11,
  affectation-académique 7, automation 6 → **127/127 verts** au total.
- Non exécutés : suite complète `php artisan test` (~366 fichiers, temps), `composer audit`/`npm audit` (réseau), CI distante.

## 6. Preuves de sécurité par produit
6/6 refusent sans consommateur ; sans rôle refusé ; hors-périmètre refusé (explicite) ou ramené de force ;
`consultation` suit la matrice réelle (gates `enseignements.view`/`resultats.view` accordés par la matrice —
test aligné sur la règle effective, pas supposée) ; `resultats_examens` : `publie=true` obligatoire, moyennes
publiées seules, aucune identité brute ; PII masquée vérifiée par test.

## 7. Concurrence et idempotence outbox
Course simulée déterministe (insert concurrent via `creating`) : 1 seule ligne, existant retourné, 0 exception.
Terminal : republication no-op (horodatage conservé), `published→failed` refuse (`LogicException`).
Rollback transactionnel, reprise au seuil, purge sélective : prouvés en tests. Revendication : **au-moins-une-fois
+ consommation idempotente** (jamais d'exactement-une-fois).

## 8. Benchmarks reproductibles (dev local MySQL, ~2000 lignes, Darwin arm64)
| Scénario | Requêtes | Durée | Volume |
|---|---|---|---|
| inscriptions p100 / p1000 | 5 / 5 | 98 / 104 ms | 24 Ko / 248 Ko |
| teaching_assignments | 5 | 77 ms | — |
| exam_results | 1 | 5 ms | — |
| outbox publier 100 | 101 | 134 ms | — |
| outbox retenter | 2 | 4 ms | — |
| contrôles qualité (8 règles) | ~181 | ~243 ms | — |
| flux vertical complet | 40 | 50 ms | pic 46 Mo |
\* après optimisation du comptage groupé (vérifié en test par garde d'invariance).
Limites : mesures locales mono-itération, pas de garantie prod ; charge > actuelle non testée (réserve).

## 9. Migrations
`migrate:status` : 0 en attente. Nouvelles : outbox (jouée 16/10), `capacite_illimitee` (additive, réversible,
jouée dev + sqlite). Aucun `migrate:fresh`/`db:wipe` dans ce lot.

## 10. Sauvegarde et restauration
`storage/app/sauvegardes/manifest-20261016-194401.json` (base 1,7 Mo + documents 19,6 Mo),
`--verifier` : « intègre ». Restauration démontrée sur `inscription_restore_test`
(6 tables clés à comptes identiques), base scratch supprimée. RPO/RTO proposés à valider.

## 11. Réserves et décisions requises
1. Bascule « INCONNUE refuse les nouvelles affectations » : **BLOCKED**, décision métier requise.
2. Rattrapage 2000 affectations : **non réappliqué**, décision explicite requise.
3. Commit git du périmètre Data Mesh : instruction requise (fichiers `??`).
4. Charge > actuelle, `composer/npm audit`, suite complète CI : non exécutés ici.
5. Conservation (propositions, audits) et RPO/RTO : validations métier/technique requises.

## 12. Commandes exécutées (résultats)
`vendor/bin/pint` (changed files, passed) · `phpunit --filter=DataMesh` (55/55) · suites voisines
(127/127) · `php artisan migrate --force` (1 additive DONE) · `sauvegarde:creer` + `--verifier`
(intègre) · import/restore/drop scratch (comptes identiques) · `datamesh:outbox-publier` (0 pending, OK).

## 13. Verdict justifié : `PASS_WITH_RESERVATIONS`
Socle durci, prouvé par 127 tests verts et mesures reproductibles, sans régression ni mutation
métier ; réserves documentées ci-dessus (aucune masquée). `PASS` refusé honnêtement : décisions
métier (bascule NULL, rattrapage, RPO/RTO, commit) hors de portée d'un lot technique.

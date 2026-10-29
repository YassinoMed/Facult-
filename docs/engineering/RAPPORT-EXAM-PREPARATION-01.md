# RAPPORT-EXAM-PREPARATION-01 — Préparation des examens (surface SmartExam)

**Date :** 18/10/2026 · **Protocole :** LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION
**Verdict : PASS_WITH_RESERVATIONS**

---

## 1. Résumé exécutif

Le cahier des charges supposait un module à créer de zéro. L'audit LAN a
prouvé que le module Evaluation couvre déjà ~80 % du besoin (sessions,
épreuves, conflits, salles multi-affectation, candidats idempotents,
surveillance, PDF). Ce LOOP a donc livré **la surface demandée**
(`/admin/examens/preparation`) et comblé uniquement les **6 lacunes
prouvées** (G1-G6) — **zéro duplication fonctionnelle**, migrations
purement additives, aucun flux existant modifié dans son comportement.

## 2. Réalisation vs cahier des charges

| § | Besoin | Origine | Statut |
|---|---|---|---|
| A | Sessions CRUD + statuts + archivage | Existant (`SessionExamen`, transitions serveur) | ✅ réutilisé — statuts du cahier mappés sur les conventions |
| A | Validation bloquée par cohérence | Existant `readiness()` + **G6 étendu** (capacité, population) | ✅ renforcé |
| B | Épreuves + conflits + doublons | Existant (`EpreuveService`, `ConflitEpreuveService`) | ✅ réutilisé |
| C | Salles 1..n, capacité, chevauchements | Existant (`Room`, `ExamRoomAssignmentService`, places) | ✅ réutilisé |
| D | Candidats depuis inscriptions validées, zéro doublon | Existant (`genererCandidats`, idempotent + **unique DB (épreuve, étudiant)** constaté) | ✅ réutilisé |
| D | Snapshot population + signalement changements | **Nouveau (G4)** : `population_validated_at/hash/effectif`, dérive détectée → avertissement UI + **blocage** de l'ouverture de session | ✅ livré |
| E | Émargement PDF | Existant par épreuve → **étendu (G2/G3)** : sections par salle, aperçu inline, téléchargement, visa, colonne numéro anonyme | ✅ étendu |
| E | Historique génération/réimpression | **Nouveau (G3)** : table `evaluation_documents_generes` (auteur, salle, effectif, fichier) affichée dans l'assistant | ✅ livré |
| F | Identifiant stable par candidat×épreuve, prêt code-barres, sans CIN publique | **Nouveau (G5)** : `numero_anonyme` unique DB par épreuve, attribué à la validation, jamais réattribué | ✅ livré |
| G | Tableau de bord + assistant + recherche/filtres | **Nouveau (G1)** : `PreparationExamenController` + 2 vues (thème existant, `x-page-header`, `ds-card`) | ✅ livré |
| S | RBAC, scope faculté, logs sans PII, transactions | Gates `evaluations.*` + `verifierFaculte` (404) + `IntegrationLog`/`EvaluationChanged` + `DB::transaction` + `lockForUpdate` | ✅ respecté |

## 3. Fichiers

**Créés :**
- `app/Modules/Evaluation/Services/PreparationExamenService.php` (tableau de bord, contrôles, validation population, hash/dérive)
- `app/Modules/Evaluation/Controllers/PreparationExamenController.php` (index, session, validerPopulation, emargement)
- `app/Modules/Evaluation/Models/DocumentExamenGenere.php`
- `database/migrations/2026_10_18_090000_exam_preparation_lacunes_g2_g5.php` (additive : colonnes + table historique — appliquée dev, 454 ms)
- `resources/views/modules/evaluation/preparation-index.blade.php` + `preparation-session.blade.php`
- `tests/Feature/ExamPreparationTest.php` (18 tests)
- `docs/engineering/LOOP-EXAM-PREPARATION-01.md`

**Modifiés (additif) :**
- `app/Modules/Evaluation/Services/ConvocationService.php` — `listeEmargement(?Room, $apercu)` : sections par salle, disposition inline, visa, trace historique
- `app/Modules/Evaluation/Services/SessionExamenService.php` — `readiness()` étendu (capacité + dérive population)
- `app/Modules/Evaluation/Models/Epreuve.php`, `Candidat.php` — fillable/casts
- `app/Modules/Evaluation/Routes/web.php` — 4 routes `/admin/examens/preparation*` (mêmes middlewares/gates)

## 4. Preuves d'exécution

| Commande | Résultat |
|---|---|
| `php artisan migrate --force` | 2 migrations OK (dont 1 préexistante non appliquée, voir §6) |
| `php artisan route:list` | 4 routes `admin.examens.preparation.*` enregistrées |
| `php artisan test --filter=ExamPreparationTest` | **18/18, 68 assertions** |
| `php artisan test --filter="Evaluation|Surveillance|…"` | **300/301** (1 échec **préexistant**, prouvé par stash) |
| `php artisan test --filter=DataMesh` | **78/78, 1 181 assertions** |
| `vendor/bin/pint` (10 fichiers) | OK |

**Couverture test livrée :** module désactivé 404 · invité redirect · 403 sans `evaluations.update` · 404 hors périmètre faculté · dashboard+assistant 200 · snapshot+numéros anonymes (uniques, jamais une CIN) · idempotence validation · **uniques DB (numéro anonyme + doublon candidat)** · dérive signalée **et bloquante** à l'ouverture · re-validation efface le signal · capacité insuffisante bloquante · salle sans capacité = avertissement (jamais inventée) · PDF téléchargement/aperçu/par salle/liste vide/25 candidats multi-salles accents français · historique cumulé et affiché.

## 5. PERFORMANCE & INTÉGRITÉ

- Requêtes agrégées (`selectRaw`, `withCount`, `whereHas`) — pas de N+1 dans les nouveaux parcours ; vues paginées par nature (sessions limit(8), documents limit(10)).
- Index posés : `uq_candidat_numero_anonyme (epreuve_id, numero_anonyme)`, `evaluation_documents_generes(epreuve_id, created_at)`.
- Écritures transactionnelles + `lockForUpdate` (validation population) ; PDF en lecture seule.
- Hors périmètre respecté : notes, inscriptions, affectations de groupes **non touchées**.

## 6. Réserves (justifient PASS_WITH_RESERVATIONS)

1. **R1 — Échec préexistant dans la suite** : `AffectationEnseignantExamenTest::test_auto_sans_session_id_message_utilisateur` échoue **avant et après** mes changements (preuve par stash sélectif). Cause probable : apostrophe typographique/échappement du message dans la vue affectations. Hors périmètre de ce LOOP — à corriger séparément.
2. **R2 — PDF = population courante, pas le snapshot** : l'émargement est généré à la volée depuis la liste actuelle. La conformité à la « version validée » est assurée indirectement (dérive → avertissement + blocage d'ouverture + effectif tracé par génération), pas par un PDF figé. Décision documentée.
3. **R3 — Validation de population optionnelle** : une session sans snapshot peut toujours être ouverte (compatibilité des flux existants). La garde n'opère qu'après une première validation. Décision de compat arrière documentée.
4. **R4 — Code-barres non implémenté** (conforme au cahier des charges : « ne pas prétendre ») : seule la structure (numéro stable unique) est posée. Scan/anonymatisation/copies : non implémentés, non revendiqués.
5. **R5 — Migration préexistante appliquée au passage** : `2026_10_18_100000_verifier_unicite_inscription_annuelle` (garde de déploiement, vérification seule, aucune donnée modifiée) était en attente et a été appliquée avec la mienne.

## 7. Contraintes respectées

Aucun `migrate:fresh`/`db:wipe`, aucune donnée métier modifiée, aucun
commit/push/déploiement, aucune règle d'éligibilité inventée (population
centrale Data Mesh réutilisée), aucune capacité de salle inventée,
journalisation sans PII (identifiants et effectifs seuls).

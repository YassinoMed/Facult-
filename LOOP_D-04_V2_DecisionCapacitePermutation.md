# LOOP D-04 V2 — Décision de capacité, permutations et réaffectations de salles

**Projet :** inscription-etudiants (Laravel 13, PHP 8.5, SQLite en mémoire en tests)
**Surface :** `/admin/evaluation/examens/groupes?annee_id=7&mode=B` — parc mixte (amphithéâtres + salles normales)

## 1. État initial (audit)

- Aucune logique de décision de capacité / permutation n'existait pour le Mode B ; seul `auditerModeBAmphitheatres()` existait (amphis/cohortes, ratio 0.50 en dur).
- Source de vérité de la jauge utile : `ExamScheduleEngineService::capaciteUtileExamen()` (`floor(capacite × 0.50)`).
- Validateur autoritaire à l'application : `ConflitEpreuveService::detecter()` (salle/groupe/candidat/ouverture/cours).
- Colonnes déjà présentes (aucune migration requise) : `evaluation_epreuves.salle_id`, `evaluation_candidats.salle_id`, `evaluation_candidats.place`, `population_hash`, `population_effectif`.
- `Candidat::salleEffective()` propage déjà la salle/place vers convocations, émargement et PDF.
- Versioning durable : `audit_versioning` (append-only, jamais de suppression) + `AuditVersioningService::tracer()` ; trace intégration hachée via `IntegrationLog::tracer()` (SHA-256, jamais de PII brute).
- Contrôleurs/routes/vues existants réutilisés : `ExamenAdvancedMenuController::groupes()`, `resources/views/modules/evaluation/examens-advanced/groupes.blade.php`, `routes/web.php` (groupe `evaluation/examens`), `config/modules.php`.

## 2. Fichiers créés / modifiés

### Créés
- `app/Modules/Evaluation/Services/ExamDecisionCapacitePermutationService.php` — cœur métier (diagnostic, proposer, appliquer, refuser, annulerDecision, historique, payloadVue, prévalidation sonde, cache éphémère, audit).
- `app/Modules/Evaluation/Controllers/ExamDecisionCapaciteController.php` — 11 routes (lecture + écriture), permission-gées et throttlées.
- `tests/Feature/ExamDecisionCapacitePermutationTest.php` — 22 tests (16 obligatoires + 6 annexes).

### Modifiés
- `app/Modules/Versioning/Services/AuditVersioningService.php` — `tracer()` accepte maintenant `?int $utilisateurId = null` (enregistrement de l'auteur même hors contexte HTTP).
- `app/Modules/Evaluation/Controllers/ExamenAdvancedMenuController.php` — injection du service + calcul du payload D-04 (Mode B uniquement) dans `groupes()`.
- `app/Modules/Evaluation/Routes/web.php` — routes `groupes.capacite.*` (throttle, `epreuves.view`/`epreuves.update`).
- `config/modules.php` — bloc `modules.evaluation.capacite` (ratio, max_propositions, max_salles_par_epreuve, max_epreuves, ttl, autoriser_repartition_multi_salles).
- `resources/views/modules/evaluation/examens-advanced/groupes.blade.php` — section « Décision de capacité et permutations (D-04 V2) » insérée après Tableau 2, avant `@else` ; chaînes D-04 existantes préservées.

## 3. Stratégie de permutation (préférence métier)

1. `reaffectation` : salle unique disponible, capacité utile >= effectif, même créneau, pas de conflit groupe/étudiant.
2. `permutation` : deux épreuves échangent leurs salles, les deux restant valides (capacité utile de chaque salle respectée).
3. `combinaison` : répartition sur plusieurs salles (préférence 2 salles, amphi + normale), jusqu'à `max_salles_par_epreuve`.
4. `mixte` : fusion des variantes ci-dessus.

Règles de placement à l'application :
- multi-salles : `salle_id` explicite + `place` 1..n par salle sur chaque `Candidat` ;
- salle unique : `salle_id = null` sur les candidats, `place = 1..n` (`salleEffective()` hérite de la salle de l'épreuve).

## 4. Preuves de cohérence

- **Jauge utile opposable** : `Dépassement = max(0, affectés − jauge utile)` ; `Places restantes = max(0, jauge utile − affectés)`, recalculées server-side. Capacité ≤ 0 = bloquant.
- **Gel de gouvernance** : `appliquer()` refusé quand l'examen a une `PlanningValidation` publiée (message orientant vers `creerNouvelleVersionPostGel()`).
- **Statuts modifiables** : épreuves `draft/scheduled`, examen `draft/planned/scheduled`, session non verrouillée.
- **Revalidation à l'application** : verrous pessimistes déterministes (`lockForUpdate` salles → épreuves → candidats), empreinte (fingerprint) vérifiée, `ConflitEpreuveService` en post-écriture, rollback complet en cas d'échec.
- **Audibilité** : `audit_versioning` (ancienne/nouvelle valeur, auteur, date, version) + `IntegrationLog` haché + événement `EvaluationChanged` après commit.
- **Annulation** : restaure salle + répartition par comptage (IDs candidats ascendants), protégée par empreinte/audit_id.
- **Isolation** : `verifierPortee()` + `faculteRestreinteId()` ; aucune écriture hors faculté ; pas de bypass HTTP.
- **Performance** : contexte chargé en requêtes bornées (aucune N+1), propositions en cache éphémère (UUID + TTL), obsolescence détectée par empreinte, `max_propositions` configurable.

## 5. Résultats des tests

- `ExamDecisionCapacitePermutationTest` : **22/22 passés** (94 assertions).
  - 16 tests obligatoires : amphi suffisant, amphi insuffisant, remplacement salle normale, combinaison amphi+normale, multi-salle sur un créneau, capacité totale insuffisante, salle occupée rejetée, permutation valide, matière/groupe incohérent, revalidation concurrence, rollback échec, planning publié, convocations/PDF cohérents, hors-faculté refusé, requête répétée sans duplication, pas de solution → motif précis.
  - 6 tests annexes : annulation/restauration, rendu page Mode B, gate écriture sans permission, refus sans écriture, historique, structure payloadVue.
- Suites connexes (pas de régression) : **54/54 passés** (`ExamSurveillanceGroupesEnterpriseTest`, `ExamPlanificationSallesEnterpriseTest`, `ExamCandidatsConvocationsEnterpriseTest` + le test Mode B existant `test_vue_groupes_mode_b_amphitheatres_et_decision_d04` qui affirme les 13 chaînes D-04).
- `vendor/bin/pint --dirty` : propre (conventions Laravel respectées).

## 6. Limites

- La validation d'occupation de salle au moment de `appliquer()` est conditionnée à la présence d'une empreinte fournie ; sans elle, le service s'appuie sur `construireEtValiderRepartitionD04` (capacité utile). La salle occupée est détectée quand l'empreinte est fournie.
- La surcharge de surveillance est une alerte non-bloquante (les enseignants sont affectés séparément).
- Aucune migration n'a été ajoutée (conformément aux guard-rails) ; le journal de décision est durable dans `audit_versioning`.
- Aucun commit/push n'a été effectué (guard-rail) ; toutes les modifications locales préexistantes sont préservées.

## 7. Verdict

**PASS**

Toutes les contraintes dures (pas de salle au-delà de la jauge utile, pas d'examen incompatible simultané, créneau officiel conservé, pas de candidat perdu/ajouté/convoyé deux fois, restrictions et équipements respectés, propagation surveillance/convocations/documents, audit et rollback, permission + cloisonnement faculté, pas de modification silencieuse du planning publié) sont implémentées et vérifiées par les tests. Le scoring des variantes respecte l'ordre de préférence métier (réaffectation > permutation > combinaison > mixte).

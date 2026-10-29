# SIMULATEUR TEMPOREL MULTI-DOMAINES — Rapport d'implémentation (ST-01→ST-06)

**Date :** 2026-10-25 · **Pilote validé :** effectifs/cohortes LMD.
**Verdict : `PASS_WITH_RESERVATIONS`** (17/17 tests, pint PASS, non-régression verte).

## Livré (16 fichiers + 2 enregistrements)

| Composant | Fichiers |
|---|---|
| Moteur pur (ST-01) | `Support/HorlogeSimulee` (pas annuel, AUCUN now()), `EvenementSimule` (ordre total temps+séquence), `MoteurEvenements` (plafond 10 000, replanification), `GenerateurVariantes` (sha256(graine:i), jamais mt_rand), `EvaluateurScenarios` (contraintes dures déclaratives plafond/plancher_total ; objectifs cible_total/stabilite, poids SAISIS), `ClasseurScenarios` (tri score desc, égalité par index, non-conformes isolés) |
| Adaptateur (ST-02) | `ContratAdaptateur` + `AdaptateurEffectifsCohortes` (lecture seule : inscriptions `valide` agrégées par niveau via programme→faculté ; périmètre vide refusé, jamais zéro silencieux) |
| Persistance (ST-03) | migration `2026_10_25_040000` : `scenarios_temporels` (définitions versionnées, critères/contraintes/objectifs JSON), `executions_temporelles` (UNIQUE scénario+hash_entrees+graine → **idempotence**, hash_resultat figé, version_moteur, duree_ms), `snapshots_executions` (agrégats par pas) — down() complet, zéro colonne sur tables existantes |
| Service | `ServiceSimulationTemporelle` : validation (bornes delta [-0.5,2], horizon ≤ 6, variantes ≤ 20, taux somme ≤ 1), hash d'entrées, progression par moteur d'événements (années simulées, taux passage/redoublement/abandon SAISIS ; taux absents = avertissement « cohortes gelées »), classement + rappel « AUCUNE décision réelle » |
| API (ST-04) | `SimulationTemporelleController` (5 actions JSON) + 5 routes `admin/simulation-temporelle/*` — middleware `module:simulation-temporelle` (404 si off), `can:statistiques.view` RÉUTILISÉE, `FacultePerimetre` (Lot 14) réutilisé, throttle préfixé `st-def`/`st-exec` |
| Enregistrement | `config/modules.php` drapeau `MODULE_SIMULATION_TEMPORELLE` (défaut **false**) + require routes dans `ModuleServiceProvider` (diff minimal, inséré à la suite de HubIntegration/GouvernanceConformite de la session concurrente) |

## Preuves d'exécution (réelles)

- `tests/Unit/MoteurTemporelTest.php` : **7 passed (99 assertions)** — horloge sans now(), ordre total des simultanés, tri temporel, générateur reproductible+borné, évaluateur (score exact 0.875 vérifié), classeur, plafond anti-coût.
- `tests/Feature/SimulationTemporelleTest.php` : **10 passed (42 assertions)** — création+code dupliqué 422, critères invalides 422 (delta hors bornes, horizon 9, taux somme>1), **reproductibilité+idempotence** (même id/hash, 1 seule exécution), graines différentes → hash différents, contrainte dure isole les 3 non-conformes, taux absents → avertissement, plafonds (50 variantes/année inconnue → 422 sans exécution), isolation (admin_faculte B → 403 ; sans back-office → 403 ; module off → 404), **non-écriture** (inscriptions immobiles) + **snapshots zéro PII** (aucun champ nom/cin/email/prenom) + 4 snapshots (pas 0..3), performance 20 variantes × 6 ans < 3 s.
- Non-régression : Lot 14 + ST = **33 passed (195)** ; suite IA = **111 passed (356)**.
- `vendor/bin/pint` → **PASS (17 fichiers)** · scan cyrillique/CJK → **0 corruption** · `route:list` → **5 routes**.

## Incidents corrigés (traçabilité)

| # | Incident | Correction |
|---|---|---|
| 1 | Espace parasite dans un nom de méthode de test | renommé `test_evaluateur_contrainte_dure_…` |
| 2 | Collision `get/post` avec la TestCase (leçon Lot 20 réappliquée) | helpers `appelGet/appelPost` |
| 3 | `etudiant_id` NOT NULL + colonne textuelle `niveau` requises | étudiant synthétique par inscription + `niveau=>$code` |
| 4 | 500 silencieux : tables non trouvées (pluriel Laravel au 1ᵉʳ mot ≠ migration) | `protected $table` explicite sur les 3 modèles |
| 5 | `consultation` possède `statistiques.view` (200 attendu 403) | compte hors back-office (`role: etudiant`, pattern Lot 14) |
| 6 | `FacultePerimetre::resoudre` exige (User, ?int) | appel corrigé à 2 arguments |

## Réserves (non bloquantes)

1. **UI différée** : JSON uniquement (pattern de la série 14-20).
2. Snapshots persistés pour la **variante référence** uniquement (index 0).
3. Adaptateurs supplémentaires (salles/charges dans le temps = ST-07, finances NON VÉRIFIÉ) non livrés — le contrat `ContratAdaptateur` est prêt.
4. Exécution synchrone bornée (pas de job async : plafonds 20 variantes × 6 ans ≈ ms) ; reprise = idempotence par hash.
5. Migration NON appliquée à MySQL (`APP_ENV=production`) — à migrer à l'activation.
6. Développement mené pendant l'écriture CONCURRENTE du Lot 19 : fichiers partagés édités en diff minimal avec relecture fraîche ; suite complète du dépôt à rejouer sur arbre stabilisé (protocole 03.6-03.8).

## Conformité

Aucun commit/pull/reset/stash ; aucune écriture métier (test non-écriture) ;
aucune PII dans les snapshots (testé) ; permissions/périmètre réutilisés
(aucune clé nouvelle) ; module désactivé par défaut ; temps 100 % simulé.

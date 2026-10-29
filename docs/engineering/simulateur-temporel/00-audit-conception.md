# SIMULATEUR TEMPOREL MULTI-DOMAINES — Phase 0 : Audit et conception (LOOP-00/01)

**Date :** 2026-10-25 09:55 · **Mode :** lecture seule intégrale — aucune
implémentation, aucun test exécuté, aucune migration, aucun commit.
**Statut :** document soumis à validation — **aucune modification de
l'application ne sera engagée avant acceptation du plan.**

---

## 0. Stabilité du dépôt et travaux concurrents (préalable du mandat)

- HEAD `38ef149`, branche `feature/admin-modifier-etudiant`, stash 0.
- 759 fichiers en travail (345 M / 412 ?? / 2 A) — incluant la série
  concurrente Lots 14→20 (dernier rapport : LOOP-18 à 08:47).
- **Écritures détectées il y a moins de 20 minutes** (`Permissions.php`,
  `OnlineRequestService.php`, `DelegationDemandeService.php`) : la session
  concurrente exécute le **Lot 19 (Gouvernance)**, rapport non encore produit.
- Décision conforme au mandat : l'audit (lecture seule) est livré ;
  **toute implémentation et tout test restent bloqués** jusqu'à
  stabilisation constatée (protocole d'empreintes des phases 03.6→03.8).

---

## 1. Inventaire des fonctionnalités de simulation existantes (preuve : code lu)

| Simulateur | Emplacement | Périmètre | Temporalité | Persistance | Déterminisme |
|---|---|---|---|---|---|
| Parcours individuel | `ParcoursAcademique/Services/SimulationParcoursService` (+ interface, contrôleur) | devenir d'UN étudiant : semestres, compensation, règles LMD résolues par (code, année, programme) | parcours ordonné implicite (historique) | non (lecture/calcul) | oui — 0 appel `now()` |
| Scénarios institutionnels (Lot 14) | `Modules/SimulateurScenarios` (ServiceScenarios, CriteresScenario, FacultePerimetre) | 5 axes : effectifs, capacité salles, saturation, charges, programmes ; comparaison 2 scénarios | **aucune** (instantané) | non (transitoire, « uniquement si nécessaire ») | `VERSION_REGLES='1.0'` + `hash_entrees` sha256 + bornes DTO |
| Affectation de groupes | `AssignmentSimulation` (modèle racine) | affectation technique d'étudiants en groupes | aucune | OUI : `parametres`, `hash_entrees`, **`seed`**, `version`, **`simulation_origine_id`** (lignage de variante), statuts brouillon→simulee→confirmee/expiree/annulee, compteurs résultats | seed + hash |
| Emploi du temps | `Schedule/Models/TimetableScenario` + `TimetableGeneration` + `ScheduleConstraintRegistry` | scénarios de grille EDT, contraintes, **rétention humaine** (brouillon/évalué/retenu/rejeté ; planning jamais publié sans validation) | créneaux (grille) | oui | NON VÉRIFIÉ (moteur non audité en profondeur) |
| Activation de maquette | `MaquetteActivationSimulationService` | simulation d'activation d'une version de maquette | aucune | non | NON VÉRIFIÉ (rapports existants : vert) |

Autres mécanismes connexes : `MaquetteComparaisonService` (diff de versions,
réutilisable pour comparer des exécutions), `AcademicProgressionService` /
`AcademicCalculationService` (règles par étudiant), moteur
`Workflow/MoteurWorkflowService` (transitions d'états — **en cours de
modification par la session concurrente**), Data Mesh
(`ServiceObservabilite`, périmètre facultaire forcé), jobs/queues database,
throttle préfixé par famille (pattern Lot 16).

## 2. Matrice EXISTANT / RÉUTILISABLE / À ÉTENDRE / À CRÉER / NON VÉRIFIÉ

| Capacité requise par le mandat | Statut | Détail |
|---|---|---|
| Définitions et versions de scénarios | **À ÉTENDRE** | pattern `AssignmentSimulation` (parametres+version+statuts) à généraliser ; Lot 14 est transitoire sans table |
| Exécutions traçables | **À ÉTENDRE** | mêmes colonnes + `hash_entrees` ; ajouter empreinte des SORTIES et durées/plafonds |
| Critères, contraintes, objectifs | **À CRÉER** (structure) ; bornes validées : **RÉUTILISABLE** (`CriteresScenario` : refus hors bornes, jamais de clamp silencieux) |
| Générateur de variantes | **À ÉTENDRE** | `seed` + `simulation_origine_id` existent (affectations) : à généraliser en génération bornée et reproductible |
| Horloge simulée | **À CRÉER** | **aucune abstraction de temps dans l'app** (0 `Clock`, 0 `setTestNow` en prod-code) ; les simulateurs existants évitent `now()` — l'horloge simulée sera un value object explicite |
| Moteur d'événements | **À CRÉER** | `Modules/Calendar` = calendrier métier (Events/Listeners Laravel), PAS un moteur à événements discrets |
| Pas fixe vs événements | **À CRÉER** | rien d'existant ; calendrier universitaire (AnneeUniversitaire, SemestreLmd) = source de pas |
| Adaptateurs métier | **À CRÉER** (contrat) ; sources : **RÉUTILISABLES** | effectifs/inscriptions, salles (`Room`), charges (`AffectationEnseignement`), référentiel LMD, parcours — tous déjà consommés en lecture par Lot 14/15 |
| Snapshots et résultats | **À CRÉER** | jamais d'écriture métier : snapshots JSON versionnés dans les tables du module (précédent : rapports figés du Lot 16) |
| KPI, alertes | **RÉUTILISABLE (lecture)** | `KpiDefinition`/BI/Data Mesh en source ; les KPI SIMULÉS restent à produire par le moteur |
| Comparaisons | **À ÉTENDRE** | `MaquetteComparaisonService` (diff structuré) + comparateur Lot 14 (écarts par axe) = précédents directs |
| Audit | **RÉUTILISABLE** | `HistoriqueAcademique` (immuable), pattern listeners `AuditMaquette` |
| Permissions | **RÉUTILISABLE** | `statistiques.view/export` (Lot 14), `referentiel.*` ; périmètre : `User::peutAccederFaculte` + `FacultePerimetre` |
| Quotas, annulation, reprise idempotente | **À CRÉER** (pattern jobs existant : BDD queue, throttle préfixé, `ImportJob` reprise) | pas de moteur d'optimisation externe — conforme au mandat |
| Domaine finances | **NON VÉRIFIÉ** | module `finances` déclaré + commandes (dossiers paiement, rapprochement) ; adaptateur à auditer avant tout branchement |
| Domaine examens/notes | **PARTIELLEMENT VÉRIFIÉ** | sources Evaluation/Notes confirmées par la série ; projection temporelle des notes = hors périmètre pilote (risque PII/décision) |

## 3. Lacunes confirmées (après audit, pas déduites du nom)

1. **Aucun axe temporel** : aucun simulateur existant ne projette un état
   dans le temps (tous instantanés ou parcours individuel reconstitué).
2. **Aucune horloge abstraite** : rien ne matérialise « temps simulé » ;
   toute temporalité devra être explicite (mandat : jamais l'horloge système).
3. **Pas de moteur d'événements discrets** (ordonnancement, simultanéité,
   périodes, changements de calendrier).
4. **Pas de classement multicritère avec explications** (la « rétention »
   EDT est humaine, pas un classement automatisé motivé).
5. **Pas de génération automatique de variantes** au-delà du lignage
   manuel d'affectation (`simulation_origine_id` saisi, pas généré).
6. **Pas de quota/annulation/reprise** propres aux simulations (les plafonds
   Lot 14 sont des bornes d'entrée, pas une gouvernance d'exécution).

## 4. Modèle conceptuel proposé (moteur central ≠ règles métier)

```
┌─ Moteur central (sans connaissance métier) ─────────────────────┐
│ HorlogeSimulee      date/heure virtuelle ; pas fixe (P1Y/P6M/P1D)│
│ CalendrierSimulé    pas issus du référentiel (années, semestres) │
│ MoteurEvenements    file ordonnée à t simulé ; simultanéité      │
│                     déterministe (ordre total stable) ; aucun    │
│                     now()/sleep — temps injecté                  │
│ GénérateurVariantes graine + bornes → variantes reproductibles   │
│ Évaluateur          contraintes DURES (rejet) / objectifs SOUPLES │
│                     (score pondéré SAISI, jamais inventé)        │
│ Classeur            rangement + explication (contribution par    │
│                     objectif, contraintes actives)               │
│ Exécution           idempotente : (hash_entrees, version_moteur, │
│                     graine) → hash_resultat identique            │
└──────────────────────────────────────────────────────────────────┘
┌─ Adaptateurs métier (lecture seule, un par domaine) ────────────┐
│ EffectifsCohortes   inscriptions validées → flux par promotion   │
│ CapaciteSalles      Room (Lot 14) → capacité dans le temps       │
│ ChargesEnseignement AffectationEnseignement (Lot 14)             │
│ ReglesAcademiques   règles LMD VERSIONNÉES existantes (réutilis.) │
└──────────────────────────────────────────────────────────────────┘
┌─ Persistance (tables additives du module uniquement) ───────────┐
│ scenarios_temporels (définition versionnée, critères, objectifs) │
│ executions_temporelles (statut, hash_entrees/résultat, graine,   │
│   version_moteur, empreinte état initial, durées, plafonds)      │
│ snapshots_executions (état JSON par pas — jamais d'écriture      │
│   métier ; aucune copie de PII : agrégats uniquement)            │
└──────────────────────────────────────────────────────────────────┘
Sécurité : permission `statistiques.view` (+ gestion `statistiques.export`
réutilisées) ; périmètre `FacultePerimetre` ; module flag défaut false ;
throttle préfixé par famille ; toute application réelle d'une décision =
workflow EXISTANT distinct et autorisé (jamais depuis le simulateur).
```

## 5. Domaine pilote recommandé : flux de cohortes LMD pluriannuel

**Justification (factuelle) :**
- Sources les plus fiables du dépôt : `InscriptionAcademique` (2 066
  `valide` réels constatés en sondage Lot 14), `AnneeUniversitaire`,
  `SemestreLmd`, règles de progression versionnées
  (`AcademicProgressionService`), historique disponible.
- Étend le Lot 14 (instantané) avec l'axe qui lui manque (temps), sans le
  réécrire : réutilisation directe de `FacultePerimetre`, des axes et du
  pattern de reproductibilité.
- Agrégats uniquement (zéro PII copiée), lecture seule, permissions
  existantes — le risque sécurité est minimal.
- Les autres domaines attendent : finances (NON VÉRIFIÉ), notes/examens
  (sensible décision/PII), EDT (moteur propre déjà retenu par l'humain).

Question pilote type : « pour un delta d'admissions L1 de +15 %/an pendant
3 ans, quels effectifs par niveau, quelle saturation des salles et quelle
charge par semestre, avec/sans ouverture d'un programme ? » — contraintes
dures (capacité physique), objectifs souples (taux d'encadrement saisi).

## 6. Matrice des risques et tests

| Risque | Gravité | Tests exigés (mandat) |
|---|---|---|
| Fuite d'horloge système dans le moteur | Élevée | unité : horloge (aucun `now()` — scan + test d'injection) ; événements simultanés à t identique → ordre total déterministe |
| Non-reproductibilité (graine/version) | Élevée | mêmes entrées+version+graine → `hash_resultat` identique (2 exécutions, `assertSame`) |
| Effet réel pendant simulation | CRITIQUE | isolation : snapshot des tables métier avant/après (pattern Lot 14 test 11) ; aucune notification/publish/affectation émise (fakes vérifiés) |
| Contournement de permissions/périmètre | CRITIQUE | IDOR inter-facultés (403), périmètre forcé sans paramètre (pattern Lots 14-16), module désactivé → 404 |
| PII copiée dans snapshots | Élevée | agrégats uniquement — test : aucun champ nominatif dans le JSON persisté |
| Exécution coûteuse non bornée | Moyenne | plafonds (variantes/pas/événements), quota par utilisateur, annulation, reprise idempotente, doublons de jobs |
| Changement de calendrier en cours d'année | Moyenne | périodes, bornes, chevauchements d'années (temporalité) |
| Régression des simulateurs existants | Moyenne | non-régression : suites Simulateur/Parcours/Assignment/Schedule existantes REJOUEES (aucune counted-skip) |
| Concurrence d'exécution | Moyenne | MySQL CONCURRENCE UNIQUEMENT sur base dédiée `inscription_*_test` opt-in (garde TestCase existante) |
| Dérive de contrat (précédent DataMesh) | Faible | test d'instantané versionné du format des résultats (leçon LOOP-03) |

## 7. Plan de lots chiffré (complexité : S < 0,5 j · M ≈ 1 j · L ≈ 2-3 j)

| Lot | Périmètre | Complexité | Prérequis |
|---|---|---|---|
| ST-00 | Cet audit + validation du plan | S (fait) | — |
| ST-01 | Moteur pur : HorlogeSimulee, CalendrierSimulé, MoteurEvenements, GénérateurVariantes, Évaluateur, Classeur — 100 % unitaire, zéro dépendance Laravel métier | **L** | stabilité dépôt |
| ST-02 | Contrats adaptateurs + adaptateur pilote EffectifsCohortes (lecture seule) + tests métier | **M** | ST-01 |
| ST-03 | Persistance additive (3 tables, down complet, AUCUNE migration avant validation schéma), exécution traçable idempotente, quotas/annulation/reprise | **M** | ST-02 |
| ST-04 | Contrôleur/JSON + permissions/périmètre réutilisés + comparaison d'exécutions (réutilisation du diff) + sécurité complète | **M** | ST-03 |
| ST-05 | Performance (volumétrie variantes×pas×événements, mémoire) + robustesse (échecs partiels, doublons jobs) | **M** | ST-04 |
| ST-06 | Validation métier : règles et objectifs SAISIS par les responsables ; documentation des limites ; audit des exécutions | **S** | ST-04 |
| ST-07 | Deuxième domaine (capacité salles dans le temps) pour prouver le contrat d'adaptateur | **S/M** | ST-04 |
| ST-08 | Corrections ciblées + rapport final consolidé | S | ST-05..07 |

Dépendance transverse : chaque lot exige le protocole de stabilité
(empreintes ≥15 min) préalablement accepté — la session concurrente
(Lot 19 en cours) reste prioritaire sur le dépôt.

## 8. Verdict de l'audit

**PASS_WITH_RESERVATIONS** — l'audit est complet et factuel : cinq
simulateurs/mécanismes existants inventoriés, réutilisation massive
identifiée (patterns de reproductibilité, périmètre, permissions, bornes),
lacunes confirmées uniquement là où le code l'établit (axe temporel,
horloge, moteur d'événements, classement, génération de variantes,
gouvernance d'exécution). Réserves : (1) domaine finances NON VÉRIFIÉ ;
(2) moteur EDT non audité en profondeur ; (3) **dépôt encore mouvant** —
aucune implémentation/test ne démarrera avant stabilisation constatée ET
validation du présent plan par le propriétaire.

---

### Décisions attendues du propriétaire avant tout code

1. Valider (ou ajuster) le domaine pilote « flux de cohortes LMD ».
2. Valider le plan ST-01→ST-08 et son chiffrage.
3. Trancher la coordination avec la session concurrente (attendre la fin
   de la série / suspendre l'autre session / fenêtres alternées).
4. Confirmer l'interdiction maintenue de toute écriture métier pour les
   états simulés (snapshots JSON dans les tables du module uniquement).

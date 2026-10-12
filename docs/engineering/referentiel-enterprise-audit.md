# Référentiel LMD — Audit ENTERPRISE (LOOP 00)

Date : 11/10/2026 · Périmètre : `App\Modules\Referentiel` + consommateurs · Méthode : lecture du code,
exécution ciblée, base locale réelle en **lecture seule** (aucune écriture d'audit).

> Contexte : pas de dépôt git dans ce workspace (`git status` indisponible),
> pas de `docs/roadmap.md`. Références : `AGENTS.md`, `docs/referentiel/*`,
> `docs/MODULE-REFERENTIEL.md`.

## 1. Données initiales — vérifiées (base locale, 11/10/2026)

| Indicateur | Attendu (rapport) | Observé |
|---|---|---|
| Programmes | 4 | 4 (`DEMO-LGL`, `PAR-GEST`, `PAR-ECO`, `PAR-INFO`, tous FST, statut `actif`) |
| Niveaux | 12 | 12 (L1/L2/L3 × 4) |
| UE | 7 | 7 (DEMO 1, GEST 3, ECO 1, INFO 2) |
| ECUE rattachées | 14 | 14 (2/UE) |
| Matières standalone (`ue_id NULL`) | 38 | 38 (total matières 52) |
| Maquettes actives | 0 | 0 |
| Maquette brouillon DEMO-LGL | 1 | 1 (`draft` v1, année 2026-2027 active, 1 UE + 2 occurrences aux régimes vides) |
| Semestres | 24 | 24 (S1–S6 × 4) |
| Types de diplômes | 4 | 4 (`LIC` licence durée 3 + 3 autres) |

Constats complémentaires : 2 087 inscriptions académiques sans `programme_id`
(legacy), 12 liées aux 4 programmes ; 4 examens (PAR-GEST × 2, PAR-INFO × 2,
année 7) créés hors `genererEpreuves` ; 1 arête prérequis (`DEMO-ALG2 ← DEMO-ALG`,
acyclique) ; UE S2/S4/S5/S6 vides partout (DEMO : seule S1 couverte).

## 2. Fonctionnalités présentes

- CRUD générique des 9 entités (`EntitesReferentiel`, recherche, pagination 12,
  gardes de suppression, périmètre avant validation, INV-1/2/3/5/6/7/10).
- Maquettes versionnées : `draft→review→approved→active→archived`, bascule
  atomique 1-active, verrouillage `approved/active/archived`, snapshot UE+ECUE
  en nouvelle version, `lockForUpdate` anti double-active (durcissement 11/10).
- ECUE : rattachement UE, `ordre` drag-drop, prérequis anti-cycle (BFS),
  contextes annuels `maquette_ue_ecue` (coef/crédits/volumes/régime/K_PARMI_N).
- Tableau de bord (7 compteurs scopés + 8 alertes), arbre hiérarchique,
  page qualité (200), recherche globale (providers scopés).
- Simulation d'activation lecture seule (`MaquetteActivationSimulationService` +
  `referentiel:simuler-activation`, 9 tests) : validations/blocages codés.
- **Nouveau (11/10)** : centre de pilotage (`admin.referentiel.pilotage`) et
  prévalidation UI (`admin.referentiel.prevalidation[.show]`) avec gravités
  BLOCKER/WARNING/INFO + règles `PROG/NIV/DIP/SEM/UE/EC/PRE/MAQ-*`.
- Consommateurs branchés sur le canonique : `inscrireEcue` / `genererEpreuves`
  exigent la maquette active (refus explicite, repli documenté).
- Imports/exports DataExchange (10+ définitions) + API `referentiel:read`
  (6 endpoints) + `backfill` dry-run (jamais de rattachement auto).

## 3. Fonctionnalités absentes (→ boucles)

| # | Absent | Boucle |
|---|---|---|
| Éditeur visuel semestres×UE×ECUE (DnD, totaux, brouillon) | 03 |
| Comparaison v1↔v2 (ajouts/retraits/modifs, synthétique+détaillée) | 04 |
| Explorateur interactif (repliable, recherche scopée, chargement progressif) | 05 |
| Qualité : statuts de traitement, correction assistée transactionnelle | 06 |
| Migration contrôlée des 38 standalone (simulation→validation→application) | 07 |
| Charge pédagogique (crédits/heures par semestre, descriptif sans seuils inventés) | 08 |
| Dashboard multi-facultés modernisé (filtres combinés, tri, a11y) | 09 |
| Import intelligent (prévisualisation, reprise, idempotence) au-delà du pipeline | 10 |
| Graphe prérequis visualisé (cycles, hors-programme) | 11 |
| Audit gouvernance unifié (acteur/motif/avant-après, conservation) | 12 |
| Parcours étudiant lecture seule (version applicable démontrée) | 13 |

## 4. Dette technique

1. `matieres.niveau/semestre` legacy NOT NULL (double lecture, suppression interdite sans migration).
2. 2 087 inscriptions sans `programme_id` (consommateurs en repli, à qualifier LOOP 06/07).
3. Régimes `''` dans le plan DEMO (repli `obligatoire`, à normaliser à la prochaine version).
4. Pas d'index unique anti double-active (MySQL : colonne générée envisageable, non faite).
5. Compteurs dashboard : UE/ECUE du plan vs programme non distingués (pilotage couvre le besoin autrement).

## 5. Invariants (rappel opposable)

FK restrict partout · unicités contextuelles · codes normalisés (`INV-4`) ·
chaîne spécialité→mention→domaine = faculté du programme (INV-2/3/9) ·
UE non transférable (INV-1) · semestre du pivot = semestre de l'UE (INV-5) ·
niveaux ≤ durée diplôme (INV-10) · UE verrouillée si maquette non-brouillon ·
`approved/active` exigent `maquettes.approve` · scope facultaire partout ·
types de diplômes transversaux.

## 6. Risques

| Risque | Niveau | Couverture |
|---|---|---|
| Activation d'un plan incomplet (S2–S6 vides) | élevé | simulation BLOCKER + workflow, **0 activation effectuée** |
| Double-active concurrente | moyen | `lockForUpdate` (preuve MySQL réelle non rejouée) |
| Migration standalone au nom seul | moyen | interdit (LOOP 07 : 4 temps) |
| Fuite inter-facultés (cartes/filtres/API/exports) | moyen | policy + tests IDOR, à consolider LOOP 14 |
| N+1 (arbre, pilotage 12 simulations) | faible | eager + page seule, mesures LOOP 15 |

## 7. Dépendances / consommateurs

Inscriptions (acad/péda) · affectations groupes · enseignement · emplois du
temps · évaluations/épreuves/délibérations · population · archives · DataExchange ·
API v1 · recherche globale · listes/exports. Tous lisent le canonique ; les flux
plan-actif refusent explicitement sans maquette (jamais de silently-dégradé).

## 8. Tests existants (base)

`ReferentielTest/Hierarchy/Integrity/DeletionPolicy/TenantIsolation/Lot2/
AffectationLmd/ImportIdempotence/Performance/Phase1`, `MatiereCatalogue/Context/
LegacyCatalogue`, `MaquetteVersioning/PlanEcues`, `Backfill`,
`ActivationSimulation` (9), `Pilotage` (7), `Prevalidation` (7),
`Editeur` (4), `Comparaison` (6), `ExplorateurQualite` (5),
`StandaloneCharge` (5), `DashboardGraphe` (7), `ImportBoucle10` (14),
`Gouvernance` (4), `ParcoursEtudiant` (9), `Securite` (5),
`NormalisationImport` (5), `PlanImport` (6), `RaceMysql` (2 + 2 skips
hors MySQL), `ConcurrenceMysql` (1 + 1 skip hors MySQL) —
**219 verts cumulés le 12/10** (218 + 1, sans chevauchement).

## 11. Flux d'import référentiel (LOOP 10, constat + écarts comblés)

Fichier → analyse (parse IoFactory 5 formats, normalisation NFC,
corrections déterministes, règles + métier `validerLigneMetier`,
doublons fichier + métier, classification graduée) → aperçu (token
session lié auteur+entité, empreinte sha256, avertissement
IMPORT_ALREADY_PROCESSED) → revue optionnelle (accepter/modifier/
rejeter/attente, complète exigée, collisions intra-lot refusées) →
confirmation (deps, mode, revue, **revalidation anti-TOCTOU**, puis
**token consommé avant écriture** — double confirmation expire) →
transaction unique ≤500 lignes sinon file (`ImportJob` en_file→en_cours
atomique, reprise non rejouée) → `ImportReport` + `IntegrationLog` +
notification. Upsert programmes **scopé faculte+code** (fini le
détournement inter-facultés ; unicité réelle). `remplacer` interdit
programmes (jamais de vidage). Résiduels documentés : spécialité/
diplôme inconnus ignorés sans alerte, code programme ambigu inter-
facultés pour UE/niveaux (fail-closed pour les restreints).

## 12. Parcours étudiant + sécurité (LOOP 13–14, 12/10)
`ParcoursEtudiantService::consulter()` (lecture seule, admin + portail
partagés) : inscription la plus récente, programme/niveau réels (repli
dossier, jamais deviné — 2087 SANS_PROGRAMME), maquette applicable
démontrée = ACTIVE programme×année (même règle que `inscrireEcue` ;
la courante ne s'applique jamais implicitement — test autre-année),
chaîne canonique hors plan sinon, matières suivies. Routes
`admin.etudiants.parcours` (Gate `view`) + `etudiant.parcours`
(session-scopé, entrée nav). LOOP 14 : `audit:routes-securite`
authentification OK (parcours = REVUE attendue, Gate contrôleur comme
ses sœurs) ; `ReferentielSecuriteTest` 5/5 (auth sur 7 routes, zéro
fuite restreint, mass assignment inopérant, API 401, scope service
direct). PDF parcours non ajouté (confusion relevé officiel —
décision assumée).

## 9. Plan de migration (legacy, 4 temps — non démarré)

1. Inventaire 38 (usages + candidats) → 2. simulation → 3. validation humaine →
4. application idempotente traçable. Repli legacy conservé jusqu'à migration
prouvée des consommateurs. Décision : ne jamais affecter au nom seul.

## 10. Ordre des boucles et critères d'acceptation

Ordre imposé §21 conservé (00→18). Critère générique par boucle : LAN→DEV→TEST→
AUDIT→CORRECTION→VALIDATION→DOCUMENTATION→VERDICT, preuves + tests + périmètre
vérifié + verdict explicite (`PASS`/`PASS_WITH_RESERVATIONS`/`IN_PROGRESS`/
`BLOCKED`/`NOT_EXECUTED`/`NOT_APPLICABLE`). Clôture `PASS` interdite si
fonctionnalité obligatoire, sécurité ou migration critique non prouvée.
État au 11/10 : **00 PASS · 01 PASS · 02 PASS_WITH_RESERVATIONS**
(4 programmes en ATTENTE, activation interdite par les données) ·
**03 PASS · 04 PASS · 05 PASS · 06 PASS** (éditeur, comparaison,
explorateur, qualité — corrections automatiques volontairement absentes :
choix pédagogique = geste humain) · **07 PASS (temps 1) · 08 PASS**
(inventaire classé sans rattachement auto, charges descriptives sans
seuils — temps 3/4 en attente de validation humaine) ·
**09 PASS · 11 PASS** (comparaison multi-facultés 6 requêtes fixes +
filtre verrouillé restreint, graphe scopé avec arêtes en cycle et hors
programme — moteur BFS canonique réutilisé) ·
**10 PASS · 12 PASS** (import : token à consommation unique, upsert
programmes scopé faculte+code, 14 tests ; gouvernance : refus tracés
sans PII, répétitions impossibles, approve réservé) ·
**13 PASS · 14 PASS** (parcours lecture seule admin+portail, version
applicable démontrée programme×année, 2087 jamais devinés ; sécurité :
auth sur 7 routes, zéro fuite, mass assignment inopérant, API 401).

## 13. Performance mesurée (LOOP 15, 12/10)

Références sans SLA (bornes = observé + marge, sqlite :memory: sauf
mention MySQL dev) :

| Parcours | Requêtes | Temps | Volume |
|---|---|---|---|
| `simuler()` 4 prog. (dev MySQL) | 59 (~15/prog., borné) | 435 ms | 2 Mo |
| `parFaculte()` 10 (dev) | 6 (fixes) | 32 ms | 0 |
| compteurs + anomalies 200 (dev) | 24 | 35 ms | 0 |
| `inventaire()` 38 (dev) | 13 (fixes) | 294 ms | 0 |
| charges PAR-GEST (dev) | 8 | 18 ms | 2 Mo |
| graphe PAR-GEST (dev) | 11 | 221 ms | 0 |
| parcours étudiant (dev) | 4 | 34 ms | 0 |
| pilotage / prévalidation (fixture 5 prog.) | < 150 / < 5 s | OK | — |
| maquette show / comparer (fixture) | < 60 | OK | — |

Optimisation prouvée : `compteurs()` rechargeait le builder à chaque
`pluck` (dashboard 47 > borne 45 après +7 requêtes comparaison) →
ids chargés 1 fois + comparaison partagée select/tableau + calculée
seulement si affichée — chiffres identiques (10/4/12/7/14/0/1),
borne 45 reverdie. Aucune autre optimisation requise (tout est plat).

## 14. Non-régression consolidée (LOOP 16, 12/10)

Vagues : A périmètre maquettes/éditeur/pilotage/prévalidation/
simulation/gouvernance/import (104) · B socle/perf/sécu/standalone/
dashboard (112) · C consommateurs inscriptions/progression/admissions/
affectations/évaluations/délibérations/API/DataExchange/parcours
(175) · sécurité écritures+IDOR (27) + autorisations+import-export
(14) · périmètre import (222 : 120 + 102 dont PERF-1000) · navigation
incluse. **0 échec.** 1 régression réelle détectée et corrigée
(dashboard 47, voir §13). Suite complète mono-processus :
NOT_EXECUTED (durée ; couverture par vagues cumulées).

## 15. Statuts finaux (LOOP 17–18)

00 PASS · 01 PASS · 02 PASS_WITH_RESERVATIONS (4 ATTENTE, activation
interdite par les données) · 03 PASS · 04 PASS · 05 PASS · 06 PASS ·
07 PASS (temps 1 ; 3/4 NOT_EXECUTED, validation humaine requise) ·
08 PASS · 09 PASS · 10 PASS · 11 PASS · 12 PASS · 13 PASS · 14 PASS ·
15 PASS · 16 PASS · 17 PASS (ce document) · 18 : rapport final ci-dessous.
15 PASS · 16 PASS (vagues) · 17 PASS (ce document) · 18 : voir rapport.

Limites connues : preuve FOR UPDATE MySQL non rejouée (skip sqlite
documenté) ; code programme ambigu inter-facultés pour UE/niveaux
(fail-closed restreints) ; spécialité/diplôme inconnus ignorés sans
alerte à l'import ; 2 087 inscriptions sans programme ; 38 standalone
EN_ATTENTE ; 0 maquette active (décision pédagogique : UE S2–S6 +
3 maquettes v1 + workflow DEMO review→approved→active).

## 16. Pistes avancées A/B/C (12/10, choix utilisateur « tous »)

**A. Consolidation — PASS.**
- A1 normalisation unifiée : `NormalisationService::nomComparable()`
  (casse/espaces/accents, convention `SearchNormalizer`) — source
  unique de l'appariement par nom, branchée inventaire + backfill
  (jumeaux côté PHP, fini la divergence de collation). Preuve réelle
  dry-run : `[DUPLICATE] INFO201 « Bases de données » ↔ ECUE-INFO-03`
  (l'autre main signalait la divergence ; résorbée, 0 migration).
- A2 références inconnues fail-closed : `SPECIALITE_INTROUVABLE` +
  `DIPLOME_INTROUVABLE` en validation ET persister (miroir anti-TOCTOU) ;
  colonne absente = repli null inchangé.
- A3 preuve MySQL réelle : `MaquetteRaceMysqlTest`
  (`MAQUETTE_RACE_MYSQL=1`, base scratch `maquette_race_scratch`,
  rollback, dev intact 4/1) — FOR UPDATE émis par `transiter()`,
  NOWAIT concurrent refusé, unicité active. 2/2 verts, 2 skips sinon.
- A4 résolution canonique : `Programme::resoudreParCode($code,
  $faculteCode)` (miroir `Matiere::resoudreParCode`) ; `UeDefinition`
  gagne la colonne optionnelle `faculte` (import + export symétriques,
  `cleMetier` étendue) — ambiguïté levée si fournie, repli historique
  sinon (documenté).

**B. Déblocage activation — PASS (sans invention).**
- B6 import `maquettes-plan` (import seul, registre + dépendances) :
  programme/année/UE/ECUE requis (année jamais devinée), brouillon
  réutilisé ou créé vN, gardes INV-1/INV-5/régime/type-évaluation,
  verrou respecté (`MAQUETTE_VERROUILLEE`), périmètre, idempotence par
  clé unique, **jamais d'approbation/activation**. 6 tests.
- B1 kit guidé (pas de coquilles générées — ce serait de l'invention) :
  lien « Créer la maquette v1 » depuis la prévalidation si
  `MAQUETTE_ABSENTE` + présélection programme/année active dans le
  formulaire (contexte seul, validé serveur).

**C. Cycle de vie — PASS (C2 différé, justifié).**
- C1 lignage : section « Versions du programme » sur la fiche (statut,
  année, MAJ, Ouvrir/Comparer — 0 table).
- C3 graphe → fiche ECUE (retrait du cycle au seul endroit autorisé,
  `detacherPrerequis` existant).
- C4 PDF parcours portail (`etudiant.parcours.pdf`, throttle 10/1,
  moteur officiel, sans CIN, « informatif — ni relevé ni attestation »,
  échec sans 500). 2 tests (contenu %PDF, panne mockée).
- C2 notifications de transition : DIFFÉRÉ — politique destinataire
  (qui ? programme/responsable/faculté ?) = décision métier requise ;
  spammer les étudiants est exclu d'office. Base prête (event
  `MaquetteTransitionnee` + dispatcher existants).

Vagues : 11 (A+B6) + 41 (C1/C3/C4/B1) + 46 (re-passe) + 152 (périmètre)
+ 86 (imports/exports/portail/progression) + 2 MySQL + 1 SALIMA = **0
échec**. Réel intact (4/1/0/38). Pint vert.

**Suite complète mono-processus (12/10, fond) : 3007 tests, 2993
réussis, 14 ignorés (gates MySQL/environnement documentées),
0 échec, EXIT=0** (`tmp/tmpdir/suite-complete-20261012.log`) —
première exécution intégrale sans le crash historique : LOOP 16 soldé
définitivement.

## 18. Convergence consolidation parallèle (12/10)

Autre main sur le même périmètre — audité et accepté (0 conflit) :
- Migration `2026_10_12_000001` (colonne générée
  `active_programme_id` + unique, MySQL-only idempotente/réversible,
  appliquée dev : migration=1, idx=1) : besoin prouvé par eux (écrit
  direct hors `transiter()`), compatible avec la bascule (archiver
  d'abord, activer ensuite) et les verrous ; ma décision « pas de
  migration » visait le chemin service — les deux protections se
  complètent, aucune régression (96 tests : 92 verts + 4 skips MySQL).
- `anomalies()` : erreur « flux bloqués » si inscriptions/examens,
  avertissement « en construction » sinon — sans règle inventée, mon
  mapping qualité (`maquette active` substring) et mes tests tiennent
  (36/36 croisés avec `ReferentielStatsBlocageTest`).
- Leçon partagée : prouver d'abord (course, doublon accepté), outiller
  ensuite — appliquée des deux côtés.

## 17. Consolidation post-A/B/C (12/10)

**LOOP 1 — Livraisons vérifiées.** Fichiers présents (normalisateur,
`maquettes-plan`, 2 tests MySQL, 2 tests import/normalisation, PDF) ;
`nomComparable` dans les 4 chemins (inventaire ×2, backfill ×2) ;
race re-prouvée 2/2 MySQL 9.2 ; idempotence + double-confirmation
éprouvées ; PDF (%PDF, sans CIN, échec sans 500) et graphe (nœuds →
fiches, scope) conformes ; doc §16 alignée au code.

**LOOP 2 — Anti-double-active : décision.** Schéma : unique
(programme,année,version) + index (programme,statut) — AUCUNE
contrainte durable d'unicité active. Course réelle (fork ×2,
`transiter` simultanés, scratch MySQL) : **deadlock 1213 prouvé** sur
le verrou frères — converti en `DomainException` rejouable
(« Activation concurrente détectée : réessayez », mappée 422 par le
contrôleur existant) ; 3 runs stables : exactement 1 active, perdant
archivé ou resté approved, sorties {0,3} seules. **Pas de migration** :
aucun chemin ne contourne `transiter()` (web/import n'écrivent jamais
`statut`), contrainte générée superflue et à risque sur l'historique —
étude close, verrous + refus explicite = protection suffisante et
prouvée.

**LOOP 3 — Dashboard/qualité.** Compteurs exigés désormais présents :
standalone (38), rattachées (14, libellé clarifié), approuvées (0),
archivées (0) — regroupés en 1 requête (borne 45 tenue, chiffres
vérifiés identiques). Construction vs bloqué : le dashboard reste
factuel ; la distinction vit dans le pilotage (`ACTIVE_DEJA` =
opérationnel, sinon « Non utilisable » — règle moteur existante,
jamais inventée).

**LOOP 4 — Arbitrages humains (non appliqués).**
1. Destinataires/contenu des notifs d'activation (C2) — trancher qui
   (responsable ? scolarité faculté ?) avant tout envoi.
2. Seuils de charge — aucune norme fournie, écrans descriptifs.
3. « SEM-03 » : aucune entité de ce code (S1–S6 existants) —
   clarification requise (couverture S3 ? autre objet ?).
4. 38 standalone : valider les DOCUMENTÉES une par une (temps 3/4).
5. UE S2–S6 + 3 maquettes v1 + workflow DEMO review→approved→active.
6. Versions historiques : règle actuelle = ACTIVE programme×année ;
   tout autre rattachement (ex. courante pour tous) exige une
   décision écrite.

**LOOP 5 — Verdicts.** Ciblés : race 2/2 + concurrence 1/1 (MySQL
réel, 2 skips sans flag) + vagues 26 + 84 sqlite = 0 échec. Docs :
§16–17. **Consolidation : PASS** (1 vraie course traitée, 0 donnée
touchée : 4/1/0/38).

## 13. Registre de propositions (stable, dédoublonné — consolidation 12/10)

Sources fusionnées : master-loop §Propositions + boucles entreprise 00-15.
État : ✅ livré et prouvé · ◑ partiel · ○ décidable-humain-requis.

| ID | Proposition | État | Preuve |
|---|---|---|---|
| P-01 | Normalisation commune des classifieurs standalone | ✅ | `NormalisationService::nomComparable` ×4 sites, `NormalisationImportTest` |
| P-02 | Preuve concurrence activation (MySQL réel) | ✅ | `MaquetteRaceMysqlTest` 4/4 sur 9.2 scratch |
| P-03 | Garde SQL anti double-active (colonne générée + unique) | ✅ | migration 2026_10_12 (idempotente, réversible, no-op sqlite), appliquée dev |
| P-04 | Compteurs dashboard standalone + maquettes par état | ✅ | `compteurs()` 10 clés, vue 10 cartes |
| P-05 | Distinction en construction / opérationnel bloqué | ✅ | `anomalies()` (erreur si consommateurs) + 5 tests |
| P-06 | Import `maquettes-plan` (brouillon seul) | ✅ | 6 tests, jamais d'activation |
| P-07 | Graphe prérequis visualisé (BFS canonique) | ✅ | `GraphePrerequisService` + 7 tests |
| P-08 | PDF parcours étudiant (portail, sans CIN) | ✅ | route + moteur officiel + 2 tests |
| P-09 | Notification activation maquette | ○ | destinataires/contenu = décision métier (LOOP 4.1) |
| P-10 | Seuils d'alerte charges pédagogiques | ○ | aucun seuil normalisé existant (LOOP 4.2) |
| P-11 | Alerte SEM-03 crédits vs `credits_attendus` | ○ | colonnes présentes, règle à activer (LOOP 4.3) |
| P-12 | Correspondances standalone + UE S2-S6 manquantes | ○ | validation pédagogique (LOOP 4.4/4.5) |
| P-13 | Règle d'application aux historiques des nouvelles versions | ○ | inexistante, à définir (LOOP 4.6) |
| P-14 | Assistant bascule année universitaire (versions en masse) | ○ réalisable | sur `nouvelleVersion` existant, rapport avant application |
| P-15 | API prévalidation (`referentiel:read`) | ○ réalisable | réutilise le moteur, lecture seule |

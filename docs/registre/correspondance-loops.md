# Correspondance anciens LOOP → identifiants canoniques

> Établi le 12/10/2026 (LOOP 1). Les anciens numéros LOOP sont conservés comme **références
> historiques uniquement** — la clé stable est l'identifiant canonique du
> `registre-canonique.md`. Les collisions de numérotation entre programmes sont
> résolues par le préfixe de domaine.

## 1. Programmes LOOP numérotés

| Programme (fichier/journal) | Ancienne boucle | ID canonique | Notes |
|---|---|---|---|
| `LOOP_Inscription_Etudiants_Experience_Intelligente.md` — LOOP 01 | Cartographie/diagnostic | INSC-12 (couvert) | Audit initial du parcours |
| id. — LOOP 02/03 | Assistant guidé + autosave/reprise | INSC-07 | Autosave = 2e main (`InscriptionBrouillonTest`) |
| id. — LOOP 04 | Checklist documentaire | INSC-15 | |
| id. — LOOP 05/06 | Dépôt sécurisé + préremplissage MENS | INSC-02 | |
| id. — LOOP 07 | Doublons/incohérences | INSC-07 (garde CIN normalisée) | |
| id. — LOOP 08 | Éligibilité et orientation | PACA-07 | |
| id. — LOOP 09 | Résumé avant soumission | INSC-07 | |
| id. — LOOP 10 | Suivi du dossier + reset mdp | INSC-08 | |
| id. — LOOP 11/12 | Compléments/corrections + notifications | INSC-08 (+ INSC-06 côté admin) | Compléments 09/10 |
| id. — LOOP 13 | Récépissé PDF | INSC-08 | Convergence 2 mains 10/10 |
| id. — LOOP 14 | Affectation académique | PACA-05, PACA-07, INSC-14 | Approfondie 10/10 (concurrence) |
| id. — LOOP 15 | FAQ officielle | INSC-09 | Approfondie (assistant) 10/10 |
| id. — LOOP 16 | Aide/RDV scolarité | INSC-09 | RDV livré par 2e main, notifs `NotifyDemande` |
| id. — LOOP 17 | Accessibilité | INSC-10 | |
| id. — LOOP 18 | File administrative | INSC-11 | Convergence 2e main + phases 0→F |
| id. — LOOP 19 | Audit sécurité transversal | INSC-12 | ≠ SEC-02 (mission §11 du 04/10) |
| id. — LOOP 20 | Performance/observabilité | INSC-13 | |
| id. — LOOP 21 | E2E/non-régression | Couvert par suite complète du 12/10 | |
| id. — LOOP 22 | Mesure d'expérience | INSC-13 (partiel assumé) | |
| id. — LOOP 23 | Audit final GO/NO-GO | INSC-12 | GO — 23/23 couvertes |
| `LOOP_Portails_Enseignant_Etudiant_Laravel.md` — LOOP 01-04 | Socle, dashboard, EDT, appel | PORT-01…04 | 101 tests portails |
| id. — LOOP 05/06 | Notes + ressources enseignant | PORT-05, PORT-06 | |
| id. — LOOP 07/08 | Communication + charge/stats | PORT-07 (**PARTIAL**), PORT-08 | |
| id. — LOOP 09-14 | Portail étudiant (dashboard→ressources) | PORT-09…14 | |
| id. — LOOP 15/16/17 | Démarches, accompagnement, services communs | PORT-07, PORT-13 | |
| id. — LOOP 18/19 | Recherche + exports | PORT-15, PORT-16 | |
| id. — LOOP 20 | Mode hors-ligne | PORT-18 | **NO-GO documenté** |
| id. — LOOP 21 | QR temporaire | PORT-04 (fusionné) | |
| id. — LOOP 22 | Assistant IA | PORT-19 | **NO-GO documenté** |
| id. — LOOP 23 | Audit transversal final | PORT-17 | |
| `docs/parcours-academique/loops-17-34.md` — P01…P34 | Toutes exécutées | PACA-08 | + améliorations UX + audit sécurité/perf |
| MASTER LOOP référentiel — LOOP 0, 0-bis, 1…8, consolidation, clôtures | Audit + invariants + clôtures | REF-05 | Résultats partiels → REF-06…09 |
| `loop_emploi.md` — SCHEDULE-01…15 | Audit, IDOR, TOCTOU, déplacement, clôture | SCH-02, SCH-03, SCH-05 | |

## 2. Boucles « métier » des journaux AGENTS

| Ancien intitulé (date) | ID canonique |
|---|---|
| LOOP 37 D-004 (check notes MySQL) | EXM-07 |
| LOOP 38 D-005 (payload analyse -43 %) | PACA-02 |
| LOOP 39 (notifs batch affecterLot) | PACA-01 |
| LOOP 40 (sync niveau groupe) | MTR-02 |
| LOOP 41 (`evaluerLot` supprimé) | PACA-02 |
| LOOP 42 (identifiant national MENS) | INSC-02 |
| LOOP 43 (`attendance.update` retiré) | EXM-01 (permissions) |
| LOOP 44 (homonymes enseignants import) | MTR-02 |
| LOOP 45 (bandeau transition périodes) | INSC-01 |
| LOOP 2/3 industrialisation affectation (benchmark 10K) | PACA-02 |
| LOOP P1 risques attendance (verrou upsert + audit suppression) | PORT-12 |
| LOOP P2 lots masse async | PACA-04 |
| LOOP C CIN masquée registre | SEC-05 (minimisation) |
| LOOP A (façade éligibilité) | PACA-07 |
| LOOP B (changement de groupe) | PACA-06 |
| LOOP C (catégories file) | INSC-11 |
| LOOP D (KPIs automatisation) | INSC-11 |
| LOOP notes anonymisées / compostage (07/10) | EXM-05 |
| LOOP audit évaluation post-compostage | EXM-05 (P4 dormant documenté) |
| LOOP N+1 délibération·résultats·validation | EXM-07 |
| LOOP partie examens — incidents | EXM-02 |
| LOOP surveillance & garde DS+examens | EXM-03 |
| LOOP affectation intelligente surveillants + validation adversariale | EXM-03 |
| Module emploi des surveillances | EXM-04 |
| FIX boutons affectation auto/optimiser | EXM-03 |
| JOB affectation surveillances async | EXM-03 |
| LOOP contraintes configurables surveillances | EXM-03 |
| LOOP dimensionnement par salle + extension grandes salles + GO 1-4 | EXM-03 |
| LOOP EDT intelligent enseignant (+ convergences) | PORT-03 |
| LOOP présence intelligente enseignant (+ convergences) | PORT-12 |
| LOOP appel intelligent étudiants (+ convergence) | PORT-04 |
| LOOP expérience inscription (23 LOOPs) + revalidation + compléments | INSC-07…15 (voir §1) |
| LOOPs 15/16/17/20 — FAQ/aide/a11y/perf | INSC-09, INSC-10, INSC-13 |
| LOOPs 13/14/15 — récépissé·affectation·FAQ (protocole par cahier) | INSC-08, PACA-05, INSC-09 |
| LOOP 14 approfondi (concurrence) | INSC-14 |
| LOOP 15 approfondi (assistant FAQ) | INSC-09 |
| LOOPs 16/17 (RDV + notifs demandes + aria) | INSC-09, INSC-10 |
| LOOPs 18/19 (file + sécurité transversale) | INSC-11, INSC-12 |
| LOOP correctifs périodes A1/A2 | INSC-01 |
| LOT 1 finalisation master (B4/B5/B8) | INSC-07, INSC-02, INSC-06 |
| MISSIONS « phases restantes » (admissions, diplôme G, purge, backups, notifs périodes) | PACA-09, EXM-08, INFRA-07, SEC-07, INSC-01 |
| MISSION MENS / documents obligatoires / notes import / fiabilisation périodes | INSC-02, INSC-03, INSC-04, INSC-01 |
| MISSION conversion page inscription | INSC-07 |
| Mission audit sécurité §11 (04/10) | SEC-02 |
| Hardening 29/09 (§10) | SEC-01 |
| Audit sécurité du jour 12/10 (baseline/remediation) | SEC-08 |
| Audit CI/CD 12/10 (BLOCKED) | INFRA-01 |
| Modules Sentinel / sauvegardes planifiées | SEC-06, SEC-07 |
| Enseignants phases A-E / registre étudiants / file de traitement | MTR-02, PACA-11, INSC-11 |
| Doc import-export-affectation + master import/export intelligent | INFRA-09 |
| LOOP vérification boucle dossier (CAS 6-9) | INSC-06 |
| LOOP affectation auto après validation (Cas 1-11) | PACA-05 |
| LOOP périodes UI / page étudiant / vérification auto / fiabilisation | INSC-01 |
| Dataset FSEGS + correctifs master | PACA-10 |
| Fiches présence multi-modèles + QR | PORT-04, EXM-03 |
| Nouveaux entrants L1/M1 dossier (provisionnement) | INSC-02 |
| LOOP 4 relevés | INSC-05 |
| LOOP centre de résolution | INSC-06 |
| Lot 3 fuseau/bornes | INSC-01 |
| Boucles inscriptions/affectations (audit LAN, LOOP A/B) | PACA-07, PACA-06 |

## 3. Règle

Toute nouvelle boucle reçoit un identifiant canonique dans son domaine à la création ;
l'ancien numéro ne doit plus jamais servir de clé unique. Les intitulés libres
(« LOOP A », « mission », « lot », « fix ») sont rattachés ici pour la traçabilité, mais
ne créent **pas** de nouvelle entrée si le chantier correspondant existe déjà au registre.

# Roadmap (ajustable après chaque audit)

```text
PHASE 0 Fondations ✓ (ce document + 8 docs, baseline ci-dessus)
  ↓
PHASE 1 Référentiel académique ✓ (maquettes versionnées, ECUE=Matiere)
  ↓
PHASE 2 Population ✓ (statuts, affectations + capacité, datasets)
  ↓
PHASE 3 Inscriptions / Réinscriptions ✓ (workflow, progression LMD idempotente)
  ↓
PHASE 4 Enseignement ✓ (ECUE×type×année, sous-groupes, services)
  ↓
PHASE 5 Évaluation / Examens ✓ (sessions→épreuves→candidats→notes)
  ↓
PHASE 6 Délibération / Progression ✓ (jury, décisions, N+1 dry-run)
  ↓
PHASE 7 Documents académiques ✓ (GED 3 services, versions, expires)
  ↓
PHASE 8 Dossier / Archive ✓ (StudentArchive + confidentiel isolé)
  ↓
PHASE 9 Services étudiants ✓ (OnlineRequests SLA, portail, Finances)
  ↓
PHASE 10 Pilotage ✓ (BI cache versionné, cockpit, qualité, stats)
  ↓
PHASE 11 API / Interopérabilité ✓ (scopes, DataExchange 27 entités, webhooks HMAC)
  ↓
PHASE 12 Sécurité / Résilience ✓ (hardening, supervision, sauvegardes DR)
  ↓
PHASE 13 Industrialisation ✓ (PWA, CI, pint, audits, sessions, schedules API)
  ↓
MATURITÉ (suivi : baseline §29, roadmap revue à chaque phase)
```

Restes connus — RÉSORBÉS le 05/10/2026 (sauf mention) :
  ✓ Admission Master : entité `Admission` dédiée (acte traçable consommé
    par la progression LMD — `AcademicProgressionService` + `AdmissionTest`)
  ✓ Phase G : génération du DIPLOME (catalogue `documents-officiels`,
    décision admis* + fin de cycle — `DiplomePhaseGTest`)
  ✓ Purge des pièces orphelines : `documents:purger-orphelins`
    (dry-run par défaut, racines GED uniquement, planifié dimanche 04:30)
  ✓ Backups chiffrés : `sauvegarde:creer --chiffrer` (sodium, clé
    SAUVEGARDE_CLE) + `sauvegarde:dechiffrer` (restauration DR documentée)
  ✓ Notifications des périodes d'inscription : `periodes:avertir`
    (ouverture + clôture imminente, idempotent par période)

Restant (dette assumée, cf. AGENTS.md) : facettes recherche avancée,
assistant masse documents (preview/ZIP/fusion-PDF), 2FA (décision),
CSP Alpine build, C2/C3/C4/C5/D1-D5 — priorisées au besoin.
(Livrés depuis : wizard 6 étapes, correction MENS workflowisée R12,
checklist pièces mon-dossier, CHECK SQL notes — retirés de la dette.)

Suivi 09-10/10/2026 (audits + boucles de consolidation) :
  ✓ Module Enseignants : audit + phases A-E (isolation scopée modèle
    salles, statut/actif unifiés, import/export 9/9, traçabilité)
  ✓ Registre Étudiants : pagination/charge/PII/verrous consolidés
  ✓ File de traitement : filtres/catégories dérivées, KPIs automatisation
  ✓ Emplois du temps : clôture SCHEDULE-07→15 (11/10, verdict
    CLOSED_WITH_RESERVATIONS — rapport `schedule-audit-report.md`
    §Clôture) : garde publication portail étudiant (fixtures
    historiques alignées), notifs post-commit fail-soft, TOCTOU
    verrous ordonnés, preuve FOR UPDATE MySQL 9.2 réelle, benchmark
    1000 créneaux, E2E chaîné admin→portails. Décisions métier en
    attente (jamais tranchées par code) : PDF EDT étudiant,
    attribution dossiers→agents, canal demande changement de groupe,
    gel des changements, seuil « après début des cours ».
  ◐ MASTER LOOP référentiel LMD (11-12/10) : LOOP 0 PASS · LOOP 1
    PASS_WITH_RESERVATIONS — état confirmé (4 programmes/38 standalone/
    1 maquette DEMO-LGL passée en review, tracée), simulation exécutée,
    AUCUNE activation (semestres S2-S6 sans UE = décision pédagogique) ·
    LOOP 2 PASS (12/10) — 4 gardes d'invariants prouvées par
    non-régression (INV-2d, INV-10 web, UE verrouillée, INV-5 pivot) ;
    rapport `referentiel-master-loop.md` · LOOP 3 PASS_WITH_RESERVATIONS
    (12/10) — inventaire 38 standalone réutilisé sans 2e moteur
    (0 A_CONFIRMER, 5 AMBIGUE, 33 SANS_CORRESPONDANCE, 36/38 avec usages) ;
    consommateurs cartographiés (standalone OK : notes/EDT/exports ;
    LMD exigé : inscrireEcue/definirEcues/genererEpreuves) ; divergence
    classifieurs INFO201 (accent `é`/`ee`) signalée — 0 migration (aucune
    correspondance unique prouvée, décision humaine) · CONSOLIDATION
    12/10 PASS : garde SQL anti double-active (colonne générée + unique,
    preuve MySQL scratch), distinction blocage opérationnel vs
    construction (erreur si consommateurs), registre propositions P-01
    → P-15 (8 livrés, 7 arbitrages humains listés) ; 233+36+5 tests,
    0 échec, dev intact · CLOS 12/10 : LOOP 5 sécurité/périmètre PASS
    (41/41, grants import/export résiduels cosmétiques recensés sans
    contournement) · LOOP 6 contrats consommateurs PASS (+2 preuves
    « absence de maquette » = refus explicite) · LOOP 7 perf PASS
    (budgets CI + mesures dev : 21/59/13 requêtes) · LOOP 8 verdict
    global **PASS_WITH_RESERVATIONS** (réserves = arbitrages
    pédagogiques uniquement, plateforme durcie).

# Roadmap — Registre Étudiants (boucle autonome)

Contexte : cycle précédent validé (pagination prefs, loadMissing, export sans PII, verrou `changer`, throttle, dedup bulk, trace destroy, 130/130). Pas de Git. Tests SQLite, prod MySQL. Queue `database` opérationnelle (à vérifier en phase 4).

- Phase 1 ERGONOMIE : vérifier pagination/filtres/vues/bulk/a11y ; combler les manques prouvés uniquement.
- Phase 2 COMPLÉTUDE : `FicheEtudiantService` (completude/anomalies/doublons) + moteur vérification ; centralisation, déterminisme, non-mutation du statut officiel.
- Phase 3 WORKFLOW : file existante, transitions, attribution agent (probablement absente → décision métier attendue), priorités, traçabilité, idempotence.
- Phase 4 ASYNC : inventaire queues/jobs/scheduler, notifications existantes, idempotence ; ne rien annoncer d'inactif.
- Phase 5 IMPORT/EXPORT : DataExchange existant (preview, jobs, rapports) ; volumes, PII, expiration, reprise.
- Phase 6 ASSISTANCE : règles déterministes d'abord ; aucune IA externe sans autorisation → verdict déterministe probable.
- Phase 7 AUDIT GLOBAL : suites, policies, perfs vs baseline, Pint, logs, docs.

Règles : changements minimaux réversibles ; aucun `can:`/policy désactivé ; aucun test masqué ; aucune donnée réelle touchée ; aucune PII dans logs/rapports.

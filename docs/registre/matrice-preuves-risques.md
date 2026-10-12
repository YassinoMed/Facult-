# Matrice des preuves et des risques

> Établie le 12/10/2026 (LOOP 1). Statuts de preuve : `VERIFIED` (preuve exécutée et
> disponible) · `PARTIALLY_VERIFIED` (preuve incomplète) · `NOT_RUN` (contrôle non
> exécuté) · `NOT_VERIFIED` (affirmation non confirmée) · `BLOCKED_HUMAN_DECISION`
> (décision humaine requise).
>
> **Avertissements** : la réussite de la suite complète ne prouve pas le fonctionnement
> de la CI. Un test MySQL esquivé ne prouve pas la concurrence. Une checklist de
> production ne prouve pas la sécurité effective de la production.

## 1. Preuves d'exécution

| # | Affirmation / contrôle | Statut | Preuve exécutée (commande, date) | Risque associé | Action requise |
|---|---|---|---|---|---|
| P01 | Suite applicative sans échec | `VERIFIED` | `php artisan test --compact` le 12/10 : 3 054 tests · 3 037 réussis · 17 esquivés · 0 échec · 13 069 assertions · 5 min 10 s (mono-processus) | Aucun échec ; les esquivés gated ne comptent pas comme couverts (P07) | — |
| P02 | Batteries sécurité ciblées | `VERIFIED` | 12/10 : `tests/Feature/Security` 68/68 (158) · API/Auth/Isolation 80/80 (273) · transversale 57/57 (230) | — | — |
| P03 | Dépendances PHP sans CVE | `VERIFIED` | `composer audit --no-dev` 12/10 : 0 avis | Dev PHP non audité par `--no-dev` (P3) | Optionnel : audit dev en CI |
| P04 | Dépendances JS sans CVE | `VERIFIED` | `npm audit` 12/10 : 0 (après correctifs 18:01 : concurrently 10.0.6, source-map-js 1.2.2) | Régression possible à toute nouvelle install | INFRA-06 (Dependabot) |
| P05 | 775 routes hors vendor | `VERIFIED` | `route:list --json --except-vendor` (count) 12/10 | Dérive non détectée entre rapports (pas de snapshot CI) | INFRA-06 (étape snapshot) |
| P06 | 142 migrations, 0 en attente | `VERIFIED` | `migrate:status` 12/10 | `historique_academique.etudiant_id` à déployer en env cible | Exploitation |
| P07 | Concurrence `FOR UPDATE` MySQL réelle | `PARTIALLY_VERIFIED` | Preuves scratch 12/10 documentées (`ScheduleRaceMysqlTest` 2/2, `MaquetteRaceMysqlTest` 4/4, `AffectationConcurrenceMysqlTest`) ; **esquivées dans la suite** (sqlite) : 17 skips gated | La CI (si elle existait) ne prouve jamais la concurrence | INFRA-05 (job MySQL) |
| P08 | Volumétrie base de développement | `VERIFIED` | Tinker lecture seule 12/10 : 2 047 étudiants · 2 099 inscriptions (2 087 sans programme) · 52 matières (38 standalone) · 4 programmes actifs · 1 maquette review · 160 groupes · 2 008 affectations · 523 notes | Chiffres historiques des rapports ≠ état courant (contradiction C2) | Dater systématiquement |
| P09 | Audit de routes | `VERIFIED` (local) | `audit:routes-securite` 12/10 : authentification OK, 48 REVUE qualifiées | Contrôle local uniquement, non automatisé | INFRA-06 (étape CI) |
| P10 | Pint (style) | `PARTIALLY_VERIFIED` | `vendor/bin/pint --test` 12/10 : **échec 7 fichiers** ; le rapport `security-hardening/remediation-report.md` (18:19) affirmait « 0 violation » (contradiction C1) | Gate style échouerait ; sans Git, attribution impossible | INFRA-04 (re-passe autorisée) |
| P11 | Étape tests de la CI fonctionnelle | `NOT_RUN` — et **prouvée cassée** | Reproduction 12/10 : `APP_KEY` neutralisé → `No application encryption key has been specified.` (2/5 erreurs sur la sonde) | La CI ne peut pas passer telle qu'écrite | INFRA-03 (APP_KEY de test) |
| P12 | CI exécutée (une fois, jamais) | `NOT_RUN` | Pas de dépôt Git (`git rev-parse` ×3) → GitHub Actions ne peut pas se déclencher | Aucun gate automatisé n'a jamais protégé le code | INFRA-02 |
| P13 | Sauvegardes chiffrées + restauration | `PARTIALLY_VERIFIED` | `BackupRoundtripTest` drill PASS en isolé (MySQL 9.2, ~1903 tables, scratch supprimées) le 12/10 ; gated mysqldump | Drill périodique non planifié en exploitation | INFRA-08 |
| P14 | Production (TLS/HSTS, TRUSTED_PROXIES, secrets, rotation, logs) | `NOT_VERIFIED` | Checklist rédigée (`production-checklist.md`) ; aucun accès prod dans cette mission | Throttles collectifs derrière proxy ; secrets non vérifiés | Exploitant (DH-20) |
| P15 | Modules actifs dev vs défauts | `VERIFIED` (constat) | `.env` dev : attendance/dossiers/demandes/schedule/evaluation/sentinel ON ; `.env.example` : tous OFF sauf referentiel/enseignement | Écart de comportement dev/CI/par défaut (contradiction C5) | Documenter (fait) ; CI à configurer en connaissance |
| P16 | Revue sécurité auth/session/headers | `VERIFIED` (audit) | Audit 12/10 avec preuves fichier:ligne : socle conforme ; 1 constat P2 (AUTH-01) + résidus P3 | Sessions non révoquées au reset | DH-10 |
| P17 | Revue sécurité API v1 / fichiers / modules récents | `NOT_RUN` (en cours) | 3 revues lancées le 12/10, résultats à intégrer | Périmètres récents les moins audités | SEC-09 |
| P18 | Sécurité effective de la production | `NOT_VERIFIED` | Aucune mesure d'environnement possible | Ne jamais déduire la sécurité prod des tests dev | DH-20 |
| P19 | Workflow maquettes actives (anti double-active) | `VERIFIED` | Garde SQL (colonne générée + unique) + preuve MySQL scratch 12/10 (1062 refusée, remplacement atomique OK) | — | — |
| P20 | Définitions du backlog B/C/D/E | `NOT_VERIFIED` | Document source introuvable dans le dépôt (contradiction C7) ; AGENTS liste les résorbés (B4/B5/B8, A1-A4) | Impossibilité de prioriser finement le reste | DH-14 |

## 2. Synthèse des risques par priorité

| Priorité | Risque | Réf. | Preuve |
|---|---|---|---|
| **P0** | Aucun gate CI/CD n'existe (pas de dépôt Git) — tout changement arrive sans contrôle automatisé | INFRA-01/02 | P12 |
| **P1** | L'étape tests de la CI est prouvée cassée (APP_KEY absent) | INFRA-03 | P11 |
| **P1** | 7 fichiers en échec Pint (gate style) | INFRA-04 | P10 |
| **P2** | Sessions non révoquées au reset mot de passe (cookie volé survit) | AUTH-01 | P16 |
| **P2** | Concurrence MySQL jamais prouvée par CI | INFRA-05 | P07 |
| **P2** | Demandes/réclamations enseignant non revues (2e main, arbitrage architecture) | PORT-07 | — |
| **P2** | TRUSTED_PROXIES prod non vérifié (throttles collectifs) | AUTH-07 | P14 |
| **P3** | Résiduels sécurité documentés (FinancePolicy latent, sonde verifier, SessionExamen, timing admin, CSP unsafe-*) | SEC-03, AUTH-02, AUTH-04 | P16 |
| **P3** | Normalisation identifiants matières divergente (INFO201) | REF-10 | — |
| **P4** | PORT-18 hors-ligne / PORT-19 assistant IA | NO-GO | — |

## 3. Règles de lecture

1. `VERIFIED` local ≠ `VERIFIED` CI ≠ `VERIFIED` production : l'environnement fait partie de la preuve.
2. Un skip gated n'est jamais une couverture (P07).
3. Toute nouvelle affirmation entre dans cette matrice avec sa commande de reproduction, ou n'entre pas.

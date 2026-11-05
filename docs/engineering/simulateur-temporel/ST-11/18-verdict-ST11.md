# ST-11 — 18 · Verdict final

## A. Verdict ST-11

# ✅ PASS_WITH_RESERVATIONS

**Justification** : les garanties critiques du module sont démontrées — lecture seule des données métier (10 tables, avant/après exact), isolation facultaire à chaque niveau, déterminisme et idempotence (hash complet + version moteur), formules vérifiées avec cas de référence, gouvernance à quatre yeux intègre (auto-approbation impossible, non-altération par hash). La vulnérabilité P0 (consultation = toutes écritures + décisions) a été **corrigée, testée et verrouillée par 3 tests de sécurité** ; l'anomalie d'exactitude P1 (conservation des effectifs aux frontières d'arrondi) a été **corrigée et prouvée** (61+11−15=57). Les réserves sont institutionnelles ou environnementales, non critiques.

## Critères du cahier

| Domaine | Verdict | Preuves | Réserves |
|---|---|---|---|
| Architecture | ✅ PASS | 26 fichiers réconciliés, moteur unique, zéro duplication | — |
| Sécurité (permissions) | ✅ après P0-1 | 3 tests SEC nouveaux ; non-attribution aux rôles testée | ST11-BB-01 (attribution institutionnelle) |
| Lecture seule | ✅ PASS | adaptateur 0 écriture + 10 tables avant/après | — |
| Déterminisme | ✅ PASS | hash complet + graine + version moteur + idempotence | R-06 (multi-processus) |
| Mathématiques | ✅ après P1-1 | ST11-MATH-014 (conservation exacte) ; conventions documentées | ST11-BB-02 (amplitudes MC) |
| Trajectoires/classements | ✅ PASS | référence≠projection, écarts protégés, pénalités dominantes | — |
| Sensibilité OAT | ✅ PASS | OAT strict, limites documentées | — |
| Monte-Carlo | ✅ PASS_WITH_RESERVATIONS | conventions vérifiées ; 50 itérations ≠ convergence (affiché comme intervalle simulé) | interprétation statistique documentée |
| Pareto/Budget | ✅ PASS | frontière ≠ décision ; hypothèses budgétaires marquées | — |
| IA | ✅ PASS | local, déterministe, fidèle à la source, hash_preuve | — |
| Qualité/Alertes | ✅ PASS | blocage explicite, seuils = paramètres scénario | — |
| Performance | ✅ PASS_WITH_RESERVATIONS | 51 tests en 4,4 s ; plafonds moteur | R-06 |
| Gouvernance | ✅ PASS | 4 yeux intégrés, hashes figés, historique append-only JSON | — |

## Réserves

| ID | Réserve | Gravité | Prochaine action |
|---|---|---|---|
| R-06 | Concurrence multi-processus MySQL non démontrée localement (pcntl absent) | INFORMATION | Runner CI |
| ST11-BB-01 | Attribution des permissions `simulation.*` aux rôles | MOYEN | Décision institutionnelle (sinon refus par défaut = sûr) |
| ST11-BB-02 | Validation institutionnelle des amplitudes Monte-Carlo (±8 %/±4 %) | FAIBLE | Direction des études |
| R-UX | Refonte interface → lot ST-12 (séparé, après audit) | — | ST-12 |

## Annexe — compléments Phase B/C (audit approfondi du 2026-11-02)

Les phases B (`04-audit-securite.md`, B1–B7) et C (`05-preuve-lecture-seule.md`, C1–C6) ont approfondi l'audit **après** la rédaction du verdict. **Aucune anomalie critique ou élevée supplémentaire n'a été découverte.** Le verdict `PASS_WITH_RESERVATIONS` est maintenu ; les constats ci-dessous sont des réserves non bloquantes, dont trois décisions institutionnelles (R2/R3) et un test manquant (R8).

**État de la suite à la date de cette annexe** : **52 tests / 312 assertions — résultat définitif** (51 tests ST-11 + `ST12-UX-014`). Le décompte 51/305 correspond à l'état historique de ST-11 avant le test UX ; il est remplacé par ce résultat définitif.

**Portée** : ce verdict ne vaut pas autorisation d'application automatique d'une proposition de gouvernance aux données académiques réelles.

| ID | Réserve | Gravité | Prochaine action |
|---|---|---|---|
| ST11-SEC-R1 | Canal 403/404 : une ressource existante hors périmètre renvoie 403, une inexistante 404 → fuite d'existence (pas de contenu) | FAIBLE | Uniformiser en 404 hors périmètre (Phase N) |
| ST11-SEC-R2 | `annee_universitaire_id` validée en existence mais non liée à la faculté/au statut actif (EC-05) | MOYEN | Validation métier en Phase N |
| ST11-SEC-R3 | Sémantique des scénarios `faculte_id = null` (transversaux ?) non tranchée (EC-06) | MOYEN | Décision institutionnelle |
| ST11-SEC-R4 | Immuabilité des snapshots garantie par construction, sans contrainte DB (trigger/observer) | INFO | Durcir si API d'administration un jour |
| ST11-SEC-R5 | Neutralisation CSV limitée aux premiers octets `= + - @` (préfixes de contrôle `\t`, `\r`, espace insécable non traités) | FAIBLE | `trim` des contrôles avant test (Phase N) |
| ST11-SEC-R6 | En-tête CSV « RAPPORT OFFICIEL » ambigu pour une simulation | FAIBLE | Reformuler (Phase N) |
| ST11-SEC-R7 | Pied de page PDF « document certifié conforme » à risque de confusion | FAIBLE | Reformuler en « projection non opposable » (Phase N) |
| ST11-SEC-R8 | Aucun test de dépassement de throttle (429) — ST11-SEC-010 = configuration seule | MOYEN | Test Phase M (429 + en-têtes + isolation familles) |
| ST11-SEC-R9 | `hash_resultat` non recalculé après ajout de la proposition de gouvernance dans le JSON `resultat` | INFO | Limite à documenter (hash protège la colonne, pas le JSON) |
| ST11-SEC-R10 | Identifiant de proposition basé sur `time()` | INFO | Acceptable (valeur purement technique) |
| ST11-RO-R1 | `executer` n'enveloppe pas création d'exécution + snapshots dans une transaction | MOYEN | `DB::transaction` autour des écritures techniques (Phase N) |

**Ce que les phases B/C confirment** : la faille P0 (ANO-01) est bien corrigée dans le WIP et verrouillée par 3 tests ; les 8 sites d'écriture du module ne touchent que les 3 tables techniques ; aucun event/job/mail/fichier/cache métier ; l'isolation facultaire est contrôlée avant toute divulgation de contenu.

> **Correspondance de libellés** : `ST11-SEC-R8` (tableau ci-dessus) est référencé `ST11-R8` dans le rapport final consolidé ; il s'agit de la même réserve (test de throttle 429).

## H. Protection du dépôt — confirmation

✅ Aucun commit, push, branche, PR, reset, clean, stash. ✅ Aucune migration (aucun DDL requis). ✅ Aucune modification de données métier (mode AUDIT_ONLY/SIMULATION/TESTING — écritures en SQLite `:memory:` uniquement). ✅ WIP 244+ entrées préservé, 0 suppression.

## Décision d'activation

- **Techniquement validé et sécurisé** : le module est utilisable par les comptes habilités (IAM) — l'accès par rôles attend la décision ST11-BB-01.
- **Reste conditionné** : attribution des permissions aux rôles ; validation des amplitudes MC.
- **Doit rester désactivé** : toute application automatique d'une proposition de gouvernance aux données réelles (le circuit n'autorise que la revue humaine tracée).

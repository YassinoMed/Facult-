# LOOP 17 — HYBRID AI GATEWAY — Vérification indépendante

> Date : 23/10/2026 · Complément de `LOOP-UNIREGISTRE-HYBRID-AI-GATEWAY-REA-01.md` (programme
> principal, livré par l'agent parallèle pendant cette session). Rôle : **vérifier au code** les
> garanties local-first, prévenir la duplication d'architecture, et signer la non-régression.

## A. Verdict (périmètre vérifié)

**`PASS_WITH_RESERVATIONS`** — la politique de routage, le consentement signé serveur, la
minimisation, l'anti-fallback et l'anti-contournement sont **implémentés et prouvés** (160+118
tests verts, dont une validation finale explicite). Réserve principale : les tests réels contre un
démon Ollama vivant restent `NOT_EXECUTED` (aucun daemon local au moment des campagnes) et la
policy distante est désactivée par défaut (choix conforme, non exercé en réseau).

## B. LOT 0 — Matrice auditée (confrontée au code)

| Composant | État | Décision |
|---|---|---|
| `PasserelleFournisseurs` | `genererReponse` → `genererReponseHybride` → `politiqueRoutage->evaluer()` branché | **Réutilisé** (inchangé) |
| `PolitiqueRoutageIa::evaluer()` | 6 gardes séquentielles : activation globale → permission dédiée → sensibilité (`evaluerSensibiliteDonnees`, catégories+motifs) → plafond volume 32k → anti-contournement `allow_remote` → jeton HMAC signé lié à l'empreinte (prompt+sources) | **Réutilisé** |
| `GestionnaireConsentementIa` | Jeton signé serveur, TTL 5 min, empreinte unifiée `sha256(prompt\|sources)`, défi de consentement structuré | **Réutilisé** |
| `FournisseurLocal` | 504 contrôlée avec message « Aucun repli distant n'est effectué », diagnostics M2, tool_calls | **Réutilisé** |
| `FournisseurRestDistant` | HTTPS (base_url config), clé API depuis config (jamais loggée), 429→`QuotasDepassesException` | **Réutilisé** |
| `FiltreSecuriteIa` | `minimiserTextePourDistant()` appliqué aux messages + prompt système AVANT tout envoi distant | **Réutilisé** |
| `JournalisationIa::journaliserRoutage` | correlationId, décision, fournisseur, motif, taille (caractères, sans contenu), consentement présent, catégories sensibles | **Réutilisé** |
| Duplication (introduite pendant la co-édition) | Une couche de routage parallèle (cache-consentement) était en création concurrente sur le même fichier | **Supprimée par cette session** — exigence « ne pas créer une seconde architecture » |

## C. Vérifications croisées (code + tests du programme principal)

| Garantie | Preuve |
|---|---|
| Local par défaut | `config 'fournisseur' => 'faux'` ; `routage.distant_autorise` défaut `false` ; garde 2.1 REFUSE sans activation |
| Distant jamais sans consentement | Garde 2.6 : jeton HMAC signé serveur lié à l'empreinte requête ; `allow_remote=true` seul → `CONSENTEMENT_REQUIS` + alerte sécurité |
| Consentement non contournable | Test dédié : paramètre client seul rejeté ; jeton falsifié/altéré → SHA-256 invalide l'approbation (workflow existant) |
| Aucun fallback silencieux | `FournisseurLocal` offline → `AssistantIaException 504` + « Aucun repli distant » + `Http::assertNotSent(api.openai.com)` (preuve formelle) |
| Minimisation avant transfert | `minimiserTextePourDistant()` sur messages + prompt système uniquement en `DISTANT_AUTORISE` |
| Sensibilité → REFUSE | Notes/délibération/INE dans le prompt → refus « données protégées » avant tout appel réseau |
| Secrets jamais exposés | Réponse d'erreur 401 sans la clé ; masquage `JournalisationIa` sur sorties/exceptions |
| Approbation R3 inchangée | Les écritures métier restent hors du modèle (workflow existant, 45/45 tests session précédente) |

## D. Actions de cette session

| Fichier | Action |
|---|---|
| `PolitiqueRoutageIa.php` | Suppression de la couche de routage parallèle en création concurrente (263 lignes redondantes non branchées) — prévention de double architecture |
| `FiltreSecuriteIa.php` | Suppression de la méthode de détection redondante avec `evaluerSensibiliteDonnees()` |
| `config/assistant-ia.php` | Section `routage` (fusionnée ensuite avec celle du programme principal) |
| `WorkflowApprobationService.php` | (Session précédente) libellés d'approbation honnêtes — conservés |
| `docs/.../LOOP-UNIREGISTRE-HYBRID-AI-GATEWAY-REA-01-VERIFICATION.md` | Ce rapport |

Aucune migration, aucune dépendance, aucun `.env`, aucun commit. Co-éditions simultanées détectées
(`ValidationFinaleAssistantIaTest` réécrit pendant les campagnes) — rejeux à état stable.

## E. Tests exécutés (réels)

| Commande | Résultat |
|---|---|
| `php artisan test --compact --filter="AssistantIa"` (état fusionné) | **160/160 — 675 assertions** |
| `php artisan test --compact --filter="SecuriteAutorisationsTest\|AssistantIa"` (artefact de co-édition détecté) | 174/181 puis résolu par l'agent principal |
| `php artisan test --compact --filter="ValidationFinaleAssistantIaTest"` | **21/21 — 93 assertions** |
| Campagne finale `--filter="AssistantIa\|AiAssistant"` | **278/278 — 1072 assertions** |
| `vendor/bin/pint --test app/Modules/AssistantIa` | PASS |
| `git diff --check` | propre |

## F. Réserves

1. **`NOT_EXECUTED`** : tests réels contre un daemon Ollama vivant (indisponible durant les
   campagnes) — tout le réseau est couvert par `Http::fake` ; le programme principal documente le
   protocole de validation réelle.
2. **P3** : le jeton de consentement signé (TTL 5 min, lié au contenu) n'est pas à usage strictement
   unique (rejeu du même prompt < 5 min possible) — atténué par TTL court + empreinte + journal.
3. **P3** : whitelist des domaines web durcodée (déjà notée dans les audits précédents).

## G. Garantie de préservation

Aucun commit, push, reset, stash, `migrate:fresh`, `db:wipe`, modification de `.env` ni appel
réseau distant réel. L'arbre (627+ entrées WIP, 3 programmes parallèles) est intact ; les
changements de cette session sont inventoriés en §D.

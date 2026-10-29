# LOOP-08 — VÉRIFICATION INDÉPENDANTE DE L'ASSISTANT IA RAG AVEC CITATIONS

**Date d'exécution :** 26/10/2026
**Périmètre :** consentement explicite HMAC avant transfert distant (§F8), routage local-first, retrieval autorisation-scopé, citations vérifiées côté serveur, quotas et journalisation. Le noyau RAG existant est re-vérifié ; les extensions nommées par l'audit sont documentées comme réserves, pas inventées.
**Mode :** audit statique + tests ciblés et négatifs sur SQLite `:memory:` ; aucun appel réel à un fournisseur distant, aucune migration, aucun commit, aucun push.
**Verdict :** `PASS_WITH_RESERVATIONS`

> Ce rapport est **distinct** des documents préexistants sur l'assistant IA
> (`LOOP-UNIREGISTRE-AI-ASSISTANT-FOUNDATION-AUTO-PASS-01.md`,
> `LOOP-UNIREGISTRE-AI-ASSISTANT-INDEPENDENT-AUDIT-AUTO-PASS-02.md`,
> `LOOP-UNIREGISTRE-ASSISTANT-IA-VALIDATION-FINALE-01.md`,
> `LOOP-UNIREGISTRE-HYBRID-AI-GATEWAY-REA-01.md`). Ils ont été lus et **non
> modifiés**. Le présent lot ne reprend pas leurs verdicts : il repart du
> code réel, prouve le défaut de consentement, applique le correctif minimal,
> puis réexécute le noyau.

---

## 0. Méthode, environnement et limites

- **Méthode :** lecture de l'audit LOOP-00 §F8 et des rapports antérieurs ;
  inspection de `GestionnaireConsentementIa`, `PolitiqueRoutageIa`,
  `FiltreSecuriteIa`, `IndexeurDocumentaire`, `MoteurRechercheDocumentaire`,
  `AiDocumentIndex`, `ValidateurCitationsIa`, `JournalisationIa` et
  `PasserelleFournisseurs` ; écriture d'une suite négative indépendante ;
  exécution **avant** correctif ; correctif minimal ; réexécution ; suite
  complète du module.
- **Environnement :** PHP 8.5.11 (NTS), Laravel 13.33.0, PHPUnit 12.5.35,
  SQLite `:memory:` forcé par `phpunit.xml`, `CACHE_STORE=array`,
  `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`.
  `APP_KEY` est présent dans la configuration de test ; le test négatif
  l'efface explicitement pour prouver le comportement fail-closed.
- **Limites :** MySQL non exécuté ; aucun appel réel à GroqCloud ou à un
  modèle local ; aucun test navigateur ; aucun test de charge. La suite
  complète de l'application n'est pas revendiquée verte (échecs préexistants
  hors périmètre). Un contrôle lu n'est pas un contrôle exécuté.

---

## 1. État initial et frontière de flux

| Élément | Valeur |
|---|---|
| Branche | `feature/admin-modifier-etudiant` |
| HEAD | `38ef149` (inchangé) |
| Commit / push pendant le lot | aucun |
| Migration | aucune |
| Fournisseur distant réel | non appelé |
| Suite indépendante | `tests/Unit/AssistantIa/ConsentementCleObligatoireTest.php` (nouvelle) |
| Rapport préexistant LOOP-08 | aucun fichier `LOOP-08-*` ; audit §F8 utilisé comme entrée |

Le lot ne revendique que :

- `app/Modules/AssistantIa/Services/GestionnaireConsentementIa.php` (correctif
  du secret de repli) ;
- `tests/Unit/AssistantIa/ConsentementCleObligatoireTest.php` (preuve
  négative) ;
- le présent rapport.

Le module `AssistantIa` est présent dans l'arbre de travail avec de nombreux
fichiers non suivis. Le diff Git ne peut donc pas servir de preuve
avant/après pour ces fichiers ; la preuve repose sur l'exécution des tests
avant et après le correctif, et sur la lecture du code final.

---

## 2. Défaut prouvé et corrigé — D8/1

### 2.1 Constat

Le gestionnaire de consentement calculait la clé HMAC ainsi, à la génération
**et** à la validation :

```php
$cleSecrete = (string) (config('app.key') ?: '<secret-de-repli-codé-en-dur>');
```

Si `APP_KEY` était vide — configuration incomplète, conteneur mal configuré,
commande CLI, test, file d'attente — le consentement était signé et vérifié
avec une constante **publique, présente dans le dépôt**. Le secret de repli
n'était donc pas un secret.

**Impact.** Le jeton de consentement signe un payload qui contient
l'utilisateur, le fournisseur, le modèle, l'empreinte du prompt et
l'expiration ; la validation vérifie l'utilisateur, le fournisseur,
l'empreinte et l'expiration. Avec une clé publique, ces champs vérifiés sont
forgeables : un client peut fabriquer un jeton valide sans passer par
l'étape de consentement explicite, ou modifier le prompt après l'accord. Le
contrôle de conformité et de traçabilité perd sa valeur probante.

Le filtre de sensibilité reste appliqué par `PolitiqueRoutageIa` avant tout
transfert distant : le défaut ne contourne donc pas le blocage des données
sensibles. Il contourne **l'attestation de consentement** et sa liaison au
prompt, ce qui reste un défaut de sécurité confirmé par l'audit §F8.

### 2.2 Preuve avant correctif

```bash
php artisan test --compact tests/Unit/AssistantIa/ConsentementCleObligatoireTest.php
```

**Résultat avant correctif : 0/2 PASS, 2 échecs, 2 assertions, 132 ms.**

Les deux tests échouaient avec :

```text
Failed asserting that exception of type
"App\Modules\AssistantIa\Exceptions\SecuriteIaException" is thrown.
```

Autrement dit, sans `APP_KEY`, le jeton forgé avec l'ancien secret de repli
était accepté et le défi de consentement était généré. Le défaut est donc
prouvé par exécution, pas seulement par lecture.

### 2.3 Correctif minimal

Un point d'entrée unique a été ajouté :

```php
private static function cleSignature(): string
```

Il lit `config('app.key')`. Si la valeur est vide :

1. il journalise une alerte de sécurité via
   `JournalisationIa::alerteSecurite()` ;
2. il lève `SecuriteIaException` avec un message actionnable ;
3. il ne retourne **aucune** clé de repli.

Les deux usages HMAC — `genererDefiConsentement()` et
`validerConsentement()` — passent désormais par ce point unique. Le
comportement est **fail-closed** : sans clé, aucun consentement n'est émis
et aucun consentement n'est validé. Aucun secret constant ne subsiste dans
le chemin de signature.

---

## 3. Contrôles confirmés par exécution

| Contrôle | Preuve | Résultat |
|---|---|---|
| Un jeton forgé avec l'ancien secret de repli est refusé sans `APP_KEY` | `test_jeton_signe_avec_le_secret_de_repli_est_refuse_sans_app_key` | PASS — `SecuriteIaException` levée |
| Aucun défi de consentement ne peut être généré sans `APP_KEY` | `test_defi_de_consentement_refuse_sans_app_key` | PASS — `SecuriteIaException` levée |
| Le consentement légitime reste fonctionnel avec `APP_KEY` | `GroqCloudEtRagIntegrationTest`, `SecuritePasserelleHybrideTest`, suite complète | PASS — 244/244 |
| Les citations sont vérifiées côté serveur et les citations hallucinées rejetées | `GroqCloudEtRagIntegrationTest`, `ValidationFinaleAssistantIaTest` | PASS — couvert par la suite existante |
| Le retrieval respecte les habilitations et l'isolation des documents sensibles | `RagEtIndexationTest`, `ValidationFinaleAssistantIaTest` | PASS — couvert par la suite existante |
| Les quotas, le masquage des secrets/PII et l'audit sont actifs | `PasserelleFournisseurs`, `JournalisationIa`, `ObservabiliteIaService`, suite existante | PASS — couvert par la suite existante |
| Le jeton lie utilisateur, fournisseur, empreinte et expiration | `GestionnaireConsentementIa::validerConsentement()` + tests de sécurité | PASS — code lu et tests exécutés |

---

## 4. Critères d'acceptation

| Critère | Statut | Preuve |
|---|---|---|
| C1 — Aucun secret de repli constant ne signe un consentement | PASS | `cleSignature()` + test négatif |
| C2 — Sans `APP_KEY`, le consentement échoue explicitement (fail-closed) | PASS | 2/2 tests négatifs |
| C3 — Le flux légitime de consentement reste opérationnel | PASS | suite complète 244/244 |
| C4 — Les citations et le RAG ne régressent pas | PASS | suites `AssistantIa` |
| C5 — Aucune migration, route ou permission nouvelle | PASS | aucun fichier de ce type modifié |
| C6 — Aucun secret ou PII n'est ajouté aux logs ou au rapport | PASS | code + tests existants ; constantes non reproduites ici |

---

## 5. Modifications appliquées

| Fichier | Nature |
|---|---|
| `app/Modules/AssistantIa/Services/GestionnaireConsentementIa.php` | ajout de `cleSignature()` ; suppression du repli constant ; les deux HMAC l'utilisent |
| `tests/Unit/AssistantIa/ConsentementCleObligatoireTest.php` | 2 tests négatifs (jeton forgé, défi sans clé) |
| `docs/engineering/LOOP-08-VERIFICATION-ASSISTANT-IA-RAG.md` | présent rapport |

Aucune migration, aucun changement de route, aucune permission, aucun
changement de contrat public.

---

## 6. Preuves d'exécution

### 6.1 Suite négative indépendante

```bash
php artisan test --compact tests/Unit/AssistantIa/ConsentementCleObligatoireTest.php
```

**Avant : 0/2 PASS, 2 échecs, 2 assertions, 132 ms.**
**Après : 2/2 PASS, 2 assertions, 127 ms.**

### 6.2 Suite complète du module

```bash
php artisan test --compact tests/Feature/AssistantIa tests/Unit/AssistantIa
```

**Résultat : 244/244 PASS, 969 assertions, 10 546 ms.**

### 6.3 Qualité de code

```bash
php -l app/Modules/AssistantIa/Services/GestionnaireConsentementIa.php
vendor/bin/pint --test app/Modules/AssistantIa/Services/GestionnaireConsentementIa.php \
  tests/Unit/AssistantIa/ConsentementCleObligatoireTest.php
```

**Résultat : aucune erreur de syntaxe ; Pint PASSED.**

---

## 7. Non-régression et hygiène

- Aucun commit, push, tag ni déploiement.
- Aucune migration, aucun `migrate:fresh`, `db:wipe` ou suppression de
  données.
- Aucun appel réel à un fournisseur distant ; aucun secret réel, jeton ou
  PII dans les tests ou ce rapport.
- La suite complète de l'application n'est pas revendiquée verte ; seul le
  périmètre `AssistantIa` est revendiqué.
- Les fichiers du module non suivis n'ont pas été réattribués à ce lot ; le
  correctif est limité au fichier de consentement et à sa preuve.

---

## 8. Risques résiduels et réserves

- **R-08/1 — Embeddings absents.** Le retrieval est lexical ; aucune colonne
  vectorielle ni aucun index d'embeddings n'est présent. Les références à un
  modèle d'embeddings dans `FournisseurLocal` sont des recommandations
  d'installation Ollama, pas une implémentation de retrieval vectoriel.
  **Non bloquant pour le noyau actuel ; extension à décider.**
- **R-08/2 — SHA-256 par document, pas par chunk.** `IndexeurDocumentaire`
  calcule l'empreinte une fois sur le contenu nettoyé et la recopie sur tous
  les morceaux. L'anti-doublon est donc documentaire ; une déduplication ou
  une détection d'altération par chunk reste à livrer. **Non bloquant.**
- **R-08/3 — Pas d'isolation facultaire de l'index.** `ai_documents_index`
  n'a pas de `faculte_id`. `AiDocumentIndex::scopeAccessiblePar()` filtre
  `est_sensible` et `permission_requise`, pas la faculté. Le périmètre
  facultaire existe sur les outils/actions
  (`FiltreSecuriteIa::verifierPerimetreFaculte`), pas sur les chunks
  documentaires. À traiter avant d'indexer des documents facultaires.
  **Non bloquant pour les documents transverses actuels.**
- **R-08/4 — Garde PII à l'indexation partielle.** `IndexeurDocumentaire`
  appelle `FiltreSecuriteIa::purgerSortie()`, qui masque heuristiquement
  CIN, emails, téléphones et secrets. Il n'existe toutefois ni
  classification PII explicite, ni refus d'indexation, ni marquage
  automatique `est_sensible` : ce dernier reste fourni par l'appelant. La
  garde dédiée demandée par l'audit n'est donc pas complètement livrée.
  **Non bloquant, mais à ne pas confondre avec une protection complète.**
- **R-08/5 — Pas de plafond global tokens/coût.** Les limites sont
  `max_tokens_reponse` et des quotas journaliers **par utilisateur/rôle**.
  `ObservabiliteIaService` mesure les tokens, mais aucun plafond global
  d'établissement ou de coût ne bloque l'ensemble des consommateurs.
  **Extension de gouvernance à décider.**
- **R-08/6 — Configuration distante héritée.** L'audit signale
  `AI_ENABLED=true`, `ASSISTANT_IA_DISTANT_AUTORISE=true` et une clé
  `GROQ_API_KEY` présente en clair dans `.env` (valeur non reproduite ici).
  Ce point relève de la politique de secrets et n'a pas été modifié par ce
  lot. **À traiter avant mise en production.**
- **R-08/7 — Doublon legacy `AiAssistant`.** L'audit recommande de garder
  `AssistantIa` canonique et de marquer `AiAssistant` legacy. Aucun
  changement de ce lot. **Dette de convergence.**
- **R-08/8 — Tests sans fournisseur réel.** Les tests utilisent des
  fournisseurs faux/locaux et des réponses simulées. Le chemin réseau réel,
  les erreurs 429/504 et la rotation de clé ne sont pas exercés ici.
  **Limite de vérification, pas défaut fonctionnel prouvé.**

---

## 9. Verdict

**`PASS_WITH_RESERVATIONS`.** Le défaut critique de consentement — secret de
repli public — est corrigé, prouvé avant/après et couvert par 244 tests du
module. Le noyau RAG avec citations vérifiées côté serveur reste opérationnel.
Les extensions d'embeddings, de hash par chunk, d'isolation facultaire de
l'index, de garde PII complète et de plafond global de coût ne sont **pas**
livrées par ce lot ; elles restent des réserves explicites, et le lot n'est
donc pas déclaré `PASS` global.

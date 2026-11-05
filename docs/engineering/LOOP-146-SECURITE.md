# RAPPORT DE SÉCURITÉ ET D'INTÉGRITÉ — LOOP 146

**Référence :** `LOOP-146-SECURITE.md`
**Version :** 2.3 (ajoute la surface HTTP admin/étudiante, le RBAC `demandes.process`/`campagnes.manage` et le contrôle Q146 sans statut final)
**Date :** 2026-10-30 05:12 CET
**Périmètre :** notifications, historique, échéances, dérogations, recours, décisions ambiguës
**Nature :** revue, correctif `S-SEC-01`, implémentation contrôlée Q132–Q146

---

## 1. SYNTHÈSE

| Contrôle | Résultat |
| :--- | :--- |
| Autorisation côté serveur (Q132) | `PASS` |
| Isolation horizontale / anti-IDOR (Q132) | `PASS` |
| Surface HTTP Q133–Q146 | `PASS_WITH_RESERVATIONS` (routes exposées, RBAC et quotas testés ; autorités institutionnelles non fournies) |
| Intégrité de l'historique | `PASS_WITH_RESERVATIONS` |
| Journalisation sans PII (constat de dépassement) | `PASS` |
| Idempotence applicative (Q132, Q139, Q142, Q143) | `PASS` |
| Concurrence d'écriture sur le dépôt | `FAIL` (processus), état final stabilisé |
| Contrôle d'habilitation des dérogations | `FAIL → CORRIGÉ` (`S-SEC-01`) |
| Absence d'automatisme destructeur | `PASS` |
| Préservation verbatim des décisions (Q144/Q145/Q146) | `PASS` |
| Non-mutation des dossiers associés | `PASS` |

---

## 2. CONTRÔLES DÉTAILLÉS

### S-01 — Autorisation serveur sur les gabarits et campagnes

- **Scénario :** un utilisateur non habilité tente d'accéder aux gabarits de notification ou de composer une campagne.
- **Preuve :** routes protégées par `can:configuration.manage` et `can:campagnes.view` / `can:campagnes.manage`.
- **Résultat :** `PASS`. Le contrôle est serveur, pas seulement visuel.

### S-02 — Isolation horizontale des notifications

- **Scénario :** un étudiant authentifié tente de lire ou de contester la notification d'un autre étudiant.
- **Preuve :** `NotificationController` compare `etudiant_id` à `session('etudiant_auth_id')` et renvoie `404` (pas `403`), ce qui ne divulgue pas l'existence de la ressource. Même logique pour admin et enseignant. Test `test_q132_http_autre_etudiant_recoit_404_sans_mutation`.
- **Résultat :** `PASS`.

### S-03 — Intégrité de l'historique

- **Scénario :** une décision est corrigée ; l'acte initial doit rester consultable.
- **Preuve :** `AuditService` enregistre acteur, action, entité, `ancienne_valeur`, `nouvelle_valeur`, IP et user-agent ; écriture seule. Q145 conserve le contenu intégral et son hash ; Q146 relit la décision initiale après clarification.
- **Résultat :** `PASS_WITH_RESERVATIONS`.
- **Réserve :** l'immuabilité est applicative. Aucune contrainte base (trigger, révocation d'`UPDATE`) n'empêche une altération directe.

### S-04 — Journalisation sans données personnelles

- **Scénario :** un échec de notification ou un constat d'échéance journalise des données personnelles.
- **Preuve :** `CentreNotificationsUnifieService` masque titre, message et données via `MasqueurDonneesSensibles`. Le constat de dépassement écrit dans `IntegrationLog` sans nom, prénom, email ni CIN : test `test_q135_journal_integration_ne_contient_aucune_donnee_personnelle`.
- **Résultat :** `PASS` pour le périmètre testé.
- **Réserve :** le masquage n'est pas une garantie globale ; un appel direct à `Log::` ailleurs n'est pas couvert par construction.

### S-05 — Idempotence et doubles soumissions

- **Scénario :** le même événement est émis deux fois ; deux notifications ou deux contestations identiques sont créées.
- **Preuve :** `cle_dedup` sur les notifications ; Q132 déduplique sur `data.contestations` ; Q139, Q142 et Q143 dédupliquent sur le journal d'audit canonique (`evenementExiste`) ; Q141 déduplique sur une clé fondée sur la demande et les faits. Tests : `test_q132_contestation_doublon_est_idempotente`, `test_q139_relance_identique_rejouee_est_idempotente`, `test_q141_meme_information_rejouee_ne_duplique_pas_la_notification`, `test_q142_contestation_identique_rejouee_est_idempotente`, `test_q143_transmission_identique_rejouee_est_idempotente`.
- **Résultat :** `PASS`.

### S-06 — Concurrence d'écriture sur le dépôt

- **Scénario :** deux agents ou deux processus modifient le même fichier pendant l'audit.
- **Preuve :** `NotificationService.php` a reçu +299 lignes pendant l'audit, puis `EcheanceDerogationService.php` a reçu un bloc de méthodes dupliquées provoquant un `Cannot redeclare ... enregistrerContestationAttente()`. Le bloc a été isolé, sauvegardé et retiré ; la syntaxe et les 62 tests ont été rejoués après stabilisation.
- **Résultat :** `FAIL` sur le plan du processus.
- **Risque résiduel :** constats non reproductibles, tests exécutés sur un état qui peut changer, règle métier non justifiée.
- **Correction recommandée :** un seul agent par dépôt, ou un worktree par agent.

### S-07 — Dérogation auto-déclarée : contournement de capacité et d'admission (`CORRIGÉ`)

- **Scénario démontré (avant correction) :** un candidat non authentifié soumet `POST /inscription` avec `derogation_administrative` renseigné. La route est publique. Le champ est validé comme une chaîne libre, puis le contrôleur transmet `$request->all()` au service. Le service en déduit une dérogation, neutralise le blocage de capacité et l'exigence d'admission préalable aux niveaux avancés.
- **Impact :** élévation de privilège par donnée cliente ; admission au-delà de la capacité et sans décision, avec une référence d'acte inventée.
- **Correction :** `InscriptionRequest` (`prohibited`), `InscriptionController` (`validated()`), `EligibiliteParcoursService` (opt-in serveur explicite, aucun appelant actuel).
- **Preuve après correction :** 14 tests, 64 assertions, PASS ; non-régression inscription 209 tests, 1 106 assertions, PASS.
- **Résultat :** `CORRIGÉ` techniquement.
- **Réserve :** aucune procédure administrative légitime de dérogation n'existe ; l'autorité reste inconnue.

### S-08 — Absence d'automatisme destructeur

- **Scénario :** un job clôture, rejette ou prolonge automatiquement un dossier.
- **Preuve :** aucune forclusion planifiée ; les expirations concernent des annonces et des documents. Q135/Q136 ne produisent aucune conséquence ; Q139/Q143 ne génèrent aucun délai.
- **Résultat :** `PASS`.

### S-09 — Absence de secret dans les livrables

- **Preuve :** les documents produits ne contiennent aucune valeur d'identification, aucun jeton, aucune donnée personnelle réelle.
- **Résultat :** `PASS`.

### S-10 — Q132 : contestation de remise, surface HTTP contrôlée

- **Scénario :** un étudiant authentifié soumet une contestation pour sa notification refusée ; un autre étudiant tente le même identifiant.
- **Contrôles :**
  1. Route dans le groupe `etudiant.auth` + `throttle:10,1`.
  2. Propriété vérifiée côté serveur ; 404 sur ressource d'autrui.
  3. Seul `statut_remise === 'refusee'` est contestable.
  4. Motif 10–2000 caractères ; pièce PDF/image ≤ 5 Mo, MIME réel vérifié.
  5. Aucun fichier stocké : seul le hash SHA-256 est conservé.
  6. Aucun statut accepté depuis la requête ; le service ne modifie pas `statut_remise`.
  7. Double soumission idempotente ; audit canonique.
- **Preuve :** `Q132ContestationRemiseHttpTest` (7 tests) + 3 tests service ; tous PASS.
- **Résultat :** `PASS`.

### S-11 — Q133/Q134 : recours de remise, falsification d'état

- **Scénario :** un appelant tente d'imposer `confirme`, `admis` ou `rejete`, ou une décision sans référence, ou deux décisions contradictoires sur la même référence.
- **Contrôles :** liste fermée d'issues ; référence obligatoire ; conflit de référence détecté ; statut antérieur conservé dans l'audit ; aucune mutation des dossiers associés ; route `admin.notifications.recours` protégée par `auth`, `admin`, `mfa.obligatoire`, `secondaire.verrou`, `can:campagnes.manage` et `throttle:10,1`.
- **Preuve :** 8 tests PASS (4 service + 4 HTTP), dont `test_q133_admin_sans_permission_de_gestion_ne_peut_pas_appliquer_le_recours` (403) et `test_q133_utilisateur_non_authentifie_est_redirige`.
- **Résultat :** `PASS_WITH_RESERVATIONS`.
- **Réserve :** aucune voie de recours institutionnelle configurée ; la permission `campagnes.manage` est un habilitant technique, pas une délégation de compétence métier.

### S-12 — Q135/Q136 : constat d'échéance sans effet de bord

- **Scénario :** un dépassement est constaté et le système applique une forclusion, une prolongation ou une sanction.
- **Contrôles :** `consequences_appliquees=false`, `forclusion_automatique=false`, `dossier_associe_mute=false`, échéance initiale retournée à l'identique ; référence officielle non interprétée ; route `admin.derogations.constater-depassement` protégée par `can:demandes.process` et `throttle:20,1`.
- **Preuve :** 6 tests PASS + 1 test HTTP mutualisé (`test_q135_q136_admin_constate_depassement_echeance`) ; journal d'intégration sans PII.
- **Résultat :** `PASS_WITH_RESERVATIONS`.
- **Réserve :** aucun texte officiel de conséquence n'est disponible.

### S-13 — Q137/Q138 : dérogation, compétence non inventée

- **Scénario :** un appelant désigne une autorité arbitraire ou une décision sans acte.
- **Contrôles :** mécanisme et autorités issus exclusivement de `config/loop146.php`, vides par défaut ; `statuerDerogation` échoue si le mécanisme n'est pas identifié ou si l'autorité n'est pas compétente ; décision et référence validées ; pièce réduite à un hash ; aucun stockage de fichier ; routes `admin.derogations.statuer` et dépôt étudiant protégées.
- **Preuve :** 12 tests PASS (7 service + 5 HTTP), dont `test_q138_decision_invalide_rejetee`, `test_q138_decision_sans_reference_officielle_rejetee`, `test_q138_admin_statuer_fail_closed_puis_succes_si_autorite_reconnue` et les 4 tests HTTP Q137.
- **Résultat :** `PASS_WITH_RESERVATIONS`.
- **Réserve :** aucune autorité réelle n'est configurée ; le fail-closed est volontaire.

### S-14 — Q139/Q140 : relance, canal et silence

- **Scénario :** un appelant utilise un canal non institutionnel, rejoue une relance ou déduit une décision du silence.
- **Contrôles :** liste blanche de canaux ; clé d'idempotence ; `echeance_generee=null` ; `inference_silence='aucune'` ; `delai_promis=null` ; `decision_prejugee=false` ; statut inchangé ; routes admin `relancer` et `informer-attente` protégées par `can:demandes.process` + `throttle:20,1`.
- **Preuve :** 8 tests PASS (Q139 + Q140) + preuves HTTP mutualisées (`test_q139_admin_relance_fail_closed_puis_succes`, `test_q141_admin_informer_attente_prolongee`).
- **Résultat :** `PASS_WITH_RESERVATIONS`.
- **Réserve :** aucun canal institutionnel configuré par défaut.

### S-15 — Q141/Q142 : information, notification et contestation d'attente

- **Scénario :** l'information promet un délai, préjuge une décision, part vers un autre destinataire, ou la contestation invente une procédure et mute le statut.
- **Contrôles :**
  - Q141 : destinataire résolu depuis le modèle `Etudiant` ; message factuel ; `delai_promis=null` ; `decision_prejugee=false` ; `template_version=q141-v1` ; clé anti-doublon ; acte journalisé avant envoi ; échec d'envoi capturé sans perte de la trace ; route `admin.derogations.informer-attente` derrière `can:demandes.process` + `throttle:20,1`.
  - Q142 : route `POST etudiant/derogations/contester-attente` derrière `etudiant.auth` + `throttle:10,1` ; session serveur ; anti-IDOR 404 ; motif 10–2000 caractères ; voies limitées à la configuration ; `procedure_inventee=false` ; `statut_modifie=false` ; idempotence.
- **Preuve :** 21 tests PASS (Q141 9 + Q142 12).
- **Résultat :** `PASS_WITH_RESERVATIONS`.
- **Réserve :** aucune voie de recours configurée ; le circuit éditorial du template Q141 n'est pas institutionnellement validé.

### S-16 — Q143 : transmission de réexamen

- **Scénario :** un appelant transmet hors voie officielle, sans référence, ou déduit un délai/une décision du silence.
- **Contrôles :** voie en liste blanche ; référence obligatoire ; `delai_confirme/fixe/garanti=null` ; `preuve_transmission_enregistree=true` ; `decision_deduite_du_silence=false` ; destinataire officiel, source officielle et horodatage tracés dans l'audit ; idempotence ; route `admin.derogations.transmettre-reexamen` protégée par `can:demandes.process` + `throttle:20,1`.
- **Preuve :** 6 tests PASS (5 service + 1 HTTP).
- **Résultat :** `PASS_WITH_RESERVATIONS`.
- **Réserve :** aucune voie de réexamen configurée.

### S-17 — Q144/Q145/Q146 : préservation verbatim et absence de présomption

- **Scénario :** le système complète des motifs manquants, interprète une décision ambiguë, ou applique l'option Q146 non confirmée.
- **Contrôles :** texte et motifs conservés verbatim ; `motifs_completes=false` ; `interpretation_supplee=false` ; contenu intégral + hash ; `acceptation_presumee=false` ; `refus_presume=false` ; Q146 : `statut_final=null`, `decision_finale_appliquee=false`, `option_q146_confirmee=false`, décision initiale consultable ; routes admin `decision-insuffisamment-motivee`, `decision-ambigue` et `appliquer-clarification` protégées par `can:demandes.process` + `throttle:20,1`.
- **Preuve :** 18 tests PASS (Q144 4 + Q145 4 + Q146 10).
- **Résultat :** `PASS` pour la préservation ; `SANS VERDICT FINAL` pour Q146.
- **Réserve :** l'option Q146 (A/B/C) n'est pas confirmée ; toute règle qui en dépendrait est volontairement absente.

### S-18 — Reachability : Q132–Q146 exposées et protégées

- **Constat :** les points d'entrée HTTP de production sont :
  - côté étudiant : Q132 (`etudiant.notifications.contester`), Q137 (`etudiant.derogations.deposer`), Q141/Q142 (`etudiant.derogations.index`, `etudiant.derogations.contester-attente`) ;
  - côté administration : Q133/Q134 (`admin.notifications.recours`, `can:campagnes.manage`), Q135–Q146 (`admin.derogations.*`, lecture `demandes.view`, mutation `demandes.process`, `throttle:20,1`).
- **Conséquence sécurité :** chaque phase dispose d'une barrière d'authentification et d'autorisation serveur, testée par des requêtes HTTP réelles (403 pour un rôle en lecture seule, redirection pour un invité, anti-IDOR pour Q132/Q142).
- **Résultat :** `PASS_WITH_RESERVATIONS`.
- **Réserve :** les permissions (`demandes.process`, `campagnes.manage`) sont des habilitants techniques ; aucune délégation institutionnelle de compétence, de signature ou de périmètre facultaire n'est fournie. Q140 n'a pas d'endpoint propre (preuve mutualisée).

### S-19 — Absence de second système de journalisation

- **Constat :** Q139, Q142 et Q143 utilisent `historique_academique` via `AuditService` / `evenementExiste` ; Q132 utilise `data.contestations` de la notification ; Q141 utilise le système central `NotificationService::notifierEtudiant()` tout en journalisant l'acte. Aucun journal parallèle n'a été créé.
- **Résultat :** `PASS`.
- **Réserve :** la déduplication Q132 est portée par la charge JSON de la notification ; une migration de schéma future devra préserver cette structure.

### S-20 — Modèle de lecture consolidé `consulterDossierDerogations`

- **Constat :** une méthode publique `consulterDossierDerogations` a été ajoutée au service par un acteur concurrent, sans test. Deux défauts d'intégrité ont été identifiés puis corrigés :
  1. **Présomption de refus (Q146) :** toute clarification enregistrée était traduite en `refuse=true` avec le libellé « Dérogation refusée (clarifiée) », alors que Q146 n'autorise aucune conclusion finale. Correction : `statut='clarification_enregistree'`, `statut_final=null`, `decision_finale_appliquee=false`, `refuse=false`, `accepte=false`.
  2. **Précédence inversée :** la lecture utilisait `latest('id')` ; un événement ancien pouvait écraser l'état d'un événement postérieur. Correction : `oldest('id')`, l'état final reflétant le dernier événement.
- **Contrôles :**
  - la contestation Q142 ne change plus le statut du dossier ; elle n'annote que l'affichage lorsque le dossier est encore `en_attente` ;
  - une décision explicite (`decision_derogation`) reste prioritaire sur une contestation postérieure ;
  - l'échéance initiale Q135 est lue depuis `ancienne_valeur`, jamais recalculée ;
  - la consultation est strictement limitée à l'`etudiant_id` fourni (pas d'identifiant de dossier externe).
- **Tests :** `tests/Feature/Loop146ConsultationEtIntegrationTest.php` — 9 tests, 72 assertions, PASS.
- **Résultat :** `PASS`.
- **Réserve :** la méthode est appelée par les contrôleurs étudiant et admin ; l'isolation repose sur la résolution serveur de l'étudiant (session ou paramètre `{etudiant}`), sans policy facultaire dédiée.

### S-21 — Autorisation administrative des routes Q133–Q146

- **Scénario :** un agent back-office authentifié mais dépourvu de la permission de mutation tente d'appliquer un recours, une décision ou une clarification.
- **Contrôles :**
  - groupe admin : `auth`, `admin`, `mfa.obligatoire`, `secondaire.verrou` ;
  - lecture : `can:demandes.view` ;
  - mutation : `can:demandes.process` + `throttle:20,1` ;
  - Q133/Q134 : `can:campagnes.manage` + `throttle:10,1`.
- **Preuve :** `AdminDerogationGestionHttpTest` (11 tests, 62 assertions) et `Q133Q134NotificationRecoursAdminHttpTest` (4 tests, 16 assertions) ; le rôle `consultation` (lecture seule) obtient `403` et ne crée aucune trace.
- **Résultat :** `PASS_WITH_RESERVATIONS`.
- **Réserve :** aucune policy de périmètre facultaire n'est appliquée sur `{etudiant}` ; un agent habilité peut cibler un étudiant d'une autre faculté. À corriger avant production si le modèle facultaire l'exige.

---

## 3. RISQUES RÉSIDUELS

| ID | Risque | Gravité | Traitement |
| :--- | :--- | :--- | :--- |
| `R-01` | Règles Q132–Q145 fondées sur une source reconstruite (formulations à vérifier) | Moyenne | Vérifier les formulations contre le questionnaire original ; les réponses sont traitées comme confirmées par désignation utilisateur |
| `R-02` | Routes Q133–Q146 exposées sans validation institutionnelle des compétences ni policy facultaire | Moyenne | RBAC et quotas testés ; faire valider les habilitations et ajouter le scoping facultaire avant production |
| `R-03` | Absence de textes officiels (autorités, délais, voies, silence) | Élevée | Configuration vide par défaut ; fail-closed ; aucune règle inventée |
| `R-04` | Q146 non tranchée (A/B/C) | Élevée | Aucun statut final ; toute règle dépendante bloquée |
| `R-05` | Historique modifiable hors application | Moyenne | À traiter si une exigence d'immuabilité est confirmée |
| `R-06` | Écritures concurrentes entre agents | Moyenne | Isolation par worktree ; rejeu des tests après stabilisation |
| `R-07` | Preuve de remise non fondée (méthodes ajoutées hors LOOP) | Moyenne | Ne pas automatiser de forclusion ; décision utilisateur requise |
| `R-08` | Déduplication Q132 portée par un JSON | Faible | Documenté ; préserver la structure lors des migrations |

---

## 4. CONCLUSION SÉCURITÉ

La vulnérabilité critique de logique métier de la route publique d'inscription (dérogation auto-déclarée) est corrigée et couverte par des tests (`S-SEC-01`).

Les règles Q132–Q146 sont implémentées avec des contrôles vérifiés : autorisation et isolation pour Q132, listes fermées et fail-closed pour les autorités et canaux, préservation verbatim des décisions, idempotence, absence de mutation automatique et journalisation canonique sans PII pour le constat d'échéance.

**Aucun `PASS` global n'est revendiqué.** Trois limites empêchent une validation complète :

1. les formulations Q132–Q145 sont reconstruites et restent à vérifier ;
2. les routes Q132–Q146 sont exposées et protégées par RBAC/quotas, mais les autorités institutionnelles, les délégations de signature et le périmètre facultaire ne sont pas validés ;
3. Q146 n'a aucune option confirmée : aucun statut final ne peut être posé.

Les Q001–Q131 restent sans source et ne sont couvertes par aucun contrôle métier.

# 01 — Décisions métier (cycle de vie des examens)

**LOOP GLOBAL — Lot EX-LIFECYCLE — Phase B**

Ce document isole les règles **non démontrées par le code** qui ne peuvent pas être inventées
par l'ingénierie. Les transitions et corrections indépendantes sont traitées dans
`03-implementation.md` et `04-regles-de-transition.md`.

Statuts : `EN_ATTENTE` (décision requise) — `CONSIGNÉ` (comportement actuel documenté, décision non urgente) — `RÉALISÉ` (correction technique sans règle nouvelle).

---

## D-01 — Réouverture d'un examen (↔ ANOM-A11)

- **Question** : une réouverture d'examen (post-publication ou post-archivage) est-elle une
  opération métier autorisée ? Si oui : autorité, motif, état cible, conservation des versions,
  impact sur les résultats officiels.
- **Code concerné** : aucun code existant (absence constatée). Comparaison : la réouverture de
  **session** existe (`ExamClotureCorrectionService::rouvrirSessionCloturee` — motif obligatoire
  + approbateur distinct).
- **Comportement actuel (conservé)** : **aucune réouverture possible**. `published`/`ongoing`
  sont scellés pour le moteur simple ; la seule évolution post-publication est une **nouvelle
  version de planning** via la gouvernance (`creerNouvelleVersionPostGel`), qui n'écrase jamais
  la version publiée. Aucune réouverture non contrôlée n'existe — l'historique reste intact.
- **Risque** : absence de procédure formalisée si un besoin institutionnel de réouverture survient.
- **Décision nécessaire** : consacrer le statu quo ou spécifier un circuit de réouverture
  (autorité, motif, états, impacts).
- **Responsable** : Direction des études / autorité de publication.
- **Statut** : `EN_ATTENTE`. La matrice refuse la réouverture ; aucun test « passant » artificiel
  n'a été créé (EX-LC-029/030/031 → statut documenté).

## D-02 — Motif obligatoire des transitions sensibles

- **Question** : les transitions sensibles (`→ cancelled`, `→ locked`, `→ archived`) doivent-elles
  exiger un motif obligatoire, comme les opérations de gouvernance (rejet/escalade/gel) ?
- **Code concerné** : `ExamenController::transiter` (`motif` optionnel), `ExamenService::transiter`
  (colonne `motif` du journal alimentée si fournie).
- **Comportement actuel (conservé)** : le motif est **facultatif** ; l'exigence n'a pas été inventée.
  La colonne du journal est prête : le passage à l'obligatoire ne nécessitera qu'une validation
  de requête.
- **Risque** : annulation/archivage tracés sans justification textuelle.
- **Décision nécessaire** : trancher l'exigence de motif pour `cancelled`/`locked`/`archived`.
- **Responsable** : Scolarité centrale.
- **Statut** : `EN_ATTENTE`. Test EX-LC-026 adapté à la politique réelle (motif facultatif).

## D-03 — Notifications dédiées aux transitions simples

- **Question** : les transitions ordinaires d'examen (`draft → planned → scheduled`, annulation)
  doivent-elles déclencher des notifications dédiées (enseignants, scolarité, étudiants) ?
- **Code concerné** : `EvaluationChanged` (événement `examen.statut` existant, listeners actuels :
  log applicatif + génération de relevés à la publication).
- **Comportement actuel (conservé)** : aucun destinataire défini pour les transitions simples ;
  aucun canal nouveau introduit.
- **Décision nécessaire** : définir destinataires et canaux.
- **Responsable** : Scolarité / communication institutionnelle.
- **Statut** : `EN_ATTENTE`.

## D-04 — Propagation de l'annulation aux épreuves et sessions (↔ ANOM-A10)

- **Question** : l'annulation d'un examen (`→ cancelled`) doit-elle entraîner une conséquence sur
  les épreuves et sessions rattachées (annulation, avertissement, statu quo) ?
- **Code concerné** : aucune propagation automatique aujourd'hui. L'audit de conformité
  (`ExamScheduleEngineService::auditerSessionExamens`) **détecte** les incohérences mais ne
  corrige rien silencieusement.
- **Comportement actuel (conservé)** : statu quo — aucune conséquence automatique ; l'incohérence
  éventuelle reste détectée par l'audit dédié.
- **Décision nécessaire** : choisir propagation automatique / auto-guidage / statu quo.
- **Responsable** : Direction des études.
- **Statut** : `EN_ATTENTE`. Test EX-LC-036 : couvert par les suites existantes (détection).

## D-05 — Précondition de complétude pour `→ completed`

- **Question** : quelles conditions définissent un examen « terminé » (`completed`) ?
  (toutes épreuves achevées ? notes saisies ? verrouillées ?)
- **Code concerné** : matrice `completed ← ongoing` (rappel : transitoire `ongoing` scellée côté
  moteur simple, cf. `04`) ; aucune précondition de complétude codée.
- **Décision nécessaire** : définir « terminé » (liste vérifiable de conditions).
- **Responsable** : Direction des études.
- **Statut** : `EN_ATTENTE`.

---

## Annexe — Points d'audit complémentaires (consignés, aucune décision requise à ce stade)

| Réf. audit (`00` §13) | Sujet | Qualification retenue |
|---|---|---|
| ANOM-A1 | Automate effectif — scellement post-publication | Consigné dans `04` (comportement réel) ; évolution possible = décision complémentaire |
| ANOM-A2 | Refus de gouvernance → `draft` (écriture directe) | Règle explicite encadrée : transaction verrouillée de gouvernance + journal `PlanningEvenement` ; routage via le moteur = amélioration optionnelle |
| ANOM-A3 | Scénario appliqué → `scheduled` (raccourci `draft/planned → scheduled`) | Écriture directe encadrée (transaction + verrou + audit) ; alignement de la matrice = amélioration optionnelle |
| ANOM-A4 | `statut` importé (DataExchange) | Contrôle d'appartenance à `Examen::STATUTS` = amélioration annexe (aucune règle nouvelle à inventer) |
| ANOM-A5 | Propagation publication épreuves sans trace unitaire | Propagations groupées documentées (D-06 convocations) ; traces unitaires = amélioration optionnelle |
| ANOM-A6 | Payload `EvaluationChanged` ignoré (3ᵉ argument) | Sans impact métier démontré : l'audit persistant passe par `evaluation_examen_evenements` et `PlanningEvenement` |
| ANOM-A9 | MFA absent sur routes de modules | Schéma systémique (tous modules administratifs), transverse — hors périmètre EX-LIFECYCLE |
| ANOM-A12 | Dépendance `ExamenService` non utilisée dans la gouvernance | Nettoyage de code annexe, sans impact fonctionnel |

**Règle appliquée** : aucune règle inventée n'a été codée. Les réalisations du LOOP
(cf. `03-implementation.md`) se limitent au verrou transactionnel, à l'historisation append-only
et à l'explicabilité — des conventions DÉJÀ démontrées dans le module (gouvernance, publication,
`EpreuveService`).

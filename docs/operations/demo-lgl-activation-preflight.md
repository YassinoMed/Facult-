# DEMO-LGL — Dossier de préactivation (audit + simulation, SANS écriture métier)

Date : 12/10/2026 · Environnement : `local` · Base : MySQL `inscription_etudiants` (dev partagée).
Mode : lecture seule. **Cette mission n'est pas une autorisation d'activation.**
Référence de décision institutionnelle : **aucune fournie**.

## A. Identité et état observé

| Élément | Valeur vérifiée (12/10, lecture seule) |
|---|---|
| Programme | `DEMO-LGL` « Licence Génie Logiciel (démo) », id 1, statut `actif`, durée 3, crédits requis 180 |
| Établissement | `FST` — Faculté des Sciences et Techniques |
| Maquette | id 1, **v1**, année 2026-2027 (id 7, active), statut **`review`**, `validee_par` NULL, jamais approuvée |
| Maquettes actives (tous périmètres) | **0** — index unique `maquettes_active_programme_unique` présent |
| Contenu du plan v1 | 1 UE `DEMO-UE01` (S1, 12 cr, coef 3) + 2 occurrences (`DEMO-ALG`, `DEMO-ALG2` ; 6+6 = 12 cr ; volumes 20/20/10 ; régime NULL = repli obligatoire) |
| Consommateurs liés | 2 inscriptions académiques, 0 examen |
| Maquette modifiée entre 11/10 et 12/10 | Non (updated_at = 11/10 20:49, transition draft→review tracée `referentiel.maquette.review`) |

**Aucune écriture métier effectuée** pendant ce dossier (preuve en fin de document §Vérification).

## B. Résultats

### B1. Complétude pédagogique par semestre (base actuelle)

| Semestre | Structure | UE | ECUE | Crédits/volumes | Prérequis | Statut |
|---|---|---|---|---|---|---|
| S1 (L1) | présent | DEMO-UE01 (12 cr) | DEMO-ALG, DEMO-ALG2 (6+6) | renseignés ✓ | DEMO-ALG2 ← DEMO-ALG (acyclique) ✓ | **PASS technique** (conformité pédagogique non prouvée : aucune source officielle) |
| S2 (L1) | présent | — | — | — | — | **BLOCKED_HUMAN_DECISION** (contenu officiel absent — ARB-001) |
| S3 (L2) | présent | — | — | — | — | **BLOCKED_HUMAN_DECISION** |
| S4 (L2) | présent | — | — | — | — | **BLOCKED_HUMAN_DECISION** |
| S5 (L3) | présent | — | — | — | — | **BLOCKED_HUMAN_DECISION** |
| S6 (L3) | présent | — | — | — | — | **BLOCKED_HUMAN_DECISION** |

Aucune UE/ECUE inventée ; aucune source institutionnelle de contenu fournie pour S2–S6.

### B2. Contrôles techniques (workflow réel, sans appeler la transition)

Preuve de référence : `MaquetteActivationSimulationService` (LECTURE SEULE vérifiée — aucun
UPDATE/INSERT dans le code) + `referentiel:simuler-activation --programme=DEMO-LGL`
(sortie : `2 blocage(s), Aucune écriture effectuée.`).

| # | Contrôle | Résultat | Preuve / règle | Conséquence | Action requise |
|---|---|---|---|---|---|
| 1 | Maquette cible existe | PASS | id 1, v1, année 2026-2027 active | — | — |
| 2 | Programme/année cohérents | PASS | programme actif, LIC 3 ans = 3 niveaux, année active | — | — |
| 3 | Complétude S/UE/ECUE | **FAIL structurel** | SEM-02 : S2–S6 sans UE ; MAQ-03 : v1 `review` | activation refuserait un plan incomplet | ARB-001 (contenu) + ARB-003 (approbation) |
| 4 | Relations + prérequis | PASS (périmètre S1) | PRE-01 sans cycle ; INV-1/INV-5 garde serveur | — | — |
| 5 | Aucune maquette active en conflit | PASS | 0 active + index unique DB + lockForUpdate `transiter` | — | — |
| 6 | Permissions de transition | PASS technique | `approved/active` exigent `maquettes.approve` (super_admin/admin_scolarite) ; `validee_par` posé par le service | l'acteur actuel devra détenir la permission | compte approbateur habilité à désigner |
| 7 | Invariants transactionnels | PASS (code+prouvé MySQL) | `transiter` transactionnel : archive→active, jamais partiel | — | — |
| 8 | Anti double-active | PASS | index unique prouvé scratch (1062 sur 2e active) | — | — |
| 9 | Consommateurs si activée | prouvés par code | `inscrireEcue`/`genererEpreuves` passeraient de refus à admis (plan actif) ; les 2 inscriptions existantes deviennent éligibles S1 | changement de **comportement** sans migration de passé | ARB-008 (versions/historiques) |
| 10 | Retour arrière | défini | « archiver l'active » via workflow + nouvelle version (snapshot intact) ; migration réversible | — | valider la procédure avant opération |
| 11 | Notifications | **BLOCKED_HUMAN_DECISION** | aucun envoi câblé sur `MaquetteTransitionnee` (listener = audit Log seul, prouvé) | activation sans notification = sûr par défaut | ARB-009 |
| 12 | Référence de décision | **absente** | aucune référence fournie | activation INTERDITE | décision documentée exigée |

### B3. Registre institutionnel (LOOP 3)

`docs/pedagogie/registre-arbitrages-lmd.md` (relu 12/10) : `ARB-001`, `ARB-003`, `ARB-008`, `ARB-009` =
tous **`A_DECIDER`**, sans référence vérifiable. Blocage maintenu. Aucune recommandation technique
n'a été transformée en décision.

**Informations nécessaires à l'autorisation** : décision explicite · référence vérifiable (PV/note/ticket)
· date · fonction du responsable habilité (détenteur `maquettes.approve`) · périmètre exact
(`DEMO-LGL` v1, année 2026-2027) · conditions éventuelles.

### B4. Effets indirects (LOOP 4 — prouvés par le code, non exécutés)

- Lecture maquette active : `InscriptionService::inscrireEcue`, `SessionPreparationService::genererEpreuves`
  (DomainException sans active → admis après activation) ; tableau de bord/prévalidation compteurs
  passeraient à 1 active ; l'UE DEMO-UE01 resterait verrouillée (`ueVerrouillee`, déjà le cas en review).
- Notifications : **aucune** déclenchée (seul journal d'audit) — aucun envoi sans ARB-009.
- Jobs/events : aucun consommateur automatique du changement d'état prouvé ; audit loggué
  (`referentiel.maquette.*`, ids seuls).
- Contrôles postactivation à prévoir : état réel (`active`), unicité (count=1), `inscrireEcue`
  sur ECUE du plan admise / hors plan refusée (tests existants), journal de transition présent.

## C. Blocages

1. **Contenu pédagogique S2–S6 absent** (ARB-001) — déterminant : activer v1 rendrait opposable un
   plan ne couvrant que S1.
2. **Approbation institutionnelle absente** (ARB-003) — `review → approved → active` exige une décision
   formelle et un approbateur habilité.
3. **Règle versions↔historiques non tranchée** (ARB-008).
4. **Politique de notification non définie** (ARB-009).
5. Aucune anomalie technique bloquante (`BLOCKED_TECHNICAL` non retenu).

## D. Préconditions d'une future activation

1. ARB-001 tranchée et contenu S2–S6 versé (import `maquettes-plan`, aperçu→confirmation, transaction,
   bilan) OU décision de limiter le périmètre.
2. ARB-003 : décision formelle référencée + approbateur détenteur de `maquettes.approve`.
3. ARB-008 tranchée (règle applicable aux 2 inscriptions existantes).
4. ARB-009 tranchée (ou « aucune notification » acté par écrit).
5. Simulation `referentiel:simuler-activation --programme=DEMO-LGL` sans blocage.
6. Rapport de préactivation accepté (le présent document réévalué), puis transition via le service
   uniquement, avec rapport postactivation.

## E. Décision finale de la boucle

**`BLOCKED_HUMAN_DECISION`** — dossier complet pour revue, activation interdite sans décision référencée.
(Pas `READY_FOR_HUMAN_REVIEW` seul au sens strict : ARB-001 prive la revue de son contenu principal.)

## Vérification d'absence d'écriture (12/10)

Indicateurs re-mesurés à l'identique de l'état initial : 4 programmes · maquette v1 statut `review`
(`validee_par` NULL) · 0 maquette active · 52 matières (14 rattachées / 38 standalone) · 2099
inscriptions (2087 sans programme) · 0 notification émise (aucun câblage, vérifié par code) ·
0 migration / schéma inchangé. **Aucune écriture métier démontrée par re-mesure.**

## F. Réception des contenus officiels (préparation, 12/10)

Canaux d'import éprouvés prêts (aperçu sans écriture, refus propres vérifiés, compteurs inchangés `ue=7`, `occ=2`) :
- Fichier sans ligne de données → refus `Fichier illisible ou malformé` (`ues`, `ecues`, `maquettes-plan`).
- Référence inconnue → aperçu **bloquant** (`ues`: 1 erreur `PROGRAMME_NOT_FOUND` ; `maquettes-plan`: 2 erreurs), rien d'écrit.
- Formats attendus (en-têtes) :
  - `ues` : `code;semestre;programme;nom;credits;coefficient;type` (+ `faculte` optionnelle).
  - `ecues` : `code;ue;nom;credits;coefficient;volume_cm;volume_td;volume_tp;type_evaluation`.
  - `maquettes-plan` : `programme;annee;ue;ecue` (+ `credits;volume_cm;volume_td;volume_tp;coefficient;ordre;regime;groupe_choix`).
- Ordre d'intégration (après ARB-001) : `ues` → `ecues` → `maquettes-plan` (brouillon v1, jamais d'activation par l'import), chacun avec aperçu → confirmation explicite.
- En attente : **fichiers officiels ARB-001** puis **décision ARB-003** (référence/date/signataire). Étapes 3–5 du plan : non démarrées, à juste titre.

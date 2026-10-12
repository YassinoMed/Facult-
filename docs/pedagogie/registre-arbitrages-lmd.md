# Registre des arbitrages LMD

Tenu par : scolarité / direction (outil : lecture seule, jamais décideur).
Statuts : `A_DECIDER` · `APPROUVE` · `REFUSE` · `A_COMPLETER`.
Fonction par défaut : `Responsable institutionnel à désigner`.

## ARB-001 — Contenu UE S2–S6 de DEMO-LGL

- Périmètre : DEMO-LGL (FST), semestres S2–S6 (S1 seule couverte : DEMO-UE01).
- Problème : plan incomplet — `SEMESTRES_NON_COUVERTS` (prévalidation).
- Preuves : `admin.referentiel.prevalidation.show` (programme 1) ; crédits/volumes absents car UE inexistantes.
- Décision requise : fournir le contenu officiel (UE/ECUE, crédits, volumes, prérequis) ou réduire le périmètre du programme.
- Options (sans choix) : (a) verser la maquette officielle complète ; (b) limiter DEMO-LGL à S1 (licence partielle assumée) ; (c) geler le programme.
- Impact : bloque toute activation DEMO-LGL ; 2 inscriptions liées restent sans plan actif (refus explicites, pas de corruption).
- Habilité : Responsable institutionnel à désigner. Statut : `A_DECIDER`.
- Consigne reçue (12/10) : attendre les fichiers de contenus officiels validés S2–S6 ; à réception,
  contrôler structure et périmètre, présenter un aperçu SANS écriture (import `maquettes-plan`,
  aperçu→rejets→conflits), exiger la confirmation explicite avant toute intégration en brouillon,
  vérifier références et compteurs, puis relancer la simulation. Interdictions maintenues : rien
  d'inventé, aucune ressemblance de noms ne fonde un rattachement, statut `review` inchangé.

## ARB-002 — Maquettes v1 PAR-GEST / PAR-ECO / PAR-INFO

- Périmètre : 3 programmes (UE S1+S3 partielles, S2/S4/S5/S6 vides).
- Problème : `MAQUETTE_ABSENTE` + semestres non couverts.
- Preuves : simulation 4/4 ATTENTE ; 10 inscriptions + 4 examens liés sans plan actif.
- Décision requise : contenus officiels + création v1 (bouton guidé ou import `maquettes-plan` avec fichier officiel).
- Options : (a) verser les contenus puis créer ; (b) phaser par niveau (L1 d'abord) ; (c) geler.
- Impact : examens existants créés hors `genererEpreuves` (traçabilité à revoir après activation).
- Habilité : Responsable institutionnel à désigner. Statut : `A_DECIDER`.

## ARB-003 — Approbation puis activation DEMO-LGL v1

- Périmètre : maquette v1 DEMO-LGL (`review`, année 2026-2027).
- Problème : passage `review→approved→active` exige approbation formelle + contenu complet (ARB-001 préalable).
- Preuves : workflow prouvé (tests), bascule atomique, garde DB unique ; dossier de préactivation
  `docs/operations/demo-lgl-activation-preflight.md` (12/10, SANS écriture — état re-mesuré inchangé).
- Décision requise : approbation documentée (qui, quand, référence) puis autorisation d'activation (programme+année+version exacts).
- Options : (a) approuver après ARB-001 ; (b) refuser et garder en revue ; (c) archiver.
- Impact : activation rend le plan opposable aux inscriptions/examens ; retour = nouvelle version.
- Habilité : détenteur `maquettes.approve` (super_admin/admin_scolarite) + validation pédagogique. Statut : `A_DECIDER`.

## ARB-004 — 38 matières standalone (temps 3/4)

- Périmètre : 38 `ue_id NULL` (`admin.referentiel.standalone`).
- Problème : aucune correspondance appliquée sans validation. Classification **convergente** (12/10,
  après unification `nomComparable`) : **1 DOCUMENTEE (INFO201, jumeau accentué ECUE-INFO-03 à confirmer),
  5 AMBIGUE (ALGB101, DEMO102, FRAN101, INFO101, MATH101 — 5 UE candidates L1/S1), 32 SANS_CORRESPONDANCE**.
  Usages vérifiés (inventaire 12/10, 0 écriture) : INFO201 = 19 usages (révision d'impact requise avant
  validation), AMBIGUËS = 30/0/29/29/27 (migration au nom seul exclue à raison).
- Preuves : inventaire + usages ; `referentiel:backfill` dry-run.
- Décision requise : valider ou refuser chaque correspondance, une par une.
- Options : (a) valider les DOCUMENTÉES ; (b) laisser en repli legacy (supporté) ; (c) archiver les sans-usage après audit.
- Impact : aucun — le repli legacy est supporté ; migration = traçée et réversible.
- Habilité : Responsable institutionnel à désigner. Statut : `A_DECIDER`.

## ARB-005 — 2087 inscriptions sans programme

- Périmètre : 2087/2099 inscriptions (`programme_id NULL`).
- Problème : périmètre d'inscription indéterminé (pas d'affectation devinée — parcours affiche `SANS_PROGRAMME`).
- Preuves : comptage ; parcours testé non-devinant.
- Décision requise : statu quo documenté (recommandé : historique legacy intouché) OU règle de reprise explicite avec sources.
- Options : (a) statu quo + mention dossier ; (b) reprise tracée par lot avec justificatifs ; (c) ne rien faire et clore.
- Impact : affecte périmètre, statistiques et parcours affichés.
- Habilité : Responsable institutionnel à désigner. Statut : `A_DECIDER`.

## ARB-006 — Clarification « SEM-03 »

- Périmètre : indéterminé — aucune entité de ce code (S1–S6 existants par programme).
- Problème : identifiant cité sans référent.
- Décision requise : préciser l'objet (semestre S3 d'un programme ? autre système ?).
- Options : selon clarification. Habilité : demandeur de la mention. Statut : `A_COMPLETER`.

## ARB-007 — Seuils de charge pédagogique

- Périmètre : tous programmes (`admin.referentiel.charges` descriptif).
- Problème : aucune norme institutionnelle (min/max crédits ou heures par semestre).
- Décision requise : fournir les seuils ou acter le mode descriptif permanent.
- Impact : aucun (affichage seul). Habilité : Responsable institutionnel à désigner. Statut : `A_DECIDER`.

## ARB-008 — Versions applicables aux historiques

- Périmètre : inscriptions antérieures sans maquette active pour leur année.
- Problème : règle actuelle = ACTIVE programme×année ; toute autre règle (courante pour tous, gelée, etc.) changerait le passé affiché.
- Décision requise : confirmer la règle actuelle ou en arrêter une autre par écrit.
- Impact : parcours affichés, relevés, délibérations relues.
- Habilité : Responsable institutionnel à désigner. Statut : `A_DECIDER`.

## ARB-009 — Notifications d'activation (C2)

- Périmètre : events `MaquetteTransitionnee` (technique prête, aucun envoi).
- Problème : destinataires, contenu et déclenchement non validés.
- Décision requise : qui (responsable programme ? scolarité faculté ? — jamais les étudiants en masse sans motif), quoi, quand.
- Impact : anti-spam, confidentialité, charge support.
- Habilité : Responsable institutionnel à désigner. Statut : `A_DECIDER`.

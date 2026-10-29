# Angle 4 — FAQ (questions fréquentes)

> Chaque réponse est ancrée dans le métier réel du projet. Si votre question porte sur un
> libellé d'écran non listé ici, lancez d'abord un contrôlé du côté « À CONFIRMER ».

## Étudiant

**Q. Je suis inscrit et mes notes n'apparaissent pas. Non payé ?**
> Non — la publication des notes dépend des délibérations, pas du paiement. Tant que le jury
> n'a pas **publié**, vous ne voyez rien au niveau des notes : voir l'écran
> `/etudiant/notes` et l'angle 5.

**Q. Puis-je corriger mon nom, prénom, déclaration ?**
> Vos coordonnées basiques : oui via `/etudiant/profil/modifier`. L'identité officielle
> (CIN, nom, etc.) : uniquement via une demande à la scolarité ; utilisez
> `/etudiant/corrections` ou le vérificateur à travers la correction assistée côté agent.

**Q. Comment être réinscrit l'année prochaine ?**
> Deux chemins : une demande via `/etudiant/demandes` (demande administrative), ou les
> réinscriptions pilotées par la scolarité
> (`/admin/etudiants/{id}/reinscriptions/{creer}` + POST de réinscription). Un seul
> compte officiel par année ; anti-doublon automatique (unique).

**Q. Qu'est-ce que le « compostage » au moment du dépôt ?**
> Vos notes d'épreuve sont saisies sous une référence anonyme
> `CMP-####` ; le lien sur votre identité est démasqué **uniquement côté serveur** quand
> la scolarité fait ensuite la réconciliation ; c'est un garde-fou anti-fraude. Vous n'y accédez pas.

**Q. Puis-je consulter les cours d'un autre ?**
> Non. Votre session contient votre dossier uniquement ; le serveur le résout sans jamais
> lire un ID d'URL. Testé explicitement.

**Q. Mon document est refusé (photo peu lisible/OCR).**
> OCR « fail-soft » : l'extraction automatique est silencieuse ou ne se fait pas ; une
> saisie manuelle est proposée + une alerte de vérification. Re-consultez la liste des
> pièces obligatoires au niveau du percepteur et re-déposez une meilleure pièce.

## Administration

**Q. Je valide mais le bouton « refuse » : pièces ? bloquants ?**
> La validation exige : piècefs obligatoires complètes **ET** verdict non-BLOQUANT du
> vérificateur. Ouvrez `/admin/etudiants/{id}/verification` : chaque règle débloque la
> raison précise (R1-R10, angle 5).

**Q. Un reçu bizarre : deux paiement confirmés ?**
> Impossible : un seul reçu VALIDE par dossier ; commentaire obligatoire si écart de
> montant. Si vous voyez deux entrées au-delà de VALIDÉ, vérifiez les statuts et la date ;
> sinon, le cas est un doublon historique à escalader (règle + `DossierPaiementService`).

**Q. Ai-je le droit de déverrouiller une note verrouillee ?**
> Seulement si vous avez `notes.unlock` (réservé `admin_scolarite`+). Sinon : un chemin en
> 2 étapes (demande d'autorisation → correction autorisée + puis corrections) existe sous
> le module Évaluation.

**Q. Publication d'une délibération : un étudiant que je notifie n'atteint pas sa note.**
> Vérifiez : (a) la délibération est véritablement `publie=true` (angle 5), (b) l'étudiant
> consulte page `/etudiant/notes` (et pas une moyenne bidon), (c) notification distribuée
> *après* publication (délai fenêtrée de déduplication). Si tout OK et toujours invisible,
> escaladez avec l'audit de la délibération (historique, run hashé).

**Q. Des dossiers restent en file sans action.**
> Chaque ligne de la file propose une **action attendue** calculée. Si la cause n'est pas
> apparente : ouvrir `admin/centre-travail/{id}` (diagnostic explicable dry-run) ; ne
> jamais « forcer » via un contournement.

**Q. Ai-je le droit de créer un dossier administratif sans inscription publique ?**
> Oui, c'est le flux dédié (`admin.etudiants.creer`) avec inscription validée + motif tracé
> et indicateur de paiement `oui/non` (paiement effectué sur un site externe) — vérifier
> que le motif est précis, l'audit le gardera.

## Général

**Q. Pourquoi tant de 404 ?**
> Modules activés via drapeaux d'environnement. Un module désactivé → 404 sur toutes ses
> pages (c'est le middleware `module:xxx`). Vérifier la configuration via `/admin/parametres`
> ou les env de déploiement.

**Q. Peut-on annuler une action déjà validée ?**
> Pour la plupart des écritures critiques : **non** (invariants métier) ; il existe des
> chemins compensatoires (re-vérification, régularisations, corrections traçées). Comptez
> toujours sur le motif + l'audit ; ne tentez pas d'édition directe en base.

**Q. Mes données personnelles sont-elles stockées longtemps ?**
> Les pièces d'identité scannées sont traitées **temporairement** puis supprimées
> (chemin `storage/app/private`, `finally`). Des dossiers étudiants versionnés restent en
> GED privée (SHA-256 + trace). Aucune PII dans les logs.

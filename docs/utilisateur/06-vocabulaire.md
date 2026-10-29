# Angle 6 — Vocabulaire (langage utilisateur)

> Ces mots apparaissent dans l'interface. Régles simples, zéro code.
> Termes marqués (*dev*) ont un pendant technique — voir `docs/KNOWLEDGE-BASE.md`.

| Terme | Sens pour l'utilisateur |
|---|---|
| **CIN** | Carte d'identité nationale : identifiant principal de dossier (8 chiffres, unique) |
| **Numéro d'inscription** (`INS-YYYY-XXXXX`) | La référence officielle attribuée au dossier à la création. « YYYY » = année |
| **Dossier académique (360°)** | Le regroupement visible d'un étudiant : identité, inscriptions, groupes, notes, documents, historique |
| **File de traitement** | La liste FIFO que la scolarité travaille : un dossier « en_attente » + l'action attendue calculée par la plateforme |
| **Vérificateur intelligent** | Le moteur d'expertise du dossier (lecture seule) : 10 règles, verdict + score /100, recommandations |
| **Verdict BLOQUANT** | Ce qui interdit la validation tant que le problème existe — lié à une règle R1-R10 |
| **Correction assistée** | Modifier la donnée par la voie contrôlée : permission + **motif obligatoire** + transaction + audit + re-vérification |
| **Compostage (`CMP-######`)** | Numéro anonyme attribué pour la saisie de notes sans voir l'identité (anti-fraude) ; la révélation se fait côté serveur |
| **Épreuve** | Une modalité de notation (examen, rattrapage…) ; dispose de compostage, salle, surveillance, incidents |
| **UE / ECUE** | Élément du référentiel LMD ; l'ECUE (Matiere) porte ses coefficients et ses crédits |
| **Maquette** | Le programme LMD de la faculté/programme — le référentiel des cours (avec prévalidation intégrée) |
| **Note d'épreuve** vs **Note officielle** | « Brouillon » au niveau épreuve (saisie anonyme, bornée) ; devient officielle quand validée — c'est la seule source des moyennes |
| **Seuil / Bornes 0..20** | Toute note est contrainte entre 0 et note_max **vérifié serveur** ; 2 décimales maximum ; barème absent ou ≤ 0 = saisie bloquée |
| **Verrouiller / Déverrouiller** | Geler une note officielle ; déverrouiller est une permission rare (`notes.unlock`) |
| **Correction (note) en 2 étapes** | Demande d'autorisation, puis autorisation + correction — traçable à chaque étape |
| **Délibération** | L'instance de jury qui va prendre des décisions par étudiant (machine à 9 états, dès « préparation » jusqu'à « archivée ») |
| **Quorum** | Président + au moins 2 présents ; sans lui, la validation du jury est refusée (compteur temps réel à l'écran) |
| **Publier (délibération)** | Rendre les décisions visibles aux étudiants et à l'API ; unique devient leur source de vérité |
| **PV / Relevé de notes** | Documents ajoutés du jury (PV) et documents officiels pour l'étudiant (relevés) |
| **Attestation** | Document officiel émis par la scolarité (par inscription) |
| **Récépissé** | Le document d'accusé de dépôt de votre dossier d'inscription |
| **Période d'inscription** | Fenêtre temporelle pendant laquelle les inscriptions sont acceptées ; exceptions individuelles à usage unique |
| **Régularisation** | Réconcilier les inscriptions/d'é affects en arrière-plan ; plus rapide que recréer un doublon biaisé |
| **Affectation (groupe)** | L'inscription pédagogique à un groupe de cours ; toujours contrôlée par capacités |
| **Affectation automatique** | Le système essaie avec un produit re-essai (idempotent) ; échec = jamais bloquant pour l'inscription |
| **Demande en ligne** | Formulaires administratifs côté étudiant (attestation, etc.) : 9 états, pièces, messages intégrés |
| **Rendez-vous** | Système de créneaux avec la scolarité (réserver/déplacer/annuler) |
| **Émargement QR** | Présence confirmée par QR (module Attendance) |
| **Justificatif d'absence** | Pièce jointe pour une absence ; traitement simple (soumission → vérification) |
| **MFA / Défi de connexion** | Second facteur sur les connexions admin selon criticalité |
| **Verrouillage secondaire** | Garde supplémentaire sur les routes admin sensibles (double contrôle) |
| **Audit / Historique** | Le journal immuable : qui, quoi, quand, avant/après — sans données sensibles en plus |
| **Gestion de faculté (scopie)** | Un `admin_faculte` ne voit que SA faculté ; un filtrage depuis le navigateur est ignoré |
| **Session étudiant vs admin** | Le compte étudiant et les comptes administrateurs sont deux mondes séparés (tables, sessions) |
| **Module activé / désactivé** | Chaque brique fonctionnelle s'active par drapeau ; désactivé = pages 404, rien n'est cassé |
| **Idempotent** | Refaire la même écriture deux fois aboutit au même état (aucun doublon) — les boutons « rejouer » sont sûrs |
| **Dry-run (simulation)** | « Simuler / prévisualiser » : voir ce qui se passerait, sans rien écrire |
| **4 yeux** | Une action sensible exige deux avis (demandeur ≠ approbateur) |

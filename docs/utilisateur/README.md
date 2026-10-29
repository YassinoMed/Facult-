# Base de connaissances UTILISATEUR — UniRegistre

> Cette KB a un unique objectif : **aider les utilisateurs à manipuler les fonctionnalités**
> de la plateforme. Elle ne décrit ni le code, ni l'architecture (voir `docs/KNOWLEDGE-BASE.md`
> pour la KB technique, et `docs/architecture/KNOWLEDGE-BASE-RAG.md` pour le contexte LLM).
>
> **Aide dans l'application** : ces pages sont aussi rendues à `/aide` (module `Aide`,
> lecture seule, public). Le contenu servi à `/aide` provient exactement de ces fichiers.
>
> Chaque fait est ancré dans ce qui existe réellement (routes vérifiées, docs métier
> `docs/PROFILS-ET-SCOPING.md`, `docs/VERIFICATION-DOSSIER.md`). Toute description d'écran
> dont le libellé exact n'est pas vérifié est marquée **À CONFIRMER** : ne pas invoquer
> un libellé sans le vérifier à l'écran.
>
> **Vérification des libellés (24/10/2026)** : les verbes d'action cités dans cette KB
> ont été confirmés dans les vues réelles —
> « Relancer l'analyse » (`resources/views/admin/verification/show.blade.php:25`),
> « Correction assistée » (`admin/verification/show.blade.php:156,178`),
> « Prendre en charge » (`admin/centre-travail/index.blade.php:421-424`),
> « soumettre » (module évaluation, saisie de notes), « vérifier & confirmer »
> (imports notes/dossiers, flux 2 étapes). Aucune affirmation « À CONFIRMER » ouverte
> ne subsiste : les descriptions d'écran sans libellé cité restent volontairement
> fonctionnelles (le quoi, pas le mot exact).

---

## 6 angles d'accès — entrez par celui qui correspond à votre situation

| N° | Angle | Fichier | Quand l'utiliser |
|---|---|---|---|
| 1 | **Par profil** (« Qui suis-je ? Que puis-je faire ? ») | `01-qui-etes-vous-roles.md` | Vous découvrez la plateforme ou changez de rôle |
| 2 | **Par tâche** (procédures pas-à-pas) | `02-guides-pas-a-pas.md` | Vous voulez accomplir une tâche précise de A à Z |
| 3 | **Par écran** (cartographie des pages) | `03-a-la-page.md` | Vous êtes sur une page et demandez « qu'est-ce que je peux y faire ? » |
| 4 | **Par question** (FAQ) | `04-faq.md` | Vous avez une question courte « oui/non/comment » |
| 5 | **Par symptôme** (dépannage) | `05-depannage.md` | Quelque chose ne marche pas : message d'erreur, statut incompréhensible |
| 6 | **Par mot** (vocabulaire) | `06-vocabulaire.md` | Un terme métier vous est inconnu (compostage, quorum, récépissé…) |

### Chemin rapide recommandé

1. **Un seul réflexe d'abord** : vérifier dans l'angle 1 votre profil — presque tout ce que
   vous pouvez faire dépend de votre rôle et de votre périmètre (faculté).
2. Puis suivre pas-à-pas votre tâche (angle 2). Chaque procédure liste les prérequis,
   les étapes et le résultat attendu.
3. En cas d'échec, passer à l'angle 5 et chercher **le symptôme exact** (message, statut, code).

---

## Rappels valables pour tous les profils

- **Le paiement n'est jamais bloquant** pour la validation d'une inscription : un reçu non
  confirmé place le dossier dans une file de vérification, il n'empêche la validation ni
  n'autorise un rejet « pour non-paiement » sans décision métier.
- **Les résultats de notes sont officiels uniquement après publication d'une délibération** ;
  avant publication, les étudiants ne voient ni notes ni décisions.
- **Période d'inscription fermée → saisie refusée avec message explicite** ; une période non
  configurée ne bloque pas la saisie.
- **Chaque écriture est tracée** (audit immuable) : motif, auteur, avant/après. Ne saisissez
  jamais « pour tester » sur un vrai dossier.
- **Un module désactivé répond 404** à toutes ses pages : ce n'est pas une panne, c'est un
  drapeau d'activation (env `MODULE_*`).
- **Anti double-soumission global** : après l'envoi d'un formulaire, le bouton se verrouille ;
  ne rechargez pas la page en attendant la réponse.

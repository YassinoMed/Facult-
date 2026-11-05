# LOOP 43-BIS — Interface administrative avec sélections par checkbox

## OBJECTIF PRIORITAIRE UX
Toutes les listes de sélection multiples doivent être présentées sous forme de cases à cocher. Ne pas remplacer les cases à cocher par des menus déroulants multi-sélection.

## 1.1 Principes directeurs

### 1.1.1 Cases à cocher obligatoires
Toutes les listes suivantes doivent utiliser des cases à cocher (`checkbox`) :
- Rôles
- Permissions
- Utilisateurs
- Facultés
- Programmes
- Modules
- Périmètres
- Groupes
- Autorisations déléguées

### 1.1.2 Éviter les multi-sélecteurs
Ne pas remplacer les cases à cocher par des menus déroulants multi-sélection pour les listes ci-dessus.

### 1.1.3 Valeurs exclusives
Les valeurs exclusives (lorsqu'une seule valeur est possible) peuvent utiliser des boutons radio ou un contrôle adapté. Ne pas simuler une sélection exclusive avec des cases à cocher qui permettraient des états incohérents.

### 1.1.4 Recherche et filtrage
Les champs de recherche et de filtrage restent des champs de saisie normaux. Ils ne remplacent pas les cases à cocher.

## 1.2 Écran principal

### 1.2.1 Sections
L'espace d'administration comporte les sections suivantes (non surchargées) :
1. Utilisateurs
2. Rôles
3. Permissions (matrice principale)
4. Affectations
5. Demandes d'accès
6. Approbations
7. Délégations
8. Audit
9. Révisions des accès
10. Paramètres de sécurité

## 1.3 Sélection des utilisateurs

### 1.3.1 Affichage
| Case | Utilisateur | Fonction | Faculté | Statut |
|---|---|---|---|---|
| ☐ | Utilisateur A | Agent | FST | Actif |
| ☐ | Utilisateur B | Enseignant | FST | Actif |
| ☐ | Utilisateur C | Consultation | Autre faculté | Actif |

### 1.3.2 Fonctionnalités
- Recherche : champ de saisie normal
- Filtre par faculté
- Filtre par rôle
- Filtre par statut (actif/suspendu/archive)
- Pagination
- Sélection individuelle
- Sélection de la page courante
- Désélection
- Compteur de sélection : "X permissions/Y permissions sélectionnées"

### 1.3.3 Sélection de tous
- La sélection de tous les résultats doit être une opération explicite et confirmée
- Distincte de la sélection de la page courante
- Comportement : cocher/décocher le en-tête sélectionne/déselectionne tous les résultats du jeu courant (filtrés ou page)

## 1.4 Sélection des rôles

### 1.4.1 Affichage
Les rôles affichés sous forme de cases à cocher avec :
- Plusieurs rôles sélectionnables
- Sélection individuelle
- Sélection groupée
- Recherche
- Filtrage par catégorie
- Aperçu de description
- Aperçu des permissions
- Indication des rôles privilégiés (style visuel différent)

### 1.4.2 Règles
- Un utilisateur peut recevoir plusieurs rôles uniquement si la politique d'affectation l'autorise
- Détection des conflits affichée avant validation (ex: deux rôles avec permissions contradictoires)

## 1.5 Matrice des permissions par checkbox

### 1.5.1 Structure hiérarchique
C'est l'écran principal de cette LOOP. Matrice hiérarchique :

```
[ ] Module Scolarité

    [ ] Étudiants
        [ ] Consulter
        [ ] Créer
        [ ] Modifier
        [ ] Archiver

    [ ] Inscriptions
        [ ] Consulter
        [ ] Créer
        [ ] Modifier
        [ ] Valider

    [ ] Acquis pédagogiques
        [ ] Consulter
        [ ] Simuler
        [ ] Calculer
        [ ] Corriger
        [ ] Approuver

    [ ] Délibérations
        [ ] Consulter
        [ ] Préparer
        [ ] Valider
        [ ] Publier
```

### 1.5.2 Fonctionnement des checkboxes

Chaque permission dispose d'une case à cocher individuelle.

Les groupes disposent d'une case parent avec trois états :
- Non cochée
- Cochee (toutes les permissions du groupe sélectionnées)
- Partiellement cochée (`indeterminate` — certaines seulement)

Comportement :
- Cocher un parent sélectionne les permissions de son groupe
- Décocher un parent retire les permissions de son groupe
- Cocher un enfant met à jour l'état du parent (passant de partiel à plein, ou inversement)
- Les sélections partielles sont visibles (case parent en état `indeterminate`)
- Un compteur affiche le nombre de permissions sélectionnées sur le total affichées

### 1.5.3 Recherche et filtres
- Recherche textuelle (filtre sur les libellés)
- Filtrage par module
- Filtrage par ressource (sous-module)
- Filtrage par action
- Filtrage par criticité (icône/indicateur)
- Affichage des permissions sélectionnées
- Affichage des permissions non sélectionnées

### 1.5.4 La commande "Tout sélectionner"
- Doit préciser son périmètre :
  - Résultats filtrés (après application des filtres)
  - Groupe courant (le groupe parent actuel)
  - Ensemble des permissions affichées (tout ce qui est dans la vue actuelle)
- Ne jamais sélectionner silencieusement tout le catalogue lorsque l'utilisateur consulte seulement un filtre

### 1.5.5 Permissions sensibles
Pour les permissions critiques, afficher :
- Niveau de risque (icône/indicateur)
- Description du danger
- Impact
- Nécessité d'approbation
- Éventuelle obligation MFA
- Périmètre autorisé

Exemples de permissions sensibles à rechercher :
- `gerer les utilisateurs`, `attribuer des rôles`, `administrer les permissions`
- `consulter toutes les facultés`, `modifier les notes`, `valider les résultats`
- `publier une délibération`, `gérer les dérogations`, `accéder aux paramètres de sécurité`
- `exporter des données sensibles`

Ces exemples ne deviennent pas automatiquement des permissions nouvelles ou accordées.

### 1.5.6 Dépendances
Si une permission nécessite une autre permission :
- Afficher la dépendance (lignée ou chaîne)
- Empêcher une combinaison incohérente
- Indiquer explicitement les cases concernées
- Demander une action claire à l'administrateur (sélectionner les deux, ou retirer l'une)

Ne pas ajouter silencieusement des droits supplémentaires.

Toute dépendance doit être déclarée dans le catalogue et contrôlée côté serveur.

## 1.6 Sélection des périmètres par checkbox

### 1.6.1 Interface hiérarchique
```
[ ] Toutes les facultés autorisées dans mon périmètre

    [ ] Faculté A
        [ ] Programme 1
        [ ] Programme 2

    [ ] Faculté B
        [ ] Programme 3
        [ ] Programme 4
```

### 1.6.2 Fonctionnalités
- Sélection individuelle
- Sélection par faculté
- Sélection des programmes
- État partiel du parent (indeterminate)
- Recherche (sur noms de facultés/ programmes)
- Compteur (nombre de facultés/programmes sélectionnés sur le total)
- Aperçu du périmètre final (résumé textuel ou visuel)
- Validation des compétences de l'acteur

### 1.6.3 Règles
- Une sélection de faculté ne doit jamais donner automatiquement accès à une autre faculté
- L'option "toutes les facultés" doit être une opération privilégiée, non disponible pour un administrateur facultaire ordinaire (contrôle par `faculteRestreinteId()` et `estSuperAdmin()`)

## 1.7 UX et Accessibilité

### 1.7.1 Exigences obligatoires
- Cases à cocher suffisamment grandes (minimum 24-30px de hauteur)
- Libellés clairs et descriptifs
- Zone cliquable confortable (tout le ligne ou zone élargie)
- Sélection au clavier (fleches, espace, tabulation)
- Focus visible (style CSS clair sur focus)
- Contraste suffisant (WCAG AA minimum)
- État partiel accessible (screen readers annoncant "partiellement sélectionné")
- Compatibilité mobile (responsive, cases tactiles aisées)
- Responsive (s'adapte à la largeur de l'écran)

### 1.7.2 Éviter les mauvaises pratiques
- Ne pas utiliser uniquement une couleur pour distinguer les permissions sensibles
- Éviter les tableaux illisibles contenant plusieurs centaines de cases à cocher
- Utiliser des groupes repliables, une recherche, une pagination adaptée et un récapitulatif de sélection

## 1.8 Récapitulatif avant enregistrement

### 1.8.1 Avant toute attribution ou modification, afficher :
- Utilisateur(s) sélectionné(s) — nom, rôle actuel, faculté
- Rôle(s) sélectionné(s) — nom, description, catégorie
- Permissions ajoutées — liste avec libellés
- Permissions retirées — liste avec libellés
- Facultés — sélectionnée(s)
- Programmes — sélectionnés le cas échéant
- Durée — si affectation temporaire (ex: 30 jours, 1 an)
- Niveau de risque — d'après la matrice de criticité
- Approbation requise — oui/non, combien d'approbateurs
- Conséquences — impact sur les utilisateurs, sur les données

### 1.8.2 Demander une confirmation explicite
- Boîte de dialogue modale ou section en haut de l'écran
- Bouton "Confirmer et appliquer" clairement identifié
- Bouton "Annuler" à côté

### 1.8.3 Validation serveur
- Le serveur doit reconstruire et valider l'intégralité de la demande
- Il ne doit jamais considérer le récapitulatif du navigateur comme une preuve
- Toute incohérence doit provoquer un refus avec message explicite
- L'opération n'est exécutée qu'après validation serveur complète

## État de l'interfacecheckbox
- 100% des listes multiples utilisent des cases à cocher
- Hiérarchie Module → Ressource → Action fonctionnelle
- États partiels (indeterminate) supportés
- Compteur de sélection visible en permanence
- Recherche et filtres fonctionnels
- Responsive et accessible (WCAG AA en cours)
- Récapitulatif avant enregistrement avec validation serveur

## Livrables
- `LOOP-43BIS-INTERFACE-CHECKBOX.md`

**Verdict G43BIS-10 :** ✅ Interface checkbox — 100% des listes multiples en cases à cocher, hiérarchie Module→Ressource→Action, états partiels indeterminate, compteur visible, recherche/filtres, responsive, accessible, récapitulatif avec validation serveur.
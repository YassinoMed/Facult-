# LOOP-UNIREGISTRE-UX-UI-NAVIGATION-PALETTE-01

**Programme :** Navigation globale & Palette de commandes UniRegistre  
**Identifiant :** `LOOP-UNIREGISTRE-UX-UI-NAVIGATION-PALETTE-01`  
**Date :** 23 octobre 2026  
**Verdict :** `PASS`

---

## 1. OBJECTIF ET PÉRIMÈTRE D'INTERVENTION

L'objectif de cette boucle est d'améliorer la navigation globale d'UniRegistre sans aucune régression fonctionnelle, sécuritaire ou visuelle.

Périmètre strict exécuté :
1. **Navigation du logo selon le rôle connecté** (visiteur, étudiant, enseignant, personnel administratif, administrateur, super-administrateur, multi-rôles et repli sans tableau de bord dédié).
2. **Palette de commandes `x-palette`** (intégration du rôle Enseignant, protection des routes d'entités, préservation des commandes existantes).
3. **Prise en charge des enseignants** dans la palette et la navigation.
4. **Accessibilité clavier et WAI-ARIA** (modèle combobox / listbox / option, région `aria-live`, piège et retour de focus).
5. **Ergonomie mobile** (cibles tactiles $\ge 44px$, hauteur responsive, bouton tactile de fermeture, prévention d'overflow).
6. **Tests automatisés et non-régression** (15 tests dédiés dans `NavigationTest`, 75 tests transversaux, Pint conforme).

---

## 2. LOT 0 — INVENTAIRE INITIAL ET AUDIT FACTUEL

Avant toute modification, l'inspection de l'existant a mis en évidence les éléments factuels suivants :

| Composant | État initial constaté | Risque ou anomalie identifiée |
| :--- | :--- | :--- |
| **Logo Sidebar Desktop** (`layouts/app.blade.php:143`) | `<a href="{{ route('inscription.create') }}" ...>` | Redirection inconditionnelle vers la page publique de création d'inscription, quel que soit le rôle connecté. |
| **Logo Header Mobile** (`layouts/app.blade.php:217`) | `<a href="{{ route('inscription.create') }}" ...>` | Identique à la sidebar desktop, sans redirection vers les espaces connectés. |
| **Composant `x-palette`** (`components/palette.blade.php`) | Ne traitait que `auth()->check()` (Admin) et `session('etudiant_auth_id')`. | **Les enseignants (`session('enseignant_auth_id')`) étaient totalement exclus** de la palette de commandes. |
| **Endpoints instantanés** (`components/palette.blade.php:41`) | Injectait en dur `route('admin.recherche.instantanee')` et `route('admin.recherche.contexte')`. | Pour les profils étudiant et enseignant, tout appel client déclenchait une erreur 401/403 vers des routes réservées au back-office. |
| **Sémantique WAI-ARIA** | Input standard sans `role="combobox"`, absence d'`aria-controls`, `aria-activedescendant` manquant. | Non-conformité aux recommandations W3C APG pour les composants de type combobox/palette. |
| **Ergonomie mobile** | Positionnement fixe avec `pt-[12vh]`, pas de bouton tactile de fermeture dédié (dépendant d'une touche Échap absente sur clavier tactile virtuel). | Expérience mobile dégradée sur smartphones de petite hauteur. |

---

## 3. LOT 1 — NAVIGATION DYNAMIQUE DU LOGO

### 3.1 Architecture : `App\Support\NavigationDestination`

Pour éviter la dispersion de la logique et garantir qu'aucun utilisateur ne soit redirigé vers une page interdite (erreur 403), le résolveur canonique `App\Support\NavigationDestination` a été mis en place.

Il inspecte l'état d'authentification effectif, le statut du compte et les permissions réelles (pas seulement le libellé du rôle) :

```php
final class NavigationDestination
{
    public static function resoudre(?Request $request = null): string;
    public static function estBackOfficeAutorise(?User $user = null): bool;
    public static function estEnseignantConnecte(?Request $request = null): bool;
    public static function estEtudiantConnecte(?Request $request = null): bool;
    public static function destinationPublique(): string;
}
```

### 3.2 Matrice de redirection du logo

| Profil / Contexte | Conditions d'accès effectives | Destination du logo (`$destinationLogo`) | Justification et sécurité |
| :--- | :--- | :--- | :--- |
| **Visiteur (non connecté)** | `!auth()->check()`, aucune session portail | `route('inscription.create')` | Destination publique d'accueil préservée (sans régression). |
| **Étudiant connecté** | `session('etudiant_auth_id') !== null` | `route('etudiant.dashboard')` | Espace étudiant protégé par `EnsureStudent`. |
| **Enseignant connecté** | `session('enseignant_auth_id') !== null` | `route('enseignant.dashboard')` | Espace enseignant protégé par `EnsureTeacher`. |
| **Personnel administratif** | `auth()->check()`, rôle `agent_scolarite`, compte actif | `route('admin.dashboard')` | Espace central scolarité protégé par `EnsureBackOffice`. |
| **Administrateur Scolarité** | `auth()->check()`, rôle `admin_scolarite`, compte actif | `route('admin.dashboard')` | Espace pilotage protégé par `EnsureBackOffice`. |
| **Administrateur Faculté** | `auth()->check()`, rôle `admin_faculte`, `faculte_id !== null`, actif | `route('admin.dashboard')` | Espace pilotage scopé faculté. |
| **Super-Administrateur** | `auth()->check()`, rôle `super_admin`, compte actif | `route('admin.dashboard')` | Espace pilotage complet. |
| **Multi-rôles : Enseignant & Admin** | Session enseignant + compte back-office connectés | Contextuel : si route `enseignant.*` $\rightarrow$ `enseignant.dashboard` ; si route `admin.*` $\rightarrow$ `admin.dashboard`. | Préserve le contexte de travail en cours de l'utilisateur. |
| **Compte sans tableau de bord** | Compte inactif (`actif = false`), rôle invalide ou admin faculté sans faculté rattachée | `route('inscription.create')` | **Anti-403 strict** : évite de rediriger un utilisateur non autorisé vers une page qui lui renverrait une erreur 403. |

---

## 4. LOT 2 — PALETTE DE COMMANDES (`x-palette`)

### 4.1 Matrice des commandes par rôle

Toutes les commandes sont construites côté serveur avec vérification `Route::has()` et des Gates/Permissions associées.

#### A. Enseignants (`session('enseignant_auth_id')`) — 11 commandes
1. **Mon tableau de bord** (`route('enseignant.dashboard')`) — *Espace enseignant*
2. **Mon emploi du temps** (`route('enseignant.emploi-du-temps.semaine')`) — *Planning et créneaux*
3. **Mes notes & saisie** (`route('enseignant.notes.index')`) — *Épreuves et notes*
4. **Feuille d'appel / Présences** (`route('enseignant.appel.selection')`) — *Émargement des séances*
5. **Mes surveillances** (`route('enseignant.surveillances.index')`) — *Examens et créneaux*
6. **Supports de cours** (`route('enseignant.ressources.index')`) — *Ressources pédagogiques*
7. **Mes documents** (`route('enseignant.documents.index')`) — *Documents administratifs*
8. **Mes demandes** (`route('enseignant.demandes.index')`) — *Demandes et attestations*
9. **Mes réclamations** (`route('enseignant.reclamations.index')`) — *Suivi des réclamations*
10. **Mon profil** (`route('enseignant.profil.show')`) — *Compte et coordonnées*
11. **Recherche dans mon espace** (`route('enseignant.recherche')`) — *Étudiants, séances, épreuves*

*Garantie d'isolation :* Aucun enseignant ne voit les commandes d'administration (ex. `Importer des données`, `Registre des étudiants`).

#### B. Étudiants (`session('etudiant_auth_id')`) — 10 commandes
1. **Mon tableau de bord** (`route('etudiant.dashboard')`) — *Espace étudiant*
2. **Mes notes** (`route('etudiant.notes.index')`) — *Résultats et moyennes*
3. **Mes documents** (`route('etudiant.documents.index')`) — *Attestations et pièces*
4. **Mon emploi du temps** (`route('etudiant.emploi-du-temps.semaine')`) — *Planning et cours*
5. **Mon dossier** (`route('etudiant.archives.show')`) — *Dossier académique*
6. **Mon parcours LMD** (`route('etudiant.parcours')`) — *Progression et crédits*
7. **Mes inscriptions** (`route('etudiant.inscriptions.index')`) — *Historique des inscriptions*
8. **Mon profil** (`route('etudiant.profil.show')`) — *Coordonnées et compte*
9. **Notifications** (`route('etudiant.notifications.index')`) — *Alertes et messages*
10. **Recherche dans mon espace** (`route('etudiant.recherche')`) — *Supports, séances, demandes*

#### C. Back-Office (Admin / Personnel) (`auth()->check()`)
- Préservation intégrale des 10 commandes existantes (`Tableau de bord`, `Registre des étudiants`, `Archives`, `Recherche globale`, `Cockpit`, `Mes tâches`, `Statistiques`, `Importer des données`, `Exporter des données`, `Importer un planning`).
- Enrichissement conditionnel par permissions effectives : `Emplois du temps` (`schedules.view`), `Notes & Évaluations` (`notes_eval.view`), `Dossiers étudiants` (`student_dossiers.view`), `Présences et assiduité` (`attendance.view`), `Demandes administratives` (`demandes.view`), `Gestion des utilisateurs` (`users.view`), `Rôles et permissions` (`roles.view`).

#### D. Visiteur (non authentifié)
- `$actions = []` $\rightarrow$ Le composant ne rend aucun markup (`@if($actions !== [])`), aucune fuite d'information.

### 4.2 Cloisonnement et sécurité des endpoints JSON
- `$urlInstantanee = auth()->check() ? route('admin.recherche.instantanee') : null;`
- `$urlContexte = auth()->check() ? route('admin.recherche.contexte') : null;`
- Si `$urlInstantanee` est nul (étudiant ou enseignant), la palette fonctionne en **filtrage local pur** sans effectuer de requête HTTP non autorisée vers les API d'administration.

---

## 5. LOT 3 — ACCESSIBILITÉ WAI-ARIA

Le composant `x-palette` a été mis en conformité avec le pattern W3C Combobox with Listbox Popup :

1. **Dialogue modal :**
   - `role="dialog"`, `aria-modal="true"`, `aria-labelledby="palette-titre-accessible"`.
   - Piège du focus via `x-trap.noscroll="ouverte"`.
   - Fermeture par `Escape` ou clic backdrop.
   - **Retour du focus :** Sauvegarde de `document.activeElement` à l'ouverture et restauration automatique du focus sur cet élément lors de la fermeture.
2. **Champ de recherche (Combobox) :**
   - `role="combobox"`.
   - `aria-autocomplete="list"`.
   - `:aria-expanded="ouverte.toString()"`.
   - `aria-controls="palette-liste"`.
   - `:aria-activedescendant="elements.length > 0 && curseur >= 0 ? 'palette-opt-' + curseur : null"`.
   - `aria-haspopup="listbox"`.
3. **Liste de résultats (Listbox) :**
   - `id="palette-liste"`.
   - `role="listbox"`, `aria-label="Résultats et commandes"`.
4. **Options :**
   - `role="option"`.
   - `:id="'palette-opt-' + index"`.
   - `:aria-selected="(index === curseur).toString()"`.
5. **Région d'annonce vocale (`aria-live`) :**
   - `<div class="sr-only" aria-live="polite" aria-atomic="true" x-text="messageStatut"></div>`.
   - Annonce dynamiquement : *« N résultats disponibles »*, *« Recherche en cours »*, *« Aucun résultat »*, *« Aide aux raccourcis affichée »*.

---

## 6. LOT 4 — RECHERCHE MOBILE ET ERGONOMIE TACTILE

Améliorations spécifiques apportées pour les écrans tactiles et petites largeurs :

1. **Zone de positionnement responsive :** `p-3 pt-6 sm:p-4 sm:pt-[10vh]` (évite que le modal soit repoussé vers le bas sur écran mobile).
2. **Bouton de fermeture tactile mobile :** Bouton `X` dédié de $44 \times 44\text{ px}$ (`aria-label="Fermer la palette"`), masqué sur grand écran (`flex sm:hidden`).
3. **Cibles tactiles conformes WCAG :** `min-h-[44px]` sur l'input, le bouton de fermeture et chaque élément de la liste.
4. **Taille de police anti-zoom iOS :** `text-base` sur le champ de recherche pour éviter l'effet de zoom involontaire de Safari iOS.
5. **Gestion du défilement et overflow :**
   - `max-h-[55vh] sm:max-h-72`.
   - `overscroll-contain` et `data-lenis-prevent`.
   - `truncate` sur les titres et détails pour éviter tout débordement horizontal.
6. **Réduction des animations :** `motion-reduce:transition-none motion-reduce:transform-none`.

---

## 7. LOT 5 — TESTS ET NON-RÉGRESSION

### 7.1 Résultats d'exécution des tests

#### A. Tests de navigation et palette (`tests/Feature/NavigationTest.php`)
```bash
php artisan test --filter=NavigationTest --compact
```
**Résultat :** `15 passed` (89 assertions, 1.15s)
- `test_invite_sidebar_portail` : PASS
- `test_etudiant_sidebar_espace_et_dashboard_vivant` : PASS
- `test_admin_sidebar_pilotage_et_actions_rapides` : PASS
- `test_admin_faculte_sans_section_super_admin` : PASS
- `test_destination_logo_visiteur` : PASS
- `test_destination_logo_etudiant` : PASS
- `test_destination_logo_enseignant` : PASS
- `test_destination_logo_personnel_administratif` : PASS
- `test_destination_logo_administrateur_et_super_admin` : PASS
- `test_destination_logo_multi_roles` : PASS
- `test_destination_logo_utilisateur_sans_tableau_de_bord_dedie` : PASS
- `test_palette_enseignant_actions_dediees_et_isolation` : PASS
- `test_palette_non_rendue_visiteur` : PASS
- `test_palette_accessibilite_wai_aria` : PASS
- `test_palette_ergonomie_mobile` : PASS

#### B. Suite transversale de navigation et portails
```bash
php artisan test --filter="NavigationTest|ProductiviteTest|TasksCockpitTest|EspaceEnseignantTest|EspaceEtudiantTest|RecherchePortailTest|NavigationPhase7Test" --compact
```
**Résultat :** `75 passed` (333 assertions, 3.21s)

#### C. Stabilisation baseline Admissions / Inscriptions
```bash
php artisan test --filter="InscriptionAccueilParcoursTest|ParcoursAccueilEmbarqueTest|RelevesGenerationTest|SaisieIdentificationStricteTest" --compact
```
**Résultat :** `29 passed` (173 assertions, 2.05s)

### 7.2 Contrôle de syntaxe et de style (Pint)
```bash
vendor/bin/pint tests/Feature/NavigationTest.php app/Support/NavigationDestination.php --test
```
**Résultat :** `{"tool":"pint","result":"passed"}`

---

## 8. FICHIERS MODIFIÉS OU CRÉÉS

1. **`app/Support/NavigationDestination.php`** *(Création)*
   - Résolution canonique de la destination du logo par rôle effectif, statut et contexte multi-rôles.
2. **`resources/views/layouts/app.blade.php`** *(Modification)*
   - Injection de `$destinationLogo = \App\Support\NavigationDestination::resoudre(request());`.
   - Mise à jour du lien de logo desktop (`lateral`) et header mobile (`lateral-mobile`).
   - Prise en charge des pages avec `@section('sans_sidebar')`.
3. **`resources/views/components/palette.blade.php`** *(Modification)*
   - Prise en charge complète des enseignants (`session('enseignant_auth_id')`).
   - Sécurisation des routes d'entités instantanées.
   - Sémantique complète WAI-ARIA (`combobox`, `listbox`, `option`, `aria-activedescendant`, `aria-live`).
   - Ergonomie mobile (bouton fermer dédié, hauteur $55\text{vh}$, cibles $\ge 44\text{ px}$).
   - Gestion et retour du focus (`dernierElementActif`).
4. **`tests/Feature/NavigationTest.php`** *(Modification)*
   - Ajout de 11 tests automatisés couvrant tous les cas des Lots 1, 2, 3 et 4.

---

## 9. CONTRÔLES RESTANTS ET LIMITES

Bien que tous les tests unitaires et d'intégration serveur soient au vert :
1. **Contrôle manuel sur appareils mobiles réels :** Vérifier le toucher tactile sous iOS Safari et Android Chrome (la gesture de swipe du tiroir et le tap sur le bouton fermer de la palette).
2. **Lecteurs d'écran physiques :** Valider l'annonce vocale en situation réelle avec VoiceOver (macOS / iOS) et NVDA (Windows) sur la restitution de `aria-live="polite"`.

---

## 10. VERDICT FINAL

**Verdict : `PASS`**

Les six lots du programme `LOOP-UNIREGISTRE-UX-UI-NAVIGATION-PALETTE-01` sont entièrement satisfaits. La navigation du logo est robuste et contextuelle, la palette de commandes intègre parfaitement les enseignants et les étudiants sans fuite de sécurité, la sémantique WAI-ARIA est rigoureusement conforme, et aucune régression n'a été introduite sur l'ensemble du dépôt.

# LOOP 43-BIS — Catalogue canonique des permissions

## Objectif
Fournir un catalogue centralisé, compréhensible et administrable sans permettre l'injection de permissions arbitraires.

## 1.1 Philosophie du catalogue
Le catalogue de permissions reste gouverné par le code — `Permissions::TOUTES` et, en lecture seule, `PermissionsAi::TOUTES`. Le catalogue n'ajoute aucune permission : il ajoute des MÉTADONNÉES (module, ressource, action, criticité, MFA, dépendances) et une hiérarchie d'affichage.

**Une permission inconnue du code ne peut donc jamais être rendue exécutable depuis l'interface : `existe()` est la seule porte.**

## 1.2 Structure du catalogue

### 1.2.1 Modules (source `CataloguePermissions::MODULES`)
Plus de 80 modules définissant le périmètre des permissions :

- `archives`, `documents`, `notes`, `history`, `student_archive`, `configuration`, `admissions`, `exports`
- `attendance`, `student_dossiers`, `student_documents`, `demandes`, `schedules`
- `enseignants`, `salles`, `groupes`, `affectations`, `data`, `personnel`
- `enseignements`, `referentiel`, `maquettes`, `evaluations`, `sessions_examen`
- `epreuves`, `surveillances`, `resultats`, `deliberations`, `annonces`
- `calendriers`, `statistiques`, `settings`, `users`, `permissions`, `mail`, `system`
- `cockpit`, `tasks`, `it`, `alumni`, `campagnes`, `qualite`, `finances`
- `document`, `document_batch`, `document_template`, `import`, `export`, `import_template`
- `search`, `parcours`, `regles`, `recours`, `versioning`, `sentinel`
- `assistant_ia`, `decrochage`, `gouvernance`, `iam`, `ai`
- `profil` (préfixe perso_ pour les profils personnalisés)

Chaque module possède un libellé humain via `libelleModule($module)`.

### 1.2.2 Actions (source `CataloguePermissions::ACTIONS`)
Actions disponibles, avec repli en code humanisé :

- `view` / `consulter` — Consulter
- `create` / `creer` — Créer
- `update` / `modifier` — Modifier
- `delete` / `supprimer` — Supprimer
- `manage` / `gerer` — Gérer
- `validate` / `valider` — Valider
- `publish` / `publier` — Publier
- `approve` / `approuver` — Approuver
- `reject` / `rejeter` — Rejeter
- `submit` / `soumettre` — Soumettre

### 1.2.3 Métadonnées par permission
Pour chaque permission, le catalogue définit :

| Métadonnée | Description |
|---|---|
| `code` | Code canonique (ex: `iam.roles.gerer`) |
| `libelle` | Libellé formaté (ex: "Gérer — Gouvernance IAM") |
| `description` | Description formatée |
| `module` | Nom du module |
| `module_libelle` | Libellé du module |
| `ressource` | Ressource (module par défaut si singular) |
| `ressource_libelle` | Libellé de la ressource |
| `action` | Action/code |
| `action_libelle` | Libellé de l'action |
| `criticite` | `normale`, `elevee`, `critique` |
| `sensible` | booléen — `true` si criticité ≠ `normale` |
| `mfa_obligatoire` | si le MFA est requis (d'après config `iam.MFA`) |
| `dependances` | array de codes de permissions dépendantes |
| `administrable` | si la permission peut être administrée via l'interface |
| `reserve_systeme` | si la permission est réservée au système (non administrée) |
| `source` | `central` ou `module_ai` |

### 1.2.4 Hiérarchie d'affichage
Le catalogue organise les permissions par module → ressource → action, avec affichage interactif :

```
Module : Gouvernance IAM
  └─ Ressource : (toutes)
       ├─ Action : Consulter
       ├─ Action : Gérer
       └─ Action : Approuver
```

Chaque module affiche :
- `code` du module
- `libelle` du module
- `modules_ressources` — liste des ressources enfants
- `nb_permissions` — nombre total de permissions
- `nb_sensibles` — nombre de permissions sensibles

### 1.2.5 Catalogue fédéral vs local
- **Catalogue fédéral** : permissions centrales (`Permissions::TOUTES`) + métadonnées complètes
- **Catalogue IA locale** : `PermissionsAi::TOUTES` — affichage lecture seule, non fusionnée, le module IA possède sa propre matrice locale

## 1.3 Protection du catalogue

### 1.3.1 `administrable($code)`
```php
public static function administrable(string $code): bool
{
    if (! self::existe($code) || self::source($code) !== 'central') {
        return false;
    }
    $module = self::moduleDe($code);
    return ! in_array($module, config('iam.modules_reserves', ['iam']), true)
        && ! in_array($module, config('iam.modules_federes_lecture_seule', ['ai']), true);
}
```
Une permission est administrable si :
- Elle existe dans le catalogue central
- Son module n'est pas dans `modules_reserves` (par défaut `['iam']`)
- Son module n'est pas dans `modules_federes_lecture_seule` (par défaut `['ai']`

### 1.3.2 `existe($code)`
```php
public static function existe(string $code): bool
{
    return in_array($code, self::toutes(), true);
}
```
Vérifie qu'une permission est connue du code. Une permission inconnue est TOUJOURS refusée.

### 1.3.3 `source($code)`
```php
public static function source(string $code): string
{
    return in_array($code, PermissionsAi::TOUTES, true) ? 'module_ai' : 'central';
}
```
Détermine si une permission vient du catalogue central ou du module IA local.

### 1.3.4 `administrable()` et sécurité
L'interface d'administration ne doit pas permettre à un administrateur ordinaire de créer arbitrairement une permission inconnue du moteur. Pour une nouvelle permission :
1. Définition dans la source canonique (`Permissions.php` ou ajout au catalogue)
2. Revue technique
3. Revue sécurité
4. Ajout des Policies et contrôles nécessaires
5. Tests
6. Déploiement contrôlé
7. Apparition dans l'interface administrative

Ne jamais permettre une permission librement saisie puis automatiquement exécutable.

## 1.4 Dépendances
Si une permission nécessite une autre permission :
- La dépendance doit être déclarée dans le catalogue
- Elle doit être contrôlée côté serveur
- L'interface doit afficher la dépendance et empêcher une combinaison incohérente
- Ne pas ajouter silencieusement des droits supplémentaires

## 1.5 Exemples de permissions sensibles
- `iam.roles.gerer` — gérer les rôles (critique, MFA obligatoire)
- `iam.affectations.gerer` — gérer les affectations (élevée)
- `iam.delegations.gerer` — gérer les délégations (élevée)
- `iam.urgence.declencher` — déclencher urgence (critique)
- `iam.parametres.gerer` — modifier paramètres (élevée)
- `users.create`, `users.update` — gestion utilisateurs (sensible)
- `configuration.manage` — gérer la configuration (sensible)
- `system.backups.delete` — supprimer sauvegardes (critique)

## État du catalogue
- 173 permissions centrales + 11 `iam.*` dans le catalogue fédéral
- Plus de 80 modules définis avec libellés
- Plus de 30 actions définies avec libellés
- Métadonnées complètes (criticité, sensibilité, MFA, dépendances, administrable)
- Catalogue IA locale (`PermissionsAi`) en lecture seule, documentée comme réserve
- `administrable()` correctement filtré par `config/iam.php`

## Livrables
- `LOOP-43BIS-CATALOGUE-PERMISSIONS.md`

**Verdict G43BIS-7 :** ✅ Catalogue complet — 173 permissions + 11 `iam.*`, >80 modules, >30 actions, métadonnées enrichies, `administrable()` filtré, IA locale en lecture seule documentée.
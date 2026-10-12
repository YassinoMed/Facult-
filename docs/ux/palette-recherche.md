# Palette + recherche (Phase 7)

## Registre

`SearchRegistry` (`app/Services/Recherche/`, 13 providers, singleton) :
point unique d'extension — page globale, instantanée et API future
consomment les mêmes providers (mêmes gates, mêmes périmètres).
Ajouter une entité = 1 provider + 1 ligne d'enregistrement
(`AppServiceProvider`), jamais de requête ad hoc.

## Palette (Ctrl/⌘K)

Modes : libre (actions + entités), `>` commandes seules, `?` aide.
Curseur unifié actions + résultats, AbortController (requêtes
obsolètes annulées), états chargement/erreur, requête vide →
favoris + récents personnels (`recherche.contexte`). Saut direct :
CIN / n° inscription / référence exacte → fiche (302 scopé).

## Actions rapides

`QuickActionRegistry` (`app/Support/Actions/`) : `{key, label, icon,
contextes, visible, url}` — permission + périmètre + route existante.
`<x-quick-actions contexte sujet>` (dossier étudiant, groupe). Actions
sans route : enregistrées mais masquées (phases ultérieures).

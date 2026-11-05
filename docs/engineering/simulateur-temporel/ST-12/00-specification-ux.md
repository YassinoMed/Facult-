# ST-12 — 00 · Spécification UX

**Principe :** améliorer la présentation SANS reconstruire le moteur, SANS supprimer une fonctionnalité, SANS nouvelle bibliothèque (Blade + Alpine.js + design system existant).

## O1 · Identité visuelle — conservée

Design system UniRegistre (Tailwind, palette primary/slate/emerald/rose, `x-alert`, composants rounded-2xl) — **aucune bibliothèque graphique ajoutée**. Le graphique SVG existant est conservé.

## O2 · Navigation — conservée (5 onglets) + sous-sections existantes

| Onglet | Contenu |
|---|---|
| Trajectoire | KPI (initial/final/score/écart), graphique SVG référence vs meilleure variante, tableau comparatif |
| Capacités | Effectifs vs capacité, groupes requis, années en déficit |
| Sensibilité & Pareto | élasticités OAT + frontière de Pareto (solutions non dominées) |
| Monte-Carlo | N, graine, P10/médiane/P90, min/max, moyenne, écart-type, fréquence dépassement |
| Gouvernance, Qualité & IA | audit qualité + alertes, circuit 4 yeux, assistant IA |

## Corrections UX apportées dans cette campagne (additives, ciblées)

| ID | Écart (audit) | Correction | État |
|---|---|---|---|
| P6 | **Les boutons Approuver/Rejeter étaient visibles par l'auteur de la proposition** (le service refuse, l'UI ne masquait pas) | Masquage Alpine (`propose_par_id !== utilisateurId`) + rappel explicite « votre proposition attend un second acteur habilité » ; injection `utilisateurId` dans le composant | ✅ implémenté + testé (ST12-UX-014) |
| P8 | Mention budgétaire présente mais en petit texte peu visible | **Bandeau ambre distinctif** : « ⚠️ Estimation indicative — sans valeur comptable : … non des dépenses ou recettes réelles » | ✅ implémenté + testé (ST12-UX-014) |

## Spécifications conservées (déjà conformes à l'audit)

- **En-tête/stepper** : étapes 1→4 (périmètre → paramètres → exécution → résultats), états visuels, disabled tant que non atteint.
- **Bannière permanente** : « Simulation uniquement — aucune décision réelle n'est appliquée » (testée).
- **Confirmation** : exécution en deux opérations distinctes (création puis exécution) ; double-clic protégé (`enCours`).
- **Erreurs** : messages métier français (`e.message` des DomainException), jamais de trace PHP.
- **Cascade AJAX** : niveaux → groupes préservée (endpoints dynamiques existants, logique métier côté serveur).
- **États d'interface** : chargement (`chargementNiveaux/Groupes/Gov/Ia`), erreur (`erreur`, `erreurIa`, `erreurGov`), succès (`messageGov`), vide (résultat null → écran de configuration).

## O5/O6 · Respect des contraintes

- Endpoints AJAX existants non modifiés (urlsNiveaux/urlsGroupes/creer/executer/…).
- Aucune logique métier déplacée dans Alpine (formule somme des taux = contrôle d'affichage ; le contrôle **serveur** reste dans `validerCriteres`).
- Aucune suppression d'onglet/action/filtre.

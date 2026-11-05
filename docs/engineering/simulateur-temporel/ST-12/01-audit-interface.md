# ST-12 — 01 à 05 · Audit interface, composants, navigation, accessibilité (consolidé)

## 01 · Audit de l'interface existante (avant refonte)

| Élément | Constat | Verdict |
|---|---|---|
| Vue principale | 1 480 lignes, mono-fichier avec composant Alpine `simulateur()` | structurée en sections (stepper, onglets, sous-sections) — découpage partiel possible mais non exigé (responsabilités claires par section) |
| Bannière institutionnelle | permanente, `x-alert` warning | ✅ conforme |
| 5 onglets | trajectoire/capacités/sensibilité+Pareto/Monte-Carlo/gouvernance+qualité+IA | ✅ conformes (Pareto, budget, IA, qualité en sous-sections) |
| Budget | chiffres affichés, mention discrète | ⚠️ renforcée (P8 — bandeau visible) |
| Gouvernance | formulaires proposition + décision | ⚠️ boutons de décision visibles par l'auteur (P6 — corrigé) |
| Accessibilité | labels, aria-labels, focus rings, `prefers-reduced-motion` géré | ✅ base solide |

## 02 · Maquette fonctionnelle (diffs réels appliqués)

1. **Bloc décision gouvernance** : deux états distincts — (a) acteur ≠ auteur → formulaire motif + Rejeter/Approuver ; (b) acteur = auteur → rappel d'attente ambré (« vous ne pouvez pas statuer votre propre proposition »).
2. **Bloc budget** : bandeau ambre au-dessus des 3 KPI (coût/Recettes/Solde) : « Estimation indicative — sans valeur comptable ».

## 03 · Composants et vues

- Aucune nouvelle vue ; modifications **ciblées** dans `index.blade.php` (+18 lignes) : injection `utilisateurId`, 2 templates x-if supplémentaires (masquage + rappel), bandeau budget.
- `pdf.blade.php` : inchangé (le PDF porte déjà le contexte scénario/version/exécution ; mention simulation présente côté contrôleur/vues).

## 04 · Navigation et parcours (R5)

Parcours vérifié par les 52 tests : accès (module off → 404) → création (étapes 1-3, validations) → exécution (idempotence, graines) → résultats (5 onglets) → export CSV/PDF → gouvernance (proposition → décision par un second) → historique (exécutions index/détail).

## 05 · Accessibilité et responsive (Q1/Q2)

- Conservés : `aria-label` sur les boutons d'action, `aria-live` régions, focus-visible rings, navigation clavier native (buttons), responsive grid (`sm:grid-cols-*`), `prefers-reduced-motion` (CSS local).
- Le nouveau bandeau budgétaire : texte contrasté ambre-900 sur ambre-50 (WCAG AA) ; non dépendant de la couleur seule (icône ⚠️ + texte souligné).
- Audit UX approfondi (parcours multi-écrans réel) : reste à une campagne dédiée — réserve R-07 (inchangée).

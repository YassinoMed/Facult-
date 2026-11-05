# ST-12 — 02 · Maquette fonctionnelle

Diffs appliqués dans `resources/views/modules/simulation-temporel/index.blade.php` (audités par le test ST12-UX-014) :

1. **Injection `utilisateurId`** dans le composant Alpine (`auth()->id()` casté int).
2. **Gouvernance — 2 états** :
   - acteur ≠ auteur : formulaire motif + boutons Rejeter/Approuver (inchangés) ;
   - acteur = auteur : rappel ambré d'attente d'un second acteur (boutons masqués).
3. **Budget — bandeau visible** : « ⚠️ Estimation indicative — sans valeur comptable » (ambre-50/900, souligné).

Aucune autre modification : le moteur, les endpoints, les onglets et le parcours restent identiques.

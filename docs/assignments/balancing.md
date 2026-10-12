# Équilibrage

1. **Contraints d'abord** : tri (options admissibles ↑, id ↑).
2. **Affectation** : groupe admissible le moins rempli ; tie-break
   (effectif simulé ↑, places restantes ↓, id ↑). Aucun random.
3. **MOVE** : Gmax → Gmin si admissible, jusqu'à écart ≤ 1
   (max 5000 itérations, terminaison garantie).
4. **SWAP** : échange mutuellement valide si MOVE impossible
   (max 2000 paires testées).

Métriques affichées (jamais de score magique) : minimum, maximum,
moyenne, écart max, capacité utilisée %, capacité restante.

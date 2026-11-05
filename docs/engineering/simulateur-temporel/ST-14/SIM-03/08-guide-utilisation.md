# SIM-03 — 08 · Guide d'utilisation et interprétation

**Scénario :** SIM-03 — Gel des admissions  
**Module :** Simulation Temporelle  
**Public cible :** Doyens, Vice-Doyens aux études, Directeurs pédagogiques, Planificateurs stratégiques  

---

## H.1 Finalité métier

Le scénario **SIM-03** modélise une interruption délibérée des flux d'admission en première année (ex: année blanche pour travaux, suspension d'habilitation, moratoire pédagogique).

Il permet de répondre aux interrogations suivantes :
- À quelle vitesse les effectifs globaux diminuent-ils en l'absence de renouvellement de la base ?
- Comment les promotions déjà engagées (L2, L3) progressent-elles jusqu'à leur diplomation ?
- Quelle est la capacité d'accueil libérée au bout de 1, 2 ou 3 ans de gel ?
- Quels sont les effets d'une réouverture ultérieure (au même niveau, en croissance ou en baisse) ?

---

## H.2 Paramètres clés

1. **Durée du gel (`duree_gel_annees`)** :
   - Nombre d'années complètes durant lesquelles aucune nouvelle admission n'a lieu.
   - Doit être compris entre `1` et `horizon_annees`.
   - Si `duree_gel_annees = horizon_annees`, le gel est effectif sur toute la durée de la projection.
2. **Horizon temporel (`horizon_annees`)** :
   - Durée de simulation (de `1` à `6` ans).
3. **Taux de reprise (`delta_admissions`)** :
   - Si l'horizon dépasse la durée du gel, ce taux régit la dynamique des admissions lors de la réouverture.
   - `0.0` : réouverture au niveau d'admissions initial ($baseL1$).
   - `> 0` : reprise progressive en croissance.
   - `< 0` : reprise avec contingentement réduit.
4. **Taux de transition (`taux`)** :
   - `passage`, `redoublement`, `abandon` (obligatoires).

---

## H.3 Avertissement institutionnel

> **Outil d'aide à la décision :**  
> Les projections issues de ce scénario sont purement indicatives. Aucune fermeture de filière, suspension d'inscription ou suppression de groupe n'est exécutée sur le système d'information académique réel.


# LOOP EX-LIFECYCLE — 11 · Validation fonctionnelle

## Critères d'acceptation — état réel

### A. Fonctionnels
- [x] Création conforme (état initial `draft` forcé serveur, historisée) — EX-LC-001/002/005
- [x] Consultation conforme (listing + périmètre) — suites existantes, EX-LC-007
- [x] Modifications contrôlées (aucune route générique d'état ; champs protégés par flux dédiés) — EX-LC-023 par construction
- [x] États existants documentés (9 + 6 + 7, automate effectif) — `00`/`04`
- [x] Transitions documentées — `04-regles-de-transition.md`
- [x] Transitions valides appliquées — EX-LC-008
- [x] Transitions invalides refusées — EX-LC-009/010/011/012/013/018
- [x] Annulation contrôlée — EX-LC-047/051
- [x] Réouverture : **explicitement non prise en charge** (D-01 documenté, matrice refuse)
- [x] Verrouillage cohérent (garde critique + session verrouillée + verrou de ligne)
- [x] Préconditions explicites (audit contraintes dures, gardes service)
- [x] Audit de conformité explicable (endpoint existant + prévisualisation nouvelle)

### B. Architecture
- [x] Moteur de transition central unique (`ExamenService`)
- [x] Pas de logique métier dupliquée (gouvernance inchangée, appelle le moteur)
- [x] Aucun contournement par écriture directe (9 sites qualifiés, `00` §5.3)
- [x] Séparation examen/session/épreuve conservée
- [x] Cohérence gouvernance (empreinte, circuit, double approbation) — 198 assertions
- [x] Transactions vérifiées (écriture état + journal atomiques)
- [x] Concurrence maîtrisée (`lockForUpdate` aligné sur la convention des services sœurs)
- [x] Idempotence démontrée (EX-LC-011/047 + idempotence publication existante)

### C. Sécurité
- [x] Permissions vérifiées côté serveur (aucun wildcard supposé)
- [x] Isolation facultaire testée — EX-LC-016/017/007
- [x] IDOR/BOLA testés (état inchangé après refus)
- [x] Protection des transitions sensibles (publication = circuit exclusif)
- [x] Contrôles API/Livewire (aucun chemin d'écriture recensé — `02` §5 chemins)
- [x] Protection des états terminaux (garde critique + matrice vide)
- [x] Protection de l'historique (append-only au niveau modèle — EX-LC-055)
- [x] Activation du module vérifiée (aucune régression, mécanisme inchangé)

### D. Traçabilité
- [x] État avant/après, acteur, date, motif (facultatif), résultat — `evaluation_examen_evenements`
- [x] Identifiant de corrélation : `EvaluationChanged` (entite_id) + lignes d'événements ordonnées
- [x] Historique cohérent (écriture unique en transaction — EX-LC-051/056)

### E. Qualité
- [x] Tests ciblés exécutés (19/19)
- [x] Tests de sécurité exécutés (isolation/IDOR/journal — inclus)
- [x] Non-régression exécutée (241 tests, 0 échec)
- [x] Tests incomplets expliqués (4 ignorés = pcntl_fork, préexistant)
- [x] Pint exécuté (suite relancée après correction)
- [x] Documentation actualisée (13 livrables)
- [x] Aucun effet sur la production (aucun commit/push/reset/migration destructive)

## Conditions de validation

- **Techniquement validé** : le moteur durci, l'historisation et la prévisualisation sont prêts pour activation en préproduction contrôlée.
- **Conditionné** : D-02 (motif obligatoire), D-03 (notifications), D-04 (propagation d'annulation), D-05 (complétude) restent ouverts — aucune de ces évolutions n'est active.
- **À ne pas activer** : toute réouverture post-publication (D-01) tant que la règle institutionnelle n'est pas tranchée.

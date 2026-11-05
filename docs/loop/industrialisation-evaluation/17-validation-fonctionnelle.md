# LOOP INDUSTRIALISATION ÉVALUATION — 17 · Validation fonctionnelle

## Critères d'acceptation — état réel

### Fonctionnels
- [x] Saisie individuelle/lot/anonyme contrôlée (barème, bornes, absences, doublons, rollback) — suites notes 144 tests
- [x] Vérification distincte (permission + états + verrous) — NOTE-015/016 ✅, NOTE-017 = D-11 documenté
- [x] Correction contrôlée (3 étapes, motifs, historique avant/après, déverrouillage autorisé) — NOTE-018/019/021/022 ✅
- [x] Verrouillage serveur (note épreuve + note officielle) — NOTE-014 ✅
- [x] Résultats : provisoire vs publié distingués ; publication = décision officielle — INT-005/006/007 ✅
- [x] Consultation autorisée (étudiant = résultats publiés ; enseignant = périmètre) — ✅
- [x] Historique protégé (append-only) — **corrigé + testé (SEC-012)** ✅
- [x] Imports : analyse → prévisualisation → confirmation → rapport ; échec = aucun état partiel — ✅ (perf vérifiée)

### Sécurité
- [x] Permissions serveur sur chaque route (Gates + Policies) — P0-01 ✅
- [x] Isolation facultaire + IDOR/BOLA (effets en base vérifiés) — P0-06 ✅
- [x] Pas de contournement : contrôles doublés contrôleur+service ; jobs re-vérifient l'état — SEC-008/015 ✅
- [x] Aucune permission créée/élargie ; aucune élévation possible — ✅
- [x] Secrets absents des journaux — SEC-020 ✅

### Traçabilité
- [x] Avant/après + auteur + motif + date sur toute modification de valeur — P0-03/P0-04 ✅
- [x] 4 journaux distincts (technique, métier, décisions, intégrations) — ✅

### Qualité
- [x] Campagne : **257 tests / 0 échec / 1018 assertions** ; Pint OK — ✅
- [x] Tests incomplets expliqués (30 ignorés LoopCampagne = fixtures dédiées manquantes ; 4 pcntl = environnement) — ✅
- [x] Documentation = code (19 livrables) — ✅
- [x] Aucun effet production ; aucune donnée officielle modifiée — ✅

## Décision d'exploitation

| Fonction | Statut |
|---|---|
| Chaîne notes (saisie→validation→correction→verrou→résultats→publication) | **Prête techniquement + validée en sécurité** |
| Garde append-only historique | **Prête** (aucun DDL, rollback code trivial) |
| D-11 (auto-validation par personne) / D-12 (rectification post-publication) | **En attente de décision** — non activées |
| Analyses IA avancées (P2-01) | **Non développées** (soumises à validation métier) |

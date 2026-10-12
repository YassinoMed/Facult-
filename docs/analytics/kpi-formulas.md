# Formules KPI (Phase 10 §10.6-10.7)

- `taux_passage` = décisions `publie` ∧ decision ∈ {admis,
  admis_compensation, admis_conditionnel} / décisions `publie` × 100
  (1 décimale, null si 0 décision). Périmètre : année (via inscription),
  faculté (via étudiant), programme (via inscription).
- `redoublements` = inscriptions `type=reinscription` ∧ observation
  `LIKE 'Redoublement%'` (état explicite Phase 6 §30).
- `credits_validation` = Σ obtenus / Σ total × 100 (1 décimale).
- `moyenne_mediane` = médiane basse des `moyenne_annuelle` non nulles.
- `volumes_enseignement` = COUNT + Σ volumes (COALESCE, NULL→0),
  modules inactifs → null (jamais 0 trompeur).
- `documents_genere` = valides / révoqués (registre central).
- Arrondis : 1 décimale (%, médiane 2) ; nulls propagés, jamais 0 par
  défaut. Historique : règles versionnées (snapshot délibération) —
  jamais de recalcul silencieux.

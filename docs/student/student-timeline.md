# Timeline (Phase 8 §9)

Source unique : `ArchiveTimelineRegistry` (8 providers : inscriptions,
situations, groupes, délibérations, documents, demandes, absences +
sensible isolé). Tri desc + `limite ≤ 100` + `offset` (jamais
d'histoire complète, §108). `FicheEtudiantService::timeline` délègue
et mappe la forme legacy {date,titre,detail} (+offset). Exposée web
(onglet, limite 20), API `students/{id}/timeline` (filtre catégorie,
meta total/limite/offset), jamais de seconde source.

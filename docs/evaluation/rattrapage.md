# Évaluation — rattrapage (Phase 5 §43)

Relation explicite : `evaluation_sessions.session_origine_id` → sessions
`principale` (même année, `restrictOnDelete`, jamais de cascade
silencieuse). Règles serveur (`StoreSessionExamenRequest::withValidator`) :
`rattrapage` exige une origine `principale` de la même année ;
`principale`/`exceptionnelle` n'ont pas d'origine.

Notes conservées par session (`notes.session` normale|rattrapage, règle
`notesValideesParMatiere` : le rattrapage remplace sans écraser
l'historique — lignes distinctes, UQ semestre+matière+session).
Éligibilité au rattrapage proposée en délibération (seuil), reprise
manuelle motivée — aucune décision automatique dispersée.

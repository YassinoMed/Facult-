# Évaluation — convocations (Phase 5 §29)

Deux documents, un seul moteur (`PdfGeneratorInterface`, dompdf) :
liste d'émargement collective (`ConvocationService::listeEmargement`,
layout `pdf/liste`, trace `export.emargement`) et convocation
individuelle (`ConvocationService::convocation`, vue
`modules.evaluation.pdf.convocation`, trace `export.convocation`).

Contenu individuel : identité (nom/prénom/num_inscription — jamais de
CIN complète), matière, type, session, date, horaire, salle. Périmètre =
Gate `view` sur l'épreuve (cohérent avec l'émargement), 403 hors
périmètre. Aucun stockage persistant, aucun second moteur.

Non implémentés (décisions documentées) : anonymisation des copies
(conditionnelle §32, mécanisme de confidentialité à concevoir — pas un
champ UI), PV de délibération et relevé PDF (exports existants
suffisants), saisie individuelle (le lot couvre le cas unitaire),
endpoints API dédiés (lecture notes + `data/*` générique).

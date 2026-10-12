# PDF / Impression (§24-26 + Phase 2 §2.1-2.6)

Moteur : `PdfGeneratorInterface` (Dompdf) + vue `pdf/liste` (layout
`modules.student-dossiers.pdf.layouts.document` réutilisé — aucun 2e moteur).

## Regroupement générique (§2.1-2.2)

`ListeEtudiantsService::REGROUPEMENTS` (`programme, niveau, groupe,
sous_groupe, statut, faculte, annee, semestre, matiere` ; alias `parcours →
programme`, `module/ecue → matiere`) + `sectionsRegroupees()` : UN seul
`chunk(1000)` sur la MÊME query, sections triées, numérotation continue,
effectif par section. Aucun `if group/if program` dans les exports :
`cleRegroupement()` centralise. Multi-niveaux = exports successifs (une clé
par document — assumé, documenté). PDF « groupes + étudiants » =
`regroupement=groupe` (lien direct depuis `admin.groupes.index`).

## Page de garde / métadonnées (§2.3-2.4)

`ListesController::metadonnees()` : établissement, faculté, département,
année, programme, parcours, niveau, semestre + titre + filtres lisibles
(`contexteDetails()` : libellés résolus, jamais d'IDs) + date, utilisateur,
référence `EXP-YYYY-XXXXXX` réutilisée, nb résultats, regroupement.

## Pagination (§2.5)

Layout : `thead{display:table-header-group}` (répété), `tr{page-break-inside:
avoid}`, `.section-titre{page-break-after:avoid}` (titre + premières lignes
ensemble), pied fixe `Page X/Y` + référence.

## Options (§2.6)

Orientation `auto|portrait|paysage` (`auto` = paysage si >6 colonnes) ;
colonnes via `ExportColumnPolicy` (sensible jamais sans permission) ;
`emargement` (colonne Signature), `zone_signature` (encadré responsable).

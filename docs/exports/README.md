# Moteur central d'export et d'impression

## Architecture

```text
ListeEtudiantsService (requête scopée + filtres + tri)
        ↓ population unique
ListesController (preview = export)
        ↓ CSV / XLSX / PDF / print
IoFactory::serializerPour + PdfGeneratorInterface
```

- Prévisualisation et export partagent **exactement** la même requête
  (`requete()` + `jeuDonnees()`), pagination mise à part.
- PDF institutionnel : layout `modules.student-dossiers.pdf.layouts.document`
  (orientation auto), vue `pdf/liste.blade.php` (contexte, signature optionnelle).
- PDF générique : `PdfSerializer` pour toutes les entités DataExchange
  (`format=pdf` ajouté aux 15 définitions + routes web/API).
- Impression : vue `admin.listes.imprimer` + `@media print` (uikit, navbar,
  formulaires masqués), bouton `window.print`.
- Profils : modèle `ExportTemplate` réutilisé (`type=listes`).
- Historique : `IntegrationLog` (`export.listes`, request_id) ; expiration
  des exports via `exports:purge` existant.
- Massif : curseur + plafond 10 000 (CSV/XLSX) ; PDF plafonné à 1 000 lignes
  avec message explicite (pas de PDF silencieux).

## Datasets

Listes étudiants multi-axes (année, faculté scopée serveur, programme,
niveau, semestre, groupe, UE, ECUE, enseignant via inscriptions
pédagogiques, statut, sans-groupe, recherche) + tri (nom, prénom,
matricule, groupe, niveau) + émargement (colonne signature, PDF/print).

## Sécurité

`archives.view` (prévisualisation) + `data.export` (export) + catalogue
`ExportColumnPolicy` : CIN masquée sans `exports.personal`, email/téléphone
retirés, colonnes inconnues ignorées. Isolation faculté re-scopée serveur
(manipulation `faculty_id`/`groupe_id` testée). Audit `export.listes`.

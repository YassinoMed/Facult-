# 📚 Module Archives Académiques

Dossier académique historique complet de chaque étudiant : documents administratifs,
parcours universitaire, inscriptions, semestres, matières, notes, coefficients,
crédits, moyennes, décisions, observations et journal d'audit.

---

## 1. Architecture

```
Etudiant
   ├── DossierAcademique (1-1)                [archives_etudiants]
   ├── InscriptionsAcademiques (1-n)          [inscriptions_academiques]
   │     ├── belongsTo AnneeUniversitaire     [annees_universitaires]
   │     ├── Semestres S1/S2 (1-n)             [semestres]
   │     └── Notes (1-n)                      [notes]
   │           └── belongsTo Matiere          [matieres]
   ├── DocumentsAcademiques (1-n)             [documents_academiques]
   └── HistoriqueAcademique (1-n)             [historique_academique]
```

**Règle architecturale respectée** : aucune table « fourre-tout » — le modèle est
relationnel normalisé, chaque niveau (année → semestre → note) porte ses propres
agrégats (moyennes, crédits, décisions recalculés par le service).

### Couche applicative

| Couche | Fichiers |
|---|---|
| Modèles | `app/Models/{DossierAcademique,AnneeUniversitaire,InscriptionAcademique,Semestre,Matiere,Note,DocumentAcademique,HistoriqueAcademique}.php` |
| Services | `app/Services/AcademicCalculationService.php` (calculs purs), `ArchiveService.php` (orchestration + transactions), `DocumentService.php` (stockage sécurisé), `AuditService.php` (journal) |
| Policies | `app/Policies/{ArchivePolicy,InscriptionAcademiquePolicy,NotePolicy,DocumentAcademiquePolicy}.php` |
| RBAC | `app/Support/Permissions.php` (matrice rôle → permissions, Gates enregistrés dans `AppServiceProvider`) |
| Form Requests | 12 classes dédiées dans `app/Http/Requests/` |
| Contrôleurs | `app/Http/Controllers/Admin/{AuthController,ArchiveController,ConfigurationAcademiqueController,InscriptionAcademiqueController,NoteController,DocumentAcademiqueController}.php` + `Etudiant/ArchiveController.php` |
| Vues | `resources/views/admin/archives/*`, `admin/auth/connexion.blade.php`, `etudiant/archives.blade.php` |

---

## 2. Base de données (migrations `2026_09_24_100001` → `100009`)

| Table | Points clés |
|---|---|
| `archives_etudiants` | `etudiant_id` **unique** (un dossier par étudiant), `matricule` unique, `statut` enum(actif/clos) |
| `annees_universitaires` | `libelle` unique (AAAA-AAAA), une seule `active` |
| `matieres` | `code` unique, coefficient > 0 (validation), `niveau` + `semestre` pour la cohérence des saisies |
| `inscriptions_academiques` | **unique(etudiant_id, annee_universitaire_id)** — pas de double inscription par an |
| `semestres` | **unique(inscription_academique_id, numero)** — S1/S2 auto-créés |
| `notes` | **unique(semestre_id, matiere_id, session)** — une note par matière/session ; `locked_at` (verrouillage) ; 0 ≤ note ≤ 20 (validation serveur) |
| `documents_academiques` | `chemin` masqué (`$hidden`), `hash_fichier` SHA-256, `uploaded_by` (traçabilité), FK année nullable |
| `historique_academique` | journal **immuable** (created_at uniquement), IP + user-agent, ancienne/nouvelle valeur en JSON |

> Les contraintes numériques (0 ≤ note ≤ 20, etc.) sont arbitrées par la validation
> serveur (Form Requests + service) ; les CHECK SQL ont été écartés pour compatibilité
> SQLite (tests) — voir `docs/SECURITY.md` § risques résiduels.

---

## 3. Calculs académiques (déterministes)

`AcademicCalculationService` — arithmétique **en centièmes entiers** (pas d'erreur
de flottant sur des valeurs académiques) :

- `moyenne = Σ(note × coefficient) / Σ(coefficient)`
- **Note validée par matière** : la session de rattrapage remplace la note normale
- **Crédits obtenus** : crédits des matières dont la note validée ≥ 10
- **Moyenne semestrielle** → persistance sur le semestre
- **Moyenne annuelle** : moyenne des semestres pondérée par leurs crédits totaux
- **Moyenne générale** : moyenne des années pondérée par leurs crédits
- **Décisions** :
  - Semestre : ≥ 10 `Validé` · ≥ 8 `Rattrapage` · sinon `Non validé`
  - Année : ≥ 10 + crédits OK `Admis(e)` · ≥ 10 `Admis(e) — dettes de crédits` · ≥ 8 `Ajourné(e) — rattrapage` · sinon `Ajourné(e)`

Après **chaque** saisie/modification/suppression de note, le semestre **et**
l'année sont recalculés et persistés (`ArchiveService` → `DB::transaction`).

---

## 4. Stockage des documents

- Disque `local` → **`storage/app/private/`** (jamais `public/`)
- Arborescence : `archives/etudiant_{id}/{annee_id}/documents/{uuid}.{ext}`
- Nom interne : **UUID aléatoire** (jamais le nom utilisateur)
- Double validation : FormRequest (`mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,txt`,
  `max:10240` Ko) **+** `DocumentService` (extension, MIME réel via finfo, taille)
- Hash SHA-256 du contenu (intégrité, détection de doublons)
- Téléchargement **uniquement** via contrôleur autorisé + journalisé :
  - scolarité : `GET /admin/archives/documents/{id}/download` (permission `documents.download`)
  - étudiant : `GET /etudiant/archives/documents/{id}/download` (appartenance stricte)

---

## 5. Rôles & permissions (RBAC)

Matrice `app/Support/Permissions.php` — chaque route porte sa permission (`can:`).

| Permission | super_admin | admin_scolarite | agent_scolarite | consultation |
|---|:-:|:-:|:-:|:-:|
| archives.view | ✔ | ✔ | ✔ | ✔ |
| archives.create / update | ✔ | ✔ | ✔ | ✘ |
| archives.delete | ✔ | ✔ | ✘ | ✘ |
| documents.upload / view / download | ✔ | ✔ | ✔ (upload+download) | view+download |
| documents.delete | ✔ | ✔ | ✘ | ✘ |
| notes.view | ✔ | ✔ | ✔ | ✔ |
| notes.create / update | ✔ | ✔ | ✔ | ✘ |
| notes.delete | ✔ | ✔ | ✘ | ✘ |
| notes.unlock (verrouillage + correction de notes verrouillées) | ✔ | ✔ | ✘ | ✘ |
| history.view | ✔ | ✔ | ✔ | ✔ |
| configuration.manage (années + matières) | ✔ | ✔ | ✘ | ✘ |

Comptes seedés (mots de passe de démonstration — à changer en production) :

| Compte | Email | Mot de passe |
|---|---|---|
| Super administrateur | `admin@universite.ac.ma` | `Admin@2026` |
| Responsable scolarité | `scolarite@universite.ac.ma` | `Scolarite@2026` |
| Agent scolarité | `agent@universite.ac.ma` | `Agent@2026` |
| Consultation | `consultation@universite.ac.ma` | `Consult@2026` |

---

## 6. Journal d'audit

Chaque action sensible écrit dans `historique_academique` (via `AuditService`) :
consultation de dossier, création/modification/suppression (dossier, inscription,
note, document), upload, téléchargement, verrouillage de note, export, avec
l'acteur, l'IP, le user-agent, l'ancienne et la nouvelle valeur (JSON).

**Interdictions absolues** : jamais de mot de passe, token, cookie ou secret
(donnée vérifiée par test : `AuditLogTest::le_journal_ne_contient_jamais_de_secrets`).

---

## 7. Routes principales

```
Back-office (auth + RBAC + rate limiting)
  GET    /admin/connexion                        connexion scolarité (guest)
  POST   /admin/connexion                        throttle:5,1
  GET    /admin/archives                         registre + recherche + filtres
  GET    /admin/archives/configuration           années + matières
  POST   /admin/archives                         créer un dossier
  GET    /admin/archives/{dossier}               fiche complète (consultation journalisée)
  GET    /admin/archives/{dossier}/export        export CSV (architecture PDF/Excel prête)
  PATCH  /admin/archives/{dossier}               statut/observation
  DELETE /admin/archives/{dossier}               suppression en cascade (transaction)
  POST   /admin/archives/{dossier}/inscriptions   inscription + S1/S2
  POST   /admin/archives/inscriptions/{i}/notes  saisie de note (recalcul auto)
  PATCH  /admin/archives/notes/{note}            correction (motif journalisé)
  PATCH  /admin/archives/notes/{note}/verrouiller verrouillage (rôles supérieurs)
  POST   /admin/archives/{dossier}/documents      upload sécurisé
  GET    /admin/archives/documents/{d}/download  téléchargement journalisé

Espace étudiant (session-scoped, anti-IDOR)
  GET    /etudiant/archives                      SA fiche académique
  GET    /etudiant/archives/documents/{d}/download SES documents uniquement
```

---

## 8. Tests (10 suites, 73 tests module)

`AcademicCalculationTest` · `ArchiveTest` · `AcademicRegistrationTest` ·
`SemesterTest` · `SubjectTest` · `GradeTest` · `DocumentAcademiqueTest` ·
`ArchiveAuthorizationTest` · `ArchiveSecurityTest` · `AuditLogTest`

Suite complète : `php artisan test` → **102 tests, 429 assertions**.

---

## 9. Maintenance

```bash
# Recréer la base + les données de démonstration (académiques incluses)
php artisan migrate:fresh --seed

# Vérifier l'intégrité des fichiers archivés (à planifier en production)
php artisan tinker --execute="foreach (\App\Models\DocumentAcademique::cursor() as \$d) { echo \$d->matricule_ou_id . ' ' . (\Illuminate\Support\Facades\Storage::disk('local')->exists(\$d->chemin) ? 'OK' : 'MANQUANT') . PHP_EOL; }"

# Sauvegarde recommandée (MySQL + documents privés)
mysqldump inscription_etudiants > backup.sql
tar -czf documents-$(date +%F).tgz storage/app/private/archives
```

La suppression d'un dossier est **transactionnelle** : documents (fichiers + lignes),
inscriptions (semestres + notes en cascade) et dossier disparaissent ensemble.

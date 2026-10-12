# SETTINGS — Dépendances et activation des modules (LOOP A, 12/10/2026)

Source de vérité : `config/modules.php` + `MODULE_*` (env) →
`ModuleRegistry::estActif()` → middleware `module:{nom}` (404 si inactif) →
routes/gates/nav (`ModuleServiceProvider`, `ModuleRegistry::elementsNavigation`).

## 1. Cartographie (configuré `.env.example` / observé `.env` dev)

| Module | Défaut | Dev observé | Middleware | Effet désactivation |
|---|---|---|---|---|
| attendance | false | **true** | `module:attendance` | 404 présences/appels/feuilles |
| student-dossiers | false | **true** | `module:student-dossiers` | 404 GED + checklist pièces |
| online-requests | false | **true** | `module:online-requests` | 404 demandes + SLA |
| schedule | false | **true** | `module:schedule` | 404 EDT/imports |
| referentiel | true | true | `module:referentiel` | 404 LMD (casse tout : programmes/niveaux) |
| enseignement | true | true | `module:enseignement` | 404 services/affectations |
| evaluation | false | **true** | `module:evaluation` | 404 examens/délibérations/notes |
| announcements | false | false | `module:announcements` | 404 campagnes |
| calendar | false | false | `module:calendar` | 404 agenda |
| tasks | false | false | `module:tasks` | 404 cockpit |
| finances | false | false | `module:finances` | 404 factures/paiements |
| student-archive | false | false | `module:student-archive` | 404 archive consolidée |
| parcours-academique | false | false | `module:parcours-academique` | 404 plans/simulations |
| sentinel | false | **true** | `module:sentinel` | 404 cockpit (contrôles persistants) |
| versioning | true | (non vérifié, défaut true) | — | 404 releases |

## 2. Règles de changement (sans implémenter)

- Ne jamais exposer `MODULE_*` comme interrupteurs DB : le flag env reste
  la source de vérité jusqu'à décision d'architecture (mission §C).
- Procédure cible : simulation (routes affectées, données orphelines) →
  validation `configuration.manage` → audit → retour arrière = re-flag.
- Interdits : activation contournant dépendances (ex. evaluation sans
  referentiel), hors-ligne étudiant, assistant IA (DEC-05), maquettes
  pédagogiques (ARB, DEC-01).
- Partiel dangereux : désactiver `student-dossiers` casse `checklistDossier`
  (GED) ; désactiver `referentiel` casse inscriptions/affectations/examens.
- Validations avant changement : mapping dépendances ci-dessus + tests du
  module + non-régression voisins ; `MODULE_*` absents de `parametres`
  (aucune écriture DB possible aujourd'hui — constat, pas une lacune).

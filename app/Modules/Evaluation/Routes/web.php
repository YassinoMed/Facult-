<?php

use App\Modules\Evaluation\Controllers\AffectationEnseignantController;
use App\Modules\Evaluation\Controllers\ContrainteSurveillanceController;
use App\Modules\Evaluation\Controllers\DeliberationController;
use App\Modules\Evaluation\Controllers\EmploiSurveillanceController;
use App\Modules\Evaluation\Controllers\EpreuveController;
use App\Modules\Evaluation\Controllers\ExamenController;
use App\Modules\Evaluation\Controllers\IncidentController;
use App\Modules\Evaluation\Controllers\MesNotesController;
use App\Modules\Evaluation\Controllers\MesSurveillancesController;
use App\Modules\Evaluation\Controllers\NoteEpreuveController;
use App\Modules\Evaluation\Controllers\RegleEvaluationController;
use App\Modules\Evaluation\Controllers\SessionExamenController;
use App\Modules\Evaluation\Controllers\SurveillanceController;
use App\Modules\Evaluation\Controllers\TableauBordEvaluationController;
use App\Modules\Evaluation\Controllers\TypeEvaluationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module Evaluation — Phase 4 (§26-35)
|--------------------------------------------------------------------------
| Toujours enregistrées, verrouillées par `module:evaluation` :
| module désactivé = 404, même en appel direct.
*/
Route::middleware(['auth', 'admin', 'secondaire.verrou', 'module:evaluation'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('evaluations', [TableauBordEvaluationController::class, 'index'])
        ->middleware('can:evaluations.view')
        ->name('evaluations.index');

    Route::get('evaluations/types', [TypeEvaluationController::class, 'index'])
        ->middleware('can:evaluations.view')
        ->name('evaluations.types.index');
    // Gestion via les gates existantes (zéro permission créée) :
    // create/update/delete suivent le gradient des examens.
    Route::post('evaluations/types', [TypeEvaluationController::class, 'store'])
        ->middleware(['can:evaluations.create', 'throttle:20,1'])
        ->name('evaluations.types.store');
    Route::match(['put', 'patch'], 'evaluations/types/{type}', [TypeEvaluationController::class, 'update'])
        ->middleware(['can:evaluations.update', 'throttle:20,1'])
        ->name('evaluations.types.update');
    Route::delete('evaluations/types/{type}', [TypeEvaluationController::class, 'destroy'])
        ->middleware(['can:evaluations.delete', 'throttle:10,1'])
        ->name('evaluations.types.destroy');

    // Règles versionnées (Phase 6 §8) : gates existantes.
    Route::get('evaluations/regles', [RegleEvaluationController::class, 'index'])
        ->middleware('can:evaluations.view')
        ->name('evaluations.regles.index');
    Route::post('evaluations/regles', [RegleEvaluationController::class, 'store'])
        ->middleware(['can:evaluations.create', 'throttle:20,1'])
        ->name('evaluations.regles.store');
    Route::patch('evaluations/regles/{regle}/bascule', [RegleEvaluationController::class, 'basculer'])
        ->middleware(['can:evaluations.update', 'throttle:20,1'])
        ->name('evaluations.regles.bascule');
    Route::delete('evaluations/regles/{regle}', [RegleEvaluationController::class, 'destroy'])
        ->middleware(['can:evaluations.delete', 'throttle:10,1'])
        ->name('evaluations.regles.destroy');

    Route::get('evaluations/sessions', [SessionExamenController::class, 'index'])
        ->middleware('can:sessions_examen.view')
        ->name('evaluations.sessions.index');
    Route::get('evaluations/sessions/creer', [SessionExamenController::class, 'create'])
        ->middleware('can:sessions_examen.create')
        ->name('evaluations.sessions.create');
    Route::post('evaluations/sessions', [SessionExamenController::class, 'store'])
        ->middleware(['can:sessions_examen.create', 'throttle:20,1'])
        ->name('evaluations.sessions.store');
    Route::get('evaluations/sessions/{session}', [SessionExamenController::class, 'show'])
        ->middleware('can:sessions_examen.view')
        ->name('evaluations.sessions.show');
    Route::post('evaluations/sessions/{session}/transiter/{cible}', [SessionExamenController::class, 'transiter'])
        ->middleware(['can:sessions_examen.update', 'throttle:20,1'])
        ->whereIn('cible', ['open', 'ongoing', 'closed', 'archived'])
        ->name('evaluations.sessions.transiter');
    Route::post('evaluations/sessions/{session}/generer', [SessionExamenController::class, 'generer'])
        ->middleware(['can:epreuves.create', 'throttle:10,1'])
        ->name('evaluations.sessions.generer');
    Route::post('evaluations/sessions/{session}/surveillants-auto', [SessionExamenController::class, 'autoSurveillants'])
        ->middleware(['can:surveillances.assign', 'throttle:10,1'])
        ->name('evaluations.sessions.surveillants-auto');
    Route::post('evaluations/sessions/{session}/equilibrage/simuler', [SessionExamenController::class, 'simulerEquilibrage'])
        ->middleware(['can:surveillances.assign', 'throttle:20,1'])
        ->name('evaluations.sessions.equilibrage.simuler');
    Route::post('evaluations/sessions/{session}/equilibrage/appliquer', [SessionExamenController::class, 'appliquerEquilibrage'])
        ->middleware(['can:surveillances.manage', 'throttle:10,1'])
        ->name('evaluations.sessions.equilibrage.appliquer');
    Route::post('evaluations/sessions/{session}/verrouiller', [SessionExamenController::class, 'verrouiller'])
        ->middleware(['can:sessions_examen.lock', 'throttle:10,1'])
        ->name('evaluations.sessions.verrouiller');

    Route::get('evaluations/examens', [ExamenController::class, 'index'])
        ->middleware('can:evaluations.view')
        ->name('evaluations.examens.index');
    Route::post('evaluations/examens', [ExamenController::class, 'store'])
        ->middleware(['can:evaluations.create', 'throttle:20,1'])
        ->name('evaluations.examens.store');
    Route::post('evaluations/examens/{examen}/transiter/{cible}', [ExamenController::class, 'transiter'])
        ->middleware(['can:evaluations.update', 'throttle:20,1'])
        ->whereIn('cible', ['planned', 'scheduled', 'published', 'ongoing', 'completed', 'locked', 'cancelled', 'archived'])
        ->name('evaluations.examens.transiter');

    Route::get('evaluations/planning', [EpreuveController::class, 'planning'])
        ->middleware('can:epreuves.view')
        ->name('evaluations.planning');

    Route::get('evaluations/epreuves', [EpreuveController::class, 'index'])
        ->middleware('can:epreuves.view')
        ->name('evaluations.epreuves.index');
    Route::post('evaluations/examens/{examen}/epreuves', [EpreuveController::class, 'store'])
        ->middleware(['can:epreuves.create', 'throttle:20,1'])
        ->name('evaluations.epreuves.store');
    Route::match(['put', 'patch'], 'evaluations/epreuves/{epreuve}', [EpreuveController::class, 'update'])
        ->middleware(['can:epreuves.update', 'throttle:20,1'])
        ->name('evaluations.epreuves.update');
    Route::post('evaluations/epreuves/{epreuve}/transiter/{cible}', [EpreuveController::class, 'transiter'])
        ->middleware(['can:epreuves.update', 'throttle:20,1'])
        ->whereIn('cible', ['scheduled', 'published', 'ongoing', 'completed', 'locked', 'cancelled'])
        ->name('evaluations.epreuves.transiter');
    Route::post('evaluations/epreuves/{epreuve}/verrouiller', [EpreuveController::class, 'verrouiller'])
        ->middleware(['can:epreuves.update', 'throttle:10,1'])
        ->name('evaluations.epreuves.verrouiller');
    Route::get('evaluations/epreuves/{epreuve}/emargement', [EpreuveController::class, 'emargement'])
        ->middleware('can:epreuves.view')
        ->name('evaluations.epreuves.emargement');
    Route::post('evaluations/epreuves/{epreuve}/emargement', [EpreuveController::class, 'enregistrerEmargement'])
        ->middleware(['can:epreuves.update', 'throttle:30,1'])
        ->name('evaluations.epreuves.emargement.store');
    Route::post('evaluations/epreuves/{epreuve}/repartir-salles', [EpreuveController::class, 'repartirSalles'])
        ->middleware(['can:epreuves.update', 'throttle:20,1'])
        ->name('evaluations.epreuves.repartir-salles');
    Route::post('evaluations/epreuves/verifier', [EpreuveController::class, 'verifier'])
        ->middleware(['can:epreuves.view', 'throttle:30,1'])
        ->name('evaluations.epreuves.verifier');
    Route::get('evaluations/epreuves/{epreuve}', [EpreuveController::class, 'show'])
        ->middleware('can:epreuves.view')
        ->name('evaluations.epreuves.show');
    Route::get('evaluations/epreuves/{epreuve}/convocation', [EpreuveController::class, 'convocation'])
        ->middleware(['can:epreuves.view', 'throttle:10,1'])
        ->name('evaluations.epreuves.convocation');
    Route::get('evaluations/candidats/{candidat}/convocation', [EpreuveController::class, 'convocationCandidat'])
        ->middleware(['can:epreuves.view', 'throttle:10,1'])
        ->name('evaluations.candidats.convocation');
    Route::post('evaluations/examens/{examen}/epreuves/{epreuve}/candidats', [EpreuveController::class, 'genererCandidats'])
        ->middleware(['can:epreuves.update', 'throttle:20,1'])
        ->name('evaluations.epreuves.candidats');

    Route::get('evaluations/surveillances/emploi', [EmploiSurveillanceController::class, 'index'])
        ->middleware('can:epreuves.view')
        ->name('evaluations.surveillances.emploi');
    Route::get('evaluations/surveillances/emploi/pdf', [EmploiSurveillanceController::class, 'exporterPdf'])
        ->middleware(['can:epreuves.view', 'throttle:10,1'])
        ->name('evaluations.surveillances.emploi.pdf');
    Route::get('evaluations/surveillances/emploi/csv', [EmploiSurveillanceController::class, 'exporterCsv'])
        ->middleware(['can:epreuves.view', 'throttle:10,1'])
        ->name('evaluations.surveillances.emploi.csv');
    Route::get('evaluations/surveillances/cockpit', [SurveillanceController::class, 'cockpit'])
        ->middleware('can:epreuves.view')
        ->name('evaluations.surveillances.cockpit');
    Route::get('evaluations/surveillances/mes-gardes', [SurveillanceController::class, 'mesGardes'])
        ->name('evaluations.surveillances.mes-gardes');
    Route::get('evaluations/surveillances/export', [SurveillanceController::class, 'exporter'])
        ->middleware('can:epreuves.view')
        ->name('evaluations.surveillances.export');
    Route::get('evaluations/surveillances', [SurveillanceController::class, 'index'])
        ->middleware('can:epreuves.view')
        ->name('evaluations.surveillances.index');
    Route::post('evaluations/epreuves/{epreuve}/surveillants', [SurveillanceController::class, 'store'])
        ->middleware(['can:surveillances.assign', 'throttle:20,1'])
        ->name('evaluations.surveillants.store');
    Route::post('evaluations/epreuves/{epreuve}/surveillants/simuler', [SurveillanceController::class, 'simuler'])
        ->middleware(['can:surveillances.assign', 'throttle:20,1'])
        ->name('evaluations.surveillants.simuler');
    Route::post('evaluations/surveillants/{surveillance}/confirmer', [SurveillanceController::class, 'confirmer'])
        ->middleware(['can:surveillances.assign', 'throttle:20,1'])
        ->name('evaluations.surveillants.confirmer');
    Route::post('evaluations/surveillants/{surveillance}/emargement', [SurveillanceController::class, 'emargement'])
        ->middleware(['can:surveillances.assign', 'throttle:20,1'])
        ->name('evaluations.surveillants.emargement');
    Route::post('evaluations/surveillants/{surveillance}/desaffecter', [SurveillanceController::class, 'desaffecter'])
        ->middleware(['can:surveillances.manage', 'throttle:20,1'])
        ->name('evaluations.surveillants.desaffecter');
    Route::post('evaluations/surveillants/{surveillance}/remplacer', [SurveillanceController::class, 'remplacer'])
        ->middleware(['can:surveillances.manage', 'throttle:20,1'])
        ->name('evaluations.surveillants.remplacer');

    // Affectation des enseignants aux examens et épreuves (§7-16)
    Route::get('evaluations/affectations', [AffectationEnseignantController::class, 'index'])
        ->middleware('can:epreuves.view')
        ->name('evaluations.affectations.index');
    Route::get('evaluations/affectations-hub', [AffectationEnseignantController::class, 'index'])
        ->middleware('can:epreuves.view')
        ->name('evaluations.affectations');
    Route::post('evaluations/affectations/auto', [AffectationEnseignantController::class, 'autoAssigner'])
        ->middleware(['can:surveillances.assign', 'throttle:10,1'])
        ->name('evaluations.affectations.auto');
    Route::get('evaluations/affectations/runs', [AffectationEnseignantController::class, 'runs'])
        ->middleware('can:epreuves.view')
        ->name('evaluations.affectations.runs');
    Route::get('evaluations/affectations/lancer-compte', [AffectationEnseignantController::class, 'compteLancement'])
        ->middleware(['can:surveillances.assign', 'throttle:30,1'])
        ->name('evaluations.affectations.compte');
    Route::post('evaluations/affectations/runs/{run}/annuler', [AffectationEnseignantController::class, 'annulerRun'])
        ->middleware(['can:surveillances.manage', 'throttle:20,1'])
        ->name('evaluations.affectations.runs.annuler');
    Route::post('evaluations/affectations/optimiser', [AffectationEnseignantController::class, 'optimiser'])
        ->middleware(['can:surveillances.manage', 'throttle:10,1'])
        ->name('evaluations.affectations.optimiser');
    Route::get('evaluations/affectations/runs/actif', [AffectationEnseignantController::class, 'runActif'])
        ->middleware(['can:epreuves.view', 'throttle:60,1'])
        ->name('evaluations.affectations.runs.actif');
    Route::get('evaluations/affectations/runs/{run}/status', [AffectationEnseignantController::class, 'runStatus'])
        ->middleware(['can:epreuves.view', 'secondaire.verrou:passif', 'throttle:60,1'])
        ->name('evaluations.affectations.runs.status');
    Route::post('evaluations/affectations/{epreuve}/assigner', [AffectationEnseignantController::class, 'assigner'])
        ->middleware(['can:surveillances.assign', 'throttle:30,1'])
        ->name('evaluations.affectations.assigner');
    Route::post('evaluations/affectations/{surveillance}/retirer', [AffectationEnseignantController::class, 'retirer'])
        ->middleware(['can:surveillances.manage', 'throttle:30,1'])
        ->name('evaluations.affectations.retirer');

    // Configuration des contraintes de surveillance (§Phase 8-10) :
    // implémentation unique (store = upsert par portée, JSON dual).
    Route::get('evaluations/surveillances/contraintes', [ContrainteSurveillanceController::class, 'index'])
        ->middleware('can:surveillances.manage')
        ->name('evaluations.surveillances.contraintes.index');
    Route::get('evaluations/surveillances/contraintes-alias', [ContrainteSurveillanceController::class, 'index'])
        ->middleware('can:surveillances.manage')
        ->name('evaluations.surveillances.contraintes');
    Route::post('evaluations/surveillances/contraintes', [ContrainteSurveillanceController::class, 'store'])
        ->middleware(['can:surveillances.manage', 'throttle:20,1'])
        ->name('evaluations.surveillances.contraintes.store');
    Route::match(['put', 'patch'], 'evaluations/surveillances/contraintes/{config}', [ContrainteSurveillanceController::class, 'update'])
        ->middleware(['can:surveillances.manage', 'throttle:20,1'])
        ->name('evaluations.surveillances.contraintes.update');
    Route::post('evaluations/surveillances/contraintes/reset', [ContrainteSurveillanceController::class, 'reset'])
        ->middleware(['can:surveillances.manage', 'throttle:10,1'])
        ->name('evaluations.surveillances.contraintes.reset');
    Route::post('evaluations/surveillances/contraintes/{config}/reset', [ContrainteSurveillanceController::class, 'reset'])
        ->middleware(['can:surveillances.manage', 'throttle:10,1'])
        ->name('evaluations.surveillances.contraintes.reset.item');
    Route::post('evaluations/surveillances/contraintes/simuler', [ContrainteSurveillanceController::class, 'simuler'])
        ->middleware(['can:surveillances.manage', 'throttle:30,1'])
        ->name('evaluations.surveillances.contraintes.simuler');

    Route::get('evaluations/epreuves/{epreuve}/notes', [NoteEpreuveController::class, 'saisie'])
        ->middleware('can:notes_eval.view')
        ->name('evaluations.notes.saisie');
    Route::get('notes/{epreuve}/saisie', [NoteEpreuveController::class, 'saisie'])
        ->middleware('can:notes_eval.view')
        ->name('notes.saisie');
    Route::post('evaluations/epreuves/{epreuve}/notes', [NoteEpreuveController::class, 'storeLot'])
        ->middleware(['can:notes_eval.enter', 'throttle:20,1'])
        ->name('evaluations.notes.store');
    Route::post('evaluations/epreuves/{epreuve}/notes/anonymes', [NoteEpreuveController::class, 'storeAnonyme'])
        ->middleware(['can:notes_eval.enter', 'throttle:20,1'])
        ->name('evaluations.notes.store-anonyme');
    Route::post('evaluations/epreuves/{epreuve}/compostages/generer', [NoteEpreuveController::class, 'generer'])
        ->middleware(['can:notes.compostage.generate', 'throttle:10,1'])
        ->name('evaluations.epreuves.compostages.generer');
    Route::get('evaluations/epreuves/{epreuve}/correspondance', [NoteEpreuveController::class, 'correspondance'])
        ->middleware('can:notes.compostage.resolve')
        ->name('evaluations.epreuves.correspondance');
    Route::get('evaluations/epreuves/{epreuve}/notes/export-anonyme', [NoteEpreuveController::class, 'exportAnonyme'])
        ->middleware(['can:notes_eval.enter', 'throttle:10,1'])
        ->name('evaluations.notes.export-anonyme');
    Route::post('evaluations/epreuves/{epreuve}/notes/import-anonyme', [NoteEpreuveController::class, 'importAnonyme'])
        ->middleware(['can:notes_eval.enter', 'throttle:10,1'])
        ->name('evaluations.notes.import-anonyme');
    Route::post('evaluations/epreuves/{epreuve}/notes/import-multi', [NoteEpreuveController::class, 'importMulti'])
        ->middleware(['can:notes_eval.enter', 'throttle:10,1'])
        ->name('evaluations.notes.import-multi');
    Route::post('evaluations/epreuves/{epreuve}/notes/import-multi/confirmer', [NoteEpreuveController::class, 'importMultiConfirmer'])
        ->middleware(['can:notes_eval.enter', 'throttle:10,1'])
        ->name('evaluations.notes.import-multi-confirmer');
    Route::post('evaluations/epreuves/{epreuve}/notes/import-multi/annuler', [NoteEpreuveController::class, 'importMultiAnnuler'])
        ->middleware(['can:notes_eval.enter', 'throttle:20,1'])
        ->name('evaluations.notes.import-multi-annuler');

    Route::get('evaluations/epreuves/{epreuve}/incidents', [IncidentController::class, 'index'])
        ->middleware('can:evaluations.view')
        ->name('evaluations.epreuves.incidents.index');
    Route::post('evaluations/epreuves/{epreuve}/incidents', [IncidentController::class, 'store'])
        ->middleware(['can:evaluations.update', 'throttle:20,1'])
        ->name('evaluations.epreuves.incidents.store');
    Route::post('evaluations/incidents/{incident}/transiter/{cible}', [IncidentController::class, 'transiter'])
        ->middleware(['can:evaluations.update', 'throttle:20,1'])
        ->whereIn('cible', ['signale', 'en_cours', 'resolu'])
        ->name('evaluations.incidents.transiter');
    Route::post('evaluations/notes/{note}/valider', [NoteEpreuveController::class, 'valider'])
        ->middleware(['can:notes_eval.validate', 'throttle:20,1'])
        ->name('evaluations.notes.valider');
    Route::post('evaluations/notes/{note}/verrouiller', [NoteEpreuveController::class, 'verrouiller'])
        ->middleware(['can:notes_eval.lock', 'throttle:10,1'])
        ->name('evaluations.notes.verrouiller');
    Route::post('evaluations/notes/{note}/correction-demander', [NoteEpreuveController::class, 'demanderCorrection'])
        ->middleware(['can:notes_eval.update', 'throttle:10,1'])
        ->name('evaluations.notes.correction-demander');
    Route::post('evaluations/notes/{note}/correction-autoriser', [NoteEpreuveController::class, 'autoriserCorrection'])
        ->middleware(['can:notes_eval.correct', 'throttle:10,1'])
        ->name('evaluations.notes.correction-autoriser');

    Route::get('evaluations/deliberations', [DeliberationController::class, 'index'])
        ->middleware('can:deliberations.view')
        ->name('evaluations.deliberations.index');
    Route::post('evaluations/deliberations', [DeliberationController::class, 'store'])
        ->middleware(['can:deliberations.prepare', 'throttle:20,1'])
        ->name('evaluations.deliberations.store');
    Route::get('evaluations/deliberations/{deliberation}', [DeliberationController::class, 'show'])
        ->middleware('can:deliberations.view')
        ->name('evaluations.deliberations.show');
    Route::post('evaluations/deliberations/{deliberation}/preparer', [DeliberationController::class, 'preparer'])
        ->middleware(['can:deliberations.prepare', 'throttle:10,1'])
        ->name('evaluations.deliberations.preparer');
    Route::get('evaluations/deliberations/{deliberation}/simuler', [DeliberationController::class, 'simuler'])
        ->middleware('can:deliberations.prepare')
        ->name('evaluations.deliberations.simuler');
    Route::post('evaluations/deliberations/{deliberation}/membres', [DeliberationController::class, 'ajouterMembre'])
        ->middleware(['can:deliberations.manage', 'throttle:20,1'])
        ->name('evaluations.deliberations.membres.store');
    Route::post('evaluations/membres/{membre}/pointer', [DeliberationController::class, 'pointer'])
        ->middleware(['can:deliberations.manage', 'throttle:20,1'])
        ->name('evaluations.membres.pointer');
    Route::post('evaluations/decisions/{decision}', [DeliberationController::class, 'decider'])
        ->middleware(['can:deliberations.manage', 'throttle:30,1'])
        ->name('evaluations.decisions.decider');
    Route::post('evaluations/deliberations/{deliberation}/valider', [DeliberationController::class, 'valider'])
        ->middleware(['can:deliberations.validate', 'throttle:10,1'])
        ->name('evaluations.deliberations.valider');
    Route::post('evaluations/deliberations/{deliberation}/publier', [DeliberationController::class, 'publier'])
        ->middleware(['can:deliberations.publish', 'throttle:10,1'])
        ->name('evaluations.deliberations.publier');
    Route::post('evaluations/deliberations/{deliberation}/releves', [DeliberationController::class, 'genererReleves'])
        ->middleware(['can:deliberations.publish', 'throttle:5,1'])
        ->name('evaluations.deliberations.releves');
    Route::get('evaluations/deliberations/{deliberation}/pv', [DeliberationController::class, 'pv'])
        ->middleware(['can:deliberations.view', 'throttle:10,1'])
        ->name('evaluations.deliberations.pv');
});

/*
|--------------------------------------------------------------------------
| Espace Enseignant — mes surveillances et mes épreuves
|--------------------------------------------------------------------------
| Session `enseignant_auth_id` + `module:evaluation` (404 si inactif).
| Résolution teacher_id depuis la session uniquement (anti-IDOR) :
 * aucune route d'attribution, de transition ou de paramétrage ici.
*/
Route::middleware(['enseignant.auth', 'module:evaluation'])->prefix('enseignant')->name('enseignant.')->group(function () {
    Route::get('surveillances', [MesSurveillancesController::class, 'index'])->name('surveillances.index');
    Route::get('surveillances/export-csv', [MesSurveillancesController::class, 'exporter'])
        ->middleware('throttle:10,1')
        ->name('surveillances.export');
    Route::get('epreuves/{epreuve}', [MesSurveillancesController::class, 'show'])->name('epreuves.show');
    Route::get('epreuves/{epreuve}/emargement', [MesSurveillancesController::class, 'emargement'])
        ->middleware('throttle:10,1')
        ->name('epreuves.emargement');
    Route::post('epreuves/{epreuve}/incidents', [MesSurveillancesController::class, 'signaler'])
        ->middleware('throttle:10,1')
        ->name('epreuves.incidents.signaler');

    // Saisie des notes de MES épreuves (LOOP 05) : voie strictement
    // anonyme (compostages résolus serveur), éligibilité ECUE affectée.
    // Ni validation, ni verrouillage, ni correspondance CMP → identité.
    Route::get('notes', [MesNotesController::class, 'index'])->name('notes.index');
    Route::get('notes/{epreuve}/saisie', [MesNotesController::class, 'saisie'])->name('notes.saisie');
    Route::post('notes/{epreuve}/saisie', [MesNotesController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('notes.store');
    Route::post('notes/{epreuve}/import', [MesNotesController::class, 'importer'])
        ->middleware('throttle:10,1')
        ->name('notes.importer');
    Route::get('notes/{epreuve}/export', [MesNotesController::class, 'exporter'])
        ->middleware('throttle:10,1')
        ->name('notes.exporter');
});

<?php

use App\Modules\Referentiel\Controllers\CompetencesController;
use App\Modules\Referentiel\Controllers\EcuesController;
use App\Modules\Referentiel\Controllers\MaquetteController;
use App\Modules\Referentiel\Controllers\ReferentielController;
use App\Modules\Referentiel\Controllers\ReordonnancementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module Referentiel LMD — routes (§2-26)
|--------------------------------------------------------------------------
| Fondation académique : tableau de bord, arbre, qualité, CRUD des
| entités, maquettes versionnées, ECUE. Verrou `module:referentiel`.
*/
Route::middleware(['auth', 'admin', 'secondaire.verrou', 'module:referentiel'])->prefix('admin')->name('admin.')->group(function () {
    Route::prefix('referentiel')->name('referentiel.')->group(function () {
        Route::get('tableau-bord', [ReferentielController::class, 'tableauBord'])
            ->middleware('can:referentiel.view')->name('tableau-bord');
        Route::get('arbre', [ReferentielController::class, 'arbre'])
            ->middleware('can:referentiel.view')->name('arbre');
        Route::get('qualite', [ReferentielController::class, 'qualite'])
            ->middleware('can:referentiel.view')->name('qualite');
        // Centre de pilotage (ENTERPRISE LOOP 01) + prévalidation
        // (LOOP 02) : lecture seule, mêmes permissions et périmètre.
        Route::get('pilotage', [ReferentielController::class, 'pilotage'])
            ->middleware('can:referentiel.view')->name('pilotage');
        Route::get('prevalidation', [ReferentielController::class, 'prevalidation'])
            ->middleware('can:referentiel.view')->name('prevalidation.index');
        Route::get('prevalidation/{programme}', [ReferentielController::class, 'prevalidationProgramme'])
            ->middleware('can:referentiel.view')->name('prevalidation.show');
        // Inventaire standalone (LOOP 07, temps 1 : lecture seule) +
        // charge pédagogique descriptive (LOOP 08, sans seuils).
        Route::get('standalone', [ReferentielController::class, 'standalone'])
            ->middleware('can:referentiel.view')->name('standalone');
        Route::get('charges', [ReferentielController::class, 'charges'])
            ->middleware('can:referentiel.view')->name('charges');
        // Graphe des prérequis (LOOP 11) : lecture seule, programme exigé.
        Route::get('prerequis', [ReferentielController::class, 'prerequis'])
            ->middleware('can:referentiel.view')->name('prerequis');

        // Maquettes versionnées (avant les routes {entite} génériques).
        Route::get('maquettes', [MaquetteController::class, 'index'])
            ->middleware('can:referentiel.view')->name('maquettes.index');
        Route::get('maquettes/creer', [MaquetteController::class, 'create'])
            ->middleware('can:referentiel.manage')->name('maquettes.create');
        Route::post('maquettes', [MaquetteController::class, 'store'])
            ->middleware(['can:referentiel.manage', 'throttle:10,1'])->name('maquettes.store');
        Route::get('maquettes/{maquette}/comparer', [MaquetteController::class, 'comparer'])
            ->middleware('can:referentiel.view')->name('maquettes.comparer');
        Route::get('maquettes/{maquette}', [MaquetteController::class, 'show'])
            ->middleware('can:referentiel.view')->name('maquettes.show');
        Route::delete('maquettes/{maquette}', [MaquetteController::class, 'destroy'])
            ->middleware(['can:referentiel.manage', 'throttle:10,1'])->name('maquettes.destroy');
        Route::patch('maquettes/{maquette}/transition', [MaquetteController::class, 'transition'])
            ->middleware(['can:referentiel.manage', 'throttle:20,1'])->name('maquettes.transition');
        Route::put('maquettes/{maquette}/contenu', [MaquetteController::class, 'contenu'])
            ->middleware(['can:referentiel.manage', 'throttle:20,1'])->name('maquettes.contenu');
        Route::put('maquettes/{maquette}/ecues', [MaquetteController::class, 'ecues'])
            ->middleware(['can:referentiel.manage', 'throttle:20,1'])->name('maquettes.ecues');
        Route::post('maquettes/{maquette}/nouvelle-version', [MaquetteController::class, 'nouvelleVersion'])
            ->middleware(['can:referentiel.manage', 'throttle:10,1'])->name('maquettes.nouvelle-version');

        // ECUE = rattachement LMD des matières (avant {entite} générique).
        Route::get('ecues', [EcuesController::class, 'index'])
            ->middleware('can:referentiel.view')->name('ecues.index');
        Route::get('ecues/{ecue}', [EcuesController::class, 'show'])
            ->middleware('can:referentiel.view')->name('ecues.show');
        Route::get('ecues/{ecue}/modifier', [EcuesController::class, 'edit'])
            ->middleware('can:referentiel.manage')->name('ecues.edit');
        Route::patch('ecues/{ecue}', [EcuesController::class, 'update'])
            ->middleware(['can:referentiel.manage', 'throttle:20,1'])->name('ecues.update');
        Route::post('ecues/{ecue}/prerequis', [EcuesController::class, 'attacherPrerequis'])
            ->middleware(['can:referentiel.manage', 'throttle:20,1'])->name('ecues.prerequis.store');
        Route::delete('ecues/{ecue}/prerequis/{prerequis}', [EcuesController::class, 'detacherPrerequis'])
            ->middleware(['can:referentiel.manage', 'throttle:20,1'])->name('ecues.prerequis.destroy');
        Route::post('reordonner', [ReordonnancementController::class, 'store'])
            ->middleware(['can:referentiel.manage', 'throttle:20,1'])->name('reordonner');

        // Compétences et couverture pédagogique (Lot 20) : catalogue
        // SAISI, associations canoniques, rapport par maquette, import
        // par codes avec ambiguïtés détectées. Permissions RÉUTILISÉES
        // (referentiel.*, maquettes.approve, referentiel.import) —
        // avant les routes {entite} génériques.
        Route::get('competences', [CompetencesController::class, 'index'])
            ->middleware('can:referentiel.view')->name('competences.index');
        Route::post('competences', [CompetencesController::class, 'store'])
            ->middleware(['can:referentiel.manage', 'throttle:30,1,pc-cat'])->name('competences.store');
        Route::post('competences/import', [CompetencesController::class, 'importer'])
            ->middleware(['can:referentiel.import', 'throttle:10,1,pc-import'])->name('competences.import');
        Route::get('competences/coherence', [CompetencesController::class, 'coherence'])
            ->middleware('can:referentiel.view')->name('competences.coherence');
        Route::post('competences/{id}/reviser', [CompetencesController::class, 'reviser'])
            ->middleware(['can:referentiel.manage', 'throttle:30,1,pc-cat'])
            ->whereNumber('id')->name('competences.reviser');
        Route::post('competences/{id}/approuver', [CompetencesController::class, 'approuver'])
            ->middleware(['can:maquettes.approve', 'throttle:30,1,pc-cat'])
            ->whereNumber('id')->name('competences.approuver');
        Route::post('competences/{id}/archiver', [CompetencesController::class, 'archiver'])
            ->middleware(['can:referentiel.manage', 'throttle:30,1,pc-cat'])
            ->whereNumber('id')->name('competences.archiver');
        Route::post('competences/{id}/objectifs', [CompetencesController::class, 'associer'])
            ->middleware(['can:referentiel.manage', 'throttle:60,1,pc-assoc'])
            ->whereNumber('id')->name('competences.objectifs.associer');
        Route::delete('competences/{id}/objectifs', [CompetencesController::class, 'dissocier'])
            ->middleware(['can:referentiel.manage', 'throttle:60,1,pc-assoc'])
            ->whereNumber('id')->name('competences.objectifs.dissocier');
        Route::get('maquettes/{maquette}/couverture-competences', [CompetencesController::class, 'couverture'])
            ->middleware('can:referentiel.view')->name('maquettes.couverture');
        Route::post('maquettes/{maquette}/couverture-competences/figer', [CompetencesController::class, 'figerCouverture'])
            ->middleware(['can:referentiel.export', 'throttle:10,1,pc-snap'])->name('maquettes.couverture.figer');

        // CRUD générique des entités (neuf référentiels).
        Route::get('{entite}', [ReferentielController::class, 'index'])
            ->middleware('can:referentiel.view')
            ->whereIn('entite', ['departements', 'domaines', 'mentions', 'specialites', 'types-diplomes', 'programmes', 'niveaux', 'semestres', 'ues'])
            ->name('entites.index');
        Route::get('{entite}/creer', [ReferentielController::class, 'create'])
            ->middleware('can:referentiel.manage')
            ->whereIn('entite', ['departements', 'domaines', 'mentions', 'specialites', 'types-diplomes', 'programmes', 'niveaux', 'semestres', 'ues'])
            ->name('entites.create');
        Route::post('{entite}', [ReferentielController::class, 'store'])
            ->middleware(['can:referentiel.manage', 'throttle:20,1'])
            ->whereIn('entite', ['departements', 'domaines', 'mentions', 'specialites', 'types-diplomes', 'programmes', 'niveaux', 'semestres', 'ues'])
            ->name('entites.store');
        Route::get('{entite}/{id}', [ReferentielController::class, 'show'])
            ->middleware('can:referentiel.view')->whereNumber('id')
            ->whereIn('entite', ['departements', 'domaines', 'mentions', 'specialites', 'types-diplomes', 'programmes', 'niveaux', 'semestres', 'ues'])
            ->name('entites.show');
        Route::get('{entite}/{id}/modifier', [ReferentielController::class, 'edit'])
            ->middleware('can:referentiel.manage')->whereNumber('id')
            ->whereIn('entite', ['departements', 'domaines', 'mentions', 'specialites', 'types-diplomes', 'programmes', 'niveaux', 'semestres', 'ues'])
            ->name('entites.edit');
        Route::match(['put', 'patch'], '{entite}/{id}', [ReferentielController::class, 'update'])
            ->middleware(['can:referentiel.manage', 'throttle:20,1'])->whereNumber('id')
            ->whereIn('entite', ['departements', 'domaines', 'mentions', 'specialites', 'types-diplomes', 'programmes', 'niveaux', 'semestres', 'ues'])
            ->name('entites.update');
        Route::delete('{entite}/{id}', [ReferentielController::class, 'destroy'])
            ->middleware(['can:referentiel.manage', 'throttle:10,1'])->whereNumber('id')
            ->whereIn('entite', ['departements', 'domaines', 'mentions', 'specialites', 'types-diplomes', 'programmes', 'niveaux', 'semestres', 'ues'])
            ->name('entites.destroy');
    });
});

<?php

namespace App\Modules\Evaluation\Controllers;

use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\PlanningValidation;
use App\Modules\Evaluation\Services\ExamGouvernanceValidationService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Surface HTTP du circuit de gouvernance du planning (§2.7, §2.8).
 *
 * Chaque action vérifie la permission ET le périmètre facultaire côté
 * serveur. Masquer un bouton ne suffit jamais : ces routes refusent
 * explicitement un acteur hors rôle ou hors périmètre.
 */
class ExamGouvernanceController
{
    public function __construct(private ExamGouvernanceValidationService $service) {}

    public function soumettre(Request $request, Examen $examen): RedirectResponse
    {
        Gate::authorize('update', $examen);

        $donnees = $request->validate([
            'date_gel' => ['nullable', 'date'],
            'justification_exceptionnelle' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->executer(
            $examen,
            fn () => $this->service->soumettrePourValidation(
                $examen,
                (int) $request->user()->id,
                $donnees['date_gel'] ?? null,
                $donnees['justification_exceptionnelle'] ?? null,
            ),
            'Planning soumis au circuit de validation.'
        );
    }

    public function certifier(Request $request, PlanningValidation $validation): RedirectResponse
    {
        Gate::authorize('evaluations.certify');
        $this->perimetre($request, $validation);

        return $this->executer(
            $validation->examen,
            fn () => $this->service->certifierConformite($validation, (int) $request->user()->id),
            'Planning certifié conforme.'
        );
    }

    public function approuverPremierNiveau(Request $request, PlanningValidation $validation): RedirectResponse
    {
        Gate::authorize('evaluations.approve');
        $this->perimetre($request, $validation);

        $donnees = $request->validate(['commentaire' => ['nullable', 'string', 'max:1000']]);

        return $this->executer(
            $validation->examen,
            fn () => $this->service->approuverPremierNiveau(
                $validation,
                (int) $request->user()->id,
                $donnees['commentaire'] ?? null,
            ),
            'Première approbation enregistrée.'
        );
    }

    public function approuverSecondNiveau(Request $request, PlanningValidation $validation): RedirectResponse
    {
        Gate::authorize('evaluations.approve');
        $this->perimetre($request, $validation);

        $donnees = $request->validate(['commentaire' => ['nullable', 'string', 'max:1000']]);

        return $this->executer(
            $validation->examen,
            fn () => $this->service->approuverSecondNiveau(
                $validation,
                (int) $request->user()->id,
                $donnees['commentaire'] ?? null,
            ),
            'Seconde approbation enregistrée : planning prêt à publier.'
        );
    }

    public function refuser(Request $request, PlanningValidation $validation): RedirectResponse
    {
        Gate::authorize('evaluations.approve');
        $this->perimetre($request, $validation);

        $donnees = $request->validate(['motif_rejet' => ['required', 'string', 'min:5', 'max:2000']]);

        return $this->executer(
            $validation->examen,
            fn () => $this->service->refuserPlanning($validation, (int) $request->user()->id, $donnees['motif_rejet']),
            'Planning rejeté : retour au préparateur avec motif.'
        );
    }

    public function escalader(Request $request, PlanningValidation $validation): RedirectResponse
    {
        Gate::authorize('evaluations.approve');
        $this->perimetre($request, $validation);

        $donnees = $request->validate(['motif_escalade' => ['required', 'string', 'min:5', 'max:2000']]);

        return $this->executer(
            $validation->examen,
            fn () => $this->service->escaladerDesaccord($validation, (int) $request->user()->id, $donnees['motif_escalade']),
            'Désaccord escaladé : publication bloquée jusqu’à arbitrage.'
        );
    }

    public function resoudre(Request $request, PlanningValidation $validation): RedirectResponse
    {
        Gate::authorize('evaluations.publish');
        $this->perimetre($request, $validation);

        $donnees = $request->validate(['resolution' => ['required', 'string', 'min:5', 'max:2000']]);

        return $this->executer(
            $validation->examen,
            fn () => $this->service->resoudreEscalade($validation, (int) $request->user()->id, $donnees['resolution']),
            'Escalade résolue et motivée.'
        );
    }

    public function publier(Request $request, PlanningValidation $validation): RedirectResponse
    {
        Gate::authorize('evaluations.publish');
        $this->perimetre($request, $validation);

        $donnees = $request->validate([
            'justification_exceptionnelle' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->executer(
            $validation->examen,
            fn () => $this->service->publierExamenOfficiel(
                $validation,
                (int) $request->user()->id,
                $donnees['justification_exceptionnelle'] ?? null,
            ),
            'Planning publié officiellement.'
        );
    }

    public function nouvelleVersion(Request $request, Examen $examen): RedirectResponse
    {
        Gate::authorize('evaluations.publish');
        $this->perimetreExamen($request, $examen);

        $donnees = $request->validate(['motif_modification' => ['required', 'string', 'min:5', 'max:2000']]);

        return $this->executer(
            $examen,
            fn () => $this->service->creerNouvelleVersionPostGel($examen, (int) $request->user()->id, $donnees['motif_modification']),
            'Nouvelle version créée : la version publiée reste en vigueur jusqu’à validation.'
        );
    }

    private function executer(Examen $examen, callable $action, string $succes): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $e) {
            return back()->withErrors(['gouvernance' => $e->getMessage()]);
        }

        return back()->with('succes', $succes);
    }

    private function perimetre(Request $request, PlanningValidation $validation): void
    {
        $examen = $validation->examen;
        abort_if($examen === null, 404);
        $this->perimetreExamen($request, $examen);
    }

    private function perimetreExamen(Request $request, Examen $examen): void
    {
        $faculteId = $examen->faculteId();
        abort_unless($faculteId === null || $request->user()->peutAccederFaculte($faculteId), 404);
    }
}

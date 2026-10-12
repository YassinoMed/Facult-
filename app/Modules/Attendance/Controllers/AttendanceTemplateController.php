<?php

namespace App\Modules\Attendance\Controllers;

use App\Http\Controllers\Controller;
use App\Models\IntegrationLog;
use App\Modules\Attendance\Models\AttendanceTemplate;
use App\Modules\Attendance\Requests\StoreAttendanceTemplateRequest;
use App\Modules\Attendance\Requests\UpdateAttendanceTemplateRequest;
use App\Modules\Attendance\Services\AttendanceTemplateRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Administration des modèles de fiches de présence (§27-30).
 *
 * Règles :
 * - Les modèles système sont protégés (is_system = true non supprimables) ;
 * - Duplication rapide vers gabarit personnalisable ;
 * - Validation stricte de la configuration JSON côté serveur ;
 * - Audit de chaque opération sensible.
 */
class AttendanceTemplateController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', AttendanceTemplate::class);

        $query = AttendanceTemplate::query()->latest('id');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('is_system')) {
            $query->where('is_system', $request->boolean('is_system'));
        }

        if ($request->filled('q')) {
            $q = $request->string('q');
            $query->where(fn ($sub) => $sub->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%"));
        }

        $templates = $query->paginate(12)->withQueryString();

        return view('modules.attendance.templates-admin.index', [
            'templates' => $templates,
            'types' => AttendanceTemplate::TYPES,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', AttendanceTemplate::class);

        $template = new AttendanceTemplate([
            'type' => AttendanceTemplate::TYPE_CUSTOM,
            'is_active' => true,
            'configuration' => [
                'orientation' => 'portrait',
                'paper' => 'A4',
                'columns' => [
                    ['key' => 'numero', 'label' => 'N°', 'width' => '6%', 'visible' => true],
                    ['key' => 'nom', 'label' => 'Nom', 'width' => '22%', 'visible' => true],
                    ['key' => 'prenom', 'label' => 'Prénom', 'width' => '22%', 'visible' => true],
                    ['key' => 'matricule', 'label' => 'Matricule', 'width' => '18%', 'visible' => true],
                    ['key' => 'presence', 'label' => 'Présence', 'width' => '14%', 'visible' => true],
                    ['key' => 'signature', 'label' => 'Signature', 'width' => '18%', 'visible' => true],
                ],
            ],
        ]);

        return view('modules.attendance.templates-admin.form', [
            'template' => $template,
            'types' => AttendanceTemplate::TYPES,
            'colonnesDisponibles' => AttendanceTemplate::COLONNES_DISPONIBLES,
            'modeEdition' => false,
        ]);
    }

    public function store(StoreAttendanceTemplateRequest $request): RedirectResponse
    {
        $donnees = $request->validated();

        if (empty($donnees['code'])) {
            $donnees['code'] = 'custom_'.Str::slug($donnees['name']).'_'.Str::random(4);
        }

        $donnees['is_system'] = false;
        $donnees['created_by'] = $request->user()->id;
        $donnees['updated_by'] = $request->user()->id;

        $template = AttendanceTemplate::create($donnees);

        IntegrationLog::tracer(
            'attendance',
            'template_manage',
            'ATTENDANCE_TEMPLATE_CREATED',
            'completed',
            null,
            201,
            null,
            null,
            (string) Str::uuid(),
            'TMPL-'.$template->code
        );

        return redirect()->route('admin.presences.modeles.index')
            ->with('success', 'Modèle de fiche « '.$template->name.' » créé avec succès.');
    }

    public function edit(AttendanceTemplate $template): View
    {
        Gate::authorize('update', $template);

        return view('modules.attendance.templates-admin.form', [
            'template' => $template,
            'types' => AttendanceTemplate::TYPES,
            'colonnesDisponibles' => AttendanceTemplate::COLONNES_DISPONIBLES,
            'modeEdition' => true,
        ]);
    }

    public function update(UpdateAttendanceTemplateRequest $request, AttendanceTemplate $template): RedirectResponse
    {
        $donnees = $request->validated();
        $donnees['updated_by'] = $request->user()->id;

        // Préserver le drapeau is_system
        if ($template->is_system) {
            unset($donnees['is_system'], $donnees['code']);
        }

        $template->update($donnees);

        IntegrationLog::tracer(
            'attendance',
            'template_manage',
            'ATTENDANCE_TEMPLATE_UPDATED',
            'completed',
            null,
            200,
            null,
            null,
            (string) Str::uuid(),
            'TMPL-'.$template->code
        );

        return redirect()->route('admin.presences.modeles.index')
            ->with('success', 'Modèle « '.$template->name.' » mis à jour avec succès.');
    }

    public function duplicate(Request $request, AttendanceTemplate $template): RedirectResponse
    {
        Gate::authorize('duplicate', $template);

        $nouveauNom = $request->string('nom')->toString() ?: 'Copie de '.$template->name;
        $copie = $template->dupliquer($nouveauNom, $request->user());

        IntegrationLog::tracer(
            'attendance',
            'template_manage',
            'ATTENDANCE_TEMPLATE_CREATED',
            'completed',
            null,
            201,
            null,
            null,
            (string) Str::uuid(),
            'TMPL-'.$copie->code
        );

        return redirect()->route('admin.presences.modeles.index')
            ->with('success', 'Modèle dupliqué avec succès sous le nom « '.$copie->name.' ».');
    }

    public function toggle(AttendanceTemplate $template): RedirectResponse
    {
        Gate::authorize('toggle', $template);

        $template->update(['is_active' => ! $template->is_active]);

        return redirect()->route('admin.presences.modeles.index')
            ->with('success', 'Statut du modèle « '.$template->name.' » actualisé.');
    }

    public function destroy(AttendanceTemplate $template): RedirectResponse
    {
        Gate::authorize('delete', $template);

        if (! $template->peutEtreSupprime()) {
            abort(403, 'Les modèles système sont protégés et ne peuvent pas être supprimés.');
        }

        $code = $template->code;
        $nom = $template->name;
        $template->delete();

        IntegrationLog::tracer(
            'attendance',
            'template_manage',
            'ATTENDANCE_TEMPLATE_DELETED',
            'completed',
            null,
            200,
            null,
            null,
            (string) Str::uuid(),
            'TMPL-'.$code
        );

        return redirect()->route('admin.presences.modeles.index')
            ->with('success', 'Modèle « '.$nom.' » supprimé avec succès.');
    }

    public function apercu(
        AttendanceTemplate $template,
        AttendanceTemplateRenderer $renderer
    ): Response {
        Gate::authorize('view', $template);

        $html = $renderer->rendreHtml($template, [
            'titre' => 'Aperçu : '.$template->name,
            'seance' => 'Séance démo',
            'is_preview' => true,
        ], auth()->user());

        return response($html)->header('Content-Type', 'text/html');
    }

    public function apercuPdf(
        AttendanceTemplate $template,
        AttendanceTemplateRenderer $renderer
    ): Response {
        Gate::authorize('view', $template);

        $pdf = $renderer->genererPdf($template, [
            'titre' => 'Aperçu : '.$template->name,
            'seance' => 'Séance démo',
            'is_preview' => true,
        ], auth()->user());

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="apercu-'.$template->code.'.pdf"',
        ]);
    }
}

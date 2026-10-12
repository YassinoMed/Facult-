<?php

namespace App\Livewire;

use App\Models\IntegrationLog;
use App\Modules\Attendance\Models\AttendanceTemplate;
use App\Modules\Attendance\Services\AttendanceTemplateBlockValidator;
use App\Modules\Attendance\Services\AttendanceTemplateRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Cockpit Builder visuel des modèles de présence (§10-12, §27-30).
 *
 * Architecture :
 * - Livewire : état, sélection, réorganisation, configuration des blocs, validation, audit ;
 * - Alpine.js : drag & drop léger, repliement des panneaux, aperçu réactif ;
 * - Moteur unique : AttendanceTemplateRenderer + DompdfPdfGenerator ;
 * - Zéro PII : données fictives contrôlées (Ahmed BEN ALI, etc.).
 */
class AttendanceTemplateBuilder extends Component
{
    public string $search = '';

    public string $typeFilter = '';

    public string $systemFilter = '';

    public string $activeFilter = '';

    public ?int $selectedTemplateId = null;

    public string $activeTab = 'editor'; // 'editor' | 'preview'

    public int $selectedBlockIndex = 0;

    // Attributs du modèle en cours d'édition
    public string $name = '';

    public string $code = '';

    public string $description = '';

    public string $type = 'custom';

    public bool $is_active = true;

    public bool $is_system = false;

    public ?int $faculte_id = null;

    public string $orientation = 'portrait';

    public string $paper = 'A4';

    /** @var array<int, array{type: string, position: int, enabled: bool, config: array<string, mixed>}> */
    public array $blocks = [];

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function mount(?int $initialTemplateId = null): void
    {
        Gate::authorize('viewAny', AttendanceTemplate::class);

        if ($initialTemplateId !== null) {
            $this->selectTemplate($initialTemplateId);
        } else {
            $premier = AttendanceTemplate::query()
                ->orderByDesc('is_system')
                ->orderBy('name')
                ->first();

            if ($premier !== null) {
                $this->selectTemplate($premier->id);
            } else {
                $this->createNewTemplate();
            }
        }
    }

    /**
     * Sélectionne un modèle existant pour affichage et édition.
     */
    public function selectTemplate(int $id): void
    {
        /** @var AttendanceTemplate $template */
        $template = AttendanceTemplate::findOrFail($id);

        Gate::authorize('view', $template);

        $this->selectedTemplateId = $template->id;
        $this->name = $template->name;
        $this->code = $template->code;
        $this->description = (string) ($template->description ?? '');
        $this->type = $template->type;
        $this->is_active = (bool) $template->is_active;
        $this->is_system = (bool) $template->is_system;
        $this->faculte_id = $template->faculte_id;
        $this->orientation = $template->orientation();
        $this->paper = $template->formatPapier();

        $blocs = $template->blocsOrdonnes();
        if (empty($blocs)) {
            $blocs = $template->blocsParDefaut();
        }
        $this->blocks = $blocs;
        $this->selectedBlockIndex = 0;
        $this->statusMessage = null;
        $this->errorMessage = null;
    }

    /**
     * Initialise le builder pour créer un nouveau modèle personnalisé.
     */
    public function createNewTemplate(): void
    {
        Gate::authorize('create', AttendanceTemplate::class);

        $dummy = new AttendanceTemplate(['type' => 'custom']);
        $this->selectedTemplateId = null;
        $this->name = 'Nouveau modèle personnalisé';
        $this->code = 'custom_'.Str::lower(Str::random(6));
        $this->description = 'Modèle créé via le builder visuel';
        $this->type = 'custom';
        $this->is_active = true;
        $this->is_system = false;
        $this->faculte_id = auth()->user()?->faculte_id;
        $this->orientation = 'portrait';
        $this->paper = 'A4';
        $this->blocks = $dummy->blocsParDefaut();
        $this->selectedBlockIndex = 0;
        $this->statusMessage = 'Nouveau modèle prêt. Modifiez les blocs et enregistrez.';
        $this->errorMessage = null;
    }

    /**
     * Sélectionne le bloc actif dans l'inspecteur d'options.
     */
    public function selectBlock(int $index): void
    {
        if (isset($this->blocks[$index])) {
            $this->selectedBlockIndex = $index;
        }
    }

    /**
     * Active ou désactive un bloc.
     */
    public function toggleBlock(int $index): void
    {
        $this->verifierDroitGestion();

        if (isset($this->blocks[$index])) {
            $this->blocks[$index]['enabled'] = ! ($this->blocks[$index]['enabled'] ?? true);
        }
    }

    /**
     * Déplace un bloc d'une position à une autre (Drag & Drop Alpine ou boutons).
     */
    public function moveBlock(int $fromIndex, int $toIndex): void
    {
        $this->verifierDroitGestion();

        if (! isset($this->blocks[$fromIndex]) || ! isset($this->blocks[$toIndex])) {
            return;
        }

        $deplace = array_splice($this->blocks, $fromIndex, 1)[0];
        array_splice($this->blocks, $toIndex, 0, [$deplace]);

        $this->recalculerPositions();
        $this->selectedBlockIndex = $toIndex;
    }

    public function moveBlockUp(int $index): void
    {
        if ($index > 0) {
            $this->moveBlock($index, $index - 1);
        }
    }

    public function moveBlockDown(int $index): void
    {
        if ($index < count($this->blocks) - 1) {
            $this->moveBlock($index, $index + 1);
        }
    }

    /**
     * Réordonne la liste selon les types de blocs reçus (payload JS).
     *
     * @param  array<int, string>  $orderedTypes
     */
    public function reorderBlocks(array $orderedTypes): void
    {
        $this->verifierDroitGestion();

        $indexe = [];
        foreach ($this->blocks as $b) {
            $indexe[$b['type']] = $b;
        }

        $nouveauxBlocs = [];
        foreach ($orderedTypes as $type) {
            if (isset($indexe[$type])) {
                $nouveauxBlocs[] = $indexe[$type];
                unset($indexe[$type]);
            }
        }

        foreach ($indexe as $restant) {
            $nouveauxBlocs[] = $restant;
        }

        $this->blocks = $nouveauxBlocs;
        $this->recalculerPositions();
    }

    /**
     * Ajoute un bloc disponible si non encore présent.
     */
    public function addBlock(string $type): void
    {
        $this->verifierDroitGestion();

        if (! in_array($type, AttendanceTemplateBlockValidator::TYPES_BLOCS_AUTORISES, true)) {
            $this->errorMessage = 'Type de bloc non autorisé.';

            return;
        }

        $existe = array_filter($this->blocks, fn ($b) => $b['type'] === $type);
        if (! empty($existe)) {
            $this->errorMessage = "Le bloc « {$type} » est déjà présent dans ce modèle.";

            return;
        }

        $validateur = app(AttendanceTemplateBlockValidator::class);
        $configParDefaut = $validateur->validerEtNettoyerConfig($type, []);

        $this->blocks[] = [
            'type' => $type,
            'position' => count($this->blocks) + 1,
            'enabled' => true,
            'config' => $configParDefaut,
        ];

        $this->recalculerPositions();
        $this->selectedBlockIndex = count($this->blocks) - 1;
        $this->statusMessage = "Bloc « {$type} » ajouté.";
    }

    /**
     * Supprime un bloc du modèle.
     */
    public function removeBlock(int $index): void
    {
        $this->verifierDroitGestion();

        if (isset($this->blocks[$index])) {
            $type = $this->blocks[$index]['type'];
            array_splice($this->blocks, $index, 1);
            $this->recalculerPositions();
            $this->selectedBlockIndex = max(0, min($this->selectedBlockIndex, count($this->blocks) - 1));
            $this->statusMessage = "Bloc « {$type} » retiré du modèle.";
        }
    }

    /**
     * Met à jour une option de configuration dans le bloc sélectionné.
     */
    public function updateBlockOption(string $key, mixed $value): void
    {
        $this->verifierDroitGestion();

        if (isset($this->blocks[$this->selectedBlockIndex])) {
            $this->blocks[$this->selectedBlockIndex]['config'][$key] = $value;
        }
    }

    /**
     * Enregistre le modèle courant (création ou mise à jour).
     */
    public function save(): void
    {
        $this->statusMessage = null;
        $this->errorMessage = null;

        if ($this->selectedTemplateId !== null) {
            $template = AttendanceTemplate::findOrFail($this->selectedTemplateId);
            Gate::authorize('update', $template);
        } else {
            Gate::authorize('create', AttendanceTemplate::class);
        }

        // 1. Validation stricte des données du formulaire
        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-zA-Z0-9_\-]+$/',
                Rule::unique('attendance_templates', 'code')->ignore($this->selectedTemplateId),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'orientation' => ['required', Rule::in(['portrait', 'landscape'])],
            'paper' => ['required', Rule::in(['A4', 'A3', 'Letter'])],
        ]);

        // Nettoyage XSS anti-injection
        $nomNettoye = mb_substr(strip_tags(trim($this->name)), 0, 150);
        $descriptionNettoyee = mb_substr(strip_tags(trim($this->description)), 0, 500);

        // 2. Validation stricte JSON des blocs
        try {
            $validateur = app(AttendanceTemplateBlockValidator::class);
            $blocsValides = $validateur->validerBlocs($this->blocks);
        } catch (ValidationException $e) {
            $this->errorMessage = 'Erreur de configuration des blocs : '.$e->getMessage();

            return;
        }

        if ($this->selectedTemplateId !== null) {
            /** @var AttendanceTemplate $template */
            $template = AttendanceTemplate::findOrFail($this->selectedTemplateId);

            $config = $template->configuration ?? [];
            $config['orientation'] = $this->orientation;
            $config['paper'] = $this->paper;
            $config['blocks'] = $blocsValides;

            $template->update([
                'name' => $nomNettoye,
                'code' => $this->code,
                'description' => $descriptionNettoyee,
                'configuration' => $config,
                'is_active' => $this->is_active,
                'updated_by' => auth()->id(),
            ]);

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

            $this->blocks = $blocsValides;
            $this->statusMessage = "Modèle « {$template->name} » mis à jour avec succès.";
        } else {
            $nouveau = AttendanceTemplate::create([
                'name' => $nomNettoye,
                'code' => $this->code,
                'description' => $descriptionNettoyee,
                'type' => 'custom',
                'faculte_id' => $this->faculte_id ?? auth()->user()?->faculte_id,
                'is_system' => false,
                'is_active' => $this->is_active,
                'configuration' => [
                    'orientation' => $this->orientation,
                    'paper' => $this->paper,
                    'blocks' => $blocsValides,
                    'columns' => AttendanceTemplate::COLONNES_PAR_DEFAUT,
                ],
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

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
                'TMPL-'.$nouveau->code
            );

            $this->selectedTemplateId = $nouveau->id;
            $this->blocks = $blocsValides;
            $this->statusMessage = "Modèle personnalisé « {$nouveau->name} » créé avec succès.";
        }
    }

    /**
     * Duplique le modèle sélectionné.
     */
    public function duplicate(): void
    {
        if ($this->selectedTemplateId === null) {
            return;
        }

        /** @var AttendanceTemplate $template */
        $template = AttendanceTemplate::findOrFail($this->selectedTemplateId);
        Gate::authorize('duplicate', $template);

        $copie = $template->dupliquer(
            'Copie de '.$template->name,
            auth()->user()
        );

        IntegrationLog::tracer(
            'attendance',
            'template_manage',
            'ATTENDANCE_TEMPLATE_DUPLICATED',
            'completed',
            null,
            201,
            null,
            null,
            (string) Str::uuid(),
            'TMPL-'.$copie->code
        );

        $this->selectTemplate($copie->id);
        $this->statusMessage = "Modèle dupliqué sous le nom « {$copie->name} ».";
    }

    /**
     * Active ou désactive le modèle sélectionné.
     */
    public function toggleActive(): void
    {
        if ($this->selectedTemplateId === null) {
            return;
        }

        /** @var AttendanceTemplate $template */
        $template = AttendanceTemplate::findOrFail($this->selectedTemplateId);
        Gate::authorize('toggle', $template);

        $template->update(['is_active' => ! $template->is_active]);
        $this->is_active = (bool) $template->is_active;

        $operation = $template->is_active ? 'ATTENDANCE_TEMPLATE_ENABLED' : 'ATTENDANCE_TEMPLATE_DISABLED';
        IntegrationLog::tracer(
            'attendance',
            'template_manage',
            $operation,
            'completed',
            null,
            200,
            null,
            null,
            (string) Str::uuid(),
            'TMPL-'.$template->code
        );

        $this->statusMessage = 'Statut du modèle actualisé : '.($this->is_active ? 'Actif' : 'Inactif').'.';
    }

    /**
     * Supprime le modèle personnalisé (interdit sur les modèles système).
     */
    public function deleteTemplate(): void
    {
        if ($this->selectedTemplateId === null) {
            return;
        }

        /** @var AttendanceTemplate $template */
        $template = AttendanceTemplate::findOrFail($this->selectedTemplateId);

        if (! $template->peutEtreSupprime()) {
            $this->errorMessage = 'Les modèles système sont protégés et ne peuvent pas être supprimés.';

            return;
        }

        Gate::authorize('delete', $template);

        $nom = $template->name;
        $code = $template->code;
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

        $suivant = AttendanceTemplate::query()->first();
        if ($suivant !== null) {
            $this->selectTemplate($suivant->id);
        } else {
            $this->createNewTemplate();
        }

        $this->statusMessage = "Modèle « {$nom} » supprimé.";
    }

    /**
     * Génère l'aperçu HTML instantané en mémoire avec le moteur officiel.
     */
    public function getPreviewHtmlProperty(): string
    {
        $temp = new AttendanceTemplate([
            'id' => $this->selectedTemplateId,
            'name' => $this->name ?: 'Fiche de présence',
            'code' => $this->code ?: 'preview_builder',
            'type' => $this->type ?: 'custom',
            'is_system' => $this->is_system,
            'is_active' => $this->is_active,
            'faculte_id' => $this->faculte_id,
            'configuration' => [
                'orientation' => $this->orientation,
                'paper' => $this->paper,
                'blocks' => $this->blocks,
                'columns' => AttendanceTemplate::COLONNES_PAR_DEFAUT,
            ],
        ]);

        /** @var AttendanceTemplateRenderer $renderer */
        $renderer = app(AttendanceTemplateRenderer::class);

        return $renderer->rendreHtml($temp, [
            'titre' => $this->name ?: 'Fiche de présence',
            'seance' => 'Séance 1 (08:30 - 10:00)',
            'is_preview' => true,
        ], auth()->user());
    }

    /**
     * Liste filtrée des modèles pour la barre latérale.
     */
    public function getTemplatesListProperty()
    {
        $query = AttendanceTemplate::query()
            ->orderByDesc('is_system')
            ->orderBy('name');

        if (! empty($this->search)) {
            $q = trim($this->search);
            $query->where(fn ($sub) => $sub->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%"));
        }

        if (! empty($this->typeFilter)) {
            $query->where('type', $this->typeFilter);
        }

        if ($this->systemFilter !== '') {
            $query->where('is_system', $this->systemFilter === '1');
        }

        if ($this->activeFilter !== '') {
            $query->where('is_active', $this->activeFilter === '1');
        }

        return $query->get();
    }

    private function recalculerPositions(): void
    {
        foreach ($this->blocks as $idx => &$b) {
            $b['position'] = $idx + 1;
        }
        unset($b);
    }

    private function verifierDroitGestion(): void
    {
        if (! auth()->user()?->hasPermission('attendance.templates.manage')) {
            abort(403, 'Permission attendance.templates.manage requise.');
        }
    }

    public function render(): View
    {
        return view('livewire.attendance-template-builder', [
            'templates' => $this->templatesList,
            'types' => AttendanceTemplate::TYPES,
            'allowedBlockTypes' => AttendanceTemplateBlockValidator::TYPES_BLOCS_AUTORISES,
        ]);
    }
}

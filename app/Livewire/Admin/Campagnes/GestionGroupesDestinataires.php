<?php

namespace App\Livewire\Admin\Campagnes;

use App\Models\Faculte;
use App\Models\GradeEnseignant;
use App\Models\GroupeDestinataires;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\Specialite;
use App\Services\ResolutionDestinatairesService;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Gestion des groupes enregistrés de destinataires (LOOP-MAIL-CAMPAIGNS-01).
 * Groupes dynamiques avec prévisualisation du recalcul et listes fixes.
 */
#[Layout('layouts.app')]
class GestionGroupesDestinataires extends Component
{
    use WithPagination;

    #[Url]
    public string $recherche = '';

    public bool $afficherArchives = false;

    // Modale création / édition
    public bool $modalOuverte = false;

    public ?int $groupeEnCoursId = null;

    public string $nom = '';

    public string $description = '';

    public string $type = GroupeDestinataires::TYPE_DYNAMIQUE_ETUDIANTS;

    public string $audience = GroupeDestinataires::AUDIENCE_ETUDIANTS;

    // Critères dynamiques étudiants
    public ?int $filtreFaculteId = null;

    public ?int $filtreProgrammeId = null;

    public ?string $filtreNiveau = null;

    public ?string $filtreAnneeAcademique = null;

    public ?string $filtreStatutEtudiant = null;

    // Critères dynamiques enseignants
    public ?int $filtreDepartementId = null;

    public ?int $filtreGradeId = null;

    public ?int $filtreSpecialiteId = null;

    public int $estimationMembres = 0;

    public ?string $messageSucces = null;

    public ?string $messageErreur = null;

    public function mount(): void
    {
        $this->authorize('campagnes.view');
    }

    public function ouvrirCreation(): void
    {
        $this->authorize('campagnes.manage');

        $this->reset([
            'groupeEnCoursId',
            'nom',
            'description',
            'filtreFaculteId',
            'filtreProgrammeId',
            'filtreNiveau',
            'filtreAnneeAcademique',
            'filtreStatutEtudiant',
            'filtreDepartementId',
            'filtreGradeId',
            'filtreSpecialiteId',
            'messageErreur',
        ]);

        $this->type = GroupeDestinataires::TYPE_DYNAMIQUE_ETUDIANTS;
        $this->audience = GroupeDestinataires::AUDIENCE_ETUDIANTS;
        $this->actualiserEstimation();
        $this->modalOuverte = true;
    }

    public function editerGroupe(int $id): void
    {
        $this->authorize('campagnes.manage');

        $groupe = GroupeDestinataires::findOrFail($id);
        $this->groupeEnCoursId = $groupe->id;
        $this->nom = $groupe->nom;
        $this->description = (string) $groupe->description;
        $this->type = $groupe->type;
        $this->audience = $groupe->audience;

        $c = (array) ($groupe->criteres ?? []);
        $this->filtreFaculteId = $c['faculte_id'] ?? null;
        $this->filtreProgrammeId = $c['programme_id'] ?? null;
        $this->filtreNiveau = $c['niveau'] ?? null;
        $this->filtreAnneeAcademique = $c['annee_academique'] ?? null;
        $this->filtreStatutEtudiant = $c['statut'] ?? null;

        $this->filtreDepartementId = $c['departement_id'] ?? null;
        $this->filtreGradeId = $c['grade_id'] ?? null;
        $this->filtreSpecialiteId = $c['specialite_id'] ?? null;

        $this->actualiserEstimation();
        $this->modalOuverte = true;
    }

    public function actualiserEstimation(): void
    {
        $service = app(ResolutionDestinatairesService::class);

        try {
            if ($this->type === GroupeDestinataires::TYPE_DYNAMIQUE_ETUDIANTS) {
                $filtres = array_filter([
                    'faculte_id' => $this->filtreFaculteId,
                    'programme_id' => $this->filtreProgrammeId,
                    'niveau' => $this->filtreNiveau,
                    'annee_academique' => $this->filtreAnneeAcademique,
                    'statut' => $this->filtreStatutEtudiant,
                ]);
                $this->estimationMembres = $service->requeteEtudiants($filtres)->count();
            } elseif ($this->type === GroupeDestinataires::TYPE_DYNAMIQUE_ENSEIGNANTS) {
                $filtres = array_filter([
                    'departement_id' => $this->filtreDepartementId,
                    'grade_id' => $this->filtreGradeId,
                    'specialite_id' => $this->filtreSpecialiteId,
                ]);
                $this->estimationMembres = $service->requeteEnseignants($filtres)->count();
            } else {
                $this->estimationMembres = 0;
            }
        } catch (\Throwable) {
            $this->estimationMembres = 0;
        }
    }

    public function updatedType(): void
    {
        if ($this->type === GroupeDestinataires::TYPE_DYNAMIQUE_ETUDIANTS) {
            $this->audience = GroupeDestinataires::AUDIENCE_ETUDIANTS;
        } elseif ($this->type === GroupeDestinataires::TYPE_DYNAMIQUE_ENSEIGNANTS) {
            $this->audience = GroupeDestinataires::AUDIENCE_ENSEIGNANTS;
        }
        $this->actualiserEstimation();
    }

    public function updatedFiltreFaculteId(): void
    {
        $this->actualiserEstimation();
    }

    public function updatedFiltreProgrammeId(): void
    {
        $this->actualiserEstimation();
    }

    public function updatedFiltreNiveau(): void
    {
        $this->actualiserEstimation();
    }

    public function updatedFiltreAnneeAcademique(): void
    {
        $this->actualiserEstimation();
    }

    public function updatedFiltreStatutEtudiant(): void
    {
        $this->actualiserEstimation();
    }

    public function updatedFiltreDepartementId(): void
    {
        $this->actualiserEstimation();
    }

    public function updatedFiltreGradeId(): void
    {
        $this->actualiserEstimation();
    }

    public function updatedFiltreSpecialiteId(): void
    {
        $this->actualiserEstimation();
    }

    public function sauvegarder(): void
    {
        $this->authorize('campagnes.manage');

        if (trim($this->nom) === '' || mb_strlen($this->nom) > 120) {
            $this->messageErreur = 'Le nom du groupe est obligatoire (max 120 caractères).';

            return;
        }

        $criteres = [];
        if ($this->type === GroupeDestinataires::TYPE_DYNAMIQUE_ETUDIANTS) {
            $criteres = array_filter([
                'faculte_id' => $this->filtreFaculteId,
                'programme_id' => $this->filtreProgrammeId,
                'niveau' => $this->filtreNiveau,
                'annee_academique' => $this->filtreAnneeAcademique,
                'statut' => $this->filtreStatutEtudiant,
            ]);
        } elseif ($this->type === GroupeDestinataires::TYPE_DYNAMIQUE_ENSEIGNANTS) {
            $criteres = array_filter([
                'departement_id' => $this->filtreDepartementId,
                'grade_id' => $this->filtreGradeId,
                'specialite_id' => $this->filtreSpecialiteId,
            ]);
        }

        $donnees = [
            'nom' => trim($this->nom),
            'description' => trim($this->description) ?: null,
            'type' => $this->type,
            'audience' => $this->audience,
            'criteres' => ! empty($criteres) ? $criteres : null,
            'auteur_id' => auth()->id(),
        ];

        if ($this->groupeEnCoursId) {
            $groupe = GroupeDestinataires::findOrFail($this->groupeEnCoursId);
            $groupe->update($donnees);
            $this->messageSucces = "Le groupe « {$groupe->nom} » a été mis à jour.";
        } else {
            $groupe = GroupeDestinataires::create($donnees);
            $this->messageSucces = "Le groupe « {$groupe->nom} » a été créé.";
        }

        $this->modalOuverte = false;
    }

    public function archiverGroupe(int $id): void
    {
        $this->authorize('campagnes.manage');

        $groupe = GroupeDestinataires::findOrFail($id);
        $groupe->update(['est_archive' => ! $groupe->est_archive]);

        $etat = $groupe->est_archive ? 'archivé' : 'restauré';
        $this->messageSucces = "Le groupe « {$groupe->nom} » a été {$etat}.";
    }

    public function dupliquerGroupe(int $id): void
    {
        $this->authorize('campagnes.manage');

        $source = GroupeDestinataires::findOrFail($id);
        $copie = $source->replicate([
            'nom' => $source->nom.' (Copie)',
            'est_archive' => false,
            'created_at',
            'updated_at',
        ]);
        $copie->nom = mb_substr($source->nom.' (Copie)', 0, 120);
        $copie->save();

        $this->messageSucces = "Le groupe a été dupliqué sous le nom « {$copie->nom} ».";
    }

    public function render(): View
    {
        $this->authorize('campagnes.view');

        $query = GroupeDestinataires::query()->latest('id');

        if (! $this->afficherArchives) {
            $query->where('est_archive', false);
        }

        if ($this->recherche !== '') {
            $terme = '%'.mb_substr(trim($this->recherche), 0, 80).'%';
            $query->where(function ($q) use ($terme) {
                $q->where('nom', 'like', $terme)
                    ->orWhere('description', 'like', $terme);
            });
        }

        return view('livewire.admin.campagnes.gestion-groupes', [
            'groupes' => $query->paginate(15),
            'facultes' => Faculte::orderBy('nom')->get(),
            'programmes' => Programme::orderBy('nom')->get(),
            'departements' => Departement::orderBy('nom')->get(),
            'grades' => GradeEnseignant::orderBy('nom')->get(),
            'specialites' => Specialite::orderBy('nom')->get(),
        ]);
    }
}

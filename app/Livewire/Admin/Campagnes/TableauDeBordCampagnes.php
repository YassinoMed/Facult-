<?php

namespace App\Livewire\Admin\Campagnes;

use App\Models\CampagneCourriel;
use App\Services\CampagneCourrielService;
use DomainException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tableau de bord principal des campagnes de courriels (LOOP-MAIL-CAMPAIGNS-01).
 * Suivi en temps réel des compteurs par état, filtrage paginé et actions de cycle de vie.
 */
#[Layout('layouts.app')]
class TableauDeBordCampagnes extends Component
{
    use WithPagination;

    #[Url]
    public string $recherche = '';

    #[Url]
    public string $filtreStatut = '';

    public ?string $messageSucces = null;

    public ?string $messageErreur = null;

    public function updatingRecherche(): void
    {
        $this->resetPage();
    }

    public function updatingFiltreStatut(): void
    {
        $this->resetPage();
    }

    public function annulerCampagne(int $campagneId, CampagneCourrielService $service): void
    {
        $this->authorize('campagnes.manage');

        $campagne = CampagneCourriel::findOrFail($campagneId);

        try {
            $service->annuler($campagne);
            $this->messageSucces = "La campagne « {$campagne->titre} » a été annulée.";
            $this->messageErreur = null;
        } catch (DomainException $e) {
            $this->messageErreur = $e->getMessage();
            $this->messageSucces = null;
        }
    }

    public function relancerCampagne(int $campagneId, CampagneCourrielService $service): void
    {
        $this->authorize('campagnes.manage');
        $this->authorize('mail.send');

        $campagne = CampagneCourriel::findOrFail($campagneId);

        try {
            $service->relancerEchecs($campagne);
            $this->messageSucces = "La relance des échecs de la campagne « {$campagne->titre} » a été déclenchée.";
            $this->messageErreur = null;
        } catch (DomainException $e) {
            $this->messageErreur = $e->getMessage();
            $this->messageSucces = null;
        }
    }

    public function supprimerBrouillon(int $campagneId): void
    {
        $this->authorize('campagnes.manage');

        $campagne = CampagneCourriel::findOrFail($campagneId);
        if ($campagne->statut !== CampagneCourriel::STATUT_BROUILLON) {
            $this->messageErreur = 'Seul un brouillon peut être supprimé.';

            return;
        }

        $campagne->delete();
        $this->messageSucces = 'Le brouillon a été supprimé.';
    }

    public function render(): View
    {
        $this->authorize('campagnes.view');

        $requete = CampagneCourriel::query()->latest('id');

        if ($this->filtreStatut !== '' && in_array($this->filtreStatut, CampagneCourriel::STATUTS, true)) {
            $requete->where('statut', $this->filtreStatut);
        }

        if ($this->recherche !== '') {
            $terme = '%'.mb_substr(trim($this->recherche), 0, 80).'%';
            $requete->where(function ($q) use ($terme) {
                $q->where('titre', 'like', $terme)
                    ->orWhere('sujet', 'like', $terme);
            });
        }

        $compteurs = [
            'brouillons' => CampagneCourriel::where('statut', CampagneCourriel::STATUT_BROUILLON)->count(),
            'planifiees' => CampagneCourriel::where('statut', CampagneCourriel::STATUT_PLANIFIEE)->count(),
            'en_cours' => CampagneCourriel::whereIn('statut', [CampagneCourriel::STATUT_EN_ATTENTE, CampagneCourriel::STATUT_EN_COURS])->count(),
            'terminees' => CampagneCourriel::where('statut', CampagneCourriel::STATUT_TERMINEE)->count(),
            'erreurs' => CampagneCourriel::whereIn('statut', [CampagneCourriel::STATUT_TERMINEE_ERREURS, CampagneCourriel::STATUT_ECHOUEE])->count(),
            'total' => CampagneCourriel::count(),
        ];

        return view('livewire.admin.campagnes.tableau-de-bord', [
            'campagnes' => $requete->paginate(15),
            'compteurs' => $compteurs,
            'statuts' => CampagneCourriel::STATUTS,
        ]);
    }
}

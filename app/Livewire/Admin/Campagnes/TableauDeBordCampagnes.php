<?php

namespace App\Livewire\Admin\Campagnes;

use App\Models\CampagneCourriel;
use App\Services\CampagneCourrielService;
use App\Support\CampagnePerimetre;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tableau de bord principal des campagnes de courriels (LOOP-MAIL-CAMPAIGNS-01).
 * Suivi en temps réel des compteurs par état, filtrage paginé et actions de cycle de vie.
 *
 * LOOP-SECURITY-REMEDIATION-01 : un administrateur de faculté ne voit et
 * ne pilote que les campagnes de SA faculté (marqueur `filtres.faculte_id`
 * ou groupe enregistré rattaché) — liste, compteurs et actions compris.
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
        abort_unless(CampagnePerimetre::campagneAutorisee($campagne, CampagnePerimetre::faculteAutorisee()), 404);

        try {
            $service->annuler($campagne, CampagnePerimetre::faculteAutorisee());
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
        abort_unless(CampagnePerimetre::campagneAutorisee($campagne, CampagnePerimetre::faculteAutorisee()), 404);

        try {
            $service->relancerEchecs($campagne, CampagnePerimetre::faculteAutorisee());
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
        abort_unless(CampagnePerimetre::campagneAutorisee($campagne, CampagnePerimetre::faculteAutorisee()), 404);

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

        $faculte = CampagnePerimetre::faculteAutorisee();

        $requete = CampagneCourriel::query()->latest('id');
        $this->appliquerPerimetre($requete, $faculte);

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

        $compter = function (array $statuts = []) use ($faculte): int {
            $q = CampagneCourriel::query();

            if (! empty($statuts)) {
                $q->whereIn('statut', $statuts);
            }

            $this->appliquerPerimetre($q, $faculte);

            return $q->count();
        };

        $compteurs = [
            'brouillons' => $compter([CampagneCourriel::STATUT_BROUILLON]),
            'planifiees' => $compter([CampagneCourriel::STATUT_PLANIFIEE]),
            'en_cours' => $compter([CampagneCourriel::STATUT_EN_ATTENTE, CampagneCourriel::STATUT_EN_COURS]),
            'terminees' => $compter([CampagneCourriel::STATUT_TERMINEE]),
            'erreurs' => $compter([CampagneCourriel::STATUT_TERMINEE_ERREURS, CampagneCourriel::STATUT_ECHOUEE]),
            'total' => $compter(),
        ];

        return view('livewire.admin.campagnes.tableau-de-bord', [
            'campagnes' => $requete->paginate(15),
            'compteurs' => $compteurs,
            'statuts' => CampagneCourriel::STATUTS,
        ]);
    }

    /**
     * Restreint la requête au périmètre faculté : campagne marquée sur ses
     * filtres OU rattachée à un groupe enregistré de la faculté.
     */
    private function appliquerPerimetre(Builder $requete, ?int $faculte): void
    {
        if ($faculte === null) {
            return;
        }

        $requete->where(function (Builder $q) use ($faculte) {
            $q->where('filtres->faculte_id', $faculte)
                ->orWhereHas('groupeEnregistre', fn (Builder $g) => $g->where('criteres->faculte_id', $faculte));
        });
    }
}

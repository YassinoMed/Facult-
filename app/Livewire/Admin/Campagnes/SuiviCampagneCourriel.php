<?php

namespace App\Livewire\Admin\Campagnes;

use App\Models\CampagneCourriel;
use App\Models\CampagneCourrielDestinataire;
use App\Services\CampagneCourrielService;
use DomainException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Suivi en temps réel et historique détaillé d'une campagne de courriels (LOOP-MAIL-CAMPAIGNS-01).
 * Jauge de progression, ventilation des statuts, audit des erreurs expurgées
 * et relance sélective des échecs.
 */
#[Layout('layouts.app')]
class SuiviCampagneCourriel extends Component
{
    use WithPagination;

    public CampagneCourriel $campagne;

    #[Url]
    public string $rechercheDestinataire = '';

    #[Url]
    public string $filtreStatutDestinataire = '';

    public ?string $messageSucces = null;

    public ?string $messageErreur = null;

    public function mount(CampagneCourriel $campagne): void
    {
        $this->authorize('campagnes.view');
        $this->campagne = $campagne;
    }

    public function updatingRechercheDestinataire(): void
    {
        $this->resetPage();
    }

    public function updatingFiltreStatutDestinataire(): void
    {
        $this->resetPage();
    }

    public function rafraichir(): void
    {
        $this->campagne->refresh();
        $this->campagne->recalculerCompteurs();
    }

    public function annuler(CampagneCourrielService $service): void
    {
        $this->authorize('campagnes.manage');

        try {
            $service->annuler($this->campagne);
            $this->campagne->refresh();
            $this->messageSucces = 'La campagne a été annulée avec succès.';
            $this->messageErreur = null;
        } catch (DomainException $e) {
            $this->messageErreur = $e->getMessage();
            $this->messageSucces = null;
        }
    }

    public function relancerEchecs(CampagneCourrielService $service): void
    {
        $this->authorize('campagnes.manage');
        $this->authorize('mail.send');

        try {
            $service->relancerEchecs($this->campagne);
            $this->campagne->refresh();
            $this->messageSucces = 'La relance des destinataires en échec a été lancée.';
            $this->messageErreur = null;
        } catch (DomainException $e) {
            $this->messageErreur = $e->getMessage();
            $this->messageSucces = null;
        }
    }

    public function render(): View
    {
        $this->authorize('campagnes.view');

        $query = $this->campagne->destinataires()->latest('id');

        if ($this->filtreStatutDestinataire !== '' && in_array($this->filtreStatutDestinataire, CampagneCourrielDestinataire::STATUTS, true)) {
            $query->where('statut', $this->filtreStatutDestinataire);
        }

        if ($this->rechercheDestinataire !== '') {
            $terme = '%'.mb_substr(trim($this->rechercheDestinataire), 0, 80).'%';
            $query->where(function ($q) use ($terme) {
                $q->where('nom_complet', 'like', $terme)
                    ->orWhere('email', 'like', $terme);
            });
        }

        $destinataires = $query->paginate(20);

        return view('livewire.admin.campagnes.suivi-campagne', [
            'destinataires' => $destinataires,
            'statutsDestinataires' => CampagneCourrielDestinataire::STATUTS,
        ]);
    }
}

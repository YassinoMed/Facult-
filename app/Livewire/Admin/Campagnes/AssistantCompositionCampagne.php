<?php

namespace App\Livewire\Admin\Campagnes;

use App\Models\CampagneCourriel;
use App\Models\Faculte;
use App\Models\GradeEnseignant;
use App\Models\GroupeDestinataires;
use App\Models\ModeleCourriel;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\Specialite;
use App\Modules\Schedule\Models\Group;
use App\Services\CampagneCourrielService;
use App\Services\MoteurRenduCourriel;
use App\Services\ResolutionDestinatairesService;
use Carbon\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Assistant de composition de campagne e-mail en 5 étapes (LOOP-MAIL-CAMPAIGNS-01).
 * Étape 1 : Type d'envoi
 * Étape 2 : Destinataires & Filtres
 * Étape 3 : Message & Modèle
 * Étape 4 : Vérification & Confirmation
 * Étape 5 : Exécution (Immédiat / Programmé / Brouillon)
 */
#[Layout('layouts.app')]
class AssistantCompositionCampagne extends Component
{
    use WithPagination;

    public ?int $campagneId = null;

    /** Étape courante (1 à 5). */
    public int $etape = 1;

    // Métadonnées principales
    public string $titre = '';

    public string $sujet = '';

    public string $typeCiblage = CampagneCourriel::CIBLAGE_DYNAMIQUE_ETUDIANTS;

    // Modèle & Contenu
    public ?int $modeleId = null;

    public string $contenuTexte = '';

    public string $contenuHtml = '';

    // Ciblage individuel
    public string $destinataireIndividuelEmail = '';

    public string $destinataireIndividuelNom = '';

    public string $destinataireIndividuelType = 'externe';

    public ?int $destinataireIndividuelId = null;

    // Filtres dynamiques étudiants
    public ?int $filtreFaculteId = null;

    public ?int $filtreProgrammeId = null;

    public ?int $filtreGroupeId = null;

    public ?string $filtreNiveau = null;

    public ?string $filtreAnneeAcademique = null;

    public ?string $filtreStatutEtudiant = null;

    public string $rechercheEtudiants = '';

    // Filtres dynamiques enseignants
    public ?int $filtreDepartementId = null;

    public ?int $filtreGradeId = null;

    public ?int $filtreSpecialiteId = null;

    public ?string $filtreStatutEnseignant = null;

    public ?bool $filtreActifEnseignant = true;

    public string $rechercheEnseignants = '';

    // Sélection liste fixe
    public array $selectionEtudiants = [];

    public array $selectionEnseignants = [];

    // Groupe enregistré
    public ?int $groupeEnregistreId = null;

    // Programmation & Exécution
    public ?string $dateProgrammation = null;

    public ?string $heureProgrammation = '08:00';

    public bool $confirmationExplicite = false;

    // Données d'estimation calculées
    public int $estimationValides = 0;

    public int $estimationDoublons = 0;

    public int $estimationInvalides = 0;

    public int $estimationBrut = 0;

    // Aperçu calculé
    public string $apercuHtml = '';

    public string $apercuTexte = '';

    public ?string $messageErreur = null;

    public function mount(?CampagneCourriel $campagne = null): void
    {
        $this->authorize('campagnes.manage');

        if ($campagne && $campagne->exists) {
            $this->campagneId = $campagne->id;
            $this->titre = $campagne->titre;
            $this->sujet = $campagne->sujet;
            $this->typeCiblage = $campagne->type_ciblage;
            $this->modeleId = $campagne->modele_id;
            $this->contenuTexte = (string) $campagne->contenu_texte;
            $this->contenuHtml = (string) $campagne->contenu_html;
            $this->groupeEnregistreId = $campagne->groupe_enregistre_id;

            if ($campagne->planifiee_a) {
                $this->dateProgrammation = $campagne->planifiee_a->format('Y-m-d');
                $this->heureProgrammation = $campagne->planifiee_a->format('H:i');
            }

            if ($campagne->filtres) {
                $f = $campagne->filtres;
                $this->filtreFaculteId = $f['faculte_id'] ?? null;
                $this->filtreProgrammeId = $f['programme_id'] ?? null;
                $this->filtreGroupeId = $f['groupe_id'] ?? null;
                $this->filtreNiveau = $f['niveau'] ?? null;
                $this->filtreAnneeAcademique = $f['annee_academique'] ?? null;
                $this->filtreStatutEtudiant = $f['statut'] ?? null;

                $this->filtreDepartementId = $f['departement_id'] ?? null;
                $this->filtreGradeId = $f['grade_id'] ?? null;
                $this->filtreSpecialiteId = $f['specialite_id'] ?? null;
                $this->filtreStatutEnseignant = $f['statut_enseignant'] ?? null;
            }
        } else {
            $this->dateProgrammation = now()->addDay()->format('Y-m-d');
        }

        $this->actualiserEstimation();
    }

    public function updatedTypeCiblage(): void
    {
        $this->actualiserEstimation();
    }

    public function updatedModeleId(): void
    {
        $this->actualiserApercu();
    }

    public function updatedFiltreFaculteId(): void
    {
        $this->actualiserEstimation();
    }

    public function updatedFiltreProgrammeId(): void
    {
        $this->actualiserEstimation();
    }

    public function updatedFiltreGroupeId(): void
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

    public function updatedFiltreStatutEnseignant(): void
    {
        $this->actualiserEstimation();
    }

    public function updatedGroupeEnregistreId(): void
    {
        $this->actualiserEstimation();
    }

    public function updatedSelectionEtudiants(): void
    {
        $this->actualiserEstimation();
    }

    public function updatedSelectionEnseignants(): void
    {
        $this->actualiserEstimation();
    }

    public function updatedDestinataireIndividuelEmail(): void
    {
        $this->actualiserEstimation();
    }

    public function changerEtape(int $nouvelleEtape): void
    {
        $this->messageErreur = null;

        if ($nouvelleEtape > $this->etape) {
            $this->validerEtapeCourante();
        }

        if ($this->messageErreur !== null) {
            return;
        }

        $this->etape = max(1, min(5, $nouvelleEtape));

        if ($this->etape === 3 || $this->etape === 4) {
            $this->actualiserApercu();
        }

        $this->actualiserEstimation();
    }

    private function validerEtapeCourante(): void
    {
        if ($this->etape === 1) {
            if (! in_array($this->typeCiblage, CampagneCourriel::CIBLAGES, true)) {
                $this->messageErreur = 'Veuillez sélectionner un type d’envoi valide.';
            }
        } elseif ($this->etape === 2) {
            $this->actualiserEstimation();
            if ($this->estimationValides === 0) {
                $this->messageErreur = 'Aucun destinataire valide n’a été trouvé avec les critères actuels.';
            }
        } elseif ($this->etape === 3) {
            if (trim($this->titre) === '') {
                $this->messageErreur = 'Le titre de la campagne est requis.';
            } elseif (trim($this->sujet) === '') {
                $this->messageErreur = 'L’objet du message est requis.';
            } elseif ($this->modeleId === null && trim($this->contenuTexte) === '') {
                $this->messageErreur = 'Veuillez choisir un modèle ou saisir le texte de votre message.';
            }
        } elseif ($this->etape === 4) {
            if (! $this->confirmationExplicite) {
                $this->messageErreur = 'Veuillez cocher la case de confirmation avant de poursuivre.';
            }
        }
    }

    public function actualiserEstimation(): void
    {
        $service = app(ResolutionDestinatairesService::class);
        $donnees = $this->recueillirDonneesCiblage();

        try {
            $res = $service->resoudre($this->typeCiblage, $donnees);
            $this->estimationBrut = $res['total_brut'];
            $this->estimationValides = $res['total_valides'];
            $this->estimationDoublons = $res['total_doublons'];
            $this->estimationInvalides = $res['total_invalides'];
        } catch (\Throwable) {
            $this->estimationBrut = 0;
            $this->estimationValides = 0;
            $this->estimationDoublons = 0;
            $this->estimationInvalides = 0;
        }
    }

    public function actualiserApercu(): void
    {
        $contexte = [
            'etablissement_nom' => config('app.name', 'Université Exemple'),
            'destinataire_nom' => 'Étudiant(e) / Enseignant(e) Exemple',
            'contact_email' => config('inscriptions.contact_scolarite.email', 'scolarite@univ.test'),
        ];

        if ($this->modeleId) {
            $modele = ModeleCourriel::with('versions')->find($this->modeleId);
            $version = $modele?->derniereVersion();
            if ($version) {
                $moteur = app(MoteurRenduCourriel::class);
                $rendu = $moteur->rendre((array) $version->schema, $contexte);
                $this->apercuHtml = $rendu['html'];
                $this->apercuTexte = $rendu['texte'];
                if ($this->sujet === '') {
                    $this->sujet = $rendu['sujet'];
                }

                return;
            }
        }

        $texte = $this->contenuTexte ?: 'Votre message apparaîtra ici.';
        $this->apercuTexte = $texte;
        $this->apercuHtml = '<div style="font-family:sans-serif;padding:20px;line-height:1.6;color:#1e293b;">'.nl2br(e($texte)).'</div>';
    }

    private function recueillirDonneesCiblage(): array
    {
        $donnees = [];

        if ($this->typeCiblage === CampagneCourriel::CIBLAGE_INDIVIDUEL) {
            $donnees = [
                'type_destinataire' => $this->destinataireIndividuelType,
                'reference_id' => $this->destinataireIndividuelId,
                'email' => $this->destinataireIndividuelEmail,
                'nom_complet' => $this->destinataireIndividuelNom,
            ];
        } elseif ($this->typeCiblage === CampagneCourriel::CIBLAGE_LISTE_FIXE) {
            $donnees = [
                'etudiant_ids' => array_keys(array_filter($this->selectionEtudiants)),
                'teacher_ids' => array_keys(array_filter($this->selectionEnseignants)),
            ];
        } elseif ($this->typeCiblage === CampagneCourriel::CIBLAGE_DYNAMIQUE_ETUDIANTS) {
            $donnees = [
                'filtres' => array_filter([
                    'faculte_id' => $this->filtreFaculteId,
                    'programme_id' => $this->filtreProgrammeId,
                    'groupe_id' => $this->filtreGroupeId,
                    'niveau' => $this->filtreNiveau,
                    'annee_academique' => $this->filtreAnneeAcademique,
                    'statut' => $this->filtreStatutEtudiant,
                    'recherche' => $this->rechercheEtudiants,
                ]),
            ];
        } elseif ($this->typeCiblage === CampagneCourriel::CIBLAGE_DYNAMIQUE_ENSEIGNANTS) {
            $donnees = [
                'filtres' => array_filter([
                    'departement_id' => $this->filtreDepartementId,
                    'grade_id' => $this->filtreGradeId,
                    'specialite_id' => $this->filtreSpecialiteId,
                    'statut' => $this->filtreStatutEnseignant,
                    'actif' => $this->filtreActifEnseignant,
                    'recherche' => $this->rechercheEnseignants,
                ], fn ($v) => $v !== null && $v !== ''),
            ];
        } elseif ($this->typeCiblage === CampagneCourriel::CIBLAGE_GROUPE_ENREGISTRE) {
            $donnees = [
                'groupe_enregistre_id' => $this->groupeEnregistreId,
            ];
        }

        return $donnees;
    }

    public function sauvegarderBrouillon(CampagneCourrielService $service): void
    {
        $this->authorize('campagnes.manage');

        if (trim($this->titre) === '') {
            $this->titre = 'Campagne du '.now()->format('d/m/Y H:i');
        }
        if (trim($this->sujet) === '') {
            $this->sujet = 'Information universitaire';
        }

        $donnees = [
            'titre' => $this->titre,
            'sujet' => $this->sujet,
            'type_ciblage' => $this->typeCiblage,
            'modele_id' => $this->modeleId,
            'contenu_texte' => $this->contenuTexte,
            'contenu_html' => $this->contenuHtml,
            'filtres' => $this->recueillirDonneesCiblage()['filtres'] ?? null,
            'groupe_enregistre_id' => $this->groupeEnregistreId,
        ];

        $campagne = $this->campagneId ? CampagneCourriel::find($this->campagneId) : null;
        $campagne = $service->enregistrerBrouillon($donnees, $campagne, auth()->id());
        $service->figerDestinataires($campagne, $this->recueillirDonneesCiblage());

        $this->redirectRoute('admin.campagnes-courriel.details', $campagne);
    }

    public function planifierCampagne(CampagneCourrielService $service): void
    {
        $this->authorize('campagnes.manage');
        $this->validerEtapeCourante();

        if ($this->messageErreur) {
            return;
        }

        if (! $this->dateProgrammation) {
            $this->messageErreur = 'Veuillez choisir une date de programmation.';

            return;
        }

        $dateHeure = Carbon::parse($this->dateProgrammation.' '.($this->heureProgrammation ?: '08:00'));
        if ($dateHeure->isPast()) {
            $this->messageErreur = 'La date de programmation doit être située dans le futur.';

            return;
        }

        $donnees = [
            'titre' => $this->titre,
            'sujet' => $this->sujet,
            'type_ciblage' => $this->typeCiblage,
            'modele_id' => $this->modeleId,
            'contenu_texte' => $this->contenuTexte,
            'contenu_html' => $this->contenuHtml,
            'filtres' => $this->recueillirDonneesCiblage()['filtres'] ?? null,
            'groupe_enregistre_id' => $this->groupeEnregistreId,
        ];

        $campagne = $this->campagneId ? CampagneCourriel::find($this->campagneId) : null;
        $campagne = $service->enregistrerBrouillon($donnees, $campagne, auth()->id());
        $service->figerDestinataires($campagne, $this->recueillirDonneesCiblage());
        $service->planifier($campagne, $dateHeure);

        $this->redirectRoute('admin.campagnes-courriel.details', $campagne);
    }

    public function envoyerImmediatement(CampagneCourrielService $service): void
    {
        $this->authorize('campagnes.manage');
        $this->authorize('mail.send');
        $this->validerEtapeCourante();

        if ($this->messageErreur) {
            return;
        }

        $donnees = [
            'titre' => $this->titre,
            'sujet' => $this->sujet,
            'type_ciblage' => $this->typeCiblage,
            'modele_id' => $this->modeleId,
            'contenu_texte' => $this->contenuTexte,
            'contenu_html' => $this->contenuHtml,
            'filtres' => $this->recueillirDonneesCiblage()['filtres'] ?? null,
            'groupe_enregistre_id' => $this->groupeEnregistreId,
        ];

        $campagne = $this->campagneId ? CampagneCourriel::find($this->campagneId) : null;
        $campagne = $service->enregistrerBrouillon($donnees, $campagne, auth()->id());
        $service->figerDestinataires($campagne, $this->recueillirDonneesCiblage());
        $service->envoyer($campagne);

        $this->redirectRoute('admin.campagnes-courriel.details', $campagne);
    }

    public function render(): View
    {
        $resolution = app(ResolutionDestinatairesService::class);

        $apercuEtudiants = null;
        if ($this->typeCiblage === CampagneCourriel::CIBLAGE_LISTE_FIXE || $this->typeCiblage === CampagneCourriel::CIBLAGE_DYNAMIQUE_ETUDIANTS) {
            $reqEtudiants = $resolution->requeteEtudiants([
                'faculte_id' => $this->filtreFaculteId,
                'programme_id' => $this->filtreProgrammeId,
                'groupe_id' => $this->filtreGroupeId,
                'niveau' => $this->filtreNiveau,
                'annee_academique' => $this->filtreAnneeAcademique,
                'statut' => $this->filtreStatutEtudiant,
                'recherche' => $this->rechercheEtudiants,
            ]);
            $apercuEtudiants = $reqEtudiants->paginate(10, ['*'], 'etudiantsPage');
        }

        $apercuEnseignants = null;
        if ($this->typeCiblage === CampagneCourriel::CIBLAGE_LISTE_FIXE || $this->typeCiblage === CampagneCourriel::CIBLAGE_DYNAMIQUE_ENSEIGNANTS) {
            $reqEnseignants = $resolution->requeteEnseignants([
                'departement_id' => $this->filtreDepartementId,
                'grade_id' => $this->filtreGradeId,
                'specialite_id' => $this->filtreSpecialiteId,
                'statut' => $this->filtreStatutEnseignant,
                'actif' => $this->filtreActifEnseignant,
                'recherche' => $this->rechercheEnseignants,
            ]);
            $apercuEnseignants = $reqEnseignants->paginate(10, ['*'], 'enseignantsPage');
        }

        return view('livewire.admin.campagnes.assistant-composition', [
            'facultes' => Faculte::orderBy('nom')->get(),
            'programmes' => Programme::orderBy('nom')->get(),
            'groupes' => Group::orderBy('name')->get(),
            'departements' => Departement::orderBy('nom')->get(),
            'grades' => GradeEnseignant::orderBy('nom')->get(),
            'specialites' => Specialite::orderBy('nom')->get(),
            'groupesEnregistres' => GroupeDestinataires::where('est_archive', false)->orderBy('nom')->get(),
            'modelesCourriel' => ModeleCourriel::where('statut', ModeleCourriel::STATUT_PUBLIE)->orderBy('nom')->get(),
            'apercuEtudiants' => $apercuEtudiants,
            'apercuEnseignants' => $apercuEnseignants,
        ]);
    }
}

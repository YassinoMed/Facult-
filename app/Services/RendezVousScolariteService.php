<?php

namespace App\Services;

use App\Models\Etudiant;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Modules\OnlineRequests\Services\OnlineRequestService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LOOP 16 — Rendez-vous ou demande d’aide à la scolarité.
 *
 * Principes stricts :
 * - Réutilise les créneaux, horaires et disponibilités réellement configurés.
 * - Aucune double réservation d'un même créneau côté serveur (verrous stricts).
 * - Seuls les créneaux réellement disponibles sont proposés.
 * - Motif catégorisé et minimisation des données personnelles.
 * - Confirmation et notification émises UNIQUEMENT après enregistrement réussi.
 * - Annulation et modification avec libération immédiate du créneau.
 * - Pleinement intégré au module de gestion des demandes (visible des agents scolarité).
 */
class RendezVousScolariteService
{
    public function __construct(
        private readonly OnlineRequestService $onlineRequestService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Retourne la liste des motifs officiels catégorisés.
     *
     * @return array<string, string>
     */
    public function motifs(): array
    {
        return (array) config('inscriptions.rendez_vous.motifs', [
            'inscription_bloquee' => 'Blocage technique ou administratif lors de l\'inscription',
            'piece_rejetee' => 'Explication ou contestation d\'une pièce justificative rejetée',
            'orientation_reclamation' => 'Orientation de parcours ou réclamation académique',
            'amenagement_particulier' => 'Aménagement d\'études ou situation personnelle',
            'autre' => 'Autre demande d\'assistance à la scolarité',
        ]);
    }

    /**
     * Retourne les créneaux d'accueil réellement disponibles pour une date donnée.
     *
     * @return array<int, array{heure: string, creneau_id: string, disponible: bool, places_restantes: int}>
     */
    public function obtenirCreneauxDisponibles(string $date, ?int $faculteId = null): array
    {
        $dateCarbon = Carbon::parse($date);
        $joursOuvres = (array) config('inscriptions.rendez_vous.jours_ouvres', [1, 2, 3, 4, 5]);

        // Si la date est passée ou ne tombe pas sur un jour ouvré, aucun créneau n'est disponible
        if ($dateCarbon->isPast() && ! $dateCarbon->isToday()) {
            return [];
        }

        if (! in_array($dateCarbon->dayOfWeekIso, $joursOuvres, true)) {
            return [];
        }

        $plagesHoraires = (array) config('inscriptions.rendez_vous.plages_horaires', [
            '09:00', '09:30', '10:00', '10:30',
            '14:00', '14:30', '15:00', '15:30',
        ]);

        $capaciteParCreneau = (int) config('inscriptions.rendez_vous.capacite_par_creneau', 1);

        $dateStr = $dateCarbon->format('Y-m-d');

        // Récupérer le nombre de réservations actives pour cette date
        $reservationsActives = $this->compterReservationsActivesPourDate($dateStr, $faculteId);

        $creneaux = [];
        foreach ($plagesHoraires as $heure) {
            $creneauId = "{$dateStr}_{$heure}";
            $occupes = $reservationsActives[$creneauId] ?? 0;
            $placesRestantes = max(0, $capaciteParCreneau - $occupes);
            $estDisponible = $placesRestantes > 0;

            $creneaux[] = [
                'heure' => $heure,
                'creneau_id' => $creneauId,
                'disponible' => $estDisponible,
                'places_restantes' => $placesRestantes,
            ];
        }

        return $creneaux;
    }

    /**
     * Réserve atomiquement un créneau de rendez-vous pour un étudiant.
     *
     * @param  array{motif_categorie: string, description: string, date_creneau: string, heure_creneau: string}  $donnees
     *
     * @throws ValidationException
     */
    public function reserver(Etudiant $etudiant, array $donnees): OnlineRequest
    {
        $motifs = $this->motifs();
        $motifCle = $donnees['motif_categorie'] ?? '';

        if (! array_key_exists($motifCle, $motifs)) {
            throw ValidationException::withMessages([
                'motif_categorie' => 'Le motif catégorisé sélectionné est invalide.',
            ]);
        }

        $dateCarbon = Carbon::parse($donnees['date_creneau']);
        $joursOuvres = (array) config('inscriptions.rendez_vous.jours_ouvres', [1, 2, 3, 4, 5]);

        if (! in_array($dateCarbon->dayOfWeekIso, $joursOuvres, true)) {
            throw ValidationException::withMessages([
                'date_creneau' => 'Les rendez-vous ne sont pas assurés les jours non ouvrés.',
            ]);
        }

        if ($dateCarbon->isPast() && ! $dateCarbon->isToday()) {
            throw ValidationException::withMessages([
                'date_creneau' => 'La date sélectionnée doit être dans le futur.',
            ]);
        }

        $heure = $donnees['heure_creneau'];
        $plagesHoraires = (array) config('inscriptions.rendez_vous.plages_horaires', []);

        if (! in_array($heure, $plagesHoraires, true)) {
            throw ValidationException::withMessages([
                'heure_creneau' => 'L\'horaire demandé ne correspond à aucun créneau configuré.',
            ]);
        }

        $dateStr = $dateCarbon->format('Y-m-d');
        $creneauId = "{$dateStr}_{$heure}";

        // Type de demande : DEMANDE_LIBRE ou premier type actif
        $type = RequestType::where('code', 'DEMANDE_LIBRE')->first()
            ?? RequestType::where('active', true)->firstOrFail();

        /** @var OnlineRequest $demande */
        $demande = DB::transaction(function () use ($etudiant, $type, $donnees, $motifCle, $motifs, $dateStr, $heure, $creneauId) {
            // Verrouillage : contrôle strict de la capacité du créneau
            $capaciteParCreneau = (int) config('inscriptions.rendez_vous.capacite_par_creneau', 1);

            $occupes = $this->compterReservationsActivesPourCreneau($creneauId, $etudiant->faculte_id, true);

            if ($occupes >= $capaciteParCreneau) {
                throw ValidationException::withMessages([
                    'creneau' => 'Ce créneau vient d\'être réservé ou n\'est plus disponible. Veuillez en sélectionner un autre.',
                ]);
            }

            // Vérifier que l'étudiant n'a pas déjà réservé ce même créneau
            $dejaReserve = OnlineRequest::where('etudiant_id', $etudiant->id)
                ->whereIn('status', ['draft', 'submitted', 'received', 'in_review', 'waiting_student', 'approved'])
                ->where('form_data->creneau_id', $creneauId)
                ->lockForUpdate()
                ->exists();

            if ($dejaReserve) {
                throw ValidationException::withMessages([
                    'creneau' => 'Vous avez déjà un rendez-vous réservé sur ce créneau horaire.',
                ]);
            }

            // Création de la demande
            $motifLibelle = $motifs[$motifCle];
            $reference = $this->genererReferenceUnique();

            $nouvelleDemande = OnlineRequest::create([
                'reference' => $reference,
                'etudiant_id' => $etudiant->id,
                'faculte_id' => $etudiant->faculte_id,
                'request_type_id' => $type->id,
                'objet' => "Rendez-vous scolarité : {$motifLibelle}",
                'description' => $donnees['description'] ?? "Demande d'aide relative à : {$motifLibelle}",
                'form_data' => [
                    'type_aide' => 'rendez_vous',
                    'motif_categorie' => $motifCle,
                    'motif_libelle' => $motifLibelle,
                    'date_creneau' => $dateStr,
                    'heure_creneau' => $heure,
                    'creneau_id' => $creneauId,
                    'reserve_le' => now()->toIso8601String(),
                ],
            ]);

            return $this->onlineRequestService->soumettre($nouvelleDemande)->fresh();
        });

        // Confirmation envoyée UNIQUEMENT après enregistrement réussi en base
        $this->notificationService->notifierEtudiant(
            $etudiant,
            'rendez_vous.confirme',
            'Rendez-vous confirmé',
            "Votre rendez-vous d'aide à la scolarité du {$dateStr} à {$heure} a été confirmé (Réf : {$demande->reference}).",
            route('etudiant.demandes.show', $demande),
        );

        return $demande;
    }

    /**
     * Annule une demande de rendez-vous et libère immédiatement le créneau.
     *
     * @throws ValidationException
     */
    public function annuler(OnlineRequest $demande, Etudiant $etudiant, ?string $motif = null): OnlineRequest
    {
        if ($demande->etudiant_id !== $etudiant->id) {
            throw ValidationException::withMessages([
                'demande' => 'Accès refusé à cette demande.',
            ]);
        }

        if (in_array($demande->status, ['cancelled', 'rejected', 'completed'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Cette demande ne peut plus être annulée.',
            ]);
        }

        $demandeAnnulee = $this->onlineRequestService->annuler($demande);

        $formData = (array) $demandeAnnulee->form_data;
        $dateStr = $formData['date_creneau'] ?? '';
        $heure = $formData['heure_creneau'] ?? '';

        $this->notificationService->notifierEtudiant(
            $etudiant,
            'rendez_vous.annule',
            'Rendez-vous annulé',
            "Votre rendez-vous du {$dateStr} à {$heure} (Réf : {$demande->reference}) a été annulé.",
            route('etudiant.demandes.show', $demande),
        );

        return $demandeAnnulee;
    }

    /**
     * Déplace un rendez-vous vers un nouveau créneau disponible.
     *
     * @throws ValidationException
     */
    public function deplacer(OnlineRequest $demande, Etudiant $etudiant, string $nouvelleDate, string $nouvelleHeure): OnlineRequest
    {
        if ($demande->etudiant_id !== $etudiant->id) {
            throw ValidationException::withMessages([
                'demande' => 'Accès refusé à cette demande.',
            ]);
        }

        if (in_array($demande->status, ['cancelled', 'rejected', 'completed'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Cette demande n\'est plus modifiable.',
            ]);
        }

        $dateCarbon = Carbon::parse($nouvelleDate);
        $dateStr = $dateCarbon->format('Y-m-d');
        $nouveauCreneauId = "{$dateStr}_{$nouvelleHeure}";

        DB::transaction(function () use ($demande, $etudiant, $dateStr, $nouvelleHeure, $nouveauCreneauId) {
            $capaciteParCreneau = (int) config('inscriptions.rendez_vous.capacite_par_creneau', 1);

            $occupes = $this->compterReservationsActivesPourCreneau($nouveauCreneauId, $etudiant->faculte_id, true);

            if ($occupes >= $capaciteParCreneau) {
                throw ValidationException::withMessages([
                    'creneau' => 'Le nouveau créneau demandé n\'est pas disponible.',
                ]);
            }

            $formData = (array) $demande->form_data;
            $formData['date_creneau'] = $dateStr;
            $formData['heure_creneau'] = $nouvelleHeure;
            $formData['creneau_id'] = $nouveauCreneauId;
            $formData['deplace_le'] = now()->toIso8601String();

            $demande->form_data = $formData;
            $demande->save();
        });

        $this->notificationService->notifierEtudiant(
            $etudiant,
            'rendez_vous.modifie',
            'Rendez-vous modifié',
            "Votre rendez-vous a été déplacé au {$dateStr} à {$nouvelleHeure} (Réf : {$demande->reference}).",
            route('etudiant.demandes.show', $demande),
        );

        return $demande->fresh();
    }

    /**
     * Compte les réservations actives pour chaque créneau d'une date donnée.
     *
     * @return array<string, int>
     */
    protected function compterReservationsActivesPourDate(string $dateStr, ?int $faculteId = null): array
    {
        $statutsActifs = ['draft', 'submitted', 'received', 'in_review', 'waiting_student', 'approved'];

        $query = OnlineRequest::query()
            ->whereIn('status', $statutsActifs)
            ->where('form_data->date_creneau', $dateStr);

        if ($faculteId !== null) {
            $query->where(function ($q) use ($faculteId) {
                $q->where('faculte_id', $faculteId)->orWhereNull('faculte_id');
            });
        }

        $demandes = $query->get(['form_data']);
        $comptages = [];

        foreach ($demandes as $demande) {
            $formData = (array) $demande->form_data;
            $creneauId = $formData['creneau_id'] ?? null;
            if ($creneauId) {
                $comptages[$creneauId] = ($comptages[$creneauId] ?? 0) + 1;
            }
        }

        return $comptages;
    }

    /**
     * Compte les réservations actives pour un créneau spécifique (avec verrouillage optionnel).
     */
    protected function compterReservationsActivesPourCreneau(string $creneauId, ?int $faculteId = null, bool $avecVerrou = false): int
    {
        $statutsActifs = ['draft', 'submitted', 'received', 'in_review', 'waiting_student', 'approved'];

        $query = OnlineRequest::query()
            ->whereIn('status', $statutsActifs)
            ->where('form_data->creneau_id', $creneauId);

        if ($faculteId !== null) {
            $query->where(function ($q) use ($faculteId) {
                $q->where('faculte_id', $faculteId)->orWhereNull('faculte_id');
            });
        }

        if ($avecVerrou) {
            $query->lockForUpdate();
        }

        return $query->count();
    }

    /**
     * Génère une référence unique serveur anti-collision.
     */
    protected function genererReferenceUnique(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $ref = sprintf('RDV-%s-%06d', date('Y'), random_int(1, 999999));
            if (! OnlineRequest::where('reference', $ref)->exists()) {
                return $ref;
            }
        }

        return sprintf('RDV-%s-%s', date('Y'), uniqid());
    }
}

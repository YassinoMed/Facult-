<?php

namespace App\Jobs;

use App\Mail\CampagneCourrielMailable;
use App\Models\CampagneCourriel;
use App\Models\CampagneCourrielDestinataire;
use App\Models\Courriel;
use App\Services\CampagneCourrielService;
use App\Services\CourrielService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Traitement asynchrone d'un lot de destinataires pour une campagne de courriels (LOOP-MAIL-CAMPAIGNS-01).
 * Idempotence stricte, arrêt immédiat si campagne annulée, journalisation sans PII
 * et transition d'état finale.
 */
class TraiterLotCampagneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Nombre maximal de tentatives du job complet. */
    public int $tries = 3;

    /** Timeout en secondes pour le lot. */
    public int $timeout = 300;

    /**
     * @param  list<int>  $destinataireIds
     */
    public function __construct(
        public readonly int $campagneId,
        public readonly array $destinataireIds,
        public readonly int $numeroLot = 1,
    ) {}

    public function handle(
        CampagneCourrielService $campagneService,
        CourrielService $courrielService,
    ): void {
        $campagne = CampagneCourriel::find($this->campagneId);

        if ($campagne === null) {
            return;
        }

        // Si la campagne a été annulée entre-temps, on n'exécute pas ce lot
        if ($campagne->statut === CampagneCourriel::STATUT_ANNULEE) {
            CampagneCourrielDestinataire::whereIn('id', $this->destinataireIds)
                ->where('statut', CampagneCourrielDestinataire::STATUT_EN_ATTENTE)
                ->update(['statut' => CampagneCourrielDestinataire::STATUT_ANNULE]);

            return;
        }

        if ($campagne->statut === CampagneCourriel::STATUT_EN_ATTENTE) {
            $campagne->transitionnerVers(CampagneCourriel::STATUT_EN_COURS);
        }

        $destinataires = CampagneCourrielDestinataire::whereIn('id', $this->destinataireIds)
            ->whereIn('statut', [CampagneCourrielDestinataire::STATUT_EN_ATTENTE, CampagneCourrielDestinataire::STATUT_ECHEC])
            ->get();

        foreach ($destinataires as $dest) {
            // Re-vérifier l'état de la campagne avant chaque envoi individuel
            $campagne->refresh();
            if ($campagne->statut === CampagneCourriel::STATUT_ANNULEE) {
                $dest->update(['statut' => CampagneCourrielDestinataire::STATUT_ANNULE]);

                continue;
            }

            // Protection anti-double envoi
            if ($dest->statut === CampagneCourrielDestinataire::STATUT_ENVOYE) {
                continue;
            }

            $dest->update([
                'statut' => CampagneCourrielDestinataire::STATUT_EN_COURS,
                'tentatives' => $dest->tentatives + 1,
            ]);

            $rendu = $campagneService->rendrePourDestinataire($campagne, $dest);
            $cleIdempotence = "camp-{$campagne->id}-dest-{$dest->id}-t{$dest->tentatives}";
            $correlationId = "campagne-{$campagne->id}-lot-{$this->numeroLot}";

            // Journalisation technique de l'intention dans la table courriels
            $courrielJournal = null;
            try {
                $courrielJournal = Courriel::create([
                    'cle_idempotence' => $cleIdempotence,
                    'categorie' => 'campagne',
                    'modele' => $campagne->modele_id ? 'studio:'.$campagne->modele_id : 'campagne_directe',
                    'version_modele' => (string) ($campagne->modele_version ?? 1),
                    'statut' => Courriel::STATUT_EN_COURS,
                    'tentatives' => $dest->tentatives,
                    'destinataire_masque' => $dest->emailMasque(),
                    'destinataire_hash' => $dest->email_hash,
                    'sujet' => mb_substr($rendu['sujet'], 0, 255),
                    'correlation_id' => $correlationId,
                    'reference_metier' => 'campagne:'.$campagne->id,
                    'acteur_admin_id' => $campagne->auteur_id,
                    'donnees' => ['reemissible' => false],
                ]);
            } catch (Throwable) {
                $courrielJournal = Courriel::where('cle_idempotence', $cleIdempotence)->first();
            }

            // Tentative d'envoi effectif via le transport configuré
            try {
                Mail::to($dest->email)->send(new CampagneCourrielMailable(
                    $campagne->id,
                    $dest->id,
                    $rendu['sujet'],
                    $rendu['html'],
                    $rendu['texte'],
                ));

                $dest->update([
                    'statut' => CampagneCourrielDestinataire::STATUT_ENVOYE,
                    'envoye_a' => now(),
                    'courriel_id' => $courrielJournal?->id,
                    'correlation_id' => $correlationId,
                    'derniere_erreur' => null,
                ]);

                $courrielJournal?->update([
                    'statut' => Courriel::STATUT_ACCEPTE_SMTP,
                    'envoye_le' => now(),
                ]);
            } catch (Throwable $e) {
                $categorieErreur = $courrielService->classifierErreur($e);
                $diagnostic = CourrielService::expurgerMessage($e);

                $dest->update([
                    'statut' => CampagneCourrielDestinataire::STATUT_ECHEC,
                    'derniere_erreur' => mb_substr($diagnostic, 0, 255),
                    'courriel_id' => $courrielJournal?->id,
                    'correlation_id' => $correlationId,
                ]);

                $courrielJournal?->update([
                    'statut' => Courriel::STATUT_ECHEC,
                    'categorie_erreur' => $categorieErreur,
                    'donnees' => ['diagnostic' => $diagnostic, 'reemissible' => false],
                ]);
            }
        }

        // Fin de lot : mise à jour des statistiques de la campagne
        $campagne->recalculerCompteurs();

        // Si plus aucun destinataire n'est en attente ou en cours pour la campagne
        $restants = $campagne->destinataires()
            ->whereIn('statut', [CampagneCourrielDestinataire::STATUT_EN_ATTENTE, CampagneCourrielDestinataire::STATUT_EN_COURS])
            ->count();

        if ($restants === 0 && in_array($campagne->statut, [CampagneCourriel::STATUT_EN_COURS, CampagneCourriel::STATUT_EN_ATTENTE], true)) {
            if ($campagne->total_echecs > 0) {
                $campagne->transitionnerVers(CampagneCourriel::STATUT_TERMINEE_ERREURS);
            } else {
                $campagne->transitionnerVers(CampagneCourriel::STATUT_TERMINEE);
            }
        }
    }
}

<?php

namespace App\Services;

use App\Jobs\TraiterLotCampagneJob;
use App\Models\CampagneCourriel;
use App\Models\CampagneCourrielDestinataire;
use App\Models\ModeleCourriel;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Service central d'orchestration des campagnes de courriels (LOOP-MAIL-CAMPAIGNS-01).
 * Gestion transactionnelle, préparation des destinataires, découpage en lots,
 * planification, annulation et relance sélective.
 */
class CampagneCourrielService
{
    public function __construct(
        private readonly ResolutionDestinatairesService $resolution,
        private readonly MoteurRenduCourriel $moteur,
        private readonly ValidateurSchemaCourriel $validateur,
    ) {}

    /**
     * Crée ou met à jour un brouillon de campagne.
     *
     * @param  array<string, mixed>  $donnees
     */
    public function enregistrerBrouillon(array $donnees, ?CampagneCourriel $campagne = null, ?int $auteurId = null): CampagneCourriel
    {
        $titre = trim((string) ($donnees['titre'] ?? ''));
        if ($titre === '' || mb_strlen($titre) > 150) {
            throw new InvalidArgumentException('Le titre de la campagne est obligatoire (max 150 caractères).');
        }

        $sujet = trim((string) ($donnees['sujet'] ?? ''));
        if ($sujet === '' || mb_strlen($sujet) > 255) {
            throw new InvalidArgumentException('L’objet du courriel est obligatoire (max 255 caractères).');
        }

        $typeCiblage = (string) ($donnees['type_ciblage'] ?? CampagneCourriel::CIBLAGE_INDIVIDUEL);
        if (! in_array($typeCiblage, CampagneCourriel::CIBLAGES, true)) {
            throw new InvalidArgumentException("Type de ciblage « {$typeCiblage} » invalide.");
        }

        $modeleId = isset($donnees['modele_id']) && $donnees['modele_id'] !== '' ? (int) $donnees['modele_id'] : null;
        $modeleVersion = null;
        $contenuHtml = null;
        $contenuTexte = null;

        if ($modeleId !== null) {
            $modele = ModeleCourriel::with('versions')->find($modeleId);
            if ($modele === null) {
                throw new InvalidArgumentException('Modèle de courriel introuvable.');
            }
            $derniereVersion = $modele->derniereVersion();
            if ($derniereVersion !== null) {
                $modeleVersion = $derniereVersion->numero;
                $rendu = $this->moteur->rendre(
                    $this->validateur->valider((array) $derniereVersion->schema),
                    ['etablissement_nom' => config('app.name', 'Université')]
                );
                $contenuHtml = $rendu['html'];
                $contenuTexte = $rendu['texte'];
            }
        } else {
            $contenuTexte = (string) ($donnees['contenu_texte'] ?? '');
            $contenuHtml = (string) ($donnees['contenu_html'] ?? nl2br(e($contenuTexte)));
        }

        $attributs = [
            'titre' => $titre,
            'sujet' => $sujet,
            'type_ciblage' => $typeCiblage,
            'modele_id' => $modeleId,
            'modele_version' => $modeleVersion,
            'contenu_texte' => $contenuTexte,
            'contenu_html' => $contenuHtml,
            'filtres' => $donnees['filtres'] ?? null,
            'groupe_enregistre_id' => $donnees['groupe_enregistre_id'] ?? null,
            'taille_lot' => (int) ($donnees['taille_lot'] ?? 50),
            'meta' => $donnees['meta'] ?? null,
        ];

        if ($campagne === null) {
            $attributs['cle_idempotence'] = CampagneCourriel::genererCleIdempotence();
            $attributs['statut'] = CampagneCourriel::STATUT_BROUILLON;
            $attributs['auteur_id'] = $auteurId;

            return CampagneCourriel::create($attributs);
        }

        if (! $campagne->peutEtreModifiee()) {
            throw new DomainException("Cette campagne ne peut plus être modifiée (statut : {$campagne->statut}).");
        }

        $campagne->update($attributs);

        return $campagne->fresh();
    }

    /**
     * Résout et enregistre l'instantané des destinataires pour la campagne
     * dans une transaction atomique.
     *
     * @param  array<string, mixed>  $donneesCiblage
     */
    public function figerDestinataires(CampagneCourriel $campagne, array $donneesCiblage = []): CampagneCourriel
    {
        if (! $campagne->peutEtreModifiee()) {
            throw new DomainException('Les destinataires ne peuvent pas être modifiés pour cette campagne.');
        }

        // Fusion des filtres de la campagne s'ils ne sont pas passés explicitement
        if (! isset($donneesCiblage['filtres']) && $campagne->filtres !== null) {
            $donneesCiblage['filtres'] = $campagne->filtres;
        }
        if (! isset($donneesCiblage['groupe_enregistre_id']) && $campagne->groupe_enregistre_id !== null) {
            $donneesCiblage['groupe_enregistre_id'] = $campagne->groupe_enregistre_id;
        }

        $resolution = $this->resolution->resoudre($campagne->type_ciblage, $donneesCiblage);

        return DB::transaction(function () use ($campagne, $resolution) {
            // Suppression des anciens destinataires préparés en brouillon
            $campagne->destinataires()->delete();

            $aInserer = [];
            $now = now()->toDateTimeString();

            foreach ($resolution['destinataires'] as $dest) {
                $aInserer[] = [
                    'campagne_id' => $campagne->id,
                    'type_destinataire' => $dest['type_destinataire'],
                    'reference_id' => $dest['reference_id'],
                    'nom_complet' => $dest['nom_complet'],
                    'email' => $dest['email'],
                    'email_hash' => $dest['email_hash'],
                    'statut' => CampagneCourrielDestinataire::STATUT_EN_ATTENTE,
                    'tentatives' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                // Insertion par morceaux pour respecter les limites de requêtes SQL
                if (count($aInserer) >= 200) {
                    CampagneCourrielDestinataire::insert($aInserer);
                    $aInserer = [];
                }
            }

            if (! empty($aInserer)) {
                CampagneCourrielDestinataire::insert($aInserer);
            }

            $campagne->update([
                'total_destinataires' => $resolution['total_valides'],
                'total_ignores' => $resolution['total_invalides'],
                'total_envoyes' => 0,
                'total_echecs' => 0,
                'meta' => array_merge($campagne->meta ?? [], [
                    'resolution' => [
                        'total_brut' => $resolution['total_brut'],
                        'total_valides' => $resolution['total_valides'],
                        'total_doublons' => $resolution['total_doublons'],
                        'total_invalides' => $resolution['total_invalides'],
                        'date' => $now,
                    ],
                ]),
            ]);

            return $campagne->fresh();
        });
    }

    /**
     * Planifie la campagne pour une exécution ultérieure.
     */
    public function planifier(CampagneCourriel $campagne, DateTimeInterface $date): CampagneCourriel
    {
        if (! $campagne->peutEtreEnvoyee()) {
            throw new DomainException("La campagne ne peut pas être planifiée depuis « {$campagne->statut} ».");
        }

        if ($campagne->total_destinataires === 0) {
            throw new DomainException('La campagne ne contient aucun destinataire valide.');
        }

        $campagne->update(['planifiee_a' => $date]);
        $campagne->transitionnerVers(CampagneCourriel::STATUT_PLANIFIEE);

        return $campagne->fresh();
    }

    /**
     * Déclenche l'envoi immédiat de la campagne par lots asynchrones.
     */
    public function envoyer(CampagneCourriel $campagne): CampagneCourriel
    {
        if (! $campagne->peutEtreEnvoyee()) {
            throw new DomainException("La campagne ne peut pas être envoyée depuis « {$campagne->statut} ».");
        }

        if ($campagne->total_destinataires === 0) {
            throw new DomainException('Impossible d’envoyer une campagne sans destinataires.');
        }

        $campagne->transitionnerVers(CampagneCourriel::STATUT_EN_ATTENTE);
        $this->dispatcherLots($campagne);

        return $campagne->fresh();
    }

    /**
     * Annule une campagne planifiée ou en attente/en cours.
     */
    public function annuler(CampagneCourriel $campagne): CampagneCourriel
    {
        if (! $campagne->peutEtreAnnulee()) {
            throw new DomainException("Cette campagne ne peut pas être annulée (statut : {$campagne->statut}).");
        }

        return DB::transaction(function () use ($campagne) {
            $campagne->transitionnerVers(CampagneCourriel::STATUT_ANNULEE);

            // Annule tous les destinataires encore en attente
            $campagne->destinataires()
                ->where('statut', CampagneCourrielDestinataire::STATUT_EN_ATTENTE)
                ->update(['statut' => CampagneCourrielDestinataire::STATUT_ANNULE]);

            $campagne->recalculerCompteurs();

            return $campagne->fresh();
        });
    }

    /**
     * Relance sélectivement les destinataires en échec uniquement.
     */
    public function relancerEchecs(CampagneCourriel $campagne): CampagneCourriel
    {
        if (! $campagne->peutEtreRelancee()) {
            throw new DomainException('Aucun destinataire en échec à relancer pour cette campagne.');
        }

        return DB::transaction(function () use ($campagne) {
            // Remet en attente uniquement les destinataires ayant échoué
            $campagne->destinataires()
                ->where('statut', CampagneCourrielDestinataire::STATUT_ECHEC)
                ->update([
                    'statut' => CampagneCourrielDestinataire::STATUT_EN_ATTENTE,
                    'derniere_erreur' => null,
                ]);

            $campagne->transitionnerVers(CampagneCourriel::STATUT_EN_ATTENTE);
            $campagne->recalculerCompteurs();

            $this->dispatcherLots($campagne);

            return $campagne->fresh();
        });
    }

    /**
     * Découpe les destinataires en attente en lots et dispatche les jobs.
     */
    public function dispatcherLots(CampagneCourriel $campagne): void
    {
        $tailleLot = max(1, min(200, $campagne->taille_lot ?: 50));

        $idsEnAttente = $campagne->destinataires()
            ->where('statut', CampagneCourrielDestinataire::STATUT_EN_ATTENTE)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if (empty($idsEnAttente)) {
            $campagne->recalculerCompteurs();
            $campagne->transitionnerVers(CampagneCourriel::STATUT_TERMINEE);

            return;
        }

        $chunks = array_chunk($idsEnAttente, $tailleLot);

        foreach ($chunks as $numeroLot => $lotIds) {
            TraiterLotCampagneJob::dispatch($campagne->id, $lotIds, $numeroLot + 1);
        }
    }

    /**
     * Rendu personnalisé pour un destinataire donné.
     *
     * @return array{sujet: string, html: string, texte: string}
     */
    public function rendrePourDestinataire(CampagneCourriel $campagne, CampagneCourrielDestinataire $destinataire): array
    {
        $contexte = [
            'etablissement_nom' => config('app.name', 'Université'),
            'destinataire_nom' => $destinataire->nom_complet ?: 'Madame, Monsieur',
            'contact_email' => config('inscriptions.contact_scolarite.email', 'scolarite@univ.test'),
        ];

        if ($campagne->modele_id !== null && $campagne->modele !== null) {
            $version = $campagne->modele->versions()->where('numero', $campagne->modele_version)->first()
                ?? $campagne->modele->derniereVersion();

            if ($version !== null) {
                return $this->moteur->rendre((array) $version->schema, $contexte);
            }
        }

        $sujet = preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => $contexte[$m[1]] ?? '', $campagne->sujet);
        $texte = preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => $contexte[$m[1]] ?? '', (string) $campagne->contenu_texte);
        $html = $campagne->contenu_html ?: nl2br(e($texte));

        return [
            'sujet' => $sujet,
            'html' => $html,
            'texte' => $texte,
        ];
    }
}

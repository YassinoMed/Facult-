<?php

namespace App\Modules\Finances\Services;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Finances\Models\DossierPaiement;
use App\Modules\Finances\Models\Facture;
use App\Modules\Finances\Models\RecuPaiement;
use App\Modules\Finances\Models\Tarif;
use App\Modules\StudentDossiers\Services\DocumentStorageService;
use App\Services\AuditService;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * LOOP-UNIREGISTRE-INSCRIPTIONS-PAIEMENTS-01 : cycle de vie du dossier
 * de paiement annuel.
 *
 * Règles absolues :
 *  - un seul dossier par inscription (contrainte UNIQUE en base) ;
 *  - un dépôt n'est JAMAIS un paiement confirmé ;
 *  - la validation exige une vérification préalable et ne peut se
 *    produire qu'une fois (contrainte UNIQUE + verrou de ligne) ;
 *  - un refus conserve le reçu et son motif ; un nouveau reçu crée une
 *    version supplémentaire, sans écraser l'historique ;
 *  - les montants attendus viennent des factures/tarifs existants ;
 *    aucun montant n'est inventé dans ce service.
 */
final class DossierPaiementService
{
    public function __construct(
        private readonly DocumentStorageService $stockage,
        private readonly AuditService $audit,
    ) {}

    /**
     * Crée le dossier manquant (idempotent, tolérant à la concurrence).
     * Appelé par l'observateur d'inscription et la commande de rattrapage.
     */
    public function creerPour(InscriptionAcademique $inscription): DossierPaiement
    {
        $existant = DossierPaiement::where(
            'inscription_academique_id',
            $inscription->id
        )->first();

        if ($existant !== null) {
            return $existant;
        }

        try {
            return DossierPaiement::create([
                'inscription_academique_id' => $inscription->id,
                'statut' => DossierPaiement::NON_PAYE,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Course entre deux créations : la contrainte UNIQUE arbitre.
            return DossierPaiement::where(
                'inscription_academique_id',
                $inscription->id
            )->firstOrFail();
        }
    }

    /**
     * Montant attendu, uniquement depuis les règles officielles :
     *  1. facture non annulée liée à l'inscription (source la plus précise) ;
     *  2. sinon, tarifs actifs de l'année, filtrés par programme/niveau.
     *
     * Aucun montant par défaut : si rien n'est configuré, `centimes` vaut
     * null et l'interface affiche explicitement « non configuré ».
     *
     * @return array{centimes: ?int, source: string, source_libelle: string, echeance: mixed, details: array<int, array<string, mixed>>}
     */
    public function montantAttendu(InscriptionAcademique $inscription): array
    {
        return $this->montantsAttendus(collect([$inscription]))[$inscription->id];
    }

    /**
     * Version par lot : évite deux requêtes par dossier dans la file
     * administrative (facture + tarifs). Le calcul reste identique à
     * `montantAttendu`, sans montant inventé.
     *
     * @param  Collection<int, InscriptionAcademique>  $inscriptions
     * @return array<int, array{centimes: ?int, source: string, source_libelle: string, echeance: mixed, details: array<int, array<string, mixed>>}>
     */
    public function montantsAttendus(Collection $inscriptions): array
    {
        $inscriptions = (new InscriptionAcademique)
            ->newCollection(
                $inscriptions
                    ->filter(fn ($inscription): bool => $inscription instanceof InscriptionAcademique)
                    ->all()
            )
            ->unique('id')
            ->values();

        if ($inscriptions->isEmpty()) {
            return [];
        }

        // Chargement par lot des relations utilisées par le calcul :
        // aucune requête supplémentaire par inscription.
        $inscriptions->loadMissing(['etudiant', 'anneeUniversitaire', 'programmeRef']);

        /** @var Collection<int, Facture> $factures */
        $factures = Facture::query()
            ->whereIn('inscription_academique_id', $inscriptions->pluck('id'))
            ->whereNotIn('statut', ['cancelled', 'draft', 'refunded'])
            ->orderBy('id')
            ->get()
            ->groupBy('inscription_academique_id')
            ->map(fn (Collection $groupe): Facture => $groupe->last());

        $types = (array) config('inscriptions-paiements.types_frais', []);

        /** @var Collection<int|string, Collection<int, Tarif>> $tarifsParAnnee */
        $tarifsParAnnee = Tarif::query()
            ->whereIn(
                'annee_universitaire_id',
                $inscriptions->pluck('annee_universitaire_id')->unique()->values()
            )
            ->where('actif', true)
            ->when($types !== [], fn ($q) => $q->whereIn('type_frais', $types))
            ->orderBy('code')
            ->get()
            ->groupBy('annee_universitaire_id');

        $resultat = [];

        foreach ($inscriptions as $inscription) {
            $facture = $factures->get($inscription->id);

            if ($facture !== null) {
                $resultat[$inscription->id] = [
                    'centimes' => $facture->netCentimes(),
                    'source' => 'facture',
                    'source_libelle' => 'Facture '.$facture->reference,
                    'echeance' => $facture->date_echeance,
                    'details' => [],
                ];

                continue;
            }

            $tarifs = $this->selectionnerTarifs(
                $tarifsParAnnee->get($inscription->annee_universitaire_id, collect()),
                $inscription->programme_id ?? $inscription->etudiant?->programme_id,
                $inscription->niveau,
            );

            if ($tarifs->isNotEmpty()) {
                $resultat[$inscription->id] = [
                    'centimes' => (int) $tarifs->sum('montant_centimes'),
                    'source' => 'tarif',
                    'source_libelle' => 'Tarifs officiels de l’année',
                    'echeance' => null,
                    'details' => $tarifs->map(fn (Tarif $tarif): array => [
                        'code' => $tarif->code,
                        'libelle' => $tarif->libelle,
                        'montant_centimes' => (int) $tarif->montant_centimes,
                    ])->values()->all(),
                ];

                continue;
            }

            $resultat[$inscription->id] = [
                'centimes' => null,
                'source' => 'non_configure',
                'source_libelle' => 'Aucun tarif officiel configuré pour cette inscription',
                'echeance' => null,
                'details' => [],
            ];
        }

        return $resultat;
    }

    /**
     * Tarifs réellement applicables : pour chaque type de frais, on
     * retient la règle la plus spécifique (programme + niveau), sinon
     * programme, sinon niveau, sinon règle globale. Évite de cumuler
     * une règle générique et sa déclinaison spécifique.
     *
     * @return Collection<int, Tarif>
     */
    public function tarifsApplicables(InscriptionAcademique $inscription): Collection
    {
        $inscription->loadMissing('etudiant');

        $programmeId = $inscription->programme_id ?? $inscription->etudiant?->programme_id;
        $niveau = $inscription->niveau;
        $types = (array) config('inscriptions-paiements.types_frais', []);

        $tarifs = Tarif::query()
            ->where('annee_universitaire_id', $inscription->annee_universitaire_id)
            ->where('actif', true)
            ->when($types !== [], fn ($q) => $q->whereIn('type_frais', $types))
            ->orderBy('code')
            ->get();

        return $this->selectionnerTarifs($tarifs, $programmeId, $niveau);
    }

    /**
     * @param  Collection<int, Tarif>  $tarifs
     * @return Collection<int, Tarif>
     */
    private function selectionnerTarifs(Collection $tarifs, ?int $programmeId, ?string $niveau): Collection
    {
        $selection = collect();

        foreach ($tarifs->groupBy(fn (Tarif $tarif): string => (string) ($tarif->type_frais ?: 'autre')) as $groupe) {
            $scores = $groupe->map(fn (Tarif $tarif): ?int => $this->scoreTarif($tarif, $programmeId, $niveau));

            $meilleur = $scores->filter(fn (?int $score): bool => $score !== null)->max();

            if ($meilleur === null) {
                continue;
            }

            foreach ($groupe as $index => $tarif) {
                if (($scores[$index] ?? null) === $meilleur) {
                    $selection->push($tarif);
                }
            }
        }

        return $selection->sortBy('code')->values();
    }

    /**
     * Score de spécificité ; null = règle inapplicable à ce parcours.
     */
    private function scoreTarif(Tarif $tarif, ?int $programmeId, ?string $niveau): ?int
    {
        $score = 0;

        if ($tarif->programme_id === null) {
            $score += 1;
        } elseif ($programmeId !== null && (int) $tarif->programme_id === (int) $programmeId) {
            $score += 2;
        } else {
            return null;
        }

        if ($tarif->niveau === null) {
            $score += 1;
        } elseif ($niveau !== null && (string) $tarif->niveau === (string) $niveau) {
            $score += 2;
        } else {
            return null;
        }

        return $score;
    }

    /**
     * Dépose un reçu : stockage privé + instantané des règles + statut
     * RECU_DEPOSE. Aucun champ `paye` n'est modifié ici.
     *
     * @param  array{montant: numeric-string|int|float, reference_paiement: string, date_paiement: string}  $donnees
     */
    public function deposerRecu(
        InscriptionAcademique $inscription,
        Etudiant $etudiant,
        UploadedFile $fichier,
        array $donnees,
    ): RecuPaiement {
        if ((int) $inscription->etudiant_id !== (int) $etudiant->id) {
            throw new DomainException('Cette inscription ne vous appartient pas.');
        }

        $inscription->loadMissing(['anneeUniversitaire', 'etudiant']);

        $montant = $this->montantAttendu($inscription);
        $reference = trim((string) ($donnees['reference_paiement'] ?? ''));

        if ($reference === '') {
            throw new DomainException('La référence du paiement est obligatoire.');
        }

        // Normalisation en date pure : le même jour doit produire la même
        // clé anti-doublon, quel que soit le format d'entrée.
        $datePaiement = Carbon::parse((string) ($donnees['date_paiement'] ?? ''))->toDateString();
        $montantDeclare = (int) round(((float) ($donnees['montant'] ?? 0)) * 100);

        if ($montantDeclare <= 0) {
            throw new DomainException('Le montant déclaré doit être strictement positif.');
        }

        // Empreinte calculée avant stockage : le même fichier ne peut pas
        // servir deux fois (autre inscription, autre année, ou doublon).
        $sha256 = hash('sha256', (string) $fichier->get());

        if (RecuPaiement::where('sha256', $sha256)->exists()) {
            throw new DomainException(
                'Ce fichier a déjà été déposé. Un reçu ne peut jamais être réutilisé pour une autre inscription ou une autre année.'
            );
        }

        // Un même paiement ne peut être déclaré que sur une seule
        // inscription. En revanche, dans la même inscription, un nouveau
        // dépôt après refus peut conserver la référence, la date et le
        // montant d'origine (document corrigé) : l'historique reste
        // versionné et seule la validation finale compte.
        if (RecuPaiement::query()
            ->where('reference_paiement', $reference)
            ->whereDate('date_paiement', $datePaiement)
            ->where('montant_declare_centimes', $montantDeclare)
            ->where('inscription_academique_id', '!=', $inscription->id)
            ->exists()
        ) {
            throw new DomainException(
                'Un reçu avec la même référence, la même date et le même montant existe déjà pour une autre inscription. Vérifiez qu’il ne s’agit pas d’un doublon.'
            );
        }

        $meta = null;

        try {
            return DB::transaction(function () use (
                $inscription,
                $etudiant,
                $fichier,
                $reference,
                $datePaiement,
                $montantDeclare,
                $montant,
                &$meta,
            ): RecuPaiement {
                $dossier = DossierPaiement::where(
                    'inscription_academique_id',
                    $inscription->id
                )->lockForUpdate()->first();

                if ($dossier === null) {
                    $dossier = $this->creerPour($inscription);
                    $dossier = DossierPaiement::whereKey($dossier->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                if ($dossier->statut === DossierPaiement::VALIDE) {
                    throw new DomainException(
                        'Le paiement de cette inscription est déjà validé : aucun nouveau reçu ne peut être déposé.'
                    );
                }

                if (! $dossier->peutDeposerRecu()) {
                    throw new DomainException(
                        'Un reçu est déjà en cours de vérification pour cette inscription.'
                    );
                }

                $version = ((int) RecuPaiement::where(
                    'dossier_paiement_id',
                    $dossier->id
                )->max('version')) + 1;

                $meta = $this->stockage->stocker(
                    $fichier,
                    (int) $inscription->etudiant_id,
                    (string) ($inscription->anneeUniversitaire?->libelle ?? 'sans-annee'),
                    'recus-paiement',
                    (string) config('inscriptions-paiements.racine_stockage', 'paiements-inscriptions'),
                );

                $recu = RecuPaiement::create([
                    'dossier_paiement_id' => $dossier->id,
                    'inscription_academique_id' => $inscription->id,
                    'annee_universitaire_id' => $inscription->annee_universitaire_id,
                    'version' => $version,
                    'storage_path' => $meta['storage_path'],
                    'original_filename' => $meta['original_filename'],
                    'mime_type' => $meta['mime_type'],
                    'extension' => $meta['extension'],
                    'size' => $meta['size'],
                    'sha256' => $meta['sha256'],
                    'montant_attendu_centimes' => $montant['centimes'],
                    'source_montant' => $montant['source'],
                    'echeance' => $montant['echeance']?->format('Y-m-d'),
                    'montant_declare_centimes' => $montantDeclare,
                    'reference_paiement' => $reference,
                    'date_paiement' => $datePaiement,
                    'statut' => RecuPaiement::RECU_DEPOSE,
                    'depose_le' => now(),
                ]);

                // Nouveau cycle : on efface uniquement les traces de
                // décision du dossier ; les reçus précédents restent
                // intacts (statut, motif, agent, date).
                $dossier->update([
                    'statut' => DossierPaiement::RECU_DEPOSE,
                    'pris_en_charge_par' => null,
                    'pris_en_charge_le' => null,
                    'decide_par' => null,
                    'decide_le' => null,
                ]);

                $this->audit->tracer(
                    $etudiant,
                    'paiement.recu_depose',
                    'recu_paiement',
                    $recu->id,
                    null,
                    [
                        'statut' => RecuPaiement::RECU_DEPOSE,
                        'version' => $version,
                        'montant_declare_centimes' => $montantDeclare,
                    ],
                    'web',
                );

                return $recu->fresh();
            });
        } catch (UniqueConstraintViolationException $e) {
            // Course entre deux dépôts : les index UNIQUE (fichier,
            // référence/date/montant, version) arbitrent.
            if ($meta !== null) {
                $this->stockage->supprimer($meta['storage_path']);
            }

            throw new DomainException(
                'Un reçu identique (même fichier ou même référence, date et montant) existe déjà. Vérifiez qu’il ne s’agit pas d’un doublon.',
                0,
                $e,
            );
        } catch (\Throwable $e) {
            // Le fichier ne doit jamais rester orphelin si la transaction
            // échoue après l'écriture sur le disque privé.
            if ($meta !== null) {
                $this->stockage->supprimer($meta['storage_path']);
            }

            throw $e;
        }
    }

    /**
     * RECU_DEPOSE → EN_VERIFICATION. Le verrou de ligne sérialise deux
     * agents qui cliqueraient simultanément.
     */
    public function prendreEnVerification(DossierPaiement $dossier, User $agent): RecuPaiement
    {
        return DB::transaction(function () use ($dossier, $agent): RecuPaiement {
            $dossier = DossierPaiement::whereKey($dossier->id)->lockForUpdate()->firstOrFail();

            if ($dossier->statut !== DossierPaiement::RECU_DEPOSE) {
                throw new DomainException('Aucun reçu déposé n’est disponible pour la vérification.');
            }

            $recu = $dossier->recus()
                ->where('statut', RecuPaiement::RECU_DEPOSE)
                ->latest('version')
                ->first();

            if ($recu === null) {
                throw new DomainException('Le reçu à vérifier est introuvable.');
            }

            $recu->update([
                'statut' => RecuPaiement::EN_VERIFICATION,
                'pris_en_charge_par' => $agent->id,
                'pris_en_charge_le' => now(),
            ]);

            $dossier->update([
                'statut' => DossierPaiement::EN_VERIFICATION,
                'pris_en_charge_par' => $agent->id,
                'pris_en_charge_le' => now(),
            ]);

            $this->audit->tracer(
                $this->etudiantDuDossier($dossier),
                'paiement.recu_pris_en_charge',
                'recu_paiement',
                $recu->id,
                ['statut' => RecuPaiement::RECU_DEPOSE],
                ['statut' => RecuPaiement::EN_VERIFICATION],
                'web',
            );

            return $recu->fresh();
        });
    }

    /**
     * EN_VERIFICATION → VALIDE. Une seule validation possible par
     * dossier ; un écart entre montant déclaré et montant attendu exige
     * un commentaire de justification (traçabilité de la décision).
     */
    public function valider(DossierPaiement $dossier, User $agent, ?string $commentaire = null): RecuPaiement
    {
        try {
            return DB::transaction(function () use ($dossier, $agent, $commentaire): RecuPaiement {
                $dossier = DossierPaiement::whereKey($dossier->id)->lockForUpdate()->firstOrFail();

                if ($dossier->statut !== DossierPaiement::EN_VERIFICATION) {
                    throw new DomainException('La validation exige que le reçu ait d’abord été pris en vérification.');
                }

                $recu = $dossier->recus()
                    ->where('statut', RecuPaiement::EN_VERIFICATION)
                    ->latest('version')
                    ->first();

                if ($recu === null) {
                    throw new DomainException('Le reçu en vérification est introuvable.');
                }

                if (RecuPaiement::where('dossier_paiement_id', $dossier->id)
                    ->where('est_valide', true)
                    ->exists()
                ) {
                    throw new DomainException('Un reçu est déjà validé pour cette inscription.');
                }

                $commentaire = trim((string) $commentaire);

                if ($recu->montant_attendu_centimes === null && $commentaire === '') {
                    throw new DomainException(
                        'Aucun montant attendu n’est configuré pour cette inscription : un commentaire de justification est obligatoire avant validation.'
                    );
                }

                if ($recu->montant_attendu_centimes !== null
                    && (int) $recu->montant_declare_centimes !== (int) $recu->montant_attendu_centimes
                    && $commentaire === ''
                ) {
                    throw new DomainException(
                        'Le montant déclaré diffère du montant attendu : un commentaire de justification est obligatoire.'
                    );
                }

                $recu->update([
                    'statut' => RecuPaiement::VALIDE,
                    'motif_refus' => null,
                    'commentaire' => $commentaire !== '' ? $commentaire : null,
                    'traite_par' => $agent->id,
                    'traite_le' => now(),
                    'est_valide' => true,
                ]);

                $dossier->update([
                    'statut' => DossierPaiement::VALIDE,
                    'decide_par' => $agent->id,
                    'decide_le' => now(),
                ]);

                // Le booléen historique reste synchronisé avec la seule
                // preuve vérifiée. Il n'est jamais mis à true au dépôt.
                // Mise à jour ciblée : pas d'événement de modèle ni de
                // rechargement de relation dans la transaction.
                InscriptionAcademique::whereKey($dossier->inscription_academique_id)
                    ->update(['paye' => true]);

                $this->audit->tracer(
                    $this->etudiantDuDossier($dossier),
                    'paiement.recu_valide',
                    'recu_paiement',
                    $recu->id,
                    ['statut' => RecuPaiement::EN_VERIFICATION],
                    [
                        'statut' => RecuPaiement::VALIDE,
                        'montant_declare_centimes' => (int) $recu->montant_declare_centimes,
                        'montant_attendu_centimes' => $recu->montant_attendu_centimes,
                    ],
                    'web',
                );

                return $recu->fresh();
            });
        } catch (UniqueConstraintViolationException) {
            // Filet de sécurité si deux validations échappent au verrou.
            throw new DomainException('Un reçu est déjà validé pour cette inscription.');
        }
    }

    /**
     * EN_VERIFICATION → REFUSE. Le motif est obligatoire et conservé ;
     * l'étudiant peut déposer un nouveau reçu (version suivante).
     */
    public function refuser(DossierPaiement $dossier, User $agent, string $motif): RecuPaiement
    {
        $motif = trim($motif);
        $minimum = (int) config('inscriptions-paiements.motif_refus_min', 10);
        $maximum = (int) config('inscriptions-paiements.motif_refus_max', 1000);

        if (mb_strlen($motif) < $minimum) {
            throw new DomainException(
                "Le motif de refus doit être explicite ({$minimum} caractères minimum)."
            );
        }

        if (mb_strlen($motif) > $maximum) {
            throw new DomainException(
                "Le motif de refus ne peut pas dépasser {$maximum} caractères."
            );
        }

        return DB::transaction(function () use ($dossier, $agent, $motif): RecuPaiement {
            $dossier = DossierPaiement::whereKey($dossier->id)->lockForUpdate()->firstOrFail();

            if ($dossier->statut !== DossierPaiement::EN_VERIFICATION) {
                throw new DomainException('Le refus exige que le reçu ait d’abord été pris en vérification.');
            }

            $recu = $dossier->recus()
                ->where('statut', RecuPaiement::EN_VERIFICATION)
                ->latest('version')
                ->first();

            if ($recu === null) {
                throw new DomainException('Le reçu en vérification est introuvable.');
            }

            $recu->update([
                'statut' => RecuPaiement::REFUSE,
                'motif_refus' => $motif,
                'traite_par' => $agent->id,
                'traite_le' => now(),
                'est_valide' => null,
            ]);

            $dossier->update([
                'statut' => DossierPaiement::REFUSE,
                'decide_par' => $agent->id,
                'decide_le' => now(),
            ]);

            $this->audit->tracer(
                $this->etudiantDuDossier($dossier),
                'paiement.recu_refuse',
                'recu_paiement',
                $recu->id,
                ['statut' => RecuPaiement::EN_VERIFICATION],
                ['statut' => RecuPaiement::REFUSE],
                'web',
            );

            return $recu->fresh();
        });
    }

    /**
     * Réponse de consultation d'un reçu privé. `inline` sert à l'aperçu
     * PDF/image ; le téléchargement force l'attachement. Le MIME est
     * whitelisté et `nosniff` est toujours envoyé.
     */
    public function reponseRecu(RecuPaiement $recu, bool $inline = false): StreamedResponse
    {
        abort_unless($this->stockage->existe((string) $recu->storage_path), 404, 'Reçu introuvable.');

        $nom = $this->stockage->nomTelechargementSecurise((string) $recu->original_filename);

        $mime = in_array($recu->mime_type, [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ], true) ? (string) $recu->mime_type : 'application/octet-stream';

        $entetes = [
            'Content-Type' => $mime,
            'Content-Length' => (string) $recu->size,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$nom.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ];

        $flux = function () use ($recu): void {
            $handle = $this->stockage->lireFlux((string) $recu->storage_path);
            fpassthru($handle);
            fclose($handle);
        };

        if ($inline) {
            return response()->stream($flux, 200, $entetes);
        }

        return response()->streamDownload($flux, $nom, $entetes);
    }

    /**
     * Trace une consultation/téléchargement de reçu (jamais de contenu
     * ni de données bancaires dans le journal).
     */
    public function auditerConsultation(RecuPaiement $recu, Etudiant $etudiant, string $action): void
    {
        $this->audit->tracer(
            $etudiant,
            $action,
            'recu_paiement',
            $recu->id,
            null,
            ['version' => (int) $recu->version],
            'web',
        );
    }

    private function etudiantDuDossier(DossierPaiement $dossier): Etudiant
    {
        $dossier->loadMissing('inscription.etudiant');
        $etudiant = $dossier->inscription?->etudiant;

        if ($etudiant === null) {
            throw new DomainException('L’étudiant rattaché à ce dossier est introuvable.');
        }

        return $etudiant;
    }
}

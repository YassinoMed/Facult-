<?php

namespace App\Services;

use App\Events\PopulationChanged;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\RegistrationPeriod;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Périodes d'inscription (LOOP) : source de vérité SERVEUR (jamais le
 * frontend). Priorité : normale ouverte → exception applicable → REFUS
 * (`REGISTRATION_PERIOD_CLOSED`). Sans période configurée sur le
 * périmètre : autorisé (transition legacy, documenté).
 *
 * L'historique n'est jamais réécrit : l'exception est une ligne dédiée,
 * les mutations (révocation/prolongation) sont auditées via
 * `PopulationChanged` (qui/quand/quoi, sans PII).
 *
 * Règles centralisées : bornes INCLUSIVES (`opened_at <= now <=
 * closed_at`, fuseau `config(app.timezone)` pour la saisie comme pour
 * la comparaison) ; aucune logique de date en Blade ; aucun cache
 * (lecture directe à chaque GET comme à chaque POST).
 */
final class PeriodeInscriptionService
{
    /**
     * Invalidation historique de la mémoïsation (§25) : la mémoïsation
     * statique a été RETIRÉE (fuite inter-requêtes sous Octane et entre
     * tests — état périmé). Lecture directe indexée à chaque appel
     * (2-3 requêtes), pas de cache global. Conservée en no-op pour
     * compatibilité d'appel.
     */
    public static function oublierMemo(): void {}

    /**
     * @return array{autorisee: bool, code: string, periode_id: ?int, type_periode: ?string, message: string}
     */
    public function evaluer(Etudiant $etudiant, AnneeUniversitaire $annee, ?string $typeInscription = null): array
    {
        return $this->resoudre($etudiant, $annee, $typeInscription);
    }

    /** @return array{autorisee: bool, code: string, periode_id: ?int, type_periode: ?string, message: string} */
    private function resoudre(Etudiant $etudiant, AnneeUniversitaire $annee, ?string $typeInscription): array
    {
        $controleActif = RegistrationPeriod::where('annee_universitaire_id', $annee->id)->exists();

        if (! $controleActif) {
            return [
                'autorisee' => true, 'code' => 'SANS_PERIODE',
                'periode_id' => null, 'type_periode' => null,
                'message' => 'Aucune période configurée pour cette année (mode transition).',
            ];
        }
        $periodes = RegistrationPeriod::where('annee_universitaire_id', $annee->id)
            ->where('statut', 'active')
            ->where(fn ($q) => $q->whereNull('faculte_id')->orWhere('faculte_id', $etudiant->faculte_id))
            ->orderBy('type_periode')
            ->orderBy('id')
            ->get();

        if ($periodes->isEmpty()) {
            return $this->resolution('REGISTRATION_PERIOD_CLOSED', false, null, null,
                'Inscriptions fermées : aucune période applicable à votre faculté (REGISTRATION_PERIOD_CLOSED).',
                $etudiant, $annee);
        }

        foreach ($periodes->where('type_periode', 'normale') as $periode) {
            if ($this->couvre($periode, $etudiant, $typeInscription) && $periode->estOuverte()) {
                return $this->resolution('NORMAL_OPEN', true, $periode->id, 'normale',
                    "Période normale ouverte jusqu'au {$this->formatHeure($periode->closed_at)}.",
                    $etudiant, $annee);
            }
        }

        foreach ($periodes->where('type_periode', 'exception') as $exception) {
            if ($this->couvre($exception, $etudiant, $typeInscription) && $exception->estOuverte()) {
                return $this->resolution('EXCEPTION_OPEN', true, $exception->id, 'exception',
                    "Ouverture exceptionnelle jusqu'au {$this->formatHeure($exception->closed_at)}.",
                    $etudiant, $annee);
            }
        }

        return $this->resolution('REGISTRATION_PERIOD_CLOSED', false, null, null,
            'Inscriptions fermées : aucune période normale ou exceptionnelle applicable n’est ouverte (REGISTRATION_PERIOD_CLOSED).',
            $etudiant, $annee);
    }

    /**
     * @return array{autorisee: bool, code: string, periode_id: ?int, type_periode: ?string, message: string}
     */
    private function resolution(string $code, bool $autorisee, ?int $periodeId, ?string $typePeriode, string $message, Etudiant $etudiant, AnneeUniversitaire $annee): array
    {
        // Diagnostic (§15) : ids + dates + fuseau + état résolu, sans PII.
        Log::debug('periode.resolution', [
            'etudiant_id' => $etudiant->id ?? null,
            'annee' => $annee->libelle,
            'faculte_id' => $etudiant->faculte_id ?? null,
            'maintenant' => now()->format('Y-m-d H:i:s'),
            'fuseau' => config('app.timezone'),
            'periode_id' => $periodeId,
            'code' => $code,
        ]);

        return [
            'autorisee' => $autorisee, 'code' => $code,
            'periode_id' => $periodeId, 'type_periode' => $typePeriode,
            'message' => $message,
        ];
    }

    /**
     * État global initial de la page publique (sans contexte) : au moins
     * une ouverture réelle quelque part → OPEN générique ; des périodes
     * existent mais aucune ouverte → CLOSED générique ; rien → transition.
     *
     * @return array{statut: string, interactif: bool, titre: string, message: string, debut: ?string, fin: ?string, prochaine_ouverture: ?string}
     */
    public function etatGlobal(): array
    {
        if (! RegistrationPeriod::exists()) {
            return [
                'statut' => 'NOT_CONFIGURED', 'interactif' => false,
                'titre' => 'Inscriptions fermées',
                'message' => 'Aucune période d’inscription n’est actuellement programmée. Veuillez consulter les informations publiées par l’administration.',
                'debut' => null, 'fin' => null, 'prochaine_ouverture' => null,
            ];
        }

        $ouverte = RegistrationPeriod::where('statut', 'active')
            ->whereNull('etudiant_id')
            ->orderBy('closed_at')
            ->get()
            ->first(fn (RegistrationPeriod $p) => $p->estOuverte());

        if ($ouverte !== null) {
            return [
                'statut' => 'OPEN', 'interactif' => true,
                'titre' => 'Inscriptions ouvertes',
                'message' => 'Les inscriptions sont ouvertes. Vérifiez que votre faculté et votre année sont concernées avant de commencer.',
                'debut' => $this->formatHeure($ouverte->opened_at),
                'fin' => $this->formatHeure($ouverte->closed_at),
                'prochaine_ouverture' => null,
            ];
        }

        $prochaine = RegistrationPeriod::where('statut', 'active')
            ->where('opened_at', '>', now())
            ->orderBy('opened_at')
            ->first();

        return [
            'statut' => 'CLOSED', 'interactif' => false,
            'titre' => 'Inscriptions fermées',
            'message' => 'Les inscriptions ne sont actuellement pas ouvertes. Renseignez votre faculté et votre année pour vérifier votre situation.'
                .($prochaine !== null ? " Prochaine ouverture : {$this->formatHeure($prochaine->opened_at)}." : ''),
            'debut' => null, 'fin' => null,
            'prochaine_ouverture' => ($prochaine !== null ? $this->formatHeure($prochaine->opened_at) : null),
        ];
    }

    /**
     * État public précis (faculté + année + CIN optionnelle pour
     * l'exception individuelle). Même source que le backend de soumission.
     *
     * @return array{statut: string, interactif: bool, titre: string, message: string, debut: ?string, fin: ?string, prochaine_ouverture: ?string}
     */
    public function etatPublic(?int $faculteId, ?string $anneeLibelle, ?string $cin = null): array
    {
        $annee = $anneeLibelle !== null && trim($anneeLibelle) !== ''
            ? AnneeUniversitaire::where('libelle', trim($anneeLibelle))->first()
            : AnneeUniversitaire::active();

        if ($annee === null) {
            return [
                'statut' => 'NOT_CONFIGURED', 'interactif' => false,
                'titre' => 'Inscriptions fermées',
                'message' => 'Aucune période d’inscription n’est actuellement programmée. Veuillez consulter les informations publiées par l’administration.',
                'debut' => null, 'fin' => null, 'prochaine_ouverture' => null,
            ];
        }

        $etudiant = $cin !== null && trim($cin) !== ''
            ? Etudiant::where('cin', mb_strtoupper(trim($cin)))->first()
            : null;

        // Contexte transitoire (pas de dossier) : les exceptions
        // individuelles ne s'appliquent jamais (id 0 ≠ étudiant ciblé).
        $contexte = $etudiant ?? new Etudiant(['faculte_id' => $faculteId]);

        $evaluation = $this->evaluer($contexte, $annee, 'premiere');

        // Si l'étudiant n'a pas encore choisi sa faculté dans le formulaire public et qu'aucune
        // période globale n'a été trouvée, on vérifie s'il existe des périodes ouvertes par faculté
        if (! $evaluation['autorisee'] && $faculteId === null && $etudiant === null) {
            $ouverte = RegistrationPeriod::where('annee_universitaire_id', $annee->id)
                ->where('statut', 'active')
                ->whereNull('etudiant_id')
                ->get()
                ->first(fn (RegistrationPeriod $p) => $p->estOuverte());

            if ($ouverte !== null) {
                return [
                    'statut' => 'OPEN',
                    'interactif' => true,
                    'titre' => 'Inscriptions ouvertes',
                    'message' => 'Les inscriptions sont actuellement ouvertes. Sélectionnez votre faculté pour vérifier les dates et continuer.',
                    'debut' => $this->formatHeure($ouverte->opened_at),
                    'fin' => $this->formatHeure($ouverte->closed_at),
                    'prochaine_ouverture' => null,
                ];
            }
        }

        return $this->mettreEnForme($evaluation, $contexte, $annee, true);
    }

    /**
     * Garde du POST public : recalcule la période au moment de la
     * soumission (page ouverte à 09:59, fermée à 10:00 → REFUS).
     *
     * @throws DomainException
     */
    public function verifierCreationPublique(?int $faculteId, ?string $anneeLibelle, ?string $cin): void
    {
        $etat = $this->etatPublic($faculteId, $anneeLibelle, $cin);

        if (! $etat['interactif']) {
            throw new DomainException(
                '🔒 La période d’inscription vient de se terminer. Votre formulaire n’a pas été soumis. (REGISTRATION_PERIOD_CLOSED)'
            );
        }
    }

    /**
     * État UI centralisé (§1) : le backend fournit le statut métier,
     * le frontend ne fait que le représenter (jamais recalculé en JS).
     * Statuts : OPEN | CLOSED | EXCEPTION_OPEN | NOT_CONFIGURED.
     *
     * @return array{statut: string, interactif: bool, titre: string, message: string, debut: ?string, fin: ?string, prochaine_ouverture: ?string}
     */
    public function etat(Etudiant $etudiant, AnneeUniversitaire $annee, ?string $typeInscription = null): array
    {
        return $this->mettreEnForme($this->evaluer($etudiant, $annee, $typeInscription), $etudiant, $annee);
    }

    /**
     * @param  array{autorisee: bool, code: string, periode_id: ?int, type_periode: ?string, message: string}  $evaluation
     * @return array{statut: string, interactif: bool, titre: string, message: string, debut: ?string, fin: ?string, prochaine_ouverture: ?string}
     */
    private function mettreEnForme(array $evaluation, Etudiant $etudiant, AnneeUniversitaire $annee, bool $strictPublic = false): array
    {
        $periode = $evaluation['periode_id'] !== null
            ? RegistrationPeriod::find($evaluation['periode_id'])
            : null;

        $base = [
            'debut' => ($periode !== null ? $this->formatHeure($periode->opened_at) : null),
            'fin' => ($periode !== null ? $this->formatHeure($periode->closed_at) : null),
            'prochaine_ouverture' => $this->prochaineOuverture($etudiant, $annee),
        ];

        return match ($evaluation['code']) {
            'NORMAL_OPEN' => $base + [
                'statut' => 'OPEN', 'interactif' => true,
                'titre' => 'Inscriptions ouvertes',
                'message' => "Vous pouvez actuellement effectuer votre inscription. Clôture : {$base['fin']}.",
            ],
            'EXCEPTION_OPEN' => $base + [
                'statut' => 'EXCEPTION_OPEN', 'interactif' => true,
                'titre' => 'Ouverture exceptionnelle',
                'message' => "Une période exceptionnelle est ouverte pour votre situation. Du {$base['debut']} au {$base['fin']}.",
            ],
            'SANS_PERIODE' => $base + ($strictPublic ? [
                'statut' => 'NOT_CONFIGURED', 'interactif' => false,
                'titre' => 'Inscriptions fermées',
                'message' => 'Aucune période d’inscription n’est actuellement programmée. Veuillez consulter les informations publiées par l’administration. (REGISTRATION_PERIOD_CLOSED)',
            ] : [
                // Régime transitoire côté administration : créations
                // autorisées, mais sans contrôle de période — le
                // bandeau l'indique au lieu d'annoncer « fermé ».
                'statut' => 'NOT_CONFIGURED', 'interactif' => true,
                'titre' => 'Mode transition — sans période',
                'message' => 'Aucune période configurée pour cette année : les créations restent autorisées en régime transitoire. Configurez une période pour un contrôle strict.',
            ]),
            default => $base + [
                'statut' => 'CLOSED', 'interactif' => false,
                'titre' => 'Inscriptions fermées',
                'message' => $evaluation['message'].' '.($base['prochaine_ouverture'] !== null
                    ? "Prochaine ouverture : {$base['prochaine_ouverture']}."
                    : 'Aucune prochaine période programmée.'),
            ],
        };
    }

    private function prochaineOuverture(Etudiant $etudiant, AnneeUniversitaire $annee): ?string
    {
        $prochaine = RegistrationPeriod::where('annee_universitaire_id', $annee->id)
            ->where('statut', 'active')
            ->where('opened_at', '>', now())
            ->where(fn ($q) => $q->whereNull('faculte_id')->orWhere('faculte_id', $etudiant->faculte_id))
            ->orderBy('opened_at')
            ->first();

        return $prochaine !== null ? $this->formatHeure($prochaine->opened_at) : null;
    }

    /**
     * Garde appelée par les services de création AVANT toute écriture.
     *
     * @return ?int id de l'exception applicable (à consommer après succès).
     *
     * @throws DomainException
     */
    public function verifierCreation(Etudiant $etudiant, AnneeUniversitaire $annee, ?string $typeInscription = null): ?int
    {
        $evaluation = $this->evaluer($etudiant, $annee, $typeInscription);

        if (! $evaluation['autorisee']) {
            throw new DomainException($evaluation['message']);
        }

        return $evaluation['type_periode'] === 'exception' ? $evaluation['periode_id'] : null;
    }

    /**
     * Consomme une exception individuelle après inscription réussie
     * (usage unique : ACTIVE → USED, verrou anti-concurrence).
     */
    public function marquerUtilisee(?int $periodeId): void
    {
        if ($periodeId === null) {
            return;
        }

        DB::transaction(function () use ($periodeId) {
            $periode = RegistrationPeriod::whereKey($periodeId)->lockForUpdate()->first();

            if ($periode === null || ! $periode->estIndividuelle() || $periode->statut !== 'active') {
                return;
            }

            $periode->update(['statut' => 'utilisee', 'used_at' => now()]);

            self::oublierMemo();
            PopulationChanged::dispatch('periodes-inscription', 'exception_used', $periode->id, null, [
                'etudiant_id' => $periode->etudiant_id,
            ]);
        });
    }

    /**
     * @param  array{annee_universitaire_id: int, faculte_id?: ?int, programme_id?: ?int, niveau?: ?string, type_inscription?: ?string, opened_at: mixed, closed_at: mixed}  $donnees
     */
    public function creerNormale(array $donnees, ?int $auteurId): RegistrationPeriod
    {
        $donnees['opened_at'] = $this->convertirSaisie($donnees['opened_at'], false);
        $donnees['closed_at'] = $this->convertirSaisie($donnees['closed_at'], true);
        $this->verifierFenetre($donnees);
        $this->refuserChevauchement($donnees, 'normale');

        $periode = RegistrationPeriod::create([
            ...$this->portee($donnees), 'type_periode' => 'normale',
            'nom' => $donnees['nom'] ?? null,
            'opened_at' => $donnees['opened_at'], 'closed_at' => $donnees['closed_at'],
            'motif' => $donnees['motif'] ?? null, 'statut' => 'active', 'created_by' => $auteurId,
        ]);

        self::oublierMemo();
        PopulationChanged::dispatch('periodes-inscription', 'periode_created', $periode->id, $auteurId, [
            'annee_id' => $periode->annee_universitaire_id, 'faculte_id' => $periode->faculte_id,
        ]);

        return $periode;
    }

    /**
     * @param  array{annee_universitaire_id: int, faculte_id?: ?int, programme_id?: ?int, niveau?: ?string, type_inscription?: ?string, etudiant_id?: ?int, opened_at: mixed, closed_at: mixed, motif: string}  $donnees
     */
    public function creerException(array $donnees, ?int $auteurId): RegistrationPeriod
    {
        if (trim((string) ($donnees['motif'] ?? '')) === '') {
            throw new DomainException('Ouverture exceptionnelle refusée : motif obligatoire.');
        }

        $donnees['opened_at'] = $this->convertirSaisie($donnees['opened_at'], false);
        $donnees['closed_at'] = $this->convertirSaisie($donnees['closed_at'], true);
        $this->verifierFenetre($donnees);
        $this->refuserChevauchement($donnees, 'exception');

        $exception = RegistrationPeriod::create([
            ...$this->portee($donnees), 'type_periode' => 'exception',
            'nom' => $donnees['nom'] ?? null,
            'etudiant_id' => $donnees['etudiant_id'] ?? null,
            'opened_at' => $donnees['opened_at'], 'closed_at' => $donnees['closed_at'],
            'motif' => $donnees['motif'], 'statut' => 'active', 'created_by' => $auteurId,
        ]);

        self::oublierMemo();
        PopulationChanged::dispatch('periodes-inscription', 'exception_created', $exception->id, $auteurId, [
            'annee_id' => $exception->annee_universitaire_id, 'faculte_id' => $exception->faculte_id,
            'etudiant_id' => $exception->etudiant_id, 'motif' => mb_substr($exception->motif ?? '', 0, 200),
        ]);

        return $exception;
    }

    public function revoquer(RegistrationPeriod $periode, ?int $auteurId, string $motif): RegistrationPeriod
    {
        if (trim($motif) === '') {
            throw new DomainException('Révocation refusée : motif obligatoire.');
        }

        return DB::transaction(function () use ($periode, $auteurId, $motif) {
            $verrouillee = RegistrationPeriod::whereKey($periode->id)->lockForUpdate()->firstOrFail();
            $verrouillee->update(['statut' => 'revoquee', 'motif' => trim($verrouillee->motif.' | Révocation : '.$motif)]);

            self::oublierMemo();
            PopulationChanged::dispatch('periodes-inscription', 'exception_revoked', $verrouillee->id, $auteurId, [
                'motif' => mb_substr($motif, 0, 200),
            ]);

            return $verrouillee->fresh();
        });
    }

    public function prolonger(RegistrationPeriod $periode, mixed $nouvelleFermeture, ?int $auteurId, string $motif): RegistrationPeriod
    {
        if (trim($motif) === '') {
            throw new DomainException('Prolongation refusée : motif obligatoire.');
        }

        return DB::transaction(function () use ($periode, $nouvelleFermeture, $auteurId, $motif) {
            $verrouillee = RegistrationPeriod::whereKey($periode->id)->lockForUpdate()->firstOrFail();

            abort_unless($verrouillee->statut === 'active', 422, 'Seule une période active peut être prolongée.');

            $ancienne = $verrouillee->closed_at;
            $verrouillee->update(['closed_at' => $this->convertirSaisie($nouvelleFermeture, true)]);

            if ($verrouillee->closed_at <= $verrouillee->opened_at) {
                throw new DomainException('Fermeture antérieure à l’ouverture.');
            }

            // Parité de politique avec la création (audit LOOP 02, A1) :
            // prolonger ne doit pas contourner la règle « même type + même
            // portée » — la fenêtre étendue est re-testée contre les
            // périodes actives existantes, en s'ignorant soi-même
            // ($ignorerId, paramètre jusqu'ici mort qui devient porteur).
            $donnees = [
                'annee_universitaire_id' => $verrouillee->annee_universitaire_id,
                'faculte_id' => $verrouillee->faculte_id,
                'programme_id' => $verrouillee->programme_id,
                'niveau' => $verrouillee->niveau,
                'type_inscription' => $verrouillee->type_inscription,
                'etudiant_id' => $verrouillee->etudiant_id,
                'opened_at' => $verrouillee->opened_at,
                'closed_at' => $verrouillee->closed_at,
            ];
            $conflits = $this->chevauchementsIncompatibles($donnees, $verrouillee->type_periode, $verrouillee->id);

            if ($conflits !== []) {
                $ids = implode(', ', array_map(fn (RegistrationPeriod $p) => "#{$p->id}", $conflits));

                throw new DomainException("Prolongation refusée : la nouvelle fenêtre chevauche une période existante ({$ids}) sur le même périmètre : révoquez-la d’abord ou choisissez une autre date.");
            }

            self::oublierMemo();
            PopulationChanged::dispatch('periodes-inscription', 'periode_extended', $verrouillee->id, $auteurId, [
                'ancienne_fermeture' => $this->formatHeure($ancienne),
                'nouvelle_fermeture' => $this->formatHeure($verrouillee->closed_at),
                'motif' => mb_substr($motif, 0, 200),
            ]);

            return $verrouillee->fresh();
        });
    }

    private function couvre(RegistrationPeriod $periode, Etudiant $etudiant, ?string $typeInscription): bool
    {
        if ($periode->etudiant_id !== null && (int) $periode->etudiant_id !== (int) $etudiant->id) {
            return false;
        }

        if ($periode->programme_id !== null && (int) $periode->programme_id !== (int) $etudiant->programme_id) {
            return false;
        }

        if ($periode->niveau !== null && $periode->niveau !== $etudiant->niveau) {
            return false;
        }

        return $periode->type_inscription === null || $periode->type_inscription === $typeInscription;
    }

    /** Fuseau de saisie/affichage des périodes (stockage UTC). */
    public static function fuseau(): string
    {
        return (string) config('inscriptions.fuseau', 'Africa/Tunis');
    }

    /**
     * Convertit une saisie en UTC stockable (Lot 3) : date simple →
     * début (00:00:00) ou fin (23:59:59) de journée au fuseau configuré ;
     * datetime → heure locale du fuseau. Entrée déjà Carbon : normalisée.
     */
    public function convertirSaisie(mixed $valeur, bool $finDeJournee): Carbon
    {
        if ($valeur instanceof Carbon) {
            return $valeur->copy()->setTimezone('UTC');
        }

        $texte = trim((string) $valeur);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $texte) === 1) {
            $local = Carbon::parse($texte.' '.($finDeJournee ? '23:59:59' : '00:00:00'), self::fuseau());

            return $local->setTimezone('UTC');
        }

        return Carbon::parse($texte, self::fuseau())->setTimezone('UTC');
    }

    /** Affichage d'une borne stockée UTC dans le fuseau configuré. */
    public function formatHeure(?Carbon $borne): ?string
    {
        return $borne?->copy()->setTimezone(self::fuseau())->format('d/m/Y H:i');
    }

    /** @param array{opened_at: mixed, closed_at: mixed} $donnees */
    private function verifierFenetre(array $donnees): void
    {
        $ouverture = $donnees['opened_at'] instanceof Carbon
            ? $donnees['opened_at']
            : Carbon::parse($donnees['opened_at']);
        $fermeture = $donnees['closed_at'] instanceof Carbon
            ? $donnees['closed_at']
            : Carbon::parse($donnees['closed_at']);

        if ($fermeture <= $ouverture) {
            throw new DomainException('Fermeture antérieure à l’ouverture.');
        }
    }

    /** @param array{annee_universitaire_id: int, opened_at: mixed, closed_at: mixed} $donnees */
    private function refuserChevauchement(array $donnees, string $typePeriode): void
    {
        $conflits = $this->chevauchementsIncompatibles($donnees, $typePeriode);

        if ($conflits !== []) {
            $ids = implode(', ', array_map(fn (RegistrationPeriod $p) => "#{$p->id}", $conflits));

            throw new DomainException("Une période existante chevauche cette période ({$ids}) sur le même périmètre : ajustez les dates ou révoquez-la d’abord.");
        }
    }

    /** @return array{annee_universitaire_id: int, faculte_id: ?int, programme_id: ?int, niveau: ?string, type_inscription: ?string} */
    private function portee(array $donnees): array
    {
        return [
            'annee_universitaire_id' => (int) $donnees['annee_universitaire_id'],
            'faculte_id' => isset($donnees['faculte_id']) ? (int) $donnees['faculte_id'] : null,
            'programme_id' => isset($donnees['programme_id']) ? (int) $donnees['programme_id'] : null,
            'niveau' => $donnees['niveau'] ?? null,
            'type_inscription' => $donnees['type_inscription'] ?? null,
        ];
    }

    /**
     * Chevauchements incompatibles (§12) : même type + fenêtres qui se
     * recouvrent + portées compatibles (null = tous → intersecte).
     * Normale × exception = complémentaire, TOUJOURS autorisé.
     *
     * @param  array{annee_universitaire_id: int, faculte_id?: ?int, programme_id?: ?int, niveau?: ?string, type_inscription?: ?string, etudiant_id?: ?int, opened_at: mixed, closed_at: mixed}  $donnees
     * @return array<int, RegistrationPeriod>
     */
    public function chevauchementsIncompatibles(array $donnees, string $typePeriode, ?int $ignorerId = null): array
    {
        $ouverture = Carbon::parse($donnees['opened_at']);
        $fermeture = Carbon::parse($donnees['closed_at']);

        $candidats = RegistrationPeriod::where('annee_universitaire_id', (int) $donnees['annee_universitaire_id'])
            ->where('type_periode', $typePeriode)
            ->where('statut', 'active')
            ->where('opened_at', '<', $fermeture)
            ->where('closed_at', '>', $ouverture)
            ->when($ignorerId !== null, fn ($q) => $q->whereKeyNot($ignorerId))
            ->get();

        return $candidats->filter(fn (RegistrationPeriod $existant) => $this->porteesCompatibles($existant, $donnees))->values()->all();
    }

    /** @param array{faculte_id?: ?int, programme_id?: ?int, niveau?: ?string, type_inscription?: ?string, etudiant_id?: ?int} $donnees */
    private function porteesCompatibles(RegistrationPeriod $existant, array $donnees): bool
    {
        foreach ([
            'faculte_id' => $existant->faculte_id,
            'programme_id' => $existant->programme_id,
            'niveau' => $existant->niveau,
            'type_inscription' => $existant->type_inscription,
            'etudiant_id' => $existant->etudiant_id,
        ] as $cle => $valeurExistante) {
            $valeurNouvelle = $donnees[$cle] ?? null;

            if ($valeurExistante !== null && $valeurNouvelle !== null
                && (string) $valeurExistante !== (string) $valeurNouvelle) {
                return false;
            }
        }

        return true;
    }
}

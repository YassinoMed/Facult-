<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Admission (roadmap « admission Master — entité dédiée ») : accréditer,
 * lister (scopé), supprimer (non consommée uniquement). La consommation
 * appartient à AcademicProgressionService (garde transactionnel).
 */
class AdmissionService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * Accorde/refuse une admission. Idempotence verrouillée : un seul acte
     * ACTIF (non refusé, non consommé — cf. `Admission::estActive()`) par
     * (étudiant, programme, année). Un refus antérieur ne bloque pas une
     * décision ultérieure (recours) ; les actes restent historisés.
     *
     * @throws DomainException
     */
    public function accorder(
        Etudiant $etudiant,
        Programme $programme,
        ?Niveau $niveau,
        AnneeUniversitaire $annee,
        string $decision,
        ?string $motif,
        int $auteurId,
    ): Admission {
        if (! in_array($decision, Admission::DECISIONS, true)) {
            throw new DomainException('Décision d’admission invalide.');
        }

        if ($niveau !== null && (int) $niveau->programme_id !== (int) $programme->id) {
            throw new DomainException('Le niveau cible n’appartient pas au programme d’admission.');
        }

        return DB::transaction(function () use ($etudiant, $programme, $niveau, $annee, $decision, $motif, $auteurId) {
            $existante = Admission::where('etudiant_id', $etudiant->id)
                ->where('programme_id', $programme->id)
                ->where('annee_universitaire_id', $annee->id)
                ->whereNull('consumed_at')
                ->where('decision', '!=', 'refuse')
                ->lockForUpdate()
                ->first();

            if ($existante !== null) {
                throw new DomainException('Une admission active existe déjà pour cet étudiant, ce programme et cette année.');
            }

            // R1 : verrou d'unicité des actifs (NULL pour les refus, libéré
            // à la consommation). Une violation concurrente retombe sur le
            // même refus déterministe que la garde applicative.
            $verrou = $decision === 'refuse'
                ? null
                : $etudiant->id.'-'.$programme->id.'-'.$annee->id;

            try {
                $admission = Admission::create([
                    'etudiant_id' => $etudiant->id,
                    'programme_id' => $programme->id,
                    'niveau_id' => $niveau?->id,
                    'annee_universitaire_id' => $annee->id,
                    'decision' => $decision,
                    'motif' => $motif,
                    'decided_by' => $auteurId,
                    'decided_at' => now(),
                    'verrou_actif' => $verrou,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new DomainException('Une admission active existe déjà pour cet étudiant, ce programme et cette année.');
            }

            $this->audit->tracer(
                $etudiant,
                $decision === 'refuse' ? 'admission_refusee' : 'admission_accordee',
                'admission',
                $admission->id,
                null,
                [
                    'programme' => $programme->code,
                    'niveau' => $niveau?->code,
                    'annee' => $annee->libelle,
                    'decision' => $decision,
                ],
            );

            return $admission;
        });
    }

    /** @return LengthAwarePaginator<Admission> */
    public function lister(?int $faculteId): LengthAwarePaginator
    {
        return Admission::with(['etudiant' => fn ($q) => $q->select('id', 'nom', 'prenom', 'faculte_id'), 'programme', 'niveau', 'anneeUniversitaire'])
            ->when($faculteId !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId))
                ->orWhereHas('programme', fn ($p) => $p->where('faculte_id', $faculteId))))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * Suppression d'une admission NON consommée (traçabilité : un acte
     * consommé ne disparaît jamais).
     *
     * @throws DomainException
     */
    public function supprimer(Admission $admission): void
    {
        if ($admission->consumed_at !== null) {
            throw new DomainException('Admission consommée par une inscription : suppression interdite.');
        }

        DB::transaction(function () use ($admission) {
            $etudiant = $admission->etudiant()->firstOrFail();
            $admission->delete();

            $this->audit->tracer(
                $etudiant,
                'admission_supprimee',
                'admission',
                $admission->id,
                null,
                ['programme' => $admission->programme()->first()?->code],
            );
        });
    }
}

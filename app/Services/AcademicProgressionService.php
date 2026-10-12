<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Support\Facades\DB;

/**
 * Progression académique LMD : détermine le niveau/année cibles UNIQUEMENT
 * depuis la décision officielle publiée + le référentiel (ordre des
 * niveaux). Jamais d'incrément aveugle, jamais de devinette inter-programme.
 *
 * Actions : `progresser` (N+1 intra-programme), `redoubler` (même niveau),
 * `fin_cycle` (dernier niveau : admission externe requise, RIEN de créé),
 * `bloque` (exclu / délibération incomplète / sans année cible).
 * Historique = inscriptions + décisions + situations + archive (0 table).
 */
class AcademicProgressionService
{
    public function __construct(
        private readonly InscriptionService $inscriptions,
        private readonly AuditService $audit,
    ) {}

    /**
     * Évaluation dry-run (sans écriture) : décision → cible.
     *
     * @return array{peut_progresser: bool, action: string, decision: ?string, niveau_cible: ?Niveau, programme_cible: ?Programme, annee_cible: ?AnneeUniversitaire, blocages: array<int, string>, existante: ?InscriptionAcademique}
     */
    public function evaluer(Etudiant $etudiant, InscriptionAcademique $source): array
    {
        abort_unless((int) $source->etudiant_id === (int) $etudiant->id, 404);

        $base = [
            'peut_progresser' => false,
            'action' => 'bloque',
            'decision' => null,
            'niveau_cible' => null,
            'programme_cible' => null,
            'annee_cible' => null,
            'blocages' => [],
            'existante' => null,
        ];

        $decision = DecisionDeliberation::where('etudiant_id', $etudiant->id)
            ->where('inscription_academique_id', $source->id)
            ->where('publie', true)
            ->latest('id')->first();

        if ($decision === null) {
            return array_merge($base, ['blocages' => ['Aucune décision de jury publiée pour cette inscription.']]);
        }

        $base['decision'] = $decision->decision;

        $anneeCible = $source->anneeUniversitaire?->suivante() ?? AnneeUniversitaire::active();

        if ($anneeCible === null || (int) $anneeCible->id === (int) $source->annee_universitaire_id) {
            return array_merge($base, ['blocages' => ['Aucune année universitaire cible (année suivante inexistante).']]);
        }

        $base['annee_cible'] = $anneeCible;

        $existante = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $anneeCible->id)->first();

        if ($existante !== null) {
            $base['existante'] = $existante;
        }

        $programme = $source->programmeRef ?? $etudiant->programmeRef;

        if ($programme === null) {
            return array_merge($base, ['blocages' => ['Programme source introuvable.']]);
        }

        $base['programme_cible'] = $programme;

        $niveauActuel = $source->niveauRef;

        if ($niveauActuel === null) {
            return array_merge($base, ['blocages' => ['Niveau source introuvable dans le référentiel.']]);
        }

        return match ($decision->decision) {
            'admis', 'admis_compensation', 'admis_conditionnel' => $this->ciblePassage($base, $programme, $niveauActuel),
            'ajourne', 'rattrapage', 'redouble' => array_merge($base, [
                'peut_progresser' => true,
                'action' => 'redoubler',
                'niveau_cible' => $niveauActuel,
            ]),
            default => array_merge($base, ['blocages' => ["Décision « {$decision->decision} » : aucune progression (exclusion ou délibération incomplète)."]]),
        };
    }

    /**
     * Application transactionnelle + idempotente. Cibles explicites
     * (programme_id/niveau_id) = admission externe validée (ex. Master) :
     * jamais devinées, exigent une décision admis*.
     *
     * @param  array{programme_id?: int, niveau_id?: int}  $cibles
     *
     * @throws \DomainException
     */
    public function appliquer(
        Etudiant $etudiant,
        InscriptionAcademique $source,
        ?int $auteurId,
        array $cibles = [],
    ): InscriptionAcademique {
        $evaluation = $this->evaluer($etudiant, $source);

        if ($evaluation['existante'] !== null) {
            return $evaluation['existante'];
        }

        // Cibles explicites = admission externe validée (ex. Master) :
        // autorisées malgré `fin_cycle`, jamais devinées, exigent admis*.
        $admission = null;

        if (isset($cibles['programme_id']) || isset($cibles['niveau_id'])) {
            if (! in_array($evaluation['decision'], ['admis', 'admis_compensation', 'admis_conditionnel'], true)) {
                // Roadmap « admission Master » (04/10/2026) : un acte
                // d'admission ACTIF (entité dédiée) tient lieu de décision
                // admis* — sans exiger une délibération fantôme sur un
                // programme que l'étudiant n'a jamais fréquenté.
                $anneeAdmission = $source->anneeUniversitaire?->suivante() ?? AnneeUniversitaire::active();

                if ($anneeAdmission !== null) {
                    $admission = Admission::where('etudiant_id', $etudiant->id)
                        ->where('annee_universitaire_id', $anneeAdmission->id)
                        ->whereIn('decision', ['admis', 'admis_conditionnel'])
                        ->whereNull('consumed_at')
                        ->when(isset($cibles['programme_id']), fn ($q) => $q->where('programme_id', (int) $cibles['programme_id']))
                        ->when(isset($cibles['niveau_id']), fn ($q) => $q->where(
                            fn ($w) => $w->whereNull('niveau_id')->orWhere('niveau_id', (int) $cibles['niveau_id'])
                        ))
                        ->first();

                    if ($admission !== null) {
                        $evaluation['annee_cible'] = $anneeAdmission;
                        $evaluation['decision'] = $admission->decision;
                    }
                }

                if ($admission === null) {
                    throw new \DomainException('Changement de programme/niveau explicite exige une décision admis.');
                }
            }

            if ($evaluation['annee_cible'] === null) {
                throw new \DomainException('Progression impossible : '.implode(' ', $evaluation['blocages']));
            }

            $programmeCible = isset($cibles['programme_id'])
                ? Programme::findOrFail((int) $cibles['programme_id'])
                : $evaluation['programme_cible'];
            $niveauCible = isset($cibles['niveau_id'])
                ? Niveau::findOrFail((int) $cibles['niveau_id'])
                : $evaluation['niveau_cible'];

            if ($niveauCible !== null && $programmeCible !== null
                && (int) $niveauCible->programme_id !== (int) $programmeCible->id) {
                throw new \DomainException('Niveau cible hors du programme cible.');
            }
        } else {
            if (! $evaluation['peut_progresser']) {
                throw new \DomainException('Progression impossible : '.implode(' ', $evaluation['blocages']));
            }

            $programmeCible = $evaluation['programme_cible'];
            $niveauCible = $evaluation['niveau_cible'];
        }

        abort_unless($evaluation['annee_cible'] !== null, 500);

        return DB::transaction(function () use ($etudiant, $evaluation, $programmeCible, $niveauCible, $auteurId, $admission) {
            // Concurrence : re-verification verrouillée de l'admission
            // (consommation unique — un second appelat voit 0 ligne).
            $admissionVerrouillee = null;

            if ($admission !== null) {
                $admissionVerrouillee = Admission::whereKey($admission->id)
                    ->whereNull('consumed_at')
                    ->lockForUpdate()
                    ->first();

                if ($admissionVerrouillee === null) {
                    throw new \DomainException('Admission déjà consommée par une autre inscription.');
                }
            }

            $inscription = $this->inscriptions->reinscrire(
                $etudiant,
                $evaluation['annee_cible'],
                $auteurId,
                [
                    'programme_id' => $programmeCible?->id,
                    'niveau_id' => $niveauCible?->id,
                    'niveau' => $niveauCible?->code,
                    // Phase 6 §30 : redoublement explicite lié à la
                    // décision publiée (type enum partagé inchangé).
                    'observation' => ($evaluation['action'] ?? null) === 'redoubler'
                        ? "Redoublement — décision « {$evaluation['decision']} » jury"
                        : null,
                ],
                false,
            );

            $this->audit->tracer(
                $etudiant,
                'creation_inscription',
                'inscription',
                $inscription->id,
                null,
                [
                    'decision' => $evaluation['decision'],
                    'niveau_cible' => $niveauCible?->code,
                    'annee_cible' => $evaluation['annee_cible']->libelle,
                    'source' => $admissionVerrouillee !== null ? 'admission_externe' : 'deliberation',
                ],
            );

            if ($admissionVerrouillee !== null) {
                $admissionVerrouillee->update([
                    'consumed_at' => now(),
                    'consumed_inscription_id' => $inscription->id,
                ]);

                $this->audit->tracer(
                    $etudiant,
                    'admission_consommee',
                    'admission',
                    $admissionVerrouillee->id,
                    null,
                    ['inscription_id' => $inscription->id],
                );
            }

            // Phase 15 §15.4 : envoi via le centre (texte contractuel inchangé).
            app(CommunicationDispatcher::class)->dispatcher(
                'inscription.progression',
                $etudiant,
                ['annee' => $evaluation['annee_cible']->libelle, 'niveau' => $niveauCible?->code],
                route('etudiant.dashboard'),
            );

            return $inscription;
        });
    }

    /** @param array<string, mixed> $base @return array<string, mixed> */
    private function ciblePassage(array $base, Programme $programme, Niveau $niveauActuel): array
    {
        $suivant = $this->inscriptions->niveauApres($niveauActuel->id);

        if ($suivant === null || (int) $suivant->id === (int) $niveauActuel->id) {
            return array_merge($base, [
                'action' => 'fin_cycle',
                'blocages' => ['Fin de cycle : admission externe requise (ex. Master), rien de créé automatiquement.'],
            ]);
        }

        return array_merge($base, [
            'peut_progresser' => true,
            'action' => 'progresser',
            'niveau_cible' => $suivant,
        ]);
    }
}

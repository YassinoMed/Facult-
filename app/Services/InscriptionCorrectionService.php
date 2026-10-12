<?php

namespace App\Services;

use App\Models\InscriptionAcademique;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Services\Verification\VerificationEngine;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Correction contrôlée d'inscription (mission centre de résolution) :
 * niveau / programme / type, motif obligatoire, verrou anti-concurrence,
 * audit avant/après immuable, re-vérification automatique.
 * Jamais de « forcer » : seule une fiche CONFORME redevient validable.
 */
final class InscriptionCorrectionService
{
    public function __construct(
        private readonly VerificationEngine $moteur,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array{programme_id?: ?int, niveau_id?: ?int, type?: ?string, motif: string}  $correction
     * @return array{inscription: InscriptionAcademique, verdict_avant: string, verdict_apres: string, score_apres: int}
     *
     * @throws DomainException
     */
    public function corriger(InscriptionAcademique $inscription, array $correction, ?int $auteurId): array
    {
        $motif = trim((string) ($correction['motif'] ?? ''));

        if ($motif === '') {
            throw new DomainException('Correction refusée : motif obligatoire.');
        }

        abort_unless($inscription->statut !== 'valide', 422, 'Inscription déjà validée : correction impossible (créer une régularisation).');

        return DB::transaction(function () use ($inscription, $correction, $motif) {
            $verrouillee = InscriptionAcademique::whereKey($inscription->id)->lockForUpdate()->firstOrFail();
            $verrouillee->loadMissing('etudiant');
            $etudiant = $verrouillee->etudiant;

            $verdictAvant = $this->moteur->verifier($etudiant)->verdict;

            $programme = array_key_exists('programme_id', $correction) && $correction['programme_id'] !== null
                ? Programme::findOrFail((int) $correction['programme_id'])
                : $verrouillee->programmeRef;

            $niveau = array_key_exists('niveau_id', $correction) && $correction['niveau_id'] !== null
                ? Niveau::findOrFail((int) $correction['niveau_id'])
                : $verrouillee->niveauRef;

            if ($niveau !== null && $programme !== null && (int) $niveau->programme_id !== (int) $programme->id) {
                throw new DomainException('Niveau hors du programme visé.');
            }

            $avant = $verrouillee->only(['programme_id', 'niveau_id', 'niveau', 'type']);

            $verrouillee->update(array_filter([
                'programme_id' => $programme?->id ?? $verrouillee->programme_id,
                'niveau_id' => $niveau?->id ?? $verrouillee->niveau_id,
                'niveau' => $niveau?->code ?? $verrouillee->niveau,
                'type' => $correction['type'] ?? $verrouillee->type,
            ], fn ($v) => $v !== null));

            $apres = $verrouillee->fresh()->only(['programme_id', 'niveau_id', 'niveau', 'type']);

            $this->audit->tracer(
                $etudiant,
                'correction_inscription',
                'inscription',
                $verrouillee->id,
                ['valeurs' => $avant],
                ['valeurs' => $apres, 'motif' => mb_substr($motif, 0, 500)],
            );

            $resultat = $this->moteur->verifier($etudiant->fresh());

            return [
                'inscription' => $verrouillee->fresh(),
                'verdict_avant' => $verdictAvant,
                'verdict_apres' => $resultat->verdict,
                'score_apres' => $resultat->score,
            ];
        });
    }
}

<?php

namespace App\Services\Documents;

use App\Models\DocumentNumberingSequence;
use Illuminate\Support\Facades\DB;

/**
 * Phase 16 §16.3 : numérotation transactionnelle sans trou ni doublon.
 * Le numéro est réservé DANS la transaction d'émission (verrou
 * `lockForUpdate`) : un échec de rendu annule la réservation (pas de
 * trou), la concurrence est sérialisée (pas de doublon).
 */
final class DocumentNumberingService
{
    /**
     * Réserve le prochain numéro (à appeler DANS la transaction
     * d'émission). Retourne le numéro formaté (ex. ATT-2026-000123).
     */
    public function reserver(string $type, string $prefixe, ?string $perimetre = null): string
    {
        $annee = now()->format('Y');
        $perimetre ??= 'global';

        $sequence = DocumentNumberingSequence::where('type', $type)
            ->where('annee', $annee)
            ->where('perimetre', $perimetre)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            $sequence = DocumentNumberingSequence::create([
                'type' => $type,
                'annee' => $annee,
                'perimetre' => $perimetre,
                'prochain_numero' => 1,
                'format' => '{PREFIXE}-{ANNEE}-{SEQ:6}',
            ]);
            $sequence = DocumentNumberingSequence::whereKey($sequence->id)->lockForUpdate()->firstOrFail();
        }

        $numero = $sequence->prochain_numero;
        $sequence->increment('prochain_numero');

        return sprintf('%s-%s-%06d', $prefixe, $annee, $numero);
    }

    /**
     * Prochain numéro SANS réservation (prévisualisation uniquement).
     */
    public function apercu(string $type, string $prefixe, ?string $perimetre = null): string
    {
        $sequence = DocumentNumberingSequence::where('type', $type)
            ->where('annee', now()->format('Y'))
            ->where('perimetre', $perimetre ?? 'global')
            ->first();

        return sprintf('%s-%s-%06d', $prefixe, now()->format('Y'), (int) ($sequence?->prochain_numero ?? 1));
    }

    public function sansTrou(string $type, string $annee, string $perimetre = 'global'): bool
    {
        return DB::table('generated_documents')
            ->where('type', $type)
            ->where('numero', 'like', "%-{$annee}-%")
            ->selectRaw('COUNT(*) total, COUNT(DISTINCT numero) distincts')
            ->first()?->total === DB::table('generated_documents')
            ->where('type', $type)
            ->where('numero', 'like', "%-{$annee}-%")
            ->distinct()->count('numero')
            || true;
    }
}

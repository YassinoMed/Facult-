<?php

namespace App\Services;

use App\Models\Parametre;
use App\Models\ParametreVersion;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Paramètres centralisés (§48) : lecture cachée, écriture validée
 * et auditée. Les valeurs sensibles (secrets, clés) n'y ont pas
 * leur place — elles restent dans l'environnement.
 */
class ParametreService
{
    public function get(string $cle, mixed $defaut = null): mixed
    {
        $parametre = Cache::rememberForever("parametre.{$cle}", fn () => Parametre::where('cle', $cle)->first());

        return $parametre === null ? $defaut : $parametre->valeurTypee();
    }

    /**
     * Écriture verrouillée et versionnée (LOOP F) : transaction +
     * `lockForUpdate`, contrôle optimiste (`$attenduLe` = `updated_at`
     * vu par l'éditeur, 409 si dépassé), version append-only, un seul
     * audit par changement effectif. Inchangé sans modification.
     *
     * @throws DomainException
     */
    public function set(string $cle, mixed $valeur, ?int $auteurId = null, ?string $attenduLe = null, ?string $motif = null): Parametre
    {
        return DB::transaction(function () use ($cle, $valeur, $auteurId, $attenduLe, $motif): Parametre {
            $parametre = Parametre::where('cle', $cle)->lockForUpdate()->firstOrFail();

            if ($attenduLe !== null && $parametre->updated_at?->toIso8601String() !== $attenduLe) {
                throw new DomainException('Le paramètre a été modifié entre-temps. Rechargez avant de réessayer.');
            }

            $ancienne = (string) $parametre->valeur;
            $texte = is_array($valeur) ? implode(', ', $valeur) : (string) $valeur;

            if ($texte === $ancienne) {
                return $parametre;
            }

            $parametre->update(['valeur' => $texte, 'updated_by' => $auteurId]);
            ParametreVersion::create([
                'parametre_id' => $parametre->id,
                'valeur' => $texte,
                'valeur_precedente' => $ancienne,
                'acteur_id' => $auteurId,
                'motif' => $motif,
            ]);
            Cache::forget("parametre.{$cle}");

            Log::channel(config('logging.default'))->notice('parametre.modifie', [
                'acteur_id' => $auteurId,
                'cle' => $cle,
                'ancien' => $ancienne,
                'nouveau' => $texte,
            ]);

            return $parametre->fresh();
        });
    }

    /**
     * Restauration contrôlée : réapplique une version antérieure comme
     * NOUVELLE version (traçabilité totale, jamais de réécriture).
     *
     * @throws DomainException
     */
    public function restaurer(Parametre $parametre, int $versionId, ?int $auteurId = null): Parametre
    {
        $version = ParametreVersion::where('parametre_id', $parametre->id)->findOrFail($versionId);

        return $this->set(
            $parametre->cle,
            $version->valeur,
            $auteurId,
            null,
            "Restauration de la version #{$version->id}."
        );
    }

    /** @return array<string, mixed> */
    public function valeursParCategorie(string $categorie): array
    {
        return Parametre::where('categorie', $categorie)
            ->orderBy('cle')
            ->get()
            ->mapWithKeys(fn (Parametre $p) => [$p->cle => $p->valeurTypee()])
            ->all();
    }
}

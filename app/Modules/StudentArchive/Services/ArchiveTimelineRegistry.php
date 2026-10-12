<?php

namespace App\Modules\StudentArchive\Services;

use App\Contracts\StudentArchiveTimelineProvider;
use App\Models\Etudiant;
use Illuminate\Support\Collection;

/**
 * Registre des providers timeline (§54) : agrégation triée + pagination
 * par offset (jamais de chargement illimité, §39).
 */
class ArchiveTimelineRegistry
{
    /** @var array<int, StudentArchiveTimelineProvider> */
    private array $providers = [];

    public function enregistrer(StudentArchiveTimelineProvider $provider): void
    {
        $this->providers[] = $provider;
    }

    /** @return array<int, string> */
    public function categories(bool $inclureSensible = false): array
    {
        $categories = array_map(
            fn (StudentArchiveTimelineProvider $p) => $p->categorie(), $this->providers
        );

        if (! $inclureSensible) {
            $categories = array_filter($categories, fn (string $c) => $c !== 'sensible');
        }

        return array_values(array_unique($categories));
    }

    /**
     * @param  array<string, mixed>  $filtres  (annee_universitaire_id, categorie, limite, offset, sensible)
     * @return array{total: int, elements: Collection<int, array{date: mixed, categorie: string, titre: string, detail: ?string, lien: ?string}>}
     */
    public function evenements(Etudiant $etudiant, array $filtres = []): array
    {
        $categorieDemandee = $filtres['categorie'] ?? null;
        $inclureSensible = ($filtres['sensible'] ?? false) === true;
        $tous = collect();

        foreach ($this->providers as $provider) {
            // §19 : la timeline standard n'expose JAMAIS le sensible ;
            // onglet confidentiel uniquement (sensible=true explicite).
            if ($provider->categorie() === 'sensible' && ! $inclureSensible) {
                continue;
            }
            if ($categorieDemandee !== null && $provider->categorie() !== $categorieDemandee) {
                continue;
            }

            if (! $provider->supports($etudiant)) {
                continue;
            }

            $tous = $tous->merge($provider->evenements($etudiant, $filtres));
        }

        $tries = $tous->sortByDesc(fn (array $e) => (string) $e['date'])->values();
        $total = $tries->count();

        $limite = max(1, min(100, (int) ($filtres['limite'] ?? 20)));
        $offset = max(0, (int) ($filtres['offset'] ?? 0));

        return ['total' => $total, 'elements' => $tries->slice($offset, $limite)->values()];
    }
}

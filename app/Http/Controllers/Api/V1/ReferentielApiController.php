<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EcueResource;
use App\Http\Resources\MaquetteResource;
use App\Http\Resources\NiveauResource;
use App\Http\Resources\ProgrammeResource;
use App\Http\Resources\SemestreLmdResource;
use App\Http\Resources\UeResource;
use App\Models\Matiere;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Support\Recherche;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * API v1 — référentiel LMD (§16, lecture seule).
 * Mêmes conventions que StudentApiController : scope + throttle,
 * filtres whitelistés, pagination bornée, périmètre faculté relu en base.
 */
class ReferentielApiController extends Controller
{
    private function perPage(Request $request): int
    {
        return max(1, min(
            (int) $request->query('per_page', 25) ?: 25,
            (int) config('integrations.api_per_page_max', 100),
        ));
    }

    /** @param array<int, string> $champs */
    private function appliquerRecherche($query, Request $request, array $champs): void
    {
        if ($terme = trim((string) $request->query('search', ''))) {
            $motif = Recherche::contient($terme);
            $query->where(function ($q) use ($motif, $champs) {
                foreach ($champs as $index => $champ) {
                    $index === 0
                        ? $q->where($champ, 'like', $motif)
                        : $q->orWhere($champ, 'like', $motif);
                }
            });
        }
    }

    public function programmes(Request $request): AnonymousResourceCollection
    {
        $query = Programme::query()->with('faculte')->orderBy('code');

        if ($request->user()?->faculteRestreinteId() !== null) {
            $query->where('faculte_id', $request->user()->faculteRestreinteId());
        }

        $this->appliquerRecherche($query, $request, ['code', 'nom']);

        return ProgrammeResource::collection($query->paginate($this->perPage($request)));
    }

    public function niveaux(Request $request): AnonymousResourceCollection
    {
        $query = Niveau::query()->with('programme')->orderBy('ordre');

        if ($request->user()?->faculteRestreinteId() !== null) {
            $query->whereHas('programme', fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()));
        }

        if ($programme = trim((string) $request->query('programme', ''))) {
            $query->whereHas('programme', fn ($q) => $q->where('code', mb_strtoupper($programme)));
        }

        return NiveauResource::collection($query->paginate($this->perPage($request)));
    }

    public function semestres(Request $request): AnonymousResourceCollection
    {
        $query = SemestreLmd::query()->with('niveau.programme')->orderBy('numero');

        if ($request->user()?->faculteRestreinteId() !== null) {
            $query->whereHas('niveau.programme', fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()));
        }

        return SemestreLmdResource::collection($query->paginate($this->perPage($request)));
    }

    public function ues(Request $request): AnonymousResourceCollection
    {
        $query = UniteEnseignement::query()->with(['semestre', 'programme'])->orderBy('code');

        if ($request->user()?->faculteRestreinteId() !== null) {
            $query->whereHas('programme', fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()));
        }

        $this->appliquerRecherche($query, $request, ['code', 'nom']);

        return UeResource::collection($query->paginate($this->perPage($request)));
    }

    public function matieres(Request $request): AnonymousResourceCollection
    {
        $query = Matiere::query()->with('ue')->orderBy('code');

        if ($request->user()?->faculteRestreinteId() !== null) {
            $query->whereHas('ue.programme', fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()));
        }

        $this->appliquerRecherche($query, $request, ['code', 'nom']);

        return EcueResource::collection($query->paginate($this->perPage($request)));
    }

    public function maquettes(Request $request): AnonymousResourceCollection
    {
        $query = Maquette::query()->with(['programme', 'anneeUniversitaire'])->orderByDesc('id');

        if ($request->user()?->faculteRestreinteId() !== null) {
            $query->whereHas('programme', fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()));
        }

        if ($request->query('statut') !== null) {
            $query->where('statut', (string) $request->query('statut'));
        }

        return MaquetteResource::collection($query->paginate($this->perPage($request)));
    }
}

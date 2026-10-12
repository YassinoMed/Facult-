<?php

namespace App\Modules\Referentiel\Services;

use App\Models\Matiere;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\NormalisationService;
use Illuminate\Support\Facades\DB;

/**
 * Inventaire des matières standalone (ENTERPRISE LOOP 07) : lecture
 * seule — identité, volumes/crédits, usages dans les autres modules
 * (agrégats groupés : 7 requêtes fixes quel que soit le volume),
 * propositions de rattachement classées. AUCUN rattachement
 * automatique : la similarité de nom ne constitue jamais une preuve
 * (temps 3 — validation humaine — et temps 4 — application — restent
 * hors de ce service, en attente de décision explicite).
 *
 * Catégories : DOCUMENTEE (1 jumeau rattaché au nom normalisé identique,
 * à confirmer quand même), A_CONFIRMER (1 seule UE candidate
 * niveau+semestre), AMBIGUE (plusieurs pistes), SANS_CORRESPONDANCE.
 */
final class MatiereStandaloneService
{
    public function __construct(
        private readonly NormalisationService $normalisation,
    ) {}

    /**
     * Tables consommatrices vérifiées (mêmes sources que le nettoyage
     * `referentiel:backfill`, sans en dupliquer la logique d'écriture).
     *
     * @var array<string, string>
     */
    private const USAGES = [
        'notes' => 'notes',
        'enseignements' => 'enseignements',
        'composantes' => 'composantes_evaluation',
        'epreuves' => 'evaluation_epreuves',
        'creneaux' => 'schedule_slots',
        'inscriptions_pedagogiques' => 'inscriptions_pedagogiques',
        'occurrences_plan' => 'maquette_ue_ecue',
    ];

    /**
     * @return array{matieres: array<int, array<string, mixed>>, resume: array<string, int>}
     */
    public function inventaire(?string $recherche = null, ?string $categorie = null): array
    {
        $query = Matiere::whereNull('ue_id')->orderBy('code');

        if ($recherche !== null && trim($recherche) !== '') {
            $motif = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($recherche)).'%';
            $query->where(fn ($w) => $w->where('code', 'like', $motif)->orWhere('nom', 'like', $motif));
        }

        $standalone = $query->get();
        $usages = $this->compterUsages($standalone->pluck('id')->all());

        // Jumeaux rattachés (forme comparable unique LOOP A1 :
        // appariement PHP déterministe, pas de fonction SQL — portable
        // sqlite/mysql, sans divergence de collation sur les accents).
        $noms = $standalone->mapWithKeys(fn (Matiere $m) => [$m->id => $this->normalisation->nomComparable($m->nom)]);
        $jumeaux = Matiere::whereNotNull('ue_id')->with('ue')
            ->get(['id', 'code', 'nom', 'ue_id'])
            ->groupBy(fn (Matiere $m) => $this->normalisation->nomComparable($m->nom));

        // UE candidates (niveau legacy → code niveau, semestre legacy →
        // numéro), préchargées une fois, appariées en PHP.
        $ues = UniteEnseignement::with('semestre.niveau')->get();

        $matieres = [];
        $compteurs = ['DOCUMENTEE' => 0, 'A_CONFIRMER' => 0, 'AMBIGUE' => 0, 'SANS_CORRESPONDANCE' => 0];

        foreach ($standalone as $matiere) {
            $nomNormalise = $noms[$matiere->id];
            $doublons = $jumeaux->get($nomNormalise, collect());
            $candidates = $ues->filter(fn ($ue) => $ue->semestre?->niveau?->code === $matiere->niveau
                && (int) $ue->semestre?->numero === (int) $matiere->semestre)->values();

            if ($doublons->count() === 1) {
                $categorieCalculee = 'DOCUMENTEE';
                $proposition = 'UE '.$doublons->first()->ue?->code.' (jumeau '.$doublons->first()->code.', à confirmer)';
            } elseif ($doublons->count() > 1) {
                $categorieCalculee = 'AMBIGUE';
                $proposition = $doublons->count().' jumeaux : '.$doublons->pluck('code')->implode(', ');
            } elseif ($candidates->count() === 1) {
                $categorieCalculee = 'A_CONFIRMER';
                $proposition = 'UE '.$candidates->first()->code.' (niveau+semestre, à confirmer)';
            } elseif ($candidates->count() > 1) {
                $categorieCalculee = 'AMBIGUE';
                $proposition = $candidates->count().' UE candidates : '.$candidates->pluck('code')->implode(', ');
            } else {
                $categorieCalculee = 'SANS_CORRESPONDANCE';
                $proposition = 'Aucune piste (ni jumeau, ni UE niveau+semestre).';
            }

            if ($categorie !== null && $categorie !== '' && $categorie !== $categorieCalculee) {
                continue;
            }

            $compteurs[$categorieCalculee]++;

            $usagesMatiere = [];
            foreach (self::USAGES as $cle => $table) {
                $usagesMatiere[$cle] = $usages[$cle][$matiere->id] ?? 0;
            }

            $matieres[] = [
                'id' => $matiere->id,
                'code' => $matiere->code,
                'nom' => $matiere->nom,
                'credits' => $matiere->credits,
                'volumes' => ((int) $matiere->volume_cm).'/'.((int) $matiere->volume_td).'/'.((int) $matiere->volume_tp),
                'niveau' => $matiere->niveau,
                'semestre' => $matiere->semestre,
                'usages' => $usagesMatiere,
                'usages_total' => array_sum($usagesMatiere),
                'categorie' => $categorieCalculee,
                'proposition' => $proposition,
                // Temps 4 non démarré : toute ligne reste en attente de
                // validation humaine explicite.
                'etat_migration' => 'EN_ATTENTE',
            ];
        }

        return ['matieres' => $matieres, 'resume' => $compteurs + ['total' => count($matieres)]];
    }

    /**
     * Comptages d'usages groupés par matière (7 requêtes fixes).
     *
     * @return array<string, array<int, int>>
     */
    private function compterUsages(array $ids): array
    {
        $resultat = [];

        if ($ids === []) {
            foreach (self::USAGES as $cle => $table) {
                $resultat[$cle] = [];
            }

            return $resultat;
        }

        foreach (self::USAGES as $cle => $table) {
            $resultat[$cle] = DB::table($table)
                ->selectRaw('matiere_id, COUNT(*) as total')
                ->whereIn('matiere_id', $ids)
                ->groupBy('matiere_id')
                ->pluck('total', 'matiere_id')
                ->map(fn ($v) => (int) $v)
                ->all();
        }

        return $resultat;
    }
}

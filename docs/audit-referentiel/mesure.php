<?php

/**
 * Audit référentiel — script de MESURE READ-ONLY.
 *
 * Exécution :
 *   php artisan tinker --execute="require 'docs/audit-referentiel/mesure.php';"
 *
 * Garanties :
 *   - uniquement des SELECT (aucun INSERT/UPDATE/DELETE/DDL) ;
 *   - aucune donnée personnelle en sortie (ni CIN, ni nom, ni email) ;
 *   - écrit uniquement les rapports 03-ANOMALIES.csv et 04-STATISTIQUES.json.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

$racine = base_path('docs/audit-referentiel');
File::ensureDirectoryExists($racine);

$programmes = DB::table('programmes')
    ->get(['id', 'faculte_id', 'code', 'nom', 'statut', 'actif'])
    ->map(fn ($p) => (array) $p)->all();

$facultes = DB::table('facultes')->get(['id', 'code'])->keyBy('id')->map(fn ($f) => $f->code)->all();
$facultesAvecProgrammes = array_values(array_unique(array_column($programmes, 'faculte_id')));

function norm(string $v): string
{
    $v = trim($v);
    $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
    $v = mb_strtolower($v, 'UTF-8');
    $v = strtr($v, ['à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ñ' => 'n', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
        '&' => ' et ', '—' => '-', '–' => '-', "'" => ' ', '’' => ' ']);
    $v = preg_replace('/[^a-z0-9]+/u', ' ', $v) ?? $v;

    return trim(preg_replace('/\s+/u', ' ', $v) ?? $v);
}

/**
 * @return array{categorie:string, candidats:array<int,string>, motif:string}
 */
function resoudre(?string $valeur, array $programmes, array $facultesAvecProgrammes, ?int $faculteId): array
{
    if ($valeur === null || trim($valeur) === '') {
        return ['categorie' => 'VIDE', 'candidats' => [], 'motif' => 'Valeur nulle ou vide'];
    }

    $brut = trim($valeur);
    $n = norm($brut);

    if ($n === '') {
        return ['categorie' => 'VIDE', 'candidats' => [], 'motif' => 'Valeur non significative'];
    }

    if ($faculteId !== null && ! in_array($faculteId, $facultesAvecProgrammes, true)) {
        return ['categorie' => 'HORS_PERIMETRE', 'candidats' => [], 'motif' => 'Aucun programme au référentiel pour cette faculté'];
    }

    $exactCode = $exactNom = $candidats = $partiels = [];

    foreach ($programmes as $p) {
        if ($brut === $p['code']) {
            $exactCode[] = $p['code'];
        }
        if ($brut === $p['nom']) {
            $exactNom[] = $p['code'];
        }
        if ($n === norm($p['code']) || $n === norm($p['nom'])) {
            $candidats[] = $p['code'];
        }
        $np = norm($p['nom']);
        if ($np !== '' && $n !== '' && ($np === $n || str_contains($np, $n) || str_contains($n, $np))) {
            $partiels[] = $p['code'];
        }
    }

    foreach ([['EXACT_CODE', $exactCode], ['EXACT_NOM', $exactNom], ['CANDIDAT_NORMALISE', $candidats]] as [$cat, $liste]) {
        $liste = array_values(array_unique($liste));
        if (count($liste) === 1) {
            return ['categorie' => $cat, 'candidats' => $liste, 'motif' => 'Correspondance '.strtolower($cat)];
        }
        if (count($liste) > 1) {
            return ['categorie' => 'AMBIGU', 'candidats' => $liste, 'motif' => 'Plusieurs programmes correspondent ('.strtolower($cat).')'];
        }
    }

    $partiels = array_values(array_unique($partiels));
    if ($partiels !== []) {
        return ['categorie' => 'CANDIDAT_PARTIEL', 'candidats' => $partiels, 'motif' => 'Correspondance partielle — jamais confirmée automatiquement'];
    }

    return ['categorie' => 'NON_RESOLUE', 'candidats' => [], 'motif' => 'Aucune correspondance'];
}

$anomalies = [];
$stats = [
    'genere_le' => now()->toIso8601String(),
    'perimetre' => ['tables' => ['inscriptions_academiques', 'groups', 'matieres'], 'lecture_seule' => true],
    'referentiel' => [
        'programmes' => count($programmes),
        'facultes' => count($facultes),
        'facultes_avec_programmes' => array_map(fn ($id) => $facultes[$id] ?? (string) $id, $facultesAvecProgrammes),
        'programmes_detail' => array_map(fn ($p) => [
            'code' => $p['code'], 'faculte' => $facultes[$p['faculte_id']] ?? $p['faculte_id'],
            'statut' => $p['statut'], 'nom' => $p['nom'],
        ], $programmes),
    ],
    'tables' => [],
];

/* Inscriptions */
$lignesInscriptions = DB::table('inscriptions_academiques as i')
    ->leftJoin('etudiants as e', 'e.id', '=', 'i.etudiant_id')
    ->leftJoin('annees_universitaires as a', 'a.id', '=', 'i.annee_universitaire_id')
    ->leftJoin('programmes as p', 'p.id', '=', 'i.programme_id')
    ->leftJoin('niveaux as n', 'n.id', '=', 'i.niveau_id')
    ->get([
        'i.id', 'i.filiere', 'i.programme_id', 'i.niveau_id', 'i.niveau as niveau_texte',
        'e.faculte_id', 'e.programme_id as etudiant_programme_id',
        'a.libelle as annee', 'p.code as programme_code', 'n.programme_id as niveau_programme_id',
    ]);

$compteur = $parFac = $parAnnee = [];

foreach ($lignesInscriptions as $l) {
    $facCode = $facultes[$l->faculte_id] ?? 'INCONNUE';
    $r = resoudre($l->filiere, $programmes, $facultesAvecProgrammes, $l->faculte_id);
    $cat = $r['categorie'];

    if ($l->niveau_id !== null && $l->programme_id !== null && (int) $l->niveau_programme_id !== (int) $l->programme_id) {
        $cat = 'INCOHERENCE_NIVEAU';
        $r['motif'] = 'niveau_id appartient à un autre programme';
        $r['candidats'] = [$l->programme_code];
    } elseif ($l->etudiant_programme_id !== null && $l->programme_id !== null && (int) $l->etudiant_programme_id !== (int) $l->programme_id) {
        $cat = 'DIVERGENCE_ETUDIANT';
        $r['motif'] = 'programme_id différent de celui de l’étudiant';
    }

    $compteur[$cat] = ($compteur[$cat] ?? 0) + 1;
    $parFac[$facCode][$cat] = ($parFac[$facCode][$cat] ?? 0) + 1;
    $parAnnee[$l->annee ?? 'INCONNUE'][$cat] = ($parAnnee[$l->annee ?? 'INCONNUE'][$cat] ?? 0) + 1;

    if (! in_array($cat, ['VIDE', 'EXACT_CODE', 'EXACT_NOM'], true)) {
        $anomalies[] = [
            'table' => 'inscriptions_academiques', 'id' => $l->id, 'faculte' => $facCode,
            'annee' => $l->annee, 'champ' => 'filiere', 'valeur' => (string) $l->filiere,
            'categorie' => $cat, 'candidats' => implode('|', $r['candidats']), 'motif' => $r['motif'],
        ];
    }
}

$stats['tables']['inscriptions_academiques'] = [
    'total_lignes' => $lignesInscriptions->count(),
    'valeurs_distinctes_non_nulles' => DB::table('inscriptions_academiques')->whereNotNull('filiere')->where('filiere', '!=', '')->distinct()->count('filiere'),
    'categories' => $compteur,
    'par_faculte' => $parFac,
    'par_annee' => $parAnnee,
    'programme_id_renseigne' => $lignesInscriptions->whereNotNull('programme_id')->count(),
    'niveau_id_renseigne' => $lignesInscriptions->whereNotNull('niveau_id')->count(),
];

/* Groupes */
$lignesGroupes = DB::table('groups as g')
    ->leftJoin('annees_universitaires as a', 'a.id', '=', 'g.annee_universitaire_id')
    ->leftJoin('programmes as p', 'p.id', '=', 'g.programme_id')
    ->leftJoin('niveaux as n', 'n.id', '=', 'g.niveau_id')
    ->get(['g.id', 'g.filiere', 'g.programme_id', 'g.niveau_id', 'g.faculte_id', 'a.libelle as annee', 'p.code as programme_code', 'n.programme_id as niveau_programme_id']);

$compteurG = $parFacG = [];

foreach ($lignesGroupes as $l) {
    $facCode = $facultes[$l->faculte_id] ?? 'INCONNUE';
    $r = resoudre($l->filiere, $programmes, $facultesAvecProgrammes, $l->faculte_id);
    $cat = $r['categorie'];

    if ($l->niveau_id !== null && $l->programme_id !== null && (int) $l->niveau_programme_id !== (int) $l->programme_id) {
        $cat = 'INCOHERENCE_NIVEAU';
        $r['motif'] = 'niveau_id appartient à un autre programme';
    }

    $compteurG[$cat] = ($compteurG[$cat] ?? 0) + 1;
    $parFacG[$facCode][$cat] = ($parFacG[$facCode][$cat] ?? 0) + 1;

    if (! in_array($cat, ['VIDE', 'EXACT_CODE', 'EXACT_NOM'], true)) {
        $anomalies[] = [
            'table' => 'groups', 'id' => $l->id, 'faculte' => $facCode, 'annee' => $l->annee,
            'champ' => 'filiere', 'valeur' => (string) $l->filiere,
            'categorie' => $cat, 'candidats' => implode('|', $r['candidats']), 'motif' => $r['motif'],
        ];
    }
}

$stats['tables']['groups'] = [
    'total_lignes' => $lignesGroupes->count(),
    'valeurs_distinctes_non_nulles' => DB::table('groups')->whereNotNull('filiere')->where('filiere', '!=', '')->distinct()->count('filiere'),
    'categories' => $compteurG,
    'par_faculte' => $parFacG,
    'programme_id_renseigne' => $lignesGroupes->whereNotNull('programme_id')->count(),
    'niveau_id_renseigne' => $lignesGroupes->whereNotNull('niveau_id')->count(),
];

/* Matières */
$lignesMatieres = DB::table('matieres as m')
    ->leftJoin('unites_enseignement as ue', 'ue.id', '=', 'm.ue_id')
    ->get(['m.id', 'm.filiere', 'm.ue_id', 'ue.programme_id as programme_id']);

$compteurM = [];
foreach ($lignesMatieres as $l) {
    $faculteId = null;
    if ($l->programme_id !== null) {
        $fac = DB::table('programmes')->where('id', $l->programme_id)->value('faculte_id');
        $faculteId = $fac === null ? null : (int) $fac;
    }
    $r = resoudre($l->filiere, $programmes, $facultesAvecProgrammes, $faculteId);
    $compteurM[$r['categorie']] = ($compteurM[$r['categorie']] ?? 0) + 1;
}

$stats['tables']['matieres'] = [
    'total_lignes' => $lignesMatieres->count(),
    'valeurs_distinctes_non_nulles' => 0,
    'categories' => $compteurM,
    'ue_id_renseigne' => $lignesMatieres->whereNotNull('ue_id')->count(),
    'programme_via_ue' => $lignesMatieres->whereNotNull('programme_id')->count(),
];

File::put($racine.'/04-STATISTIQUES.json', json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$fh = fopen($racine.'/03-ANOMALIES.csv', 'w');
fputcsv($fh, ['table', 'id', 'faculte', 'annee', 'champ', 'valeur', 'categorie', 'candidats', 'motif'], ';', '"', '\\');
foreach ($anomalies as $a) {
    fputcsv($fh, [$a['table'], $a['id'], $a['faculte'], $a['annee'], $a['champ'], $a['valeur'], $a['categorie'], $a['candidats'], $a['motif']], ';', '"', '\\');
}
fclose($fh);

echo "JSON : docs/audit-referentiel/04-STATISTIQUES.json\n";
echo 'CSV  : docs/audit-referentiel/03-ANOMALIES.csv ('.count($anomalies)." lignes)\n";

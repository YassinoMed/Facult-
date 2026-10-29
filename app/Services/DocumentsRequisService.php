<?php

namespace App\Services;

use App\Models\Etudiant;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Pièces obligatoires d'inscription (mission documents) : matrice par
 * type (`config/inscriptions.php`, vide = rien imposé → validation
 * autorisée), états GED réels (upload ≠ validé), réutilisation des
 * pièces valides (jamais redemandées).
 *
 * États par type : VALIDE | MANQUANT | EN_ATTENTE | REJETE | EXPIRE.
 */
final class DocumentsRequisService
{
    /** Catalogue officiel des pièces justificatives avec métadonnées techniques. */
    public const CATALOGUE_PIECES = [
        'cin' => [
            'code' => 'cin',
            'libelle' => 'Carte d’Identité Nationale ou Passeport',
            'description' => 'Copie recto-verso de la CIN tunisienne ou page d’identification du passeport en cours de validité.',
            'formats' => ['pdf', 'jpg', 'jpeg', 'png'],
            'taille_max_mo' => 10,
            'category' => 'identity',
            'consignes' => 'Document officiel original ou copie certifiée, parfaitement lisible.',
        ],
        'photo_identite' => [
            'code' => 'photo_identite',
            'libelle' => 'Photo d’identité récente',
            'description' => 'Photo couleur sur fond uni clair, de face, tête nue, format officiel 35×45 mm.',
            'formats' => ['jpg', 'jpeg', 'png'],
            'taille_max_mo' => 5,
            'category' => 'identity',
            'consignes' => 'Prise de vue récente, nette, sans filtre.',
        ],
        'releve_notes' => [
            'code' => 'releve_notes',
            'libelle' => 'Relevé de notes du Baccalauréat',
            'description' => 'Relevé officiel des notes de la session du baccalauréat avec mention et moyenne générale.',
            'formats' => ['pdf', 'jpg', 'jpeg', 'png'],
            'taille_max_mo' => 10,
            'category' => 'academic',
            'consignes' => 'Relevé officiel délivré par le ministère ou le rectorat.',
        ],
        'attestation_reussite' => [
            'code' => 'attestation_reussite',
            'libelle' => 'Attestation de réussite ou Diplôme',
            'description' => 'Certificat officiel attestant de l’obtention du diplôme de fin d’études secondaires.',
            'formats' => ['pdf', 'jpg', 'jpeg', 'png'],
            'taille_max_mo' => 10,
            'category' => 'academic',
            'consignes' => 'Document revêtu du cachet officiel de l’établissement.',
        ],
        'attestation_transfert' => [
            'code' => 'attestation_transfert',
            'libelle' => 'Attestation de transfert / Accord d’accueil',
            'description' => 'Autorisation de transfert inter-universités ou décision d’admission par passerelle.',
            'formats' => ['pdf', 'jpg', 'jpeg', 'png'],
            'taille_max_mo' => 10,
            'category' => 'administrative',
            'consignes' => 'Document officiel délivré par l’université d’origine et signé par le doyen.',
        ],
        'releves_anterieurs' => [
            'code' => 'releves_anterieurs',
            'libelle' => 'Relevés de notes universitaires antérieurs',
            'description' => 'Tous les relevés de notes des années universitaires déjà validées (L1, L2, etc.).',
            'formats' => ['pdf', 'jpg', 'jpeg', 'png'],
            'taille_max_mo' => 10,
            'category' => 'academic',
            'consignes' => 'Ensemble complet des semestres d’études supérieures déjà suivis.',
        ],
        'quitus_scolarite' => [
            'code' => 'quitus_scolarite',
            'libelle' => 'Quitus de scolarité / Réinscription',
            'description' => 'Attestation de situation régulière délivrée par le service de scolarité ou bibliothèque.',
            'formats' => ['pdf', 'jpg', 'jpeg', 'png'],
            'taille_max_mo' => 10,
            'category' => 'registration',
            'consignes' => 'Quitus officiel validant l’absence de dettes documentaires ou administratives.',
        ],
        'acte_naissance' => [
            'code' => 'acte_naissance',
            'libelle' => 'Extrait d’acte de naissance',
            'description' => 'Extrait récent d’état civil (facultatif si la CIN est fournie).',
            'formats' => ['pdf', 'jpg', 'jpeg', 'png'],
            'taille_max_mo' => 10,
            'category' => 'identity',
            'consignes' => 'Extrait d’état civil datant de moins de 3 mois.',
        ],
    ];

    /** Matrice par défaut des pièces selon le parcours. */
    public const MATRICE_DEFAUT = [
        'premiere' => [
            'obligatoires' => ['cin', 'releve_notes', 'photo_identite'],
            'facultatives' => ['acte_naissance'],
        ],
        'reinscription' => [
            'obligatoires' => ['cin', 'quitus_scolarite'],
            'facultatives' => ['photo_identite'],
        ],
        'transfert' => [
            'obligatoires' => ['cin', 'attestation_transfert', 'releves_anterieurs', 'photo_identite'],
            'facultatives' => ['acte_naissance'],
        ],
    ];

    /**
     * Retourne le catalogue des pièces.
     *
     * @return array<string, array<string, mixed>>
     */
    public function catalogue(): array
    {
        return self::CATALOGUE_PIECES;
    }

    /**
     * Retourne la checklist des pièces attendues pour une catégorie de parcours.
     *
     * @return array<int, array{code: string, libelle: string, description: string, obligatoire: bool, formats: array<int, string>, taille_max_mo: int, consignes: string}>
     */
    public function checklistPourType(?string $type): array
    {
        $typeNormalise = in_array((string) $type, ['premiere', 'reinscription', 'transfert'], true)
            ? (string) $type
            : 'premiere';

        $configRequis = $this->requisPour($typeNormalise);
        $matriceType = self::MATRICE_DEFAUT[$typeNormalise] ?? self::MATRICE_DEFAUT['premiere'];

        if (config('inscriptions.documents_requis_desactives', false) === true) {
            $codesObligatoires = [];
        } else {
            $codesObligatoires = $configRequis !== [] ? $configRequis : ($matriceType['obligatoires'] ?? []);
        }

        $codesFacultatifs = $matriceType['facultatives'] ?? [];
        $codesFacultatifs = array_values(array_diff($codesFacultatifs, $codesObligatoires));

        $pieces = [];
        foreach ($codesObligatoires as $code) {
            $def = self::CATALOGUE_PIECES[$code] ?? [
                'code' => $code,
                'libelle' => ucfirst(str_replace('_', ' ', $code)),
                'description' => 'Document justificatif requis pour l’inscription.',
                'formats' => ['pdf', 'jpg', 'jpeg', 'png'],
                'taille_max_mo' => 10,
                'consignes' => 'Document officiel, net et lisible.',
            ];
            $pieces[] = [
                'code' => $code,
                'libelle' => $def['libelle'],
                'description' => $def['description'],
                'obligatoire' => true,
                'formats' => $def['formats'],
                'taille_max_mo' => $def['taille_max_mo'],
                'consignes' => $def['consignes'],
            ];
        }

        foreach ($codesFacultatifs as $code) {
            $def = self::CATALOGUE_PIECES[$code] ?? [
                'code' => $code,
                'libelle' => ucfirst(str_replace('_', ' ', $code)),
                'description' => 'Document complémentaire recommandé.',
                'formats' => ['pdf', 'jpg', 'jpeg', 'png'],
                'taille_max_mo' => 10,
                'consignes' => 'Document net et lisible.',
            ];
            $pieces[] = [
                'code' => $code,
                'libelle' => $def['libelle'],
                'description' => $def['description'],
                'obligatoire' => false,
                'formats' => $def['formats'],
                'taille_max_mo' => $def['taille_max_mo'],
                'consignes' => $def['consignes'],
            ];
        }

        return $pieces;
    }

    /**
     * Bilan détaillé d'un dossier étudiant avec statuts GED réels et calcul de complétude.
     *
     * @return array{type: string, complet: bool, total_pieces: int, obligatoires_total: int, obligatoires_valides: int, obligatoires_manquantes: array<int, string>, peut_soumettre: bool, progression: string, pieces: array<int, array<string, mixed>>}
     */
    public function checklistDossier(Etudiant $etudiant, ?string $type = null): array
    {
        $typeCible = $type ?? (string) ($etudiant->type_inscription ?? 'premiere');
        $attendues = $this->checklistPourType($typeCible);
        $piecesDossier = $this->piecesDossier($etudiant);

        $resultatPieces = [];
        $obligatoiresValides = 0;
        $obligatoiresTotal = 0;
        $obligatoiresManquantes = [];
        $bloquant = false;

        foreach ($attendues as $item) {
            $code = $item['code'];
            $obligatoire = $item['obligatoire'];
            if ($obligatoire) {
                $obligatoiresTotal++;
            }

            $piece = $piecesDossier->get($code);
            $statut = 'non_fourni';
            $statutLibelle = 'Non fourni';
            $motifRejet = null;
            $documentId = null;
            $nomFichier = null;

            if ($piece !== null) {
                $documentId = $piece->id;
                $nomFichier = $piece->original_filename;
                switch ($piece->status) {
                    case 'validated':
                        if ($piece->expires_at !== null && $piece->expires_at->isPast()) {
                            $statut = 'expire';
                            $statutLibelle = 'Expiré';
                            if ($obligatoire) {
                                $bloquant = true;
                                $obligatoiresManquantes[] = $code;
                            }
                        } else {
                            $statut = 'valide';
                            $statutLibelle = 'Validé';
                            if ($obligatoire) {
                                $obligatoiresValides++;
                            }
                        }
                        break;
                    case 'pending':
                        $statut = 'en_attente';
                        $statutLibelle = 'En attente de contrôle';
                        if ($obligatoire) {
                            $bloquant = true;
                            $obligatoiresManquantes[] = $code;
                        }
                        break;
                    case 'rejected':
                        $statut = 'rejete';
                        $statutLibelle = 'Refusé';
                        $motifRejet = $piece->rejected_reason;
                        if ($obligatoire) {
                            $bloquant = true;
                            $obligatoiresManquantes[] = $code;
                        }
                        break;
                    case 'expired':
                        $statut = 'expire';
                        $statutLibelle = 'Expiré';
                        if ($obligatoire) {
                            $bloquant = true;
                            $obligatoiresManquantes[] = $code;
                        }
                        break;
                    default:
                        $statut = 'non_fourni';
                        $statutLibelle = 'Non fourni';
                        if ($obligatoire) {
                            $bloquant = true;
                            $obligatoiresManquantes[] = $code;
                        }
                        break;
                }
            } else {
                if ($obligatoire) {
                    $bloquant = true;
                    $obligatoiresManquantes[] = $code;
                }
            }

            $resultatPieces[] = array_merge($item, [
                'statut' => $statut,
                'statut_libelle' => $statutLibelle,
                'motif_rejet' => $motifRejet,
                'document_id' => $documentId,
                'nom_fichier' => $nomFichier,
            ]);
        }

        $complet = ($obligatoiresTotal === 0 || (! $bloquant && $obligatoiresValides === $obligatoiresTotal));

        return [
            'type' => $typeCible,
            'complet' => $complet,
            'total_pieces' => count($attendues),
            'obligatoires_total' => $obligatoiresTotal,
            'obligatoires_valides' => $obligatoiresValides,
            'obligatoires_manquantes' => $obligatoiresManquantes,
            'peut_soumettre' => $complet,
            'progression' => "{$obligatoiresValides} / {$obligatoiresTotal} obligatoires validés",
            'pieces' => $resultatPieces,
        ];
    }

    /**
     * @return array<int, string> codes `document_type` exigés.
     */
    public function requisPour(?string $type): array
    {
        $matrice = config('inscriptions.documents_requis_par_type', []);

        if (is_array($matrice) && array_key_exists((string) $type, $matrice) && is_array($matrice[(string) $type]) && $matrice[(string) $type] !== []) {
            return array_values($matrice[(string) $type]);
        }

        return array_values(config('inscriptions.documents_requis', []));
    }

    /**
     * Dernière pièce par type dans le dossier (versions historisées :
     * seule la plus récente compte). Les versions ne sont chargées que
     * sur demande explicite (évite une requête sur les écrans qui n'en
     * ont pas besoin — budgets N+1).
     *
     * @return Collection<string, StudentDocument>
     */
    public function piecesDossier(Etudiant $etudiant, bool $avecVersions = false): Collection
    {
        $dossier = StudentDossier::where('etudiant_id', $etudiant->id)->first();

        if ($dossier === null) {
            return collect();
        }

        $requete = StudentDocument::query();

        if ($avecVersions) {
            $requete->with('versions');
        }

        return $requete
            ->where('student_dossier_id', $dossier->id)
            ->latest('id')
            ->get()
            ->unique('document_type')
            ->keyBy('document_type');
    }

    /**
     * @return array{complet: bool, requis: int, valides: int, manquants: array<int, string>, en_attente: array<int, string>, rejetes: array<int, string>, expires: array<int, string>, progression: string}
     */
    public function verifier(Etudiant $etudiant, ?string $type): array
    {
        $requis = $this->requisPour($type);
        $pieces = $this->piecesDossier($etudiant);

        $valides = 0;
        $manquants = [];
        $enAttente = [];
        $rejetes = [];
        $expires = [];

        foreach ($requis as $code) {
            $piece = $pieces->get($code);

            if ($piece === null) {
                $manquants[] = $code;

                continue;
            }

            match ($piece->status) {
                'validated' => $piece->expires_at !== null && $piece->expires_at->isPast()
                    ? $expires[] = $code
                    : $valides++,
                'pending' => $enAttente[] = $code,
                'rejected' => $rejetes[] = $code,
                'expired' => $expires[] = $code,
                default => $manquants[] = $code,
            };
        }

        $complet = $requis === [] || ($manquants === [] && $enAttente === [] && $rejetes === [] && $expires === []);

        return [
            'complet' => $complet,
            'requis' => count($requis),
            'valides' => $valides,
            'manquants' => $manquants,
            'en_attente' => $enAttente,
            'rejetes' => $rejetes,
            'expires' => $expires,
            'progression' => "{$valides} / ".count($requis).' validés',
        ];
    }

    /**
     * Garde de finalisation : inscription définitive interdite si une
     * pièce obligatoire manque ou n'est pas validée (sauf matrice vide).
     *
     * @throws DomainException
     */
    public function exigerPourValidation(Etudiant $etudiant, ?string $type): void
    {
        $bilan = $this->verifier($etudiant, $type);

        if ($bilan['complet']) {
            return;
        }

        $details = [];

        foreach (['manquants' => 'manquants', 'en_attente' => 'en attente de contrôle', 'rejetes' => 'rejetés', 'expires' => 'expirés'] as $cle => $libelle) {
            if ($bilan[$cle] !== []) {
                $details[] = implode(', ', $bilan[$cle])." ({$libelle})";
            }
        }

        throw new DomainException(
            'Inscription non finalisable : documents obligatoires incomplets (REGISTRATION_DOCUMENTS_INCOMPLETE) — '.implode(' ; ', $details).'.'
        );
    }
}

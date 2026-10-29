<?php

namespace App\Modules\Alumni\Services;

use App\Models\Faculte;
use App\Models\User;
use App\Modules\Alumni\Models\ConsentementAlumni;
use App\Modules\Alumni\Models\Diplome;
use App\Modules\Alumni\Models\ProfilAlumni;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\TypeDiplome;
use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Consentement alumni, annuaire et agrégats.
 *
 * Invariants :
 * - aucun profil n'existe sans consentement opt-in explicite ;
 * - l'annuaire ne contient que les profils consentants et visibles ;
 * - les coordonnées privées ne sont rendues qu'aux porteurs de
 *   `alumni.manage`, et seulement si l'alumni a choisi la visibilité
 *   `annuaire_contact` ;
 * - aucun dossier académique (notes, crédits, décisions) n'est exposé ;
 * - les agrégats ne publient jamais un effectif inférieur au seuil k,
 *   avec un minimum de deux compartiments publiés.
 */
final class ServiceAlumni
{
    /**
     * @return array{url: string, expire_le: string, duree_jours: int}
     */
    public function genererLien(Diplome $diplome, User $acteur): array
    {
        if (! $acteur->peutAccederFaculte((int) $diplome->faculte_id)) {
            throw new AuthorizationException('Diplôme hors du périmètre facultaire.');
        }

        $jours = max(1, (int) config('modules.alumni.lien_jours', 30));
        $expiration = now()->addDays($jours);

        return [
            'url' => URL::temporarySignedRoute(
                'alumni.consentement.show',
                $expiration,
                ['diplome' => $diplome->id],
            ),
            'expire_le' => $expiration->toIso8601String(),
            'duree_jours' => $jours,
        ];
    }

    public function profilPourDiplome(Diplome $diplome): ?ProfilAlumni
    {
        return ProfilAlumni::where('etudiant_id', $diplome->etudiant_id)->first();
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    public function consentir(Diplome $diplome, array $donnees): ProfilAlumni
    {
        if (! filter_var($donnees['consentement'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw new DomainException('Le consentement explicite est requis.');
        }

        $visibilite = (string) ($donnees['visibilite'] ?? ProfilAlumni::VISIBILITE_AGREGATS);

        if (! in_array($visibilite, ProfilAlumni::VISIBILITES, true)) {
            throw new DomainException('Visibilité alumni invalide.');
        }

        $nom = trim((string) ($donnees['nom_affichage'] ?? ''));

        if ($nom === '' || mb_strlen($nom) > 150) {
            throw new DomainException('Le nom affiché est requis (150 caractères maximum).');
        }

        $email = $this->nettoyer($donnees['email_contact'] ?? null);
        $telephone = $this->nettoyer($donnees['telephone_contact'] ?? null);

        if ($visibilite === ProfilAlumni::VISIBILITE_ANNUAIRE_CONTACT && $email === null && $telephone === null) {
            throw new DomainException('La visibilité avec contact exige au moins un e-mail ou un téléphone.');
        }

        $cle = trim((string) ($donnees['cle_idempotence'] ?? ''));

        if ($cle === '' || mb_strlen($cle) > 64) {
            throw new DomainException('Clé d’idempotence manquante ou invalide.');
        }

        $cleHashee = hash('sha256', $diplome->id.'|consentement|'.$cle);

        return DB::transaction(function () use ($diplome, $donnees, $visibilite, $nom, $email, $telephone, $cleHashee): ProfilAlumni {
            $profil = ProfilAlumni::where('etudiant_id', $diplome->etudiant_id)
                ->lockForUpdate()
                ->first();

            // Rejeu réseau : la même clé ne crée ni second profil ni second
            // journal. Les données déjà enregistrées sont retournées telles quelles.
            if ($profil !== null && ConsentementAlumni::where('cle_idempotence', $cleHashee)->exists()) {
                return $profil->fresh();
            }

            $attributs = [
                'diplome_id' => $diplome->id,
                'faculte_id' => $diplome->faculte_id,
                'programme_id' => $diplome->programme_id,
                'nom_affichage' => $nom,
                'email_contact' => $email,
                'telephone_contact' => $telephone,
                'poste_actuel' => $this->nettoyer($donnees['poste_actuel'] ?? null),
                'employeur' => $this->nettoyer($donnees['employeur'] ?? null),
                'secteur' => $this->nettoyer($donnees['secteur'] ?? null),
                'ville' => $this->nettoyer($donnees['ville'] ?? null),
                'pays' => $this->nettoyer($donnees['pays'] ?? null),
                'linkedin' => $this->nettoyer($donnees['linkedin'] ?? null),
                'visibilite' => $visibilite,
                'consentement_le' => now(),
                'consentement_version' => ProfilAlumni::VERSION_CONSENTEMENT,
                'retire_le' => null,
            ];

            $profil = $profil === null
                ? ProfilAlumni::create(['etudiant_id' => $diplome->etudiant_id] + $attributs)
                : tap($profil)->update($attributs);

            ConsentementAlumni::create([
                'profil_alumni_id' => $profil->id,
                'action' => 'opt_in',
                'version_texte' => ProfilAlumni::VERSION_CONSENTEMENT,
                'canal' => 'lien_signe',
                'cle_idempotence' => $cleHashee,
                'diplome_id' => $diplome->id,
                'survenu_le' => now(),
            ]);

            return $profil->fresh();
        }, 5);
    }

    public function retirer(ProfilAlumni $profil, string $cleIdempotence): ProfilAlumni
    {
        $cle = trim($cleIdempotence);

        if ($cle === '' || mb_strlen($cle) > 64) {
            throw new DomainException('Clé d’idempotence manquante ou invalide.');
        }

        $cleHashee = hash('sha256', $profil->id.'|retrait|'.$cle);

        return DB::transaction(function () use ($profil, $cleHashee): ProfilAlumni {
            $verrouille = ProfilAlumni::whereKey($profil->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->retire_le !== null) {
                return $verrouille;
            }

            if (ConsentementAlumni::where('cle_idempotence', $cleHashee)->exists()) {
                return $verrouille;
            }

            $verrouille->update([
                'retire_le' => now(),
                // Ceinture et bretelles : même une requête qui oublierait
                // `retire_le` ne peut plus exposer le profil dans l'annuaire.
                'visibilite' => ProfilAlumni::VISIBILITE_AGREGATS,
            ]);

            ConsentementAlumni::create([
                'profil_alumni_id' => $verrouille->id,
                'action' => 'opt_out',
                'version_texte' => ProfilAlumni::VERSION_CONSENTEMENT,
                'canal' => 'lien_signe',
                'cle_idempotence' => $cleHashee,
                'diplome_id' => $verrouille->diplome_id,
                'survenu_le' => now(),
            ]);

            return $verrouille->fresh();
        }, 5);
    }

    /**
     * @param  array<string, mixed>  $filtres
     * @return array<string, mixed>
     */
    public function annuaire(User $acteur, array $filtres = []): array
    {
        $faculteId = $acteur->faculteRestreinteId();

        $query = ProfilAlumni::query()
            ->consentis()
            ->portee($faculteId)
            ->with([
                'faculte:id,nom',
                'programme:id,nom',
                'diplome:id,date_delivrance,type_diplome_id',
                'diplome.typeDiplome:id,nom',
            ]);

        if ($faculteId === null && ! empty($filtres['faculte_id'])) {
            $query->where('faculte_id', (int) $filtres['faculte_id']);
        }

        if (! empty($filtres['programme_id'])) {
            $query->where('programme_id', (int) $filtres['programme_id']);
        }

        if (! empty($filtres['annee'])) {
            $annee = (int) $filtres['annee'];
            $query->whereHas('diplome', fn ($q) => $q->whereYear('date_delivrance', $annee));
        }

        if (! empty($filtres['secteur'])) {
            $query->where('secteur', $filtres['secteur']);
        }

        if (! empty($filtres['recherche'])) {
            $recherche = '%'.$filtres['recherche'].'%';
            $query->where(fn ($q) => $q
                ->where('nom_affichage', 'like', $recherche)
                ->orWhere('employeur', 'like', $recherche));
        }

        $avecContacts = $acteur->hasPermission('alumni.manage');

        return $query
            ->orderBy('nom_affichage')
            ->paginate(50)
            ->withQueryString()
            ->through(function (ProfilAlumni $profil) use ($avecContacts): array {
                $ligne = [
                    'nom_affichage' => $profil->nom_affichage,
                    'faculte' => $profil->faculte?->nom,
                    'programme' => $profil->programme?->nom,
                    'type_diplome' => $profil->diplome?->typeDiplome?->nom,
                    'annee_diplomation' => $profil->diplome?->date_delivrance?->format('Y'),
                    'poste_actuel' => $profil->poste_actuel,
                    'employeur' => $profil->employeur,
                    'secteur' => $profil->secteur,
                    'ville' => $profil->ville,
                    'pays' => $profil->pays,
                    'linkedin' => $profil->linkedin,
                ];

                // Les coordonnées privées ne sortent que si l'alumni a
                // explicitement choisi `annuaire_contact` ET si l'acteur
                // possède `alumni.manage`. Sinon les clés sont absentes.
                if ($avecContacts && $profil->partageSesContacts()) {
                    $ligne['email_contact'] = $profil->email_contact;
                    $ligne['telephone_contact'] = $profil->telephone_contact;
                }

                return $ligne;
            })
            ->toArray();
    }

    /**
     * Agrégats k-anonymes. Aucun identifiant, aucun nom, aucun numéro de
     * diplôme : uniquement des effectifs publiés au-dessus du seuil.
     *
     * @return array<string, mixed>
     */
    public function agregats(User $acteur): array
    {
        $seuil = max(2, (int) config('modules.alumni.seuil_k', 5));
        $faculteId = $acteur->faculteRestreinteId();

        $base = fn () => Diplome::query()->portee($faculteId);

        $nomsFacultes = Faculte::pluck('nom', 'id')->all();
        $nomsProgrammes = Programme::pluck('nom', 'id')->all();
        $nomsTypes = TypeDiplome::pluck('nom', 'id')->all();

        $parFaculte = $base()
            ->selectRaw('faculte_id, count(*) as total')
            ->groupBy('faculte_id')
            ->pluck('total', 'faculte_id')
            ->all();

        $parProgramme = $base()
            ->whereNotNull('programme_id')
            ->selectRaw('programme_id, count(*) as total')
            ->groupBy('programme_id')
            ->pluck('total', 'programme_id')
            ->all();

        $parType = $base()
            ->whereNotNull('type_diplome_id')
            ->selectRaw('type_diplome_id, count(*) as total')
            ->groupBy('type_diplome_id')
            ->pluck('total', 'type_diplome_id')
            ->all();

        // Regroupement en PHP : portable SQLite/MySQL, sans SQL spécifique.
        $parAnnee = $base()
            ->pluck('date_delivrance')
            ->filter()
            ->groupBy(fn ($date): string => Carbon::parse($date)->format('Y'))
            ->map(fn ($groupe): int => $groupe->count())
            ->all();

        $parSecteur = ProfilAlumni::query()
            ->consentisPourAgregats()
            ->portee($faculteId)
            ->whereNotNull('secteur')
            ->where('secteur', '!=', '')
            ->selectRaw('secteur, count(*) as total')
            ->groupBy('secteur')
            ->pluck('total', 'secteur')
            ->all();

        return [
            'seuil_k' => $seuil,
            'note' => 'Aucun effectif inférieur au seuil k n’est publié ; une dimension à plusieurs compartiments exige au moins deux compartiments publiables.',
            'dimensions' => [
                'par_faculte' => $this->ventiler($parFaculte, $seuil, fn ($cle) => $nomsFacultes[$cle] ?? 'Inconnue'),
                'par_programme' => $this->ventiler($parProgramme, $seuil, fn ($cle) => $nomsProgrammes[$cle] ?? 'Inconnu'),
                'par_annee' => $this->ventiler($parAnnee, $seuil, fn ($cle) => (string) $cle),
                'par_type_diplome' => $this->ventiler($parType, $seuil, fn ($cle) => $nomsTypes[$cle] ?? 'Inconnu'),
                'par_secteur' => $this->ventiler($parSecteur, $seuil, fn ($cle) => (string) $cle),
            ],
        ];
    }

    /**
     * @param  array<int|string, int|string>  $comptes
     * @return array{diffusable: bool, lignes: array<int, array{cle: string, libelle: string, total: int}>, total_publie: int, non_diffuses: int|null}
     */
    private function ventiler(array $comptes, int $seuil, callable $libelle): array
    {
        $comptes = array_map('intval', $comptes);
        arsort($comptes);

        $publies = array_filter($comptes, fn (int $total): bool => $total >= $seuil);

        // Cas 1 : aucun compartiment publiable.
        // Cas 2 : plusieurs compartiments existent mais un seul est publiable —
        // les effectifs cachés seraient devinables par soustraction.
        // Un compartiment UNIQUE et >= k représente toute la population du
        // périmètre : il est publiable sans réidentification.
        if ($publies === [] || (count($publies) < 2 && count($comptes) > 1)) {
            return [
                'diffusable' => false,
                'lignes' => [],
                'total_publie' => 0,
                'non_diffuses' => null,
            ];
        }

        $lignes = [];

        foreach ($publies as $cle => $total) {
            $lignes[] = [
                'cle' => (string) $cle,
                'libelle' => (string) $libelle($cle),
                'total' => $total,
            ];
        }

        $nonDiffuses = array_sum($comptes) - array_sum($publies);

        return [
            'diffusable' => true,
            'lignes' => $lignes,
            'total_publie' => array_sum($publies),
            'non_diffuses' => $nonDiffuses === 0
                ? 0
                : ($nonDiffuses >= $seuil ? $nonDiffuses : null),
        ];
    }

    private function nettoyer(mixed $valeur): ?string
    {
        if (! is_string($valeur)) {
            return null;
        }

        $valeur = trim($valeur);

        return $valeur === '' ? null : $valeur;
    }
}

<?php

namespace App\Services;

use App\Models\Etudiant;
use Illuminate\Support\Facades\DB;

/**
 * Recherche publique d'un étudiant par CIN pour le pré-remplissage du
 * formulaire d'inscription.
 *
 * Règles absolues :
 * - requête 100 % paramétrée (Eloquent, jamais de SQL concaténé) ;
 * - le DTO de pré-remplissage est une WHITELIST explicite : le modèle
 *   complet n'est JAMAIS retourné, le mot de passe et son hash ne
 *   quittent jamais la couche service ;
 * - le CIN est un identifiant métier (§12) : jamais utilisé comme
 *   mot de passe, token, clé API ou identifiant de session.
 */
class StudentLookupService
{
    /**
     * Recherche un étudiant par son CIN (normalisé en majuscules).
     * Requête paramétrée via Eloquent — aucune concaténation SQL.
     */
    public function findByCin(string $cin): ?Etudiant
    {
        return Etudiant::query()
            ->where('cin', mb_strtoupper(trim($cin)))
            ->first();
    }

    /**
     * Recherche pour le pré-remplissage PUBLIC (audit §P0-4) :
     * le CIN (identifiant semi-public) doit être accompagné de la
     * date de naissance EXACTE — second facteur de vérification.
     *
     * - requête 100 % paramétrée, comparaison de date en base ;
     * - un étudiant sans date de naissance connue n'est JAMAIS
     *   retourné (fail-closed : pas d'oracle, pas de contournement) ;
     * - une date incorrecte donne la même réponse qu'un CIN inconnu
     *   ({ exists: false }) : aucune énumération possible.
     */
    public function trouverPourPrefill(string $cin, string $dateNaissance): ?Etudiant
    {
        return Etudiant::query()
            ->where('cin', mb_strtoupper(trim($cin)))
            ->whereDate('date_naissance', $dateNaissance)
            ->first();
    }

    /**
     * DTO de pré-remplissage : UNIQUEMENT les champs utiles au
     * formulaire public — jamais password, hash, remember_token,
     * statut interne ou données inutiles (§2/§4/§5).
     *
     * @return array<string, mixed>
     */
    public function donneesPrefill(Etudiant $etudiant): array
    {
        return [
            'cin' => $etudiant->cin,
            'nom' => $etudiant->nom,
            'prenom' => $etudiant->prenom,
            'email' => $etudiant->email,
            'telephone' => $etudiant->telephone,
            'date_naissance' => $etudiant->date_naissance?->format('Y-m-d'),
            'sexe' => $etudiant->sexe,
            'adresse' => $etudiant->adresse,
            'ville' => $etudiant->ville,
            'code_postal' => $etudiant->code_postal,
            'faculte_id' => (string) $etudiant->faculte_id,
            'niveau' => $etudiant->niveau,
            'matricule' => $etudiant->dossierAcademique?->matricule,
        ];
    }

    /**
     * Vérifie l'existence d'un CIN sans retourner de données (usage interne).
     */
    public function cinExiste(string $cin): bool
    {
        return DB::table('etudiants')
            ->where('cin', mb_strtoupper(trim($cin)))
            ->exists();
    }
}

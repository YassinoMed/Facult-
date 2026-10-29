<?php

namespace App\Modules\AssistantIa\DTO;

use App\Models\Etudiant;
use App\Models\User;
use App\Modules\Schedule\Models\Teacher;

/**
 * DTO immuable représentant l'acteur authentifié qui interagit avec l'Assistant IA
 * (Administration/Back-office, Enseignant, ou Étudiant).
 */
class ActeurPortail
{
    public function __construct(
        public readonly string $portail, // 'admin', 'enseignant', 'etudiant'
        public readonly int $id,
        public readonly string $nom,
        public readonly string $email,
        public readonly string $role,
        public readonly ?int $faculteId = null,
        public readonly User|Teacher|Etudiant|null $modele = null
    ) {}

    public static function depuisAdmin(User $user): self
    {
        return new self(
            portail: 'admin',
            id: (int) $user->id,
            nom: (string) ($user->name ?? 'Administrateur'),
            email: (string) ($user->email ?? ''),
            role: (string) ($user->role ?? 'agent_scolarite'),
            faculteId: $user->faculteRestreinteId(),
            modele: $user
        );
    }

    public static function depuisEnseignant(Teacher $teacher): self
    {
        $nomComplet = trim(($teacher->prenom ?? '').' '.($teacher->nom ?? ''));
        if (empty($nomComplet)) {
            $nomComplet = 'Enseignant #'.$teacher->id;
        }

        return new self(
            portail: 'enseignant',
            id: (int) $teacher->id,
            nom: $nomComplet,
            email: (string) ($teacher->email ?? ''),
            role: 'enseignant',
            faculteId: $teacher->faculte_id ?? null,
            modele: $teacher
        );
    }

    public static function depuisEtudiant(Etudiant $etudiant): self
    {
        $nomComplet = trim(($etudiant->prenom ?? '').' '.($etudiant->nom ?? ''));
        if (empty($nomComplet)) {
            $nomComplet = 'Étudiant #'.$etudiant->id;
        }

        return new self(
            portail: 'etudiant',
            id: (int) $etudiant->id,
            nom: $nomComplet,
            email: (string) ($etudiant->email ?? ''),
            role: 'etudiant',
            faculteId: $etudiant->faculte_id ?? null,
            modele: $etudiant
        );
    }

    public function estAdmin(): bool
    {
        return $this->portail === 'admin';
    }

    public function estEnseignant(): bool
    {
        return $this->portail === 'enseignant';
    }

    public function estEtudiant(): bool
    {
        return $this->portail === 'etudiant';
    }

    /**
     * Vérifie si l'acteur est autorisé à ouvrir le popup UniRegistre AI.
     */
    public function peutAccederAssistantIa(): bool
    {
        if ($this->estAdmin()) {
            /** @var User $user */
            $user = $this->modele;

            return $user->hasPermission('assistant_ia.view') || $user->estSuperAdmin();
        }

        if ($this->estEnseignant()) {
            /** @var Teacher $teacher */
            $teacher = $this->modele;

            return (bool) ($teacher->actif ?? true);
        }

        if ($this->estEtudiant()) {
            /** @var Etudiant $etudiant */
            $etudiant = $this->modele;

            return in_array($etudiant->statut, ['valide', 'en_attente'], true);
        }

        return false;
    }

    /**
     * Vérifie si l'acteur peut utiliser le mode développeur (strictement réservé aux admins habilités).
     */
    public function peutModeDeveloppeur(): bool
    {
        if (! $this->estAdmin()) {
            return false;
        }

        /** @var User $user */
        $user = $this->modele;

        return $user->hasPermission('assistant_ia.mode_developpeur') || $user->estSuperAdmin();
    }

    /**
     * Vérifie si l'acteur peut solliciter un modèle distant/cloud.
     */
    public function peutFournisseurDistant(): bool
    {
        if (! $this->estAdmin()) {
            return false;
        }

        /** @var User $user */
        $user = $this->modele;

        return $user->hasPermission('assistant_ia.fournisseur_distant') || $user->estSuperAdmin();
    }

    /**
     * Produit une instance de User compatible avec OrchestrateurAgentIa et PasserelleFournisseurs.
     */
    public function versUtilisateurIa(): User
    {
        if ($this->modele instanceof User) {
            return $this->modele;
        }

        $adaptateur = new ActeurUserAdapter;
        $adaptateur->id = $this->id;
        $adaptateur->name = $this->nom;
        $adaptateur->email = $this->email;
        $adaptateur->role = $this->role;
        $adaptateur->faculte_id = $this->faculteId;
        $adaptateur->actif = true;

        if ($this->estEtudiant() || $this->estEnseignant()) {
            $adaptateur->definirPermissions([
                'assistant_ia.mode_metier',
                'assistant_ia.recherche_doc',
            ]);
        }

        return $adaptateur;
    }
}

/**
 * Adaptateur User sécurisé pour les acteurs sans compte back-office (Étudiants, Enseignants).
 */
class ActeurUserAdapter extends User
{
    /** @var array<string, bool> */
    protected array $permissionsExplicites = [];

    public function definirPermissions(array $permissions): void
    {
        $this->permissionsExplicites = array_fill_keys($permissions, true);
    }

    public function hasPermission(string $permission): bool
    {
        if (isset($this->permissionsExplicites[$permission])) {
            return $this->permissionsExplicites[$permission];
        }

        return parent::hasPermission($permission);
    }
}

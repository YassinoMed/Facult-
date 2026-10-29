<?php

namespace App\Http\Livewire\Admin\Etudiants;

use App\Http\Livewire\LivewireBase;
use App\Models\AnneesUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Niveau;

class EtudiantCreate extends LivewireBase
{
    public Etudiant $etudiant;

    public $facultes;

    public $anneesUniversitaires;

    public $niveaux;

    public $programmes;

    public $showPhoto = false;

    public $photoPath = null;

    public $estCreationReussie = false;

    public $erreursValidation = [];

    public $mode = 'creation'; // creation, preview

    public function mount()
    {
        $this->etudiant = new Etudiant;
        $this->facultes = Faculte::where('active', true)->get();
        $this->anneesUniversitaires = AnneesUniversitaire::where('active', true)->get();
        $this->niveaux = Niveau::where('actif', true)->get();
    }

    protected $rules = [
        'etudiant.nom' => 'required|string|max:100',
        'etudiant.prenom' => 'required|string|max:100',
        'etudiant.email' => 'required|email|max:191|unique:etudiants,email',
        'etudiant.cin' => 'required|string|max:30|unique:etudiants,cin',
        'etudiant.telephone' => 'nullable|string|max:30',
        'etudiant.date_naissance' => 'required|date|before:today',
        'etudiant.sexe' => 'required|in:M,F',
        'etudiant.faculte_id' => 'required|exists:facultes,id',
        'etudiant.annee_academique' => 'required|string|max:20',
        'etudiant.niveau' => 'nullable|string|max:20',
        'etudiant.groupe_id' => 'nullable|exists:groups,id',
        'etudiant programme_id' => 'nullable|exists:programmes,id',
        'etudiant.niveau_id' => 'nullable|exists:niveaux,id',
        'etudiant.adresse' => 'nullable|string',
        'etudiant.ville' => 'nullable|string|max:100',
        'etudiant.code_postal' => 'nullable|string|max:20',
        'etudiant.annee_bac' => 'nullable|string|max:10',
        'etudiant.mention_bac' => 'nullable|string|max:50',
    ];

    public function saveEtudiant()
    {
        $this->validate();

        try {
            // Générer num_inscription si pas déjà défini
            if (empty($this->etudiant->num_inscription)) {
                $annee = date('Y');
                $prefix = "INS-{$annee}-";
                $dernier = Etudiant::where('num_inscription', 'like', "{$prefix}%")
                    ->orderByDesc('num_inscription')
                    ->value('num_inscription');

                if ($dernier) {
                    $numero = (int) substr($dernier, strlen($prefix)) + 1;
                } else {
                    $numero = 1;
                }

                $this->etudiant->num_inscription = sprintf('%s%05d', $prefix, $numero);
            }

            // Hacher le mot de passe par défaut si pas fourni
            if (empty($this->etudiant->password)) {
                $this->etudiant->password = bcrypt('default123');
            }

            $this->etudiant->save();

            $this->estCreationReussie = true;
            $this->emit('creation:reussie', $this->etudiant->id);

            // Reset du formulaire
            $this->etudiant = new Etudiant;

        } catch (\Exception $e) {
            $this->erreursValidation[] = 'Erreur lors de la sauvegarde : '.$e->getMessage();
        }
    }

    public function resetForm()
    {
        $this->etudiant = new Etudiant;
        $this->erreursValidation = [];
        $this->estCreationReussie = false;
    }

    public function togglePhoto()
    {
        $this->showPhoto = ! $this->showPhoto;
    }
}

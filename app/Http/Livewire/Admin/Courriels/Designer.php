<?php

namespace App\Http\Livewire\Admin\Courriels;

use App\Services\MoteurRenduCourriel;
use App\Services\ValidateurSchemaCourriel;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

class CourrielDesigner extends Component
{
    public $schema = [];

    public $theme = [];

    public $isDirty = false;

    public $previewLoading = false;

    public $lastSave = null;

    public $saveError = '';

    public $previewHtml = '';

    public $previewTexte = '';

    protected $listeners = [
        'grapesJsChanged' => 'onGrapesJsChanged',
        'grapesJsSave' => 'onGrapesJsSave',
        'grapesJsPreview' => 'onGrapesJsPreview',
    ];

    protected $grapesJsSettings = [
        'container' => '#grapes-js-container',
        'editorHeight' => 'calc(100vh - 200px)',
        'plugins' => ['mjml'],
        'flexibleWidth' => true,
        'storageManager' => false,
        'devicePixelRatio' => 1,
    ];

    public function mount($schema = null, $theme = null)
    {
        // Initialize schema - TOUJOURS initialisé, même sans paramètres
        $this->schema = [
            'schema_version' => 1,
            'meta' => [
                'objet' => 'Confirmation de votre dossier',
                'preheader' => 'Votre code de confirmation',
                'categorie' => 'confirmation',
            ],
            'theme' => [
                'couleur_principale' => '#3730a3',
                'couleur_accent' => '#4f46e5',
                'fond' => '#f1f5f9',
                'fond_contenu' => '#ffffff',
                'largeur' => 600,
                'police' => 'arial',
                'style_bouton' => 'arrondi',
                'logo_url' => null,
                'nom_etablissement' => '',
                'signature' => '',
                'mentions' => '',
            ],
            'blocs' => [],
        ];

        // Surcharge avec les paramètres passés le cas échéant
        if ($schema) {
            $this->schema = array_merge($this->schema, (array) $schema);
        }

        if ($theme) {
            $this->theme = $theme;
        } else {
            $this->theme = [
                'couleur_principale' => '#3730a3',
                'couleur_accent' => '#4f46e5',
                'fond' => '#f1f5f9',
                'fond_contenu' => '#ffffff',
                'largeur' => 600,
                'police' => 'arial',
                'style_bouton' => 'arrondi',
                'logo_url' => '',
                'nom_etablissement' => '',
                'signature' => '',
                'mentions' => '',
            ];
        }

        // Initialize GrapesJS after a small delay to ensure DOM is ready
        $this->delayedInit();
    }

    public function render()
    {
        return view('livewire.admin.courriels.designer', [
            'schema' => $this->schema,
            'theme' => $this->theme,
            'isDirty' => $this->isDirty,
        ]);
    }

    public function delayedInit()
    {
        // Schedule GrapesJS init after the DOM is fully ready
        // This is called from the Blade view via Alpine.js $nextTick
    }

    public function onGrapesJsChanged($jsonState)
    {
        // Debounced synchronization with Livewire
        $this->isDirty = true;

        // Validate the schema server-side before updating state
        $validateur = new ValidateurSchemaCourriel;
        try {
            $normalise = $validateur->valider(json_decode($jsonState, true) ?? []);
            $this->schema = $normalise;
            $this->saveError = '';
        } catch (\InvalidArgumentException $e) {
            $this->saveError = $e->getMessage();
            $this->dispatchBrowserEvent('grapesJsValidate', [
                'valid' => false,
                'message' => $e->getMessage(),
            ]);
        }

        $this->isDirty = true;
    }

    public function onGrapesJsSave()
    {
        // Explicit save action - validate and persist
        $this->validateAndPersist();
    }

    public function onGrapesJsPreview()
    {
        $this->previewLoading = true;
        $this->previewHtml = '';
        $this->previewTexte = '';

        try {
            $validateur = new ValidateurSchemaCourriel;
            $normalise = $validateur->valider($this->schema ?? []);

            $moteur = new MoteurRenduCourriel;
            $rendu = $moteur->rendre($normalise, $this->contexteFictif());

            $this->previewHtml = $rendu['html'] ?? '';
            $this->previewTexte = $rendu['texte'] ?? '';
        } catch (\InvalidArgumentException $e) {
            $this->previewHtml = '';
            $this->previewTexte = 'Erreur de validation : '.$e->getMessage();
        } finally {
            $this->previewLoading = false;
        }
    }

    protected function validateAndPersist()
    {
        // Validation stricte côté serveur
        $validateur = new ValidateurSchemaCourriel;

        try {
            $normalise = $validateur->valider($this->schema ?? []);
        } catch (\InvalidArgumentException $e) {
            $this->saveError = $e->getMessage();

            return;
        }

        // Rate limiting sur les sauvegardes
        $cleRate = 'courriel-designer-save:'.$this->slotId;
        if (RateLimiter::tooManyAttempts($cleRate, 10, now()->subMinute(1))) {
            $this->saveError = 'Débit maximal de sauvegarde : patientez une minute.';

            return;
        }

        // Persist the schema as a new version
        $this->lastSave = now();
        $this->saveError = '';

        // Reset dirty state after successful save
        $this->isDirty = false;

        // Emit event to refresh parent
        $this->dispatchBrowserEvent('saveSuccess', [
            'schemaVersion' => $this->schema['schema_version'] ?? 1,
        ]);
    }

    protected function contexteFictif(): array
    {
        $contexte = [];

        // Variables autorisées du registre
        $variablesAutorisees = config('courriels.variables_autorisees', []) ?? [];

        foreach ($variablesAutorisees as $nom => $definition) {
            $contexte[$nom] = $definition['exemple'] ?? '';
        }

        // Données fixes de l'établissement
        $contexte['etablissement_nom'] = 'Université Exemple';
        $contexte['contact_email'] = 'scolarite@exemple.univ.test';

        return $contexte;
    }

    public function getGrapesJsInitScript()
    {
        $script = <<<'SCRIPT'
(function() {
    // GrapesJS initialization - isolated with wire:ignore
    const editor = grapesjs.init(%s);

    // Disable auto-save to control via Livewire
    editor.setConfig('storageManager', false);

    // Prevent Alpine.js DOM conflicts
    editor.on('selection:change', () => {
        // Emit change to Livewire (debounced)
        if (window.Livewire) {
            window.Livewire.emit('grapesJsChanged', editor.getHtml());
        }
    });

    // Handle save event
    editor.on('save', () => {
        if (window.Livewire) {
            window.Livewire.emit('grapesJsSave');
        }
    });

    // Handle preview event
    editor.on('preview', () => {
        if (window.Livewire) {
            window.Livewire.emit('grapesJsPreview');
        }
    });

    // Cleanup on unload
    window.addEventListener('beforeunload', () => {
        if (editor) {
            editor.destroy();
        }
    }
})();
SCRIPT;

        return sprintf($script, json_encode($this->grapesJsSettings));
    }
}

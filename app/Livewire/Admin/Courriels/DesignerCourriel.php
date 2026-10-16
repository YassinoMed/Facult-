<?php

namespace App\Livewire\Admin\Courriels;

use App\Models\ModeleCourriel;
use App\Services\MoteurRenduCourriel;
use App\Services\StudioCourrielService;
use App\Services\ValidateurSchemaCourriel;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Designer visuel e-mail sur UNE page (LOOP-MAIL-LIVEWIRE-DESIGNER-04) :
 * sélection du modèle, métadonnées, objet, pré-en-tête, blocs, aperçu
 * responsive, sauvegarde, cycle — tout sur la même page (aucune navigation
 * pour éditer).
 *
 * Sécurité = serveur souverain : le canvas client n'est qu'une entrée ;
 * chaque sauvegarde traverse `ValidateurSchemaCourriel` + `StudioCourrielService`
 * (blocs autorisés, variables du registre, URL https/mailto, verrous,
 * conflit 409, versions immuables, journal). Aucun HTML client considéré
 * fiable ; l'aperçu consomme UNIQUEMENT le rendu serveur (iframe sandbox).
 * GrapesJS n'est PAS requis : le canvas Livewire/Alpine natif couvre la
 * composition et évite une dépendance lourde non justifiée par un besoin
 * démontré (décision STUDIO-04, rejet documenté).
 */
#[Layout('layouts.app')]
class DesignerCourriel extends Component
{
    use WithPagination;

    /** Modèle en cours d'édition (null = bibliothèque affichée sur la page). */
    public ?int $modeleId = null;

    public string $code = '';

    public string $nom = '';

    public string $categorie = 'confirmation';

    /** Schéma normalisé (blocs + thème + meta). */
    public array $schema = [
        'schema_version' => 1,
        'meta' => ['objet' => '', 'preheader' => '', 'categorie' => 'confirmation'],
        'theme' => [
            'couleur_principale' => '#3730a3', 'couleur_accent' => '#4f46e5',
            'fond' => '#f1f5f9', 'fond_contenu' => '#ffffff', 'largeur' => 600,
            'police' => 'arial', 'style_bouton' => 'arrondi',
            'logo_url' => null, 'nom_etablissement' => '',
            'signature' => '', 'mentions' => '',
        ],
        'blocs' => [],
    ];

    public int $baseVersion = 0;

    public ?int $selection = null;

    public bool $sale = false;

    public bool $sauvegardeEnCours = false;

    public string $message = '';

    public bool $messageOk = false;

    public string $erreurApercu = '';

    public string $apercuHtml = '';

    public string $apercuTexte = '';

    public string $largeurApercu = '100%';

    public string $ongletApercu = 'html';

    public string $recherche = '';

    public string $filtreStatut = '';

    #[Url]
    public string $onglet = 'compose';

    /** Piles undo/redo (photos JSON côté client uniquement). */
    public array $passe = [];

    public array $futur = [];

    public int $compteurCle = 1;

    public function mount(): void
    {
        if ($this->modeleId !== null) {
            $this->chargerModele($this->modeleId);
        }
    }

    /**
     * Bibliothèque paginée (mêmes filtres que le cockpit studio).
     */
    public function getModelesProperty(): LengthAwarePaginator
    {
        return app(StudioCourrielService::class)->bibliotheque(
            $this->recherche !== '' ? $this->recherche : null,
            $this->filtreStatut !== '' ? $this->filtreStatut : null,
            null
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getModeleProperty(): ?array
    {
        if ($this->modeleId === null) {
            return null;
        }

        $modele = ModeleCourriel::with('theme')->find($this->modeleId);

        return $modele === null ? null : $modele->only(['id', 'code', 'nom', 'statut', 'version_courante', 'verrou_systeme']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getVariablesProperty(): array
    {
        return (array) config('courriels.variables_autorisees', []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSquelettesProperty(): array
    {
        return array_values(StudioCourrielService::squelettes());
    }

    public function updatedRecherche(): void
    {
        $this->resetPage();
    }

    /**
     * Ouvre un modèle existant (adaptateur versions immuables → éditeur).
     */
    public function chargerModele(int $identifiant): void
    {
        $modele = ModeleCourriel::findOrFail($identifiant);

        if ($modele->verrou_systeme) {
            $this->message = 'Modèle système verrouillé : lecture seule.';
            $this->messageOk = false;

            return;
        }

        if (! $modele->estModifiable()) {
            $this->message = 'Modèle verrouillé ou archivé : consultation seule.';
            $this->messageOk = false;

            return;
        }

        $this->modeleId = $modele->id;
        $this->code = $modele->code;
        $this->nom = $modele->nom;
        $this->categorie = $modele->categorie;

        $derniere = $modele->derniereVersion();
        $this->baseVersion = $derniere?->numero ?? 0;
        $schema = (array) ($derniere?->schema ?? []);

        $this->schema = [
            'schema_version' => 1,
            'meta' => array_merge(['objet' => '', 'preheader' => '', 'categorie' => $modele->categorie], (array) ($schema['meta'] ?? [])),
            'theme' => array_merge($this->defautTheme(), (array) ($schema['theme'] ?? [])),
            'blocs' => array_values((array) ($schema['blocs'] ?? [])),
        ];

        $this->passe = [];
        $this->futur = [];
        $this->selection = null;
        $this->sale = false;
        $this->message = 'Modèle chargé (v'.($derniere?->numero ?? 0).').';
        $this->messageOk = true;
        $this->rafraichirApercu();
    }

    /**
     * Crée un brouillon puis bascule en édition sur la MÊME page.
     */
    public function creerBrouillon(): void
    {
        try {
            $modele = app(StudioCourrielService::class)->creer(
                $this->code,
                $this->nom,
                $this->categorie,
                $this->schema,
                auth()->id()
            );
        } catch (InvalidArgumentException $e) {
            $this->message = $e->getMessage();
            $this->messageOk = false;

            return;
        }

        $this->modeleId = $modele->id;
        $this->baseVersion = 1;
        $this->sale = false;
        $this->message = 'Brouillon créé (version 1).';
        $this->messageOk = true;
        $this->rafraichirApercu();
    }

    /**
     * Sauvegarde explicite : nouvelle version immuable, verrou optimiste.
     */
    public function sauvegarder(): void
    {
        if ($this->modeleId === null) {
            $this->creerBrouillon();

            return;
        }

        $cleDebit = 'studio-designer:'.(request()?->ip() ?? '').':'.auth()->id();
        if (RateLimiter::tooManyAttempts($cleDebit, 30)) {
            $this->message = 'Trop de sauvegardes : patientez une minute.';
            $this->messageOk = false;

            return;
        }

        $modele = ModeleCourriel::find($this->modeleId);
        if ($modele === null || ! $modele->estModifiable()) {
            $this->message = 'Modèle verrouillé ou archivé : sauvegarde impossible.';
            $this->messageOk = false;

            return;
        }

        try {
            $version = app(StudioCourrielService::class)->enregistrer(
                $modele,
                $this->schema,
                $this->baseVersion,
                auth()->id()
            );
        } catch (DomainException $e) {
            // Conflit 409 : modifications locales CONSERVÉES, comparaison possible.
            $this->message = $e->getMessage();
            $this->messageOk = false;

            return;
        } catch (InvalidArgumentException $e) {
            $this->message = $e->getMessage();
            $this->messageOk = false;

            return;
        }

        RateLimiter::hit($cleDebit);
        $this->baseVersion = $version->numero;
        $this->sale = false;
        $this->passe = [];
        $this->futur = [];
        $this->message = 'Version '.$version->numero.' enregistrée.';
        $this->messageOk = true;
    }

    public function soumettre(): void
    {
        if ($this->modeleId === null) {
            return;
        }

        $modele = ModeleCourriel::find($this->modeleId);
        if ($modele === null) {
            return;
        }

        try {
            app(StudioCourrielService::class)->soumettre($modele, auth()->id());
        } catch (DomainException|InvalidArgumentException $e) {
            $this->message = $e->getMessage();
            $this->messageOk = false;

            return;
        }

        $this->message = 'Modèle soumis à validation.';
        $this->messageOk = true;
    }

    public function publier(): void
    {
        if ($this->modeleId === null) {
            return;
        }

        $modele = ModeleCourriel::find($this->modeleId);
        if ($modele === null) {
            return;
        }

        try {
            app(StudioCourrielService::class)->publier($modele, auth()->id());
        } catch (DomainException|InvalidArgumentException $e) {
            $this->message = $e->getMessage();
            $this->messageOk = false;

            return;
        }

        $this->message = 'Modèle publié.';
        $this->messageOk = true;
        $this->rafraichirApercu();
    }

    public function restaurer(int $numero): void
    {
        if ($this->modeleId === null) {
            return;
        }

        $modele = ModeleCourriel::find($this->modeleId);
        if ($modele === null) {
            return;
        }

        try {
            $version = app(StudioCourrielService::class)->restaurer($modele, $numero, auth()->id());
        } catch (DomainException|InvalidArgumentException $e) {
            $this->message = $e->getMessage();
            $this->messageOk = false;

            return;
        }

        $this->message = 'Version '.$version->numero.' publiée (restauration de v'.$numero.').';
        $this->messageOk = true;
        $this->chargerModele($modele->id);
    }

    /* ── Édition de blocs (canvas, sans navigation) ─────────────── */

    /**
     * @param  array<string, mixed>  $bloc
     */
    public function ajouterBloc(array $bloc): void
    {
        if (count($this->schema['blocs']) >= 30) {
            $this->message = 'Plafond de 30 blocs atteint.';
            $this->messageOk = false;

            return;
        }

        $this->instantane();
        $this->schema['blocs'][] = $bloc;
        $this->selection = count($this->schema['blocs']) - 1;
        $this->marquerSale();
    }

    public function selectionner(int $index): void
    {
        if (isset($this->schema['blocs'][$index])) {
            $this->selection = $index;
        }
    }

    public function supprimerBloc(int $index): void
    {
        $bloc = $this->schema['blocs'][$index] ?? null;

        if ($bloc === null || ($bloc['verrouille'] ?? false)) {
            $this->message = 'Bloc verrouillé : suppression interdite.';
            $this->messageOk = false;

            return;
        }

        $this->instantane();
        unset($this->schema['blocs'][$index]);
        $this->schema['blocs'] = array_values($this->schema['blocs']);

        if ($this->selection !== null) {
            $this->selection = isset($this->schema['blocs'][$this->selection]) ? $this->selection : (count($this->schema['blocs']) ? count($this->schema['blocs']) - 1 : null);
        }
        $this->marquerSale();
    }

    public function dupliquerBloc(int $index): void
    {
        $bloc = $this->schema['blocs'][$index] ?? null;

        if ($bloc === null || count($this->schema['blocs']) >= 30) {
            return;
        }

        $this->instantane();
        $copie = $bloc;
        $copie['id'] = null;
        $copie['verrouille'] = false;
        array_splice($this->schema['blocs'], $index + 1, 0, [$copie]);
        $this->schema['blocs'] = array_values($this->schema['blocs']);
        $this->marquerSale();
    }

    public function monterBloc(int $index): void
    {
        if ($index <= 0 || ($this->schema['blocs'][$index]['verrouille'] ?? false)) {
            return;
        }

        $this->instantane();
        $echange = $this->schema['blocs'];
        [$echange[$index - 1], $echange[$index]] = [$echange[$index], $echange[$index - 1]];
        $this->schema['blocs'] = array_values($echange);
        $this->selection = $index - 1;
        $this->marquerSale();
    }

    public function descendreBloc(int $index): void
    {
        if ($index >= count($this->schema['blocs']) - 1 || ($this->schema['blocs'][$index]['verrouille'] ?? false)) {
            return;
        }

        $this->instantane();
        $echange = $this->schema['blocs'];
        [$echange[$index], $echange[$index + 1]] = [$echange[$index + 1], $echange[$index]];
        $this->schema['blocs'] = array_values($echange);
        $this->selection = $index + 1;
        $this->marquerSale();
    }

    public function deposerBloc(int $source, int $cible): void
    {
        if ($source === $cible || ($this->schema['blocs'][$source]['verrouille'] ?? false)) {
            return;
        }

        $this->instantane();
        $echange = $this->schema['blocs'];
        $deplace = array_splice($echange, $source, 1);
        array_splice($echange, $cible, 0, $deplace);
        $this->schema['blocs'] = array_values($echange);
        $this->selection = $cible;
        $this->marquerSale();
    }

    public function appliquerSquelette(int $index): void
    {
        $squelette = $this->squelettes[$index] ?? null;

        if ($squelette === null) {
            return;
        }

        $this->instantane();

        if (trim((string) $this->schema['meta']['objet']) === '' && isset($squelette['meta']['objet'])) {
            $this->schema['meta']['objet'] = (string) $squelette['meta']['objet'];
        }

        $disponible = 30 - count($this->schema['blocs']);
        foreach (array_slice((array) ($squelette['blocs'] ?? []), 0, max(0, $disponible)) as $bloc) {
            $this->schema['blocs'][] = $bloc;
        }

        $this->marquerSale();
    }

    public function ajouterElementListe(int $index): void
    {
        $bloc = $this->schema['blocs'][$index] ?? null;

        if ($bloc === null || $bloc['type'] !== 'liste' || ($bloc['verrouille'] ?? false)) {
            return;
        }
        if (count($bloc['elements']) >= 20) {
            return;
        }

        $this->schema['blocs'][$index]['elements'][] = '';
        $this->marquerSale();
    }

    public function retirerElementListe(int $index, int $position): void
    {
        $bloc = $this->schema['blocs'][$index] ?? null;

        if ($bloc === null || ($bloc['verrouille'] ?? false) || count($bloc['elements']) <= 1) {
            return;
        }

        $this->instantane();
        unset($this->schema['blocs'][$index]['elements'][$position]);
        $this->schema['blocs'][$index]['elements'] = array_values($this->schema['blocs'][$index]['elements']);
        $this->marquerSale();
    }

    public function ajouterLigneTableau(int $index): void
    {
        $bloc = $this->schema['blocs'][$index] ?? null;

        if ($bloc === null || $bloc['type'] !== 'tableau' || ($bloc['verrouille'] ?? false)) {
            return;
        }
        if (count($bloc['lignes']) >= 20) {
            return;
        }

        $this->schema['blocs'][$index]['lignes'][] = array_fill(0, count($bloc['entetes']), '');
        $this->marquerSale();
    }

    public function retirerLigneTableau(int $index, int $position): void
    {
        $bloc = $this->schema['blocs'][$index] ?? null;

        if ($bloc === null || ($bloc['verrouille'] ?? false)) {
            return;
        }

        $this->instantane();
        unset($this->schema['blocs'][$index]['lignes'][$position]);
        $this->schema['blocs'][$index]['lignes'] = array_values($this->schema['blocs'][$index]['lignes']);
        $this->marquerSale();
    }

    public function insererVariable(string $nom): void
    {
        // Opérée côté client (champ actif) : sans effet serveur ; la variable
        // reste revalidée par le validateur à la sauvegarde.
        $this->dispatch('inserer-variable', nom: $nom);
    }

    /* ── Undo / redo ────────────────────────────────────────────── */

    public function getPeutAnnulerProperty(): bool
    {
        return count($this->passe) > 0;
    }

    public function getPeutRetablirProperty(): bool
    {
        return count($this->futur) > 0;
    }

    public function instantane(): void
    {
        $this->passe[] = $this->schema;
        if (count($this->passe) > 30) {
            array_shift($this->passe);
        }
        $this->futur = [];
    }

    public function annuler(): void
    {
        if (! $this->peutAnnuler) {
            return;
        }

        $this->futur[] = $this->schema;
        $this->schema = array_pop($this->passe);
        $this->selection = null;
        $this->marquerSale(false);
    }

    public function retablir(): void
    {
        if (! $this->peutRetablir) {
            return;
        }

        $this->passe[] = $this->schema;
        $this->schema = array_pop($this->futur);
        $this->selection = null;
        $this->marquerSale(false);
    }

    /**
     * Aperçu sans effet : validation + rendu serveur (données fictives).
     */
    public function rafraichirApercu(): void
    {
        try {
            $normalise = app(ValidateurSchemaCourriel::class)->valider($this->schema);
            $rendu = app(MoteurRenduCourriel::class)->rendre($normalise, app(MoteurRenduCourriel::class)->contexteFictif());
        } catch (InvalidArgumentException|Throwable $e) {
            $this->erreurApercu = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Aperçu indisponible.';

            return;
        }

        $this->erreurApercu = '';
        $this->apercuHtml = $rendu['html'];
        $this->apercuTexte = $rendu['texte'];
    }

    /**
     * Méthode appelée par wire:model sur chaque champ : debounce aperçu,
     * AUCUNE requête réseau par frappe (aperçu = bouton ou action explicite).
     */
    public function marquerSale(bool $rafraichir = true): void
    {
        $this->sale = true;
        $this->erreurApercu = '';

        if ($rafraichir) {
            $this->rafraichirApercu();
        }
    }

    private function defautTheme(): array
    {
        return [
            'couleur_principale' => '#3730a3', 'couleur_accent' => '#4f46e5',
            'fond' => '#f1f5f9', 'fond_contenu' => '#ffffff', 'largeur' => 600,
            'police' => 'arial', 'style_bouton' => 'arrondi',
            'logo_url' => null, 'nom_etablissement' => '',
            'signature' => '', 'mentions' => '',
        ];
    }

    public function render(): View
    {
        return view('livewire.admin.courriels.designer', [
            'variables' => $this->variables,
            'squelettes' => $this->squelettes,
        ]);
    }
}

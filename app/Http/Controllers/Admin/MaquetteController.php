<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Models\Faculte;
use App\Models\IntegrationLog;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use App\Modules\StudentDossiers\Services\Pdf\OfficialDocumentService;
use App\Services\Documents\DocumentTemplateService;
use App\Services\Documents\TemplateTextExtractor;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Maquettes documentaires (LOT 3) : catalogue des modèles versionnés
 * (création, upload, versions immuables, publication, archivage,
 * duplication, aperçu sandbox). Contrôleur fin — cycle de vie dans
 * `DocumentTemplateService`, traçabilité dans `IntegrationLog`
 * (aucun étudiant concerné : `AuditService` exige un étudiant).
 */
class MaquetteController extends Controller
{
    public function __construct(
        private readonly DocumentTemplateService $maquettes,
        private readonly TemplateTextExtractor $extracteur,
        private readonly PdfGeneratorInterface $moteurPdf,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('document_template.view');

        $query = DocumentTemplate::query()->with('versions')->orderByDesc('id');
        $this->portee($request, $query);

        if ($request->filled('q')) {
            $motif = '%'.str_replace(['%', '_'], '', (string) $request->input('q')).'%';
            $query->where(fn ($q) => $q->where('nom', 'like', $motif)->orWhere('type', 'like', $motif));
        }

        if ($request->filled('statut')) {
            $query->where('statut', $request->string('statut'));
        }

        return view('admin.maquettes.index', [
            'maquettes' => $query->paginate(25)->withQueryString(),
            'types' => array_keys(OfficialDocumentService::catalogue()),
            'search' => (string) $request->input('q', ''),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('document_template.edit');

        return view('admin.maquettes.create', [
            'types' => OfficialDocumentService::catalogue(),
            'facultes' => Faculte::orderBy('code')->get(['id', 'code', 'nom']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('document_template.edit');

        $donnees = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', array_keys(OfficialDocumentService::catalogue()))],
            'nom' => ['required', 'string', 'max:150'],
            'locale' => ['nullable', 'in:fr,ar,bilingue'],
            'format' => ['nullable', 'in:A4'],
            'orientation' => ['nullable', 'in:portrait,paysage'],
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
            'valide_du' => ['nullable', 'date'],
            'valide_au' => ['nullable', 'date', 'after_or_equal:valide_du'],
            'contenu' => ['nullable', 'string', 'max:100000'],
            'fichier' => ['nullable', 'file', 'max:200', 'extensions:'.implode(',', TemplateTextExtractor::EXTENSIONS), 'mimetypes:'.implode(',', $this->mimesFichier())],
            'schema' => ['nullable', 'string', 'max:10000'],
        ]);

        try {
            $extraction = $this->lireContenu($donnees);
            $schema = $this->decoderSchema((string) ($donnees['schema'] ?? ''));

            $maquette = $this->maquettes->creer(
                $this->attributs($request, $donnees),
                $extraction['texte'], $schema, $request->user()->name,
            );
        } catch (DomainException $e) {
            return back()->withErrors(['contenu' => $e->getMessage(), 'fichier' => $e->getMessage()])->withInput();
        }

        IntegrationLog::tracer('documents-maquettes', 'gestion', 'modele.cree', 'completed', $extraction['texte']);

        return redirect()->route('admin.maquettes.show', $maquette)
            ->with('success', $extraction['transforme']
                ? 'Maquette créée (v1, brouillon — texte extrait, mise en forme non conservée).'
                : "Maquette « {$maquette->nom} » créée (v1, brouillon).");
    }

    public function show(Request $request, DocumentTemplate $template): View
    {
        Gate::authorize('document_template.view');
        $this->verifierPortee($request, $template);

        $template->loadMissing('versions');
        $courante = $template->versionCourante();

        return view('admin.maquettes.show', [
            'maquette' => $template,
            'courante' => $courante,
            'rendu' => $courante === null ? null : $this->maquettes->renduDirect($courante),
            'apercu' => $courante === null ? null : $this->maquettes->apercuSandbox(
                $courante, $this->maquettes->exempleVariables($courante->schema_variables)
            ),
        ]);
    }

    /**
     * Aperçu PDF en ligne : rendu direct + moteur PDF, sans écriture
     * (ni numérotation, ni stockage, ni registre).
     */
    public function apercuPdf(Request $request, DocumentTemplate $template): StreamedResponse
    {
        Gate::authorize('document_template.view');
        $this->verifierPortee($request, $template);

        $courante = $template->versionCourante();
        abort_if($courante === null, 404, 'Aucune version à visualiser.');

        $pdf = $this->moteurPdf->generer(
            $this->maquettes->renduDirect($courante),
            ['paper' => $template->format ?? 'A4', 'orientation' => $template->orientation ?? 'portrait']
        );

        $reponse = response()->stream(
            function () use ($pdf) {
                echo $pdf;
            },
            200,
            ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff']
        );
        $reponse->headers->set('Content-Disposition', "inline; filename=\"apercu-maquette-{$template->id}-v{$courante->version}.pdf\"");

        return $reponse;
    }

    public function edit(Request $request, DocumentTemplate $template): View
    {
        Gate::authorize('document_template.edit');
        $this->verifierPortee($request, $template);

        abort_if($template->statut !== 'brouillon', 422, 'Maquette publiée ou archivée : créez une nouvelle version ou dupliquez.');

        return view('admin.maquettes.edit', [
            'maquette' => $template,
            'facultes' => Faculte::orderBy('code')->get(['id', 'code', 'nom']),
        ]);
    }

    public function update(Request $request, DocumentTemplate $template): RedirectResponse
    {
        Gate::authorize('document_template.edit');
        $this->verifierPortee($request, $template);

        abort_if($template->statut !== 'brouillon', 422, 'Maquette publiée ou archivée : créez une nouvelle version ou dupliquez.');

        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:150'],
            'locale' => ['nullable', 'in:fr,ar,bilingue'],
            'format' => ['nullable', 'in:A4'],
            'orientation' => ['nullable', 'in:portrait,paysage'],
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
            'valide_du' => ['nullable', 'date'],
            'valide_au' => ['nullable', 'date', 'after_or_equal:valide_du'],
        ]);

        $template->update($this->attributs($request, $donnees, false));
        IntegrationLog::tracer('documents-maquettes', 'gestion', 'modele.modifie', 'completed');

        return redirect()->route('admin.maquettes.show', $template)->with('success', 'Maquette mise à jour.');
    }

    public function versionner(Request $request, DocumentTemplate $template): RedirectResponse
    {
        Gate::authorize('document_template.edit');
        $this->verifierPortee($request, $template);

        $donnees = $request->validate([
            'contenu' => ['nullable', 'string', 'max:100000'],
            'fichier' => ['nullable', 'file', 'max:200', 'extensions:'.implode(',', TemplateTextExtractor::EXTENSIONS), 'mimetypes:'.implode(',', $this->mimesFichier())],
            'schema' => ['nullable', 'string', 'max:10000'],
        ]);

        try {
            $extraction = $this->lireContenu($donnees);
            $version = $this->maquettes->nouvelleVersion(
                $template, $extraction['texte'], $this->decoderSchema((string) ($donnees['schema'] ?? '')), $request->user()->name
            );
        } catch (DomainException $e) {
            return back()->withErrors(['contenu' => $e->getMessage(), 'fichier' => $e->getMessage()])->withInput();
        }

        IntegrationLog::tracer('documents-maquettes', 'gestion', 'modele.version', 'completed', $extraction['texte']);

        return back()->with('success', $extraction['transforme']
            ? "Version v{$version->version} créée (texte extrait, mise en forme non conservée)."
            : "Version v{$version->version} créée (les versions publiées restent figées).");
    }

    public function publier(Request $request, DocumentTemplate $template): RedirectResponse
    {
        Gate::authorize('document_template.publish');
        $this->verifierPortee($request, $template);

        try {
            $this->maquettes->publier($template);
        } catch (DomainException $e) {
            return back()->withErrors(['maquette' => $e->getMessage()]);
        }

        IntegrationLog::tracer('documents-maquettes', 'gestion', 'modele.publie', 'completed');

        return back()->with('success', 'Maquette publiée (versions figées, génération possible).');
    }

    public function archiver(Request $request, DocumentTemplate $template): RedirectResponse
    {
        Gate::authorize('document_template.edit');
        $this->verifierPortee($request, $template);

        $this->maquettes->archiver($template);
        IntegrationLog::tracer('documents-maquettes', 'gestion', 'modele.archive', 'completed');

        return back()->with('success', 'Maquette archivée (consultable, non utilisable).');
    }

    public function dupliquer(Request $request, DocumentTemplate $template): RedirectResponse
    {
        Gate::authorize('document_template.edit');
        $this->verifierPortee($request, $template);

        $copie = $this->maquettes->dupliquer($template, $request->user()->name);
        IntegrationLog::tracer('documents-maquettes', 'gestion', 'modele.duplique', 'completed');

        return redirect()->route('admin.maquettes.show', $copie)->with('success', "Maquette dupliquée (« {$copie->nom} », brouillon).");
    }

    /**
     * Périmètre faculté : modèles globaux (faculte_id null) visibles
     * par tous ; modèles restreints réservés à leur faculté (404).
     */
    private function portee(Request $request, mixed $query): void
    {
        if ($request->user()->faculteRestreinteId() !== null) {
            $query->where(fn ($q) => $q->whereNull('faculte_id')
                ->orWhere('faculte_id', $request->user()->faculteRestreinteId()));
        }
    }

    private function verifierPortee(Request $request, DocumentTemplate $template): void
    {
        if ($template->faculte_id !== null && ! $request->user()->peutAccederFaculte((int) $template->faculte_id)) {
            abort(404);
        }
    }

    /**
     * @param  array{type?: string, nom?: string, locale?: ?string, format?: ?string, orientation?: ?string, faculte_id?: ?int, valide_du?: ?string, valide_au?: ?string}  $donnees
     * @return array{type?: string, nom?: string, locale?: ?string, format?: ?string, orientation?: ?string, faculte_id?: ?int, valide_du?: ?string, valide_au?: ?string}
     */
    private function attributs(Request $request, array $donnees, bool $creation = true): array
    {
        // Utilisateur restreint : rattachement forcé à sa faculté
        // (défense en profondeur, la Gate filtre déjà les rôles).
        $faculteId = $donnees['faculte_id'] ?? null;

        if ($request->user()->faculteRestreinteId() !== null) {
            $faculteId = $request->user()->faculteRestreinteId();
        }

        $attributs = [
            'locale' => $donnees['locale'] ?? 'fr',
            'format' => $donnees['format'] ?? 'A4',
            'orientation' => $donnees['orientation'] ?? 'portrait',
            'faculte_id' => $faculteId,
            'valide_du' => $donnees['valide_du'] ?? null,
            'valide_au' => $donnees['valide_au'] ?? null,
            'nom' => (string) $donnees['nom'],
        ];

        if ($creation) {
            $attributs['type'] = (string) $donnees['type'];
        }

        return $attributs;
    }

    /**
     * MIME réels acceptés (toutes extensions gérées par l'extracteur).
     *
     * @return array<int, string>
     */
    private function mimesFichier(): array
    {
        return array_values(array_unique(array_merge(...array_values(TemplateTextExtractor::MIMES_PAR_EXTENSION))));
    }

    /**
     * Contenu initial : fichier téléversé (extraction multi-formats)
     * prioritaire, sinon zone de texte. Exige l'un des deux.
     *
     * @return array{texte: string, transforme: bool}
     *
     * @throws DomainException
     */
    private function lireContenu(array $donnees): array
    {
        $fichier = $donnees['fichier'] ?? null;

        if ($fichier instanceof UploadedFile && $fichier->isValid()) {
            return $this->extracteur->extraire($fichier);
        }

        $texte = trim((string) ($donnees['contenu'] ?? ''));

        if ($texte === '') {
            throw new DomainException('Contenu initial requis : saisissez le modèle ou téléversez un fichier.');
        }

        return ['texte' => $texte, 'transforme' => false];
    }

    /**
     * Schéma JSON : objet {variable: description}, clés
     * alphanumériques pointées (jamais de code).
     *
     * @return array<string, string>|null
     */
    private function decoderSchema(string $json): ?array
    {
        if (trim($json) === '') {
            return null;
        }

        try {
            $schema = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DomainException('Schéma illisible : objet JSON attendu (ex. {"etudiant.nom": "Nom"}).');
        }

        if (! is_array($schema)) {
            throw new DomainException('Schéma illisible : objet JSON attendu.');
        }

        if (count($schema) > 100) {
            throw new DomainException('Schéma refusé : 100 variables maximum.');
        }

        foreach ($schema as $cle => $description) {
            if (! is_string($cle) || ! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.]*\z/', $cle) || mb_strlen($cle) > 60) {
                throw new DomainException("Schéma refusé : clé invalide « {$cle} ».");
            }

            if (! is_string($description) || mb_strlen($description) > 200) {
                throw new DomainException("Schéma refusé : description invalide pour « {$cle} ».");
            }
        }

        return $schema === [] ? null : $schema;
    }
}

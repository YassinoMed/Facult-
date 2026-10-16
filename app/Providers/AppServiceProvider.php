<?php

namespace App\Providers;

use App\Contracts\CanalNotificationExterneInterface;
use App\Listeners\JournaliserCourrielEnvoye;
use App\Models\Admission;
use App\Models\AnomalieQualite;
use App\Models\AppNotification;
use App\Models\CampagneCourriel;
use App\Models\DocumentAcademique;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\GroupeDestinataires;
use App\Models\InscriptionAcademique;
use App\Models\Note;
use App\Models\Parametre;
use App\Models\User;
use App\Policies\AdmissionPolicy;
use App\Policies\ArchivePolicy;
use App\Policies\CampagneCourrielPolicy;
use App\Policies\DocumentAcademiquePolicy;
use App\Policies\EtudiantPolicy;
use App\Policies\GroupeDestinatairesPolicy;
use App\Policies\InscriptionAcademiquePolicy;
use App\Policies\NotePolicy;
use App\Policies\ParametrePolicy;
use App\Policies\QualitePolicy;
use App\Services\CanalNotificationExterneSimule;
use App\Services\DonneesFormulaireInscription;
use App\Services\Identity\ArabicTransliterationService;
use App\Services\Identity\Engines\NullOcrEngine;
use App\Services\Identity\OcrEngineInterface;
use App\Services\Identity\OcrService;
use App\Services\Identity\OcrServiceInterface;
use App\Services\Identity\TransliterationServiceInterface;
use App\Services\NotificationService;
use App\Services\PreferencesService;
use App\Services\Recherche\Providers;
use App\Services\Recherche\SearchRegistry;
use App\Services\Verification\Rules;
use App\Services\Verification\VerificationEngine;
use App\Support\Actions\QuickActionRegistry;
use App\Support\Modules\ModuleRegistry;
use App\Support\Permissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Contrat OCR applicatif → implémentation par défaut (§4)
        $this->app->bind(
            OcrServiceInterface::class,
            OcrService::class,
        );

        // Canal externe de notifications simulé (LOOP 12)
        $this->app->bind(
            CanalNotificationExterneInterface::class,
            CanalNotificationExterneSimule::class
        );

        // Translittération arabe → latin (stratégie interchangeable §8)
        $this->app->bind(
            TransliterationServiceInterface::class,
            ArabicTransliterationService::class,
        );

        // Moteur OCR brut interchangeable : résolu depuis la configuration,
        // remplaçable (tesseract, cloud…) sans toucher au reste de l'app.
        $drivers = (array) config('identity.ocr.drivers', ['null' => NullOcrEngine::class]);
        $driver = (string) config('identity.ocr.driver', 'null');

        $this->app->bind(OcrEngineInterface::class, $drivers[$driver] ?? NullOcrEngine::class);

        // Registre d'actions rapides : singleton (enregistrements boot +
        // lectures vues/tests partagent la même instance).
        $this->app->singleton(QuickActionRegistry::class);

        // Moteur de vérification intelligente du dossier étudiant :
        // 11 règles injectées, fail-soft, lecture seule.
        $this->app->singleton(VerificationEngine::class, function ($app) {
            return new VerificationEngine([
                $app->make(Rules\IdentiteCompleteRule::class),
                $app->make(Rules\IdentiteCoherenceRule::class),
                $app->make(Rules\InscriptionCoherenceRule::class),
                $app->make(Rules\ParcoursLmdRule::class),
                $app->make(Rules\DocumentsRequiredRule::class),
                $app->make(Rules\PiecesStatutsRule::class),
                $app->make(Rules\DoublonsRule::class),
                $app->make(Rules\ChronologieRule::class),
                $app->make(Rules\AffectationGroupeRule::class),
                $app->make(Rules\DonneesOrphelinesRule::class),
                $app->make(Rules\ResultatsCoherenceRule::class),
                // R12 (LOT 1 — B5) : divergence MENS ↔ dossier local.
                $app->make(Rules\MensDivergenceRule::class),
            ]);
        });

        // Registre de recherche (Phase 7 §7.2) : providers singletons,
        // éligibilité (permission + module) évaluée à chaque requête.
        $this->app->singleton(SearchRegistry::class, function ($app) {
            $registre = new SearchRegistry($app->make(ModuleRegistry::class));
            $registre->enregistrer(new Providers\EtudiantsProvider);
            $registre->enregistrer(new Providers\EnseignantsProvider);
            $registre->enregistrer(new Providers\GroupesProvider);
            $registre->enregistrer(new Providers\MatieresProvider);
            $registre->enregistrer(new Providers\ProgrammesProvider($app->make(ModuleRegistry::class)));
            $registre->enregistrer(new Providers\UeProvider($app->make(ModuleRegistry::class)));
            $registre->enregistrer(new Providers\SallesProvider);
            $registre->enregistrer(new Providers\EmploisDuTempsProvider);
            $registre->enregistrer(new Providers\EnseignementsProvider($app->make(ModuleRegistry::class)));
            $registre->enregistrer(new Providers\EpreuvesProvider($app->make(ModuleRegistry::class)));
            $registre->enregistrer(new Providers\DemandesProvider($app->make(ModuleRegistry::class)));
            $registre->enregistrer(new Providers\UtilisateursProvider);
            $registre->enregistrer(new Providers\AnnoncesProvider($app->make(ModuleRegistry::class)));

            return $registre;
        });

        // `artisan dev` sous un PHP x86_64 (Homebrew/Rosetta) exécute Vite
        // en x86_64 : le binding natif de rolldown (vite 8) n'existe alors
        // qu'en arm64 dans node_modules → vite crashe au démarrage. On
        // force la tranche native via arch -arm64 (même logique que
        // identity.ocr.ppocr.arch / hfvlm.arch). PRIORITY_USERLAND écrase
        // le défaut « npm run dev » du framework.
        if (PHP_OS_FAMILY === 'Darwin' && php_uname('m') === 'x86_64' && file_exists('/usr/bin/arch')) {
            DevCommands::register('arch -arm64 npm run dev', 'vite');
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Commandes destructrices (migrate:fresh, migrate:refresh, db:wipe…)
        // interdites en production : un wipe accidentel a déjà vidé une base
        // le 16/10/2026. En local/test elles restent autorisées (récupération).
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // ── Règle CIN (§3) : ne pas trimmer silencieusement la CIN afin que tout espace la rende invalide ──
        TrimStrings::except(['cin']);

        // ── Politique de mots de passe (audit §P3-21) : 10 caractères
        // minimum, lettres + chiffres, et vérification de compromission
        // (haveibeenpwned) hors tests — aucun appel réseau en test.
        Password::defaults(fn () => app()->environment('testing')
            ? Password::min(10)->letters()->numbers()
            : Password::min(10)->letters()->numbers()->uncompromised());

        // ── RBAC : une Gate par permission, résolue via la matrice rôle → permissions ──
        foreach (Permissions::TOUTES as $permission) {
            Gate::define($permission, fn ($user) => $user->hasPermission($permission));
        }

        // ── Verrouillage secondaire (LOOP N, B2 ; LOOP P : ordre) : l'endpoint
        // Livewire `/livewire/update` traverse `secondaire.verrou` APRÈS le
        // groupe `web` — le verrou lit la session et l'utilisateur, donc la
        // session doit être démarrée avant lui (invités et non-éligibles
        // traversent, garde `user() === null`).
        if (class_exists(Livewire::class)) {
            Livewire::setUpdateRoute(fn ($gestionnaire) => Route::post('/livewire/update', $gestionnaire)
                ->middleware(['web', 'secondaire.verrou']));
        }

        // ── Journal passif des courriels (LOOP-MAIL-ENTERPRISE-01, LOOP 04) :
        // métadonnées expurgées uniquement, fail-soft (le journal ne doit
        // jamais empêcher l'envoi d'un code de sécurité).
        Event::listen(MessageSending::class, [JournaliserCourrielEnvoye::class, 'surEnvoi']);
        Event::listen(MessageSent::class, [JournaliserCourrielEnvoye::class, 'surEnvoye']);

        // ── Policies du module Archives Académiques ──
        Gate::policy(DossierAcademique::class, ArchivePolicy::class);

        // Densité des tableaux (Phase 7 §7.4) : classe globale lue une
        // fois par requête pour les utilisateurs connectés uniquement.
        View::composer('layouts.app', function ($vue) {
            $densite = 'confortable';

            if (auth()->check()) {
                $densite = app(PreferencesService::class)
                    ->lire((int) auth()->id())['densite'];
            }

            $vue->with('densiteTableaux', $densite);
        });
        Gate::policy(InscriptionAcademique::class, InscriptionAcademiquePolicy::class);
        Gate::policy(Note::class, NotePolicy::class);
        Gate::policy(DocumentAcademique::class, DocumentAcademiquePolicy::class);

        // Registre des étudiants (back-office) : isolation par faculté
        Gate::policy(Etudiant::class, EtudiantPolicy::class);

        // Admissions (roadmap « admission Master ») : le périmètre
        // (étudiant + programme cible) est vérifié au contrôleur.
        Gate::policy(Admission::class, AdmissionPolicy::class);

        // Paramètres centralisés (Phase 6) : settings.view/manage.
        Gate::policy(Parametre::class, ParametrePolicy::class);

        // Qualité des données (Phase 19) : qualite.view/manage.
        Gate::policy(AnomalieQualite::class, QualitePolicy::class);

        // Campagnes de courriels (LOOP-MAIL-CAMPAIGNS-01)
        Gate::policy(CampagneCourriel::class, CampagneCourrielPolicy::class);
        Gate::policy(GroupeDestinataires::class, GroupeDestinatairesPolicy::class);

        // ── Actions rapides contextuelles (Phase 7 §7.3) : navigation
        // uniquement (GET), permission + périmètre + route existante —
        // les actions futures restent masquées tant que la route manque.
        $actions = app(QuickActionRegistry::class);

        $actions->enregistrer([
            'key' => 'etudiant.archive',
            'label' => 'Voir l’archive',
            'icon' => '🗂️',
            'contextes' => ['etudiant.show'],
            'visible' => fn (?User $u, mixed $s) => $u !== null
                && $u->can('student_archive.view')
                && $u->peutAccederFaculte((int) $s->faculte_id),
            'url' => fn (?User $u, mixed $s) => Route::has('admin.etudiants.archive')
                ? route('admin.etudiants.archive', $s) : '',
        ]);

        $actions->enregistrer([
            'key' => 'etudiant.export',
            'label' => 'Exporter le dossier (JSON)',
            'icon' => '📥',
            'contextes' => ['etudiant.show'],
            'visible' => fn (?User $u, mixed $s) => $u !== null
                && $u->can('archives.view')
                && $u->peutAccederFaculte((int) $s->faculte_id),
            'url' => fn (?User $u, mixed $s) => Route::has('admin.etudiants.exportDossier')
                ? route('admin.etudiants.exportDossier', $s) : '',
        ]);

        $actions->enregistrer([
            'key' => 'groupe.etudiants',
            'label' => 'Voir les étudiants du groupe',
            'icon' => '👥',
            'contextes' => ['groupe.show'],
            'visible' => fn (?User $u, mixed $s) => $u !== null
                && $u->can('archives.view')
                && $u->peutAccederFaculte((int) $s->faculte_id),
            'url' => fn (?User $u, mixed $s) => Route::has('admin.etudiants.index')
                ? route('admin.etudiants.index', ['groupe_id' => $s->id]) : '',
        ]);

        // ── Rate limiting API (§49) — valeurs configurables (config/integrations.php) ──
        RateLimiter::for('api', function (Request $request) {
            $quota = (int) config('integrations.throttle.api', 60);

            return Limit::perMinute($quota)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('api-imports', function (Request $request) {
            $quota = (int) config('integrations.throttle.imports', 5);

            return Limit::perMinute($quota)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('api-exports', function (Request $request) {
            $quota = (int) config('integrations.throttle.exports', 10);

            return Limit::perMinute($quota)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('api-webhooks', function (Request $request) {
            $quota = (int) config('integrations.throttle.webhooks', 30);

            return Limit::perMinute($quota)->by($request->ip());
        });

        // ── Formulaire officiel partagé (`inscription._wizard`) : la page
        // canonique fournit déjà ces variables ; le parcours embarqué
        // (modal d'accueil) les reçoit ici — MÊME source, aucune divergence.
        // Seules les clés MANQUANTES sont complétées (jamais d'écrasement).
        View::composer('inscription._wizard', function ($vue) {
            $manquantes = array_diff(
                ['facultes', 'niveaux', 'anneesAcademiques', 'anneesBac', 'mentionsBac', 'etatInscription', 'checklistsDocuments', 'idempotencyToken'],
                array_keys($vue->getData())
            );

            if ($manquantes !== []) {
                $vue->with(array_intersect_key(
                    app(DonneesFormulaireInscription::class)->pourCreation(),
                    array_flip($manquantes)
                ));
            }
        });
        // ── Scan de documents d'identité (§19) : OCR coûteux — quota
        // dédié configurable, par IP, anti-uploads massifs. ──
        RateLimiter::for('identity.scan', function (Request $request) {
            return Limit::perMinute((int) config('identity.throttle.scan', 5))
                ->by($request->ip());
        });

        // ── Parcours public « dossier existant » : compartiments par
        // fonction (doctrine LOOP P) — mêmes budgets que les anciens
        // `throttle:X,1` inline, mais des seaux isolés par étape : le
        // parcours nominal complet (recherche → vérification → demande →
        // confirmation ancienne → confirmation nouvelle → soumission) ne
        // doit jamais se voir opposer un 429. Limitation par identité en
        // complément dans `DossierExistantService`. ──
        foreach ([
            'dossier-existant.recherche' => 5,
            'dossier-existant.verification' => 10,
            'dossier-existant.store' => 5,
            'dossier-existant.email' => 5,
            'dossier-existant.email-confirmer' => 10,
            'dossier-existant.donnees' => 30,
            'dossier-existant.autonome' => 5,
            'dossier-existant.autonome-confirmer' => 10,
        ] as $nom => $quota) {
            RateLimiter::for($nom, function (Request $request) use ($quota) {
                return Limit::perMinute($quota)->by($request->ip());
            });
        }

        // ── Cloche de notifications : compteur + 6 dernières, par espace.
        // Une seule requête bornée par page (index user/étudiant).
        // Fail-safe : une panne (ex. base injoignable) ne doit jamais
        // casser le layout — surtout pas les pages d'erreur.
        View::composer('layouts.app', function ($vue) {
            try {
                if (auth()->check()) {
                    $service = app(NotificationService::class);
                    $vue->with('notificationsNonLues', $service->nonLuesUser((int) auth()->id()));
                    $vue->with('notificationsRecentes', AppNotification::where('user_id', auth()->id())->latest()->limit(6)->get());
                    $vue->with('notificationsLien', route('admin.notifications.index'));
                } elseif (session()->has('etudiant_auth_id')) {
                    $id = (int) session('etudiant_auth_id');
                    $service = app(NotificationService::class);
                    $vue->with('notificationsNonLues', $service->nonLuesEtudiant($id));
                    $vue->with('notificationsRecentes', AppNotification::where('etudiant_id', $id)->latest()->limit(6)->get());
                    $vue->with('notificationsLien', route('etudiant.notifications.index'));
                } elseif (session()->has('enseignant_auth_id')) {
                    $id = (int) session('enseignant_auth_id');
                    $service = app(NotificationService::class);
                    $vue->with('notificationsNonLues', $service->nonLuesEnseignant($id));
                    $vue->with('notificationsRecentes', AppNotification::where('teacher_id', $id)->latest()->limit(6)->get());
                    $vue->with('notificationsLien', route('enseignant.notifications.index'));
                } else {
                    $vue->with('notificationsNonLues', 0);
                    $vue->with('notificationsRecentes', collect());
                    $vue->with('notificationsLien', null);
                }
            } catch (\Throwable) {
                $vue->with('notificationsNonLues', 0);
                $vue->with('notificationsRecentes', collect());
                $vue->with('notificationsLien', null);
            }
        });
    }
}

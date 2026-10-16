<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Parametre;
use App\Models\ParametreVersion;
use App\Services\ParametreService;
use App\Services\SettingDefinition;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Paramètres centralisés (§48) : lecture groupée par catégorie,
 * modification validée selon le type, chaque changement audité
 * (acteur, ancienne/nouvelle valeur — jamais de secret ici).
 */
class ParametreController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Parametre::class);

        $ordre = [
            'securite' => 1,
            'etablissement' => 2,
            'inscription' => 3,
            'academique' => 4,
            'sentinel' => 5,
            'sauvegardes' => 6,
            'presences' => 7,
            'notifications' => 8,
            'interface' => 9,
        ];

        $parametres = Parametre::with('auteur')
            ->orderBy('categorie')
            ->orderBy('cle')
            ->get()
            ->groupBy('categorie')
            ->sortBy(fn ($items, $key) => $ordre[$key] ?? 99);

        // Historique récent groupé (1 requête, pas de N+1) pour la section
        // versions de la vue (LOOP G).
        $historiques = ParametreVersion::with('acteur')
            ->whereIn('parametre_id', $parametres->flatten()->pluck('id'))
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->groupBy('parametre_id');

        $categoriesMeta = [
            'securite' => [
                'nom' => 'Sécurité & Verrouillage',
                'icone' => '🛡️',
                'description' => 'Verrouillage secondaire, code PIN, temporisation anti-brute-force et politiques d\'authentification.',
            ],
            'etablissement' => [
                'nom' => 'Établissement & Général',
                'icone' => '🏢',
                'description' => 'Identité officielle de l\'établissement et fuseau horaire de référence.',
            ],
            'inscription' => [
                'nom' => 'Inscriptions & Dépôts',
                'icone' => '📝',
                'description' => 'Ouverture du portail, pré-remplissage automatique par CIN et reconnaissance OCR.',
            ],
            'academique' => [
                'nom' => 'Scolarité & Académique',
                'icone' => '🎓',
                'description' => 'Seuil de validation de moyenne et alertes d\'assiduité des étudiants.',
            ],
            'sentinel' => [
                'nom' => 'Surveillance Sentinel',
                'icone' => '👁️',
                'description' => 'Surveillance de santé applicative, seuils des files d\'attente et cooldown des alertes.',
            ],
            'sauvegardes' => [
                'nom' => 'Sauvegardes & Rétention',
                'icone' => '💾',
                'description' => 'Politique de rétention des archives SQL et alertes de fraîcheur.',
            ],
            'presences' => [
                'nom' => 'Pointage & Émargement',
                'icone' => '⏱️',
                'description' => 'Tolérance de retard et fenêtres d\'appariement des présences enseignants.',
            ],
            'notifications' => [
                'nom' => 'Centre de Notifications',
                'icone' => '🔔',
                'description' => 'Durée de conservation et purge automatique des notifications lues.',
            ],
            'interface' => [
                'nom' => 'Interface & Ergonomie',
                'icone' => '🎨',
                'description' => 'Pagination par défaut et réglages d\'affichage des tableaux.',
            ],
        ];

        $securiteRaccourcis = [
            [
                'titre' => 'Code PIN de verrouillage',
                'description' => 'Définir ou modifier votre code secret de déverrouillage de session.',
                'route' => route('admin.verrouillage.code'),
                'icone' => '🔒',
                'libelle' => 'Gérer mon PIN',
            ],
            [
                'titre' => 'Double facteur (MFA / 2FA)',
                'description' => 'Protection renforcée par code TOTP Google Authenticator.',
                'route' => route('admin.mfa.configurer'),
                'icone' => '🛡️',
                'libelle' => 'Configurer 2FA',
            ],
            [
                'titre' => 'Sessions actives',
                'description' => 'Visualiser et révoquer les sessions connectées sur tous les appareils.',
                'route' => route('admin.sessions.index'),
                'icone' => '💻',
                'libelle' => 'Voir les sessions',
            ],
            [
                'titre' => 'Sonde Sentinel',
                'description' => 'Audit de sécurité, santé de base de données et intégrité plateforme.',
                'route' => Route::has('admin.sentinel.index') ? route('admin.sentinel.index') : '#',
                'icone' => '🚨',
                'libelle' => 'Ouvrir Sentinel',
            ],
            [
                'titre' => 'Sauvegardes BD',
                'description' => 'Créer, restaurer ou vérifier les sauvegardes de la base.',
                'route' => Route::has('admin.system.backups.index') ? route('admin.system.backups.index') : '#',
                'icone' => '💾',
                'libelle' => 'Sauvegardes',
            ],
            [
                'titre' => 'Serveur SMTP',
                'description' => 'Tester la délivrabilité et paramétrer le serveur de messagerie sortant.',
                'route' => Route::has('admin.smtp.index') ? route('admin.smtp.index') : '#',
                'icone' => '✉️',
                'libelle' => 'Gérer SMTP',
            ],
        ];

        return view('admin.parametres.index', [
            'groupes' => $parametres,
            'definitions' => SettingDefinition::catalogue(),
            'historiques' => $historiques,
            'categoriesMeta' => $categoriesMeta,
            'securiteRaccourcis' => $securiteRaccourcis,
            // Diagnostic non sensible (Phase B) : jamais de secret ici.
            'diagnostic' => [
                'environnement' => (string) config('app.env'),
                'langue' => (string) config('app.locale'),
                'fuseau_php' => date_default_timezone_get(),
                'debug' => (bool) config('app.debug'),
                // Messagerie (Phase C) : pilote + expéditeur + hôte si SMTP.
                // Mot de passe et secrets : jamais lus ni affichés.
                'courriel' => [
                    'pilote' => (string) config('mail.default'),
                    'expediteur' => (string) config('mail.from.address'),
                    'hote' => config('mail.default') === 'smtp'
                        ? (string) config('mail.mailers.smtp.host')
                        : null,
                ],
            ],
        ]);
    }

    public function update(Request $request, Parametre $parametre, ParametreService $service): RedirectResponse
    {
        Gate::authorize('update', $parametre);

        // Registre par clé (LOOP C) avec repli historique par type :
        // les règles métier priment, l'ancien comportement est conservé
        // pour toute clé hors registre (ex. sauvegarde.plan.*).
        $regles = SettingDefinition::reglesPour($parametre->cle) ?? match ($parametre->type) {
            'entier' => ['required', 'integer'],
            'booleen' => ['required', 'in:0,1,vrai,faux,true,false,oui,non'],
            default => ['nullable', 'string', 'max:2000'],
        };

        // Redirection explicite vers l'index (LOOP D) : `back()` sans
        // historique renvoyait à `/` et perdait les erreurs. Sac nommé
        // par paramètre : l'erreur ne s'affiche que sur son formulaire.
        // `attendu` obligatoire (E, point 2) : tout écrasement aveugle est
        // refusé avec l'état à recharger ; la restauration garde son
        // intention explicite (motif + nouvelle version).
        $sac = 'p'.$parametre->id;
        $validateur = Validator::make(
            $request->only(['valeur', 'attendu', 'motif']),
            array_merge(
                ['valeur' => $regles],
                ['attendu' => ['required', 'string', 'max:64'], 'motif' => ['nullable', 'string', 'max:500']]
            )
        );

        if ($validateur->fails()) {
            return redirect()->route('admin.parametres.index')
                ->withErrors($validateur, $sac)
                ->withInput();
        }
        $donnees = $validateur->validated();

        try {
            $service->set(
                $parametre->cle,
                $donnees['valeur'],
                (int) $request->user()->id,
                $donnees['attendu'] ?? null,
                $donnees['motif'] ?? null
            );
        } catch (DomainException $e) {
            return redirect()->route('admin.parametres.index')
                ->withErrors(['concurrence' => $e->getMessage()], $sac)
                ->withInput();
        }

        return back()->with('success', "Paramètre « {$parametre->cle} » mis à jour.");
    }

    public function restaurer(Request $request, Parametre $parametre, ParametreService $service): RedirectResponse
    {
        Gate::authorize('restore', $parametre);

        $versionId = (int) $request->validate(['version_id' => ['required', 'integer']])['version_id'];

        try {
            $service->restaurer($parametre, $versionId, (int) $request->user()->id);
        } catch (ModelNotFoundException $e) {
            return redirect()->route('admin.parametres.index')
                ->withErrors(['version' => 'Version introuvable pour ce paramètre.']);
        }

        return back()->with('success', "Paramètre « {$parametre->cle} » restauré (nouvelle version créée).");
    }
}

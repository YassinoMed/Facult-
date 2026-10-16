<?php

namespace App\Services;

use App\Mail\CourrielMessage;
use App\Models\Courriel;
use App\Models\JournalModele;
use App\Models\ModeleCourriel;
use App\Models\ThemeCourriel;
use App\Models\VersionModeleCourriel;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

/**
 * Studio de modèles e-mail (STUDIO-03) : cycle de vie explicite
 * BROUILLON → À_VALIDER → PUBLIÉ → ARCHIVÉ, versions immuables, verrou
 * système, retour arrière par republication, envoi de test autorisé
 * uniquement (allowlist, throttle, journal masqué). Les 7 modèles du
 * catalogue `courriels` restent verrouillés et intacts.
 */
class StudioCourrielService
{
    public function __construct(
        private readonly ValidateurSchemaCourriel $validateur,
        private readonly MoteurRenduCourriel $moteur,
        private readonly ControleQualiteCourriel $qualite,
    ) {}

    public function bibliotheque(?string $recherche, ?string $statut, ?string $categorie): LengthAwarePaginator
    {
        $requete = ModeleCourriel::query()->latest('id');

        if (is_string($statut) && in_array($statut, ModeleCourriel::STATUTS, true)) {
            $requete->where('statut', $statut);
        }

        if (is_string($categorie) && $categorie !== '') {
            $requete->where('categorie', mb_substr($categorie, 0, 40));
        }

        if (is_string($recherche) && trim($recherche) !== '') {
            $terme = '%'.mb_substr(trim($recherche), 0, 60).'%';
            $requete->where(fn ($q) => $q->where('nom', 'like', $terme)->orWhere('code', 'like', $terme));
        }

        return $requete->paginate(20);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public function creer(string $code, string $nom, string $categorie, array $schema, ?int $auteurId = null): ModeleCourriel
    {
        $code = mb_strtolower(trim($code));
        if (! preg_match('/\A[a-z0-9-]{3,60}\z/', $code)) {
            throw new InvalidArgumentException('Code invalide (3 à 60 caractères : minuscules, chiffres, tirets).');
        }
        if (ModeleCourriel::where('code', $code)->exists()) {
            throw new InvalidArgumentException('Code déjà utilisé.');
        }

        $nom = trim($nom);
        if ($nom === '' || mb_strlen($nom) > 150) {
            throw new InvalidArgumentException('Nom invalide.');
        }

        $normalise = $this->validateur->valider($schema);

        return DB::transaction(function () use ($code, $nom, $categorie, $normalise, $auteurId) {
            $modele = ModeleCourriel::create([
                'code' => $code,
                'nom' => $nom,
                'categorie' => mb_substr(trim($categorie) === '' ? 'administratif' : trim($categorie), 0, 40),
                'statut' => ModeleCourriel::STATUT_BROUILLON,
            ]);

            $modele->versions()->create([
                'numero' => 1,
                'schema' => $normalise,
                'auteur_id' => $auteurId,
            ]);

            $this->journaliser($modele, 'cree', 1, $auteurId);

            return $modele;
        });
    }

    /**
     * Sauvegarde explicite : nouvelle version immuable. Verrouillage
     * optimiste via `baseVersion` (409 en cas de conflit).
     *
     * @param  array<string, mixed>  $schema
     */
    public function enregistrer(ModeleCourriel $modele, array $schema, int $baseVersion, ?int $auteurId = null, ?string $note = null): VersionModeleCourriel
    {
        $this->garderModifiable($modele);

        $dernier = $modele->derniereVersion();
        if ($dernier === null || $dernier->numero !== $baseVersion) {
            throw new DomainException('Une version plus récente existe : rechargez avant d’enregistrer.');
        }

        $normalise = $this->validateur->valider($schema);
        $this->garderBlocsVerrouilles($modele, $normalise);

        return DB::transaction(function () use ($modele, $normalise, $dernier, $auteurId, $note) {
            $version = $modele->versions()->create([
                'numero' => $dernier->numero + 1,
                'schema' => $normalise,
                'auteur_id' => $auteurId,
                'note' => $note !== null ? mb_substr(trim($note), 0, 255) : null,
            ]);

            $this->journaliser($modele, 'modifie', $version->numero, $auteurId);

            return $version;
        });
    }

    public function soumettre(ModeleCourriel $modele, ?int $auteurId = null): void
    {
        $this->garderModifiable($modele);

        if ($modele->statut !== ModeleCourriel::STATUT_BROUILLON) {
            throw new DomainException('Seul un brouillon peut être soumis.');
        }

        $modele->update(['statut' => ModeleCourriel::STATUT_A_VALIDER]);
        $this->journaliser($modele, 'soumis', $modele->derniereVersion()?->numero, $auteurId);
    }

    /**
     * Publication atomique : revalidation du schéma courant puis bascule.
     */
    public function publier(ModeleCourriel $modele, ?int $auteurId = null): void
    {
        $this->garderModifiable($modele);

        if (! in_array($modele->statut, [ModeleCourriel::STATUT_BROUILLON, ModeleCourriel::STATUT_A_VALIDER], true)) {
            throw new DomainException('Statut incompatible avec la publication.');
        }

        DB::transaction(function () use ($modele, $auteurId) {
            $derniere = $modele->derniereVersion();
            if ($derniere === null) {
                throw new DomainException('Aucune version à publier.');
            }

            $normalise = $this->validateur->valider((array) $derniere->schema);
            $controle = $this->qualite->analyser($normalise);
            if ($controle['erreurs'] !== []) {
                throw new DomainException('Publication refusée : '.$controle['erreurs'][0]['message'].' '.$controle['erreurs'][0]['correction']);
            }

            $modele->update([
                'statut' => ModeleCourriel::STATUT_PUBLIE,
                'version_courante' => $derniere->numero,
            ]);
            $this->journaliser($modele, 'publie', $derniere->numero, $auteurId);
        });
    }

    public function archiver(ModeleCourriel $modele, ?int $auteurId = null): void
    {
        if ($modele->verrou_systeme) {
            throw new DomainException('Modèle système verrouillé.');
        }

        $modele->update(['statut' => ModeleCourriel::STATUT_ARCHIVE]);
        $this->journaliser($modele, 'archive', $modele->derniereVersion()?->numero, $auteurId);
    }

    /**
     * Retour arrière : republication d'une version antérieure SOUS UN
     * NOUVEAU numéro (historique préservé, jamais de réécriture).
     */
    public function restaurer(ModeleCourriel $modele, int $numero, ?int $auteurId = null): VersionModeleCourriel
    {
        $this->garderModifiable($modele);

        $source = $modele->versions()->where('numero', $numero)->first();
        if ($source === null) {
            throw new DomainException('Version source introuvable.');
        }

        $normalise = $this->validateur->valider((array) $source->schema);
        $dernier = $modele->derniereVersion();

        return DB::transaction(function () use ($modele, $normalise, $dernier, $numero, $auteurId) {
            $version = $modele->versions()->create([
                'numero' => ($dernier?->numero ?? 0) + 1,
                'schema' => $normalise,
                'auteur_id' => $auteurId,
                'note' => 'Restauration de la version '.$numero,
            ]);

            $modele->update([
                'statut' => ModeleCourriel::STATUT_PUBLIE,
                'version_courante' => $version->numero,
            ]);
            $this->journaliser($modele, 'restaure', $version->numero, $auteurId, ['depuis' => $numero]);

            return $version;
        });
    }

    public function dupliquer(ModeleCourriel $modele, ?int $auteurId = null): ModeleCourriel
    {
        if ($modele->verrou_systeme) {
            throw new DomainException('Modèle système verrouillé.');
        }

        $derniere = $modele->derniereVersion();
        if ($derniere === null) {
            throw new DomainException('Aucune version à dupliquer.');
        }

        $racine = mb_substr($modele->code, 0, 50).'-copie';
        $code = $racine;
        $compteur = 2;
        while (ModeleCourriel::where('code', $code)->exists()) {
            $code = $racine.'-'.$compteur++;
        }

        return $this->creer($code, $modele->nom.' (copie)', $modele->categorie, (array) $derniere->schema, $auteurId);
    }

    /**
     * Suppression définitive : UNIQUEMENT les brouillons jamais publiés.
     * Tout modèle publié s'archive (historique conservé).
     */
    public function supprimer(ModeleCourriel $modele): void
    {
        if ($modele->verrou_systeme || $modele->version_courante !== null || $modele->statut === ModeleCourriel::STATUT_PUBLIE) {
            throw new DomainException('Suppression interdite : archivez ce modèle.');
        }

        $modele->delete();
    }

    /**
     * Comparaison structurelle de deux versions (méta, thème, blocs).
     *
     * @return array<string, mixed>
     */
    public function comparer(VersionModeleCourriel $a, VersionModeleCourriel $b): array
    {
        $schemaA = (array) $a->schema;
        $schemaB = (array) $b->schema;

        $metaA = (array) ($schemaA['meta'] ?? []);
        $metaB = (array) ($schemaB['meta'] ?? []);
        $metaModifiees = [];
        foreach (array_unique(array_merge(array_keys($metaA), array_keys($metaB))) as $cle) {
            if (($metaA[$cle] ?? null) !== ($metaB[$cle] ?? null)) {
                $metaModifiees[] = $cle;
            }
        }

        $blocsA = array_values((array) ($schemaA['blocs'] ?? []));
        $blocsB = array_values((array) ($schemaB['blocs'] ?? []));
        $lignes = [];
        $max = max(count($blocsA), count($blocsB));
        for ($i = 0; $i < $max; $i++) {
            $existeA = array_key_exists($i, $blocsA);
            $existeB = array_key_exists($i, $blocsB);
            if (! $existeA) {
                $lignes[] = ['bloc' => $i + 1, 'changement' => 'ajouté', 'type' => $blocsB[$i]['type'] ?? '?'];
            } elseif (! $existeB) {
                $lignes[] = ['bloc' => $i + 1, 'changement' => 'supprimé', 'type' => $blocsA[$i]['type'] ?? '?'];
            } elseif ($blocsA[$i] !== $blocsB[$i]) {
                $lignes[] = ['bloc' => $i + 1, 'changement' => 'modifié', 'type' => $blocsB[$i]['type'] ?? '?'];
            }
        }

        return [
            'meta_modifiees' => $metaModifiees,
            'theme_modifie' => (($schemaA['theme'] ?? null) !== ($schemaB['theme'] ?? null)),
            'blocs' => $lignes,
        ];
    }

    /**
     * Aperçu sans effet (validation + rendu fictif).
     *
     * @param  array<string, mixed>  $schema
     * @return array{sujet: string, html: string, texte: string}
     */
    public function apercu(array $schema): array
    {
        return $this->moteur->rendre(
            $this->validateur->valider($schema),
            $this->moteur->contexteFictif()
        );
    }

    /**
     * Envoi de test : modèle personnalisé non archivé uniquement (jamais
     * de modèle système/OTP), destinataire allowlist, variables fictives.
     */
    public function envoyerTest(ModeleCourriel $modele, int $numero, string $destinataire, ?int $auteurId = null): Courriel
    {
        if ($modele->verrou_systeme || $modele->statut === ModeleCourriel::STATUT_ARCHIVE) {
            throw new DomainException('Envoi de test impossible pour ce modèle.');
        }

        $version = $modele->versions()->where('numero', $numero)->first();
        if ($version === null) {
            throw new DomainException('Version introuvable.');
        }

        $destinataire = mb_strtolower(trim($destinataire));
        if (! in_array($destinataire, $this->adressesTestAutorisees(), true)) {
            throw new DomainException('Destinataire de test non autorisé.');
        }

        $rendu = $this->moteur->rendre(
            $this->validateur->valider((array) $version->schema),
            $this->moteur->contexteFictif()
        );

        $courriel = Courriel::create([
            'cle_idempotence' => 'studio-test-'.$modele->id.'-'.$numero.'-'.hash('sha256', $destinataire.microtime(true)),
            'categorie' => 'test',
            'modele' => 'studio:'.$modele->code,
            'version_modele' => (string) $numero,
            'statut' => Courriel::STATUT_EN_COURS,
            'tentatives' => 1,
            'destinataire_masque' => Courriel::masquerDestinataire($destinataire),
            'destinataire_hash' => Courriel::condensatDestinataire($destinataire),
            'sujet' => mb_substr('[TEST] '.$rendu['sujet'], 0, 255),
            'acteur_admin_id' => $auteurId,
            'donnees' => ['reemissible' => false],
        ]);

        try {
            Mail::to($destinataire)->send(new CourrielMessage(
                $courriel->id,
                'studio:'.$modele->code,
                '[TEST] '.$rendu['sujet'],
                explode("\n", $rendu['texte'])
            ));
        } catch (Throwable $e) {
            $courriel->update([
                'statut' => Courriel::STATUT_ECHEC,
                'categorie_erreur' => app(CourrielService::class)->classifierErreur($e),
                'donnees' => ['reemissible' => false, 'diagnostic' => CourrielService::expurgerMessage($e)],
            ]);

            return $courriel;
        }

        $courriel->update(['statut' => Courriel::STATUT_ACCEPTE_SMTP, 'envoye_le' => now()]);
        $this->journaliser($modele, 'envoi_test', $numero, $auteurId, ['courriel_id' => $courriel->id]);

        return $courriel;
    }

    /**
     * @return list<string>
     */
    private function adressesTestAutorisees(): array
    {
        return array_values(array_filter(array_map(
            static fn ($adresse) => mb_strtolower(trim((string) $adresse)),
            (array) config('courriels.adresses_test_autorisees', [])
        )));
    }

    public function creerTheme(string $nom, array $config): ThemeCourriel
    {
        $nom = trim($nom);
        if ($nom === '' || mb_strlen($nom) > 100 || ThemeCourriel::where('nom', $nom)->exists()) {
            throw new InvalidArgumentException('Nom de thème invalide ou déjà utilisé.');
        }

        // Validation via un schéma minimal portant ce thème.
        $this->validateur->validerTheme($config);

        return ThemeCourriel::create(['nom' => $nom, 'config' => $config, 'actif' => true]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function modifierTheme(ThemeCourriel $theme, array $config): ThemeCourriel
    {
        $this->validateur->validerTheme($config);
        $theme->update(['config' => $config]);

        return $theme;
    }

    /**
     * Blocs verrouillés : présents et byte-identiques exigés dans le
     * nouveau schéma (comparaison par identifiant).
     *
     * @param  array<string, mixed>  $normalise
     *
     * @throws InvalidArgumentException
     */
    private function garderBlocsVerrouilles(ModeleCourriel $modele, array $normalise): void
    {
        $precedent = $modele->derniereVersion();
        if ($precedent === null) {
            return;
        }

        $verrouilles = [];
        foreach ((array) ((array) $precedent->schema)['blocs'] ?? [] as $bloc) {
            if (is_array($bloc) && ($bloc['verrouille'] ?? false) === true && isset($bloc['id'])) {
                $verrouilles[(string) $bloc['id']] = $bloc;
            }
        }

        if ($verrouilles === []) {
            return;
        }

        $nouveaux = [];
        foreach ((array) ($normalise['blocs'] ?? []) as $bloc) {
            if (is_array($bloc) && isset($bloc['id'])) {
                $nouveaux[(string) $bloc['id']] = $bloc;
            }
        }

        foreach ($verrouilles as $identifiant => $bloc) {
            if (! array_key_exists($identifiant, $nouveaux) || $nouveaux[$identifiant] != $bloc) {
                throw new InvalidArgumentException('Bloc verrouillé modifié ou supprimé : '.$identifiant.'.');
            }
        }
    }

    /**
     * Squelettes déterministes par catégorie (aide à la création, PAS une
     * IA : contenus fixes, validés comme tout schéma).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function squelettes(): array
    {
        $theme = [
            'couleur_principale' => '#3730a3', 'couleur_accent' => '#4f46e5',
            'fond' => '#f1f5f9', 'fond_contenu' => '#ffffff', 'largeur' => 600,
            'police' => 'arial', 'style_bouton' => 'arrondi',
            'logo_url' => null, 'nom_etablissement' => '',
            'signature' => '', 'mentions' => '',
        ];

        $base = fn (string $objet, array $blocs): array => [
            'schema_version' => 1,
            'meta' => ['objet' => $objet, 'preheader' => '', 'categorie' => 'confirmation'],
            'theme' => $theme,
            'blocs' => $blocs,
        ];

        return [
            'confirmation' => $base('Confirmation de votre dossier {{reference_dossier}}', [
                ['type' => 'titre', 'texte' => 'Bonjour {{destinataire_nom}}', 'niveau' => 'h2', 'alignement' => 'gauche', 'id' => null, 'verrouille' => false],
                ['type' => 'texte', 'contenu' => 'Votre demande a bien été enregistrée sous la référence {{reference_dossier}}.', 'alignement' => 'gauche', 'id' => null, 'verrouille' => false],
                ['type' => 'bouton', 'libelle' => 'Suivre mon dossier', 'url' => '{{url_action}}', 'alignement' => 'centre', 'id' => null, 'verrouille' => false],
            ]),
            'rappel' => $base('Rappel : {{echeance_intitule}} le {{echeance_date}}', [
                ['type' => 'titre', 'texte' => '{{echeance_intitule}}', 'niveau' => 'h2', 'alignement' => 'gauche', 'id' => null, 'verrouille' => false],
                ['type' => 'texte', 'contenu' => 'Nous vous rappelons l’échéance du {{echeance_date}}.', 'alignement' => 'gauche', 'id' => null, 'verrouille' => false],
                ['type' => 'bouton', 'libelle' => 'Voir le détail', 'url' => '{{url_action}}', 'alignement' => 'centre', 'id' => null, 'verrouille' => false],
            ]),
            'information' => $base('Information de {{etablissement_nom}}', [
                ['type' => 'titre', 'texte' => 'Bonjour {{destinataire_nom}}', 'niveau' => 'h2', 'alignement' => 'gauche', 'id' => null, 'verrouille' => false],
                ['type' => 'texte', 'contenu' => 'Voici une information de {{etablissement_nom}}.', 'alignement' => 'gauche', 'id' => null, 'verrouille' => false],
                ['type' => 'separateur', 'style' => 'fin', 'alignement' => 'gauche', 'id' => null, 'verrouille' => false],
            ]),
        ];
    }

    private function garderModifiable(ModeleCourriel $modele): void
    {
        if (! $modele->estModifiable()) {
            throw new DomainException('Modèle verrouillé ou archivé : modification interdite.');
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function journaliser(ModeleCourriel $modele, string $action, ?int $versionNumero, ?int $auteurId, array $detail = []): void
    {
        JournalModele::create([
            'modele_id' => $modele->id,
            'version_numero' => $versionNumero,
            'action' => $action,
            'acteur_id' => $auteurId,
            'detail' => $detail === [] ? null : $detail,
        ]);
    }
}

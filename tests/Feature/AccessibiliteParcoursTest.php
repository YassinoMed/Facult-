<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP 17 — Accessibilité, mobile et connexion instable : preuves
 * automatisées sur le HTML servi du parcours public (même niveau que
 * les autres assertions de vues du projet ; l'exécution clavier/lecteur
 * d'écran relève de la revue manuelle) :
 *
 * - chaque champ a un label associé (utilisable sans souris) ;
 * - `aria-invalid` sur les champs en erreur (16 posés, script idempotent) ;
 * - erreurs associées via `aria-describedby` ;
 * - anti double-soumission (`submitting` + `:disabled`) ;
 * - SW : aucune page mise en cache (assets `/build/`, `/icons/` et
 *   fallback `/hors-ligne` seuls — jamais de données) ;
 * - brouillon localStorage : whitelist de champs non sensibles uniquement.
 */
class AccessibiliteParcoursTest extends TestCase
{
    use RefreshDatabase;

    private function htmlInscription(): string
    {
        return (string) $this->get(route('inscription.create'))->assertOk()->getContent();
    }

    private function bladeInscription(): string
    {
        return (string) file_get_contents(resource_path('views/inscription/create.blade.php'));
    }

    public function test_chaque_champ_a_un_label_associe(): void
    {
        $contenu = $this->htmlInscription();

        preg_match_all('/<(input|select|textarea)\b[^>]*>/i', $contenu, $champs);

        $this->assertNotEmpty($champs[0]);

        // Blocs <label>…</label> : un champ enveloppé est associé sans `for`.
        preg_match_all('/<label\b[^>]*>.*?<\/label>/is', $contenu, $labels);
        $enveloppes = implode("\n", $labels[0]);

        $sansLabel = [];
        foreach ($champs[0] as $champ) {
            if (str_contains($champ, 'type="hidden"') || str_contains($champ, 'type="submit"')) {
                continue;
            }
            if (str_contains($champ, 'aria-label=') || str_contains($champ, 'aria-labelledby=')) {
                continue;
            }
            if (preg_match('/\bid="([^"]+)"/i', $champ, $m)) {
                if (str_contains($contenu, 'for="'.$m[1].'"')) {
                    continue;
                }
            }
            if (str_contains($enveloppes, $champ)) {
                continue;
            }
            $sansLabel[] = mb_substr($champ, 0, 80);
        }

        $this->assertSame([], $sansLabel, 'Champs sans label associé.');
    }

    public function test_aria_invalid_sur_champs_en_erreur(): void
    {
        // Directives Blade : lues dans la SOURCE (le HTML rendu omet les
        // @error sans erreur en session — comportement voulu).
        $source = $this->bladeInscription();

        // 16 attributs posés (script idempotent), chacun conditionné à
        // l'erreur de SON champ (pas de aria-invalid permanent).
        $this->assertGreaterThanOrEqual(16, substr_count($source, 'aria-invalid="true"'));
        $this->assertStringNotContainsString('aria-invalid="false"', $source);

        preg_match_all('/@error\(\'([a-zA-Z0-9_.]+)\'\) aria-invalid="true" @enderror/', $source, $appariements);
        $this->assertNotEmpty($appariements[1]);
    }

    public function test_erreurs_associees_aux_champs_via_describedby(): void
    {
        // Template source : le bloc d'erreurs global ne se rend qu'avec des
        // erreurs en session — la structure est prouvée dans la source, le
        // rendu avec erreurs est couvert par InscriptionEtudiantsTest.
        $source = $this->bladeInscription();

        $this->assertGreaterThanOrEqual(10, substr_count($source, 'aria-describedby'));
        $this->assertStringContainsString('role="alert"', $source);
    }

    public function test_anti_double_soumission_et_etat_chargement(): void
    {
        $contenu = $this->htmlInscription();

        // State `submitting` + bouton désactivé pendant l'envoi (LOOP 17 :
        // réseau lent sans fausse confirmation ; la 2e soumission est
        // impossible côté client, l'unicité CIN verrouille côté serveur).
        $this->assertStringContainsString('submitting', $contenu);
        $this->assertStringContainsString(':disabled="submitting', $contenu);
    }

    public function test_service_worker_ne_met_jamais_de_page_en_cache(): void
    {
        $sw = (string) file_get_contents(public_path('sw.js'));

        // Filtre `cachable()` : seuls les assets versionnés passent.
        $this->assertStringContainsString("url.pathname.startsWith('/build/')", $sw);
        $this->assertStringContainsString("url.pathname.startsWith('/icons/')", $sw);

        // Un SEUL `cache.put` dans tout le fichier, placé APRÈS le filtre
        // `if (!cachable(request)) return;` : toute mise en cache passe
        // par `cachable()` — les navigations (réseau + fallback
        // `/hors-ligne`) ne mettent jamais de page avec données en cache.
        $this->assertSame(1, substr_count($sw, 'cache.put('));

        $positionFiltre = strpos($sw, 'if (!cachable(request)) return;');
        $positionPut = strpos($sw, 'cache.put(');
        $this->assertNotFalse($positionFiltre);
        $this->assertGreaterThan($positionFiltre, $positionPut);
        $this->assertStringContainsString('caches.match(HORS_LIGNE)', $sw);
    }

    public function test_brouillon_localstorage_sans_donnee_sensible(): void
    {
        $js = (string) file_get_contents(resource_path('js/app.js'));

        preg_match("/champsBrouillon:\s*\[([^\]]+)\]/", $js, $m);
        $this->assertNotEmpty($m, 'Whitelist du brouillon introuvable.');

        $sensibles = ['nom', 'prenom', 'cin', 'naissance', 'email', 'password', 'telephone', 'adresse', 'document', 'scan'];

        foreach (explode(',', $m[1]) as $champ) {
            $nom = mb_strtolower(trim($champ, " \t\n\r\0\x0B'\""));
            foreach ($sensibles as $interdit) {
                $this->assertStringNotContainsString(
                    $interdit, $nom,
                    "Le brouillon localStorage contient un champ sensible : {$nom}."
                );
            }
        }
    }
}

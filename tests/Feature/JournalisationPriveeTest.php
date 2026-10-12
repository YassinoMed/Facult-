<?php

namespace Tests\Feature;

use App\Services\Identity\CinOcrService;
use App\Support\JournalisationPrivee;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * LOOP 9 — Hygiène des journaux : aucune PII (CIN, hash, mot de passe)
 * dans les logs, diagnostic conservé (codes, tables, comptes).
 */
class JournalisationPriveeTest extends TestCase
{
    use RefreshDatabase;

    public function test_assainir_masque_cles_sensibles_meme_imbriquees(): void
    {
        $propre = JournalisationPrivee::assainir([
            'password' => 'secret',
            'CIN' => '12345678',
            'user' => ['eMail' => 'a@test.tn', 'mot_de_passe' => 'x', 'Tel' => '+21600000000'],
            'token_session' => 'abc',
            'nom' => 'Ben Ali',
        ]);

        $this->assertSame('[MASQUÉ]', $propre['password']);
        $this->assertSame('[MASQUÉ]', $propre['CIN']);
        $this->assertSame('a@test.tn', $propre['user']['eMail']);
        $this->assertSame('[MASQUÉ]', $propre['user']['mot_de_passe']);
        $this->assertSame('[MASQUÉ]', $propre['user']['Tel']);
        $this->assertSame('[MASQUÉ]', $propre['token_session']);
        $this->assertSame('Ben Ali', $propre['nom']);
    }

    public function test_assainir_masque_hash_bcrypt_en_valeur(): void
    {
        $this->assertSame(
            ['contexte' => '[HASH MASQUÉ]'],
            JournalisationPrivee::assainir(['contexte' => 'insert ... $2y$12$abcdef'])
        );
    }

    public function test_empreinte_deterministe_sans_exposer(): void
    {
        $this->assertSame(hash('sha256', '12345678'), JournalisationPrivee::empreinte('12345678'));
        $this->assertNull(JournalisationPrivee::empreinte(null));
        $this->assertNull(JournalisationPrivee::empreinte(''));
    }

    public function test_contexte_journal_ocr_sans_pii(): void
    {
        $service = app(CinOcrService::class);
        $cin = '99887766';
        $contexte = $service->contexteJournal(
            ["CIN : {$cin}\nNom : TEST", 'texte vide'],
            "CIN : {$cin}",
            true
        );

        $texte = json_encode($contexte);
        $this->assertNotFalse($texte);
        $this->assertStringNotContainsString($cin, $texte);
        $this->assertStringNotContainsString('TEST', $texte);
        $this->assertSame(2, $contexte['sources']);
        $this->assertTrue($contexte['document_detecte']);
        $this->assertSame(hash('sha256', "CIN : {$cin}"), $contexte['empreinte_corpus']);
    }

    public function test_query_exception_rapportee_assainie_sans_bindings(): void
    {
        $captures = [];
        Log::listen(function (MessageLogged $evenement) use (&$captures): void {
            $captures[] = $evenement->message.' '.json_encode($evenement->context);
        });

        $cin = '55443322';
        $hash = '$2y$12$abcdefghijklmnopqrstuvwxyz0123456789AB';
        $exception = new QueryException(
            DB::connection()->getName(),
            'insert into `etudiants` (`cin`, `password`) values (?, ?)',
            [$cin, $hash],
            new \Exception('Column not found')
        );

        app(ExceptionHandler::class)->report($exception);

        $tout = implode("\n", $captures);

        // Aucune valeur sensible, même dans le message d'exception formaté.
        $this->assertStringNotContainsString($cin, $tout);
        $this->assertStringNotContainsString($hash, $tout);
        $this->assertStringNotContainsString('$2y$', $tout);

        // Diagnostic conservé : canal dédié, code, table, pas de SQL.
        $this->assertStringContainsString('sql.exception_assainie', $tout);
        $this->assertStringContainsString('etudiants', $tout);
        $this->assertStringNotContainsString('insert into', $tout);
    }
}

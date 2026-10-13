<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmtpConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Throwable;

/**
 * Configurations SMTP nommées (LOOP J) : super_admin seul, mot de passe
 * chiffré jamais réaffiché (vide = conservé), test de connexion réel
 * (start/stop sans envoi) aux erreurs expurgées.
 */
class SmtpConfigurationController extends Controller
{
    public function index(): View
    {
        return view('admin.smtp.index', [
            'configurations' => SmtpConfiguration::orderBy('nom')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.smtp.edit', ['configuration' => new SmtpConfiguration()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate($this->regles());

        SmtpConfiguration::create($donnees + ['actif' => (bool) $request->boolean('actif')]);

        return redirect()->route('admin.smtp.index')->with('success', 'Configuration SMTP créée.');
    }

    public function edit(SmtpConfiguration $smtp): View
    {
        return view('admin.smtp.edit', ['configuration' => $smtp]);
    }

    public function update(Request $request, SmtpConfiguration $smtp): RedirectResponse
    {
        $donnees = $request->validate($this->regles(true));

        // Mot de passe vide = conservé (jamais réaffiché).
        if (($donnees['mot_de_passe'] ?? null) === null || $donnees['mot_de_passe'] === '') {
            unset($donnees['mot_de_passe']);
        }

        $smtp->update($donnees + ['actif' => (bool) $request->boolean('actif')]);

        return redirect()->route('admin.smtp.index')->with('success', 'Configuration SMTP mise à jour.');
    }

    public function destroy(SmtpConfiguration $smtp): RedirectResponse
    {
        $smtp->delete();

        return redirect()->route('admin.smtp.index')->with('success', 'Configuration SMTP supprimée.');
    }

    public function tester(SmtpConfiguration $smtp): RedirectResponse
    {
        try {
            $transport = new EsmtpTransport($smtp->hote, $smtp->port, $smtp->chiffrement === 'ssl');
            $transport->setTimeout(10);
            if ($smtp->identifiant !== null && $smtp->identifiant !== '') {
                $transport->setUsername($smtp->identifiant);
                $transport->setPassword((string) $smtp->mot_de_passe);
            }
            $transport->start();
            $transport->stop();
        } catch (Throwable $e) {
            Log::channel(config('logging.default'))->warning('smtp.test_echec', [
                'configuration_id' => $smtp->id,
                'classe' => $e::class,
            ]);

            return back()->withErrors(['connexion' => 'Connexion SMTP impossible ('.$smtp->hote.'). Voir le journal.']);
        }

        return back()->with('success', "Connexion SMTP « {$smtp->nom} » réussie (aucun e-mail envoyé).");
    }

    /**
     * @return array<string, mixed>
     */
    private function regles(bool $modification = false): array
    {
        return [
            'nom' => ['required', 'string', 'max:100'],
            'hote' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'chiffrement' => ['required', 'in:ssl,tls,aucun'],
            'identifiant' => ['nullable', 'string', 'max:255'],
            'mot_de_passe' => [$modification ? 'nullable' : 'required', 'string', 'max:500'],
            'expediteur' => ['required', 'email', 'max:255'],
            'nom_expediteur' => ['nullable', 'string', 'max:255'],
        ];
    }
}

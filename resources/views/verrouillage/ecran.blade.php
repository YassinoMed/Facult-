{{-- Shell autonome (LOOP N) : AUCUNE navigation, AUCUNE donnée métier —
     uniquement l'identité non sensible et le défi PIN. Thème clair/sombre
     conservé. Recours autoritaire sans JS (le voile client reste cosmétique). --}}
<!DOCTYPE html>
<html lang="fr" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#1e3a5f">
    <title>Session verrouillée — UniRegistre</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>
        (function () {
            try {
                var choix = localStorage.getItem('theme') || 'system';
                var sombre = choix === 'dark' || (choix === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                if (sombre) document.documentElement.classList.add('dark');
            } catch (e) {}
        })();
    </script>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-800 antialiased selection:bg-primary-500 selection:text-white dark:bg-slate-950 dark:text-slate-100">
    <main class="mx-auto flex min-h-screen max-w-md items-center px-4 py-10" tabindex="-1">
        <div class="ds-card w-full p-6 text-center sm:p-8" role="main" aria-label="Session verrouillée">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900" aria-hidden="true">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
            </div>
            <h1 class="mt-4 text-xl font-black text-slate-900 dark:text-slate-100">Session verrouillée</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Inactivité prolongée — {{ $identite }}. Votre travail enregistré est conservé.</p>
            <form method="POST" action="{{ route('admin.verrouillage.verifier') }}" class="mt-5 space-y-4 text-left"
                  onsubmit="var b=this.querySelector('[type=submit]');if(b){b.disabled=true;}">
                @csrf
                <div>
                    <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="verrou-code">Code de sécurité *</label>
                    <input id="verrou-code" name="code" type="password" required inputmode="numeric" autocomplete="off" autofocus
                           pattern="\d{6,8}" maxlength="8" minlength="6" aria-describedby="verrou-erreur"
                           class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-center text-lg font-mono tracking-[0.3em] dark:border-slate-700 dark:bg-slate-900" />
                    <div class="mt-1 text-center">
                        <button type="button" id="verrou-afficher" aria-pressed="true"
                                class="text-[11px] font-bold text-slate-500 underline underline-offset-2">Afficher la saisie</button>
                    </div>
                    @error('code')<p id="verrou-erreur" class="mt-1 text-xs font-semibold text-rose-600" role="alert">{{ $message }}</p>@enderror
                </div>
                <x-btn type="submit" class="w-full">Déverrouiller</x-btn>
            </form>
            <script>
                // Bascule afficher/masquer (shell autonome sans Alpine).
                (function () {
                    var champ = document.getElementById('verrou-code');
                    var bouton = document.getElementById('verrou-afficher');
                    if (!champ || !bouton) return;
                    bouton.addEventListener('click', function () {
                        var masque = champ.type === 'password';
                        champ.type = masque ? 'text' : 'password';
                        bouton.textContent = masque ? 'Masquer la saisie' : 'Afficher la saisie';
                        bouton.setAttribute('aria-pressed', String(!masque));
                        champ.focus();
                    });
                })();
            </script>
            <form method="POST" action="{{ route('admin.verrouillage.deconnecter') }}" class="mt-3">
                @csrf
                <button type="submit" class="text-xs font-bold text-slate-500 underline underline-offset-2 hover:text-slate-700 dark:text-slate-400">Se déconnecter</button>
            </form>
            <p class="mt-3 text-[11px] text-slate-400 dark:text-slate-500">Code oublié ? Déconnectez-vous puis reconnectez-vous (mot de passe + double facteur) : vous pourrez redéfinir le code sur la page « Code de verrouillage ».</p>
        </div>
    </main>
</body>
</html>

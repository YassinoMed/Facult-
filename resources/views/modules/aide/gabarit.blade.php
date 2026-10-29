<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titre', "Centre d'aide") — UniRegistre</title>

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif

    <style>
        /* Styles d'aide non Tailwind : rendu markdown lisible, autonome. */
        .aide-contenu h1 { font-size: 1.75rem; font-weight: 800; margin: 1.25rem 0 1rem; }
        .aide-contenu h2 { font-size: 1.35rem; font-weight: 700; margin: 1.5rem 0 .75rem; border-top: 1px solid #e2e8f0; padding-top: 1rem; }
        .aide-contenu h3 { font-size: 1.1rem; font-weight: 700; margin: 1.25rem 0 .5rem; }
        .aide-contenu p, .aide-contenu li { line-height: 1.65; }
        .aide-contenu p { margin: .5rem 0; }
        .aide-contenu ul, .aide-contenu ol { margin: .5rem 0 .5rem 1.25rem; list-style: disc; }
        .aide-contenu ol { list-style: decimal; }
        .aide-contenu table { width: 100%; border-collapse: collapse; margin: .75rem 0; font-size: .9rem; }
        .aide-contenu th, .aide-contenu td { border: 1px solid #e2e8f0; padding: .5rem .65rem; text-align: left; vertical-align: top; }
        .aide-contenu th { background: #f8fafc; font-weight: 700; }
        .aide-contenu code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85em; background: #f1f5f9; padding: .1rem .35rem; border-radius: .25rem; }
        .aide-contenu pre { background: #0f172a; color: #e2e8f0; padding: .75rem 1rem; border-radius: .5rem; overflow-x: auto; margin: .75rem 0; }
        .aide-contenu pre code { background: transparent; color: inherit; padding: 0; }
        .aide-contenu blockquote { border-left: 4px solid #94a3b8; padding-left: .75rem; color: #475569; margin: .5rem 0; }
        .aide-contenu hr { border: 0; border-top: 1px solid #e2e8f0; margin: 1rem 0; }
    </style>
</head>
<body class="bg-slate-100 min-h-screen text-slate-900">
    <header class="bg-slate-900 text-white">
        <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between">
            <a href="{{ route('aide.index') }}" class="font-black tracking-wide">UniRegistre — Centre d'aide</a>
            <span class="text-xs text-slate-300">6 angles d'accès</span>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-4 py-6">
        @yield('contenu')
    </main>

    <footer class="max-w-5xl mx-auto px-4 py-6 text-xs text-slate-500">
        Base de connaissances utilisateur — contenu du dépôt `docs/utilisateur/`.
        Aucune donnée personnelle n'est affichée sur ces pages.
    </footer>
</body>
</html>

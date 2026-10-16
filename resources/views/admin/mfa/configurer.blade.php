@extends('layouts.app')

@section('title', 'Double facteur')

@section('content')
<div class="mx-auto max-w-2xl px-4 sm:px-6 py-8">
    <x-page-header titre="Double facteur (TOTP)" description="Application d'authentification requise."
        :fil="['Double facteur' => '#']" />

    @if(session('success'))
        <div class="mt-4"><x-alert type="success">{{ session('success') }}</x-alert></div>
    @endif

    @if($actif)
        <div class="mt-4 ds-card p-5">
            <p class="text-sm font-bold text-emerald-700">Protection active sur ce compte.</p>
            <form method="POST" action="{{ route('admin.mfa.desactiver') }}" class="mt-4 space-y-3"
                   onsubmit="return confirm('Désactiver le double facteur ?')">
                @csrf
                <div>
                    <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="mfa-mdp">Mot de passe actuel *</label>
                    <input id="mfa-mdp" name="password" type="password" required autocomplete="current-password"
                           class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm" />
                    @error('password')<p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>@enderror
                </div>
                <x-btn variant="danger" type="submit">Désactiver</x-btn>
            </form>
        </div>
    @else
        <div class="mt-4 ds-card space-y-5 p-5">
            {{-- Méthode recommandée : aucun recopiage, zéro faute de frappe. --}}
            <div>
                <p class="text-sm font-bold text-slate-800">1. Scannez ce QR avec votre application <span class="font-normal text-slate-500">(Google Authenticator, Microsoft Authenticator, FreeOTP…)</span></p>
                <div class="mt-2 flex justify-center">
                    <div id="mfa-qr" class="rounded-xl border border-slate-200 bg-white p-3" role="img" aria-label="QR code d'enrôlement double facteur">
                        <p class="px-4 py-8 text-center text-xs text-slate-400">Chargement du QR…</p>
                    </div>
                </div>
                <p id="mfa-qr-erreur" class="mt-1 hidden text-center text-xs text-rose-600">QR indisponible — utilisez la saisie manuelle ci-dessous.</p>
            </div>

            {{-- Secours : saisie manuelle du secret. --}}
            <div>
                <p class="text-sm font-bold text-slate-800">2. Sans caméra : saisissez ce secret <span class="font-normal text-slate-500">(copier-coller recommandé)</span></p>
                <div class="mt-2 flex items-stretch gap-2">
                    <p class="flex-1 rounded-xl bg-slate-900 px-4 py-3 text-center font-mono text-sm font-bold tracking-widest text-white select-all">{{ trim(chunk_split($secret, 4, ' ')) }}</p>
                    <button type="button" data-copier="secret"
                            class="shrink-0 rounded-xl border border-slate-200 px-3 text-xs font-bold text-slate-600 hover:bg-slate-100">Copier</button>
                </div>
                <p class="mt-1 text-[11px] text-slate-400">Alphabet : lettres A–Z et chiffres 2–7 uniquement (pas de 0, 1, 8, 9). Espaces, tirets et minuscules acceptés.</p>
                <details class="mt-2">
                    <summary class="cursor-pointer text-xs font-bold text-slate-500 underline underline-offset-2">Détails techniques (URI otpauth)</summary>
                    <div class="mt-2 flex items-stretch gap-2">
                        <code class="flex-1 break-all rounded-xl bg-slate-100 px-3 py-2 font-mono text-[11px] text-slate-600 select-all">{{ $uri }}</code>
                        <button type="button" data-copier="uri"
                                class="shrink-0 rounded-xl border border-slate-200 px-3 text-xs font-bold text-slate-600 hover:bg-slate-100">Copier</button>
                    </div>
                </details>
            </div>

            <div>
                <p class="text-sm font-bold text-slate-800">3. Saisissez le code à 6 chiffres affiché par l'application</p>
                <p class="mt-1 text-[11px] text-slate-400">Heure du serveur : <strong class="font-mono">{{ now()->format('H:i:s') }} UTC</strong> — elle doit correspondre à votre téléphone à quelques secondes près (heure automatique activée). Le code change toutes les 30 secondes : <span class="font-mono font-bold text-slate-600">nouveau code dans <span id="mfa-compte-rebours">…</span> s</span>. Si le code est refusé en fin de période, attendez le suivant.</p>
                <form method="POST" action="{{ route('admin.mfa.confirmer') }}" class="mt-3 space-y-3">
                    @csrf
                    <div>
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="mfa-code">Code à 6 chiffres *</label>
                        <input id="mfa-code" name="code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"
                               class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm font-mono" />
                        @error('code')<p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <x-btn type="submit">Activer</x-btn>
                </form>
            </div>

            <form method="POST" action="{{ route('admin.mfa.nouveau-secret') }}" class="border-t border-slate-100 pt-3"
                  onsubmit="return confirm('Générer un nouveau secret ? Pensez à supprimer l’ancienne entrée dans votre application.')">
                @csrf
                <button type="submit" class="text-xs font-bold text-slate-500 underline underline-offset-2">Recommencer avec un nouveau secret</button>
                <p class="mt-1 text-[11px] text-slate-400">Un nouveau secret invalide l'ancien : supprimez l'ancienne entrée dans votre application avant de scanner ou saisir le nouveau.</p>
            </form>
        </div>

        {{-- QR réel généré localement depuis l'URI otpauth (libellé MIT vendored, aucun réseau, le secret ne quitte pas le navigateur). --}}
        <script src="{{ asset('js/qrcode-generator.min.js') }}"></script>
        <script>
        (function () {
            var uri = @json($uri);
            var secret = @json($secret);
            var zone = document.getElementById('mfa-qr');
            var erreur = document.getElementById('mfa-qr-erreur');
            try {
                if (typeof qrcode === 'undefined') throw new Error('libellé QR absent');
                var qr = qrcode(0, 'M');
                qr.addData(uri);
                qr.make();
                zone.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 4, scalable: true, title: 'Enrôlement double facteur UniRegistre' });
                var svg = zone.querySelector('svg');
                if (svg) { svg.style.width = '100%'; svg.style.maxWidth = '220px'; svg.style.height = 'auto'; svg.style.display = 'block'; svg.style.margin = '0 auto'; }
            } catch (e) { erreur.classList.remove('hidden'); }

            // Copie du secret (sans espaces) ou de l'URI.
            document.querySelectorAll('[data-copier]').forEach(function (bouton) {
                bouton.addEventListener('click', function () {
                    var texte = bouton.getAttribute('data-copier') === 'uri' ? uri : secret;
                    var confirmer = function () { bouton.textContent = 'Copié !'; setTimeout(function () { bouton.textContent = 'Copier'; }, 2000); };
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(texte).then(confirmer, function () { secours(); });
                    } else { secours(); }
                    function secours() {
                        var champ = document.createElement('textarea');
                        champ.value = texte;
                        document.body.appendChild(champ);
                        champ.select();
                        try { document.execCommand('copy'); confirmer(); } catch (err) { /* presse-papiers indisponible */ }
                        document.body.removeChild(champ);
                    }
                });
            });

            // Compte à rebours de la période TOTP (30 s).
            var decompte = document.getElementById('mfa-compte-rebours');
            function rafraichir() { if (decompte) decompte.textContent = String(30 - (Math.floor(Date.now() / 1000) % 30)); }
            rafraichir();
            setInterval(rafraichir, 1000);
        })();
        </script>
    @endif
</div>
@endsection

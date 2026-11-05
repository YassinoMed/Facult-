@extends('layouts.app')

@section('title', 'Dossier Étudiant — ' . $etudiant->nom_complet)

@section('content')
@php
    // Monogramme décoratif du dossier (2 initiales maximum).
    $initiales = collect(explode(' ', $etudiant->nom_complet))
        ->filter()
        ->map(fn ($mot) => mb_strtoupper(mb_substr($mot, 0, 1)))
        ->take(2)
        ->implode('');

    // Indicateurs clés : compteur GSAP uniquement si la valeur est numérique.
    $chiffres = [
        ['Moyenne générale', $resume['moyenne_generale'] ?? '—', 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z'],
        ['Crédits', $resume['credits_obtenus'] . ' / ' . $resume['credits_total'], 'M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z'],
        ['Absences', $resume['absences'], 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5'],
        ['Demandes ouvertes', $resume['demandes_ouvertes'], 'M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H6.911a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661Z'],
    ];
@endphp
<style>
    /* ═══ Fiche dossier étudiant — style moderne fluide (portée .fiche-dossier) ═══
       Mouvement borné au back-office : pas de Lenis, pas de parallaxe, pas de
       curseur. GSAP (admin.js) révèle les blocs [data-motion-block] et anime les
       compteurs [data-motion-count] ; le reste est du CSS sobre, désactivé en
       prefers-reduced-motion. Sans JavaScript, tout reste visible. */

    /* ── Couverture : dégradé profond, halos aurora, grille en filigrane ── */
    .fiche-hero {
        position: relative;
        overflow: hidden;
        isolation: isolate;
        background:
            radial-gradient(1100px 560px at 88% -12%, rgb(99 102 241 / .38), transparent 62%),
            radial-gradient(820px 460px at -8% 112%, rgb(14 165 233 / .28), transparent 58%),
            linear-gradient(158deg, #1e1b4b 0%, #191640 52%, #12102e 100%);
    }
    .fiche-hero::before {
        content: "";
        position: absolute;
        inset: 0;
        background-image:
            linear-gradient(to right, rgb(255 255 255 / .05) 1px, transparent 1px),
            linear-gradient(to bottom, rgb(255 255 255 / .05) 1px, transparent 1px);
        background-size: 44px 44px;
        -webkit-mask-image: radial-gradient(ellipse 85% 90% at 20% 0%, black 30%, transparent 75%);
        mask-image: radial-gradient(ellipse 85% 90% at 20% 0%, black 30%, transparent 75%);
        pointer-events: none;
    }
    .fiche-hero-halo {
        position: absolute;
        z-index: -1;
        top: -15rem;
        right: -11rem;
        width: 34rem;
        height: 34rem;
        border-radius: 9999px;
        opacity: .45;
        pointer-events: none;
    }
    .fiche-hero-halo-2 {
        top: auto;
        right: auto;
        left: -13rem;
        bottom: -17rem;
        width: 28rem;
        height: 28rem;
        opacity: .4;
        background: radial-gradient(circle, rgb(56 189 248 / .4), transparent 66%);
        filter: blur(40px);
    }

    /* ── Verre dépoli des cartes de couverture ── */
    .fiche-verre {
        background: rgb(255 255 255 / .07);
        border: 1px solid rgb(255 255 255 / .14);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        transition: background-color .35s cubic-bezier(.22, 1, .36, 1), border-color .35s, transform .35s cubic-bezier(.22, 1, .36, 1);
    }
    .fiche-verre:hover {
        background: rgb(255 255 255 / .11);
        border-color: rgb(255 255 255 / .22);
    }
    .fiche-monogramme {
        background: linear-gradient(135deg, rgb(129 140 248 / .9), rgb(56 189 248 / .55));
        border: 1px solid rgb(255 255 255 / .28);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
    }

    /* ── Sur-titres éditoriaux des sections ── */
    .fiche-surtitre {
        font-size: .7rem;
        font-weight: 800;
        letter-spacing: .16em;
        text-transform: uppercase;
        color: #4f46e5;
    }
    .dark .fiche-surtitre { color: #a5b4fc; }

    /* ── Champs de formulaire : un seul style fluide pour toute la fiche ── */
    .fiche-dossier :is(input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="submit"]), select, textarea) {
        width: 100%;
        min-height: 44px;
        border-radius: .85rem;
        border: 1px solid #cbd5e1;
        background-color: #fff;
        padding: .6rem .9rem;
        font-size: .95rem;
        line-height: 1.45;
        transition: border-color .25s, box-shadow .25s, background-color .25s;
    }
    .fiche-dossier :is(input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="submit"]), select, textarea):focus-visible {
        outline: none;
        border-color: #6366f1;
        box-shadow: 0 0 0 4px rgb(99 102 241 / .16);
    }
    .dark .fiche-dossier :is(input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="submit"]), select, textarea) {
        background-color: #0f172a;
        border-color: #334155;
        color: #e2e8f0;
    }
    .dark .fiche-dossier :is(input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="submit"]), select, textarea):focus-visible {
        border-color: #818cf8;
        box-shadow: 0 0 0 4px rgb(129 140 248 / .18);
    }

    /* ── Boutons : micro-relèvement fluide, sans rebond ── */
    .fiche-dossier button {
        transition: transform .3s cubic-bezier(.22, 1, .36, 1), box-shadow .3s, background-color .25s, color .25s, border-color .25s, opacity .25s;
    }
    .fiche-dossier button:not(:disabled):hover { transform: translateY(-1.5px); }
    .fiche-dossier button:not(:disabled):active {
        transform: translateY(0) scale(.98);
        transition-duration: .08s;
    }

    /* ── Onglets : pilule vitrée collante sous l'en-tête, onglet actif dégradé ── */
    .fiche-dossier .admin-tabs [role="tablist"] {
        gap: .25rem;
        padding: .375rem;
        background: rgb(255 255 255 / .82);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        border: 1px solid rgb(226 232 240 / .9);
        border-radius: 1.25rem;
        box-shadow: 0 10px 30px -18px rgb(15 23 42 / .3);
    }
    @media (min-width: 768px) {
        .fiche-dossier .admin-tabs [role="tablist"] {
            position: sticky;
            top: 4.5rem;
            z-index: 30;
        }
    }
    .fiche-dossier .admin-tabs [role="tab"] {
        border-radius: .9rem;
    }
    .fiche-dossier .admin-tabs [role="tab"][aria-selected="true"] {
        background: linear-gradient(135deg, #4f46e5, #6366f1 55%, #7c3aed);
        color: #fff;
        box-shadow: 0 8px 20px -10px rgb(79 70 229 / .55);
    }
    .fiche-dossier .admin-tabs [role="tab"][aria-selected="false"]:hover {
        background: rgb(241 245 249 / .9);
        color: #0f172a;
    }
    .dark .fiche-dossier .admin-tabs [role="tablist"] {
        background: rgb(15 23 42 / .85);
        border-color: #1e293b;
    }
    .dark .fiche-dossier .admin-tabs [role="tab"][aria-selected="false"]:hover {
        background: #1e293b;
        color: #e2e8f0;
    }
    .fiche-dossier .admin-tabs [role="tabpanel"] {
        animation: fiche-panneau .45s cubic-bezier(.22, 1, .36, 1) both;
    }
    @keyframes fiche-panneau {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: none; }
    }

    /* ── Jauges (complétude, score) : remplissage dégradé animé une fois ── */
    .fiche-jauge {
        position: relative;
        height: .5rem;
        overflow: hidden;
        border-radius: 9999px;
        background: #e2e8f0;
    }
    .dark .fiche-jauge { background: #1e293b; }
    .fiche-jauge-remplie {
        height: 100%;
        border-radius: 9999px;
        width: var(--fiche-jauge, 0%);
        background: linear-gradient(90deg, #4f46e5, #6366f1 60%, #38bdf8);
        animation: fiche-jauge-remplit 1.2s cubic-bezier(.22, 1, .36, 1) .15s both;
    }
    @keyframes fiche-jauge-remplit {
        from { width: 0; }
    }

    /* ── Respect de l'accessibilité : tout est figé si le mouvement est réduit ── */
    @media (prefers-reduced-motion: reduce) {
        .fiche-dossier *,
        .fiche-dossier *::before,
        .fiche-dossier *::after {
            animation-duration: .01ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: .01ms !important;
        }
    }

    /* ── Impression : couverture lisible sur fond blanc, décor retiré ── */
    @media print {
        .fiche-hero {
            background: #fff !important;
            border-radius: 0 !important;
        }
        .fiche-hero::before,
        .fiche-hero-halo,
        .fiche-monogramme {
            display: none !important;
        }
        .fiche-hero :is(h1, p, a, strong, ol, li, span) {
            color: #0f172a !important;
            -webkit-text-fill-color: #0f172a !important;
            background: none !important;
        }
    }
</style>
<div class="fiche-dossier mx-auto max-w-7xl space-y-8 px-4 py-8 text-base leading-relaxed sm:px-6 lg:px-8" data-motion-zone>

    {{-- ── Couverture du dossier : bandeau cinématique, identité en grand, indicateurs vitrés ── --}}
    <section class="fiche-hero -mx-4 rounded-b-[2.5rem] px-4 py-10 text-white sm:-mx-6 sm:px-6 sm:py-14 lg:-mx-8 lg:px-8 print-card" aria-labelledby="titre-fiche">
        <div class="fiche-hero-halo aurora-glow" aria-hidden="true"></div>
        <div class="fiche-hero-halo fiche-hero-halo-2" aria-hidden="true"></div>

        <nav aria-label="Fil d'Ariane">
            <ol class="inline-flex flex-wrap items-center gap-2 rounded-full bg-white/10 px-3.5 py-1.5 text-[13px] font-semibold text-primary-100 ring-1 ring-white/15 backdrop-blur-sm">
                <li><a href="{{ route('admin.etudiants.index') }}" class="transition hover:text-white hover:underline underline-offset-2">Registre des étudiants</a></li>
                <li aria-hidden="true" class="text-primary-400">/</li>
                <li><span aria-current="page" class="text-primary-100">{{ $etudiant->nom_complet }}</span></li>
            </ol>
        </nav>

        <div class="mt-7 flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex min-w-0 items-start gap-5">
                <span class="fiche-monogramme hidden h-16 w-16 shrink-0 items-center justify-center rounded-2xl text-2xl font-black text-white sm:flex" aria-hidden="true">{{ $initiales }}</span>
                <div class="min-w-0">
                    <h1 id="titre-fiche" class="text-balance text-white [font-size:clamp(1.9rem,5.5vw,4.25rem)] font-black leading-[1.02] tracking-tight">{{ $etudiant->nom_complet }}</h1>
                    <p class="mt-4 text-base text-primary-100">
                        {{ $etudiant->email }} · {{ $etudiant->telephone ?: 'Aucun téléphone renseigné' }}
                    </p>
                    <p class="mt-1 text-base text-primary-200">
                        CIN <span class="font-semibold tabular-nums text-white">{{ $etudiant->cin }}</span>
                        · Inscrit en {{ $etudiant->faculte->nom }}
                    </p>
                </div>
            </div>

            <div class="fiche-verre shrink-0 rounded-3xl p-5 lg:text-right">
                <p class="text-[13px] font-semibold text-primary-200">Numéro d'inscription
                    <span class="mt-1 block text-3xl font-black tabular-nums text-white">{{ $etudiant->num_inscription }}</span>
                </p>
                <div class="mt-3">{!! $etudiant->statut_badge !!}</div>
            </div>
        </div>

        {{-- ── Actions administratives (statut + mot de passe), panneau vitré sous l'identité ── --}}
        <div class="fiche-verre mt-8 rounded-3xl p-4 no-print sm:p-5">
            <p class="text-base text-primary-100">
                Statut actuel : <strong class="font-bold text-white">{{ $etudiant->statut_label }}</strong>.
                Les décisions sont historisées et notifiées à l'étudiant.
            </p>

            <div class="mt-4 flex flex-wrap gap-2">
                @if($etudiant->statut !== 'valide')
                    <form action="{{ route('admin.etudiants.updateStatut', $etudiant) }}" method="POST">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="statut" value="valide">
                        <button type="submit" class="btn-shine inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2.5 text-base font-bold text-white shadow-lg shadow-emerald-950/40 transition hover:bg-emerald-500">
                            Valider la candidature
                        </button>
                    </form>
                @endif

                @if($etudiant->statut !== 'rejete')
                    <form action="{{ route('admin.etudiants.updateStatut', $etudiant) }}" method="POST">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="statut" value="rejete">
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl bg-rose-700 px-4 py-2.5 text-base font-bold text-white shadow-lg shadow-rose-950/40 transition hover:bg-rose-600">
                            Rejeter le dossier
                        </button>
                    </form>
                @endif

                @if($etudiant->statut !== 'en_attente')
                    <form action="{{ route('admin.etudiants.updateStatut', $etudiant) }}" method="POST">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="statut" value="en_attente">
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl bg-amber-600 px-4 py-2.5 text-base font-bold text-white shadow-lg shadow-amber-950/40 transition hover:bg-amber-500">
                            Remettre en attente
                        </button>
                    </form>
                @endif

                @can('archives.update')
                    <form action="{{ route('admin.etudiants.reinitialiserMotDePasse', $etudiant) }}" method="POST"
                          x-data="{ confirmer: false }" @submit.prevent="confirmer = true">
                        @csrf
                        <input type="hidden" name="confirmation" value="1">
                        <button type="submit"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-white/25 px-4 py-2.5 text-base font-bold text-white transition hover:bg-white/10">
                            Réinitialiser le mot de passe
                        </button>
                        <x-modal titre="Réinitialiser le mot de passe étudiant ?" nom="confirmer">
                            <p>Un nouveau mot de passe sera généré et affiché <strong>une seule fois</strong>. L'ancien mot de passe de <strong>{{ $etudiant->nom_complet }}</strong> cessera immédiatement de fonctionner.</p>
                            <x-slot:actions>
                                <x-btn variant="secondary" @click="confirmer = false">Annuler</x-btn>
                                <x-btn @click="$root.requestSubmit()">Réinitialiser</x-btn>
                            </x-slot:actions>
                        </x-modal>
                    </form>
                @endcan
            </div>

            @if(session('mot_de_passe_provisoire'))
                <div class="mt-4 rounded-2xl bg-emerald-50 p-4 ring-1 ring-emerald-200" role="alert">
                    <p class="text-base font-bold text-emerald-900">Nouveau mot de passe — affiché une seule fois</p>
                    <p class="mt-1 select-all rounded-xl bg-white px-3 py-2 text-lg font-black tracking-wider text-emerald-900">{{ session('mot_de_passe_provisoire') }}</p>
                    <p class="mt-1 text-base text-emerald-800">Communiquez-le à l'étudiant maintenant (en main propre ou par téléphone) : il ne pourra plus être consulté.</p>
                </div>
            @endif
        </div>

        {{-- ── Indicateurs clés : cartes vitrées, compteurs GSAP opt-in ── --}}
        <div class="mt-10 grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4" role="group" aria-label="Indicateurs clés du dossier">
            @foreach($chiffres as [$libelle, $valeur, $icone])
                @php($decimales = is_numeric($valeur) ? (str_contains((string) $valeur, '.') ? 2 : 0) : null)
                <div class="fiche-verre rounded-2xl p-4" data-motion-block>
                    <div class="flex items-start justify-between gap-2">
                        <p class="mt-0.5 text-[13px] font-semibold text-primary-200">{{ $libelle }}</p>
                        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-white/10 text-primary-100 ring-1 ring-inset ring-white/15" aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icone }}" /></svg>
                        </span>
                    </div>
                    <p class="mt-2 text-3xl font-black tabular-nums {{ $valeur === '—' ? 'text-primary-300' : 'text-white' }}"
                       @if($decimales !== null) data-motion-count="{{ $valeur }}" data-motion-count-decimales="{{ $decimales }}" aria-hidden="true" @endif
                    >{{ $valeur }}</p>
                    @if($decimales !== null)
                        <span class="sr-only">{{ $valeur }} {{ strtolower($libelle) }}</span>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    {{-- ── Barre d'actions secondaires ── --}}
    <div class="flex flex-col gap-3 no-print sm:flex-row sm:items-center sm:justify-between">
        <p class="text-base text-slate-600">
            Dossier déposé le {{ $etudiant->created_at->format('d/m/Y') }}, dernière mise à jour le {{ $etudiant->updated_at->format('d/m/Y') }}.
        </p>
        <div class="flex flex-wrap items-center gap-2" x-data="{ openDelete: false }">
            <x-quick-actions contexte="etudiant.show" :sujet="$etudiant" />
            @if(app(\App\Support\Modules\ModuleRegistry::class)->estActif('student-archive'))
                @can('student_archive.view')
                    <a href="{{ route('admin.etudiants.archive', $etudiant) }}"
                       class="inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-base font-bold text-slate-700 transition hover:bg-slate-100">
                        Archive historique
                    </a>
                @endcan
            @endif
            @can('update', $etudiant)
                <a href="{{ route('admin.etudiants.modifier', $etudiant) }}"
                   class="inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-base font-bold text-slate-700 transition hover:bg-slate-100">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" /></svg>
                    Modifier
                </a>
            @endcan
            <button type="button" onclick="window.print()"
                    class="inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-base font-bold text-slate-700 transition hover:bg-slate-100">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z" /></svg>
                Imprimer le dossier
            </button>

            <button type="button" @click="openDelete = true"
                    class="inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-base font-bold text-rose-700 transition hover:bg-rose-50">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                Supprimer
            </button>

            <div x-show="openDelete" x-cloak
                 class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 text-left backdrop-blur-sm">
                <div @click.away="openDelete = false"
                     class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl ring-1 ring-slate-200"><!-- taste-skill-ignore : ombre de dialogue (suppression) -->
                    <h3 class="text-base font-bold text-slate-900">Supprimer définitivement ce dossier ?</h3>
                    <p class="mt-2 text-base leading-relaxed text-slate-600">
                        Supprimer le dossier de <strong>{{ $etudiant->nom_complet }}</strong> ({{ $etudiant->num_inscription }}) ? Cette opération est irréversible.
                    </p>
                    <div class="mt-6 flex justify-end gap-2.5">
                        <button type="button" @click="openDelete = false" class="rounded-xl px-4 py-2 text-base font-semibold text-slate-700 hover:bg-slate-100">
                            Annuler
                        </button>
                        <form action="{{ route('admin.etudiants.destroy', $etudiant) }}" method="POST">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-xl bg-rose-700 px-4 py-2 text-base font-bold text-white hover:bg-rose-600">
                                Confirmer la suppression
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Détail en onglets ── --}}
    <x-tabs :onglets="['synthese' => 'Synthèse 360°', 'infos' => 'Informations', 'parcours' => 'Parcours & situations', 'services' => 'Dossier & services']">
        <x-slot:panneau_synthese>
            <div class="space-y-8">
                {{-- Contrôle intelligent : verdict, score et anomalies sur un seul champ de couleur --}}
                @if(isset($verification))
                    @php($verdictCarte = match($verification->verdict) {
                        'CONFORME' => 'bg-emerald-50 ring-emerald-200/70',
                        'BLOQUANT' => 'bg-rose-50 ring-rose-200/70',
                        default => 'bg-amber-50 ring-amber-200/70',
                    })
                    <section class="rounded-3xl p-6 ring-1 ring-inset {{ $verdictCarte }}" aria-label="Contrôle intelligent du dossier" data-motion-block>
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="flex items-start gap-3">
                                <span class="mt-2 h-3 w-3 shrink-0 animate-pulse rounded-full {{ match($verification->verdict) {
                                    'CONFORME' => 'bg-emerald-600',
                                    'BLOQUANT' => 'bg-rose-600',
                                    default => 'bg-amber-500',
                                } }}" aria-hidden="true"></span>
                                <div>
                                    <p class="fiche-surtitre">Synthèse</p>
                                    <h2 class="text-xl font-bold text-slate-900">Contrôle intelligent : {{ $verification->verdict }}</h2>
                                    <p class="mt-1 text-base text-slate-700">
                                        Score {{ $verification->score }}/100 · {{ $verification->rulesPassed }}/{{ $verification->rulesChecked }} règles validées · {{ $verification->nombreAnomalies() }} anomalie(s)
                                    </p>
                                    <div class="fiche-jauge mt-3 max-w-xs bg-slate-200/80" aria-hidden="true">
                                        <div class="fiche-jauge-remplie" style="--fiche-jauge: {{ max(0, min(100, (int) $verification->score)) }}%"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-4">
                                <a href="{{ route('admin.verification.show', $etudiant) }}" class="text-base font-bold text-slate-800 underline underline-offset-2">
                                    Rapport détaillé
                                </a>
                                <form method="post" action="{{ route('admin.verification.verifier', $etudiant) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center rounded-xl bg-slate-900 px-5 py-2.5 text-base font-bold text-white transition hover:bg-slate-700 min-h-[44px] dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200">
                                        Relancer
                                    </button>
                                </form>
                            </div>
                        </div>
                    </section>
                @endif

                {{-- Anomalies à traiter : liste de travail, à part du verdict --}}
                @if(!empty($anomaliesFiche))
                    <section class="rounded-3xl border border-amber-200/70 bg-amber-50/60 p-6" aria-label="Anomalies à traiter" data-motion-block>
                        <p class="fiche-surtitre">Contrôle qualité</p>
                        <h2 class="text-xl font-bold text-slate-900">Anomalies à traiter ({{ count($anomaliesFiche) }})</h2>
                        <ul class="mt-3 space-y-2.5 text-base text-slate-700">
                            @foreach($anomaliesFiche as $anomalie)
                                <li class="flex flex-wrap items-center gap-2">
                                    <svg class="h-4 w-4 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                                    </svg>
                                    <span>{{ $anomalie['message'] }}</span>
                                    <a href="{{ $anomalie['lien'] }}" class="font-bold underline underline-offset-2">{{ $anomalie['action'] }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                {{-- Doublons --}}
                @if($doublons->isNotEmpty())
                    <section class="rounded-3xl bg-rose-50 p-6 ring-1 ring-inset ring-rose-200/70" role="alert" aria-label="Doublons potentiels" data-motion-block>
                        <h2 class="text-xl font-bold text-rose-900">Doublons potentiels</h2>
                        <p class="mt-1 text-base text-rose-900">Comparaison humaine requise, jamais de fusion automatique.</p>
                        <ul class="mt-3 space-y-1.5 text-base text-rose-800">
                            @foreach($doublons as $doublon)
                                <li>
                                    <a href="{{ route('admin.etudiants.show', $doublon) }}" class="font-bold underline underline-offset-2">{{ $doublon->nom_complet }}</a>
                                    <span class="tabular-nums"> · {{ $doublon->cin }} · {{ $doublon->email }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                {{-- Complétude du dossier --}}
                <section class="rounded-3xl border border-slate-200/80 bg-white p-6 shadow-sm card-hover sm:p-8" aria-label="Complétude du dossier" data-motion-block>
                    <p class="fiche-surtitre">Avancement</p>
                    <h2 class="text-xl font-bold text-slate-900">Dossier complet à {{ $completude['pourcentage'] }} %</h2>
                    <div class="fiche-jauge mt-4 max-w-xl" aria-hidden="true">
                        <div class="fiche-jauge-remplie" style="--fiche-jauge: {{ $completude['pourcentage'] }}%"></div>
                    </div>
                    <ul class="mt-6 grid grid-cols-1 gap-x-10 gap-y-2.5 text-base sm:grid-cols-2">
                        @foreach($completude['items'] as $item)
                            <li class="flex items-center gap-2.5 {{ $item['ok'] ? 'text-slate-700' : 'font-bold text-amber-800' }}">
                                <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full {{ $item['ok'] ? 'bg-emerald-700 text-white' : 'bg-amber-500 text-white' }}" aria-hidden="true">@if($item['ok'])<svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>@else<svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>@endif</span>
                                <span class="{{ $item['ok'] ? '' : 'font-semibold text-amber-900' }}">{{ $item['libelle'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>

                {{-- Éligibilité & affectation --}}
                @if(isset($ficheEligibilite))
                    <section class="rounded-3xl border border-slate-200/80 bg-white p-6 shadow-sm card-hover sm:p-8" aria-label="Éligibilité et affectation" data-motion-block>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="fiche-surtitre">Candidature</p>
                                <h2 class="text-xl font-bold text-slate-900">Éligibilité & affectation</h2>
                            </div>
                            <span class="rounded-full px-3 py-1 text-sm font-bold
                                @if($ficheEligibilite['statut_affectation']['badge'] === 'emerald') bg-emerald-100 text-emerald-900
                                @elseif($ficheEligibilite['statut_affectation']['badge'] === 'sky') bg-sky-100 text-sky-900
                                @elseif($ficheEligibilite['statut_affectation']['badge'] === 'rose') bg-rose-100 text-rose-900
                                @else bg-slate-200 text-slate-800 dark:bg-slate-800 dark:text-slate-100 @endif">
                                {{ $ficheEligibilite['statut_affectation']['label'] }}
                            </span>
                        </div>
                        <p class="mt-1 text-base text-slate-600">Contrôle des 5 piliers administratifs et affectation de groupe.</p>

                        <dl class="mt-5">
                            @foreach($ficheEligibilite['axes'] as $axe)
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-slate-100 py-2.5 last:border-b-0">
                                    <dt class="flex items-center gap-2 text-base font-semibold text-slate-900">
                                        <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $axe['badge'] === 'emerald' ? 'bg-emerald-600' : ($axe['badge'] === 'amber' ? 'bg-amber-500' : ($axe['badge'] === 'sky' ? 'bg-sky-600' : ($axe['badge'] === 'rose' ? 'bg-rose-600' : 'bg-slate-500'))) }}" aria-hidden="true"></span>
                                        {{ $axe['label'] }}
                                    </dt>
                                    <dd class="text-base text-slate-700">
                                        {{ $axe['texte'] }}@if(!empty($axe['detail']))<span class="text-slate-500"> — {{ $axe['detail'] }}</span>@endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>

                        @if(!empty($ficheEligibilite['blocking_reasons']))
                            <div class="mt-5 rounded-2xl bg-rose-100 p-4" role="alert">
                                <p class="text-base font-bold text-rose-900">Affectation bloquée ({{ count($ficheEligibilite['blocking_reasons']) }} anomalie(s))</p>
                                <ul class="mt-1.5 list-inside list-disc space-y-0.5 text-base text-rose-800">
                                    @foreach($ficheEligibilite['blocking_reasons'] as $motif)
                                        <li>{{ $motif }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </section>
                @endif

                {{-- Finances --}}
                @if($soldeFinance !== null)
                    <section class="rounded-3xl border border-slate-200/80 bg-white p-6 shadow-sm card-hover sm:p-8" aria-label="Situation financière" data-motion-block>
                        <p class="fiche-surtitre">Facultés & finances</p>
                        <h2 class="text-xl font-bold text-slate-900">Situation financière</h2>
                        <dl class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-inset ring-slate-100">
                                <dt class="text-[13px] font-semibold text-slate-500">Total</dt>
                                <dd class="mt-1 text-xl font-black tabular-nums text-slate-900">{{ \App\Modules\Finances\Models\Facture::formater($soldeFinance['total']) }}</dd>
                            </div>
                            <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-inset ring-slate-100">
                                <dt class="text-[13px] font-semibold text-slate-500">Payé</dt>
                                <dd class="mt-1 text-xl font-black tabular-nums text-slate-900">{{ \App\Modules\Finances\Models\Facture::formater($soldeFinance['paye']) }}</dd>
                            </div>
                            <div class="rounded-2xl bg-primary-50 p-4 ring-1 ring-inset ring-primary-100">
                                <dt class="text-[13px] font-semibold text-primary-700">Reste</dt>
                                <dd class="mt-1 text-xl font-black tabular-nums text-primary-900">{{ \App\Modules\Finances\Models\Facture::formater($soldeFinance['reste']) }}</dd>
                            </div>
                        </dl>
                    </section>
                @endif

                {{-- Activité récente --}}
                <section class="rounded-3xl border border-slate-200/80 bg-white p-6 shadow-sm card-hover sm:p-8" aria-label="Activité récente" data-motion-block>
                    <p class="fiche-surtitre">Journal</p>
                    <h2 class="text-xl font-bold text-slate-900">Activité récente</h2>
                    <div class="mt-4">
                        <x-timeline :elements="$timeline" />
                    </div>
                </section>

                <div class="flex flex-wrap gap-2">
                    @if($etudiant->groupe_id)
                        <a href="{{ route('admin.emplois-du-temps.explorer', ['groupe_id' => $etudiant->groupe_id]) }}" class="rounded-xl bg-slate-900 px-4 py-2.5 text-base font-bold text-white transition hover:bg-slate-700 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200">Voir le planning du groupe</a>
                    @endif
                    <a href="{{ route('admin.etudiants.show', $etudiant) }}#parcours" class="rounded-xl px-4 py-2.5 text-base font-bold text-slate-800 underline underline-offset-2">Gérer le parcours</a>
                </div>
            </div>
        </x-slot:panneau_synthese>

        <x-slot:panneau_infos>
            <div class="rounded-3xl border border-slate-200/80 bg-white p-6 shadow-sm sm:p-8" data-motion-block>
                <p class="fiche-surtitre">Identité</p>
                <h2 class="text-xl font-bold text-slate-900">Informations déclarées</h2>
                <div class="mt-6 grid grid-cols-1 gap-x-10 gap-y-8 md:grid-cols-3">
                    {{-- État civil --}}
                    <section aria-label="État civil et contact">
                        <h3 class="text-base font-bold text-slate-900">État civil & contact</h3>
                        <dl class="mt-3 space-y-3 text-base">
                            <div>
                                <dt class="text-slate-500">Nom & prénom</dt>
                                <dd class="mt-0.5 font-semibold text-slate-900">{{ $etudiant->nom_complet }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Numéro de CIN</dt>
                                <dd class="mt-0.5 font-semibold tabular-nums text-slate-900">{{ $etudiant->cin }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Sexe</dt>
                                <dd class="mt-0.5 text-slate-800">{{ $etudiant->sexe === 'M' ? 'Masculin' : 'Féminin' }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Date de naissance</dt>
                                <dd class="mt-0.5 tabular-nums text-slate-800">{{ $etudiant->date_naissance ? $etudiant->date_naissance->format('d/m/Y') : 'Non renseignée' }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Adresse email</dt>
                                <dd class="mt-0.5 break-all"><a href="mailto:{{ $etudiant->email }}" class="font-semibold text-primary-700 underline underline-offset-2">{{ $etudiant->email }}</a></dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Téléphone</dt>
                                <dd class="mt-0.5 tabular-nums text-slate-800">
                                    @if($etudiant->telephone)
                                        <a href="tel:{{ $etudiant->telephone }}" class="underline underline-offset-2">{{ $etudiant->telephone }}</a>
                                    @else
                                        <span class="text-slate-500">Non renseigné</span>
                                    @endif
                                </dd>
                            </div>
                        </dl>
                    </section>

                    {{-- Résidence & bac --}}
                    <section aria-label="Résidence et baccalauréat">
                        <h3 class="text-base font-bold text-slate-900">Résidence & baccalauréat</h3>
                        <dl class="mt-3 space-y-3 text-base">
                            <div>
                                <dt class="text-slate-500">Ville de résidence</dt>
                                <dd class="mt-0.5 font-semibold text-slate-900">{{ $etudiant->ville }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Code postal</dt>
                                <dd class="mt-0.5 tabular-nums text-slate-800">{{ $etudiant->code_postal }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Adresse postale</dt>
                                <dd class="mt-0.5 leading-relaxed text-slate-800">{{ $etudiant->adresse }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Session du baccalauréat</dt>
                                <dd class="mt-0.5 font-bold text-slate-900">Session {{ $etudiant->annee_bac }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Mention obtenue</dt>
                                <dd class="mt-1"><span class="rounded-full bg-primary-100 px-3 py-1 text-sm font-bold text-primary-900">{{ $etudiant->mention_bac ?: 'Très Bien' }}</span></dd>
                            </div>
                        </dl>
                    </section>

                    {{-- Affectation universitaire --}}
                    <section aria-label="Affectation universitaire">
                        <h3 class="text-base font-bold text-slate-900">Affectation universitaire</h3>
                        <dl class="mt-3 space-y-3 text-base">
                            <div>
                                <dt class="text-slate-500">Faculté d'accueil</dt>
                                <dd class="mt-0.5 font-semibold leading-snug text-slate-900">
                                    {{ $etudiant->faculte->nom }}
                                    <span class="block text-slate-500">Code {{ $etudiant->faculte->code }}</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Niveau d'études</dt>
                                <dd class="mt-1"><span class="rounded-full bg-primary-100 px-3 py-1 text-sm font-bold text-primary-900">{{ $etudiant->niveau }}</span></dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Année académique</dt>
                                <dd class="mt-0.5 font-semibold text-slate-900">{{ $etudiant->annee_academique }}</dd>
                            </div>
                            @if($derniereInscription !== null)
                                <div>
                                    <dt class="text-slate-500">Paiement (site externe)</dt>
                                    <dd class="mt-1">
                                        <span class="rounded-full px-3 py-1 text-sm font-bold {{ $derniereInscription->paye ? 'bg-emerald-100 text-emerald-900' : 'bg-amber-100 text-amber-900' }}">
                                            {{ $derniereInscription->paye ? 'Oui — effectué' : 'Non — non effectué' }}
                                        </span>
                                    </dd>
                                </div>
                            @endif
                            <div>
                                <dt class="text-slate-500">Date de dépôt du dossier</dt>
                                <dd class="mt-0.5 tabular-nums text-slate-800">{{ $etudiant->created_at->format('d/m/Y à H:i') }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">Dernière modification</dt>
                                <dd class="mt-0.5 tabular-nums text-slate-800">{{ $etudiant->updated_at->format('d/m/Y à H:i') }}</dd>
                            </div>
                            @if($etudiant->groupe_id)
                                <div>
                                    <dt class="text-slate-500">Groupe</dt>
                                    <dd class="mt-0.5 font-semibold tabular-nums text-slate-900">{{ $etudiant->groupe->code }} — {{ $etudiant->groupe->nom }}</dd>
                                </div>
                            @endif
                        </dl>
                    </section>
                </div>
            </div>
        </x-slot:panneau_infos>

        <x-slot:panneau_parcours>
            <div class="space-y-8">
                <section class="rounded-3xl border border-slate-200/80 bg-white p-6 shadow-sm sm:p-8" aria-label="Situation actuelle" data-motion-block>
                    <p class="fiche-surtitre">Aujourd'hui</p>
                    <h2 class="text-xl font-bold text-slate-900">Situation actuelle</h2>
                    <dl class="mt-4 grid grid-cols-1 gap-x-10 gap-y-4 text-base sm:grid-cols-3">
                        <div><dt class="text-slate-500">Programme</dt><dd class="mt-0.5 font-semibold text-slate-900">{{ $etudiant->programmeRef?->nom ?? '—' }}</dd></div>
                        <div><dt class="text-slate-500">Niveau</dt><dd class="mt-0.5 font-semibold text-slate-900">{{ $etudiant->niveauRef?->code ?? $etudiant->niveau }}</dd></div>
                        <div><dt class="text-slate-500">Groupe</dt><dd class="mt-0.5 font-semibold text-slate-900">{{ $etudiant->groupe?->code ?? '—' }}</dd></div>
                    </dl>
                    @if($etudiant->estSuspendu())
                        <p class="mt-4 rounded-2xl bg-rose-100 p-4 text-base font-bold text-rose-900" role="alert">Suspension active : les opérations académiques sont bloquées.</p>
                    @endif
                </section>

                {{-- Progression LMD --}}
                @if($derniereInscription !== null && $progression !== null)
                    <section class="rounded-3xl border border-slate-200/80 bg-white p-6 shadow-sm sm:p-8" aria-label="Progression LMD" data-motion-block>
                        <p class="fiche-surtitre">Parcours</p>
                        <h2 class="text-xl font-bold text-slate-900">Progression LMD</h2>
                        <dl class="mt-4 grid grid-cols-1 gap-x-10 gap-y-4 text-base sm:grid-cols-3">
                            <div><dt class="text-slate-500">Niveau actuel</dt><dd class="mt-0.5 font-semibold text-slate-900">{{ $derniereInscription->niveauRef?->code ?? $derniereInscription->niveau }} ({{ $derniereInscription->anneeUniversitaire?->libelle }})</dd></div>
                            <div><dt class="text-slate-500">Décision jury</dt><dd class="mt-0.5 font-semibold text-slate-900">{{ $progression['decision'] ?? '—' }}</dd></div>
                            <div><dt class="text-slate-500">Niveau cible</dt><dd class="mt-0.5 font-semibold text-slate-900">{{ $progression['niveau_cible']?->code ?? '—' }}{{ $progression['annee_cible'] ? ' · ' . $progression['annee_cible']->libelle : '' }}</dd></div>
                        </dl>
                        @if($progression['existante'] !== null)
                            <p class="mt-4 text-base text-slate-600">Inscription {{ $progression['annee_cible']?->libelle }} déjà créée (idempotence).</p>
                        @elseif($progression['peut_progresser'])
                            @can('archives.create')
                                <form method="POST" action="{{ route('admin.inscriptions.progresser', $derniereInscription) }}" class="mt-5 flex flex-wrap items-end gap-2">
                                    @csrf
                                    <input type="hidden" name="confirmer" value="1">
                                    <p class="w-full text-base text-slate-600">Action : {{ $progression['action'] }} — la décision publiée et le référentiel déterminent la cible. Cibles explicites = admission externe validée (ex. Master), jamais devinées.</p>
                                    <select name="programme_id" aria-label="Programme cible (optionnel)" class="max-w-xs">
                                        <option value="">Programme calculé</option>
                                        @foreach($programmesOptions as $programme)
                                            <option value="{{ $programme->id }}">{{ $programme->code }} — {{ $programme->nom }}</option>
                                        @endforeach
                                    </select>
                                    <select name="niveau_id" aria-label="Niveau cible (optionnel)" class="max-w-xs">
                                        <option value="">Niveau calculé</option>
                                        @foreach($niveauxOptions as $niveau)
                                            <option value="{{ $niveau->id }}">{{ $niveau->code }}</option>
                                        @endforeach
                                    </select>
                                    <x-btn type="submit" variant="secondary">Créer l'inscription {{ $progression['annee_cible']?->libelle }}</x-btn>
                                </form>
                            @endcan
                        @else
                            <p class="mt-4 text-base font-bold text-amber-800">{{ implode(' ', $progression['blocages']) }}</p>
                        @endif
                        @if($errors->has('progression'))
                            <p class="mt-2 text-base font-bold text-rose-700">{{ $errors->first('progression') }}</p>
                        @endif
                    </section>
                @endif

                @can('archives.update')
                    <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
                        <section class="rounded-3xl border border-slate-200/80 bg-white p-6 shadow-sm sm:p-8" aria-label="Affecter ou changer de groupe" data-motion-block>
                            <form method="POST" action="{{ route('admin.etudiants.affectations.store', $etudiant) }}" class="space-y-3">
                                @csrf
                                <p class="fiche-surtitre">Groupes</p>
                                <h2 class="text-xl font-bold text-slate-900">Affecter / changer de groupe</h2>
                                <select name="groupe_id" required aria-label="Groupe cible">
                                    @foreach($groupesOptions as $groupe)
                                        <option value="{{ $groupe->id }}">{{ $groupe->code }}{{ $groupe->capacite ? ' (' . $groupe->effectif_actuel . '/' . $groupe->capacite . ')' : '' }}</option>
                                    @endforeach
                                </select>
                                <select name="annee_universitaire_id" required aria-label="Année">
                                    @foreach($anneesOptions as $annee)
                                        <option value="{{ $annee->id }}">{{ $annee->libelle }}</option>
                                    @endforeach
                                </select>
                                <input name="motif" required maxlength="255" placeholder="Motif (obligatoire)">
                                <div class="flex flex-wrap items-center gap-3">
                                    <x-btn type="submit" variant="secondary">Affecter (historisé)</x-btn>
                                    <button type="submit" formmethod="GET" formaction="{{ route('admin.etudiants.affectations.previsualiser', $etudiant) }}" formnovalidate
                                        class="inline-flex items-center rounded-xl px-4 py-2 text-base font-bold text-slate-800 underline underline-offset-2 min-h-[44px]">
                                        Prévisualiser (contraintes + impacts)
                                    </button>
                                </div>
                            </form>
                        </section>

                        <section class="rounded-3xl border border-slate-200/80 bg-white p-6 shadow-sm sm:p-8" aria-label="Suspendre, transférer ou rattacher" data-motion-block>
                            <p class="fiche-surtitre">Opérations</p>
                            <h2 class="text-xl font-bold text-slate-900">Suspendre, transférer, rattacher</h2>
                            <div class="mt-6 space-y-8">
                                <div>
                                    <h3 class="text-base font-bold text-slate-900">Suspendre</h3>
                                    <form method="POST" action="{{ route('admin.etudiants.suspendre', $etudiant) }}" class="mt-3 space-y-3">
                                        @csrf
                                        <div class="grid grid-cols-2 gap-2">
                                            <input type="date" name="date_debut" required aria-label="Début">
                                            <input type="date" name="date_fin" aria-label="Fin prévue">
                                        </div>
                                        <input name="motif" required maxlength="255" placeholder="Motif (obligatoire)">
                                        <x-btn type="submit" variant="danger">Suspendre</x-btn>
                                    </form>
                                </div>
                                <div>
                                    <h3 class="text-base font-bold text-slate-900">Transférer (programme)</h3>
                                    <form method="POST" action="{{ route('admin.etudiants.transferer', $etudiant) }}" class="mt-3 space-y-3">
                                        @csrf
                                        <select name="programme_id" required aria-label="Programme cible">
                                            @foreach($programmesOptions as $programme)
                                                <option value="{{ $programme->id }}">{{ $programme->code }} — {{ $programme->nom }}</option>
                                            @endforeach
                                        </select>
                                        <input name="motif" required maxlength="255" placeholder="Motif (obligatoire)">
                                        <x-btn type="submit" variant="secondary">Transférer (dossier conservé)</x-btn>
                                    </form>
                                </div>
                                <div>
                                    <h3 class="text-base font-bold text-slate-900">Rattachement LMD</h3>
                                    <form method="POST" action="{{ route('admin.etudiants.rattachement', $etudiant) }}" class="mt-3 space-y-3">
                                        @csrf @method('PATCH')
                                        <select name="programme_id" aria-label="Programme">
                                            <option value="">— Programme —</option>
                                            @foreach($programmesOptions as $programme)
                                                <option value="{{ $programme->id }}" @selected($etudiant->programme_id == $programme->id)>{{ $programme->code }}</option>
                                            @endforeach
                                        </select>
                                        <select name="niveau_id" aria-label="Niveau">
                                            <option value="">— Niveau —</option>
                                            @foreach($niveauxOptions as $niveau)
                                                <option value="{{ $niveau->id }}" @selected($etudiant->niveau_id == $niveau->id)>{{ $niveau->programme->code }} · {{ $niveau->code }}</option>
                                            @endforeach
                                        </select>
                                        <x-btn type="submit" variant="secondary">Rattacher</x-btn>
                                    </form>
                                </div>
                            </div>
                        </section>
                    </div>
                @endcan

                <section class="rounded-3xl border border-slate-200/80 bg-white p-6 shadow-sm sm:p-8" aria-label="Historique des situations" data-motion-block>
                    <p class="fiche-surtitre">Traçabilité</p>
                    <h2 class="text-xl font-bold text-slate-900">Historique ({{ $etudiant->situations->count() }} événements)</h2>
                    @if($etudiant->situations->isNotEmpty())
                        <ol class="mt-4 space-y-4">
                            @foreach($etudiant->situations as $situation)
                                <li class="border-l-2 border-slate-200 pl-4 text-base">
                                    <strong class="font-bold text-slate-900">{{ $situation->type }}</strong>
                                    @if($situation->ancienne_valeur || $situation->nouvelle_valeur)
                                        <span class="text-slate-600"> · {{ $situation->ancienne_valeur ?? '—' }} → {{ $situation->nouvelle_valeur ?? '—' }}</span>
                                    @endif
                                    @if($situation->motif)<span class="mt-0.5 block text-slate-600">{{ $situation->motif }}</span>@endif
                                    <span class="mt-0.5 block tabular-nums text-slate-500">{{ $situation->date_effet->format('d/m/Y') }}</span>
                                </li>
                            @endforeach
                        </ol>
                    @else
                        <p class="mt-3 text-base text-slate-600">Aucun événement historisé.</p>
                    @endif
                </section>

                <section class="rounded-3xl border border-slate-200/80 bg-white p-6 shadow-sm sm:p-8" aria-label="Affectations" data-motion-block>
                    <p class="fiche-surtitre">Groupes</p>
                    <h2 class="text-xl font-bold text-slate-900">Affectations ({{ $etudiant->affectations->count() }})</h2>
                    @if($etudiant->affectations->isNotEmpty())
                        <ul class="mt-4 space-y-4">
                            @foreach($etudiant->affectations as $affectation)
                                <li class="border-l-2 border-primary-200 pl-4 text-base">
                                    <strong class="font-bold tabular-nums text-slate-900">{{ $affectation->groupe->code }}</strong>
                                    <span class="text-slate-600">{{ $affectation->anneeUniversitaire->libelle }} · {{ $affectation->statut }}</span>
                                    <span class="mt-0.5 block tabular-nums text-slate-500">{{ $affectation->date_debut->format('d/m/Y') }}{{ $affectation->date_fin ? ' → ' . $affectation->date_fin->format('d/m/Y') : '' }}</span>
                                    @if($affectation->statut === 'active')
                                        @can('archives.update')
                                            <form method="POST" action="{{ route('admin.etudiants.affectations.retirer', $etudiant) }}" class="mt-2 flex gap-2">
                                                @csrf
                                                <input type="hidden" name="affectation_id" value="{{ $affectation->id }}" />
                                                <input name="motif" required maxlength="255" placeholder="Motif du retrait" aria-label="Motif du retrait" />
                                                <button type="submit" class="rounded-xl px-3 py-1.5 font-bold text-rose-700 underline underline-offset-2">Retirer</button>
                                            </form>
                                        @endcan
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-3 text-base text-slate-600">Aucune affectation historisée.</p>
                    @endif
                </section>
            </div>
        </x-slot:panneau_parcours>

        <x-slot:panneau_services>
            <div class="no-print">
                <h2 class="text-xl font-bold text-slate-900">Dossier & services liés</h2>
                <ul class="mt-4 space-y-3 text-base">
                    @if(app(\App\Support\Modules\ModuleRegistry::class)->estActif('student-dossiers'))
                        <li>
                            <a href="{{ route('admin.student-dossiers.index', ['search' => $etudiant->cin]) }}" class="group flex items-center justify-between gap-3 rounded-2xl border border-slate-200/80 bg-white px-5 py-4 font-bold text-primary-800 shadow-sm transition hover:border-primary-200 hover:shadow-md">
                                <span>Dossier administratif</span>
                                <span class="text-primary-600 transition-transform group-hover:translate-x-1" aria-hidden="true">→</span>
                            </a>
                        </li>
                    @endif
                    @if(app(\App\Support\Modules\ModuleRegistry::class)->estActif('online-requests'))
                        <li>
                            <a href="{{ route('admin.demandes.index', ['search' => $etudiant->cin]) }}" class="group flex items-center justify-between gap-3 rounded-2xl border border-slate-200/80 bg-white px-5 py-4 font-bold text-primary-800 shadow-sm transition hover:border-primary-200 hover:shadow-md">
                                <span>Demandes — voir</span>
                                <span class="text-primary-600 transition-transform group-hover:translate-x-1" aria-hidden="true">→</span>
                            </a>
                        </li>
                    @endif
                    @if(app(\App\Support\Modules\ModuleRegistry::class)->estActif('schedule') && $etudiant->groupe_id)
                        <li>
                            <a href="{{ route('admin.emplois-du-temps.index') }}" class="group flex items-center justify-between gap-3 rounded-2xl border border-slate-200/80 bg-white px-5 py-4 font-bold text-primary-800 shadow-sm transition hover:border-primary-200 hover:shadow-md">
                                <span>Emploi du temps du groupe {{ $etudiant->groupe->code }}</span>
                                <span class="text-primary-600 transition-transform group-hover:translate-x-1" aria-hidden="true">→</span>
                            </a>
                        </li>
                    @endif
                </ul>
            </div>
        </x-slot:panneau_services>
    </x-tabs>
</div>
@endsection

@extends('layouts.app')

@section('title', 'Suivi de dossier d\'inscription — Consultation officielle')
@section('meta_description', 'Interrogez l’état d’avancement de votre demande d’inscription universitaire avec votre numéro officiel.')

@section('content')
<div class="mx-auto max-w-4xl px-4 sm:px-6 py-8 sm:py-12 space-y-8">

    {{-- En-tête de page --}}
    <div class="text-center space-y-2" data-motion-hero>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-primary-100 border border-primary-300 px-3 py-0.5 text-xs font-bold text-primary-900" data-motion-hero-item>
            Consultation sécurisée
        </span>
        <h1 class="text-2xl sm:text-4xl font-black tracking-tight text-slate-900" data-motion-hero-item>
            Suivi de votre dossier d'inscription
        </h1>
        <p class="text-xs sm:text-sm text-slate-500 max-w-xl mx-auto" data-motion-hero-item>
            Renseignez votre numéro d'inscription officiel (remis lors de votre dépôt) ainsi que votre CIN ou date de naissance pour consulter l'état d'instruction.
        </p>
    </div>

    {{-- Formulaire de recherche sécurisé --}}
    <div class="rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm" data-reveal="fade-up">
        <form action="{{ route('inscription.suivi.rechercher') }}" method="POST" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="reference" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-700">
                        Numéro d'inscription officiel <span class="text-rose-600 font-bold">*</span>
                    </label>
                    <input type="text"
                           id="reference"
                           name="reference"
                           value="{{ old('reference', request('reference')) }}"
                           placeholder="Ex: INS-{{ date('Y') }}-00001"
                           required
                           class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 font-mono text-sm uppercase text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('reference') border-rose-400 bg-rose-50/50 @enderror">
                    @error('reference')
                        <p class="mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="secondaire" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-700">
                        CIN ou Date de naissance <span class="text-rose-600 font-bold">*</span>
                    </label>
                    <input type="text"
                           id="secondaire"
                           name="secondaire"
                           value="{{ old('secondaire', request('secondaire')) }}"
                           placeholder="Ex: AB123456 ou 15/06/2005"
                           required
                           class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('secondaire') border-rose-400 bg-rose-50/50 @enderror">
                    @error('secondaire')
                        <p class="mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="flex flex-col sm:flex-row items-center justify-between gap-3 pt-2">
                <p class="text-[11px] text-slate-400 text-center sm:text-left">
                    🔒 Confidentialité garantie : vos identifiants sont contrôlés conjointement sans révéler d'informations à des tiers.
                </p>
                <button type="submit"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-primary-800 px-6 py-3 text-xs sm:text-sm font-bold text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[44px]">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                    Rechercher mon dossier
                </button>
            </div>
        </form>
    </div>

    {{-- Réponse neutre en cas d'erreur / dossier introuvable (anti-énumération) --}}
    @if(!empty($erreurNeutre))
        <div class="rounded-2xl border border-slate-300 bg-slate-50/80 p-5 text-center space-y-2" role="alert">
            <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-slate-200 text-slate-600 font-bold">ℹ</span>
            <p class="text-xs sm:text-sm font-bold text-slate-800">
                {{ $erreurNeutre }}
            </p>
            <div class="pt-2">
                <a href="{{ route('etudiant.connexion.show') }}"
                   class="inline-flex items-center gap-1.5 text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">
                    Se connecter avec mon mot de passe à l'Espace Étudiant →
                </a>
            </div>
        </div>
    @endif

    {{-- Résultat de consultation réussi --}}
    @if($suivi !== null)
        <div class="space-y-6 pt-2" data-reveal="fade-up">
            <div class="rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm space-y-6">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-4 border-b border-slate-100">
                    <div>
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold border {{ $suivi['statut_badge_class'] }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $suivi['statut_brut'] === 'valide' ? 'bg-emerald-500' : ($suivi['statut_brut'] === 'rejete' ? 'bg-rose-500' : 'bg-amber-500') }}" aria-hidden="true"></span>
                            {{ $suivi['statut_libelle'] }}
                        </span>
                        <h2 class="mt-2 text-xl sm:text-2xl font-black text-slate-900">
                            Candidature : {{ $suivi['etudiant']['nom_complet'] }}
                        </h2>
                        <p class="text-xs text-slate-500 font-mono mt-0.5">
                            Réf. {{ $suivi['reference'] }} · {{ $suivi['faculte_nom'] }}
                        </p>
                    </div>

                    <div class="text-left sm:text-right text-xs text-slate-500">
                        <span class="block text-slate-400">Dernière mise à jour</span>
                        <strong class="text-slate-800 font-semibold">{{ $suivi['derniere_mise_a_jour'] }}</strong>
                    </div>
                </div>

                {{-- Action / État --}}
                @php $action = $suivi['prochaine_action']; @endphp
                <div class="rounded-2xl border p-4 sm:p-5
                     {{ $action['type'] === 'action_requise' ? 'border-amber-300 bg-amber-50/70 text-amber-950' : ($action['type'] === 'succes' ? 'border-emerald-300 bg-emerald-50/70 text-emerald-950' : ($action['type'] === 'rejet' ? 'border-rose-300 bg-rose-50/70 text-rose-950' : 'border-sky-200 bg-sky-50/60 text-sky-950')) }}">
                    <h3 class="text-sm font-bold">{{ $action['titre'] }}</h3>
                    <p class="mt-1 text-xs leading-relaxed opacity-90">{{ $action['description'] }}</p>
                </div>

                {{-- Chronologie abrégée --}}
                <div>
                    <h3 class="text-sm font-bold text-slate-900 mb-4">Progression d'instruction</h3>
                    <ol class="space-y-4 border-l-2 border-slate-200 ml-3 pl-5 text-xs">
                        @foreach($suivi['chronologie'] as $etape)
                            @php
                                $estFini = $etape['statut'] === 'complete' || $etape['statut'] === 'valide';
                                $estEnCours = $etape['statut'] === 'en_cours';
                                $estAction = $etape['statut'] === 'action_requise';
                            @endphp
                            <li class="relative">
                                <span class="absolute -left-[27px] top-0.5 flex h-4 w-4 items-center justify-center rounded-full text-[9px] font-bold text-white
                                      {{ $estFini ? 'bg-emerald-600' : ($estEnCours ? 'bg-sky-600 animate-pulse' : ($estAction ? 'bg-amber-600' : 'bg-slate-300')) }}">
                                    {{ $estFini ? '✓' : ($estAction ? '!' : '·') }}
                                </span>
                                <div class="flex items-center justify-between gap-2">
                                    <strong class="text-slate-900">{{ $etape['titre'] }}</strong>
                                    @if($etape['date'])
                                        <span class="text-[10px] text-slate-400">{{ $etape['date'] }}</span>
                                    @endif
                                </div>
                                <p class="text-slate-500 mt-0.5">{{ $etape['description'] }}</p>
                            </li>
                        @endforeach
                    </ol>
                </div>

                <div class="pt-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
                    <p class="text-slate-500">Pour téléverser des documents ou consulter l'intégralité des détails :</p>
                    <a href="{{ route('etudiant.connexion.show') }}"
                       class="inline-flex items-center gap-1.5 rounded-xl bg-primary-800 px-4 py-2.5 font-bold text-white shadow-xs hover:bg-primary-700 transition min-h-[40px]">
                        Accéder à mon Espace Étudiant →
                    </a>
                </div>
            </div>
        </div>
    @endif

</div>
@endsection

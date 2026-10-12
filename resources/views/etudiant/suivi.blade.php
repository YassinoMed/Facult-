@extends('layouts.app')

@section('title', 'Suivi de mon dossier — Espace Étudiant')
@section('meta_description', 'Consultez l’état d’avancement de votre dossier d’inscription, la chronologie d’instruction et vos prochaines actions.')

@section('content')
<div class="mx-auto max-w-5xl px-4 sm:px-6 py-6 sm:py-8 space-y-6">

    {{-- En-tête officiel du suivi --}}
    <div class="relative overflow-hidden rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold border {{ $suivi['statut_badge_class'] }}">
                        <span class="h-2 w-2 rounded-full {{ $suivi['statut_brut'] === 'valide' ? 'bg-emerald-500' : ($suivi['statut_brut'] === 'rejete' ? 'bg-rose-500' : 'bg-amber-500 animate-pulse') }}" aria-hidden="true"></span>
                        {{ $suivi['statut_libelle'] }}
                    </span>
                    <span class="font-mono text-xs font-bold text-slate-500">
                        Réf. {{ $suivi['reference'] }}
                    </span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
                    Suivi de mon dossier d'inscription
                </h1>
                <p class="mt-1 text-xs sm:text-sm text-slate-500">
                    {{ $suivi['faculte_nom'] }} · {{ $suivi['programme_nom'] }} ({{ $suivi['niveau'] }}) · Année {{ $suivi['annee_academique'] }}
                </p>
            </div>

            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-2.5">
                @if($suivi['statut_brut'] === 'valide')
                    <a href="{{ route('etudiant.recepisse') }}"
                       class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-emerald-700 transition-smooth min-h-[44px]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.5V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                        </svg>
                        Télécharger le récépissé officiel
                    </a>
                @endif
                <a href="{{ route('etudiant.dashboard') }}"
                   class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50 transition-smooth min-h-[40px]">
                    ← Tableau de bord
                </a>
            </div>
        </div>

        {{-- Barre d'état secondaire --}}
        <div class="mt-6 pt-4 border-t border-slate-100 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
            <div>
                <span>Date de dépôt : </span>
                <strong class="text-slate-800">{{ $suivi['date_depot'] }}</strong>
            </div>
            <div>
                <span>Dernière mise à jour : </span>
                <strong class="text-slate-800">{{ $suivi['derniere_mise_a_jour'] }}</strong>
                <span class="text-slate-400">({{ $suivi['derniere_mise_a_jour_diff'] }})</span>
            </div>
        </div>
    </div>

    {{-- Carte d'action prioritaire --}}
    @php $action = $suivi['prochaine_action']; @endphp
    <div class="rounded-2xl border p-5 sm:p-6 transition-smooth
         {{ $action['type'] === 'action_requise' ? 'border-amber-300 bg-amber-50/70 text-amber-950' : ($action['type'] === 'succes' ? 'border-emerald-300 bg-emerald-50/70 text-emerald-950' : ($action['type'] === 'rejet' ? 'border-rose-300 bg-rose-50/70 text-rose-950' : 'border-sky-200 bg-sky-50/60 text-sky-950')) }}">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-xl text-white font-bold
                      {{ $action['type'] === 'action_requise' ? 'bg-amber-600' : ($action['type'] === 'succes' ? 'bg-emerald-600' : ($action['type'] === 'rejet' ? 'bg-rose-600' : 'bg-sky-600')) }}">
                    @if($action['type'] === 'action_requise')
                        !
                    @elseif($action['type'] === 'succes')
                        ✓
                    @elseif($action['type'] === 'rejet')
                        ✕
                    @else
                        ℹ
                    @endif
                </span>
                <div>
                    <span class="text-[11px] font-black uppercase tracking-wider opacity-75">
                        {{ $action['badge'] }}
                    </span>
                    <h2 class="text-base sm:text-lg font-black tracking-tight">
                        {{ $action['titre'] }}
                    </h2>
                    <p class="mt-1 text-xs sm:text-sm leading-relaxed opacity-90">
                        {{ $action['description'] }}
                    </p>
                </div>
            </div>

            @if($action['lien'] && $action['libelle_bouton'])
                <div class="flex-shrink-0">
                    <a href="{{ $action['lien'] }}"
                       class="inline-flex items-center gap-2 rounded-xl px-5 py-3 text-xs sm:text-sm font-bold text-white shadow-sm transition-smooth min-h-[44px]
                              {{ $action['type'] === 'action_requise' ? 'bg-amber-700 hover:bg-amber-800' : 'bg-emerald-700 hover:bg-emerald-800' }}">
                        {{ $action['libelle_bouton'] }} →
                    </a>
                </div>
            @endif
        </div>
    </div>

    {{-- Affectation pédagogique & Groupe (LOOP 14) --}}
    @if(isset($suivi['groupe']) && $suivi['groupe'] !== null)
        <div class="rounded-2xl border p-5 sm:p-6 transition-smooth
             {{ $suivi['groupe']['statut'] === 'affecte' ? 'border-emerald-200 bg-emerald-50/50' : 'border-sky-200 bg-sky-50/40' }}">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl font-bold text-white text-sm
                          {{ $suivi['groupe']['statut'] === 'affecte' ? 'bg-emerald-600' : 'bg-sky-600' }}">
                        @if($suivi['groupe']['statut'] === 'affecte')
                            ✓
                        @else
                            ℹ
                        @endif
                    </span>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-[11px] font-black uppercase tracking-wider text-slate-500">
                                Répartition Pédagogique
                            </span>
                            <span class="rounded-full px-2.5 py-0.5 text-[10px] font-bold border
                                  {{ $suivi['groupe']['statut'] === 'affecte' ? 'bg-emerald-100 text-emerald-800 border-emerald-300' : 'bg-sky-100 text-sky-800 border-sky-300' }}">
                                {{ $suivi['groupe']['statut'] === 'affecte' ? 'Affectation confirmée' : 'Affectation en cours' }}
                            </span>
                        </div>
                        <h3 class="text-base font-black text-slate-900 mt-0.5">
                            @if($suivi['groupe']['statut'] === 'affecte')
                                Groupe d'enseignement : <span class="text-emerald-700">{{ $suivi['groupe']['code'] }}</span>
                            @else
                                Affectation administrative en cours
                            @endif
                        </h3>
                        <p class="text-xs text-slate-600 mt-1">
                            @if($suivi['groupe']['statut'] === 'affecte')
                                Votre groupe officiel a été attribué et confirmé. Vous pouvez consulter les détails de vos enseignements.
                            @else
                                Votre inscription est validée. Le service de scolarité procède à la répartition pédagogique sans surcharger les capacités.
                            @endif
                        </p>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Détail des corrections & pièces requises (si applicable) --}}
    @if($suivi['a_des_corrections'])
        <div class="rounded-2xl border border-amber-300 bg-white p-5 sm:p-6 shadow-xs space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-base font-black text-slate-900">Éléments signalés par la scolarité</h3>
                    <p class="text-xs text-slate-500">Veuillez rectifier ces éléments pour permettre la finalisation de votre dossier.</p>
                </div>
                <span class="rounded-full bg-amber-100 border border-amber-300 px-3 py-1 text-xs font-bold text-amber-900">
                    {{ count($suivi['corrections_demandees']) + count($suivi['pieces_a_corriger']) }} élément(s) à traiter
                </span>
            </div>

            @if(count($suivi['corrections_demandees']) > 0)
                <div class="space-y-2">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500">Informations à corriger</h4>
                    <div class="overflow-x-auto rounded-xl border border-slate-200">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200">
                                <tr>
                                    <th class="px-4 py-2.5">Champ</th>
                                    <th class="px-4 py-2.5">Valeur transmise</th>
                                    <th class="px-4 py-2.5">Motif / Consigne</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($suivi['corrections_demandees'] as $c)
                                    <tr>
                                        <td class="px-4 py-3 font-bold text-slate-900">{{ $c['champ_libelle'] }}</td>
                                        <td class="px-4 py-3 text-slate-600 font-mono">{{ $c['ancienne_valeur'] ?: '—' }}</td>
                                        <td class="px-4 py-3 font-semibold text-amber-800">{{ $c['motif'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if(count($suivi['pieces_a_corriger']) > 0)
                <div class="space-y-2">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500">Pièces justificatives à renouveler</h4>
                    <ul class="space-y-2">
                        @foreach($suivi['pieces_a_corriger'] as $p)
                            <li class="flex items-start justify-between gap-3 rounded-xl border border-rose-200 bg-rose-50/60 p-3 text-xs">
                                <div>
                                    <span class="font-bold text-slate-900 block">{{ $p['titre'] }}</span>
                                    <span class="text-rose-800 font-semibold mt-0.5 block">Motif du rejet : {{ $p['motif'] }}</span>
                                </div>
                                <span class="rounded-md bg-rose-200/80 px-2 py-0.5 text-[10px] font-bold text-rose-900">À remplacer</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="pt-2 flex justify-end">
                <a href="{{ route('etudiant.corrections.index') }}" class="inline-flex items-center gap-2 rounded-xl bg-amber-600 hover:bg-amber-700 px-4 py-2.5 text-xs font-bold text-white shadow-xs transition-smooth min-h-[40px]">
                    Accéder au formulaire de correction et téléversement →
                </a>
            </div>
        </div>
    @endif

    {{-- Chronologie d'instruction --}}
    <div class="rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm">
        <h3 class="text-lg font-black text-slate-900">Chronologie d'instruction du dossier</h3>
        <p class="mt-0.5 text-xs text-slate-500">Chaque étape reflète l'état officiel enregistré par l'établissement.</p>

        <ol class="mt-6 space-y-6 relative border-l-2 border-slate-200 ml-4 sm:ml-6 pl-6 sm:pl-8">
            @foreach($suivi['chronologie'] as $index => $etape)
                @php
                    $estComplete = $etape['statut'] === 'complete' || $etape['statut'] === 'valide';
                    $estEnCours = $etape['statut'] === 'en_cours';
                    $estAction = $etape['statut'] === 'action_requise';
                    $estRejete = $etape['statut'] === 'rejete';
                    $estSuspendue = $etape['statut'] === 'suspendue';
                @endphp
                <li class="relative">
                    {{-- Pastille sur la ligne --}}
                    <span class="absolute -left-[35px] sm:-left-[43px] top-1 flex h-6 w-6 items-center justify-center rounded-full border-2 bg-white text-[11px] font-black
                          {{ $estComplete ? 'border-emerald-600 bg-emerald-600 text-white' : ($estEnCours ? 'border-primary-600 bg-primary-50 text-primary-700 animate-pulse' : ($estAction ? 'border-amber-600 bg-amber-600 text-white' : ($estRejete ? 'border-rose-600 bg-rose-600 text-white' : 'border-slate-300 text-slate-400 bg-white'))) }}">
                        @if($estComplete)
                            ✓
                        @elseif($estAction)
                            !
                        @elseif($estRejete)
                            ✕
                        @else
                            {{ $index + 1 }}
                        @endif
                    </span>

                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1">
                        <h4 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                            {{ $etape['titre'] }}
                            @if($estComplete)
                                <span class="rounded-md bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-800 border border-emerald-200">Terminé</span>
                            @elseif($estEnCours)
                                <span class="rounded-md bg-sky-50 px-2 py-0.5 text-[10px] font-bold text-sky-800 border border-sky-200">En cours</span>
                            @elseif($estAction)
                                <span class="rounded-md bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-900 border border-amber-300">Action requise</span>
                            @elseif($estSuspendue)
                                <span class="rounded-md bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-800 border border-amber-200">Suspendue</span>
                            @elseif($estRejete)
                                <span class="rounded-md bg-rose-50 px-2 py-0.5 text-[10px] font-bold text-rose-800 border border-rose-200">Rejeté</span>
                            @else
                                <span class="rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-500">À venir</span>
                            @endif
                        </h4>
                        @if($etape['date'])
                            <span class="text-[11px] font-medium text-slate-400 tabular-nums">
                                {{ $etape['date'] }}
                            </span>
                        @endif
                    </div>
                    <p class="mt-1 text-xs text-slate-600 leading-relaxed">
                        {{ $etape['description'] }}
                    </p>
                </li>
            @endforeach
        </ol>
    </div>

    {{-- Synthèse des coordonnées déclarées --}}
    <div class="rounded-3xl border border-slate-200/90 bg-slate-50/50 p-6 sm:p-8">
        <h3 class="text-sm font-bold uppercase tracking-wider text-slate-500 mb-4">Informations d'admission enregistrées</h3>
        <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
            <div class="rounded-xl border border-slate-200 bg-white p-3.5">
                <dt class="text-slate-400">Identité</dt>
                <dd class="mt-1 font-bold text-slate-900 text-sm">{{ $suivi['etudiant']['nom_complet'] }}</dd>
                <dd class="text-slate-500 mt-0.5 font-mono">CIN : {{ $suivi['etudiant']['cin_masquee'] }}</dd>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-3.5">
                <dt class="text-slate-400">Contact</dt>
                <dd class="mt-1 font-bold text-slate-900 truncate">{{ $suivi['etudiant']['email_masque'] }}</dd>
                <dd class="text-slate-500 mt-0.5">Espace personnel actif</dd>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-3.5">
                <dt class="text-slate-400">Affectation demandée</dt>
                <dd class="mt-1 font-bold text-slate-900 truncate">{{ $suivi['faculte_nom'] }}</dd>
                <dd class="text-slate-500 mt-0.5">{{ $suivi['niveau'] }} · {{ $suivi['programme_nom'] }}</dd>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-3.5">
                <dt class="text-slate-400">Session universitaire</dt>
                <dd class="mt-1 font-bold text-slate-900">{{ $suivi['annee_academique'] }}</dd>
                <dd class="text-slate-500 mt-0.5">Inscription initiale</dd>
            </div>
        </dl>
    </div>

</div>
@endsection

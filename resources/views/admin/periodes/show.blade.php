@extends('layouts.app')

@section('title', 'Période d’inscription #'.$periode->id.' — Détails')

@section('content')
<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">
    {{-- En-tête de page & Actions CRUD --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between border-b border-slate-200 dark:border-slate-800 pb-5">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.periodes.index') }}" class="rounded-xl border border-slate-200 dark:border-slate-800 p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
            </a>
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                        {{ $periode->nom ?: 'Période d’inscription #'.$periode->id }}
                    </h1>
                    @if($periode->type_periode === 'exception')
                        <span class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-2.5 py-0.5 text-xs font-bold text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-950/50 dark:text-amber-400">
                            ★ Exception
                        </span>
                    @else
                        <span class="inline-flex items-center rounded-md bg-indigo-50 px-2.5 py-0.5 text-xs font-bold text-indigo-700 ring-1 ring-inset ring-indigo-700/20 dark:bg-indigo-950/50 dark:text-indigo-400">
                            Normale
                        </span>
                    @endif
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Année universitaire {{ $periode->anneeUniversitaire?->libelle ?? '—' }}</p>
            </div>
        </div>

        {{-- Barre d'actions CRUD --}}
        <div class="flex flex-wrap items-center gap-2">
            {{-- Modifier --}}
            <a href="{{ route('admin.periodes.edit', $periode) }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3.5 py-2 text-sm font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-750 transition shadow-sm">
                <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
                <span>Modifier</span>
            </a>

            {{-- Prolonger --}}
            @if($periode->statut === 'active')
                <button type="button" onclick="ouvrirModalProlongation()" class="inline-flex items-center gap-1.5 rounded-xl bg-amber-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-amber-500 transition shadow-sm">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>Prolonger</span>
                </button>

                {{-- Révoquer --}}
                <form method="post" action="{{ route('admin.periodes.revoquer', $periode) }}" onsubmit="return confirm('Êtes-vous sûr de vouloir révoquer cette période ? Elle ne permettra plus aucune inscription.')" class="inline">
                    @csrf
                    <input type="hidden" name="motif" value="Révocation depuis la fiche détaillée de la période." />
                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-100 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-400 transition shadow-sm">
                        <svg class="h-4 w-4 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                        </svg>
                        <span>Révoquer</span>
                    </button>
                </form>
            @endif

            {{-- Supprimer --}}
            @if($periode->statut !== 'utilisee')
                <form method="post" action="{{ route('admin.periodes.destroy', $periode) }}" onsubmit="return confirm('Confirmer la suppression définitive de cette période ?')" class="inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 dark:border-slate-800 p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </button>
                </form>
            @endif
        </div>
    </div>

    {{-- Alertes de notification --}}
    @if(session('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50/80 p-4 dark:border-emerald-900/50 dark:bg-emerald-950/40">
            <div class="flex items-start gap-3">
                <svg class="h-5 w-5 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <div class="text-sm font-medium text-emerald-800 dark:text-emerald-300">{{ session('success') }}</div>
            </div>
        </div>
    @endif

    {{-- Bannière Hero d'état en temps réel --}}
    @php
        $etat = $periode->statutCalcule();
    @endphp
    <div class="rounded-2xl border p-6 shadow-sm @if($etat === 'OPEN') border-emerald-200 bg-emerald-50/60 dark:border-emerald-900/50 dark:bg-emerald-950/20 @elseif($etat === 'CLOSING_SOON') border-amber-200 bg-amber-50/60 dark:border-amber-900/50 dark:bg-amber-950/20 @elseif($etat === 'SCHEDULED') border-sky-200 bg-sky-50/60 dark:border-sky-900/50 dark:bg-sky-950/20 @elseif($etat === 'CANCELLED') border-rose-200 bg-rose-50/60 dark:border-rose-900/50 dark:bg-rose-950/20 @else border-slate-200 bg-slate-50 dark:border-slate-800 dark:bg-slate-800/40 @endif">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl @if($etat === 'OPEN') bg-emerald-100 text-emerald-700 @elseif($etat === 'CLOSING_SOON') bg-amber-100 text-amber-700 @elseif($etat === 'SCHEDULED') bg-sky-100 text-sky-700 @elseif($etat === 'CANCELLED') bg-rose-100 text-rose-700 @else bg-slate-200 text-slate-700 @endif shadow-sm">
                    @if($etat === 'OPEN')
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    @elseif($etat === 'CLOSING_SOON')
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                    @elseif($etat === 'SCHEDULED')
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    @else
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    @endif
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wider text-slate-500">Statut de la période</div>
                    <div class="text-xl font-extrabold text-slate-900 dark:text-white">
                        @if($etat === 'OPEN') Ouverte (Inscriptions autorisées en temps réel)
                        @elseif($etat === 'CLOSING_SOON') Fermeture imminente (Moins de 48 heures restantes)
                        @elseif($etat === 'SCHEDULED') Planifiée (Ouverture future)
                        @elseif($etat === 'CANCELLED') Révoquée (Interrompue par l'administration)
                        @else Fermée (Période échue)
                        @endif
                    </div>
                </div>
            </div>
            <div class="text-xs text-slate-500 text-right">
                <div>Statut base de données : <span class="font-bold uppercase text-slate-700 dark:text-slate-300">{{ $periode->statut }}</span></div>
                <div>Fuseau de référence : <span class="font-bold text-slate-700 dark:text-slate-300">Africa/Tunis (UTC+1)</span></div>
            </div>
        </div>
    </div>

    {{-- Grille d'informations détaillées --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        {{-- Carte 1 : Calendrier --}}
        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-sm space-y-4">
            <h3 class="text-sm font-bold uppercase tracking-wider text-slate-900 dark:text-white flex items-center gap-2 border-b border-slate-100 dark:border-slate-800 pb-3">
                <svg class="h-4 w-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                Calendrier d’ouverture
            </h3>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                <div>
                    <dt class="text-slate-500 font-semibold">Date d'ouverture</dt>
                    <dd class="mt-1 text-sm font-bold text-slate-900 dark:text-white">
                        {{ $periode->opened_at->timezone(config('inscriptions.fuseau', 'Africa/Tunis'))->format('d/m/Y H:i:s') }}
                    </dd>
                    <span class="text-[11px] text-slate-400">Stockage UTC : {{ $periode->opened_at->format('Y-m-d H:i:s') }}</span>
                </div>
                <div>
                    <dt class="text-slate-500 font-semibold">Date de fermeture</dt>
                    <dd class="mt-1 text-sm font-bold text-slate-900 dark:text-white">
                        {{ $periode->closed_at->timezone(config('inscriptions.fuseau', 'Africa/Tunis'))->format('d/m/Y H:i:s') }}
                    </dd>
                    <span class="text-[11px] text-slate-400">Stockage UTC : {{ $periode->closed_at->format('Y-m-d H:i:s') }}</span>
                </div>
                <div class="sm:col-span-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                    <dt class="text-slate-500 font-semibold">Durée totale de la fenêtre</dt>
                    <dd class="mt-1 text-xs text-slate-700 dark:text-slate-300">
                        {{ $periode->opened_at->diffForHumans($periode->closed_at, true) }}
                    </dd>
                </div>
            </dl>
        </div>

        {{-- Carte 2 : Portée et Ciblage --}}
        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-sm space-y-4">
            <h3 class="text-sm font-bold uppercase tracking-wider text-slate-900 dark:text-white flex items-center gap-2 border-b border-slate-100 dark:border-slate-800 pb-3">
                <svg class="h-4 w-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
                Portée et ciblage pédagogique
            </h3>
            <dl class="space-y-3 text-xs">
                <div class="flex items-center justify-between">
                    <dt class="text-slate-500 font-semibold">Faculté</dt>
                    <dd class="font-bold text-slate-900 dark:text-white">
                        {{ $periode->faculte?->nom ?? '🌐 Toutes les facultés' }}
                    </dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-slate-500 font-semibold">Programme d'études</dt>
                    <dd class="font-bold text-slate-900 dark:text-white">
                        {{ $periode->programme ? $periode->programme->code.' — '.$periode->programme->nom : '📚 Tous les programmes' }}
                    </dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-slate-500 font-semibold">Niveau d'étude</dt>
                    <dd class="font-bold text-slate-900 dark:text-white">
                        {{ $periode->niveau ?: 'Tous niveaux' }}
                    </dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-slate-500 font-semibold">Type d'inscription</dt>
                    <dd class="font-bold text-slate-900 dark:text-white">
                        @if($periode->type_inscription === 'premiere') Première inscription
                        @elseif($periode->type_inscription === 'reinscription') Réinscription
                        @else Tous types
                        @endif
                    </dd>
                </div>
                @if($periode->etudiant)
                    <div class="flex items-center justify-between pt-2 border-t border-slate-100 dark:border-slate-800">
                        <dt class="text-slate-500 font-semibold">Étudiant cible (Usage unique)</dt>
                        <dd class="font-mono font-bold text-primary-600 dark:text-primary-400">
                            {{ $periode->etudiant->prenom }} {{ $periode->etudiant->nom }} (CIN: {{ $periode->etudiant->cin }})
                        </dd>
                    </div>
                @endif
            </dl>
        </div>
    </div>

    {{-- Carte 3 : Justification et Motif officiel --}}
    @if($periode->motif)
        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-sm">
            <h3 class="text-sm font-bold uppercase tracking-wider text-slate-900 dark:text-white flex items-center gap-2 mb-3">
                <svg class="h-4 w-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                Motif et justification administrative
            </h3>
            <div class="rounded-xl border border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-850 p-4 text-xs text-slate-700 dark:text-slate-300 leading-relaxed font-mono">
                {{ $periode->motif }}
            </div>
        </div>
    @endif

    {{-- Carte 4 : Traçabilité et Audit système --}}
    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-sm text-xs text-slate-500">
        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3">Traçabilité &amp; Audit</h3>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <span class="block text-slate-400">Créée par :</span>
                <span class="font-semibold text-slate-700 dark:text-slate-300">{{ $periode->creatrice?->name ?? 'Utilisateur #'.$periode->created_by }}</span>
            </div>
            <div>
                <span class="block text-slate-400">Date de création :</span>
                <span class="font-semibold text-slate-700 dark:text-slate-300">{{ $periode->created_at?->format('d/m/Y H:i:s') ?? '—' }}</span>
            </div>
            <div>
                <span class="block text-slate-400">Dernière mise à jour :</span>
                <span class="font-semibold text-slate-700 dark:text-slate-300">{{ $periode->updated_at?->format('d/m/Y H:i:s') ?? '—' }}</span>
            </div>
            @if($periode->used_at)
                <div>
                    <span class="block text-slate-400">Consommée le :</span>
                    <span class="font-semibold text-emerald-600 dark:text-emerald-400">{{ $periode->used_at->format('d/m/Y H:i:s') }}</span>
                </div>
            @endif
        </div>
    </div>
</div>

{{-- Modal de prolongation --}}
<div id="modal-prolongation-show" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl border border-slate-200 dark:border-slate-800">
        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
            <h3 class="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                <svg class="h-5 w-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Prolonger la période
            </h3>
            <button type="button" onclick="fermerModalProlongation()" class="text-slate-400 hover:text-slate-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <form method="post" action="{{ route('admin.periodes.prolonger', $periode) }}" class="mt-4 space-y-4">
            @csrf
            <div>
                <label for="modal-closed-at" class="block text-xs font-bold uppercase text-slate-500 mb-1">Nouvelle date de fermeture *</label>
                <input type="datetime-local" name="closed_at" id="modal-closed-at" required value="{{ $periode->closed_at->format('Y-m-d\TH:i') }}" class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-white" />
                <p class="mt-1 text-[11px] text-slate-400">Heure de Tunis (UTC+1)</p>
            </div>
            <div>
                <label for="modal-motif" class="block text-xs font-bold uppercase text-slate-500 mb-1">Motif de prolongation *</label>
                <textarea name="motif" id="modal-motif" required rows="3" minlength="10" placeholder="Justification officielle…" class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-white"></textarea>
            </div>
            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" onclick="fermerModalProlongation()" class="rounded-xl border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                    Annuler
                </button>
                <button type="submit" class="rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-500 transition">
                    Confirmer
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function ouvrirModalProlongation() {
    document.getElementById('modal-prolongation-show').classList.remove('hidden');
}

function fermerModalProlongation() {
    document.getElementById('modal-prolongation-show').classList.add('hidden');
}
</script>
@endsection

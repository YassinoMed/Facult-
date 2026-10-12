@extends('layouts.app')

@section('title', 'Dossier ' . $reclamation->reference . ' — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-6">
        <x-page-header :titre="'Réclamation #' . $reclamation->reference" :description="$reclamation->objet"
            :fil="['Espace Enseignant' => route('enseignant.dashboard'), 'Mes réclamations' => route('enseignant.reclamations.index'), $reclamation->reference => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('enseignant.reclamations.index') }}">← Retour à la liste</x-btn>
                @if($reclamation->peutEtreCloturee())
                    <form method="POST" action="{{ route('enseignant.reclamations.cloturer', $reclamation) }}" class="inline" onsubmit="return confirm('Confirmez-vous la clôture de ce dossier ?');">
                        @csrf
                        <x-btn variant="secondary" type="submit">Clôturer le dossier</x-btn>
                    </form>
                @endif
            </x-slot:actions>
        </x-page-header>

        @if(session('success'))
            <div class="mt-4"><x-alert type="success" :message="session('success')" /></div>
        @endif
        @if(session('error'))
            <div class="mt-4"><x-alert type="danger" :message="session('error')" /></div>
        @endif

        <div class="mt-6 space-y-6">
            {{-- Synthèse et état --}}
            <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-4 pb-4 border-b border-slate-100 dark:border-slate-800">
                    <div class="flex items-center gap-3">
                        <span class="px-2.5 py-1 rounded-md text-xs font-bold uppercase tracking-wider bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                            {{ $reclamation->categorieLibelle() }}
                        </span>
                        @if($reclamation->priorite === 'urgente')
                            <span class="px-2.5 py-1 rounded-md text-xs font-black uppercase tracking-wider bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300">
                                Urgente
                            </span>
                        @endif
                        <x-status-badge :statut="$reclamation->statut" />
                    </div>
                    <span class="text-xs text-slate-400">Signalé le {{ $reclamation->created_at?->format('d/m/Y à H:i') }}</span>
                </div>

                <div class="mt-4 space-y-4">
                    <div>
                        <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-1">Détails de l'anomalie signalée</h4>
                        <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/40 text-sm text-slate-800 dark:text-slate-200 whitespace-pre-line leading-relaxed">
                            {{ $reclamation->description }}
                        </div>
                    </div>

                    @if($reclamation->piece_jointe_chemin)
                        <div>
                            <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-1">Pièce justificative jointe</h4>
                            <div class="flex items-center justify-between p-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50">
                                <div class="flex items-center gap-2 text-xs font-semibold text-slate-700 dark:text-slate-300">
                                    <span>📎</span>
                                    <span>{{ $reclamation->piece_jointe_nom }}</span>
                                </div>
                                <a href="{{ route('enseignant.reclamations.piece-jointe', $reclamation) }}" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-primary-800 text-white hover:bg-primary-900 transition">
                                    Télécharger
                                </a>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Réponse et résolution administrative --}}
            <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-sm">
                <h4 class="text-xs font-black uppercase tracking-wider text-slate-400 mb-3">Réponse et mesures prises par l'administration</h4>

                @if($reclamation->reponse)
                    <div class="p-4 rounded-xl border border-emerald-200 dark:border-emerald-900 bg-emerald-50/40 dark:bg-emerald-950/20 text-sm">
                        <div class="flex items-center justify-between text-xs text-emerald-900 dark:text-emerald-300 font-bold mb-2">
                            <span>Décision & Réponse officielle</span>
                            @if($reclamation->traitee_le)
                                <span class="font-normal text-slate-400">Le {{ $reclamation->traitee_le->format('d/m/Y à H:i') }}</span>
                            @endif
                        </div>
                        <p class="text-slate-800 dark:text-slate-200 whitespace-pre-line">{{ $reclamation->reponse }}</p>
                    </div>
                @else
                    <div class="p-6 text-center text-xs text-slate-400 border border-dashed border-slate-200 dark:border-slate-800 rounded-xl">
                        Votre signalement a bien été transmis aux services compétents (responsable des emplois du temps / bureau des examens / scolarité). Le traitement est en cours.
                    </div>
                @endif
            </div>

            {{-- Historique des échanges / trace d'audit --}}
            @if(!empty($reclamation->historique))
                <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-sm">
                    <h4 class="text-xs font-black uppercase tracking-wider text-slate-400 mb-3">Journal du dossier</h4>
                    <ul class="space-y-3">
                        @foreach($reclamation->historique as $trace)
                            <li class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/40 text-xs flex items-start justify-between gap-4">
                                <div>
                                    <span class="font-bold text-slate-800 dark:text-slate-200 uppercase">{{ $trace['action'] ?? 'Événement' }}</span>
                                    <span class="text-slate-500"> — {{ $trace['message'] ?? '' }}</span>
                                </div>
                                <span class="font-mono text-slate-400 text-[11px] whitespace-nowrap">
                                    {{ isset($trace['date']) ? \Carbon\Carbon::parse($trace['date'])->format('d/m/Y H:i') : '' }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
@endsection

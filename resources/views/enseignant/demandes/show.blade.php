@extends('layouts.app')

@section('title', 'Dossier ' . $demande->reference . ' — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-6">
        <x-page-header :titre="'Demande #' . $demande->reference" :description="$demande->objet"
            :fil="['Espace Enseignant' => route('enseignant.dashboard'), 'Mes demandes' => route('enseignant.demandes.index'), $demande->reference => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('enseignant.demandes.index') }}">← Retour à la liste</x-btn>
                @if($demande->peutEtreAnnulee())
                    <form method="POST" action="{{ route('enseignant.demandes.annuler', $demande) }}" class="inline" onsubmit="return confirm('Confirmez-vous l\'annulation de cette demande ?');">
                        @csrf
                        <x-btn variant="danger" type="submit">Annuler la demande</x-btn>
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
                            {{ $demande->typeLibelle() }}
                        </span>
                        <x-status-badge :statut="$demande->statut" />
                    </div>
                    <span class="text-xs text-slate-400">Déposée le {{ $demande->created_at?->format('d/m/Y à H:i') }}</span>
                </div>

                <div class="mt-4 space-y-4">
                    <div>
                        <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-1">Description du dossier</h4>
                        <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/40 text-sm text-slate-800 dark:text-slate-200 whitespace-pre-line leading-relaxed">
                            {{ $demande->description }}
                        </div>
                    </div>

                    @if($demande->piece_jointe_chemin)
                        <div>
                            <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-1">Pièce jointe justificative</h4>
                            <div class="flex items-center justify-between p-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50">
                                <div class="flex items-center gap-2 text-xs font-semibold text-slate-700 dark:text-slate-300">
                                    <span>📎</span>
                                    <span>{{ $demande->piece_jointe_nom }}</span>
                                </div>
                                <a href="{{ route('enseignant.demandes.piece-jointe', $demande) }}" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-primary-800 text-white hover:bg-primary-900 transition">
                                    Télécharger
                                </a>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Réponse et instruction administrative --}}
            <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-sm">
                <h4 class="text-xs font-black uppercase tracking-wider text-slate-400 mb-3">Traitement par les services administratifs</h4>

                @if($demande->reponse)
                    <div class="p-4 rounded-xl border border-primary-200 dark:border-primary-900 bg-primary-50/40 dark:bg-primary-950/20 text-sm">
                        <div class="flex items-center justify-between text-xs text-primary-900 dark:text-primary-300 font-bold mb-2">
                            <span>Réponse officielle</span>
                            @if($demande->traitee_le)
                                <span class="font-normal text-slate-400">Le {{ $demande->traitee_le->format('d/m/Y à H:i') }}</span>
                            @endif
                        </div>
                        <p class="text-slate-800 dark:text-slate-200 whitespace-pre-line">{{ $demande->reponse }}</p>
                    </div>
                @else
                    <div class="p-6 text-center text-xs text-slate-400 border border-dashed border-slate-200 dark:border-slate-800 rounded-xl">
                        Dossier en attente d'instruction par le service de scolarité / secrétariat général. Vous recevrez une notification dès qu'une réponse sera apportée.
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection

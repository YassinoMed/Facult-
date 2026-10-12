@extends('layouts.app')

@section('title', 'Mes réclamations & signalements — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-6">
        <x-page-header titre="Mes réclamations & signalements" description="Signalez une erreur sur l'emploi du temps, une anomalie de présence, un conflit de surveillance ou un problème administratif."
            :fil="['Espace Enseignant' => route('enseignant.dashboard'), 'Mes réclamations' => '#']">
            <x-slot:actions>
                <x-btn variant="danger" href="{{ route('enseignant.reclamations.create') }}">+ Déposer une réclamation</x-btn>
            </x-slot:actions>
        </x-page-header>

        @if(session('success'))
            <div class="mt-4"><x-alert type="success" :message="session('success')" /></div>
        @endif
        @if(session('error'))
            <div class="mt-4"><x-alert type="danger" :message="session('error')" /></div>
        @endif

        {{-- Filtres --}}
        <form method="GET" action="{{ route('enseignant.reclamations.index') }}" class="mt-6 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm flex flex-wrap items-center gap-4">
            <div class="flex-1 min-w-[200px]">
                <label for="statut" class="block text-xs font-bold text-slate-500 mb-1">Filtrer par statut</label>
                <select id="statut" name="statut" class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 px-3 py-2 text-xs">
                    <option value="">Tous les statuts</option>
                    @foreach($statuts as $code => $libelle)
                        <option value="{{ $code }}" {{ $filtreStatut === $code ? 'selected' : '' }}>{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex-1 min-w-[200px]">
                <label for="categorie" class="block text-xs font-bold text-slate-500 mb-1">Catégorie de signalement</label>
                <select id="categorie" name="categorie" class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 px-3 py-2 text-xs">
                    <option value="">Toutes les catégories</option>
                    @foreach($categories as $code => $libelle)
                        <option value="{{ $code }}" {{ $filtreCategorie === $code ? 'selected' : '' }}>{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>

            <div class="pt-5 flex items-center gap-2">
                <button type="submit" class="px-4 py-2 rounded-xl text-xs font-bold bg-primary-800 text-white hover:bg-primary-900 transition">
                    Filtrer
                </button>
                <a href="{{ route('enseignant.reclamations.index') }}" class="px-3 py-2 rounded-xl text-xs font-semibold text-slate-500 hover:bg-slate-100 transition">
                    Réinitialiser
                </a>
            </div>
        </form>

        {{-- Liste des réclamations --}}
        <div class="mt-6 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
            <div class="px-5 py-3.5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Dossiers de réclamation</h3>
                <span class="text-xs text-slate-500">{{ $reclamations->total() }} dossier(s)</span>
            </div>

            @if($reclamations->isNotEmpty())
                <div class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach($reclamations as $rec)
                        <div class="p-5 flex flex-col md:flex-row md:items-center justify-between gap-4 hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition">
                            <div class="space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-xs font-bold text-slate-400">#{{ $rec->reference }}</span>
                                    <h4 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ $rec->objet }}</h4>
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                        {{ $rec->categorieLibelle() }}
                                    </span>
                                    @if($rec->priorite === 'urgente')
                                        <span class="px-2 py-0.5 rounded-md text-[10px] font-black uppercase tracking-wider bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300">
                                            Urgente
                                        </span>
                                    @endif
                                </div>
                                <p class="text-xs text-slate-600 dark:text-slate-400 line-clamp-2 max-w-2xl">{{ $rec->description }}</p>
                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-400">
                                    <span>Signalé le : {{ $rec->created_at?->format('d/m/Y H:i') }}</span>
                                    @if($rec->piece_jointe_nom)
                                        <span class="flex items-center gap-1">📎 {{ $rec->piece_jointe_nom }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-center gap-3">
                                <x-status-badge :statut="$rec->statut" />
                                <a href="{{ route('enseignant.reclamations.show', $rec) }}" 
                                   class="px-3 py-1.5 rounded-xl text-xs font-bold border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                                    Suivre →
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="p-4 border-t border-slate-100 dark:border-slate-800">
                    {{ $reclamations->links() }}
                </div>
            @else
                <div class="p-12 text-center">
                    <x-empty-state message="Aucune réclamation enregistrée." />
                    <p class="text-xs text-slate-400 mt-2">En cas d'erreur de planning, conflit de surveillance ou litige de pointage, ouvrez un dossier.</p>
                    <div class="mt-4">
                        <x-btn variant="danger" href="{{ route('enseignant.reclamations.create') }}">+ Déposer une réclamation</x-btn>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection

@extends('layouts.app')

@section('title', 'Mes simulations de parcours')

@section('content')
    <div class="simulations-page mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <x-page-header
            titre="Mes simulations de parcours"
            description="Comparez des scénarios hypothétiques sur VOS données officielles. Les résultats ne sont jamais des décisions."
            :fil="['Accueil' => route('etudiant.dashboard'), 'Mes simulations' => '#']"
        >
            <x-slot:actions>
                <a href="{{ route('etudiant.parcours.simulations.create') }}" class="btn-primary">Nouvelle simulation</a>
            </x-slot:actions>
        </x-page-header>

        <x-alert type="info" class="mt-4">
            Une simulation est <strong>strictement non officielle</strong> : notes, crédits, niveau et décisions restent inchangés. Seule la délibération du jury rend une décision officielle.
        </x-alert>

        <x-card titre="Historique de mes simulations" class="mt-4">
            @forelse($simulations as $simulation)
                <div class="simulation-row py-2 border-b border-slate-100 last:border-0 text-sm flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <a href="{{ route('etudiant.parcours.simulations.show', $simulation) }}"
                           class="text-primary-700 hover:underline font-bold">
                            {{ $simulation->scenario }}
                        </a>
                        <span class="text-slate-400"> — {{ $simulation->created_at->format('d/m/Y H:i') }}</span>
                    </div>
                    <span class="simulation-result rounded-full px-2 py-0.5 text-xs font-bold {{ ($simulation->resultat['resultat_final'] ?? '') === 'passage' ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-700' }}">
                        {{ $simulation->resultat['resultat_final'] ?? '—' }}
                    </span>
                </div>
            @empty
                <p class="simulation-empty text-sm text-slate-500 py-2">Aucune simulation pour le moment. Créez-en une pour comparer des scénarios (passage, compensation, dettes…).</p>
            @endforelse

            {{ $simulations->links() }}
        </x-card>
    </div>
@endsection

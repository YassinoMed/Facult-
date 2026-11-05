@extends('layouts.app')

@section('title', 'Circuit de validation à quatre yeux')

@section('content')
@php
    $libellesStatut = [
        'soumise' => ['En attente', 'bg-amber-50 text-amber-700 border-amber-200'],
        'approuvee' => ['Approuvée', 'bg-emerald-50 text-emerald-700 border-emerald-200'],
        'rejetee' => ['Rejetée', 'bg-rose-50 text-rose-700 border-rose-200'],
        'annulee' => ['Annulée', 'bg-slate-100 text-slate-500 border-slate-200'],
        'expiree' => ['Expirée', 'bg-slate-100 text-slate-500 border-slate-200'],
    ];
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-page-header titre="Circuit de validation à quatre yeux"
        description="Demandes d’activation de rôle et d’affectation soumises à une approbation indépendante. L’initiateur et le bénéficiaire ne peuvent jamais approuver leur propre demande."
        :fil="['Gouvernance IAM' => '#', 'Rôles' => route('admin.roles.index'), 'Circuit 4 yeux' => route('admin.roles.demandes.index')]">
        <x-slot:actions>
            <a href="{{ route('admin.roles.index') }}"
               class="rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">
                ← Retour aux rôles
            </a>
        </x-slot:actions>
    </x-page-header>

    @if(session('success'))
        <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50/80 px-5 py-3.5 text-sm font-semibold text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="ds-card p-4">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">En attente</p>
            <p class="mt-1 text-2xl font-black {{ $compteurs['en_attente'] > 0 ? 'text-amber-600' : 'text-slate-900' }}">
                {{ $compteurs['en_attente'] }}
            </p>
        </div>
        <div class="ds-card p-4">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Approuvées</p>
            <p class="mt-1 text-2xl font-black text-emerald-700">{{ $compteurs['approuvees'] }}</p>
        </div>
        <div class="ds-card p-4">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Rejetées</p>
            <p class="mt-1 text-2xl font-black text-rose-700">{{ $compteurs['rejetees'] }}</p>
        </div>
    </div>

    <form method="GET" action="{{ route('admin.roles.demandes.index') }}"
          class="ds-card mt-6 flex flex-wrap items-center justify-between gap-3 p-4">
        <div class="flex items-center gap-2">
            <label for="statut" class="text-xs font-bold text-slate-600">Filtrer par statut</label>
            <select id="statut" name="statut" onchange="this.form.submit()"
                    class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700">
                @foreach(['soumise' => 'En attente', 'approuvee' => 'Approuvées', 'rejetee' => 'Rejetées', 'annulee' => 'Annulées', 'expiree' => 'Expirées', 'tous' => 'Toutes'] as $valeur => $libelle)
                    <option value="{{ $valeur }}" @selected($statut === $valeur)>{{ $libelle }}</option>
                @endforeach
            </select>
        </div>
        <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] font-bold text-slate-600">
            {{ $demandes->total() }} demande(s)
        </span>
    </form>

    <section class="ds-card mt-4 overflow-hidden" aria-label="Liste des demandes">
        @if($demandes->isEmpty())
            <div class="p-10 text-center">
                <span class="text-4xl" aria-hidden="true">⚖️</span>
                <h2 class="mt-2 text-sm font-bold text-slate-900">Aucune demande dans cette vue</h2>
                <p class="mt-1 text-xs text-slate-500">
                    Les demandes apparaissent lorsqu’un acteur non super-administrateur tente d’activer un rôle ou d’affecter un rôle à permissions critiques.
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-slate-200 bg-slate-50 text-[10px] font-black uppercase tracking-wider text-slate-600">
                        <tr>
                            <th class="px-4 py-3">Référence</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Rôle</th>
                            <th class="px-4 py-3">Initiateur</th>
                            <th class="px-4 py-3">Bénéficiaire</th>
                            <th class="px-4 py-3">Statut</th>
                            <th class="px-4 py-3">Créée le</th>
                            <th class="px-4 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($demandes as $demande)
                            @php
                                [$libelle, $classes] = $libellesStatut[$demande->statut] ?? [ucfirst($demande->statut), 'bg-slate-100 text-slate-600 border-slate-200'];
                            @endphp
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-4 py-3">
                                    <p class="font-mono text-[10px] text-slate-500">{{ $demande->reference }}</p>
                                </td>
                                <td class="px-4 py-3 font-bold text-slate-800">{{ $demande->libelleType() }}</td>
                                <td class="px-4 py-3">
                                    @if($demande->profil)
                                        <a href="{{ route('admin.roles.show', $demande->profil->id) }}" class="font-bold text-primary-800 hover:underline">
                                            {{ $demande->profil->nom }}
                                        </a>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-slate-600">{{ $demande->initiateur?->name ?? 'Système' }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ $demande->cible?->name ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full border px-2 py-0.5 text-[10px] font-bold {{ $classes }}">
                                        {{ $libelle }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-slate-500">{{ $demande->created_at?->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('admin.roles.demandes.show', $demande->id) }}"
                                       class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-bold text-slate-700 hover:bg-slate-100">
                                        Examiner
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-100 p-3">
                {{ $demandes->links() }}
            </div>
        @endif
    </section>
</div>
@endsection

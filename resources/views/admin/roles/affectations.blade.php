@extends('layouts.app')

@section('title', 'Affectations — ' . $profil->nom)

@section('content')
<div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
    <x-page-header titre="Affectations de « {{ $profil->nom }} »"
        description="Attribution nominative du rôle à des comptes actifs, pour une durée bornée. L’auto-affectation est interdite et les rôles à permissions critiques passent par quatre yeux."
        :fil="['Gouvernance IAM' => '#', 'Rôles' => route('admin.roles.index'), $profil->nom => route('admin.roles.show', $profil->id), 'Affectations' => '#']">
        <x-slot:actions>
            <a href="{{ route('admin.roles.show', $profil->id) }}"
               class="rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">
                ← Retour à la fiche
            </a>
        </x-slot:actions>
    </x-page-header>

    @if(session('success'))
        <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50/80 px-5 py-3.5 text-sm font-semibold text-emerald-800">
            {{ session('success') }}
        </div>
    @endif
    @if(session('info'))
        <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50/80 px-5 py-3.5 text-sm font-semibold text-amber-800">
            {{ session('info') }}
        </div>
    @endif

    @if($errors->any())
        <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50/90 p-4" role="alert">
            <h2 class="text-sm font-bold text-rose-900">L’affectation n’a pas été enregistrée :</h2>
            <ul class="mt-2 list-inside list-disc space-y-1 text-xs text-rose-700">
                @foreach($errors->all() as $erreur)
                    <li>{{ $erreur }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @unless($profil->estActif())
        <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-xs text-amber-800">
            <p class="font-bold">Ce rôle n’est pas actif (statut : {{ ucfirst($profil->statut) }}).</p>
            <p class="mt-1 text-amber-700">Activez-le depuis sa fiche avant de pouvoir l’affecter à des comptes.</p>
        </div>
    @endunless

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Formulaire d'affectation --}}
        <section class="ds-card p-6 lg:col-span-1" aria-labelledby="nouvelle-affectation">
            <h2 id="nouvelle-affectation" class="text-sm font-black text-slate-900">Nouvelle affectation</h2>

            <form method="GET" action="{{ route('admin.roles.affectations.index', $profil->id) }}" class="mt-4">
                <label for="q" class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Rechercher un compte</label>
                <div class="flex gap-2">
                    <input type="text" id="q" name="q" value="{{ $recherche }}" placeholder="Nom ou courriel…"
                           class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                    <button type="submit" class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">
                        Filtrer
                    </button>
                </div>
            </form>

            <form method="POST" action="{{ route('admin.roles.affectations.store', $profil->id) }}" class="mt-4 space-y-4">
                @csrf

                <div>
                    <label for="user_id" class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-500">
                        Compte bénéficiaire <span class="text-rose-500">*</span>
                    </label>
                    <select id="user_id" name="user_id" required
                            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                        <option value="">— Sélectionnez un compte —</option>
                        @foreach($utilisateurs as $utilisateur)
                            <option value="{{ $utilisateur->id }}" @selected((string) old('user_id') === (string) $utilisateur->id)>
                                {{ $utilisateur->name }} — {{ $utilisateur->email }}
                            </option>
                        @endforeach
                    </select>
                    @if($utilisateurs->isEmpty())
                        <p class="mt-1.5 text-[11px] text-slate-400">Aucun compte actif ne correspond à la recherche.</p>
                    @endif
                </div>

                <div>
                    <label for="expire_le" class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-500">
                        Date d’expiration
                    </label>
                    <input type="datetime-local" id="expire_le" name="expire_le" value="{{ old('expire_le') }}"
                           class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                    <p class="mt-1.5 text-[11px] text-slate-400">
                        Facultative. La durée maximale est de {{ max(1, (int) config('iam.duree_max_affectation_jours', 365)) }} jours.
                    </p>
                </div>

                <div>
                    <label for="motif" class="mb-1.5 block text-[11px] font-bold uppercase tracking-wider text-slate-500">
                        Motif <span class="text-rose-500">*</span>
                    </label>
                    <textarea id="motif" name="motif" rows="3" required minlength="3" maxlength="500"
                              class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">{{ old('motif') }}</textarea>
                </div>

                <button type="submit" @disabled(! $profil->estActif())
                        class="w-full rounded-xl bg-primary-800 px-4 py-2.5 text-xs font-black text-white shadow-sm hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-50">
                    Affecter le rôle
                </button>
            </form>
        </section>

        {{-- Liste des affectations --}}
        <section class="ds-card p-6 lg:col-span-2" aria-labelledby="liste-affectations">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="liste-affectations" class="text-sm font-black text-slate-900">Historique des affectations</h2>
                <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] font-bold text-slate-600">
                    {{ $affectations->total() }} entrée(s)
                </span>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-slate-200 text-[10px] font-black uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="py-2 pr-3">Compte</th>
                            <th class="py-2 pr-3">Statut</th>
                            <th class="py-2 pr-3">Période</th>
                            <th class="py-2 pr-3">Créé par</th>
                            <th class="py-2 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($affectations as $affectation)
                            <tr>
                                <td class="py-3 pr-3">
                                    <p class="font-bold text-slate-800">{{ $affectation->user?->name ?? 'Compte supprimé' }}</p>
                                    <p class="text-[10px] text-slate-400">{{ $affectation->user?->email }}</p>
                                    <p class="mt-0.5 font-mono text-[10px] text-slate-400">v{{ $affectation->version_profil }}</p>
                                </td>
                                <td class="py-3 pr-3">
                                    <span class="rounded-full px-2 py-0.5 text-[10px] font-bold
                                                 {{ $affectation->statut === 'active' ? 'bg-emerald-50 text-emerald-700' : ($affectation->statut === 'revoquee' ? 'bg-rose-50 text-rose-700' : 'bg-slate-100 text-slate-500') }}">
                                        {{ ucfirst($affectation->statut) }}
                                    </span>
                                </td>
                                <td class="py-3 pr-3 text-slate-600">
                                    <p>{{ $affectation->debut_le?->format('d/m/Y H:i') ?? '—' }}</p>
                                    <p class="text-[10px] text-slate-400">
                                        → {{ $affectation->expire_le?->format('d/m/Y H:i') ?? 'sans expiration' }}
                                    </p>
                                </td>
                                <td class="py-3 pr-3 text-slate-600">
                                    <p>{{ $affectation->creePar?->name ?? 'Système' }}</p>
                                    @if($affectation->revoquePar)
                                        <p class="text-[10px] text-slate-400">Révoqué par {{ $affectation->revoquePar->name }}</p>
                                    @endif
                                </td>
                                <td class="py-3 text-right">
                                    @if($affectation->statut === 'active')
                                        <form method="POST" action="{{ route('admin.roles.affectations.destroy', $affectation->id) }}"
                                              onsubmit="return confirm('Révoquer cette affectation ? L’accès sera retiré immédiatement.');">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="motif" value="Révocation depuis la fiche du rôle">
                                            <button type="submit" class="rounded-lg border border-rose-200 bg-white px-2.5 py-1 text-[11px] font-bold text-rose-700 hover:bg-rose-50">
                                                Révoquer
                                            </button>
                                        </form>
                                    @else
                                        <span class="text-[10px] text-slate-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-8 text-center text-xs text-slate-500">
                                    Aucune affectation pour ce rôle.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $affectations->links() }}
            </div>
        </section>
    </div>
</div>
@endsection

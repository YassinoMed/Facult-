@extends('layouts.app')

@section('title', 'Gouvernance des Rôles & Permissions')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8" x-data="{ ongletActif: 'personnalises' }">
    {{-- En-tête de page --}}
    <x-page-header titre="Gouvernance des Rôles & Accès"
        description="Catalogue canonique des permissions, rôles système protégés, rôles personnalisés et circuit de validation à quatre yeux."
        :fil="['Gouvernance IAM' => '#', 'Rôles' => route('admin.roles.index')]">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                @if($peutAttribuer)
                    <a href="{{ route('admin.roles.demandes.index') }}"
                       class="relative inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50 transition">
                        <span>👁️‍🗨️</span> Circuit 4 Yeux
                        @if(($indicateurs['demandes_en_attente'] ?? 0) > 0)
                            <span class="inline-flex items-center justify-center rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-black text-white">
                                {{ $indicateurs['demandes_en_attente'] }}
                            </span>
                        @endif
                    </a>
                    <a href="{{ route('admin.roles.create') }}"
                       class="inline-flex items-center gap-1.5 rounded-xl bg-primary-800 px-3.5 py-2 text-xs font-bold text-white shadow-sm hover:bg-primary-700 transition">
                        <span>➕</span> Créer un rôle personnalisé
                    </a>
                @endif
            </div>
        </x-slot:actions>
    </x-page-header>

    {{-- Messages flash --}}
    @if(session('success'))
        <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50/80 px-5 py-3.5 text-sm font-semibold text-emerald-800 shadow-sm">
            {{ session('success') }}
        </div>
    @endif
    @if(session('info'))
        <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50/80 px-5 py-3.5 text-sm font-semibold text-amber-800 shadow-sm">
            {{ session('info') }}
        </div>
    @endif

    {{-- ── Indicateurs de synthèse ── --}}
    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="ds-card p-4 flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500">Rôles Système</p>
                <p class="text-2xl font-black text-slate-900 mt-1">{{ $indicateurs['total_systeme'] ?? count($rolesSysteme) }}</p>
                <p class="text-[11px] text-slate-400 mt-0.5">Immuables & protégés</p>
            </div>
            <span class="text-3xl" aria-hidden="true">🛡️</span>
        </div>

        <div class="ds-card p-4 flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500">Rôles Personnalisés</p>
                <p class="text-2xl font-black text-primary-800 mt-1">{{ $indicateurs['total_personnalises'] ?? 0 }}</p>
                <p class="text-[11px] text-slate-400 mt-0.5">Sous-ensembles configurables</p>
            </div>
            <span class="text-3xl" aria-hidden="true">🎭</span>
        </div>

        <div class="ds-card p-4 flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500">Affectations Actives</p>
                <p class="text-2xl font-black text-emerald-700 mt-1">{{ $indicateurs['total_affectations'] ?? 0 }}</p>
                <p class="text-[11px] text-slate-400 mt-0.5">Utilisateurs habilités</p>
            </div>
            <span class="text-3xl" aria-hidden="true">👥</span>
        </div>

        <div class="ds-card p-4 flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500">Demandes 4 Yeux</p>
                <p class="text-2xl font-black {{ ($indicateurs['demandes_en_attente'] ?? 0) > 0 ? 'text-amber-600' : 'text-slate-700' }} mt-1">
                    {{ $indicateurs['demandes_en_attente'] ?? 0 }}
                </p>
                <p class="text-[11px] text-slate-400 mt-0.5">En attente d'approbation</p>
            </div>
            <span class="text-3xl" aria-hidden="true">⚖️</span>
        </div>
    </div>

    {{-- Onglets de navigation entre types de rôles --}}
    <div class="mt-8 flex items-center gap-2 border-b border-slate-200">
        <button type="button" @click="ongletActif = 'personnalises'"
                :class="ongletActif === 'personnalises' ? 'border-primary-800 text-primary-800 font-black' : 'border-transparent text-slate-500 hover:text-slate-800 font-semibold'"
                class="border-b-2 px-4 py-2.5 text-sm transition">
            Rôles Personnalisés ({{ $rolesPersonnalises->total() }})
        </button>
        <button type="button" @click="ongletActif = 'systeme'"
                :class="ongletActif === 'systeme' ? 'border-primary-800 text-primary-800 font-black' : 'border-transparent text-slate-500 hover:text-slate-800 font-semibold'"
                class="border-b-2 px-4 py-2.5 text-sm transition">
            Rôles Système Protégés ({{ count($rolesSysteme) }})
        </button>
    </div>

    {{-- ── SECTION 1 : RÔLES PERSONNALISÉS (CRUD) ── --}}
    <div x-show="ongletActif === 'personnalises'" class="mt-6 space-y-4">
        {{-- Barre de recherche et filtres --}}
        <form method="GET" action="{{ route('admin.roles.index') }}" class="ds-card p-4 flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="relative w-full sm:w-96">
                <span class="absolute inset-y-0 left-3 flex items-center text-slate-400 pointer-events-none">🔍</span>
                <input type="text" name="q" value="{{ $recherche }}" placeholder="Rechercher par nom, code ou description…"
                       class="w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-4 py-2 text-xs focus:bg-white focus:outline-none focus:border-primary-600 transition">
            </div>

            <div class="flex items-center gap-2 w-full sm:w-auto">
                <select name="statut" onchange="this.form.submit()" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700">
                    <option value="tous" @selected($filtreStatut === 'tous')>Tous les statuts</option>
                    <option value="actif" @selected($filtreStatut === 'actif')>Actif</option>
                    <option value="soumis" @selected($filtreStatut === 'soumis')>Soumis</option>
                    <option value="suspendu" @selected($filtreStatut === 'suspendu')>Suspendu</option>
                    <option value="archive" @selected($filtreStatut === 'archive')>Archivé</option>
                </select>
                @if($recherche !== '' || $filtreStatut !== 'tous')
                    <a href="{{ route('admin.roles.index') }}" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-500 hover:bg-slate-100">
                        Réinitialiser
                    </a>
                @endif
            </div>
        </form>

        {{-- Table des rôles personnalisés --}}
        <div class="ds-card overflow-hidden">
            @if($rolesPersonnalises->isEmpty())
                <div class="p-8 text-center">
                    <span class="text-4xl">🎭</span>
                    <h3 class="mt-2 text-sm font-bold text-slate-900">Aucun rôle personnalisé trouvé</h3>
                    <p class="mt-1 text-xs text-slate-500">Créez votre premier rôle pour attribuer des sous-ensembles spécifiques de permissions.</p>
                    @if($peutAttribuer)
                        <div class="mt-4">
                            <a href="{{ route('admin.roles.create') }}" class="inline-flex items-center gap-1.5 rounded-xl bg-primary-800 px-4 py-2 text-xs font-bold text-white hover:bg-primary-700">
                                ➕ Créer un rôle personnalisé
                            </a>
                        </div>
                    @endif
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-50 border-b border-slate-200 text-slate-600 font-bold">
                            <tr>
                                <th class="px-4 py-3">Rôle & Code</th>
                                <th class="px-4 py-3">Statut</th>
                                <th class="px-4 py-3">Portée</th>
                                <th class="px-4 py-3">Permissions</th>
                                <th class="px-4 py-3">Affectations</th>
                                <th class="px-4 py-3">Créateur</th>
                                <th class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium">
                            @foreach($rolesPersonnalises as $p)
                                <tr class="hover:bg-slate-50/60 transition">
                                    <td class="px-4 py-3">
                                        <div class="font-bold text-slate-900">{{ $p->nom }}</div>
                                        <code class="text-[11px] text-slate-500 font-mono">{{ $p->code }}</code>
                                    </td>
                                    <td class="px-4 py-3">
                                        @php
                                            $classes = match($p->statut) {
                                                'actif' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                                'soumis' => 'bg-amber-50 text-amber-700 border-amber-200',
                                                'suspendu' => 'bg-rose-50 text-rose-700 border-rose-200',
                                                default => 'bg-slate-100 text-slate-600 border-slate-200',
                                            };
                                        @endphp
                                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-bold {{ $classes }}">
                                            {{ ucfirst($p->statut) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600">
                                        {{ $p->faculte ? $p->faculte->nom : 'Globale' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.roles.permissions.edit', $p->id) }}" class="font-bold text-primary-800 hover:underline">
                                            {{ $p->permissions->count() }} perm.
                                        </a>
                                    </td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.roles.affectations.index', $p->id) }}" class="font-bold text-primary-800 hover:underline">
                                            {{ $p->affectations->where('statut', 'active')->count() }} compte(s)
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 text-slate-500 text-[11px]">
                                        {{ $p->createur?->name ?? 'Système' }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="inline-flex items-center gap-1.5">
                                            <a href="{{ route('admin.roles.show', $p->id) }}"
                                               class="rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] font-bold text-slate-700 hover:bg-slate-100" title="Consulter">
                                                Détail
                                            </a>
                                            <a href="{{ route('admin.roles.permissions.edit', $p->id) }}"
                                               class="rounded-lg border border-primary-200 bg-primary-50/50 px-2.5 py-1 text-[11px] font-bold text-primary-800 hover:bg-primary-100" title="Gérer permissions">
                                                Permissions
                                            </a>
                                            <a href="{{ route('admin.roles.affectations.index', $p->id) }}"
                                               class="rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] font-bold text-slate-700 hover:bg-slate-100" title="Affecter utilisateurs">
                                                Affecter
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="p-3 border-t border-slate-100">
                    {{ $rolesPersonnalises->links() }}
                </div>
            @endif
        </div>
    </div>

    {{-- ── SECTION 2 : RÔLES SYSTÈME PROTÉGÉS ── --}}
    <div x-show="ongletActif === 'systeme'" class="mt-6 space-y-4">
        <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 text-xs text-slate-600 flex items-center gap-3">
            <span class="text-2xl">🔒</span>
            <div>
                <p class="font-bold text-slate-800">Les rôles système sont codés en dur dans l'infrastructure logicielle.</p>
                <p class="text-slate-500">Leurs permissions fondamentales et leurs prérogatives institutionnelles ne peuvent être modifiées ou supprimées par l'interface.</p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            @foreach($rolesSysteme as $r)
                <section class="ds-card p-5" aria-label="Rôle {{ $r['code'] }}">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2">
                                <h2 class="text-sm font-black text-slate-900">{{ $r['nom'] }}</h2>
                                <span class="rounded-full bg-slate-100 border border-slate-200 px-2 py-0.5 text-[10px] font-bold text-slate-600 font-mono">
                                    {{ $r['code'] }}
                                </span>
                            </div>
                            <p class="text-[11px] text-slate-400 mt-0.5">Portée : {{ $r['portee'] }}</p>
                        </div>
                        <span class="rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 px-2 py-0.5 text-[10px] font-black">
                            SYSTÈME
                        </span>
                    </div>

                    <div class="mt-3 flex items-center justify-between text-xs text-slate-500">
                        <span>{{ count($r['permissions']) }} permissions actives</span>
                        <span><strong id="effectif-{{ $r['code'] }}" class="text-slate-900">{{ $r['effectif'] }}</strong> utilisateur(s)</span>
                    </div>

                    <div class="mt-4 flex items-center gap-2">
                        <a href="{{ route('admin.roles.show', $r['code']) }}"
                           class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50">
                            Voir les permissions
                        </a>
                        @if($peutAttribuer)
                            <button type="button" @click="$dispatch('ouvrir-attribution', { roleCode: '{{ $r['code'] }}' })"
                                    class="rounded-xl bg-primary-800 px-3 py-1.5 text-xs font-bold text-white hover:bg-primary-700">
                                Attribuer ce rôle
                            </button>
                        @endif
                    </div>
                </section>
            @endforeach
        </div>
    </div>

    {{-- Inclusion modale d'attribution historique --}}
    @include('admin._modal-attribution-role', ['mode' => 'role', 'config' => [
        'mode' => 'role',
        'urls' => [
            'attribuer' => route('admin.roles.attribuer'),
            'recherche' => route('admin.administrateurs.recherche'),
        ],
        'roles' => $rolesAttribuables,
        'registre' => $registrePermissions,
        'facultes' => $facultes->map(fn ($f) => ['id' => $f->id, 'nom' => $f->nom])->all(),
    ]])
</div>
@endsection

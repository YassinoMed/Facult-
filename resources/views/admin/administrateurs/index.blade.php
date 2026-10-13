@extends('layouts.app')

@section('title', 'Gestion des Administrateurs')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">

    {{-- En-tête --}}
    <div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div class="flex items-center gap-2.5">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-100 text-primary-700">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                </svg>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">Administrateurs du Back-Office</h1>
                <p class="mt-0.5 text-xs sm:text-sm text-slate-500">Comptes, rôles RBAC et périmètres de faculté — Super Admin uniquement.</p>
            </div>
        </div>

        <a href="{{ route('admin.administrateurs.create') }}"
           class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-primary-600 to-accent-600 px-4 py-2.5 text-xs font-bold text-white shadow-md shadow-primary-500/20 hover:shadow-lg hover:shadow-primary-500/30 hover:-translate-y-0.5 transition-smooth">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            Ajouter un Administrateur
        </a>
    </div>

    @if(session('success'))
        <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50/80 px-5 py-3.5 text-sm font-semibold text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    {{-- Recherche & filtres --}}
    <form method="GET" action="{{ route('admin.administrateurs.index') }}" role="search" aria-label="Recherche d'administrateurs"
          class="mb-6 grid grid-cols-2 gap-3 rounded-3xl border border-slate-200/80 bg-white p-5 shadow-xs sm:grid-cols-4">
        <div class="col-span-2 sm:col-span-1">
            <label class="sr-only" for="recherche-admin">Rechercher par nom ou email</label>
            <input id="recherche-admin" type="text" name="search" value="{{ request('search') }}" autocomplete="off"
                   placeholder="Nom ou email…" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm" />
        </div>
        <div>
            <label class="sr-only" for="filtre-role">Filtrer par rôle</label>
            <select id="filtre-role" name="role" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm">
                <option value="">Tous rôles</option>
                @foreach(['super_admin' => 'Super admin', 'admin_scolarite' => 'Scolarité', 'admin_faculte' => 'Faculté', 'agent_scolarite' => 'Agent', 'consultation' => 'Consultation'] as $valeur => $libelle)
                    <option value="{{ $valeur }}" {{ request('role') === $valeur ? 'selected' : '' }}>{{ $libelle }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="sr-only" for="filtre-faculte-admin">Filtrer par faculté</label>
            <select id="filtre-faculte-admin" name="faculte_id" class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm">
                <option value="">Toutes facultés</option>
                @foreach($facultes as $faculte)
                    <option value="{{ $faculte->id }}" {{ (string) request('faculte_id') === (string) $faculte->id ? 'selected' : '' }}>{{ $faculte->code }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.administrateurs.index') }}" class="rounded-xl border border-slate-200 px-3.5 py-2 text-xs font-bold text-slate-600">Réinitialiser</a>
            <button type="submit" class="rounded-xl bg-primary-600 px-4 py-2 text-xs font-bold text-white">Filtrer</button>
        </div>
    </form>

    {{-- Liste --}}
    <div class="overflow-hidden rounded-3xl border border-slate-200/90 bg-white shadow-xs">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wider text-slate-400">
                    <th class="px-6 py-3.5">Administrateur</th>
                    <th class="px-4 py-3.5">Rôle</th>
                    <th class="px-4 py-3.5">Périmètre</th>
                    <th class="px-4 py-3.5">Statut</th>
                    <th class="px-6 py-3.5 text-right">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($administrateurs as $administrateur)
                    <tr class="border-b border-slate-50 last:border-0 hover:bg-slate-50/40 transition-smooth">
                        <td class="px-6 py-3.5">
                            <p class="font-bold text-slate-900">{{ $administrateur->name }}</p>
                            <p class="text-xs text-slate-400">{{ $administrateur->email }}</p>
                        </td>
                        <td class="px-4 py-3.5">
                            <span class="rounded-lg bg-primary-50 px-2.5 py-1 text-xs font-bold text-primary-800 border border-primary-200">
                                {{ $administrateur->role_libelle }}
                            </span>
                        </td>
                        <td class="px-4 py-3.5 text-xs text-slate-600">
                            {{ $administrateur->faculte?->nom ?? 'Toutes les facultés' }}
                        </td>
                        <td class="px-4 py-3.5">
                            @if($administrateur->actif)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-bold text-emerald-700 border border-emerald-200">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> Actif
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-2.5 py-1 text-[11px] font-bold text-rose-700 border border-rose-200">
                                    <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span> Désactivé
                                </span>
                            @endif
                        </td>
                        <td class="px-6 py-3.5 text-right">
                            @if($administrateur->id === auth()->id())
                                <span class="text-xs text-slate-300 font-semibold">Votre compte</span>
                            @else
                                <form method="POST" action="{{ route('admin.administrateurs.statut', $administrateur) }}"
                                      onsubmit="return confirm('{{ $administrateur->actif ? 'Désactiver' : 'Réactiver' }} le compte « '.e($administrateur->name).' » ?');">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit"
                                            class="rounded-lg px-3 py-1.5 text-xs font-bold border transition-smooth
                                                {{ $administrateur->actif
                                                    ? 'border-rose-200 text-rose-600 hover:bg-rose-50'
                                                    : 'border-emerald-200 text-emerald-600 hover:bg-emerald-50' }}">
                                        {{ $administrateur->actif ? 'Désactiver' : 'Réactiver' }}
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-10 text-center text-sm text-slate-400">Aucun administrateur enregistré.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $administrateurs->links() }}</div>
</div>
@endsection

@extends('layouts.app')

@section('title', $estSysteme ? 'Rôle système — ' . $role['nom'] : 'Rôle — ' . $profil->nom)

@section('content')
@php
    $libellesStatut = [
        'brouillon' => ['Brouillon', 'bg-slate-100 text-slate-600 border-slate-200'],
        'soumis' => ['Soumis', 'bg-amber-50 text-amber-700 border-amber-200'],
        'actif' => ['Actif', 'bg-emerald-50 text-emerald-700 border-emerald-200'],
        'suspendu' => ['Suspendu', 'bg-rose-50 text-rose-700 border-rose-200'],
        'archive' => ['Archivé', 'bg-slate-100 text-slate-500 border-slate-200'],
        'systeme' => ['Système protégé', 'bg-indigo-50 text-indigo-700 border-indigo-200'],
    ];
    $statutCourant = $estSysteme ? 'systeme' : $profil->statut;
    [$libelleStatut, $classesStatut] = $libellesStatut[$statutCourant] ?? [ucfirst($statutCourant), 'bg-slate-100 text-slate-600 border-slate-200'];
    $hierarchie = \App\Support\Iam\CataloguePermissions::hierarchie($permissions);
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-page-header :titre="$estSysteme ? $role['nom'] : $profil->nom"
        :description="$estSysteme ? $role['description'] : ($profil->description ?: 'Rôle personnalisé de la gouvernance IAM.')"
        :fil="['Gouvernance IAM' => '#', 'Rôles' => route('admin.roles.index'), ($estSysteme ? $role['code'] : $profil->code) => '#']">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.roles.index') }}"
                   class="rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">
                    ← Retour
                </a>
                @unless($estSysteme)
                    @can('roles.manage')
                        <a href="{{ route('admin.roles.edit', $profil->id) }}"
                           class="rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">
                            Modifier
                        </a>
                        <a href="{{ route('admin.roles.permissions.edit', $profil->id) }}"
                           class="rounded-xl border border-primary-200 bg-primary-50/60 px-3.5 py-2 text-xs font-bold text-primary-800 hover:bg-primary-100">
                            Permissions
                        </a>
                        <a href="{{ route('admin.roles.affectations.index', $profil->id) }}"
                           class="rounded-xl bg-primary-800 px-3.5 py-2 text-xs font-bold text-white hover:bg-primary-700">
                            Affecter des comptes
                        </a>
                    @endcan
                @endunless
            </div>
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
            <ul class="list-inside list-disc space-y-1 text-xs text-rose-700">
                @foreach($errors->all() as $erreur)
                    <li>{{ $erreur }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Synthèse --}}
    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="ds-card p-4">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Statut</p>
            <p class="mt-2">
                <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-black {{ $classesStatut }}">
                    {{ $libelleStatut }}
                </span>
            </p>
        </div>
        <div class="ds-card p-4">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Permissions</p>
            <p class="mt-1 text-2xl font-black text-slate-900">{{ count($permissions) }}</p>
        </div>
        <div class="ds-card p-4">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Portée</p>
            <p class="mt-1 text-sm font-black text-slate-900">
                {{ $estSysteme ? $role['portee'] : ($profil->faculte?->nom ?? 'Globale') }}
            </p>
        </div>
        <div class="ds-card p-4">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                {{ $estSysteme ? 'Utilisateurs' : 'Version' }}
            </p>
            <p class="mt-1 text-2xl font-black text-slate-900">
                {{ $estSysteme ? $role['effectif'] : 'v' . $profil->version }}
            </p>
        </div>
    </div>

    @unless($estSysteme)
        {{-- Métadonnées du rôle personnalisé --}}
        <section class="ds-card mt-6 p-6" aria-labelledby="metadonnees-role">
            <h2 id="metadonnees-role" class="text-sm font-black text-slate-900">Métadonnées de gouvernance</h2>
            <dl class="mt-4 grid grid-cols-1 gap-4 text-xs sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="font-bold uppercase tracking-wider text-slate-400">Code technique</dt>
                    <dd class="mt-1 font-mono text-slate-800">{{ $profil->code }}</dd>
                </div>
                <div>
                    <dt class="font-bold uppercase tracking-wider text-slate-400">Créé par</dt>
                    <dd class="mt-1 font-semibold text-slate-800">{{ $profil->createur?->name ?? 'Système' }}</dd>
                </div>
                <div>
                    <dt class="font-bold uppercase tracking-wider text-slate-400">Approuvé par</dt>
                    <dd class="mt-1 font-semibold text-slate-800">{{ $profil->approbateur?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="font-bold uppercase tracking-wider text-slate-400">Approuvé le</dt>
                    <dd class="mt-1 font-semibold text-slate-800">{{ $profil->approuve_le?->format('d/m/Y H:i') ?? '—' }}</dd>
                </div>
            </dl>

            @can('roles.manage')
                <div class="mt-6 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-5">
                    @if(in_array($profil->statut, [\App\Models\Iam\ProfilIam::STATUT_BROUILLON, \App\Models\Iam\ProfilIam::STATUT_SOUMIS], true))
                        <form method="POST" action="{{ route('admin.roles.activer', $profil->id) }}"
                              onsubmit="return confirm('Activer ce rôle ? Il deviendra affectable selon les règles de validation.');">
                            @csrf
                            <button type="submit" class="rounded-xl bg-emerald-700 px-3.5 py-2 text-xs font-bold text-white hover:bg-emerald-600">
                                Activer le rôle
                            </button>
                        </form>
                    @endif

                    @if($profil->statut === \App\Models\Iam\ProfilIam::STATUT_ACTIF)
                        <form method="POST" action="{{ route('admin.roles.suspendre', $profil->id) }}"
                              onsubmit="return confirm('Suspendre ce rôle ? Les affectations actives cesseront d’être effectives.');">
                            @csrf
                            <input type="hidden" name="motif" value="Suspension depuis la fiche du rôle">
                            <button type="submit" class="rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2 text-xs font-bold text-rose-700 hover:bg-rose-100">
                                Suspendre
                            </button>
                        </form>
                    @endif

                    @if($profil->statut === \App\Models\Iam\ProfilIam::STATUT_SUSPENDU)
                        <form method="POST" action="{{ route('admin.roles.reactiver', $profil->id) }}"
                              onsubmit="return confirm('Réactiver ce rôle ?');">
                            @csrf
                            <button type="submit" class="rounded-xl bg-emerald-700 px-3.5 py-2 text-xs font-bold text-white hover:bg-emerald-600">
                                Réactiver
                            </button>
                        </form>
                    @endif

                    @if($profil->statut !== \App\Models\Iam\ProfilIam::STATUT_ARCHIVE)
                        <form method="POST" action="{{ route('admin.roles.archiver', $profil->id) }}"
                              onsubmit="return confirm('Archiver définitivement ce rôle ? Cette action le rend inutilisable.');">
                            @csrf
                            <button type="submit" class="rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">
                                Archiver
                            </button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('admin.roles.destroy', $profil->id) }}"
                          onsubmit="return confirm('Supprimer physiquement ce rôle ? Refusé si des affectations existent.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="rounded-xl border border-rose-200 bg-white px-3.5 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50">
                            Supprimer
                        </button>
                    </form>
                </div>
            @endcan
        </section>
    @endunless

    {{-- Permissions par module --}}
    <section class="ds-card mt-6 p-6" aria-labelledby="permissions-effectives">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="permissions-effectives" class="text-sm font-black text-slate-900">Permissions effectives</h2>
                <p class="mt-1 text-xs text-slate-500">Vue hiérarchique module → ressource → action.</p>
            </div>
            @unless($estSysteme)
                @can('roles.manage')
                    <a href="{{ route('admin.roles.permissions.edit', $profil->id) }}"
                       class="rounded-xl border border-primary-200 bg-primary-50/60 px-3.5 py-2 text-xs font-bold text-primary-800 hover:bg-primary-100">
                        Modifier les permissions
                    </a>
                @endcan
            @endunless
        </div>

        @if(empty($hierarchie))
            <div class="mt-5 rounded-2xl border border-dashed border-slate-200 bg-slate-50/60 p-8 text-center">
                <p class="text-sm font-bold text-slate-700">Aucune permission attribuée</p>
                <p class="mt-1 text-xs text-slate-500">Ce rôle n’accorde encore aucun droit.</p>
            </div>
        @else
            <div class="mt-5 space-y-3">
                @foreach($hierarchie as $module)
                    <details class="rounded-2xl border border-slate-200 bg-slate-50/40" open>
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-bold text-slate-800">{{ $module['libelle'] }}</span>
                                <span class="rounded-full bg-white px-2 py-0.5 text-[10px] font-bold text-slate-500 ring-1 ring-slate-200">
                                    {{ $module['nb_permissions'] }}
                                </span>
                                @if($module['nb_sensibles'] > 0)
                                    <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-700 ring-1 ring-amber-200">
                                        {{ $module['nb_sensibles'] }} sensible(s)
                                    </span>
                                @endif
                            </span>
                            <span class="text-xs font-bold text-slate-400" aria-hidden="true">▾</span>
                        </summary>

                        <div class="space-y-3 border-t border-slate-200 bg-white px-4 py-4">
                            @foreach($module['modules_ressources'] as $ressource)
                                <div>
                                    <p class="text-[11px] font-black uppercase tracking-wider text-slate-500">{{ $ressource['libelle'] }}</p>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @foreach($ressource['actions'] as $permission)
                                            <span class="inline-flex items-center gap-1.5 rounded-xl border px-2.5 py-1.5 text-[11px]
                                                         {{ ($permission['criticite'] ?? 'normale') === 'critique' ? 'border-rose-200 bg-rose-50 text-rose-700' : (($permission['criticite'] ?? 'normale') === 'elevee' ? 'border-amber-200 bg-amber-50 text-amber-700' : 'border-slate-200 bg-slate-50 text-slate-700') }}">
                                                <span class="font-bold">{{ $permission['action_libelle'] }}</span>
                                                <code class="font-mono text-[10px] opacity-70">{{ $permission['code'] }}</code>
                                                @if(($permission['criticite'] ?? 'normale') === 'critique')
                                                    <span class="font-black" title="Permission critique">●</span>
                                                @endif
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endforeach
            </div>
        @endif
    </section>

    @unless($estSysteme)
        <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
            {{-- Affectations --}}
            <section class="ds-card p-6" aria-labelledby="affectations-role">
                <div class="flex items-center justify-between gap-3">
                    <h2 id="affectations-role" class="text-sm font-black text-slate-900">Affectations</h2>
                    <a href="{{ route('admin.roles.affectations.index', $profil->id) }}"
                       class="text-xs font-bold text-primary-800 hover:underline">Gérer →</a>
                </div>

                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-slate-200 text-[10px] font-black uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="py-2 pr-3">Compte</th>
                                <th class="py-2 pr-3">Statut</th>
                                <th class="py-2 pr-3">Début</th>
                                <th class="py-2">Expiration</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($affectations as $affectation)
                                <tr>
                                    <td class="py-2 pr-3">
                                        <p class="font-bold text-slate-800">{{ $affectation->user?->name ?? 'Compte supprimé' }}</p>
                                        <p class="text-[10px] text-slate-400">{{ $affectation->user?->email }}</p>
                                    </td>
                                    <td class="py-2 pr-3">
                                        <span class="rounded-full px-2 py-0.5 text-[10px] font-bold
                                                     {{ $affectation->statut === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                                            {{ ucfirst($affectation->statut) }}
                                        </span>
                                    </td>
                                    <td class="py-2 pr-3 text-slate-600">{{ $affectation->debut_le?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="py-2 text-slate-600">{{ $affectation->expire_le?->format('d/m/Y') ?? 'Permanente' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="py-6 text-center text-xs text-slate-500">Aucune affectation enregistrée.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Demandes à quatre yeux --}}
            <section class="ds-card p-6" aria-labelledby="demandes-role">
                <div class="flex items-center justify-between gap-3">
                    <h2 id="demandes-role" class="text-sm font-black text-slate-900">Demandes à quatre yeux</h2>
                    <a href="{{ route('admin.roles.demandes.index') }}"
                       class="text-xs font-bold text-primary-800 hover:underline">Circuit →</a>
                </div>

                <div class="mt-4 space-y-2">
                    @forelse($demandes as $demande)
                        <a href="{{ route('admin.roles.demandes.show', $demande->id) }}"
                           class="block rounded-xl border border-slate-200 px-3 py-2.5 text-xs hover:border-primary-300 hover:bg-primary-50/30">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-bold text-slate-800">{{ $demande->libelleType() }}</span>
                                <span class="rounded-full px-2 py-0.5 text-[10px] font-bold
                                             {{ $demande->statut === 'soumise' ? 'bg-amber-50 text-amber-700' : ($demande->statut === 'approuvee' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500') }}">
                                    {{ ucfirst($demande->statut) }}
                                </span>
                            </div>
                            <p class="mt-0.5 truncate font-mono text-[10px] text-slate-400">{{ $demande->reference }}</p>
                        </a>
                    @empty
                        <p class="rounded-xl border border-dashed border-slate-200 bg-slate-50/60 px-3 py-6 text-center text-xs text-slate-500">
                            Aucune demande pour ce rôle.
                        </p>
                    @endforelse
                </div>
            </section>
        </div>

        {{-- Journal d'audit --}}
        <section class="ds-card mt-6 p-6" aria-labelledby="audit-role">
            <h2 id="audit-role" class="text-sm font-black text-slate-900">Journal d’audit (25 derniers événements)</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-slate-200 text-[10px] font-black uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="py-2 pr-3">Horodatage</th>
                            <th class="py-2 pr-3">Événement</th>
                            <th class="py-2 pr-3">Acteur</th>
                            <th class="py-2">Empreinte</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($evenements as $evenement)
                            <tr>
                                <td class="py-2 pr-3 text-slate-500">{{ $evenement->created_at?->format('d/m/Y H:i:s') }}</td>
                                <td class="py-2 pr-3 font-mono text-[11px] text-slate-700">{{ $evenement->type }}</td>
                                <td class="py-2 pr-3 text-slate-600">{{ $evenement->acteur?->name ?? 'Système' }}</td>
                                <td class="py-2 font-mono text-[10px] text-slate-400" title="{{ $evenement->empreinte }}">
                                    {{ $evenement->empreinte ? substr($evenement->empreinte, 0, 12) . '…' : '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-6 text-center text-xs text-slate-500">Aucun événement enregistré.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endunless
</div>
@endsection

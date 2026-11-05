@extends('layouts.app')

@section('title', 'Permissions du rôle — ' . $profil->nom)

@section('content')
@php
    $critiquesAttribuees = array_values(array_filter(
        $attribuees,
        fn (string $code): bool => \App\Support\Iam\CataloguePermissions::critique($code)
    ));
@endphp

<div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
    <x-page-header titre="Permissions de « {{ $profil->nom }} »"
        description="Sélection hiérarchique des permissions administrables. Les dépendances déclarées sont vérifiées côté serveur et l’ensemble est refusé si elles manquent."
        :fil="['Gouvernance IAM' => '#', 'Rôles' => route('admin.roles.index'), $profil->nom => route('admin.roles.show', $profil->id), 'Permissions' => '#']">
        <x-slot:actions>
            <a href="{{ route('admin.roles.show', $profil->id) }}"
               class="rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">
                ← Retour à la fiche
            </a>
        </x-slot:actions>
    </x-page-header>

    @if($errors->any())
        <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50/90 p-4" role="alert">
            <h2 class="text-sm font-bold text-rose-900">La sélection n’a pas été appliquée :</h2>
            <ul class="mt-2 list-inside list-disc space-y-1 text-xs text-rose-700">
                @foreach($errors->all() as $erreur)
                    <li>{{ $erreur }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="ds-card p-4">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Permissions attribuées</p>
            <p class="mt-1 text-2xl font-black text-slate-900">{{ count($attribuees) }}</p>
        </div>
        <div class="ds-card p-4">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Dont critiques</p>
            <p class="mt-1 text-2xl font-black {{ count($critiquesAttribuees) > 0 ? 'text-rose-600' : 'text-slate-900' }}">
                {{ count($critiquesAttribuees) }}
            </p>
        </div>
        <div class="ds-card p-4">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Catalogue total</p>
            <p class="mt-1 text-2xl font-black text-slate-900">{{ $totalPermissions }}</p>
        </div>
    </div>

    @if(count($critiquesAttribuees) > 0)
        <div class="mt-4 rounded-2xl border border-rose-200 bg-rose-50/70 px-4 py-3 text-xs text-rose-800">
            <p class="font-bold">Ce rôle contient {{ count($critiquesAttribuees) }} permission(s) critique(s).</p>
            <p class="mt-1 text-rose-700">
                L’ajout d’une permission critique par un acteur non super-administrateur est soumis au circuit à quatre yeux :
                la modification n’est pas appliquée avant approbation indépendante.
            </p>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.roles.permissions.update', $profil->id) }}" class="mt-6 space-y-6">
        @csrf

        <section class="ds-card p-6" aria-labelledby="selection-permissions">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="selection-permissions" class="text-sm font-black text-slate-900">Sélection des permissions</h2>
                    <p class="mt-1 text-xs text-slate-500">
                        Les permissions grisées sont réservées aux rôles système et ne peuvent pas être déléguées.
                    </p>
                </div>
                <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] font-bold text-slate-600">
                    {{ count($attribuees) }} / {{ $totalPermissions }} sélectionnée(s)
                </span>
            </div>

            <div class="mt-5 space-y-3">
                @foreach($hierarchie as $module)
                    @php
                        $codesModule = collect($module['modules_ressources'])
                            ->flatMap(fn ($ressource) => collect($ressource['actions'])->pluck('code'))
                            ->all();
                        $nbSelectionnees = count(array_intersect($codesModule, $attribuees));
                    @endphp
                    <details class="rounded-2xl border border-slate-200 bg-slate-50/40" @if($nbSelectionnees > 0) open @endif>
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-bold text-slate-800">{{ $module['libelle'] }}</span>
                                <span class="rounded-full bg-white px-2 py-0.5 text-[10px] font-bold text-slate-500 ring-1 ring-slate-200">
                                    {{ $nbSelectionnees }} / {{ $module['nb_permissions'] }}
                                </span>
                                @if($module['nb_sensibles'] > 0)
                                    <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-700 ring-1 ring-amber-200">
                                        {{ $module['nb_sensibles'] }} sensible(s)
                                    </span>
                                @endif
                            </span>
                            <span class="text-xs font-bold text-slate-400" aria-hidden="true">▾</span>
                        </summary>

                        <div class="space-y-4 border-t border-slate-200 bg-white px-4 py-4">
                            @foreach($module['modules_ressources'] as $ressource)
                                <fieldset>
                                    <legend class="text-[11px] font-black uppercase tracking-wider text-slate-500">
                                        {{ $ressource['libelle'] }}
                                    </legend>
                                    <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        @foreach($ressource['actions'] as $permission)
                                            @php
                                                $administrable = (bool) ($permission['administrable'] ?? false);
                                                $codePermission = (string) $permission['code'];
                                                $estAttribuee = in_array($codePermission, $attribuees, true);
                                            @endphp
                                            <label class="flex items-start gap-2 rounded-xl border px-3 py-2 text-xs transition
                                                          {{ $administrable ? 'cursor-pointer border-slate-200 hover:border-primary-300 hover:bg-primary-50/40' : 'cursor-not-allowed border-slate-100 bg-slate-50 opacity-60' }}">
                                                <input type="checkbox" name="permissions[]" value="{{ $codePermission }}"
                                                       @checked($estAttribuee)
                                                       @disabled(! $administrable)
                                                       class="mt-0.5 h-4 w-4 rounded border-slate-300 text-primary-700 focus:ring-primary-500">
                                                <span class="min-w-0">
                                                    <span class="block font-bold text-slate-800">{{ $permission['action_libelle'] }}</span>
                                                    <code class="block truncate font-mono text-[10px] text-slate-400">{{ $codePermission }}</code>
                                                    @if(! $administrable)
                                                        <span class="mt-0.5 block text-[10px] font-bold text-slate-400">Réservé système</span>
                                                    @elseif(($permission['criticite'] ?? 'normale') === 'critique')
                                                        <span class="mt-0.5 block text-[10px] font-bold text-rose-600">Critique — 4 yeux</span>
                                                    @elseif(($permission['criticite'] ?? 'normale') === 'elevee')
                                                        <span class="mt-0.5 block text-[10px] font-bold text-amber-600">Élevée</span>
                                                    @endif
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                </fieldset>
                            @endforeach
                        </div>
                    </details>
                @endforeach
            </div>
        </section>

        <section class="ds-card p-6">
            <label for="motif" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-700">
                Motif de la modification
            </label>
            <textarea id="motif" name="motif" rows="2" maxlength="500"
                      placeholder="Ex. : extension du périmètre de consultation pour la scolarité."
                      class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">{{ old('motif') }}</textarea>
            <p class="mt-1.5 text-[11px] text-slate-400">Le motif est conservé dans le journal d’audit IAM.</p>
        </section>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('admin.roles.show', $profil->id) }}"
               class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50">
                Annuler
            </a>
            <button type="submit"
                    class="rounded-xl bg-primary-800 px-5 py-2.5 text-xs font-black text-white shadow-sm hover:bg-primary-700">
                Appliquer la sélection
            </button>
        </div>
    </form>
</div>
@endsection

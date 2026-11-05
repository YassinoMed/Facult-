@extends('layouts.app')

@section('title', 'Créer un rôle personnalisé')

@section('content')
<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
    <x-page-header titre="Créer un rôle personnalisé"
        description="Un rôle personnalisé est un sous-ensemble borné de permissions administrables. Il naît en brouillon et doit être activé avant toute affectation."
        :fil="['Gouvernance IAM' => '#', 'Rôles' => route('admin.roles.index'), 'Créer' => route('admin.roles.create')]" />

    @if($errors->any())
        <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50/90 p-4" role="alert">
            <h2 class="text-sm font-bold text-rose-900">Veuillez corriger les champs suivants :</h2>
            <ul class="mt-2 list-inside list-disc space-y-1 text-xs text-rose-700">
                @foreach($errors->all() as $erreur)
                    <li>{{ $erreur }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.roles.store') }}" class="mt-6 space-y-6">
        @csrf

        <section class="ds-card p-6" aria-labelledby="identite-role">
            <h2 id="identite-role" class="text-sm font-black text-slate-900">Identité du rôle</h2>
            <p class="mt-1 text-xs text-slate-500">Le code technique est immuable et sert de référence aux affectations et à l’audit.</p>

            <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <label for="code" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-700">
                        Code technique <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" id="code" name="code" value="{{ old('code') }}" required minlength="3" maxlength="60"
                           pattern="[a-z0-9_\-]+" autocomplete="off"
                           class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 font-mono text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                    <p class="mt-1.5 text-[11px] text-slate-400">Lettres minuscules, chiffres, tirets et underscores uniquement.</p>
                </div>

                <div>
                    <label for="nom" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-700">
                        Libellé <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" id="nom" name="nom" value="{{ old('nom') }}" required minlength="3" maxlength="150"
                           class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                </div>

                <div class="sm:col-span-2">
                    <label for="description" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-700">
                        Description
                    </label>
                    <textarea id="description" name="description" rows="3" maxlength="1000"
                              class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">{{ old('description') }}</textarea>
                </div>

                <div class="sm:col-span-2">
                    <label for="faculte_id" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-700">
                        Portée facultaire
                    </label>
                    <select id="faculte_id" name="faculte_id"
                            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                        <option value="">— Rôle global (toutes facultés) —</option>
                        @foreach($facultes as $faculte)
                            <option value="{{ $faculte->id }}" @selected((string) old('faculte_id') === (string) $faculte->id)>
                                {{ $faculte->nom }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1.5 text-[11px] text-slate-400">
                        Un administrateur facultaire ne peut créer que des rôles rattachés à sa propre faculté.
                    </p>
                </div>
            </div>
        </section>

        <section class="ds-card p-6" aria-labelledby="permissions-role">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="permissions-role" class="text-sm font-black text-slate-900">Permissions initiales</h2>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ $totalPermissions }} permissions au catalogue. Seules les permissions administrables peuvent être sélectionnées.
                        Les permissions critiques déclenchent une validation à quatre yeux à l’activation.
                    </p>
                </div>
                <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] font-bold text-slate-600">
                    Sélection facultative — modifiable ensuite
                </span>
            </div>

            <div class="mt-5 space-y-3">
                @foreach($hierarchiePermissions as $module)
                    <details class="rounded-2xl border border-slate-200 bg-slate-50/40">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3">
                            <span class="flex items-center gap-2">
                                <span class="text-sm font-bold text-slate-800">{{ $module['libelle'] }}</span>
                                <span class="rounded-full bg-white px-2 py-0.5 text-[10px] font-bold text-slate-500 ring-1 ring-slate-200">
                                    {{ $module['nb_permissions'] }} permission(s)
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
                                            @endphp
                                            <label class="flex items-start gap-2 rounded-xl border px-3 py-2 text-xs transition
                                                          {{ $administrable ? 'cursor-pointer border-slate-200 hover:border-primary-300 hover:bg-primary-50/40' : 'cursor-not-allowed border-slate-100 bg-slate-50 opacity-60' }}">
                                                <input type="checkbox" name="permissions[]" value="{{ $codePermission }}"
                                                       @checked(in_array($codePermission, (array) old('permissions', []), true))
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

        <div class="flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('admin.roles.index') }}"
               class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50">
                Annuler
            </a>
            <button type="submit"
                    class="rounded-xl bg-primary-800 px-5 py-2.5 text-xs font-black text-white shadow-sm hover:bg-primary-700">
                Créer le rôle en brouillon
            </button>
        </div>
    </form>
</div>
@endsection

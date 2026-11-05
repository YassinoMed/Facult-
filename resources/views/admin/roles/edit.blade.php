@extends('layouts.app')

@section('title', 'Modifier le rôle — ' . $profil->nom)

@section('content')
<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
    <x-page-header titre="Modifier « {{ $profil->nom }} »"
        description="La modification incrémente la version du rôle. Les affectations existantes conservent l’ancienne version et cessent d’être effectives jusqu’à réaffectation explicite."
        :fil="['Gouvernance IAM' => '#', 'Rôles' => route('admin.roles.index'), $profil->nom => route('admin.roles.show', $profil->id), 'Modifier' => '#']" />

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

    <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-xs text-amber-800">
        <p class="font-bold">Version actuelle : v{{ $profil->version }} — statut : {{ ucfirst($profil->statut) }}.</p>
        <p class="mt-1 text-amber-700">
            Toute modification invalide le cache d’autorisation des comptes affectés. Réaffectez-les après validation si nécessaire.
        </p>
    </div>

    <form method="POST" action="{{ route('admin.roles.update', $profil->id) }}" class="mt-6 space-y-6">
        @csrf
        @method('PATCH')

        <section class="ds-card p-6">
            <h2 class="text-sm font-black text-slate-900">Caractéristiques</h2>

            <div class="mt-5 space-y-5">
                <div>
                    <label class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-700">Code technique</label>
                    <input type="text" value="{{ $profil->code }}" readonly disabled
                           class="w-full rounded-xl border border-slate-200 bg-slate-100 px-3.5 py-2.5 font-mono text-sm text-slate-500">
                    <p class="mt-1.5 text-[11px] text-slate-400">Le code est immuable : il identifie le rôle dans l’audit et les affectations.</p>
                </div>

                <div>
                    <label for="nom" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-700">
                        Libellé <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" id="nom" name="nom" value="{{ old('nom', $profil->nom) }}" required minlength="3" maxlength="150"
                           class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                </div>

                <div>
                    <label for="description" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-700">Description</label>
                    <textarea id="description" name="description" rows="4" maxlength="1000"
                              class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">{{ old('description', $profil->description) }}</textarea>
                </div>

                <div>
                    <label for="faculte_id" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-700">Portée facultaire</label>
                    @if(auth()->user()?->estSuperAdmin())
                        <select id="faculte_id" name="faculte_id"
                                class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                            <option value="">— Rôle global (toutes facultés) —</option>
                            @foreach($facultes as $faculte)
                                <option value="{{ $faculte->id }}" @selected((string) old('faculte_id', $profil->faculte_id) === (string) $faculte->id)>
                                    {{ $faculte->nom }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1.5 text-[11px] text-slate-400">Seul un super-administrateur peut déplacer la portée d’un rôle.</p>
                    @else
                        <input type="text" value="{{ $profil->faculte?->nom ?? 'Globale' }}" readonly disabled
                               class="w-full rounded-xl border border-slate-200 bg-slate-100 px-3.5 py-2.5 text-sm text-slate-500">
                        <p class="mt-1.5 text-[11px] text-slate-400">La portée facultaire est verrouillée pour votre profil.</p>
                    @endif
                </div>
            </div>
        </section>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('admin.roles.show', $profil->id) }}"
               class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50">
                Annuler
            </a>
            <button type="submit"
                    class="rounded-xl bg-primary-800 px-5 py-2.5 text-xs font-black text-white shadow-sm hover:bg-primary-700">
                Enregistrer les modifications
            </button>
        </div>
    </form>
</div>
@endsection

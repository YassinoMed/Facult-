@extends('layouts.app')

@section('title', isset($configuration->id) ? 'Modifier SMTP' : 'Nouvelle configuration SMTP')

@section('content')
<div class="mx-auto max-w-2xl px-4 sm:px-6 py-8">
    <x-page-header :titre="isset($configuration->id) ? 'Modifier SMTP' : 'Nouvelle configuration SMTP'"
        description="Le mot de passe n'est jamais réaffiché : vide = conservé." :fil="['SMTP' => route('admin.smtp.index')]" />

    <form method="POST" action="{{ isset($configuration->id) ? route('admin.smtp.update', $configuration) : route('admin.smtp.store') }}"
          class="mt-4 ds-card space-y-4 p-5">
        @csrf
        @if(isset($configuration->id)) @method('PUT') @endif

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="smtp-nom">Nom *</label>
                <input id="smtp-nom" name="nom" required maxlength="100" value="{{ old('nom', $configuration->nom) }}"
                       class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm" />
                @error('nom')<p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="smtp-hote">Hôte *</label>
                <input id="smtp-hote" name="hote" required maxlength="255" value="{{ old('hote', $configuration->hote) }}"
                       class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm font-mono" />
                @error('hote')<p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="smtp-port">Port *</label>
                <input id="smtp-port" name="port" type="number" required min="1" max="65535" value="{{ old('port', $configuration->port ?? 587) }}"
                       class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm" />
                @error('port')<p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="smtp-chiffrement">Chiffrement *</label>
                <select id="smtp-chiffrement" name="chiffrement" required class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm">
                    @foreach(['tls' => 'STARTTLS (587)', 'ssl' => 'SSL (465)', 'aucun' => 'Aucun (déconseillé)'] as $valeur => $libelle)
                        <option value="{{ $valeur }}" {{ old('chiffrement', $configuration->chiffrement ?? 'tls') === $valeur ? 'selected' : '' }}>{{ $libelle }}</option>
                    @endforeach
                </select>
                @error('chiffrement')<p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="smtp-identifiant">Identifiant</label>
                <input id="smtp-identifiant" name="identifiant" maxlength="255" value="{{ old('identifiant', $configuration->identifiant) }}"
                       class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm" autocomplete="off" />
                @error('identifiant')<p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="smtp-mdp">Mot de passe {{ isset($configuration->id) ? '(vide = conservé)' : '*' }}</label>
                <input id="smtp-mdp" name="mot_de_passe" type="password" {{ isset($configuration->id) ? '' : 'required' }} maxlength="500"
                       class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm" autocomplete="new-password" />
                @error('mot_de_passe')<p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="smtp-exp">Expéditeur *</label>
                <input id="smtp-exp" name="expediteur" type="email" required maxlength="255" value="{{ old('expediteur', $configuration->expediteur) }}"
                       class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm" />
                @error('expediteur')<p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="smtp-nomexp">Nom expéditeur</label>
                <input id="smtp-nomexp" name="nom_expediteur" maxlength="255" value="{{ old('nom_expediteur', $configuration->nom_expediteur) }}"
                       class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm" />
                @error('nom_expediteur')<p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>@enderror
            </div>
        </div>

        <label class="flex items-center gap-2 text-xs font-bold text-slate-700">
            <input type="checkbox" name="actif" value="1" {{ old('actif', $configuration->actif) ? 'checked' : '' }} class="rounded border-slate-300">
            Configuration active (désactive les autres)
        </label>

        <div class="flex justify-end gap-2">
            <a href="{{ route('admin.smtp.index') }}" class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold text-slate-600">Annuler</a>
            <button type="submit" class="rounded-xl bg-primary-600 px-4 py-2 text-xs font-bold text-white">Enregistrer</button>
        </div>
    </form>
</div>
@endsection

@extends('layouts.app')

@section('title', 'Modifier un administrateur')

@section('content')
    <div class="mx-auto max-w-2xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Modifier — ' . $administrateur->name" description="Rôle, rattachement, réinitialisation."
            :fil="['Administrateurs' => route('admin.administrateurs.index'), 'Modifier' => '#']" />

        <form method="post" action="{{ route('admin.administrateurs.update', $administrateur) }}" class="mt-4 ds-card space-y-4 p-5">
            @csrf @method('PUT')
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="name">Nom</label>
                <input id="name" name="name" minlength="2" maxlength="120" required value="{{ old('name', $administrateur->name) }}" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" />
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="role">Rôle</label>
                <select id="role" name="role" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2">
                    @foreach(\App\Support\Permissions::ROLES as $role)
                        <option value="{{ $role }}" @selected(old('role', $administrateur->role) === $role)>{{ $role }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="faculte">Faculté (admin_faculte uniquement)</label>
                <select id="faculte" name="faculte_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2">
                    <option value="">—</option>
                    @foreach($facultes as $faculte)
                        <option value="{{ $faculte->id }}" @selected((int) old('faculte_id', $administrateur->faculte_id) === $faculte->id)>{{ $faculte->nom }}</option>
                    @endforeach
                </select>
            </div>
            @if($errors->any())
                <x-alert type="error">{{ $errors->first() }}</x-alert>
            @endif
            <x-btn type="submit">Enregistrer</x-btn>
        </form>

        <form method="post" action="{{ route('admin.administrateurs.reinitialiser', $administrateur) }}" class="mt-4 ds-card space-y-4 p-5">
            @csrf
            <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Réinitialiser le mot de passe</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <input type="password" name="password" minlength="10" autocomplete="new-password" placeholder="Nouveau mot de passe" required class="rounded-xl border border-slate-200 px-3 py-2" />
                <input type="password" name="password_confirmation" minlength="10" autocomplete="new-password" placeholder="Confirmation" required class="rounded-xl border border-slate-200 px-3 py-2" />
            </div>
            <x-btn variant="secondary" type="submit">Réinitialiser</x-btn>
        </form>
    </div>
@endsection

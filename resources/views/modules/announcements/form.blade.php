@extends('layouts.app')

@section('title', 'Nouvelle annonce')

@section('content')
    <div class="mx-auto max-w-2xl px-4 sm:px-6 py-8">
        <x-page-header titre="Nouvelle annonce" description="Brouillon puis publication avec diffusion ciblée."
            :fil="['Annonces' => route('admin.annonces.index'), 'Nouvelle' => '#']" />

        <form method="post" action="{{ route('admin.annonces.store') }}" class="mt-4 ds-card space-y-4 p-5">
            @csrf
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="titre">Titre</label>
                <input id="titre" name="titre" maxlength="150" required value="{{ old('titre') }}" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" />
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="contenu">Contenu</label>
                <textarea id="contenu" name="contenu" rows="5" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2">{{ old('contenu') }}</textarea>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="type">Type</label>
                    <select id="type" name="type" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2">
                        @foreach(\App\Modules\Announcements\Models\Annonce::TYPES as $type)
                            <option value="{{ $type }}">{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="audience">Audience</label>
                    <select id="audience" name="audience" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2">
                        @foreach(\App\Modules\Announcements\Models\Annonce::AUDIENCES as $audience)
                            <option value="{{ $audience }}">{{ $audience }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="expire">Expiration (optionnel)</label>
                <input id="expire" type="date" name="expire_a" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" />
            </div>
            @if($errors->any())
                <x-alert type="error">{{ $errors->first() }}</x-alert>
            @endif
            <x-btn type="submit">Créer le brouillon</x-btn>
        </form>
    </div>
@endsection

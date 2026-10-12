@extends('layouts.app')

@section('title', 'Gabarits de notification')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header titre="Gabarits de notification" description="Textes versionnés du centre de communication (variables {nom})."
            :fil="['Gabarits' => '#']" />

        <div class="mt-4 space-y-3">
            @foreach($gabarits as $gabarit)
                <form method="POST" action="{{ route('admin.notifications.templates.update', $gabarit) }}" class="ds-card space-y-3 p-5">
                    @csrf
                    @method('PATCH')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-mono text-sm font-bold">{{ $gabarit->code }}</p>
                        <p class="text-xs text-slate-500">v{{ $gabarit->version }} · {{ $gabarit->actif ? 'actif' : 'inactif' }}</p>
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="titre-{{ $gabarit->id }}">Titre</label>
                        <input id="titre-{{ $gabarit->id }}" name="titre" maxlength="150" required value="{{ $gabarit->titre }}"
                               class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="message-{{ $gabarit->id }}">Message (&#123;&#123;variable&#125;&#125;)</label>
                        <input id="message-{{ $gabarit->id }}" name="message" maxlength="500" required value="{{ $gabarit->message }}"
                               class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="actif" value="1" @checked($gabarit->actif) class="rounded" /> Actif
                    </label>
                    <x-btn size="sm" type="submit">Enregistrer (nouvelle version si texte modifié)</x-btn>
                </form>
            @endforeach
        </div>
        @if($errors->any())
            <div class="mt-4"><x-alert type="error">{{ $errors->first() }}</x-alert></div>
        @endif
    </div>
@endsection

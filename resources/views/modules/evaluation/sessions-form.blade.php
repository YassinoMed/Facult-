@extends('layouts.app')

@section('title', 'Nouvelle session d’examen')

@section('content')
    <div class="mx-auto max-w-2xl px-4 sm:px-6 py-8">
        <x-page-header titre="Nouvelle session" description="Une session appartient à une année universitaire."
            :fil="['Sessions' => route('admin.evaluations.sessions.index'), 'Nouvelle' => '#']" />

        <form method="post" action="{{ route('admin.evaluations.sessions.store') }}" class="mt-4 ds-card space-y-4 p-5">
            @csrf
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="annee">Année universitaire</label>
                <select id="annee" name="annee_universitaire_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" required>
                    @foreach($annees as $annee)
                        <option value="{{ $annee->id }}">{{ $annee->libelle }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="code">Code</label>
                    <input id="code" name="code" maxlength="30" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" placeholder="S1-2026-PRINC" />
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="type">Type</label>
                    <select id="type" name="type" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" required>
                        <option value="principale">Principale</option>
                        <option value="rattrapage">Rattrapage</option>
                        <option value="exceptionnelle">Exceptionnelle</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="nom">Nom</label>
                <input id="nom" name="nom" maxlength="150" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" placeholder="Session principale S1" />
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="origine">Session d’origine (rattrapage uniquement)</label>
                <select id="origine" name="session_origine_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2">
                    <option value="">— Aucune (principale / exceptionnelle) —</option>
                    @foreach($principales as $principale)
                        <option value="{{ $principale->id }}">{{ $principale->nom }} ({{ $principale->anneeUniversitaire?->libelle }})</option>
                    @endforeach
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="debut">Début</label>
                    <input id="debut" type="date" name="date_debut" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" />
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="fin">Fin</label>
                    <input id="fin" type="date" name="date_fin" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" />
                </div>
            </div>
            @if($errors->any())
                <x-alert type="error">{{ $errors->first() }}</x-alert>
            @endif
            <x-btn type="submit">Créer la session</x-btn>
        </form>
    </div>
@endsection

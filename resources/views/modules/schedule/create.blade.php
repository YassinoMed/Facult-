@extends('layouts.app')

@section('title', 'Nouvel emploi du temps — Scolarité')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8 sm:py-12">
        <a href="{{ route('admin.emplois-du-temps.index') }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">← Emplois du temps</a>
        <h1 class="mt-2 text-2xl font-black text-slate-900">Nouvel emploi du temps</h1>

        @if($errors->any())
            <div class="mt-4 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-xs text-rose-800" role="alert">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.emplois-du-temps.store') }}" class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
            @csrf
            <div>
                <label for="nom" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Nom <span class="text-rose-600">*</span></label>
                <input type="text" id="nom" name="nom" value="{{ old('nom') }}" required maxlength="150" placeholder="Ex : L1 Informatique — S1"
                       class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:outline-none">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label for="groupe" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Groupe <span class="text-rose-600">*</span></label>
                    <select id="groupe" name="groupe_id" required class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:outline-none">
                        <option value="">— Choisir —</option>
                        @foreach($groupes as $groupe)
                            <option value="{{ $groupe->id }}">{{ $groupe->code }} ({{ $groupe->faculte->code }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="annee" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Année <span class="text-rose-600">*</span></label>
                    <select id="annee" name="annee_universitaire_id" required class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:outline-none">
                        <option value="">— Choisir —</option>
                        @foreach($annees as $annee)
                            <option value="{{ $annee->id }}">{{ $annee->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="semestre" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Semestre <span class="text-rose-600">*</span></label>
                    <select id="semestre" name="semestre" required class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:outline-none">
                        <option value="1">Semestre 1</option>
                        <option value="2">Semestre 2</option>
                    </select>
                </div>
                <div>
                    <label for="debut-periode" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Début du semestre</label>
                    <input type="date" id="debut-periode" name="starts_at" value="{{ old('starts_at') }}" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:outline-none">
                </div>
                <div>
                    <label for="fin-periode" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Fin du semestre</label>
                    <input type="date" id="fin-periode" name="ends_at" value="{{ old('ends_at') }}" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:outline-none">
                </div>
            </div>
            <button type="submit" class="rounded-xl bg-primary-800 px-6 py-3 text-sm font-bold text-white hover:bg-primary-700 min-h-[48px]">Créer</button>
        </form>
    </div>
@endsection

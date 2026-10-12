@extends('layouts.app')

@section('title', 'Nouvelle admission')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8">
        <x-page-header titre="Nouvelle admission" description="Admission d’un étudiant vers un programme cible pour une année — acte traçable, consommé une seule fois par la progression."
            :fil="['Scolarité' => route('admin.admissions.index'), 'Nouvelle admission' => '#']" />

        <form method="post" action="{{ route('admin.admissions.store') }}" class="mt-4 ds-card space-y-4 p-6">
            @csrf
            @if($errors->any())
                <x-alert type="error" :message="__('Veuillez corriger les champs en erreur.')" />
            @endif

            <div>
                <label for="etudiant_id" class="mb-1 block text-sm font-bold text-slate-700">Étudiant</label>
                <select id="etudiant_id" name="etudiant_id" required
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-primary-700 focus:bg-white focus:outline-none">
                    <option value="">— Choisir un étudiant —</option>
                    @foreach($etudiants as $etudiant)
                        <option value="{{ $etudiant->id }}" @selected(old('etudiant_id') === (string) $etudiant->id)>
                            {{ $etudiant->nom }} {{ $etudiant->prenom }} — {{ $etudiant->cin }}
                        </option>
                    @endforeach
                </select>
                @error('etudiant_id')<p class="mt-1 text-xs font-bold text-rose-600">{{ $message }}</p>@enderror
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="programme_id" class="mb-1 block text-sm font-bold text-slate-700">Programme cible</label>
                    <select id="programme_id" name="programme_id" required
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-primary-700 focus:bg-white focus:outline-none">
                        <option value="">— Choisir —</option>
                        @foreach($programmes as $programme)
                            <option value="{{ $programme->id }}" @selected(old('programme_id') === (string) $programme->id)>
                                {{ $programme->code }} — {{ $programme->nom }}
                            </option>
                        @endforeach
                    </select>
                    @error('programme_id')<p class="mt-1 text-xs font-bold text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="niveau_id" class="mb-1 block text-sm font-bold text-slate-700">Niveau cible (optionnel)</label>
                    <select id="niveau_id" name="niveau_id"
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-primary-700 focus:bg-white focus:outline-none">
                        <option value="">— Tout niveau du programme —</option>
                        @foreach($niveaux as $niveau)
                            <option value="{{ $niveau->id }}" @selected(old('niveau_id') === (string) $niveau->id)>
                                {{ $niveau->programme->code }} · {{ $niveau->code }} — {{ $niveau->nom }}
                            </option>
                        @endforeach
                    </select>
                    @error('niveau_id')<p class="mt-1 text-xs font-bold text-rose-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="annee_universitaire_id" class="mb-1 block text-sm font-bold text-slate-700">Année visée</label>
                    <select id="annee_universitaire_id" name="annee_universitaire_id" required
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-primary-700 focus:bg-white focus:outline-none">
                        <option value="">— Choisir —</option>
                        @foreach($annees as $annee)
                            <option value="{{ $annee->id }}" @selected(old('annee_universitaire_id') === (string) $annee->id)>
                                {{ $annee->libelle }}
                            </option>
                        @endforeach
                    </select>
                    @error('annee_universitaire_id')<p class="mt-1 text-xs font-bold text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="decision" class="mb-1 block text-sm font-bold text-slate-700">Décision</label>
                    <select id="decision" name="decision" required
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-primary-700 focus:bg-white focus:outline-none">
                        <option value="admis" @selected(old('decision') === 'admis')>Admis</option>
                        <option value="admis_conditionnel" @selected(old('decision') === 'admis_conditionnel')>Admis (conditionnel)</option>
                        <option value="refuse" @selected(old('decision') === 'refuse')>Refusé</option>
                    </select>
                    @error('decision')<p class="mt-1 text-xs font-bold text-rose-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div>
                <label for="motif" class="mb-1 block text-sm font-bold text-slate-700">Motif / référence (optionnel)</label>
                <textarea id="motif" name="motif" rows="2" maxlength="500"
                          class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-primary-700 focus:bg-white focus:outline-none"
                          placeholder="Commission d’admission du…, admission sur titre…">{{ old('motif') }}</textarea>
                @error('motif')<p class="mt-1 text-xs font-bold text-rose-600">{{ $message }}</p>@enderror
            </div>

            <div class="flex items-center justify-end gap-3 pt-2">
                <a href="{{ route('admin.admissions.index') }}" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">Annuler</a>
                <button type="submit" class="rounded-xl bg-primary-800 px-5 py-2.5 text-sm font-bold text-white hover:bg-primary-900">Enregistrer</button>
            </div>
        </form>
    </div>
@endsection

@extends('layouts.app')

@section('title', 'Affectation automatique')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header titre="Affectation automatique" description="Simulation équilibrée SANS écriture, puis confirmation par groupe."
            :fil="['Affectations' => route('admin.affectations.masse'), 'Auto' => '#']" />

        <form method="post" action="{{ route('admin.affectations.auto.simuler') }}" class="mt-4 ds-card grid gap-3 p-5 sm:grid-cols-2">
            @csrf
            <div class="sm:col-span-2">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Groupes candidats (équilibrage par places)</span>
                <div class="mt-1 grid gap-1.5 sm:grid-cols-2">
                    @foreach($groupes as $groupe)
                        <label class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                            <input type="checkbox" name="groupe_ids[]" value="{{ $groupe->id }}" checked />
                            <span class="font-mono font-bold">{{ $groupe->code }}</span>
                            <span class="tabular-nums text-slate-500">{{ $groupe->affectations_actives }}/{{ $groupe->capacite ?? '∞' }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="annee">Année</label>
                <select id="annee" name="annee_universitaire_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    @foreach($annees as $annee)
                        <option value="{{ $annee->id }}" @selected((int) old('annee_universitaire_id', $anneeDefaut ?? 0) === $annee->id)>{{ $annee->libelle }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-2">
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="ids">IDs étudiants (un par ligne, max 5000)</label>
                <textarea id="ids" name="etudiant_ids_text" rows="6" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm font-mono" placeholder="12&#10;45&#10;78" required>{{ old('etudiant_ids_text', $nonAffectesIds ?? '') }}</textarea>
                <p class="mt-1 text-xs text-slate-400">Pré-rempli avec les étudiants non affectés (sans affectation active).</p>
            </div>
            <div class="sm:col-span-2"><x-btn type="submit">Simuler la répartition</x-btn></div>
        </form>
    </div>
@endsection

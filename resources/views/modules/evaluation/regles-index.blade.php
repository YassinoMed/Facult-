@extends('layouts.app')

@section('title', 'Règles d’évaluation')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header titre="Règles d’évaluation" description="Versionnées par année (+ programme) : l’historique snapshoté n’est jamais recalculé."
            :fil="['Évaluations' => route('admin.evaluations.index'), 'Règles' => '#']" />

        <div class="mt-4 ds-card p-5">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
                        <th class="py-2">Année</th>
                        <th class="py-2">Programme</th>
                        <th class="py-2">Seuils</th>
                        <th class="py-2">Compens.</th>
                        <th class="py-2">Version</th>
                        <th class="py-2">Active</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($regles as $regle)
                        <tr class="border-t border-slate-100">
                            <td class="py-2">{{ $regle->anneeUniversitaire?->libelle }}</td>
                            <td class="py-2">{{ $regle->programme?->code ?? '— (globale)' }}</td>
                            <td class="py-2">{{ $regle->seuil_validation }} / {{ $regle->seuil_rattrapage }}</td>
                            <td class="py-2">{{ $regle->compensation_semestre ? 'Oui' : 'Non' }}</td>
                            <td class="py-2">v{{ $regle->version }}</td>
                            <td class="py-2">{{ $regle->actif ? 'Oui' : 'Non' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <form method="post" action="{{ route('admin.evaluations.regles.store') }}" class="mt-4 ds-card space-y-4 p-5">
            @csrf
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="annee">Année universitaire</label>
                    <select id="annee" name="annee_universitaire_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" required>
                        @foreach($annees as $annee)
                            <option value="{{ $annee->id }}">{{ $annee->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="programme">Programme (vide = globale)</label>
                    <select id="programme" name="programme_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2">
                        <option value="">— Globale —</option>
                        @foreach($programmes as $programme)
                            <option value="{{ $programme->id }}">{{ $programme->code }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="seuil">Seuil validation</label>
                    <input id="seuil" name="seuil_validation" type="number" step="0.01" min="0" max="20" value="10" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" />
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="rattrapage">Seuil rattrapage</label>
                    <input id="rattrapage" name="seuil_rattrapage" type="number" step="0.01" min="0" max="20" value="8" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" />
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="arrondi">Arrondi</label>
                    <select id="arrondi" name="arrondi" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" required>
                        <option value="2dec">2 décimales</option>
                        <option value="1dec">1 décimale</option>
                        <option value="entier">Entier</option>
                    </select>
                </div>
            </div>
            @if($errors->any())
                <x-alert type="error">{{ $errors->first() }}</x-alert>
            @endif
            <x-btn type="submit">Créer la version</x-btn>
        </form>
    </div>
@endsection

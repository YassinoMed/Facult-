@extends('layouts.app')

@section('title', 'Inscriptions bloquées')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-page-header titre="Inscriptions bloquées" description="File de traitement : derniers runs non conformes, fiche par blocage, correction justifiée puis re-vérification. Jamais de forçage." />

    @if(session('status'))
        <div class="mt-4"><x-alert type="info">{{ session('status') }}</x-alert></div>
    @endif
    @if($errors->any())
        <div class="mt-4"><x-alert type="error" titre="Correction refusée">{{ $errors->first() }}</x-alert></div>
    @endif

    <div class="mt-4 grid grid-cols-3 gap-3" role="status" aria-label="Compteurs de vérification">
        <x-stat-card libelle="Bloquants" :valeur="$compteurs['bloquants']" couleur="rose" />
        <x-stat-card libelle="À vérifier" :valeur="$compteurs['a_verifier']" couleur="amber" />
        <x-stat-card libelle="Conformes" :valeur="$compteurs['conformes']" couleur="emerald" />
    </div>

    <div class="mt-4"><x-card titre="File de traitement">
        <form method="get" action="{{ route('admin.verification.index') }}" class="mb-4 flex items-end gap-3">
            <div>
                <label for="filtre-verdict" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Verdict</label>
                <select name="verdict" id="filtre-verdict" class="rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <option value="">Bloquants + à vérifier</option>
                    @foreach(['BLOQUANT', 'A_VERIFIER', 'CONFORME'] as $v)
                        <option value="{{ $v }}" @selected($verdict === $v)>{{ $v }}</option>
                    @endforeach
                </select>
            </div>
            <x-btn type="submit" variant="secondary">Filtrer</x-btn>
        </form>

        @if($etudiants->isEmpty())
            <x-empty-state message="Aucune inscription bloquée : tous les derniers runs sont conformes." />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
                            <th class="px-3 py-2">Étudiant</th>
                            <th class="px-3 py-2">Matricule</th>
                            <th class="px-3 py-2">Faculté</th>
                            <th class="px-3 py-2">Dernière inscription</th>
                            <th class="px-3 py-2">Fiche</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($etudiants as $etudiant)
                            <tr class="border-t border-slate-100">
                                <td class="px-3 py-2 font-semibold">{{ $etudiant->nom }} {{ $etudiant->prenom }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $etudiant->num_inscription }}</td>
                                <td class="px-3 py-2 text-xs">{{ $etudiant->faculte?->nom }}</td>
                                <td class="px-3 py-2 text-xs">{{ $etudiant->inscriptionsAcademiques->first()?->niveau ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <a href="{{ route('admin.verification.show', $etudiant) }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Examiner</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $etudiants->links() }}
        @endif
    </x-card></div>
</div>
@endsection

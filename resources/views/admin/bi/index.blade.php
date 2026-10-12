@extends('layouts.app')

@section('title', 'BI — Analyse académique')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="BI — Analyse académique" description="Descriptif uniquement : aucune décision automatique."
            :fil="['BI' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.bi.export', request()->query()) }}">Exporter CSV</x-btn>
            </x-slot:actions>
        </x-page-header>

        <form method="get" action="{{ route('admin.bi.index') }}" class="mt-4 flex flex-wrap gap-2">
            <select name="annee_universitaire_id" class="rounded-xl border border-slate-200 px-3 py-2 text-sm" aria-label="Année universitaire">
                @foreach($annees as $annee)
                    <option value="{{ $annee->id }}" @selected((int) $filtres['annee_universitaire_id'] === $annee->id)>{{ $annee->libelle }}</option>
                @endforeach
            </select>
            @if($facultes->isNotEmpty())
                <select name="faculte_id" class="rounded-xl border border-slate-200 px-3 py-2 text-sm" aria-label="Faculté">
                    <option value="">Toutes facultés</option>
                    @foreach($facultes as $faculte)
                        <option value="{{ $faculte->id }}" @selected((int) $filtres['faculte_id'] === $faculte->id)>{{ $faculte->nom }}</option>
                    @endforeach
                </select>
            @endif
            <x-btn size="sm" type="submit">Filtrer</x-btn>
        </form>

        <form method="get" action="{{ route('admin.bi.index') }}" class="mt-2 flex flex-wrap gap-2">
            <input type="hidden" name="annee_universitaire_id" value="{{ $filtres['annee_universitaire_id'] }}">
            <input type="hidden" name="faculte_id" value="{{ $filtres['faculte_id'] }}">
            <select name="programme_id" class="rounded-xl border border-slate-200 px-3 py-2 text-sm" aria-label="Programme">
                <option value="">Tous programmes</option>
                @foreach($programmes as $programme)
                    <option value="{{ $programme->id }}" @selected((int) ($filtres['programme_id'] ?? 0) === $programme->id)>{{ $programme->code }} — {{ $programme->nom }}</option>
                @endforeach
            </select>
            <x-btn size="sm" type="submit">Filtrer programme</x-btn>
        </form>

        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach($indicateurs as $nom => $indicateur)
                <div class="ds-card p-4">
                    <p class="text-xl font-black tabular-nums text-slate-900">{{ $indicateur['valeur'] ?? '—' }}</p>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ str_replace('_', ' ', $nom) }}</p>
                    <p class="mt-1 text-[11px] text-slate-400">{{ $indicateur['definition'] }} Source : {{ $indicateur['source'] }}.</p>
                </div>
            @endforeach
        </div>

        <section class="mt-4 ds-card p-5">
            <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Cohorte : année du bac × niveau actuel (descriptif)</h2>
            <table class="mt-2 w-full text-sm">
                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                    <tr><th class="px-4 py-2">Année bac</th><th class="px-4 py-2">Niveau</th><th class="px-4 py-2 text-right">Effectif</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($cohorte as $ligne)
                        <tr>
                            <td class="px-4 py-2 tabular-nums">{{ $ligne['annee_bac'] }}</td>
                            <td class="px-4 py-2">{{ $ligne['niveau'] }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $ligne['total'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <p class="mt-2 text-[11px] text-slate-400">Drill-down : <a href="{{ route('admin.etudiants.index') }}" class="underline">registre étudiants</a> · <a href="{{ route('admin.qualite.index') }}" class="underline">anomalies qualité</a> · <a href="{{ route('admin.cockpit.index') }}" class="underline">cockpit</a>.</p>
    </div>
@endsection

@extends('layouts.app')

@section('title', 'Règles Sentinel')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Règles de surveillance" description="Activation par contrôle. Les seuils vivent en paramètres supervision/sentinel."
            :fil="['Sentinelle' => route('admin.sentinel.index'), 'Règles' => '#']" />

        <div class="mt-4 ds-card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                    <tr><th class="px-4 py-2">Code</th><th class="px-4 py-2">Catégorie</th><th class="px-4 py-2">Gravité</th><th class="px-4 py-2">État</th><th class="px-4 py-2"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($controles as $controle)
                        @php($surcharge = $surcharges[$controle->code()] ?? null)
                        <tr>
                            <td class="px-4 py-2 font-mono font-bold">{{ $controle->code() }}</td>
                            <td class="px-4 py-2">{{ $controle->categorie() }}{{ $controle->module() ? ' · ' . $controle->module() : '' }}</td>
                            <td class="px-4 py-2"><x-status-badge :statut="$surcharge?->gravite ?? $controle->gravite()" /></td>
                            <td class="px-4 py-2">{{ ($surcharge === null || $surcharge->actif) ? 'actif' : 'désactivé' }}</td>
                            <td class="px-4 py-2 text-right">
                                @can('sentinel.manage')
                                    <form method="POST" action="{{ route('admin.sentinel.regles.basculer', $controle->code()) }}" class="inline">
                                        @csrf
                                        <x-btn size="sm" variant="secondary" type="submit">{{ ($surcharge === null || $surcharge->actif) ? 'Désactiver' : 'Activer' }}</x-btn>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection

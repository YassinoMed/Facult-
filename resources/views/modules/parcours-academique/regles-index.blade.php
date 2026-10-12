@extends('layouts.app')

@section('title', 'Règles — Parcours')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <x-page-header titre="Règles académiques versionnées" description="Chaque calcul identifie code@version. Anciennes versions conservées." :fil="['Parcours' => route('admin.parcours.index'), 'Règles' => route('admin.parcours.regles.index')]" />
        <x-card titre="Nouvelle version" class="mt-6">
            <form method="POST" action="{{ route('admin.parcours.regles.store') }}" class="grid gap-2 sm:grid-cols-2">
                @csrf
                <x-input id="code" name="code" label="Code (ex. PASSAGE_L1_L2)" required value="{{ old('code', $clone?->code) }}" />
                <x-input id="libelle" name="libelle" label="Libellé" required value="{{ old('libelle', $clone?->libelle) }}" />
                <div class="sm:col-span-2">
                    <label for="criteres_json" class="mb-1 block text-xs font-bold text-slate-700 uppercase tracking-wider">Critères (JSON)</label>
                    <textarea id="criteres_json" name="criteres_json" rows="3" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm" placeholder='{"credits_requis": 60}'>{{ old('criteres_json', $clone !== null ? json_encode($clone->criteres, JSON_UNESCAPED_UNICODE) : '') }}</textarea>
                </div>
                <div class="sm:col-span-2"><x-btn type="submit">Créer la version</x-btn></div>
            </form>
        </x-card>
        <div class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[640px] text-left text-xs sm:text-sm">
                <thead><tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                    <th class="px-5 py-3 font-bold">Code</th><th class="px-5 py-3 font-bold">Version</th><th class="px-5 py-3 font-bold">Libellé</th><th class="px-5 py-3 font-bold">Actif</th><th class="px-5 py-3 font-bold"><span class="sr-only">Cloner</span></th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($regles as $regle)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-5 py-3 font-mono">{{ $regle->code }}</td>
                            <td class="px-5 py-3">v{{ $regle->version }}</td>
                            <td class="px-5 py-3">{{ $regle->libelle }}</td>
                            <td class="px-5 py-3">{{ $regle->actif ? 'oui' : 'non' }}</td>
                            <td class="px-5 py-3"><a href="{{ route('admin.parcours.regles.index', ['code' => $regle->code]) }}#code" class="text-primary-700 hover:underline">Nouvelle version</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $regles->links() }}</div>
    </div>
@endsection

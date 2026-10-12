@extends('layouts.app')

@section('title', 'Historique Import / Export — Scolarité')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Historique des opérations" description="Imports et exports : module, format, volume, statut, durée."
            :fil="['Données' => route('admin.donnees.hub'), 'Historique' => '#']" />

        <section aria-label="Imports" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <h2 class="px-5 py-3 border-b border-slate-100 text-sm font-black text-slate-900">Imports</h2>
            @if($imports->isNotEmpty())
                <table class="w-full min-w-[680px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Date</th>
                            <th scope="col" class="px-5 py-3 font-bold">Module</th>
                            <th scope="col" class="px-5 py-3 font-bold">Format</th>
                            <th scope="col" class="px-5 py-3 font-bold">Volume</th>
                            <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-5 py-3 font-bold">Durée</th>
                            <th scope="col" class="px-5 py-3 font-bold text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($imports as $import)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 tabular-nums text-slate-600">{{ $import->created_at->format('d/m/Y H:i') }}</td>
                                <td class="px-5 py-3 font-mono text-[11px] text-slate-700">{{ $import->type }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $import->format }}</td>
                                <td class="px-5 py-3 tabular-nums text-slate-700">{{ $import->valid_rows }}/{{ $import->total_rows }}</td>
                                <td class="px-5 py-3 text-xs font-bold {{ $import->status === 'completed' ? 'text-emerald-700' : 'text-rose-700' }}">{{ $import->status }}</td>
                                <td class="px-5 py-3 tabular-nums text-slate-500">{{ $import->duration_ms }} ms</td>
                                <td class="px-5 py-3 text-center">
                                    @php
                                        $typeEntite = str_replace('data.', '', (string) $import->type);
                                        $estConnue = in_array($typeEntite, \App\Services\DataExchange\DefinitionRegistry::exportables(), true);
                                    @endphp
                                    <div class="inline-flex items-center gap-1.5">
                                        @if($estConnue)
                                            <a href="{{ route('admin.donnees.voir', $typeEntite) }}" 
                                               class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-bold text-sky-700 bg-sky-50 hover:bg-sky-100 rounded-lg border border-sky-200 transition" 
                                               title="Vérifier les données en base">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                </svg>
                                                <span>Vérifier</span>
                                            </a>
                                        @endif
                                        @if($import->error_rows > 0)
                                            <a href="{{ route('admin.donnees.rapport.erreurs', [$import, 'csv']) }}" 
                                               class="inline-flex items-center px-2 py-1 text-[11px] font-bold text-rose-700 bg-rose-50 hover:bg-rose-100 rounded-lg border border-rose-200 transition" 
                                               title="Télécharger les erreurs CSV">
                                                Erreurs
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $imports->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucun import enregistré." /></div>
            @endif
        </section>

        <section aria-label="Opérations" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <h2 class="px-5 py-3 border-b border-slate-100 text-sm font-black text-slate-900">Opérations (traçabilité)</h2>
            @if($operations->isNotEmpty())
                <table class="w-full min-w-[680px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Date</th>
                            <th scope="col" class="px-5 py-3 font-bold">Opération</th>
                            <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-5 py-3 font-bold">Durée</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($operations as $operation)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 tabular-nums text-slate-600">{{ $operation->created_at->format('d/m/Y H:i') }}</td>
                                <td class="px-5 py-3 font-mono text-[11px] text-slate-700">{{ $operation->operation }}</td>
                                <td class="px-5 py-3 text-xs font-bold {{ $operation->status === 'completed' ? 'text-emerald-700' : 'text-rose-700' }}">{{ $operation->status }}</td>
                                <td class="px-5 py-3 tabular-nums text-slate-500">{{ $operation->duration_ms }} ms</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $operations->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucune opération tracée." /></div>
            @endif
        </section>
    </div>
@endsection

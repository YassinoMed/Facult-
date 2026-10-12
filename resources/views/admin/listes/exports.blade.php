@extends('layouts.app')

@section('title', 'Mes exports listes')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Mes exports" description="Exports différés volumineux : statut, fichier (conservation limitée), téléchargement."
            :fil="['Listes' => route('admin.listes.index'), 'Exports' => '#']" />

        <section aria-label="Historique des exports" class="mt-4 overflow-x-auto admin-table-scroll rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($rapports->isNotEmpty())
                <table class="w-full min-w-[760px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Référence</th>
                            <th scope="col" class="px-5 py-3 font-bold">Format</th>
                            <th scope="col" class="px-5 py-3 font-bold">Lignes</th>
                            <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-5 py-3 font-bold">Demandé le</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($rapports as $rapport)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 font-mono font-bold">{{ $rapport->external_reference }}</td>
                                <td class="px-5 py-3 uppercase">{{ $rapport->format }}</td>
                                <td class="px-5 py-3 tabular-nums">{{ $rapport->total_rows ?? '—' }}</td>
                                <td class="px-5 py-3"><x-status-badge :statut="$rapport->status" /> {{ $rapport->statut_libelle }}</td>
                                <td class="px-5 py-3 text-slate-500">{{ $rapport->created_at?->format('d/m/Y H:i') }}</td>
                                <td class="px-5 py-3 text-right whitespace-nowrap">
                                    @if($rapport->status === 'completed' && $rapport->file_path)
                                        <a href="{{ route('admin.listes.exports.download', $rapport) }}" class="font-bold text-primary-800 underline underline-offset-2">Télécharger</a>
                                    @else
                                        <span class="text-slate-400">{{ $rapport->status === 'failed' ? 'Échoué — réduisez les filtres' : 'En cours…' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $rapports->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucun export différé. Les exports de plus de 5 000 lignes apparaîtront ici." /></div>
            @endif
        </section>
        <p class="mt-2 text-[11px] text-slate-400">Conservation : {{ config('integrations.export_retention_hours', 168) }} h — les fichiers expirent puis sont purgés (données personnelles).</p>
    </div>
@endsection

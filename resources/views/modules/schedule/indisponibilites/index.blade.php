@extends('layouts.app')

@section('title', 'Indisponibilités & préférences — Scolarité')

@section('content')
    @php($joursFr = \App\Modules\Schedule\Models\ScheduleAvailability::JOURS_FR)
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <x-page-header titre="Indisponibilités & préférences" description="Plages déclarées par ressource — une indisponibilité bloque toute planification en conflit."
            :fil="['Emplois du temps' => route('admin.emplois-du-temps.index'), 'Indisponibilités' => '#']">
            <x-slot:actions>
                @can('schedules.manage')
                    <x-btn href="{{ route('admin.emplois-du-temps.indisponibilites.create') }}">Nouvelle déclaration</x-btn>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <form method="GET" action="{{ route('admin.emplois-du-temps.indisponibilites.index') }}" class="mt-6 flex flex-col sm:flex-row gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <select name="type" aria-label="Filtrer par type" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Tous les types</option>
                @foreach($types as $type)
                    <option value="{{ $type }}" @selected(request('type') === $type)>{{ $type === 'indisponible' ? 'Indisponible (bloquant)' : 'Préférence' }}</option>
                @endforeach
            </select>
            <select name="jour" aria-label="Filtrer par jour" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Tous les jours</option>
                @foreach($jours as $jour)
                    <option value="{{ $jour }}" @selected(request('jour') === $jour)>{{ $joursFr[$jour] }}</option>
                @endforeach
            </select>
            <x-btn type="submit" variant="secondary">Filtrer</x-btn>
        </form>

        <section aria-label="Registre des indisponibilités" class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($plages->isNotEmpty())
                <table class="w-full min-w-[760px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Ressource</th>
                            <th scope="col" class="px-5 py-3 font-bold">Type</th>
                            <th scope="col" class="px-5 py-3 font-bold">Plage</th>
                            <th scope="col" class="px-5 py-3 font-bold">Période effective</th>
                            <th scope="col" class="px-5 py-3 font-bold">Année</th>
                            <th scope="col" class="px-5 py-3 font-bold">Motif</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($plages as $plage)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 font-bold text-slate-900">{{ $plage->libelleRessource() }}</td>
                                <td class="px-5 py-3">
                                    @if($plage->type === 'indisponible')
                                        <span class="inline-flex items-center rounded-full bg-rose-50 px-2.5 py-1 text-[11px] font-bold text-rose-700 ring-1 ring-inset ring-rose-200">Indisponible</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-600 ring-1 ring-inset ring-slate-200">Préférence</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 tabular-nums text-slate-700">{{ $joursFr[$plage->day_of_week] }} {{ substr((string) $plage->start_time, 0, 5) }}–{{ substr((string) $plage->end_time, 0, 5) }}</td>
                                <td class="px-5 py-3 text-slate-600">
                                    @if($plage->effective_from || $plage->effective_to)
                                        {{ $plage->effective_from?->format('d/m/Y') ?? '…' }} → {{ $plage->effective_to?->format('d/m/Y') ?? '…' }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-slate-600">{{ $plage->anneeUniversitaire?->libelle ?? 'Toutes' }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $plage->reason ?? '—' }}</td>
                                <td class="px-5 py-3">
                                    @can('schedules.manage')
                                        <div class="flex items-center justify-end gap-3">
                                            <a href="{{ route('admin.emplois-du-temps.indisponibilites.edit', $plage) }}" class="font-bold text-primary-800 underline underline-offset-2">Modifier</a>
                                            <form method="POST" action="{{ route('admin.emplois-du-temps.indisponibilites.destroy', $plage) }}" onsubmit="return confirm('Supprimer cette déclaration ?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="font-bold text-rose-700 underline underline-offset-2">Supprimer</button>
                                            </form>
                                        </div>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $plages->links() }}</div>
            @else
                <div class="p-5">
                    <x-empty-state message="Aucune plage déclarée dans ce périmètre." />
                </div>
            @endif
        </section>
    </div>
@endsection

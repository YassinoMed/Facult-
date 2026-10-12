@extends('layouts.app')

@section('title', $def['labels'] . ' — Référentiel LMD')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header :titre="$def['labels']" :description="'Référentiel ' . mb_strtolower($def['labels']) . ' — recherche, filtres et pagination.'"
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), $def['labels'] => '#']">
            <x-slot:actions>
                @can('referentiel.manage')
                    <x-btn href="{{ route('admin.referentiel.entites.create', $entite) }}">Nouveau / Nouvelle</x-btn>
                @endcan
                @if(in_array($entite, ['departements', 'ues'], true))
                    @can('referentiel.export')
                        <x-btn variant="ghost" href="{{ route('admin.donnees.export', [$entite, 'csv']) }}">Exporter CSV</x-btn>
                    @endcan
                @endif
            </x-slot:actions>
        </x-page-header>

        <form method="GET" action="{{ route('admin.referentiel.entites.index', $entite) }}" role="search" class="mt-4 flex flex-col sm:flex-row gap-2">
            <input type="search" name="search" value="{{ request('search') }}" placeholder="Code, nom…"
                   aria-label="Rechercher" class="flex-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm min-h-[44px]">
            <select name="actif" aria-label="Filtrer par statut" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Tous statuts</option>
                <option value="1" @selected(request('actif') === '1')>Actifs</option>
                <option value="0" @selected(request('actif') === '0')>Inactifs</option>
            </select>
            <x-btn type="submit" variant="secondary">Chercher</x-btn>
            @if(request()->hasAny(['search', 'actif']))
                <a href="{{ route('admin.referentiel.entites.index', $entite) }}" class="inline-flex items-center text-xs font-bold text-slate-500 underline underline-offset-2 min-h-[44px]">Réinitialiser</a>
            @endif
        </form>

        <section aria-label="Liste" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($items->isNotEmpty())
                <table class="w-full min-w-[640px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Code</th>
                            <th scope="col" class="px-5 py-3 font-bold">Nom</th>
                            <th scope="col" class="px-5 py-3 font-bold">Rattachement</th>
                            <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($items as $item)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 font-mono font-bold text-slate-800">{{ $item->code }}</td>
                                <td class="px-5 py-3 font-bold text-slate-900">{{ $item->nom }}</td>
                                @php($relParent = explode('.', $def['avec'][0] ?? '')[0])
                                <td class="px-5 py-3 text-slate-600">{{ ($relParent !== '' && $item->$relParent) ? ($item->$relParent->nom ?? $item->$relParent->code ?? '—') : '—' }}</td>
                                <td class="px-5 py-3 text-xs font-bold {{ $item->actif ?? true ? 'text-emerald-700' : 'text-slate-400' }}">{{ ($item->actif ?? true) ? '● Actif' : '○ Inactif' }}</td>
                                <td class="px-5 py-3 text-right">
                                    <x-dropdown :etiquette="'Actions pour ' . $item->code">
                                        <x-slot:liens>
                                            <a href="{{ route('admin.referentiel.entites.show', [$entite, $item]) }}" role="menuitem" class="block px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 min-h-[44px]">Voir</a>
                                            @can('referentiel.manage')
                                                <a href="{{ route('admin.referentiel.entites.edit', [$entite, $item]) }}" role="menuitem" class="block px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 min-h-[44px]">Modifier</a>
                                            @endcan
                                        </x-slot:liens>
                                    </x-dropdown>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $items->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucun élément." /></div>
            @endif
        </section>
    </div>
@endsection

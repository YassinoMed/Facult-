@extends('layouts.app')

@section('title', 'Salles — Scolarité')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Salles" description="Capacité suivie pour la détection de conflits."
            :fil="['Tableau de bord' => route('admin.dashboard'), 'Salles' => '#']">
            <x-slot:actions>
                @can('salles.manage')
                    <x-btn href="{{ route('admin.salles.create') }}" data-raccourci="nouveau">Nouvelle salle</x-btn>
                @endcan
                @can('data.import')
                    <x-btn href="{{ route('admin.donnees.import.form', 'salles') }}" variant="secondary" data-raccourci="importer">Importer</x-btn>
                @endcan
                @can('data.export')
                    <x-btn href="{{ route('admin.donnees.export', ['salles', 'csv']) }}" variant="ghost" data-raccourci="exporter">Exporter CSV</x-btn>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <form method="GET" action="{{ route('admin.salles.index') }}" role="search" class="mt-4 flex flex-col sm:flex-row gap-2">
            <input type="search" name="search" value="{{ request('search') }}" placeholder="Code, nom…"
                   aria-label="Rechercher une salle"
                   class="flex-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-primary-700 focus:outline-none min-h-[44px]">
            <x-btn type="submit" variant="secondary">Chercher</x-btn>
        </form>

        <section aria-label="Liste des salles" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($salles->isNotEmpty())
                <table class="w-full min-w-[560px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Code</th>
                            <th scope="col" class="px-5 py-3 font-bold">Nom</th>
                            <th scope="col" class="px-5 py-3 font-bold">Capacité</th>
                            <th scope="col" class="px-5 py-3 font-bold">Créneaux</th>
                            <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($salles as $salle)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 font-mono font-bold text-slate-800">{{ $salle->code }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $salle->nom ?? '—' }}</td>
                                <td class="px-5 py-3 tabular-nums text-slate-700">
                                    @can('salles.manage')
                                        <span x-data="editionInline(@js(route('admin.salles.update', $salle)), 'capacite', @js($salle->capacite))">
                                            <span x-show="!edition" class="inline-flex items-center gap-1.5">
                                                <span x-text="affichage()"></span>
                                                <button type="button" @click="edition = true; $nextTick(() => $refs.champ.focus())"
                                                        :aria-label="'Modifier la capacité de la salle {{ $salle->code }}'"
                                                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-primary-700 min-h-[32px] min-w-[32px]">✎</button>
                                            </span>
                                            <span x-show="edition" class="inline-flex items-center gap-1">
                                                <label :for="'cap-' + {{ $salle->id }}" class="sr-only">Capacité de la salle {{ $salle->code }}</label>
                                                <input :id="'cap-' + {{ $salle->id }}" x-ref="champ" x-model="valeur" type="number" min="1" max="10000"
                                                       @keydown.enter.prevent="enregistrer()" @keydown.escape="annuler()"
                                                       class="w-20 rounded-lg border border-slate-300 px-2 py-1.5 text-xs min-h-[36px]">
                                                <button type="button" @click="enregistrer()" aria-label="Enregistrer"
                                                        class="rounded-lg p-1.5 text-emerald-700 hover:bg-emerald-50 min-h-[36px] min-w-[36px]">✓</button>
                                                <button type="button" @click="annuler()" aria-label="Annuler"
                                                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 min-h-[36px] min-w-[36px]">✕</button>
                                            </span>
                                            <span x-show="etat === 'ok'" class="text-[11px] font-bold text-emerald-700" role="status">✓ Enregistré</span>
                                            <span x-show="etat === 'ko'" class="block text-[11px] font-bold text-rose-700" role="alert">⚠ <span x-text="erreur"></span> — valeur restaurée.</span>
                                        </span>
                                    @else
                                        {{ $salle->capacite ?? '—' }}
                                    @endcan
                                </td>
                                <td class="px-5 py-3 tabular-nums text-slate-700">{{ $salle->slots_count }}</td>
                                <td class="px-5 py-3 text-xs font-bold {{ $salle->actif ? 'text-emerald-700' : 'text-slate-400' }}">{{ $salle->actif ? '● Active' : '○ Inactive' }}</td>
                                <td class="px-5 py-3 text-right">
                                    @can('salles.manage')
                                        <a href="{{ route('admin.salles.edit', $salle) }}" class="font-bold text-primary-800 underline underline-offset-2">Modifier</a>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $salles->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucune salle enregistrée." /></div>
            @endif
        </section>
    </div>
@endsection

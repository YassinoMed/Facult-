@extends('layouts.app')

@section('title', 'Sous-groupes — ' . $groupe->code)

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Sous-groupes de ' . $groupe->code" :description="'TD/TP du groupe principal ' . $groupe->code"
            :fil="['Groupes' => route('admin.groupes.index'), $groupe->code => route('admin.groupes.show', $groupe), 'Sous-groupes' => '#']" />

        <section aria-label="Sous-groupes" class="mt-4 ds-card overflow-hidden">
            @if($sousGroupes->isNotEmpty())
                <table class="w-full text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Code</th>
                            <th scope="col" class="px-5 py-3 font-bold">Nom</th>
                            <th scope="col" class="px-5 py-3 font-bold">Type</th>
                            <th scope="col" class="px-5 py-3 font-bold">Capacité</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($sousGroupes as $sg)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 font-mono font-bold">{{ $sg->code }}</td>
                                <td class="px-5 py-3 font-bold">{{ $sg->nom }}</td>
                                <td class="px-5 py-3">{{ $sg->type }}</td>
                                <td class="px-5 py-3 tabular-nums">{{ $sg->capacite ?? '—' }}</td>
                                <td class="px-5 py-3 text-right">
                                    @can('groupes.manage')
                                        <form method="POST" action="{{ route('admin.sous-groupes.destroy', $sg) }}" class="inline">
                                            @csrf @method('DELETE')
                                            <button type="submit" aria-label="Supprimer {{ $sg->code }}" class="text-rose-700 font-bold min-h-[32px] min-w-[32px]">✕</button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="p-5"><x-empty-state message="Aucun sous-groupe." /></div>
            @endif
        </section>

        @can('groupes.manage')
            <form method="POST" action="{{ route('admin.sous-groupes.store', $groupe) }}" class="mt-4 ds-card p-4 grid grid-cols-1 sm:grid-cols-5 gap-2 items-end">
                @csrf
                <x-input id="code" name="code" label="Code" required maxlength="30" />
                <x-input id="nom" name="nom" label="Nom" required maxlength="100" />
                <div>
                    <label for="type" class="mb-1 block text-xs font-bold text-slate-700 uppercase tracking-wider">Type *</label>
                    <select id="type" name="type" required class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                        <option value="td">TD</option>
                        <option value="tp">TP</option>
                        <option value="autre">Autre</option>
                    </select>
                </div>
                <x-input id="capacite" name="capacite" type="number" label="Capacité" min="1" max="10000" />
                <x-btn type="submit" variant="secondary">Créer</x-btn>
            </form>
        @endcan
    </div>
@endsection

@extends('layouts.app')

@section('title', 'Types d’enseignement')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header titre="Types d’enseignement" description="CM, TD, TP, Projet, Stage… — configurable, jamais codé en dur."
            :fil="['Enseignements' => route('admin.enseignements.tableau-bord'), 'Types' => '#']" />

        <section aria-label="Liste des types" class="mt-4 ds-card overflow-hidden">
            @if($types->isNotEmpty())
                <table class="w-full text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Code</th>
                            <th scope="col" class="px-5 py-3 font-bold">Nom</th>
                            <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($types as $type)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 font-mono font-bold">{{ $type->code }}</td>
                                <td class="px-5 py-3 font-bold">{{ $type->nom }}</td>
                                <td class="px-5 py-3 text-xs font-bold">{{ $type->actif ? '● Actif' : '○ Inactif' }}</td>
                                <td class="px-5 py-3 text-right">
                                    @can('enseignements.manage')
                                        <form method="POST" action="{{ route('admin.enseignements.types.destroy', $type) }}" class="inline">
                                            @csrf @method('DELETE')
                                            <button type="submit" aria-label="Supprimer {{ $type->code }}" class="text-rose-700 font-bold min-h-[32px] min-w-[32px]">✕</button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="p-5"><x-empty-state message="Aucun type." /></div>
            @endif
        </section>

        @can('enseignements.manage')
            <form method="POST" action="{{ route('admin.enseignements.types.store') }}" class="mt-4 ds-card p-4 grid grid-cols-1 sm:grid-cols-4 gap-2 items-end">
                @csrf
                <x-input id="code" name="code" label="Code" required maxlength="20" />
                <x-input id="nom" name="nom" label="Nom" required maxlength="100" />
                <x-input id="description" name="description" label="Description" maxlength="1000" />
                <x-btn type="submit" variant="secondary">Créer</x-btn>
            </form>
        @endcan
    </div>
@endsection

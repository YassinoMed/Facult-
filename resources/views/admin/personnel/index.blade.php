@extends('layouts.app')

@section('title', 'Personnel administratif')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Personnel administratif" description="Profils des comptes back-office — super_admin uniquement."
            :fil="['Personnel' => '#']">
            <x-slot:actions>
                <x-btn href="{{ route('admin.personnel.create') }}">Nouveau profil</x-btn>
            </x-slot:actions>
        </x-page-header>

        <form method="GET" action="{{ route('admin.personnel.index') }}" role="search" class="mt-4 flex flex-col sm:flex-row gap-2">
            <input type="search" name="search" value="{{ request('search') }}" placeholder="Matricule, nom, email…"
                   aria-label="Rechercher" class="flex-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm min-h-[44px]">
            <select name="fonction_id" aria-label="Filtrer par fonction" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Toutes fonctions</option>
                @foreach($fonctions as $fonction)
                    <option value="{{ $fonction->id }}" @selected(request('fonction_id') == $fonction->id)>{{ $fonction->nom }}</option>
                @endforeach
            </select>
            <x-btn type="submit" variant="secondary">Chercher</x-btn>
        </form>

        <section aria-label="Liste du personnel" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($personnels->isNotEmpty())
                <table class="w-full min-w-[640px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Matricule</th>
                            <th scope="col" class="px-5 py-3 font-bold">Agent</th>
                            <th scope="col" class="px-5 py-3 font-bold">Fonction</th>
                            <th scope="col" class="px-5 py-3 font-bold">Département</th>
                            <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Ouvrir</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($personnels as $personnel)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 font-mono font-bold">{{ $personnel->matricule }}</td>
                                <td class="px-5 py-3 font-bold text-slate-900">{{ $personnel->user->name }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $personnel->fonction?->nom ?? '—' }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $personnel->departement?->nom ?? '—' }}</td>
                                <td class="px-5 py-3 text-xs font-bold">{{ $personnel->statut }}</td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('admin.personnel.show', $personnel) }}" class="font-bold text-primary-800 underline underline-offset-2">Ouvrir</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $personnels->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucun profil." /></div>
            @endif
        </section>
    </div>
@endsection

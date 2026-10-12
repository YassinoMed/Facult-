@extends('layouts.app')

@section('title', 'Annonces')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Annonces" description="Publication ciblée, planifiée, avec accusés de lecture."
            :fil="['Annonces' => '#']">
            <x-slot:actions>
                @can('annonces.create')
                    <x-btn href="{{ route('admin.annonces.create') }}">Nouvelle annonce</x-btn>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <div class="mt-4 ds-card overflow-hidden">
            @if($annonces->isEmpty())
                <x-empty-state :message="'Aucune annonce — Publiez la première annonce ciblée.'" />
            @else
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                        <tr><th class="px-4 py-2">Titre</th><th class="px-4 py-2">Type</th><th class="px-4 py-2">Audience</th><th class="px-4 py-2">Statut</th><th class="px-4 py-2 text-right">Lectures</th><th class="px-4 py-2"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($annonces as $annonce)
                            <tr>
                                <td class="px-4 py-2 font-bold">{{ $annonce->titre }}</td>
                                <td class="px-4 py-2">{{ $annonce->type }}</td>
                                <td class="px-4 py-2">{{ $annonce->audience }}</td>
                                <td class="px-4 py-2"><x-status-badge :statut="$annonce->statut" /></td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $annonce->lectures_count }} lue(s)</td>
                                <td class="px-4 py-2 text-right">
                                    @can('annonces.publish')
                                        @if(in_array($annonce->statut, ['draft', 'scheduled'], true))
                                            <form method="post" action="{{ route('admin.annonces.publier', $annonce) }}" class="inline">
                                                @csrf
                                                <x-btn size="sm" type="submit">Publier</x-btn>
                                            </form>
                                        @endif
                                    @endcan
                                    @can('annonces.delete')
                                        <form method="post" action="{{ route('admin.annonces.destroy', $annonce) }}" class="inline">
                                            @csrf @method('DELETE')
                                            <x-btn size="sm" variant="secondary" type="submit">Supprimer</x-btn>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-4 py-3">{{ $annonces->links() }}</div>
            @endif
        </div>
    </div>
@endsection

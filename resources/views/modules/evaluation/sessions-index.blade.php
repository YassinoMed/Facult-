@extends('layouts.app')

@section('title', 'Sessions d’examen')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Sessions d’examen" description="Principale, rattrapage, exceptionnelle — périodes verrouillables."
            :fil="['Évaluations' => route('admin.evaluations.index'), 'Sessions' => '#']">
            <x-slot:actions>
                @can('sessions_examen.create')
                    <x-btn href="{{ route('admin.evaluations.sessions.create') }}">Nouvelle session</x-btn>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <div class="mt-4 ds-card overflow-hidden">
            @if($sessions->isEmpty())
                <x-empty-state :message="'Aucune session — Créez la session principale puis le rattrapage.'" />
            @else
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                        <tr><th class="px-4 py-2">Code</th><th class="px-4 py-2">Nom</th><th class="px-4 py-2">Type</th><th class="px-4 py-2">Période</th><th class="px-4 py-2">Statut</th><th class="px-4 py-2"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($sessions as $session)
                            <tr>
                                <td class="px-4 py-2 font-mono font-bold">{{ $session->code }}</td>
                                <td class="px-4 py-2">{{ $session->nom }}</td>
                                <td class="px-4 py-2"><x-status-badge :statut="$session->type" /></td>
                                <td class="px-4 py-2 tabular-nums">{{ $session->date_debut->format('d/m/Y') }} → {{ $session->date_fin->format('d/m/Y') }}</td>
                                <td class="px-4 py-2"><x-status-badge :statut="$session->statut" /></td>
                                <td class="px-4 py-2 text-right">
                                    @can('sessions_examen.lock')
                                        @if(!$session->estVerrouillee())
                                            <form method="post" action="{{ route('admin.evaluations.sessions.verrouiller', $session) }}" class="inline">
                                                @csrf
                                                <x-btn size="sm" variant="secondary" type="submit">Verrouiller</x-btn>
                                            </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-4 py-3">{{ $sessions->links() }}</div>
            @endif
        </div>
    </div>
@endsection

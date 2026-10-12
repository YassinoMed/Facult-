@extends('layouts.app')

@section('title', 'Justifications d’absence')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Justifications" description="Dépôts étudiants, vérification scolarité."
            :fil="['Présences' => route('admin.presences.index'), 'Justifications' => '#']" />

        <div class="mt-4 ds-card overflow-hidden">
            @if($justifications->isEmpty())
                <x-empty-state :message="'Aucune justification — Les dépôts étudiants apparaîtront ici.'" />
            @else
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                        <tr><th class="px-4 py-2">Étudiant</th><th class="px-4 py-2">Motif</th><th class="px-4 py-2">Statut</th><th class="px-4 py-2"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($justifications as $justification)
                            <tr>
                                <td class="px-4 py-2 font-bold">{{ $justification->etudiant->nom_complet }}</td>
                                <td class="px-4 py-2">@can('attendance.justify'){{ mb_substr($justification->motif, 0, 80) }}@else<span class="text-slate-400">Motif masqué (validation requise)</span>@endcan</td>
                                <td class="px-4 py-2"><x-status-badge :statut="$justification->statut" /></td>
                                <td class="px-4 py-2 text-right">
                                    @can('attendance.justify')
                                        @if($justification->statut === 'pending')
                                            <form method="post" action="{{ route('admin.justifications.valider', $justification) }}" class="inline">
                                                @csrf
                                                <input type="hidden" name="decision" value="approved" />
                                                <x-btn size="sm" type="submit">Accepter</x-btn>
                                            </form>
                                            <form method="post" action="{{ route('admin.justifications.valider', $justification) }}" class="inline">
                                                @csrf
                                                <input type="hidden" name="decision" value="rejected" />
                                                <x-btn size="sm" variant="secondary" type="submit">Refuser</x-btn>
                                            </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-4 py-3">{{ $justifications->links() }}</div>
            @endif
        </div>
    </div>
@endsection

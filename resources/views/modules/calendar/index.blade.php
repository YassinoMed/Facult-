@extends('layouts.app')

@section('title', 'Calendrier académique')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Calendrier académique" description="Événementiel transversal : cours et examens restent dans leurs modules."
            :fil="['Calendrier' => '#']">
            <x-slot:actions>
                @can('calendriers.manage')
                    <x-btn href="{{ route('admin.calendrier.index') }}#nouveau">Nouvel événement</x-btn>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <div class="mt-4 ds-card overflow-hidden">
            @if($evenements->isEmpty())
                <x-empty-state :message="'Aucun événement — Rentrée, vacances, deadlines, périodes d’examens.'" />
            @else
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                        <tr><th class="px-4 py-2">Date</th><th class="px-4 py-2">Titre</th><th class="px-4 py-2">Type</th><th class="px-4 py-2">Lieu</th><th class="px-4 py-2"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($evenements as $evenement)
                            <tr>
                                <td class="px-4 py-2 tabular-nums">{{ $evenement->date_debut->format('d/m/Y') }}</td>
                                <td class="px-4 py-2 font-bold">{{ $evenement->titre }}</td>
                                <td class="px-4 py-2">{{ $evenement->type }}</td>
                                <td class="px-4 py-2">{{ $evenement->lieu ?? '—' }}</td>
                                <td class="px-4 py-2 text-right">
                                    @can('calendriers.manage')
                                        <form method="post" action="{{ route('admin.calendrier.destroy', $evenement) }}" class="inline">
                                            @csrf @method('DELETE')
                                            <x-btn size="sm" variant="secondary" type="submit">Supprimer</x-btn>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-4 py-3">{{ $evenements->links() }}</div>
            @endif
        </div>

        @can('calendriers.manage')
            <form id="nouveau" method="post" action="{{ route('admin.calendrier.store') }}" class="mt-4 ds-card grid gap-3 p-5 sm:grid-cols-2">
                @csrf
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="titre">Titre</label>
                    <input id="titre" name="titre" maxlength="150" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" />
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="annee">Année</label>
                    <select id="annee" name="annee_universitaire_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2">
                        @foreach($annees as $annee)
                            <option value="{{ $annee->id }}">{{ $annee->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="type">Type</label>
                    <select id="type" name="type" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2">
                        @foreach(\App\Modules\Calendar\Models\EvenementCalendrier::TYPES as $type)
                            <option value="{{ $type }}">{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="date">Début</label>
                    <input id="date" type="date" name="date_debut" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" />
                </div>
                <input type="hidden" name="audience" value="tous" />
                <div class="sm:col-span-2"><x-btn type="submit">Publier l’événement</x-btn></div>
            </form>
        @endcan
    </div>
@endsection

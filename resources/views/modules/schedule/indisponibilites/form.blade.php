@extends('layouts.app')

@section('title', ($plage ? 'Modifier la déclaration' : 'Nouvelle déclaration').' — Scolarité')

@section('content')
    @php($joursFr = \App\Modules\Schedule\Models\ScheduleAvailability::JOURS_FR)
    @php($anneesOptions = $annees->mapWithKeys(fn ($a) => [$a->id => $a->libelle])->all())
    @php($enseignantsOptions = $enseignants->mapWithKeys(fn ($t) => [$t->id => $t->nomComplet()])->all())
    @php($sallesOptions = $salles->mapWithKeys(fn ($s) => [$s->id => $s->code.' ('.$s->capacite.' places)'])->all())
    @php($groupesOptions = $groupes->mapWithKeys(fn ($g) => [$g->id => $g->code])->all())
    @php($joursOptions = collect($jours)->mapWithKeys(fn ($j) => [$j => $joursFr[$j]])->all())
    @php($typesOptions = ['indisponible' => 'Indisponible — bloque toute planification', 'souhaitee' => 'Préférence — non bloquante'])

    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8 sm:py-12">
        <a href="{{ route('admin.emplois-du-temps.indisponibilites.index') }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">← Indisponibilités</a>
        <h1 class="mt-2 text-2xl font-black text-slate-900">{{ $plage ? 'Modifier la déclaration' : 'Nouvelle déclaration' }}</h1>
        <p class="mt-1 text-sm text-slate-500">Déclarez une plage récurrente pour un enseignant, une salle ou un groupe. Une indisponibilité est une contrainte dure : tout créneau en conflit est refusé.</p>

        @if($errors->any())
            <div class="mt-4 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-xs text-rose-800" role="alert">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ $plage ? route('admin.emplois-du-temps.indisponibilites.update', $plage) : route('admin.emplois-du-temps.indisponibilites.store') }}" class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm space-y-5">
            @csrf
            @if($plage)
                @method('PATCH')
            @endif

            <fieldset>
                <legend class="text-xs font-bold text-slate-700 uppercase tracking-wider">Ressource <span class="font-normal normal-case text-slate-500">— exactement une</span></legend>
                <div class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <x-select id="f-enseignant" name="teacher_id" label="Enseignant" vide="— Aucun —"
                        :options="$enseignantsOptions" :valeur="$plage?->teacher_id"
                        onchange="if (this.value) { document.getElementById('f-salle').value = ''; document.getElementById('f-groupe').value = ''; }" />
                    <x-select id="f-salle" name="room_id" label="Salle" vide="— Aucune —"
                        :options="$sallesOptions" :valeur="$plage?->room_id"
                        onchange="if (this.value) { document.getElementById('f-enseignant').value = ''; document.getElementById('f-groupe').value = ''; }" />
                    <x-select id="f-groupe" name="group_id" label="Groupe" vide="— Aucun —"
                        :options="$groupesOptions" :valeur="$plage?->group_id"
                        onchange="if (this.value) { document.getElementById('f-enseignant').value = ''; document.getElementById('f-salle').value = ''; }" />
                </div>
            </fieldset>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <x-select id="f-jour" name="day_of_week" label="Jour" required
                    :options="$joursOptions" :valeur="$plage?->day_of_week ?? 'monday'" />
                <x-select id="f-type" name="type" label="Type" required
                    :options="$typesOptions" :valeur="$plage?->type ?? 'indisponible'" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <x-input id="f-debut" name="start_time" type="time" label="Heure de début" required
                    :value="old('start_time', $plage ? substr((string) $plage->start_time, 0, 5) : '')" />
                <x-input id="f-fin" name="end_time" type="time" label="Heure de fin" required
                    :value="old('end_time', $plage ? substr((string) $plage->end_time, 0, 5) : '')" />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <x-input id="f-debut-periode" name="effective_from" type="date" label="Valable à partir du"
                    :value="old('effective_from', $plage?->effective_from?->format('Y-m-d'))" />
                <x-input id="f-fin-periode" name="effective_to" type="date" label="Valable jusqu’au"
                    :value="old('effective_to', $plage?->effective_to?->format('Y-m-d'))" />
            </div>

            <x-select id="f-annee" name="annee_universitaire_id" label="Année universitaire" vide="Toutes les années"
                :options="$anneesOptions" :valeur="$plage?->annee_universitaire_id" />

            <x-textarea id="f-motif" name="reason" label="Motif (facultatif)">{{ old('reason', $plage?->reason) }}</x-textarea>

            <div class="flex flex-wrap gap-3 pt-1">
                <x-btn type="submit">Enregistrer</x-btn>
                <x-btn variant="ghost" href="{{ route('admin.emplois-du-temps.indisponibilites.index') }}">Annuler</x-btn>
            </div>
        </form>
    </div>
@endsection

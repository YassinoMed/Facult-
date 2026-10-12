@extends('layouts.app')

@section('title', 'Modifier créneau — Scolarité')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8 sm:py-12">
        <a href="{{ route('admin.emplois-du-temps.show', $slot->schedule_id) }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">← Planning</a>
        <h1 class="mt-2 text-2xl font-black text-slate-900">Modifier le créneau</h1>
        <p class="text-sm text-slate-500">{{ $slot->matiere->nom }} — {{ substr($slot->start_time, 0, 5) }}–{{ substr($slot->end_time, 0, 5) }}</p>

        @if($errors->any())
            <div class="mt-4 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-xs text-rose-800" role="alert">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.emplois-du-temps.slots.update', $slot) }}" class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm grid grid-cols-1 sm:grid-cols-2 gap-4">
            @csrf @method('PATCH')
            <div>
                <label for="matiere" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Matière</label>
                <select id="matiere" name="matiere_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    @foreach($matieres as $matiere)
                        <option value="{{ $matiere->id }}" @selected($slot->matiere_id == $matiere->id)>{{ $matiere->code }} — {{ $matiere->nom }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="jour" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Jour</label>
                <select id="jour" name="day_of_week" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    @foreach(['monday' => 'Lundi', 'tuesday' => 'Mardi', 'wednesday' => 'Mercredi', 'thursday' => 'Jeudi', 'friday' => 'Vendredi', 'saturday' => 'Samedi', 'sunday' => 'Dimanche'] as $code => $libelle)
                        <option value="{{ $code }}" @selected($slot->day_of_week === $code)>{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="debut" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Début</label>
                <input type="time" id="debut" name="start_time" value="{{ substr($slot->start_time, 0, 5) }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label for="fin" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Fin</label>
                <input type="time" id="fin" name="end_time" value="{{ substr($slot->end_time, 0, 5) }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label for="enseignant" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Enseignant</label>
                <select id="enseignant" name="teacher_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <option value="">— Aucun —</option>
                    @foreach($enseignants as $enseignant)
                        <option value="{{ $enseignant->id }}" @selected($slot->teacher_id == $enseignant->id)>{{ $enseignant->nomComplet() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="salle" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Salle</label>
                <select id="salle" name="room_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <option value="">— Aucune —</option>
                    @foreach($salles as $salle)
                        <option value="{{ $salle->id }}" @selected($slot->room_id == $salle->id)>{{ $salle->code }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="type" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Type</label>
                <select id="type" name="type" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    @foreach(['course' => 'Cours', 'td' => 'TD', 'tp' => 'TP', 'exam' => 'Examen', 'meeting' => 'Réunion', 'workshop' => 'Atelier', 'other' => 'Autre'] as $code => $libelle)
                        <option value="{{ $code }}" @selected($slot->type === $code)>{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="motif" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Motif (journalisé)</label>
                <input type="text" id="motif" name="motif" maxlength="500" placeholder="Ex : inversion avec le TD" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
            </div>
            <fieldset class="sm:col-span-2 rounded-xl border-2 border-amber-300 bg-amber-50/60 p-4" x-data="{ portee: 'semestre' }">
                <legend class="px-2 text-xs font-black uppercase tracking-wider text-amber-900">Portée de la modification</legend>
                <div class="flex flex-col sm:flex-row gap-3">
                    <label class="flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm cursor-pointer">
                        <input type="radio" name="portee" value="semestre" x-model="portee" checked class="h-4 w-4">
                        <span><strong>Tout le semestre</strong> — le créneau récurrent est modifié.</span>
                    </label>
                    <label class="flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm cursor-pointer">
                        <input type="radio" name="portee" value="date" x-model="portee" class="h-4 w-4">
                        <span><strong>Uniquement une date</strong> — crée une exception, le récurrent reste intact.</span>
                    </label>
                </div>
                <div x-show="portee === 'date'" class="mt-3">
                    <label for="date-ponctuelle" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Date concernée *</label>
                    <input type="date" id="date-ponctuelle" name="date_ponctuelle" :required="portee === 'date'" min="{{ date('Y-m-d') }}" class="w-full sm:w-64 rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <p class="mt-1 text-[11px] text-amber-800">Seuls salle, horaire, enseignant et statut sont transposables à une date.</p>
                </div>
            </fieldset>
            <div class="sm:col-span-2">
                <button type="submit" class="rounded-xl bg-primary-800 px-6 py-3 text-sm font-bold text-white hover:bg-primary-700 min-h-[48px]">Enregistrer</button>
            </div>
        </form>
    </div>
@endsection

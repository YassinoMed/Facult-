@extends('layouts.app')

@section('title', 'Régularisation ' . $reclamation->reference . ' — Scolarité')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Demande ' . $reclamation->reference" :description="$reclamation->teacher?->nomComplet() . ' · ' . $reclamation->categorieLibelle()"
            :fil="['Régularisations' => route('admin.presences.regularisations.index'), 'Détail' => '#']" />

        @if($errors->any())
            <div class="mt-4"><x-alert type="error" :message="$errors->first()" /></div>
        @endif

        <section aria-label="Demande" class="mt-4 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <dl class="divide-y divide-slate-100 text-sm">
                <div class="px-5 py-3 flex justify-between gap-4"><dt class="text-slate-500">Objet</dt><dd class="font-semibold text-right">{{ $reclamation->objet }}</dd></div>
                <div class="px-5 py-3"><dt class="text-slate-500">Description</dt><dd class="mt-1">{{ $reclamation->description }}</dd></div>
                <div class="px-5 py-3 flex justify-between gap-4"><dt class="text-slate-500">Statut</dt><dd><x-status-badge :statut="$reclamation->statut" /></dd></div>
                @if($reclamation->presence)
                    <div class="px-5 py-3 flex justify-between gap-4"><dt class="text-slate-500">Séance contestée</dt><dd class="font-semibold text-right">{{ $reclamation->presence->date->format('d/m/Y') }} · {{ $reclamation->presence->slot?->matiere?->nom ?? '—' }} · {{ substr((string) $reclamation->presence->slot?->start_time, 0, 5) }} → {{ substr((string) $reclamation->presence->slot?->end_time, 0, 5) }}</dd></div>
                    <div class="px-5 py-3 flex justify-between gap-4"><dt class="text-slate-500">Statut actuel</dt><dd><x-status-badge :statut="$reclamation->presence->statut_effectif" /></dd></div>
                @endif
                @if($reclamation->piece_jointe_chemin)
                    <div class="px-5 py-3 flex justify-between gap-4"><dt class="text-slate-500">Pièce jointe</dt><dd class="font-mono text-xs">{{ $reclamation->piece_jointe_nom }}</dd></div>
                @endif
            </dl>
        </section>

        @if(in_array($reclamation->statut, ['ouverte', 'en_cours'], true))
            <form method="POST" action="{{ route('admin.presences.regularisations.traiter', $reclamation) }}" class="mt-4 rounded-2xl border border-slate-200 bg-white p-6 space-y-4 shadow-sm">
                @csrf
                <h3 class="text-sm font-black text-slate-900">Décision (tracée, notifiée à l'enseignant)</h3>
                <div>
                    <label for="decision" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Décision *</label>
                    <select id="decision" name="decision" required class="w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm">
                        <option value="resoudre">Résoudre (corrige la présence liée si statut choisi)</option>
                        <option value="rejeter">Rejeter</option>
                    </select>
                </div>
                @if($reclamation->presence)
                    <div>
                        <label for="statut_valide" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Statut validé (résolution avec présence liée)</label>
                        <select id="statut_valide" name="statut_valide" class="w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm">
                            <option value="">— Sans correction de présence —</option>
                            @foreach(\App\Modules\Attendance\Models\TeacherPresence::STATUTS_VALIDES as $statutValide)
                                <option value="{{ $statutValide }}">{{ $statutValide }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <x-textarea id="motif" name="motif" label="Motif de la décision (obligatoire, notifié)" :rows="3">{{ old('motif') }}</x-textarea>
                <x-btn type="submit">Enregistrer la décision</x-btn>
            </form>
        @else
            <div class="mt-4"><x-alert type="info" :message="'Dossier ' . $reclamation->statutLibelle() . (filled($reclamation->reponse) ? ' : ' . $reclamation->reponse : '.')" /></div>
        @endif
    </div>
@endsection

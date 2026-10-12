@extends('layouts.app')

@section('title', 'Prévisualisation affectation')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8">
        <x-page-header :titre="$etudiant->nom_complet . ' → ' . $groupe->code" :description="'Année ' . $annee->libelle"
            :fil="['Étudiant' => route('admin.etudiants.show', $etudiant), 'Prévisualisation' => '#']" />

        <section class="mt-4 ds-card p-5">
            <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Contraintes vérifiées côté serveur</h2>
            <ul class="mt-2 space-y-1.5 text-sm">
                @forelse($contraintes as $contrainte)
                    <li class="flex items-start gap-2">
                        <x-status-badge :statut="$contrainte['bloquant'] ? 'erreur' : ($contrainte['gravite'] === 'info' ? 'info' : 'avertissement')" />
                        <span><strong class="font-mono text-xs">[{{ $contrainte['code'] }}]</strong> {{ $contrainte['message'] }}</span>
                    </li>
                @empty
                    <li class="text-emerald-700 font-bold">✓ Aucune contrainte bloquante.</li>
                @endforelse
            </ul>
        </section>

        <section class="mt-4 ds-card p-5">
            <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Impacts du changement</h2>
            <ul class="mt-2 text-sm">
                <li>Créneaux du groupe cible : <strong class="tabular-nums">{{ $impacts['planning_creneaux'] }}</strong></li>
                <li>Épreuves à venir (candidatures conservées) : <strong class="tabular-nums">{{ $impacts['epreuves_a_venir'] }}</strong></li>
                <li>Notes conservées (liées au contexte d'origine) : <strong class="tabular-nums">{{ $impacts['notes_conservees'] }}</strong></li>
            </ul>
        </section>

        <form method="post" action="{{ route('admin.etudiants.affectations.store', $etudiant) }}" class="mt-4 ds-card space-y-3 p-5">
            @csrf
            <input type="hidden" name="groupe_id" value="{{ $groupe->id }}" />
            <input type="hidden" name="annee_universitaire_id" value="{{ $annee->id }}" />
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="motif">Motif (obligatoire)</label>
                <input id="motif" name="motif" maxlength="255" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
            </div>
            <label class="inline-flex items-center gap-1 text-sm"><input type="checkbox" name="force" value="1" /> Forcer (exception capacité/niveau, groupes.manage)</label>
            <x-btn type="submit">Confirmer l'affectation</x-btn>
        </form>
    </div>
@endsection

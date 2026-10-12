@extends('layouts.app')

@section('title', 'Planning des sauvegardes')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header titre="Planning des sauvegardes" :description="'Prochaine exécution : ' . ($prochaine?->format('d/m/Y H:i') ?? 'planification désactivée') . '.'"
            :fil="['Sauvegardes' => route('admin.system.backups.index'), 'Planning' => '#']" />

        <form method="POST" action="{{ route('admin.system.backups.planification.update') }}" class="mt-4 ds-card space-y-4 p-5">
            @csrf

            <x-select id="actif" label="Planification" name="actif" required :valeur="$plan['actif'] ? '1' : '0'" :options="['1' => 'Activée (évaluée toutes les 15 minutes)', '0' => 'Désactivée']" />

            <x-select id="frequence" label="Fréquence" name="frequence" required :valeur="$plan['frequence']" :options="['horaire' => 'Toutes les heures', 'toutes_2h' => 'Toutes les 2 heures', 'toutes_6h' => 'Toutes les 6 heures', 'toutes_12h' => 'Toutes les 12 heures', 'quotidienne' => 'Quotidienne', 'personnalise' => 'Personnalisée (jours + heure)']" />

            <fieldset>
                <legend class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-700">Jours (personnalisé)</legend>
                <div class="flex flex-wrap gap-3">
                    @foreach($jours as $jour)
                        <label class="flex items-center gap-1.5 text-sm text-slate-700">
                            <input type="checkbox" name="jours[]" value="{{ $jour }}" @checked(in_array($loop->index + 1, $plan['jours'], true)) class="h-4 w-4 rounded border-slate-300">
                            {{ ucfirst($jour) }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <x-input id="heure" label="Heure (quotidienne, personnalisé)" name="heure" type="time" required :valeur="$plan['heure']" />

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-input id="retention_jours" label="Rétention (jours)" name="retention_jours" type="number" required :valeur="$plan['retention_jours']" />
                <x-input id="conserver" label="Lots conservés (nombre)" name="conserver" type="number" required :valeur="$conserver" />
            </div>

            <x-select id="destination" label="Destination" name="destination" required :valeur="$plan['destination']" :options="$destinations" />

            <x-select id="chiffrer" label="Chiffrement planifié (SAUVEGARDE_CLE requise)" name="chiffrer" required :valeur="$plan['chiffrer'] ? '1' : '0'" :options="['0' => 'Non', '1' => 'Oui']" />

            <fieldset>
                <legend class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-700">Alertes</legend>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <x-select id="alerter_echec" label="À chaque échec" name="alerter_echec" required :valeur="$plan['alerter_echec'] ? '1' : '0'" :options="['1' => 'Oui', '0' => 'Non']" />
                    <x-select id="alerter_retard" label="Si en retard" name="alerter_retard" required :valeur="$plan['alerter_retard'] ? '1' : '0'" :options="['1' => 'Oui', '0' => 'Non']" />
                    <x-select id="alerter_succes" label="À chaque succès" name="alerter_succes" required :valeur="$plan['alerter_succes'] ? '1' : '0'" :options="['0' => 'Non', '1' => 'Oui']" />
                </div>
            </fieldset>

            <x-btn type="submit">Enregistrer le planning</x-btn>
        </form>
    </div>
@endsection

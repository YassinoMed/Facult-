@extends('layouts.app')

@section('title', 'Mes présences — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-6">
        <x-page-header titre="Mes présences & pointages" description="Consultez l'historique de vos séances, vos pointages réels et vos statuts de présence."
            :fil="['Espace Enseignant' => route('enseignant.dashboard'), 'Mes présences' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('enseignant.reclamations.create', ['categorie' => 'presence', 'objet' => 'Demande de vérification de présence']) }}">
                    Contester un pointage
                </x-btn>
            </x-slot:actions>
        </x-page-header>

        {{-- Indicateurs réels --}}
        <div class="mt-6 grid grid-cols-2 sm:grid-cols-5 gap-3">
            <x-stat-card libelle="Taux de présence" :valeur="$tauxAssiduite . ' %'" detail="Assiduité globale" couleur="{{ $tauxAssiduite >= 85 ? 'emerald' : 'amber' }}" />
            <x-stat-card libelle="Séances observées" :valeur="$totalSeances" detail="Créneaux avec suivi" couleur="slate" />
            <x-stat-card libelle="Présences confirmées" :valeur="$presencesConfirmees" detail="Ponctualité & dispenses" couleur="primary" />
            <x-stat-card libelle="À vérifier" :valeur="$enAttenteVerification" detail="Pointages manquants / retards" couleur="{{ $enAttenteVerification > 0 ? 'amber' : 'slate' }}" />
            <x-stat-card libelle="Absences validées" :valeur="$absencesValidees" detail="Décisions administratives" couleur="{{ $absencesValidees > 0 ? 'rose' : 'slate' }}" />
        </div>

        {{-- Filtres --}}
        <form method="GET" action="{{ route('enseignant.presences.index') }}" class="mt-6 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm flex flex-wrap items-center gap-4">
            <div class="flex-1 min-w-[200px]">
                <label for="statut" class="block text-xs font-bold text-slate-500 mb-1">Filtrer par statut</label>
                <select id="statut" name="statut" class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 px-3 py-2 text-xs">
                    <option value="">Tous les statuts</option>
                    <option value="presence_confirmee" {{ $filtreStatut === 'presence_confirmee' ? 'selected' : '' }}>Présence confirmée</option>
                    <option value="retard_potentiel" {{ $filtreStatut === 'retard_potentiel' ? 'selected' : '' }}>Retard potentiel</option>
                    <option value="pointage_manquant" {{ $filtreStatut === 'pointage_manquant' ? 'selected' : '' }}>Pointage manquant</option>
                    <option value="absence_a_verifier" {{ $filtreStatut === 'absence_a_verifier' ? 'selected' : '' }}>Absence à vérifier</option>
                    <option value="absence_validee" {{ $filtreStatut === 'absence_validee' ? 'selected' : '' }}>Absence validée</option>
                    <option value="dispense_mission" {{ $filtreStatut === 'dispense_mission' ? 'selected' : '' }}>Dispense / Mission</option>
                </select>
            </div>

            <div class="w-40">
                <label for="date_debut" class="block text-xs font-bold text-slate-500 mb-1">Du</label>
                <input type="date" id="date_debut" name="date_debut" value="{{ $dateDebut }}" class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 px-3 py-2 text-xs">
            </div>

            <div class="w-40">
                <label for="date_fin" class="block text-xs font-bold text-slate-500 mb-1">Au</label>
                <input type="date" id="date_fin" name="date_fin" value="{{ $dateFin }}" class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 px-3 py-2 text-xs">
            </div>

            <div class="pt-5 flex items-center gap-2">
                <button type="submit" class="px-4 py-2 rounded-xl text-xs font-bold bg-primary-800 text-white hover:bg-primary-900 transition">
                    Filtrer
                </button>
                <a href="{{ route('enseignant.presences.index') }}" class="px-3 py-2 rounded-xl text-xs font-semibold text-slate-500 hover:bg-slate-100 transition">
                    Réinitialiser
                </a>
            </div>
        </form>

        {{-- Tableau des présences --}}
        <div class="mt-6 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Historique des séances</h3>
                <span class="text-xs text-slate-500">{{ $presences->total() }} enregistrement(s)</span>
            </div>

            @if($presences->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs min-w-[680px]">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-700 dark:text-slate-300 uppercase font-black tracking-wider text-[10px]">
                            <tr>
                                <th scope="col" class="px-5 py-3">Date & Séance</th>
                                <th scope="col" class="px-5 py-3">Matière & Groupe</th>
                                <th scope="col" class="px-5 py-3">Salle</th>
                                <th scope="col" class="px-5 py-3">Pointage effectif</th>
                                <th scope="col" class="px-5 py-3">Statut</th>
                                <th scope="col" class="px-5 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                            @foreach($presences as $p)
                                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition">
                                    <td class="px-5 py-3.5 whitespace-nowrap">
                                        <p class="font-bold text-slate-900 dark:text-slate-100">{{ $p->date?->format('d/m/Y') }}</p>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 font-mono">{{ substr((string) ($p->slot?->start_time ?? ''), 0, 5) }} - {{ substr((string) ($p->slot?->end_time ?? ''), 0, 5) }}</p>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <p class="font-bold text-slate-800 dark:text-slate-100">{{ $p->slot?->matiere?->nom ?? '—' }}</p>
                                        <p class="text-[11px] text-slate-500">{{ $p->slot?->schedule?->groupe?->nom ?? '—' }}</p>
                                    </td>
                                    <td class="px-5 py-3.5 whitespace-nowrap font-semibold">
                                        {{ $p->slot?->room?->code ?? '—' }}
                                    </td>
                                    <td class="px-5 py-3.5 whitespace-nowrap">
                                        @if($p->heure_arrivee)
                                            <span class="font-mono text-slate-800 dark:text-slate-200">Arrivée : {{ substr((string) $p->heure_arrivee, 0, 5) }}</span>
                                            @if($p->retard_minutes > 0)
                                                <span class="block text-[11px] text-amber-600 font-semibold">+{{ $p->retard_minutes }} min de retard</span>
                                            @endif
                                        @else
                                            <span class="text-slate-400 italic">Aucun pointage</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3.5 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold {{ $p->statutEffectif() === 'presence_confirmee' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : ($p->statutEffectif() === 'absence_validee' ? 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300') }}">
                                            {{ $p->libelle_statut_effectif }}
                                        </span>
                                        @if($p->valide_par)
                                            <span class="block text-[10px] text-slate-400 mt-0.5">Validé par la scolarité</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                        <a href="{{ route('enseignant.reclamations.create', [
                                                'categorie' => 'presence',
                                                'objet' => 'Contestation présence du ' . ($p->date?->format('d/m/Y') ?? '') . ' (' . ($p->slot?->matiere?->nom ?? '') . ')',
                                            ]) }}"
                                           class="text-[11px] font-bold text-primary-700 dark:text-primary-300 hover:underline">
                                            Contester / Vérifier
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="p-4 border-t border-slate-100 dark:border-slate-800">
                    {{ $presences->links() }}
                </div>
            @else
                <div class="p-8 text-center">
                    <x-empty-state message="Aucun enregistrement de présence ne correspond aux critères." />
                </div>
            @endif
        </div>

        {{-- Historique des pointages bruts récents --}}
        <div class="mt-8 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm p-5">
            <h4 class="text-xs font-black uppercase tracking-wider text-slate-400 mb-3">Derniers pointages matériels bruts (badgeuse / borne / terminal)</h4>
            @if($derniersPointages->isNotEmpty())
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach($derniersPointages as $pointage)
                        <div class="p-3 rounded-xl border border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/40 text-xs flex items-center justify-between">
                            <div>
                                <span class="font-bold text-slate-800 dark:text-slate-100 uppercase">{{ $pointage->type }}</span>
                                <span class="text-slate-400 text-[11px]"> · {{ $pointage->source }}</span>
                                @if($pointage->notes)
                                    <p class="text-[10px] text-slate-500 mt-0.5 truncate max-w-[180px]">{{ $pointage->notes }}</p>
                                @endif
                            </div>
                            <span class="font-mono text-slate-500 text-[11px]">{{ \Carbon\Carbon::parse($pointage->horodatage)->format('d/m H:i:s') }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-xs text-slate-400 italic">Aucun pointage matériel enregistré récemment.</p>
            @endif
        </div>
    </div>
@endsection

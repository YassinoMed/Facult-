@extends('layouts.app')

@section('title', 'Examens')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8"
         x-data="{
            modalNouvelExamen: {{ $errors->any() ? 'true' : 'false' }},
            sessionSelectionnee: '{{ old('session_id', '') }}',
            anneeSelectionnee: '{{ old('annee_universitaire_id', '') }}'
         }">
        <x-page-header titre="Examens" description="Organisation d’évaluation — transitions contrôlées serveur."
            :fil="['Évaluations' => route('admin.evaluations.index'), 'Examens' => '#']">
            @can('evaluations.create')
                <x-slot:actions>
                    <x-btn @click="modalNouvelExamen = true" variant="primary">
                        <svg class="h-4 w-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        <span>Nouvel examen</span>
                    </x-btn>
                </x-slot:actions>
            @endcan
        </x-page-header>

        @if(session('succes'))
            <div class="mt-4"><x-alert type="success" :message="session('succes')" /></div>
        @endif
        @if(session('erreur'))
            <div class="mt-4"><x-alert type="danger" :message="session('erreur')" /></div>
        @endif

        <div class="mt-4 ds-card overflow-hidden">
            @if($examens->isEmpty())
                <x-empty-state
                    titre="Aucun examen enregistré"
                    message="Créez un examen rattaché à une session d'évaluation pour commencer à organiser les épreuves."
                >
                    @can('evaluations.create')
                        <x-slot:action>
                            <x-btn @click="modalNouvelExamen = true">
                                <svg class="h-4 w-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                                <span>Nouvel examen</span>
                            </x-btn>
                        </x-slot:action>
                    @endcan
                </x-empty-state>
            @else
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                        <tr><th class="px-4 py-2">Libellé</th><th class="px-4 py-2">Session</th><th class="px-4 py-2">Type</th><th class="px-4 py-2">Statut</th><th class="px-4 py-2"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($examens as $examen)
                            <tr>
                                <td class="px-4 py-2 font-bold">{{ $examen->libelle }}</td>
                                <td class="px-4 py-2">{{ $examen->session->nom }}</td>
                                <td class="px-4 py-2">{{ $examen->type }}</td>
                                <td class="px-4 py-2"><x-status-badge :statut="$examen->statut" /></td>
                                <td class="px-4 py-2 text-right">
                                    @can('evaluations.update')
                                        @foreach(\App\Modules\Evaluation\Models\Examen::TRANSITIONS[$examen->statut] ?? [] as $cible)
                                            <form method="post" action="{{ route('admin.evaluations.examens.transiter', [$examen, $cible]) }}" class="inline">
                                                @csrf
                                                <x-btn size="sm" variant="secondary" type="submit">→ {{ $cible }}</x-btn>
                                            </form>
                                        @endforeach
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-4 py-3">{{ $examens->links() }}</div>
            @endif
        </div>

        @can('evaluations.create')
            <x-modal titre="Créer un nouvel examen" nom="modalNouvelExamen" taille="lg">
                @if($errors->any())
                    <div class="mb-4">
                        <x-alert type="danger" message="Veuillez corriger les erreurs ci-dessous avant d'enregistrer l'examen." />
                    </div>
                @endif

                @if($sessions->isEmpty())
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-800 text-sm">
                        <p class="font-bold">Aucune session d’examen active disponible.</p>
                        <p class="mt-1 text-xs">Pour pouvoir créer un examen, vous devez d'abord configurer une session d'évaluation non verrouillée.</p>
                        <div class="mt-3">
                            <x-btn size="sm" variant="secondary" href="{{ route('admin.evaluations.sessions.create') }}">Créer une session</x-btn>
                        </div>
                    </div>
                @else
                    <form id="form-creer-examen" method="POST" action="{{ route('admin.evaluations.examens.store') }}" class="space-y-4">
                        @csrf

                        {{-- Session d'examen (obligatoire) --}}
                        <div>
                            <label for="examen_session_id" class="block text-xs font-bold text-slate-700 mb-1">
                                Session d’examen <span class="text-rose-500">*</span>
                            </label>
                            <select id="examen_session_id" name="session_id" required
                                    x-model="sessionSelectionnee"
                                    @change="const opt = $event.target.selectedOptions[0]; if (opt && opt.dataset.annee) { anneeSelectionnee = opt.dataset.annee; }"
                                    class="w-full rounded-xl border {{ $errors->has('session_id') ? 'border-rose-500 bg-rose-50/30' : 'border-slate-300' }} px-3 py-2 text-sm min-h-[44px]">
                                <option value="">— Choisir une session d’évaluation —</option>
                                @foreach($sessions as $session)
                                    <option value="{{ $session->id }}" data-annee="{{ $session->annee_universitaire_id }}" {{ old('session_id') == $session->id ? 'selected' : '' }}>
                                        {{ $session->nom }} ({{ ucfirst($session->type) }} — {{ $session->anneeUniversitaire?->libelle ?? 'Année non spécifiée' }})
                                    </option>
                                @endforeach
                            </select>
                            @error('session_id')
                                <p class="mt-1 text-xs text-rose-600 font-semibold">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Libellé de l'examen (obligatoire) --}}
                        <div>
                            <label for="examen_libelle" class="block text-xs font-bold text-slate-700 mb-1">
                                Libellé de l’examen <span class="text-rose-500">*</span>
                            </label>
                            <input type="text" id="examen_libelle" name="libelle" required maxlength="200"
                                   value="{{ old('libelle') }}"
                                   placeholder="Ex. Examen final Semestre 1 - Informatique"
                                   class="w-full rounded-xl border {{ $errors->has('libelle') ? 'border-rose-500 bg-rose-50/30' : 'border-slate-300' }} px-3 py-2 text-sm min-h-[44px]">
                            @error('libelle')
                                <p class="mt-1 text-xs text-rose-600 font-semibold">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            {{-- Type d'examen (obligatoire) --}}
                            <div>
                                <label for="examen_type" class="block text-xs font-bold text-slate-700 mb-1">
                                    Type d’évaluation <span class="text-rose-500">*</span>
                                </label>
                                <select id="examen_type" name="type" required
                                        class="w-full rounded-xl border {{ $errors->has('type') ? 'border-rose-500 bg-rose-50/30' : 'border-slate-300' }} px-3 py-2 text-sm min-h-[44px]">
                                    @php
                                        $typesLibelles = [
                                            'examen' => 'Examen standard',
                                            'controle' => 'Contrôle continu',
                                            'partiel' => 'Partiel',
                                            'devoir' => 'Devoir surveillé',
                                            'oral' => 'Épreuve orale',
                                            'projet' => 'Projet',
                                            'soutenance' => 'Soutenance',
                                            'rattrapage' => 'Session de rattrapage',
                                        ];
                                    @endphp
                                    @foreach(\App\Modules\Evaluation\Models\Examen::TYPES as $typeOption)
                                        <option value="{{ $typeOption }}" {{ old('type', 'examen') === $typeOption ? 'selected' : '' }}>
                                            {{ $typesLibelles[$typeOption] ?? ucfirst($typeOption) }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('type')
                                    <p class="mt-1 text-xs text-rose-600 font-semibold">{{ $message }}</p>
                                @enderror
                            </div>

                            {{-- Année universitaire (obligatoire) --}}
                            <div>
                                <label for="examen_annee_universitaire_id" class="block text-xs font-bold text-slate-700 mb-1">
                                    Année universitaire <span class="text-rose-500">*</span>
                                </label>
                                <select id="examen_annee_universitaire_id" name="annee_universitaire_id" required
                                        x-model="anneeSelectionnee"
                                        class="w-full rounded-xl border {{ $errors->has('annee_universitaire_id') ? 'border-rose-500 bg-rose-50/30' : 'border-slate-300' }} px-3 py-2 text-sm min-h-[44px]">
                                    <option value="">— Choisir l’année —</option>
                                    @foreach($annees as $annee)
                                        <option value="{{ $annee->id }}" {{ old('annee_universitaire_id') == $annee->id ? 'selected' : '' }}>
                                            {{ $annee->libelle }} {{ $annee->active ? '(Active)' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('annee_universitaire_id')
                                    <p class="mt-1 text-xs text-rose-600 font-semibold">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            {{-- Programme (facultatif) --}}
                            <div>
                                <label for="examen_programme_id" class="block text-xs font-bold text-slate-700 mb-1">
                                    Programme <span class="text-slate-400 font-normal">(optionnel)</span>
                                </label>
                                <select id="examen_programme_id" name="programme_id"
                                        class="w-full rounded-xl border {{ $errors->has('programme_id') ? 'border-rose-500 bg-rose-50/30' : 'border-slate-300' }} px-3 py-2 text-sm min-h-[44px]">
                                    <option value="">— Aucun programme (transversal) —</option>
                                    @foreach($programmes as $programme)
                                        <option value="{{ $programme->id }}" {{ old('programme_id') == $programme->id ? 'selected' : '' }}>
                                            {{ $programme->code }} — {{ $programme->nom }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('programme_id')
                                    <p class="mt-1 text-xs text-rose-600 font-semibold">{{ $message }}</p>
                                @enderror
                            </div>

                            {{-- Groupe (facultatif) --}}
                            <div>
                                <label for="examen_groupe_id" class="block text-xs font-bold text-slate-700 mb-1">
                                    Groupe <span class="text-slate-400 font-normal">(optionnel)</span>
                                </label>
                                <select id="examen_groupe_id" name="groupe_id"
                                        class="w-full rounded-xl border {{ $errors->has('groupe_id') ? 'border-rose-500 bg-rose-50/30' : 'border-slate-300' }} px-3 py-2 text-sm min-h-[44px]">
                                    <option value="">— Aucun groupe spécifique —</option>
                                    @foreach($groupes as $groupe)
                                        <option value="{{ $groupe->id }}" {{ old('groupe_id') == $groupe->id ? 'selected' : '' }}>
                                            {{ $groupe->code }} — {{ $groupe->nom }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('groupe_id')
                                    <p class="mt-1 text-xs text-rose-600 font-semibold">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        {{-- Description (facultatif) --}}
                        <div>
                            <label for="examen_description" class="block text-xs font-bold text-slate-700 mb-1">
                                Description & Consignes <span class="text-slate-400 font-normal">(optionnel)</span>
                            </label>
                            <textarea id="examen_description" name="description" rows="2"
                                      placeholder="Consignes générales pour les étudiants ou surveillants..."
                                      class="w-full rounded-xl border {{ $errors->has('description') ? 'border-rose-500 bg-rose-50/30' : 'border-slate-300' }} px-3 py-2 text-sm">{{ old('description') }}</textarea>
                            @error('description')
                                <p class="mt-1 text-xs text-rose-600 font-semibold">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Boutons d'action dans le formulaire --}}
                        <div class="mt-6 flex flex-col-reverse sm:flex-row sm:justify-end gap-2.5 pt-4 border-t border-slate-100">
                            <x-btn type="button" variant="secondary" @click="modalNouvelExamen = false">Annuler</x-btn>
                            <x-btn type="submit" variant="primary">Enregistrer l’examen</x-btn>
                        </div>
                    </form>
                @endif
            </x-modal>
        @endcan
    </div>
@endsection

@extends('layouts.app')

@section('title', 'Rendez-vous & Demande d\'aide — Espace Étudiant')

@section('content')
<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 sm:py-12">
    <div class="mb-6">
        <a href="{{ route('etudiant.dashboard') }}" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-primary-800 transition">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
            </svg>
            Retour au tableau de bord
        </a>
        <p class="mt-3 text-[11px] font-bold uppercase tracking-wider text-primary-700">Aide humaine & Permanence</p>
        <h1 class="mt-1 text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">Prendre rendez-vous avec la scolarité</h1>
        <p class="mt-2 text-sm leading-relaxed text-slate-600">
            Lorsque l'automatisation ne suffit pas, réservez un créneau d'assistance auprès du service scolarité de votre faculté.
        </p>
    </div>

    @if($errors->any())
        <div class="mb-6 rounded-2xl border border-rose-300 bg-rose-50 p-4 text-xs text-rose-800" role="alert">
            <p class="font-bold">Veuillez corriger les points suivants :</p>
            <ul class="mt-1 list-disc list-inside space-y-0.5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8"
         x-data="rdvScolarite({
             urlCreneaux: '{{ route('etudiant.rendez-vous.creneaux') }}',
             dateInitiale: '{{ old('date_creneau', $dateParDefaut) }}',
             heureInitiale: '{{ old('heure_creneau', '') }}',
             creneauxInitiaux: @js($creneauxInitiaux)
         })">

        <form method="POST" action="{{ route('etudiant.rendez-vous.store') }}" class="space-y-6">
            @csrf

            {{-- 1. Motif catégorisé --}}
            <div>
                <label for="motif_categorie" class="block text-xs font-black uppercase tracking-wider text-slate-700">
                    Motif de votre démarche <span class="text-rose-600">*</span>
                </label>
                <p class="mt-0.5 text-xs text-slate-500">Sélectionnez la catégorie qui correspond le mieux à votre situation.</p>
                <select id="motif_categorie" name="motif_categorie" required
                        class="mt-2 block w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 focus:border-primary-600 focus:outline-none focus:ring-4 focus:ring-primary-500/10 transition">
                    <option value="">— Sélectionner un motif —</option>
                    @foreach($motifs as $cle => $libelle)
                        <option value="{{ $cle }}" @selected(old('motif_categorie') === $cle)>{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>

            {{-- 2. Description minimale du problème --}}
            <div>
                <label for="description" class="block text-xs font-black uppercase tracking-wider text-slate-700">
                    Précisions synthétiques <span class="text-rose-600">*</span>
                </label>
                <p class="mt-0.5 text-xs text-slate-500">Expliquez brièvement votre situation pour préparer l'entretien (données personnelles non exigées).</p>
                <textarea id="description" name="description" rows="3" required maxlength="2000"
                          placeholder="Indiquez brièvement les détails utiles (ex. : problème rencontré lors du dépôt, question sur un choix de parcours...)"
                          class="mt-2 block w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 focus:border-primary-600 focus:outline-none focus:ring-4 focus:ring-primary-500/10 transition">{{ old('description') }}</textarea>
            </div>

            {{-- 3. Sélection de la date --}}
            <div>
                <label for="date_creneau" class="block text-xs font-black uppercase tracking-wider text-slate-700">
                    Date souhaitée <span class="text-rose-600">*</span>
                </label>
                <p class="mt-0.5 text-xs text-slate-500">Les permanences sont assurées du lundi au vendredi.</p>
                <input type="date"
                       id="date_creneau"
                       name="date_creneau"
                       x-model="dateSelectionnee"
                       @change="chargerCreneaux"
                       min="{{ date('Y-m-d') }}"
                       required
                       class="mt-2 block w-full sm:w-64 rounded-2xl border border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:outline-none focus:ring-4 focus:ring-primary-500/10 transition">
            </div>

            {{-- 4. Choix du créneau horaire réellement disponible --}}
            <div>
                <div class="flex items-center justify-between">
                    <label class="block text-xs font-black uppercase tracking-wider text-slate-700">
                        Créneau horaire disponible <span class="text-rose-600">*</span>
                    </label>
                    <span x-show="chargement" class="inline-flex items-center gap-1.5 text-xs text-primary-700">
                        <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        Vérification des disponibilités...
                    </span>
                </div>
                <p class="mt-0.5 text-xs text-slate-500">Seuls les créneaux réellement disponibles peuvent être réservés.</p>

                <input type="hidden" name="heure_creneau" :value="heureSelectionnee" required>

                <div class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                    <template x-for="c in creneaux" :key="c.creneau_id">
                        <button type="button"
                                :disabled="!c.disponible"
                                @click="heureSelectionnee = c.heure"
                                :class="{
                                    'border-primary-600 bg-primary-50 ring-2 ring-primary-600 text-primary-900 font-bold': heureSelectionnee === c.heure,
                                    'border-slate-200 bg-white text-slate-800 hover:border-slate-300 hover:bg-slate-50': c.disponible && heureSelectionnee !== c.heure,
                                    'border-slate-100 bg-slate-50 text-slate-400 cursor-not-allowed opacity-60': !c.disponible
                                }"
                                class="flex flex-col items-center justify-center rounded-2xl border p-3 text-center transition">
                            <span class="text-sm font-bold" x-text="c.heure"></span>
                            <span class="mt-1 text-[10px]"
                                  :class="c.disponible ? 'text-emerald-700 font-medium' : 'text-slate-400'"
                                  x-text="c.disponible ? 'Disponible' : 'Complet'"></span>
                        </button>
                    </template>
                </div>

                <div x-show="creneaux.length === 0 && !chargement" class="mt-3 rounded-2xl border border-dashed border-slate-200 p-4 text-center text-xs text-slate-500">
                    Aucun créneau de permanence n'est ouvert pour cette date (week-end ou date passée). Veuillez choisir une autre date.
                </div>
            </div>

            {{-- Engagement --}}
            <div class="border-t border-slate-100 pt-5">
                <p class="text-xs text-slate-500">
                    Horaires de scolarité : <strong class="text-slate-700">{{ $contact['horaires'] ?? 'Lun–Ven' }}</strong>.
                    Une confirmation vous sera adressée dès la validation de votre réservation par le serveur.
                </p>
            </div>

            <div class="flex flex-col sm:flex-row items-center justify-end gap-3 pt-2">
                <a href="{{ route('etudiant.dashboard') }}"
                   class="w-full sm:w-auto inline-flex items-center justify-center rounded-2xl border border-slate-300 bg-white px-6 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition">
                    Annuler
                </a>
                <button type="submit"
                        :disabled="!heureSelectionnee || chargement"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-2xl bg-primary-800 px-8 py-3 text-sm font-bold text-white hover:bg-primary-700 disabled:opacity-50 transition">
                    Confirmer le rendez-vous
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('rdvScolarite', (config) => ({
        urlCreneaux: config.urlCreneaux,
        dateSelectionnee: config.dateInitiale,
        heureSelectionnee: config.heureInitiale,
        creneaux: config.creneauxInitiaux || [],
        chargement: false,

        async chargerCreneaux() {
            if (!this.dateSelectionnee) return;

            this.chargement = true;
            this.heureSelectionnee = '';

            try {
                const res = await fetch(`${this.urlCreneaux}?date=${encodeURIComponent(this.dateSelectionnee)}`, {
                    headers: { 'Accept': 'application/json' }
                });

                if (res.ok) {
                    const data = await res.json();
                    this.creneaux = data.creneaux || [];
                } else {
                    this.creneaux = [];
                }
            } catch (e) {
                console.error('Erreur chargement créneaux:', e);
                this.creneaux = [];
            } finally {
                this.chargement = false;
            }
        }
    }));
});
</script>
@endsection

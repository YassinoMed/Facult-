{{--
    Champs d'une configuration de contraintes (§10) : dimensionnement,
    pondérations de présence, limite de jours, 6 objectifs SOFT
    (actif + poids/pénalité). $valeurs = tableau de valeurs par défaut.
--}}
@php($v = fn ($cle, $defaut = '') => old($cle, $valeurs[$cle] ?? $defaut))
@php($coche = fn ($cle, $defaut = true) => old($cle, ($valeurs[$cle] ?? $defaut) ? '1' : null) ? 'checked' : '')
@php($classe = 'w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-2.5 py-1.5 text-xs focus:border-indigo-500')

<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
    <div>
        <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Min surveillants</label>
        <input type="number" name="min_surveillants" min="1" max="20" required value="{{ $v('min_surveillants', 2) }}" class="{{ $classe }}">
    </div>
    <div>
        <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Min par salle</label>
        <input type="number" name="min_par_salle" min="1" max="20" value="{{ $v('min_par_salle', 1) }}" class="{{ $classe }}">
        <p class="mt-0.5 text-[10px] text-slate-400">Plancher de chaque salle (défaut 1).</p>
    </div>
    <div>
        <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Ratio étudiants/surv.</label>
        <input type="number" name="ratio_etudiants" min="1" max="500" required value="{{ $v('ratio_etudiants', 50) }}" class="{{ $classe }}">
    </div>
    <div>
        <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Seuil grande salle</label>
        <input type="number" name="seuil_grande_salle" min="10" max="2000" value="{{ $v('seuil_grande_salle') }}" class="{{ $classe }}">
        <p class="mt-0.5 text-[10px] text-slate-400">Capacité/effectif seuil (vide = inactif).</p>
    </div>
    <div>
        <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Min grande salle</label>
        <input type="number" name="min_surveillants_grande_salle" min="1" max="20" value="{{ $v('min_surveillants_grande_salle') }}" class="{{ $classe }}">
        <p class="mt-0.5 text-[10px] text-slate-400">Plancher grande salle (ex. 3 ou 4).</p>
    </div>
    <div>
        <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Gardes</label>
        <input type="number" name="nb_gardes" min="0" max="10" required value="{{ $v('nb_gardes', 1) }}" class="{{ $classe }}">
    </div>
    <div>
        <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Max surv. (vide = aucun)</label>
        <input type="number" name="max_surveillants" min="1" max="50" value="{{ $v('max_surveillants') }}" class="{{ $classe }}">
    </div>
    <div>
        <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Poids présence active</label>
        <input type="number" name="poids_surveillance_active" min="0" max="100" step="0.01" required value="{{ $v('poids_surveillance_active', 1) }}" class="{{ $classe }}">
    </div>
    <div>
        <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Poids garde</label>
        <input type="number" name="poids_garde_reserve" min="0" max="100" step="0.01" required value="{{ $v('poids_garde_reserve', 0.5) }}" class="{{ $classe }}">
    </div>
    <div>
        <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Max jours</label>
        <input type="number" name="max_jours" min="1" max="7" required value="{{ $v('max_jours', 3) }}" class="{{ $classe }}">
    </div>
    <div>
        <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Mode limite</label>
        <select name="mode_limite" class="{{ $classe }}">
            <option value="souple" @selected($v('mode_limite', 'souple') === 'souple')>Souple (pénalité)</option>
            <option value="strict" @selected($v('mode_limite') === 'strict')>Strict (exclusion)</option>
        </select>
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 text-xs mt-3">
    @foreach([
        'charge_totale' => ['Équilibrage charge totale', 'poids_charge_totale', 10],
        'charge_jour' => ['Équilibrage journalier', 'poids_charge_jour', 15],
        'gardes' => ['Équilibrage des gardes', 'poids_gardes', 8],
        'limite_jours' => ['Limite de jours', 'penalite_depassement_jours', 35],
        'dispersion' => ['Dispersion temporelle', 'penalite_dispersion', 10],
        'nouveau_jour' => ['Nouveaux jours', 'penalite_nouveau_jour', 2],
    ] as $cle => [$libelle, $champPoids, $defaut])
        <div class="rounded-lg border border-slate-200 dark:border-slate-700 p-2.5">
            <label class="flex items-center gap-2 font-bold text-slate-700 dark:text-slate-200">
                <input type="checkbox" name="actif_{{ $cle }}" value="1" {{ $coche('actif_'.$cle) }} class="rounded">
                {{ $libelle }}
            </label>
            <label class="block mt-1.5 text-[11px] text-slate-500">Poids / pénalité</label>
            <input type="number" name="{{ $champPoids }}" min="0" max="1000" step="0.01" required value="{{ $v($champPoids, $defaut) }}" class="{{ $classe }}">
        </div>
    @endforeach

    {{-- Carte dédiée : Charge d'enseignement (SOFT configurable) --}}
    <div class="rounded-lg border border-indigo-200 dark:border-indigo-800 bg-indigo-50/30 dark:bg-indigo-950/20 p-2.5 sm:col-span-2 lg:col-span-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <label class="flex items-center gap-2 font-bold text-indigo-900 dark:text-indigo-200">
                <input type="checkbox" name="actif_charge_enseignement" value="1" {{ $coche('actif_charge_enseignement', false) }} class="rounded text-indigo-600">
                Prise en compte de la charge d’enseignement (critère SOFT configurable)
            </label>
            <span class="text-[11px] text-indigo-600 dark:text-indigo-400 font-semibold">Normalisation relative [0.0, 1.0]</span>
        </div>
        <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
            Favorise l'affectation des enseignants à plus faible charge de cours annuelle. Les contraintes HARD (conflits, capacités, facultés) restent strictement prioritaires.
        </p>
        <div class="mt-2.5 grid grid-cols-1 sm:grid-cols-4 gap-2.5">
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-0.5">Poids du critère (0-1000)</label>
                <input type="number" name="poids_charge_enseignement" min="0" max="1000" step="0.01" value="{{ $v('poids_charge_enseignement', 5.0) }}" class="{{ $classe }}">
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-0.5">Mode de calcul</label>
                <select name="mode_calcul_charge_enseignement" class="{{ $classe }}">
                    <option value="heures_brutes" @selected($v('mode_calcul_charge_enseignement', 'heures_brutes') === 'heures_brutes')>Heures brutes (CM+TD+TP)</option>
                    <option value="hetd" @selected($v('mode_calcul_charge_enseignement') === 'hetd')>Équivalent TD (CM×1.5, TD×1, TP×0.75)</option>
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-0.5">Seuil de surcharge (heures, optionnel)</label>
                <input type="number" name="seuil_surcharge_enseignement" min="0" max="2000" step="0.1" placeholder="ex: 192.0" value="{{ $v('seuil_surcharge_enseignement') }}" class="{{ $classe }}">
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 dark:text-slate-300 mb-0.5">Plafond surveillances / enseignant (optionnel)</label>
                <input type="number" name="max_surveillances_enseignant" min="1" max="100" placeholder="ex: 4" value="{{ $v('max_surveillances_enseignant') }}" class="{{ $classe }}">
            </div>
        </div>
    </div>
</div>

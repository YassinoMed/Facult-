{{--
    Onglets admin : boutons role=tab + panneaux, flèches clavier,
    aria-selected/controls. Le contenu reste dans le DOM (SEO/tests).
    Deep-link : #cle (ou #panneau-cle) ouvre l'onglet, au chargement
    comme sur hashchange (liens d'anomalies, retours formulaire).
    Usage :
    <x-tabs :onglets="['infos' => 'Informations', 'docs' => 'Documents']">
        <x-slot:panneau_infos>…</x-slot:panneau_infos>
        <x-slot:panneau_docs>…</x-slot:panneau_docs>
    </x-tabs>
--}}
@props(['onglets' => [], 'actif' => null])

@php($premier = $actif ?? array_key_first($onglets))
@php($cles = array_keys($onglets))

<div x-data="{
    onglet: '{{ $premier }}',
    cles: @js($cles),
    init() {
        // Deep-link : #parcours (ou #panneau-parcours) ouvre l'onglet visé,
        // au chargement comme à la navigation (liens d'anomalies, retours).
        const appliquerHash = () => {
            const hash = (window.location.hash || '').replace(/^#panneau-?/, '').replace(/^#/, '');
            if (hash !== '' && this.cles.includes(hash)) { this.onglet = hash; }
        };
        appliquerHash();
        window.addEventListener('hashchange', appliquerHash);
    },
    focusOnglet(i) { const onglets = [...$el.querySelectorAll('[role=tab]')]; const n = (i + onglets.length) % onglets.length; onglets[n].focus(); onglets[n].click(); }
}" class="admin-tabs">
    <div role="tablist" aria-label="Sections de la page"
         class="flex gap-1 overflow-x-auto border-b border-slate-200"
         @keydown.arrow-right.prevent="focusOnglet([...$el.querySelectorAll('[role=tab]')].indexOf(document.activeElement) + 1)"
         @keydown.arrow-left.prevent="focusOnglet([...$el.querySelectorAll('[role=tab]')].indexOf(document.activeElement) - 1)">
        @foreach($onglets as $cle => $libelle)
            <button type="button" role="tab" id="onglet-{{ $cle }}" aria-controls="panneau-{{ $cle }}"
                    :aria-selected="(onglet === '{{ $cle }}').toString()" :tabindex="onglet === '{{ $cle }}' ? 0 : -1"
                    @click="onglet = '{{ $cle }}'"
                    class="whitespace-nowrap px-4 py-2.5 text-sm font-bold min-h-[44px]"
                    :class="onglet === '{{ $cle }}' ? 'text-primary-800' : 'text-slate-500 hover:text-slate-900'">
                {{ $libelle }}
            </button>
        @endforeach
    </div>
    @foreach($onglets as $cle => $libelle)
        <div x-show="onglet === '{{ $cle }}'" x-cloak role="tabpanel" id="panneau-{{ $cle }}"
             aria-labelledby="onglet-{{ $cle }}" tabindex="0" class="pt-4">
            {{ ${'panneau_'.$cle} ?? '' }}
        </div>
    @endforeach
</div>

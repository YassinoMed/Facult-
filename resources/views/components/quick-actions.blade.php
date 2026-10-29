{{--
    Actions rapides contextuelles (Phase 7 §7.3) : bouton « + Actions »
    affichant uniquement les actions autorisées pour la page et le
    sujet courants (registre central, permission + périmètre).
    Usage : <x-quick-actions contexte="etudiant.show" :sujet="$etudiant" />
    Rien ne s'affiche si aucune action n'est disponible.
--}}
@props(['contexte', 'sujet' => null])

@php
$actionsRapides = auth()->check()
    ? app(\App\Support\Actions\QuickActionRegistry::class)->pour($contexte, $sujet, auth()->user())
    : [];
@endphp

@if($actionsRapides !== [])
    <x-dropdown etiquette="Actions rapides">
        <x-slot:liens>
            @foreach($actionsRapides as $action)
                <a href="{{ $action['url'] }}" role="menuitem"
                   class="block rounded-xl px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                    @if(! empty($action['icon']))<span aria-hidden="true">{{ $action['icon'] }}</span> @endif{{ $action['label'] }}
                </a>
            @endforeach
        </x-slot:liens>
    </x-dropdown>
@endif

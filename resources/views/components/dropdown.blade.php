{{--
    Menu d'actions secondaires (⋮) : un seul bouton visible par ligne,
    actions regroupées, Escape + clic extérieur, navigation clavier.
    Usage :
    <x-dropdown etiquette="Actions pour {{ $nom }}">
        <x-slot:liens>
            <a href="…">Voir</a>
            <button type="button" @click="…">Supprimer</button>
        </x-slot:liens>
    </x-dropdown>
--}}
@props(['etiquette' => 'Actions'])

<div x-data="{ ouvert: false }" class="relative inline-block text-left" @keydown.escape.window="ouvert = false">
    <button type="button" @click="ouvert = !ouvert" :aria-expanded="ouvert.toString()"
            aria-label="{{ $etiquette }}" aria-haspopup="menu"
            class="rounded-xl p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900 min-h-[44px] min-w-[44px]">
        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 3a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM10 8.5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM10 14a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Z" /></svg>
    </button>
    <div x-show="ouvert" x-cloak @click.outside="ouvert = false"
         x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-out duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
         role="menu" aria-label="{{ $etiquette }}"
         class="absolute right-0 z-30 mt-1 w-48 origin-top-right rounded-xl bg-white py-1 shadow-lg"><!-- taste-skill-ignore : ombre de menu -->
        {{ $liens ?? $slot }}
    </div>
</div>

{{--
    Actions d'export unifiées (§33) : PDF / Excel / CSV / Imprimer sur la
    MÊME population (query string conservée). Un seul composant partout.
--}}
@props(['emargement' => false])

@php
$query = request()->except(['_token']);
$base = [
    'csv' => route('admin.listes.exporter', array_merge(['format' => 'csv'], $query)),
    'xlsx' => route('admin.listes.exporter', array_merge(['format' => 'xlsx'], $query)),
    'pdf' => route('admin.listes.exporter', array_merge(['format' => 'pdf'], $query)),
    'print' => route('admin.listes.imprimer', $query),
];
@endphp

@can('data.export')
<div {{ $attributes->merge(['class' => 'flex flex-wrap gap-2']) }} role="group" aria-label="Exporter ou imprimer">
    <x-btn size="sm" href="{{ $base['pdf'] }}">PDF</x-btn>
    <x-btn size="sm" variant="secondary" href="{{ $base['xlsx'] }}">Excel</x-btn>
    <x-btn size="sm" variant="secondary" href="{{ $base['csv'] }}">CSV</x-btn>
    <x-btn size="sm" variant="ghost" href="{{ $base['print'] }}">Imprimer</x-btn>
</div>
@else
    {{-- Sans permission d'export, aucune action d'export n'est affichée. --}}
@endif

{{--
    Indicateur de chargement inline (spinner + message, role=status).
    Usage : <x-loading message="Chargement…" /> ou <x-loading:rien />.
--}}
@props(['message' => 'Chargement…'])

<p role="status" class="inline-flex items-center gap-2 text-sm text-slate-500">
    <span class="admin-spinner" aria-hidden="true"></span>
    {{ $message }}
</p>

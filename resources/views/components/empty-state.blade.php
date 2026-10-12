{{-- État vide réutilisable (§28) : message explicite, jamais un tableau vide muet. --}}
@props(['message'])

<p class="rounded-xl border border-dashed border-slate-300 bg-slate-50/60 px-5 py-8 text-center text-sm text-slate-500" role="status">
    {{ $message }}
</p>

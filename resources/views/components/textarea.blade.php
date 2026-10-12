{{--
    Zone de texte (§23) : label + erreur inline + focus.
--}}
@props(['id', 'label', 'erreur' => null, 'rows' => 3])

@php $message = $erreur ?? (isset($errors) ? $errors->first($attributes->get('name')) : null); @endphp

<div>
    <label for="{{ $id }}" class="mb-1 block text-xs font-bold text-slate-700 uppercase tracking-wider">
        {{ $label }}@if($attributes->has('required')) <span class="text-rose-600" aria-hidden="true">*</span>@endif
    </label>
    <textarea id="{{ $id }}" rows="{{ $rows }}"
              aria-describedby="{{ $message ? $id . '-erreur' : '' }}" aria-invalid="{{ $message ? 'true' : 'false' }}"
              {{ $attributes->merge(['class' => 'w-full rounded-xl border px-3.5 py-2.5 text-sm text-slate-900 bg-white transition focus:outline-none resize-y ' . ($message ? 'border-rose-400 bg-rose-50/50' : 'border-slate-300 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20')]) }}>{{ $slot }}</textarea>
    @if($message)
        <p id="{{ $id }}-erreur" class="mt-1 text-xs font-semibold text-rose-700" role="alert">⚠ {{ $message }}</p>
    @endif
</div>

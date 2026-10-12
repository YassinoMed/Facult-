@extends('layouts.app')

@section('title', 'Aperçu PDF — Dossier ' . $dossier->reference)

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Aperçu du document</p>
                <h1 class="mt-1 text-xl sm:text-2xl font-black text-slate-900">Dossier {{ $dossier->reference }} <span class="font-mono text-sm text-slate-500">({{ $document->reference }})</span></h1>
            </div>
        </div>

        @if(session('success'))
            <div class="mt-4 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-xs font-bold text-emerald-900" role="status">✓ {{ session('success') }}</div>
        @endif
        @if(session('info'))
            <div class="mt-4 rounded-xl border border-sky-300 bg-sky-50 px-4 py-3 text-xs font-bold text-sky-900" role="status">ℹ {{ session('info') }}</div>
        @endif

        <div class="mt-4 flex flex-col sm:flex-row gap-3" role="group" aria-label="Actions du document PDF">
            <a href="{{ $urlTelechargement }}"
               aria-label="Télécharger le dossier PDF"
               class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-800 px-5 py-3 text-sm font-bold text-white hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px]">
                Télécharger PDF
            </a>
            <button type="button"
                    onclick="fetch('{{ $urlImpression }}', { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).finally(() => document.getElementById('apercu-pdf').contentWindow.print())"
                    aria-label="Imprimer le dossier"
                    class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px]">
                Imprimer
            </button>
        </div>

        <div class="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-slate-100 shadow-sm">
            <iframe id="apercu-pdf" title="Aperçu du dossier PDF" src="{{ $urlFichier }}"
                    class="h-[70vh] w-full bg-white" loading="lazy"></iframe>
        </div>
        <p class="mt-2 text-[11px] text-slate-400">Si l'aperçu ne s'affiche pas, utilisez « Télécharger PDF ».</p>
    </div>
@endsection

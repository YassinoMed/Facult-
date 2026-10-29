{{-- Actions du circuit de gouvernance (§2.7, §2.8) — jamais un
     bouton de publication direct : chaque action dépend du statut de la
     dernière version et de la permission réelle de l'utilisateur. --}}
@php
    $pv = \App\Modules\Evaluation\Models\PlanningValidation::class;
    $validation = $examen->validations->first();
    $statut = $validation?->statut;
    $bouton = 'inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-[11px] font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40';
@endphp

<div class="flex flex-col items-end gap-2">
    @if($validation !== null)
        <span class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">
            Version {{ $validation->version }} — {{ $statut }}
        </span>
    @endif

    <div class="flex flex-wrap items-center justify-end gap-1.5">
        @if($validation === null || $statut === $pv::STATUT_REJETE)
            @can('update', $examen)
                <form method="POST" action="{{ route('admin.evaluations.gouvernance.soumettre', $examen) }}" class="inline">
                    @csrf
                    <button type="submit" class="{{ $bouton }} bg-primary-800 text-white hover:bg-primary-700">
                        Soumettre au circuit
                    </button>
                </form>
            @endcan

        @elseif($statut === $pv::STATUT_SOUMIS)
            @can('evaluations.certify')
                <form method="POST" action="{{ route('admin.evaluations.gouvernance.certifier', $validation) }}" class="inline">
                    @csrf
                    <button type="submit" class="{{ $bouton }} bg-sky-700 text-white hover:bg-sky-600">
                        Certifier la conformité
                    </button>
                </form>
            @endcan

        @elseif($statut === $pv::STATUT_CERTIFIE)
            @can('evaluations.approve')
                @if($validation->approbateur_1 === null)
                    <form method="POST" action="{{ route('admin.evaluations.gouvernance.approuver-1', $validation) }}" class="inline">
                        @csrf
                        <button type="submit" class="{{ $bouton }} bg-indigo-700 text-white hover:bg-indigo-600">
                            Approuver (1/2)
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.evaluations.gouvernance.approuver-2', $validation) }}" class="inline">
                        @csrf
                        <button type="submit" class="{{ $bouton }} bg-indigo-700 text-white hover:bg-indigo-600">
                            Approuver (2/2)
                        </button>
                    </form>
                @endif
            @endcan

        @elseif($statut === $pv::STATUT_APPROUVE)
            @can('evaluations.publish')
                <details class="relative">
                    <summary class="{{ $bouton }} cursor-pointer list-none bg-emerald-600 text-white hover:bg-emerald-700">
                        Publier officiellement
                    </summary>
                    <form method="POST" action="{{ route('admin.evaluations.gouvernance.publier', $validation) }}"
                          class="absolute right-0 z-20 mt-1 w-72 rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
                        @csrf
                        <label class="block text-[11px] font-bold text-slate-600">
                            Justification exceptionnelle (si gel dépassé)
                            <textarea name="justification_exceptionnelle" rows="2" maxlength="2000"
                                      class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1 text-xs"></textarea>
                        </label>
                        <button type="submit" class="mt-2 w-full rounded-lg bg-emerald-600 px-2 py-1.5 text-xs font-bold text-white hover:bg-emerald-700">
                            Confirmer le scellement
                        </button>
                    </form>
                </details>
            @endcan

        @elseif($statut === $pv::STATUT_ESCALADE)
            @can('evaluations.publish')
                <details class="relative">
                    <summary class="{{ $bouton }} cursor-pointer list-none bg-amber-600 text-white hover:bg-amber-700">
                        Arbitrer l’escalade
                    </summary>
                    <form method="POST" action="{{ route('admin.evaluations.gouvernance.resoudre', $validation) }}"
                          class="absolute right-0 z-20 mt-1 w-72 rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
                        @csrf
                        <label class="block text-[11px] font-bold text-slate-600">
                            Décision motivée
                            <textarea name="resolution" rows="2" required minlength="5" maxlength="2000"
                                      class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1 text-xs"></textarea>
                        </label>
                        <button type="submit" class="mt-2 w-full rounded-lg bg-amber-600 px-2 py-1.5 text-xs font-bold text-white hover:bg-amber-700">
                            Enregistrer l’arbitrage
                        </button>
                    </form>
                </details>
            @endcan

        @elseif($statut === $pv::STATUT_PUBLIE)
            <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-700">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                Version officielle
            </span>
            @can('evaluations.publish')
                <details class="relative">
                    <summary class="{{ $bouton }} cursor-pointer list-none bg-slate-100 text-slate-700 hover:bg-slate-200">
                        Nouvelle version
                    </summary>
                    <form method="POST" action="{{ route('admin.evaluations.gouvernance.nouvelle-version', $examen) }}"
                          class="absolute right-0 z-20 mt-1 w-72 rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
                        @csrf
                        <label class="block text-[11px] font-bold text-slate-600">
                            Motif de révision
                            <textarea name="motif_modification" rows="2" required minlength="5" maxlength="2000"
                                      class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1 text-xs"></textarea>
                        </label>
                        <button type="submit" class="mt-2 w-full rounded-lg bg-slate-700 px-2 py-1.5 text-xs font-bold text-white hover:bg-slate-800">
                            Ouvrir la révision
                        </button>
                    </form>
                </details>
            @endcan
            <a href="{{ route('admin.evaluation.examens.convocations') }}"
               class="{{ $bouton }} bg-slate-100 text-slate-700 hover:bg-slate-200">
                Convocations &rarr;
            </a>
        @endif

        {{-- Rejet et escalade restent accessibles tant que la version est ouverte. --}}
        @if(in_array($statut, [$pv::STATUT_SOUMIS, $pv::STATUT_CERTIFIE, $pv::STATUT_APPROUVE], true))
            @can('evaluations.approve')
                <details class="relative">
                    <summary class="{{ $bouton }} cursor-pointer list-none bg-rose-50 text-rose-700 hover:bg-rose-100">
                        Rejeter
                    </summary>
                    <form method="POST" action="{{ route('admin.evaluations.gouvernance.refuser', $validation) }}"
                          class="absolute right-0 z-20 mt-1 w-72 rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
                        @csrf
                        <label class="block text-[11px] font-bold text-slate-600">
                            Motif du rejet
                            <textarea name="motif_rejet" rows="2" required minlength="5" maxlength="2000"
                                      class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1 text-xs"></textarea>
                        </label>
                        <button type="submit" class="mt-2 w-full rounded-lg bg-rose-600 px-2 py-1.5 text-xs font-bold text-white hover:bg-rose-700">
                            Confirmer le rejet
                        </button>
                    </form>
                </details>
            @endcan

            @can('evaluations.approve')
                <details class="relative">
                    <summary class="{{ $bouton }} cursor-pointer list-none bg-slate-100 text-slate-700 hover:bg-slate-200">
                        Escalader
                    </summary>
                    <form method="POST" action="{{ route('admin.evaluations.gouvernance.escalader', $validation) }}"
                          class="absolute right-0 z-20 mt-1 w-72 rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
                        @csrf
                        <label class="block text-[11px] font-bold text-slate-600">
                            Motif du désaccord
                            <textarea name="motif_escalade" rows="2" required minlength="5" maxlength="2000"
                                      class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1 text-xs"></textarea>
                        </label>
                        <button type="submit" class="mt-2 w-full rounded-lg bg-amber-600 px-2 py-1.5 text-xs font-bold text-white hover:bg-amber-700">
                            Escalader le désaccord
                        </button>
                    </form>
                </details>
            @endcan
        @endif
    </div>
</div>

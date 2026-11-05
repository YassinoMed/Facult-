<!DOCTYPE html>
<html lang="fr" class="bg-slate-100">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $doc['type_document'] === 'CONTRAT_PEDAGOGIQUE' ? 'Contrat Pédagogique' : ($doc['type_document'] === 'SYNTHESE_ACQUIS' ? 'Synthèse des Acquis' : 'Relevé Officiel des Acquis') }} — {{ $etudiant->nom }} {{ $etudiant->prenom }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background-color: #ffffff !important; padding: 0 !important; }
            .page-a4 { box-shadow: none !important; border: none !important; max-width: 100% !important; margin: 0 !important; padding: 15mm !important; }
        }
        .page-a4 {
            width: 100%;
            max-width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            background: #ffffff;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
        }
    </style>
</head>
<body class="text-slate-800 antialiased min-h-screen py-6 sm:py-10">

    <!-- Barre d'outils supérieure (non imprimable) -->
    <div class="no-print max-w-4xl mx-auto mb-6 px-4">
        <div class="flex flex-wrap items-center justify-between gap-4 p-4 rounded-2xl bg-white border border-slate-200/90 shadow-sm">
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.redoublement.show', $etudiant) }}"
                   class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 transition">
                    ← Retour au dossier étudiant
                </a>
                <span class="text-xs font-mono font-bold text-slate-500">
                    Réf : {{ $doc['reference'] }}
                </span>
            </div>

            <div class="flex items-center gap-2">
                <a href="{{ request()->fullUrlWithQuery(['format' => 'json']) }}"
                   target="_blank"
                   class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100 transition">
                    <svg class="h-4 w-4 text-slate-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5" />
                    </svg>
                    <span>Données JSON Scellées</span>
                </a>

                <button type="button"
                        onclick="window.print()"
                        class="inline-flex items-center gap-2 rounded-xl bg-primary-800 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-primary-900 transition">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.076-.64-2.13-1.19-3.14-.54-1.01-1.25-1.92-2.1-2.69m0 0l2.67-2.67m-2.67 2.67l2.67 2.67M17.28 13.829c.24-1.076.64-2.13 1.19-3.14.54-1.01 1.25-1.92 2.1-2.69m0 0l-2.67-2.67m2.67 2.67l-2.67 2.67M6.75 6.75h10.5a2.25 2.25 0 012.25 2.25v7.5a2.25 2.25 0 01-2.25 2.25H6.75a2.25 2.25 0 01-2.25-2.25v-7.5a2.25 2.25 0 012.25-2.25z" />
                    </svg>
                    <span>🖨️ Imprimer le document</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Feuille officielle format A4 -->
    <div class="page-a4 p-8 sm:p-12 rounded-3xl border border-slate-200 relative overflow-hidden flex flex-col justify-between">

        <div>
            <!-- En-tête institutionnel républicain solennel -->
            <div class="border-b-2 border-slate-900 pb-5 mb-6">
                <div class="grid grid-cols-3 items-start gap-4">
                    <div class="text-left text-[11px] leading-tight text-slate-700">
                        <div class="font-bold uppercase tracking-wider text-slate-900">République Tunisienne</div>
                        <div>Ministère de l'Enseignement Supérieur</div>
                        <div>et de la Recherche Scientifique</div>
                        <div class="font-semibold mt-1 text-slate-900">{{ $doc['etudiant']['faculte'] ?? 'Université de Tunis' }}</div>
                    </div>

                    <div class="flex flex-col items-center justify-center text-center">
                        <div class="h-14 w-14 rounded-2xl bg-slate-900 text-white font-black flex items-center justify-center text-xl shadow-xs">
                            UR
                        </div>
                        <div class="text-[9px] font-mono uppercase tracking-widest text-slate-500 mt-1.5">UniRegistre LMD</div>
                    </div>

                    <div class="text-right text-[11px] leading-tight text-slate-700">
                        <div class="font-mono font-bold text-slate-900">Réf : {{ $doc['reference'] }}</div>
                        <div class="text-[10px] text-slate-500 font-mono mt-0.5">Certifié le {{ \Carbon\Carbon::parse($doc['date_generation'])->format('d/m/Y à H:i') }}</div>
                        <div class="inline-flex items-center gap-1 mt-1.5 px-2 py-0.5 rounded bg-emerald-50 text-emerald-800 border border-emerald-200 text-[10px] font-bold">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            CERTIFICATION OFFICIELLE
                        </div>
                    </div>
                </div>

                <!-- Titre officiel solennel du document -->
                <div class="mt-6 text-center">
                    <h1 class="text-lg sm:text-xl font-black uppercase tracking-wide text-slate-900">
                        @if($doc['type_document'] === 'CONTRAT_PEDAGOGIQUE')
                            Contrat Pédagogique d'Inscription Sélective
                        @elseif($doc['type_document'] === 'SYNTHESE_ACQUIS')
                            Synthèse Réglementaire des Acquis &amp; Progression
                        @else
                            Relevé Officiel des Acquis &amp; Décision de Redoublement
                        @endif
                    </h1>
                    <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider mt-1">
                        {{ $doc['mention_juridique'] }}
                    </p>
                    <p class="text-[10px] text-slate-400 font-mono mt-0.5">
                        Application stricte du Décret n° 2022-631 et de l'Arrêté ministériel du 7 juillet 2023
                    </p>
                </div>
            </div>

            <!-- Fiche d'identification de l'étudiant -->
            <div class="rounded-2xl border border-slate-200 bg-slate-50/60 p-4 mb-6">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs">
                    <div>
                        <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-500">Étudiant(e)</span>
                        <span class="font-bold text-slate-900 text-sm">{{ $doc['etudiant']['nom'] }} {{ $doc['etudiant']['prenom'] }}</span>
                    </div>
                    <div>
                        <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-500">Identifiants officiels</span>
                        <span class="font-mono font-bold text-slate-800">
                            CIN : {{ $doc['etudiant']['cin'] ?? '—' }}
                            @if(!empty($doc['etudiant']['matricule']))
                                • Matr : {{ $doc['etudiant']['matricule'] }}
                            @endif
                        </span>
                    </div>
                    <div>
                        <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-500">Programme / Parcours</span>
                        <span class="font-bold text-slate-800">{{ $doc['programme']['nom'] }}</span>
                        <span class="text-[10px] text-slate-500 font-mono block">Version {{ $doc['programme']['version'] }}</span>
                    </div>
                    <div>
                        <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-500">Année universitaire</span>
                        <span class="font-mono font-bold text-slate-800">{{ $doc['programme']['annee_universitaire'] ?? '2025-2026' }}</span>
                        <span class="text-[10px] font-bold text-emerald-700 block">Jury de délibération</span>
                    </div>
                </div>
            </div>

            <!-- Synthèse des Crédits ECTS & Délibération -->
            <div class="grid grid-cols-4 gap-3 mb-6">
                <div class="p-3 rounded-xl border border-emerald-200 bg-emerald-50/40 text-center">
                    <div class="text-[10px] font-bold uppercase text-emerald-800">Crédits Capitalisés</div>
                    <div class="text-xl font-black text-emerald-700 mt-0.5">{{ $doc['credits']['capitalises'] }} ECTS</div>
                    <div class="text-[9px] text-emerald-600 font-medium">Acquis à vie (Art. 8 &amp; 10)</div>
                </div>
                <div class="p-3 rounded-xl border border-amber-200 bg-amber-50/40 text-center">
                    <div class="text-[10px] font-bold uppercase text-amber-800">Crédits à Valider</div>
                    <div class="text-xl font-black text-amber-700 mt-0.5">{{ $doc['credits']['restants'] }} ECTS</div>
                    <div class="text-[9px] text-amber-600 font-medium">Matières à reprendre</div>
                </div>
                <div class="p-3 rounded-xl border border-slate-200 bg-slate-50 text-center">
                    <div class="text-[10px] font-bold uppercase text-slate-600">Total Niveau Requis</div>
                    <div class="text-xl font-black text-slate-800 mt-0.5">{{ $doc['credits']['total_requis'] }} ECTS</div>
                    <div class="text-[9px] text-slate-500">Diplomation LMD</div>
                </div>
                <div class="p-3 rounded-xl border border-blue-200 bg-blue-50/40 text-center">
                    <div class="text-[10px] font-bold uppercase text-blue-800">Décision Jury</div>
                    <div class="text-xs font-black uppercase text-blue-900 mt-1.5 leading-tight">
                        {{ $doc['deliberation']['decision'] ?? 'AUTORISÉ AU REDOUBLEMENT' }}
                    </div>
                    <div class="text-[9px] text-blue-700">PV officiel publié</div>
                </div>
            </div>

            <!-- Tableau officiel des Unités d'Enseignement et Éléments Constitutifs -->
            <div class="space-y-4 mb-6">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <h2 class="text-xs font-black uppercase tracking-wider text-slate-900">
                        Détail Analytique des Évaluations, Capitalisation &amp; Acquis
                    </h2>
                    <span class="text-[10px] text-slate-500 font-mono">Art. 8, 9, 10, 13 &amp; 14</span>
                </div>

                @foreach($doc['unites_detaillees'] as $ue)
                    <div class="rounded-xl border border-slate-200 overflow-hidden text-xs">
                        <div class="px-4 py-2.5 bg-slate-100/80 border-b border-slate-200 flex items-center justify-between">
                            <div>
                                <span class="font-mono font-bold text-slate-700 mr-1.5">{{ $ue['code'] }}</span>
                                <span class="font-bold text-slate-900">{{ $ue['nom'] }}</span>
                                <span class="text-[11px] text-slate-500 ml-1.5">({{ $ue['credits_potentiels'] }} ECTS)</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="font-mono font-bold text-slate-800">
                                    Moyenne : {{ $ue['moyenne'] !== null ? number_format($ue['moyenne'], 2) : '—' }}/20
                                </span>
                                @if($ue['credits_capitalises'] > 0)
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                        Capitalisée (+{{ $ue['credits_capitalises'] }} ECTS)
                                    </span>
                                @elseif($ue['statut'] === 'SEUIL_COMPETENCE_NON_ATTEINT')
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-800">
                                        Seuil éliminatoire (&lt; 6/20)
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800">
                                        À reprendre
                                    </span>
                                @endif
                            </div>
                        </div>

                        <table class="w-full text-left text-[11px]">
                            <thead class="bg-slate-50/70 text-slate-500 uppercase border-b border-slate-100 font-semibold">
                                <tr>
                                    <th class="px-4 py-2">Code</th>
                                    <th class="px-4 py-2">Élément Constitutif (ECUE)</th>
                                    <th class="px-4 py-2 text-center">Crédits</th>
                                    <th class="px-4 py-2 text-center">Note /20</th>
                                    <th class="px-4 py-2">Statut légal</th>
                                    <th class="px-4 py-2">Motif réglementaire</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($ue['ecues'] as $ecue)
                                    <tr class="{{ $ecue['reprise_requise'] ? 'bg-amber-50/20' : '' }}">
                                        <td class="px-4 py-2 font-mono text-slate-600">{{ $ecue['code'] }}</td>
                                        <td class="px-4 py-2 font-medium text-slate-900">{{ $ecue['nom'] }}</td>
                                        <td class="px-4 py-2 text-center font-mono font-semibold">{{ $ecue['credits'] }}</td>
                                        <td class="px-4 py-2 text-center font-mono font-bold">
                                            {{ $ecue['note_retenue'] !== null ? number_format($ecue['note_retenue'], 2) : '—' }}
                                        </td>
                                        <td class="px-4 py-2">
                                            @if(!$ecue['reprise_requise'])
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                                    Conservé à vie
                                                </span>
                                            @elseif($ecue['statut'] === 'SEUIL_NON_ATTEINT')
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-100 text-rose-800">
                                                    Seuil éliminatoire
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800">
                                                    À reprendre
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2 text-slate-500 text-[10px]">{{ $ecue['motif'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>

            <!-- Liste des éléments à reprendre pour la réinscription ciblée -->
            @if(!empty($doc['elements_a_reprendre']))
                <div class="rounded-2xl border border-amber-200 bg-amber-50/30 p-4 mb-6">
                    <div class="text-xs font-bold text-amber-900 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                        <span>📌</span>
                        <span>Matières faisant l'objet exclusif de la réinscription ciblée ({{ count($doc['elements_a_reprendre']) }} ECUEs)</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                        @foreach($doc['elements_a_reprendre'] as $el)
                            <div class="flex items-center justify-between p-2 rounded-lg bg-white border border-amber-200/80">
                                <div>
                                    <span class="font-mono font-bold text-amber-800 mr-1.5">{{ $el['code'] }}</span>
                                    <span class="font-medium text-slate-800">{{ $el['nom'] }}</span>
                                </div>
                                <span class="font-mono text-slate-500 font-bold text-[11px]">{{ $el['credits'] }} ECTS</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-2 text-[10px] text-amber-800">
                        Règle stricte : L'étudiant ne repasse aucune épreuve sur les éléments déjà validés et capitalisés.
                    </div>
                </div>
            @endif

            <!-- Références réglementaires officielles -->
            <div class="border-t border-slate-200 pt-3 text-[9px] text-slate-500 space-y-0.5">
                <div class="font-bold uppercase tracking-wider text-slate-700">Fondement Juridique &amp; Textes Applicables :</div>
                @foreach($doc['references_reglementaires'] as $ref)
                    <div>• {{ $ref }}</div>
                @endforeach
            </div>
        </div>

        <!-- Cartouche de signatures officielles et empreinte cryptographique SHA-256 -->
        <div class="border-t-2 border-slate-900 pt-6 mt-8">
            <div class="grid grid-cols-2 gap-8 mb-6">
                <div class="text-left text-xs">
                    <div class="font-bold text-slate-900 uppercase">Le Chef des Services de la Scolarité</div>
                    <div class="text-[10px] text-slate-500 mt-0.5">Visa de conformité pédagogique</div>
                    <div class="h-16 mt-2 border-b border-dashed border-slate-300"></div>
                    <div class="text-[10px] font-mono text-slate-400 mt-1">Signature &amp; Cachet officiel</div>
                </div>

                <div class="text-right text-xs">
                    <div class="font-bold text-slate-900 uppercase">Le Président du Jury / Le Doyen</div>
                    <div class="text-[10px] text-slate-500 mt-0.5">Décision souveraine du jury</div>
                    <div class="h-16 mt-2 border-b border-dashed border-slate-300"></div>
                    <div class="text-[10px] font-mono text-slate-400 mt-1">Signature &amp; Sceau de la Faculté</div>
                </div>
            </div>

            <!-- Sceau d'intégrité cryptographique SHA-256 et Sceau d'authenticité HMAC -->
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3.5 space-y-2 text-[10px]">
                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                    <div class="space-y-1 text-left flex-1">
                        <div class="font-bold uppercase tracking-wider text-slate-800 flex items-center gap-1.5">
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                            Contrôle de Sécurité : Empreinte SHA-256 &amp; Sceau Institutionnel HMAC
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mt-1">
                            <div class="bg-white p-2 rounded border border-slate-200">
                                <div class="text-[9px] uppercase font-bold text-slate-500">Empreinte d'intégrité (SHA-256)</div>
                                <div class="font-mono text-[9px] text-slate-900 break-all select-all font-semibold">
                                    {{ $doc['empreinte_sha256'] }}
                                </div>
                            </div>
                            <div class="bg-white p-2 rounded border border-slate-200">
                                <div class="text-[9px] uppercase font-bold text-slate-500">Sceau d'authenticité serveur (HMAC-SHA-256)</div>
                                <div class="font-mono text-[9px] text-indigo-900 break-all select-all font-semibold">
                                    {{ $doc['sceau_hmac'] ?? 'Sceau non calculé' }}
                                </div>
                            </div>
                        </div>
                        <div class="text-slate-500 text-[9px] mt-1">
                            * Note légale : L'empreinte SHA-256 certifie l'intégrité inaltérable du relevé. Le sceau HMAC authentifie l'émetteur serveur. La signature officielle juridiquement opposable est constituée par les visas et cachets des autorités académiques signataires ci-dessus.
                        </div>
                    </div>

                    <div class="flex-shrink-0 text-center flex flex-col items-center">
                        <div class="h-12 w-12 border-2 border-slate-900 rounded-lg p-1 flex flex-col items-center justify-center font-mono font-black text-xs text-slate-900 bg-white shadow-xs">
                            <span>QR</span>
                            <span class="text-[7px] text-slate-500 font-normal">Vérif</span>
                        </div>
                        <span class="text-[8px] text-slate-500 font-mono mt-1">Contrôle en ligne</span>
                    </div>
                </div>
            </div>
        </div>

    </div>

</body>
</html>


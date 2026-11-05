<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Opération officielle bloquée — UniRegistre</title>
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }
        .card {
            max-width: 680px;
            width: 100%;
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
            border: 1px solid #ffe4e6;
            overflow: hidden;
        }
        .card-header {
            background: linear-gradient(135deg, #e11d48 0%, #be123c 100%);
            color: #ffffff;
            padding: 24px 28px;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .header-icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.22);
            color: #ffffff;
            padding: 3px 10px;
            border-radius: 9999px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .card-title {
            font-size: 18px;
            font-weight: 800;
            line-height: 1.3;
        }
        .card-body {
            padding: 28px;
        }
        .callout {
            background: #fff1f2;
            border: 1px solid #fecdd3;
            border-radius: 16px;
            padding: 16px;
            color: #9f1239;
            font-size: 13px;
            line-height: 1.6;
            margin-bottom: 20px;
        }
        .callout-title {
            font-weight: 700;
            font-size: 13px;
            color: #881337;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .section-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-bottom: 10px;
        }
        .reasons-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 16px 18px;
            margin-bottom: 18px;
        }
        .reason-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 13px;
            color: #334155;
            line-height: 1.5;
            margin-bottom: 10px;
        }
        .reason-item:last-child {
            margin-bottom: 0;
        }
        .bullet {
            width: 7px;
            height: 7px;
            background: #e11d48;
            border-radius: 50%;
            margin-top: 6px;
            flex-shrink: 0;
        }
        .raw-message {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 10px 14px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 11px;
            color: #64748b;
            line-height: 1.5;
            word-break: break-word;
            margin-bottom: 22px;
        }
        .actions-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            padding-top: 18px;
            border-top: 1px solid #f1f5f9;
        }
        .actions-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.15s ease;
            cursor: pointer;
        }
        .btn-outline {
            background: #ffffff;
            color: #475569;
            border: 1px solid #cbd5e1;
        }
        .btn-outline:hover {
            background: #f8fafc;
            color: #1e293b;
            border-color: #94a3b8;
        }
        .btn-amber {
            background: #f59e0b;
            color: #ffffff;
            border: 1px solid #d97706;
        }
        .btn-amber:hover {
            background: #d97706;
        }
        .btn-indigo {
            background: #4f46e5;
            color: #ffffff;
            border: 1px solid #4338ca;
        }
        .btn-indigo:hover {
            background: #4338ca;
        }
    </style>
</head>
<body>
    <div class="card">
        {{-- En-tête alerte --}}
        <div class="card-header">
            <div class="header-icon">
                <svg width="26" height="26" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                </svg>
            </div>
            <div>
                <span class="badge">Gouvernance des Examens</span>
                <h1 class="card-title">Opération officielle bloquée par les contrôles de cohérence</h1>
            </div>
        </div>

        {{-- Corps --}}
        <div class="card-body">
            {{-- Encadré explicatif --}}
            <div class="callout">
                <div class="callout-title">
                    <span>🛡️</span> Règle de verrouillage des documents officiels
                </div>
                <p>
                    Le système UniRegistre applique une politique de conformité stricte : <strong>aucun document officiel d'examen</strong> (fiche récapitulative, feuille d'émargement, liste des candidats) ne peut être généré ou imprimé tant qu'il subsiste des anomalies pédagogiques, de capacité ou une dérive de population.
                </p>
            </div>

            {{-- Liste des motifs --}}
            <div class="section-title">Motifs bloquants détectés sur cette épreuve / groupe :</div>
            <div class="reasons-box">
                @forelse($raisons as $raison)
                    @if(trim($raison) !== '')
                        <div class="reason-item">
                            <span class="bullet"></span>
                            <span>{{ trim($raison) }}.</span>
                        </div>
                    @endif
                @empty
                    <div class="reason-item">
                        <span class="bullet"></span>
                        <span>Contrôles de cohérence ou d'éligibilité non validés.</span>
                    </div>
                @endforelse
            </div>

            {{-- Message brut d'origine --}}
            <div class="raw-message">
                {{ $message }}
            </div>

            {{-- Actions correctives --}}
            <div class="actions-bar">
                <a href="{{ $urlRetour }}" class="btn btn-outline">
                    ← Retour
                </a>

                <div class="actions-right">
                    <a href="{{ $urlAnomalies }}" class="btn btn-amber">
                        🛠️ Centre des anomalies
                    </a>
                    <a href="{{ route('admin.evaluation.examens.groupes-virtuels.index') }}" class="btn btn-indigo">
                        📋 Tous les groupes virtuels
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

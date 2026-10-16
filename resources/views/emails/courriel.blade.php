<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name', 'UniRegistre') }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
                <tr>
                    <td style="background-color:#3730a3;padding:20px 28px;">
                        <p style="margin:0;color:#ffffff;font-size:16px;font-weight:bold;">{{ config('app.name', 'UniRegistre') }} — Service de la scolarité</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px;color:#0f172a;font-size:14px;line-height:1.6;">
                        @foreach ($lignes as $ligne)
                            <p style="margin:0 0 12px 0;">{{ $ligne }}</p>
                        @endforeach
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 28px;border-top:1px solid #e2e8f0;color:#64748b;font-size:12px;">
                        Message automatique : merci de ne pas y répondre directement. Contact : {{ config('inscriptions.contact_scolarite.email', '') }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>

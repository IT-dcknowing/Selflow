{{-- La facture envoyée au client (23/11/2026). Styles portés par les balises : Outlook et Gmail. --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f4f6f9;margin:0;padding:24px 0;font-family:Arial,Helvetica,sans-serif;">
    <tr><td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0;">
            <tr><td style="background:{{ $couleur }};padding:24px 32px;">
                <div style="font-size:20px;font-weight:bold;color:#ffffff;">{{ $nomEntreprise }}</div>
                <div style="margin-top:10px;font-size:15px;color:#ffffff;">Votre facture {{ $vente->numero_fne ?: $vente->numero_facture }}</div>
            </td></tr>
            <tr><td style="padding:28px 32px 10px;font-size:14px;color:#334155;line-height:1.7;">
                <p style="margin:0 0 14px;">Bonjour{{ $vente->client?->nom ? ' ' . $vente->client->nom : '' }},</p>
                <p style="margin:0 0 14px;">Merci pour votre confiance. Vous trouverez votre facture en pièce jointe.</p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 18px;background:#f8fafc;border-radius:10px;border-left:4px solid {{ $couleur }};">
                    <tr><td style="padding:14px 16px;font-size:13.5px;color:#1e293b;line-height:1.8;">
                        Facture : <strong>{{ $vente->numero_fne ?: $vente->numero_facture }}</strong><br>
                        Date : <strong>{{ $vente->date_vente?->format('d/m/Y') }}</strong><br>
                        Montant : <strong>{{ number_format((float) $vente->montant_ttc, 0, ',', ' ') }} F CFA</strong>
                        @if($vente->normalise)<br><span style="color:#047857;">Facture normalisée, certifiée par la DGI.</span>@endif
                    </td></tr>
                </table>
                <p style="margin:0 0 6px;">À très bientôt,</p>
                <p style="margin:0;"><strong>{{ $nomEntreprise }}</strong></p>
            </td></tr>
            <tr><td style="padding:18px 32px 24px;">
                <div style="border-top:1px solid #e2e8f0;padding-top:14px;font-size:11.5px;color:#94a3b8;">Facture émise avec Selflow · Propulsé par DC-KNOWING</div>
            </td></tr>
        </table>
    </td></tr>
</table>

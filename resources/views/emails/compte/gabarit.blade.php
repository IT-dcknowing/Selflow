{{--
    Gabarit commun des courriels de compte (réinitialisation, ouverture).
    Tableaux imbriqués et styles portés par les balises : voir la note de
    `emails/comptaflow/compte-ouvert` — Outlook rend avec le moteur de Word,
    Gmail retire les feuilles de style.
--}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
    style="background:#f4f6f9;margin:0;padding:24px 0;font-family:Arial,Helvetica,sans-serif;">
    <tr>
        <td align="center">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600"
                style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0;">
                <tr>
                    <td style="background:#002B5C;padding:26px 32px;">
                        <div style="font-size:20px;font-weight:bold;color:#ffffff;letter-spacing:.5px;">Selflow</div>
                        <div style="margin-top:14px;font-size:17px;color:#ffffff;line-height:1.4;">@yield('titre')</div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:30px 32px 8px;">
                        @yield('corps')
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px 32px 28px;">
                        <div style="border-top:1px solid #e2e8f0;padding-top:18px;">
                            <p style="margin:0 0 12px;font-size:12px;color:#94a3b8;line-height:1.7;">
                                <strong>Nous ne vous demanderons jamais votre mot de passe</strong>, ni par courriel,
                                ni par téléphone. Aucun mot de passe ne figure dans ce message.
                            </p>
                            <p style="margin:0;font-size:12px;color:#cbd5e1;">Selflow — DC-Knowing — {{ now()->year }}</p>
                        </div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

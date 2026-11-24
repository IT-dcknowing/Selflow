{{--
    La facture reçue, en PDF, pour le justificatif envoyé à Comptaflow
    (24/11/2026). Même principe que `facture-recue-impression` : une COPIE
    d'après le relevé du portail, jamais présentée comme l'original. Hors de
    `Vues/factures/` (dossier gelé des documents que Selflow émet).
--}}
<!DOCTYPE html>
<html lang="fr"><head><meta charset="UTF-8">
<style>
    @page { margin: 26px 30px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #0f172a; }
    .bandeau { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 7px 10px; border-radius: 6px; font-size: 9px; margin-bottom: 14px; }
    h1 { font-size: 16px; margin: 0 0 4px; color: #002B5C; }
    table { width: 100%; border-collapse: collapse; }
    .bloc td { vertical-align: top; padding: 0; }
    .lignes th { background: #002B5C; color: #fff; padding: 5px; text-align: left; font-size: 9px; }
    .lignes td { padding: 5px; border-bottom: 1px solid #e2e8f0; }
    .num { text-align: right; }
    .totaux td { padding: 3px 5px; }
</style></head>
<body>
    <div class="bandeau">Copie établie par Selflow d'après le relevé du portail FNE de la DGI. Ce n'est pas l'original du fournisseur : la pièce authentique se vérifie avec le code QR.</div>
    <table class="bloc"><tr>
        <td>
            <h1>Facture reçue {{ $facture->reference }}</h1>
            <div>Date : <strong>{{ $facture->date_facture?->format('d/m/Y') }}</strong></div>
            <div>Fournisseur : <strong>{{ $facture->emetteur_nom }}</strong> · NCC {{ $facture->emetteur_ncc }}</div>
            <div>Client : <strong>{{ $entreprise->nom }}</strong> · NCC {{ $entreprise->ncc }}</div>
        </td>
        <td style="text-align:right; width:120px;">@if($qr)<img src="{{ $qr }}" style="width:100px;height:100px;">@endif</td>
    </tr></table>
    <table class="lignes" style="margin-top:14px;">
        <thead><tr><th>Désignation</th><th class="num">Qté</th><th class="num">Prix unitaire</th><th class="num">Montant HT</th></tr></thead>
        <tbody>
        @foreach($facture->lignes as $l)
            <tr>
                <td>{{ $l->designation }}@if($l->reference_article) <span style="color:#64748b;">({{ $l->reference_article }})</span>@endif</td>
                <td class="num">{{ rtrim(rtrim(number_format((float) $l->quantite, 3, ',', ' '), '0'), ',') }} {{ $l->unite }}</td>
                <td class="num">{{ number_format((float) $l->prix_unitaire, 0, ',', ' ') }}</td>
                <td class="num">{{ number_format((float) $l->quantite * (float) $l->prix_unitaire * (1 - (float) $l->remise / 100), 0, ',', ' ') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <table class="totaux" style="width:45%; margin-left:55%; margin-top:10px;">
        <tr><td>Total HT</td><td class="num">{{ number_format((float) $facture->montant_ht, 0, ',', ' ') }} F</td></tr>
        <tr><td>TVA</td><td class="num">{{ number_format((float) $facture->montant_tva, 0, ',', ' ') }} F</td></tr>
        @if((float) $facture->autres_taxes)<tr><td>Autres taxes</td><td class="num">{{ number_format((float) $facture->autres_taxes, 0, ',', ' ') }} F</td></tr>@endif
        @if((float) $facture->timbre_fiscal)<tr><td>Timbre</td><td class="num">{{ number_format((float) $facture->timbre_fiscal, 0, ',', ' ') }} F</td></tr>@endif
        <tr><td><strong>Total TTC</strong></td><td class="num"><strong>{{ number_format((float) $facture->montant_ttc, 0, ',', ' ') }} F</strong></td></tr>
    </table>
</body></html>

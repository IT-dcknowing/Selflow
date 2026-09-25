<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ isset($bl) ? 'Bon de livraison' : ($vente->normalise ? 'Reçu normalisé' : 'Reçu') }} — {{ $vente->numero_facture }}</title>
    <style>
        @page {
            size: 80mm auto;
            margin: 0;
        }
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 12px;
            color: #000;
            width: 80mm;
            margin: 0;
            padding: 10px 5mm;
            box-sizing: border-box;
            background: #fff;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .header {
            margin-bottom: 12px;
        }
        .logo-box {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border: 1px solid #000;
            padding: 4px;
            margin-bottom: 8px;
            border-radius: 4px;
        }
        .logo-svg {
            width: 28px;
            height: 28px;
        }
        .rne-label {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            line-height: 1.2;
            text-align: left;
        }
        .divider {
            border-top: 1px dashed #000;
            margin: 8px 0;
        }
        .double-divider {
            border-top: 3px double #000;
            margin: 8px 0;
        }
        .bold {
            font-weight: bold;
        }
        .uppercase {
            text-transform: uppercase;
        }
        .grid-info {
            font-size: 10px;
            line-height: 1.3;
        }
        .item-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-top: 8px;
        }
        .item-table td {
            padding: 3px 0;
            vertical-align: top;
        }
        .total-box {
            font-size: 15px;
            font-weight: bold;
            margin: 12px 0;
            text-align: center;
            border: 1px solid #000;
            padding: 6px;
        }
        .qr-container {
            margin-top: 12px;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            gap: 4px;
        }
        .qr-code {
            width: 100px;
            height: 100px;
        }
        .footer-text {
            font-size: 9px;
            text-align: center;
            color: #444;
            margin-top: 10px;
        }
        .no-print-bar {
            background: #f3f4f6;
            padding: 10px;
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 1px solid #ddd;
        }
        .btn-print {
            background: #1e293b;
            color: #fff;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        @media print {
            .no-print-bar {
                display: none !important;
            }
            body {
                padding: 4px 2mm;
            }
        }
    </style>
</head>
<body>

<div class="no-print-bar">
    <button class="btn-print" onclick="telechargerRecu()">Télécharger</button>
    <button class="btn-print" style="background:#0f766e; margin-left:8px;" onclick="window.print()">Imprimer</button>
    <button class="btn-print" style="background:#4b5563; margin-left:8px;" onclick="window.history.back()">Retour</button>
</div>

@php
    // Un document ne porte les mentions de la DGI que s'il a réellement été
    // certifié. Auparavant l'en-tête affichait des armoiries dessinées, le
    // libellé « REÇU NORMALISÉ ÉLECTRONIQUE » et un numéro de repli
    // « Simulation-FNE-DGI » sur tout ticket, normalisé ou non : autant de
    // mentions officielles usurpées.
    $entrepriseTicket = $vente->pointDeVente->entreprise;
    $estNormalise = !isset($bl) && $vente->normalise && !empty($vente->numero_fne);
    $logoTicket = $entrepriseTicket->logo_path;
    if ($logoTicket && !\Illuminate\Support\Str::startsWith($logoTicket, ['http://', 'https://'])) {
        $logoTicket = \Illuminate\Support\Facades\Storage::disk('public')->url($logoTicket);
    }

    // Le visuel FNE — deuxieme des trois elements du sticker electronique, avec
    // le code QR et le format de la numerotation. Il ne s'affiche que sur une
    // piece reellement certifiee : l'apposer sur un brouillon en ferait une
    // mention officielle usurpee.
    //
    // Celui de l'entreprise d'abord, tel qu'il a ete depose dans les
    // parametres ; a defaut le visuel livre avec l'application.
    $visuelFne = null;
    if ($estNormalise) {
        $visuelFne = $entrepriseTicket->logo_fne_path;
        if ($visuelFne && !\Illuminate\Support\Str::startsWith($visuelFne, ['http://', 'https://'])) {
            $visuelFne = \Illuminate\Support\Facades\Storage::disk('public')->url($visuelFne);
        }
        if (!$visuelFne && is_file(public_path('logo-FNE.png'))) {
            $visuelFne = asset('logo-FNE.png');
        }
    }
@endphp

<div class="header">
    <div class="logo-box">
        @if($visuelFne)
            <img class="logo-svg" src="{{ $visuelFne }}" alt="Facture normalisée électronique" style="object-fit:contain;">
        @elseif($logoTicket)
            <img class="logo-svg" src="{{ $logoTicket }}" alt="{{ $entrepriseTicket->nom }}" style="object-fit:contain;">
        @endif
        <div class="rne-label">
            @if(isset($bl))
                BON DE LIVRAISON
            @elseif($estNormalise)
                REÇU NORMALISÉ<br>ÉLECTRONIQUE
            @else
                REÇU
            @endif
        </div>
    </div>
    <div class="text-center bold" style="font-size: 11px;">
        @if(isset($bl))
            BL No: {{ $bl->numero_bl }}
        @elseif($estNormalise)
            RECU FNE No: {{ $vente->numero_fne }}
        @else
            RECU No: {{ $vente->numero_facture }}
        @endif
    </div>
    @if(!isset($bl) && !$estNormalise)
        <div class="text-center" style="font-size:9px; margin-top:4px;">Document non normalisé auprès de la DGI</div>
    @endif
</div>

<div class="divider"></div>

<div class="grid-info uppercase">
    <span class="bold">{{ $vente->pointDeVente->entreprise->nom }}</span><br>
    TERMINAL : {{ str_pad($vente->point_de_vente_id, 7, '0', STR_PAD_LEFT) }}<br>
    ADRESSE : {{ $vente->pointDeVente->commune }}, {{ $vente->pointDeVente->ville }}<br>
    REGIME : {{ $vente->pointDeVente->entreprise->regime_imposition ?? 'RNI' }}<br>
    NCC : {{ $vente->pointDeVente->entreprise->ncc ?? '—' }}
</div>

<div class="divider"></div>

<div class="grid-info">
    DATE : {{ $vente->date_vente->format('d/m/Y H:i:s') }}
</div>

<div class="divider"></div>

<table class="item-table">
    <tbody>
        @foreach($vente->details as $d)
            @php
                $qty = $d->quantite;
                if (isset($bl)) {
                    $blDetail = $bl->details->firstWhere('produit_id', $d->produit_id);
                    $qty = $blDetail ? $blDetail->qte_livree : 0;
                }
            @endphp
            <tr>
                <td>
                    <span class="bold">{{ $d->produit ? $d->produit->nom : $d->libelle_virtuel }}</span>
                    <br>
                    @if(isset($bl))
                        <span style="font-size:10px;">Quantité : {{ $qty }} {{ $d->unite }}</span>
                    @else
                        <span style="font-size:10px;">{{ $qty }} x {{ number_format($d->prix_unitaire, 0, ',', ' ') }}</span>
                    @endif
                </td>
                <td class="text-right bold">
                    {{ isset($bl) ? '' : number_format($d->montant_ttc, 0, ',', ' ') }}
                </td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="divider"></div>

@if(!isset($bl))
<div class="total-box uppercase">
    MONTANT : {{ number_format($vente->montant_ttc, 0, ',', ' ') }} XOF
</div>
@endif

<div class="grid-info uppercase">
    @if(isset($bl))
        N° BL : {{ $bl->numero_bl }}<br>
        RÉF. COMMANDE : {{ $vente->numero_facture }}
    @else
        ID TRANSACTION : {{ $vente->numero_facture }}<br>
        MODE DE PAIEMENT : {{ $vente->mode_paiement }}
    @endif
</div>

<div class="divider"></div>

@if($estNormalise)
    {{-- Signature electronique de la DGI : code QR, visuel FNE, numerotation.
         Le code QR encode le jeton de verification renvoye par la plateforme
         (« token : code de verification a convertir en QR code »). --}}
    @php $qrTicket = \App\Modules\Admin\Services\QrCodeFneService::imageDeVerification($vente->qr_code_data, 150); @endphp
    <div class="qr-container">
        <span style="font-size: 8px; font-weight: bold;">VÉRIFICATION DGI</span>
        @if($qrTicket)
            <img src="{{ $qrTicket }}" alt="Code de vérification FNE" style="width:150px; height:150px; margin:4px 0;">
        @endif
        @if($vente->qr_code_data)
            <span style="font-size: 8px; word-break: break-all;" class="text-center">{{ $vente->qr_code_data }}</span>
        @endif
    </div>
@endif

@php
    $mentionsTicket = $vente->autres_mentions ?: $entrepriseTicket->facture_autres_mentions;
    $piedTicket     = $vente->pied_de_page ?: $entrepriseTicket->pied_de_page_facture;
@endphp
@if($mentionsTicket)
    <div class="footer-text">{{ $mentionsTicket }}</div>
@endif
<div class="footer-text">
    {{ isset($bl) ? 'Merci pour votre confiance !' : 'Merci pour votre visite !' }}
    @if($piedTicket)
        <br>{{ $piedTicket }}
    @endif
</div>

<script>
/**
 * Le recu, enregistre dans un fichier.
 *
 * Le ticket porte ses styles dans la page : le fichier rendu est donc autonome,
 * et s'ouvre tel quel. Tout ce qui ne s'imprime pas — la barre de boutons — est
 * ecarte, sans quoi le fichier porterait « Imprimer » et « Retour ».
 */
function telechargerRecu() {
    var corps = document.body.cloneNode(true);
    corps.querySelectorAll('.no-print-bar, script').forEach(function (noeud) { noeud.remove(); });

    var styles = Array.prototype.map.call(
        document.querySelectorAll('link[rel="stylesheet"], style'),
        function (noeud) { return noeud.outerHTML; }
    ).join('\n');

    var nom = @json(($vente->numero_fne ?: $vente->numero_facture) . '-recu');
    var page = '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        + '<title>' + nom + '</title>'
        + '<base href="' + document.baseURI + '">'
        + styles + '</head><body>' + corps.innerHTML + '</body></html>';

    var adresse = URL.createObjectURL(new Blob([page], { type: 'text/html;charset=utf-8' }));
    var lien = document.createElement('a');
    lien.href = adresse;
    lien.download = nom + '.html';
    document.body.appendChild(lien);
    lien.click();
    lien.remove();
    setTimeout(function () { URL.revokeObjectURL(adresse); }, 2000);
}

const urlParams = new URLSearchParams(window.location.search);
if (urlParams.get('print') === '1') {
    window.onload = function() {
        window.print();
    }
}
// Le telechargement demande depuis la liste des ventes : `?telecharger=1`
// ouvre le recu et enregistre le fichier, sans boite d'impression.
if (urlParams.get('telecharger') === '1') {
    window.onload = function() {
        telechargerRecu();
    }
}
</script>
</body>
</html>

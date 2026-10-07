@extends('admin::gabarits.application')
@section('titre', 'BAPA ' . $achat->numero_facture)
@section('topbar_titre', 'Achats — BAPA')

@section('styles')
<style>
    :root {
        --navy: #0D1B3E;
        --yellow: #F9CA24;
        --br: #E2E5EC;
        --tx: #1A1A2E;
        --mu: #6B7280;
        --white: #F5F6FA;
    }

    .controls-card {
        background: #fff;
        border: 0.5px solid var(--border);
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.02);
    }

    .invoice-container {
        display: flex;
        justify-content: center;
        padding: 10px 0;
    }

    .invoice {
        background: #fff;
        border: 0.5px solid var(--border);
        border-radius: 12px;
        overflow: hidden;
        width: 100%;
        max-width: 800px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.04);
        position: relative;
    }

    .print-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        border: 0.5px solid var(--border);
        background: #fff;
        color: var(--text);
        transition: all 0.15s;
        text-decoration: none;
    }
    
    .print-btn:hover {
        background: var(--bg3);
    }
    
    .print-btn.main {
        background: var(--primary);
        color: #fff;
        border-color: var(--primary);
    }
    
    .print-btn.main:hover {
        background: var(--primary-d);
    }

    .table-m4 {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
        margin-bottom: 20px;
        border: 1px solid #000;
    }
    
    .table-m4 th, .table-m4 td {
        border: 1px solid #000;
        padding: 10px;
    }

    @media print {
        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        .sidebar, header, .topbar, .no-print, .banner-alert, .user-dropdown-menu, .sidebar-logo, .sidebar-pdv {
            display: none !important;
        }
        body, .main-wrap, .main-content, main {
            margin: 0 !important;
            padding: 0 !important;
            background: #fff !important;
            width: 100% !important;
            max-width: 100% !important;
            position: static !important;
        }
        .invoice-container {
            padding: 0 !important;
        }
        .invoice {
            border: none !important;
            box-shadow: none !important;
            max-width: 100% !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            border-radius: 0 !important;
        }
    }
</style>
@endsection

@section('contenu')
<div class="controls-card no-print">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; gap:10px;">
            <a href="{{ route('admin.achats.factures') }}" class="print-btn">
                <i class="fas fa-arrow-left"></i> Retour
            </a>
            <button onclick="window.print()" class="print-btn main">
                <i class="fas fa-print"></i> Imprimer le BAPA
            </button>
        </div>
        <div style="font-size:12px; color:var(--text-3);">
            <i class="fas fa-info-circle"></i> Généré car le fournisseur ne possède pas de compte contribuable (sans NCC).
        </div>
    </div>
</div>

<div class="invoice-container">
    <div class="invoice" style="padding:40px;">
        
        {{-- En-tête BAPA --}}
        @php
            // Logo de l'entreprise : il figure sur les factures de vente et
            // d'achat mais manquait au bordereau, seule piece a en etre privee.
            $entrepriseBapa = $achat->pointDeVente->entreprise;
            $logoBapa = $entrepriseBapa->logo_path;
            if ($logoBapa && !str_starts_with($logoBapa, 'http://') && !str_starts_with($logoBapa, 'https://')) {
                $logoBapa = \App\Modules\Admin\Services\FichierPublic::url($logoBapa);
            }
        @endphp
        <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #000; padding-bottom:20px; margin-bottom:25px;">
            <div style="display:flex; align-items:flex-start; gap:14px;">
                @if($logoBapa)
                    <img src="{{ $logoBapa }}" alt="{{ $entrepriseBapa->nom }}"
                         style="max-height:56px; max-width:140px; object-fit:contain; flex-shrink:0;">
                @endif
            <div>
                <h1 style="font-size:20px; font-weight:800; text-transform:uppercase; margin:0 0 8px 0; color:var(--navy);">
                    {{ $achat->pointDeVente->entreprise->nom }}
                </h1>
                <div style="font-size:11px; line-height:1.5; color:#333;">
                    <strong>NCC :</strong> {{ $achat->pointDeVente->entreprise->ncc ?: '—' }}<br>
                    {{-- Le « compte contribuable » et le NCC designent le meme numero :
                         seul le NCC est conserve, pour eviter deux valeurs divergentes. --}}
                    <strong>Compte contribuable :</strong> {{ $achat->pointDeVente->entreprise->ncc ?: '—' }}<br>
                    <strong>RCCM :</strong> {{ $achat->pointDeVente->entreprise->rccm ?: '—' }}<br>
                    <strong>Régime :</strong> {{ $achat->pointDeVente->entreprise->regime_imposition ?: '—' }}<br>
                    <strong>Centre des impôts :</strong> {{ $achat->pointDeVente->entreprise->centre_impots ?: '—' }}<br>
                    <strong>Téléphone :</strong> {{ $achat->pointDeVente->entreprise->telephone ?: '—' }}<br>
                    <strong>Point de vente :</strong> {{ $achat->pointDeVente->nom }}<br>
                    <strong>Adresse :</strong> {{ $achat->pointDeVente->ville }}, {{ $achat->pointDeVente->commune }}
                </div>
            </div>
            </div>
            
            <div style="text-align:right; display:flex; flex-direction:column; align-items:flex-end;">
                <div style="background:#000; color:#fff; padding:6px 16px; font-size:14px; font-weight:800; text-transform:uppercase; border-radius:4px; display:inline-block; margin-bottom:10px;">
                    Bordereau BAPA
                </div>
                <div style="font-size:12px;">
                    <strong>Numéro :</strong> {{ $achat->numero_facture }}<br>
                    <strong>Date :</strong> {{ $achat->date_achat->format('d/m/Y') }}<br>
                    <strong>Mode de paiement :</strong> {{ $achat->mode_paiement ?: '—' }}
                    
                    {{-- Affichage FNE/BAPA DGI officiel --}}
                    @if($achat->normalise)
                        {{-- Signature electronique de la DGI : code QR, visuel FNE,
                             numerotation. Le code QR n'est que l'encodage du jeton
                             de verification renvoye par la plateforme. --}}
                        @php $qrBapa = \App\Modules\Admin\Services\QrCodeFneService::imageDeVerification($achat->qr_code_data, 110); @endphp
                        <div style="margin-top:15px; display:flex; gap: 10px; align-items: center; justify-content:flex-end;">
                            @if($qrBapa)
                                <img src="{{ $qrBapa }}" alt="Code de vérification FNE" style="width:110px; height:110px;">
                            @endif
                            <div style="width:80px; height:80px; display:flex; align-items:center; justify-content:center;">
                                <img src="/dgi-stamp.png" style="max-height:100%; max-width:100%; object-fit:contain;" alt="Visuel FNE">
                            </div>
                        </div>
                        <div style="font-size:9px; color:#000; text-align:right; margin-top:8px; font-family:monospace; line-height:1.4;">
                            N° BAPA/FNE : <strong>{{ $achat->numero_fne }}</strong>
                            @if($achat->qr_code_data)
                                <br>Vérification : <strong style="word-break:break-all;">{{ $achat->qr_code_data }}</strong>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div style="text-align:center; margin-bottom:30px;">
            <h2 style="font-size:16px; font-weight:800; text-transform:uppercase; border:2px solid #000; display:inline-block; padding:8px 24px; background:#f8fafc;">
                BORDEREAU D'ACHAT ÉLECTRONIQUE (BAPA)
            </h2>
            <p style="font-size:11px; color:#555; margin-top:6px; font-style:italic;">
                Déclaration d'achat auprès d'un tiers non immatriculé fiscalement (sans NCC)
            </p>
        </div>

        {{-- Infos Vendeur --}}
        <div style="border:1px solid #000; padding:15px; border-radius:8px; margin-bottom:25px; background:#fbfbfb;">
            <h3 style="font-size:13px; font-weight:800; margin:0 0 10px 0; text-transform:uppercase; border-bottom:1px solid #ddd; padding-bottom:6px;">
                Identité du vendeur / Prestataire (sans NCC)
            </h3>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; font-size:12px;">
                <div>
                    <strong>Nom / Raison Sociale :</strong> {{ $achat->fournisseur->nom }}<br>
                    <strong>Téléphone :</strong> {{ $achat->fournisseur->telephone ?: '—' }}<br>
                    <strong>E-mail :</strong> {{ $achat->fournisseur->email ?: '—' }}
                </div>
                <div>
                    <strong>Adresse / Commune :</strong> {{ $achat->fournisseur->adresse ?? '—' }}<br>
                    <strong>Registre de Commerce (RCCM) :</strong> {{ $achat->fournisseur->rccm ?? 'Non immatriculé' }}
                </div>
            </div>
        </div>

        {{-- Détail des produits --}}
        <table class="table-m4">
            <thead>
                <tr style="background:#f1f5f9;">
                    <th>Désignation article</th>
                    <th style="text-align:center; width:80px;">Quantité</th>
                    <th style="text-align:center; width:60px;">Unité</th>
                    <th style="text-align:right; width:120px;">Prix unitaire</th>
                    <th style="text-align:right; width:80px;">Remise (%)</th>
                    {{-- Pas de colonne de taxes : le payload d'un bordereau ne
                         transmet ni `taxes` ni `customTaxes`. La DGI n'en
                         retient donc aucune, et la colonne annoncait des
                         montants qui ne figurent pas sur la piece certifiee. --}}
                    <th style="text-align:right; width:130px;">Montant HT</th>
                </tr>
            </thead>
            <tbody>
                @foreach($achat->details as $d)
                    <tr>
                        <td style="font-weight:600;">{{ $d->produit ? $d->produit->nom : $d->libelle_virtuel }}</td>
                        <td style="text-align:center;">{{ $d->quantite }}</td>
                        <td style="text-align:center;">{{ $d->unite }}</td>
                        <td style="text-align:right;">{{ number_format($d->prix_unitaire, 0, ',', ' ') }} F</td>
                        <td style="text-align:right;">{{ rtrim(rtrim(number_format((float) ($d->remise_taux ?? 0), 2, ',', ' '), '0'), ',') }} %</td>
                        <td style="text-align:right; font-weight:700;">{{ number_format($d->montant_ttc, 0, ',', ' ') }} F</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Totaux --}}
        <div style="display:flex; justify-content:flex-end; margin-bottom:40px;">
            <table style="width:280px; border-collapse:collapse; font-size:13px;">
                <tr style="border-bottom:1px solid #000;">
                    <td style="padding:6px 0; font-weight:600;">Total HT</td>
                    <td style="padding:6px 0; text-align:right; font-weight:700;">
                        {{-- Le brut est bien celui d'avant remise : afficher ici le
                             montant deja net, suivi d'une ligne « Remise », laissait
                             croire a une double deduction. --}}
                        {{ number_format($achat->montant_ttc + ($achat->remise ?? 0), 0, ',', ' ') }} F
                    </td>
                </tr>
                @if(($achat->remise ?? 0) > 0)
                <tr style="border-bottom:1px solid #000; color:#dc2626;">
                    <td style="padding:6px 0; font-weight:600;">Remise ({{ rtrim(rtrim(number_format((float) $achat->remise_taux, 2, ',', ' '), '0'), ',') }} %)</td>
                    <td style="padding:6px 0; text-align:right; font-weight:700;">-{{ number_format($achat->remise, 0, ',', ' ') }} F</td>
                </tr>
                @endif
                <tr style="border-bottom:1px solid #000;">
                    <td style="padding:6px 0; font-weight:600;">TVA (Exonéré art. BAPA)</td>
                    <td style="padding:6px 0; text-align:right; font-weight:700;">0 F</td>
                </tr>
                {{-- Les taxes personnalisees ne figurent pas sur un bordereau :
                     le payload de la DGI n'en prevoit aucune pour ce type de
                     piece — ni `taxes` ni `customTaxes`, ni sur la ligne ni a
                     la racine. Les afficher ici et les ajouter au net creerait
                     un ecart avec le bordereau certifie. --}}
                @php
                    $timbreBapa = \App\Modules\Admin\Services\TimbreQuittanceService::pourAchat($achat);
                @endphp
                @if($timbreBapa > 0)
                <tr style="border-bottom:1px solid #000;">
                    <td style="padding:6px 0; font-weight:600;">
                        Timbre de quittance
                        @if($achat->fne_timbre_fiscal === null)
                            <span style="font-weight:400; font-size:10px;">(barème, avant certification)</span>
                        @endif
                    </td>
                    <td style="padding:6px 0; text-align:right; font-weight:700;">{{ number_format($timbreBapa, 0, ',', ' ') }} F</td>
                </tr>
                @endif
                <tr style="background:#f1f5f9; font-weight:800; font-size:15px; border:1px solid #000;">
                    <td style="padding:8px 10px;">Montant Net à Payer</td>
                    <td style="padding:8px 10px; text-align:right; color:var(--navy);">
                        {{ number_format($achat->montant_ttc + $timbreBapa, 0, ',', ' ') }} FCFA
                    </td>
                </tr>
            </table>
        </div>

        {{-- Signatures --}}
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:40px; margin-top:40px; font-size:12px;">
            <div style="text-align:center; border-top:1px solid #000; padding-top:10px;">
                <strong>Signature du Vendeur / Prestataire</strong>
                <div style="height:80px;"></div>
                <span style="font-size:11px; color:#555;">{{ $achat->fournisseur->nom }}</span>
            </div>
            <div style="text-align:center; border-top:1px solid #000; padding-top:10px;">
                <strong>Signature de l'Acheteur Déclarant</strong>
                <div style="height:80px;"></div>
                <span style="font-size:11px; color:#555;">Pour {{ $achat->pointDeVente->entreprise->nom }}</span>
            </div>
        </div>

        @php
            $entrepriseBapa = $achat->pointDeVente->entreprise;
            $mentionsBapa   = $achat->autres_mentions ?: $entrepriseBapa->facture_autres_mentions;
            $piedBapa       = $achat->pied_de_page ?: $entrepriseBapa->pied_de_page_facture;
        @endphp
        @if($achat->est_rne && $achat->numero_rne)
            <div style="margin-top:24px; font-size:11px; font-weight:700;">
                Bordereau rattaché au reçu normalisé n° {{ $achat->numero_rne }}
            </div>
        @endif
        @if($mentionsBapa)
            <div style="margin-top:12px; font-size:11px; color:#444; line-height:1.6;">{{ $mentionsBapa }}</div>
        @endif
        @if($piedBapa)
            <div style="margin-top:16px; border-top:1px solid #000; padding-top:10px; font-size:10px; color:#444; line-height:1.6; text-align:center;">
                {{ $piedBapa }}
            </div>
        @endif

    </div>
</div>
@endsection

@section('scripts')
@endsection

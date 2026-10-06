<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BL {{ $bl->numero_bl }}</title>
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
    <style>
        @import url('{{ asset('vendor/inter/inter.css') }}');

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f5f6fa;
            color: #1a1a2e;
        }

        .no-print {
            background: #fff;
            border-bottom: 1px solid #e2e5ec;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .no-print .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            border: none;
        }

        .btn-primary {
            background: #0D1B3E;
            color: #fff;
        }

        .btn-outline {
            background: #fff;
            color: #0D1B3E;
            border: 1px solid #e2e5ec;
        }

        .page-wrap {
            max-width: 780px;
            margin: 30px auto;
            padding: 0 16px 50px;
        }

        .invoice {
            background: #fff;
            border: 0.5px solid #e2e5ec;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, .05);
        }

        /* En-tête navy */
        .invoice-header {
            background: #0D1B3E;
            color: #fff;
            padding: 28px 32px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .invoice-header .company {}

        .invoice-header .company .name {
            font-size: 16px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .invoice-header .company .pdv {
            font-size: 11px;
            color: rgba(255, 255, 255, .6);
            margin-bottom: 10px;
        }

        .invoice-header .company .info {
            font-size: 10.5px;
            color: rgba(255, 255, 255, .7);
            line-height: 1.8;
        }

        .invoice-header .doc-title {
            text-align: right;
        }

        .invoice-header .doc-title .title {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -.3px;
        }

        .invoice-header .doc-title .num {
            font-size: 13px;
            color: rgba(255, 255, 255, .7);
            margin-top: 4px;
            font-weight: 600;
        }

        .invoice-header .doc-title .date {
            font-size: 11px;
            color: rgba(255, 255, 255, .55);
            margin-top: 3px;
        }

        /* Corps */
        .invoice-body {
            padding: 28px 32px;
        }

        /* Bloc client + refs */
        .meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 24px;
        }

        .meta-block {
            background: #f8f9fc;
            border: 0.5px solid #e2e5ec;
            border-radius: 8px;
            padding: 14px 16px;
        }

        .meta-block .meta-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .07em;
            font-weight: 700;
            color: #0D1B3E;
            margin-bottom: 8px;
        }

        .meta-block .meta-value {
            font-size: 13px;
            font-weight: 700;
            color: #1a1a2e;
        }

        .meta-block .meta-sub {
            font-size: 11px;
            color: #6b7280;
            margin-top: 4px;
            line-height: 1.7;
        }

        /* Badge partiel */
        .badge-partiel {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            background: #fffbeb;
            color: #b45309;
            border: 1px solid #fde68a;
            margin-top: 6px;
        }

        /* Alerte sans prix */
        .no-price-banner {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 10px 16px;
            margin-bottom: 20px;
            font-size: 12px;
            color: #1d4ed8;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }

        /* Tableau */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        thead tr {
            border-bottom: 2px solid #0D1B3E;
        }

        thead th {
            padding: 9px 10px;
            text-align: left;
            font-weight: 700;
            color: #0D1B3E;
        }

        thead th.r {
            text-align: right;
        }

        thead th.c {
            text-align: center;
        }

        tbody tr {
            border-bottom: 0.5px solid #e2e5ec;
        }

        tbody td {
            padding: 10px 10px;
            color: #1a1a2e;
            vertical-align: top;
        }

        tbody td.r {
            text-align: right;
            font-weight: 700;
        }

        tbody td.c {
            text-align: center;
        }

        .reliquat-warn {
            color: #d97706;
            font-weight: 700;
        }

        .reliquat-ok {
            color: #059669;
            font-weight: 700;
        }

        /* Pied */
        .invoice-footer {
            padding: 20px 32px;
            border-top: 0.5px solid #e2e5ec;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .signature-box {
            text-align: center;
            font-size: 10px;
            color: #6b7280;
        }

        .signature-box .box {
            width: 110px;
            height: 50px;
            border: 0.5px dashed #d1d5db;
            border-radius: 5px;
            margin: 6px auto 0;
        }

        .generated-by {
            font-size: 10px;
            color: #9ca3af;
        }

        @media print {
            .no-print {
                display: none !important;
            }

            body {
                background: #fff;
            }

            .page-wrap {
                margin: 0;
                padding: 0;
                max-width: 100%;
            }

            .invoice {
                border: none;
                box-shadow: none;
                border-radius: 0;
            }
        }
    </style>
</head>

<body>

    {{-- Barre de contrôle (non imprimée) --}}
    <div class="no-print">
        <div style="display:flex; align-items:center; gap:12px;">
            @php $isCaissier = request()->routeIs('caissier.*'); @endphp
            <a href="{{ $isCaissier ? route('caissier.ventes.factures', ['etape' => 'Bon de commande']) : route('admin.ventes.factures', ['etape' => 'Bon de commande']) }}"
                class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Retour
            </a>
            <span style="font-size:13px; color:#6b7280;">Bon de Livraison <strong>{{ $bl->numero_bl }}</strong></span>
        </div>
        <div style="display:flex; gap:10px;">
            <button onclick="window.print()" class="btn btn-outline">
                <i class="fas fa-print"></i> Imprimer
            </button>

            @if($bl->statut !== 'livre' && $bl->statut !== 'facture')
                <form method="POST"
                    action="{{ $isCaissier ? route('caissier.ventes.livraison.livrer', $bl) : route('admin.ventes.livraison.livrer', $bl) }}"
                    style="display:inline; margin:0;">
                    @csrf
                    <button type="submit" class="btn" style="background:#0369a1; color:#fff;">
                        <i class="fas fa-check"></i> Marquer Livré
                    </button>
                </form>
            @endif

            @if($bl->statut !== 'facture')
                <button type="button" onclick="document.getElementById('modal-facturer').style.display='flex'" class="btn"
                    style="background:#047857; color:#fff;">
                    <i class="fas fa-file-invoice-dollar"></i> → Facturer
                </button>
            @endif
        </div>
    </div>

    <div class="page-wrap">
        <div class="invoice">

            {{-- En-tête --}}
            <div class="invoice-header">
                <div class="company">
                    <div class="name">{{ $entreprise->nom }}</div>
                    <div class="pdv">{{ $bl->pointDeVente->nom }}</div>
                    <div class="info">
                        @if($entreprise->adresse) {{ $entreprise->adresse }}<br> @endif
                        @if($entreprise->telephone) Tél : {{ $entreprise->telephone }}<br> @endif
                        @if($entreprise->email) {{ $entreprise->email }}<br> @endif
                        @if($entreprise->rccm) RCCM : {{ $entreprise->rccm }}<br> @endif
                        @if($entreprise->ncc) NCC : {{ $entreprise->ncc }} @endif
                    </div>
                </div>
                <div class="doc-title">
                    <div class="title">BON DE LIVRAISON</div>
                    <div class="num">{{ $bl->numero_bl }}</div>
                    <div class="date">{{ \Carbon\Carbon::parse($bl->date_livraison)->format('d/m/Y') }}</div>
                    @if($bl->livraison_partielle)
                        <div class="badge-partiel" style="margin-top:8px; justify-content:flex-end;">
                            <i class="fas fa-triangle-exclamation"></i> Livraison partielle
                        </div>
                    @endif
                </div>
            </div>

            {{-- Corps --}}
            <div class="invoice-body">

                {{-- Références --}}
                <div class="meta-grid">
                    <div class="meta-block">
                        <div class="meta-label">Client</div>
                        <div class="meta-value">{{ $bl->client?->nom ?? '— Client de passage —' }}</div>
                        @if($bl->client?->adresse)
                            <div class="meta-sub">{{ $bl->client->adresse }}</div>
                        @endif
                        @if($bl->client?->telephone)
                            <div class="meta-sub">Tél : {{ $bl->client->telephone }}</div>
                        @endif
                    </div>
                    <div class="meta-block">
                        <div class="meta-label">Références associées</div>
                        <div style="font-size:12px; line-height:2;">
                            <span style="color:#6b7280;">Bon de Commande : </span>
                            <strong>{{ $bl->bonDeCommande->numero_facture }}</strong><br>
                            @if($bl->facture)
                                <span style="color:#6b7280;">Facture associée : </span>
                                <strong style="color:#047857;">{{ $bl->facture->numero_facture }}</strong><br>
                            @endif
                            <span style="color:#6b7280;">Statut BL : </span>
                            <strong>{{ $bl->statut_label }}</strong>
                        </div>
                        @if($bl->notes)
                            <div class="meta-sub" style="margin-top:8px; border-top:0.5px solid #e2e5ec; padding-top:6px;">
                                <i class="fas fa-note-sticky"></i> {{ $bl->notes }}
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Bannière : pas de prix --}}
                <div class="no-price-banner">
                    <i class="fas fa-info-circle"></i>
                    Ce document est un justificatif de livraison uniquement. Les prix ne figurent pas sur ce document.
                </div>

                {{-- Tableau des articles --}}
                <table>
                    <thead>
                        <tr>
                            <th style="width:35%;">Article</th>
                            <th class="c" style="width:10%;">Unité</th>
                            <th class="c" style="width:18%;">Qté commandée</th>
                            <th class="c" style="width:18%;">Qté livrée</th>
                            <th class="c" style="width:19%;">Reliquat</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($bl->details as $detail)
                            @php $reliquat = $detail->reliquat; @endphp
                            <tr>
                                <td>
                                    <div style="font-weight:600;">{{ $detail->libelle }}</div>
                                </td>
                                <td class="c" style="color:#6b7280;">{{ $detail->unite ?? '—' }}</td>
                                <td class="c">{{ $detail->qte_commandee }}</td>
                                <td class="c r">{{ $detail->qte_livree }}</td>
                                <td class="c {{ $reliquat > 0 ? 'reliquat-warn' : 'reliquat-ok' }}">
                                    {{ $reliquat > 0 ? $reliquat : '✓' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Pied : signatures --}}
            <div class="invoice-footer">
                <div class="generated-by">
                    Généré automatiquement par <strong>Selflow</strong>
                </div>
                <div style="display:flex; gap:24px;">
                    <div class="signature-box">
                        Signature Client (Décharge)
                        <div class="box"></div>
                    </div>
                    <div class="signature-box">
                        Livreur / Société
                        <div class="box"></div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    {{-- Modal choix facturation & règlement --}}
    <div id="modal-facturer"
        style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9999; align-items:center; justify-content:center; overflow-y:auto; padding:20px;">
        <div
            style="background:#fff; border-radius:14px; max-width:480px; width:100%; padding:28px; box-shadow:0 20px 60px rgba(0,0,0,.2); margin:auto;">
            <h3 style="font-size:18px; font-weight:800; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-file-invoice-dollar" style="color:#047857;"></i> Valider & Facturer
            </h3>
            <p style="font-size:13px; color:#6b7280; margin-bottom:20px;">Veuillez configurer les options de facturation
                et de règlement :</p>

            <form method="POST"
                action="{{ $isCaissier ? route('caissier.ventes.livraison.facturer', $bl) : route('admin.ventes.livraison.facturer', $bl) }}"
                id="form-convert-bl-facture" style="margin:0;">
                @csrf

                {{-- 1. Base de facturation --}}
                <div style="margin-bottom:18px;">
                    <label
                        style="font-weight:700; font-size:12px; text-transform:uppercase; color:#475569; display:block; margin-bottom:6px;">Base
                        de facturation</label>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                        <label
                            style="border:1px solid #e2e5ec; border-radius:8px; padding:10px; cursor:pointer; display:flex; gap:8px; align-items:center;"
                            id="label-livree">
                            <input type="radio" name="base_facturation" id="r-livree" value="livree" checked
                                onchange="majBaseFacturation()">
                            <span style="font-size:12.5px; font-weight:600;">Qtés livrées (BL)</span>
                        </label>
                        <label
                            style="border:1px solid #e2e5ec; border-radius:8px; padding:10px; cursor:pointer; display:flex; gap:8px; align-items:center;"
                            id="label-commandee">
                            <input type="radio" name="base_facturation" id="r-commandee" value="commandee"
                                onchange="majBaseFacturation()">
                            <span style="font-size:12.5px; font-weight:600;">Qtés BC d'origine</span>
                        </label>
                    </div>
                </div>

                {{-- 2. Mode de paiement --}}
                <div style="margin-bottom:18px;">
                    <label
                        style="font-weight:700; font-size:12px; text-transform:uppercase; color:#475569; display:block; margin-bottom:6px;">Mode
                        de paiement <span style="color:#dc2626">*</span></label>
                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px;">
                        <label
                            style="border:1px solid #047857; background:#f0fdf4; border-radius:8px; padding:10px; cursor:pointer; text-align:center;"
                            id="label-mode-caisse">
                            <input type="radio" name="mode_paiement" value="Caisse" checked style="display:none;"
                                onchange="selectionnerMode('Caisse')">
                            <span style="font-size:12.5px; font-weight:700; color:#166534;">Caisse</span>
                        </label>
                        <label
                            style="border:1px solid #e2e5ec; border-radius:8px; padding:10px; cursor:pointer; text-align:center;"
                            id="label-mode-banque">
                            <input type="radio" name="mode_paiement" value="Banque" style="display:none;"
                                onchange="selectionnerMode('Banque')">
                            <span style="font-size:12.5px; font-weight:700; color:#475569;">Banque</span>
                        </label>
                        <label
                            style="border:1px solid #e2e5ec; border-radius:8px; padding:10px; cursor:pointer; text-align:center;"
                            id="label-mode-credit">
                            <input type="radio" name="mode_paiement" value="Crédit" style="display:none;"
                                onchange="selectionnerMode('Crédit')">
                            <span style="font-size:12.5px; font-weight:700; color:#475569;">Crédit</span>
                        </label>
                    </div>
                </div>

                {{-- Bloc Banque (caché par défaut) --}}
                <div id="selection-banque-bl"
                    style="display:none; background:#f8fafc; border:1px solid #e2e5ec; border-radius:10px; padding:16px; margin-bottom:18px;">
                    <div style="margin-bottom:12px;">
                        <label style="font-weight:600; font-size:12px; display:block; margin-bottom:4px;">Sélectionner
                            la banque *</label>
                        <select name="banque_id" id="bl-banque-select"
                            style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; background:#fff;">
                            <option value="">— Choisir la banque —</option>
                            @foreach($banques as $b)
                                <option value="{{ $b->id }}">{{ $b->intitule }} ({{ $b->code }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="font-weight:600; font-size:12px; display:block; margin-bottom:4px;">Moyen de
                            paiement bancaire *</label>
                        <select name="moyen_bancaire" id="bl-moyen-select"
                            style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; background:#fff;">
                            <option value="">— Choisir le moyen —</option>
                            <option value="carte">Carte bancaire</option>
                            <option value="virement">Virement</option>
                            <option value="cheque">Chèque</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-weight:600; font-size:12px; display:block; margin-bottom:4px;">Référence
                            *</label>
                        <input type="text" name="reference_paiement" id="bl-ref-input" placeholder="Numéro..."
                            style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px;">
                    </div>
                </div>

                {{-- 3. Montant payé --}}
                <div style="margin-bottom:18px;" id="bloc-montant-paye">
                    <label
                        style="font-weight:700; font-size:12px; text-transform:uppercase; color:#475569; display:block; margin-bottom:6px;"
                        id="label-montant-paye">Montant reçu / réglé *</label>
                    <input type="number" name="montant_paye" id="bl-montant-input"
                        value="{{ round($bl->bonDeCommande->montant_ttc) }}"
                        style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; font-weight:700;">
                </div>

                {{-- 4. Case à cocher Livraison Immédiate --}}
                @if($bl->statut !== 'livre')
                    <div
                        style="margin-bottom:20px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:12px 14px;">
                        <label
                            style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:600; font-size:13px; color:#166534;">
                            <input type="checkbox" name="livraison_immediate" value="1" checked
                                style="width:16px; height:16px; cursor:pointer;">
                            Marquer la livraison comme immédiate et finale ?
                        </label>
                    </div>
                @endif

                <div style="display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" onclick="document.getElementById('modal-facturer').style.display='none'"
                        style="padding:9px 18px; border-radius:8px; border:1px solid #cbd5e1; background:#fff; font-weight:600; cursor:pointer;">
                        Annuler
                    </button>
                    <button type="submit"
                        style="padding:9px 18px; border-radius:8px; background:#047857; color:#fff; border:none; font-weight:700; cursor:pointer;">
                        <i class="fas fa-check"></i> Créer la Facture
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function selectionnerMode(mode) {
            // Reset all labels style
            ['Caisse', 'Banque', 'Crédit'].forEach(m => {
                const label = document.getElementById('label-mode-' + m.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, ""));
                if (label) {
                    label.style.border = '1px solid #e2e5ec';
                    label.style.background = '#fff';
                    label.querySelector('span').style.color = '#475569';
                }
            });

            // Set selected style
            const actLabel = document.getElementById('label-mode-' + mode.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, ""));
            const input = actLabel.querySelector('input');
            input.checked = true;

            if (mode === 'Caisse') {
                actLabel.style.border = '1px solid #047857';
                actLabel.style.background = '#f0fdf4';
                actLabel.querySelector('span').style.color = '#166534';
                document.getElementById('selection-banque-bl').style.display = 'none';
                document.getElementById('bloc-montant-paye').style.display = 'block';
                document.getElementById('bl-montant-input').disabled = false;
            } else if (mode === 'Banque') {
                actLabel.style.border = '1px solid #1e3a8a';
                actLabel.style.background = '#eff6ff';
                actLabel.querySelector('span').style.color = '#1e40af';
                document.getElementById('selection-banque-bl').style.display = 'block';
                document.getElementById('bloc-montant-paye').style.display = 'block';
                document.getElementById('bl-montant-input').disabled = false;
            } else if (mode === 'Crédit') {
                actLabel.style.border = '1px solid #dc2626';
                actLabel.style.background = '#fef2f2';
                actLabel.querySelector('span').style.color = '#991b1b';
                document.getElementById('selection-banque-bl').style.display = 'none';
                document.getElementById('bloc-montant-paye').style.display = 'none';
                document.getElementById('bl-montant-input').disabled = true;
            }
        }

        function majBaseFacturation() {
            const isLivree = document.getElementById('r-livree').checked;
            // Styliser les boutons radio
            document.getElementById('label-livree').style.borderColor = isLivree ? '#047857' : '#e2e5ec';
            document.getElementById('label-livree').style.background = isLivree ? '#f0fdf4' : '#fff';
            document.getElementById('label-commandee').style.borderColor = !isLivree ? '#047857' : '#e2e5ec';
            document.getElementById('label-commandee').style.background = !isLivree ? '#f0fdf4' : '#fff';
        }

        document.addEventListener('DOMContentLoaded', function () {
            majBaseFacturation();
            selectionnerMode('Caisse');
        });
    </script>

</body>

</html>
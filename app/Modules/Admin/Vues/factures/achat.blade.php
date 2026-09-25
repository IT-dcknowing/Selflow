@extends('admin::gabarits.application')
@section('titre', $achat->type_facture === 'avoir' ? 'Facture d\'avoir fournisseur ' . $achat->numero_facture : 'Bon d\'achat ' . $achat->numero_facture)
@section('topbar_titre', $achat->type_facture === 'avoir' ? 'Achats — Facture d\'avoir' : 'Achats — Détails')

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

    /* Styles pour la barre de contrôle */
    .controls-card {
        background: #fff;
        border: 0.5px solid var(--border);
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.02);
    }
    
    .tabs {
        display: flex;
        gap: 8px;
        margin-top: 10px;
        flex-wrap: wrap;
    }
    
    .tab {
        padding: 8px 18px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        border: 0.5px solid var(--border);
        color: var(--text-2);
        background: #fff;
        transition: all .15s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    
    .tab:hover {
        background: var(--bg3);
        color: var(--text);
    }
    
    .tab.act {
        background: var(--primary);
        color: #fff;
        border-color: var(--primary);
    }

    /* Styles pour l'affichage du document */
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

    /* Styles pour la table modèle 4 standard */
    .table-m4 {
        width: 100%;
        border-collapse: collapse;
        font-size: 11px;
        margin-bottom: 20px;
        border: 1px solid #000;
    }
    
    .table-m4 th, .table-m4 td {
        border: 1px solid #000;
        padding: 8px 10px;
    }

@include('admin::factures.partials.styles-impression')
</style>
@endsection

@section('contenu')
<div class="no-print controls-card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <a href="{{ route('admin.achats.factures') }}" class="print-btn">
            <i class="fas fa-arrow-left"></i> Retour aux achats
        </a>
        <div style="font-weight: 700; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">Options du document</div>
    </div>

    <div class="tabs" id="model-tabs" style="margin-bottom: 15px;">
        <div class="tab act" onclick="setModel(1, this)"><i class="fas fa-list"></i> Modèle 1 — Classique</div>
        <div class="tab" onclick="setModel(2, this)"><i class="fas fa-columns"></i> Modèle 2 — Élégant</div>
        <div class="tab" onclick="setModel(3, this)"><i class="fas fa-th-large"></i> Modèle 3 — Moderne</div>
        <div class="tab" onclick="setModel(4, this)"><i class="fas fa-file-invoice"></i> Modèle 4 — Standard</div>
    </div>

    <div style="display: flex; justify-content: space-between; align-items: center; border-top: 0.5px solid var(--border); padding-top: 15px; flex-wrap: wrap; gap: 10px;">
        <div style="display: flex; gap: 8px; align-items: center;">
            <button class="tab" id="receipt-toggle-btn" onclick="toggleReceiptMode()" style="border-color: var(--primary); color: var(--primary);">
                <i class="fas fa-truck-ramp-box"></i> Passer en Bon de réception
            </button>
            @if(empty($achat->fournisseur->ncc))
                <a href="{{ route('admin.achats.bapa', $achat) }}" class="tab" style="border-color: var(--danger); color: var(--danger); text-decoration:none;">
                    <i class="fas fa-file-invoice"></i> Imprimer sous format BAPA
                </a>
            @endif
        </div>
        
        <div style="display: flex; gap: 8px; align-items: center;">
            <button class="print-btn" onclick="telechargerFichier('.invoice')"
                    title="Enregistrer le document dans un fichier, sans passer par l'impression.">
                <i class="fas fa-download"></i> Télécharger
            </button>
            <button class="print-btn main" onclick="telechargerPdf()"
                    title="Choisissez la destination « Enregistrer au format PDF » pour obtenir le fichier, ou votre imprimante pour une sortie papier.">
                <i class="fas fa-file-pdf"></i> Imprimer / PDF
            </button>
            <button class="print-btn" onclick="window.print()">
                <i class="fas fa-print"></i> Imprimer
            </button>
            
            @if($achat->etape === 'Demande de prix')
                <button class="print-btn" style="background:var(--warning); color:#fff; border-color:var(--warning);" onclick="executerAction('{{ route('admin.achats.confirmer', $achat) }}')">
                    <i class="fas fa-check-circle"></i> Confirmer la commande
                </button>
            @elseif($achat->etape === 'Bon de commande')
                <button class="print-btn" style="background:#10b981; color:#fff; border-color:#10b981;" onclick="executerAction('{{ route('admin.achats.facturer', $achat) }}')">
                    <i class="fas fa-file-invoice-dollar"></i> Valider & Facturer
                </button>
            @endif
        </div>
    </div>
</div>

<form id="action-form" method="POST" style="display:none;">
    @csrf
</form>

<div class="invoice-container">
    <div id="invoice-wrap"></div>
</div>

{{-- Modal de confirmation de l'avoir fournisseur.
     Retire pour les bordereaux d'achat aux producteurs agricoles : la DGI
     ne normalise pas leur avoir, et une piece etablie ici resterait sans
     contrepartie fiscale. Le controleur refuse de son cote — masquer le
     bouton ne ferme pas la route. --}}
@if(!$achat->estBapa())
<div class="modal-overlay" id="modalAvoir" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div class="modal" style="background:#fff; border-radius:12px; max-width:480px; width:100%; box-shadow:0 10px 30px rgba(0,0,0,0.15); overflow:hidden;">
        <div class="modal-header" style="padding:16px 20px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
            <h3 style="font-size:16px; font-weight:700; color:var(--text-1); margin:0;"><i class="fas fa-rotate-left" style="color:var(--danger)"></i> Générer une facture d'avoir fournisseur</h3>
            <button type="button" class="modal-close" onclick="fermerModalAvoir()" style="background:none; border:none; font-size:24px; cursor:pointer; color:var(--text-3);">&times;</button>
        </div>
        <form method="POST" action="{{ route('admin.achats.avoir', $achat) }}" style="margin:0; padding:20px;">
            @csrf
            <div style="font-size:13px; color:var(--text-2); margin-bottom:14px; line-height:1.5;">
                Cette action va enregistrer une facture d'avoir fournisseur pour un montant total de <strong>{{ number_format($achat->montant_ttc, 0, ',', ' ') }} FCFA</strong>. Les stocks des articles stockables associés seront décrémentés (retour fournisseur).
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label">Motif ou Référence de l'avoir fournisseur <span style="color:var(--danger)">*</span></label>
                <input type="text" name="raison" class="form-control" required placeholder="Ex: Retour d'article défectueux, erreur de prix sur facture..." maxlength="255">
            </div>

            <div style="border-top:1px solid var(--border); padding-top:14px; margin-top:14px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-outline" onclick="fermerModalAvoir()">Annuler</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-check-circle"></i> Confirmer & Enregistrer l'avoir</button>
            </div>
        </form>
    </div>
</div>
@endif
@endsection

@section('scripts')
<script>
var curModel = 1;
var isReceiptMode = false;

@php
    $entreprise = $achat->pointDeVente->entreprise;
    $logoUrl = $entreprise->logo_path;
    if ($logoUrl && !str_starts_with($logoUrl, 'http://') && !str_starts_with($logoUrl, 'https://')) {
        $logoUrl = Storage::disk('public')->url($logoUrl);
    }
@endphp

var DATA = {
    achat: {
        num: {!! json_encode($achat->numero_facture) !!},
        date: {!! json_encode(\Carbon\Carbon::parse($achat->date_achat)->isoFormat('D MMMM YYYY')) !!},
        etape: {!! json_encode($achat->etape) !!},
        type_facture: {!! json_encode($achat->type_facture) !!},
        parent_ref: {!! json_encode($achat->parent ? $achat->parent->numero_facture : null) !!},
        parent_fne: {!! json_encode($achat->parent ? $achat->parent->numero_fne : null) !!},
        // Signature electronique de la DGI. Elle figurait sur le bordereau
        // mais sur aucun des quatre modeles de facture d'achat, alors que
        // c'est la meme piece certifiee.
        normalise: {{ $achat->normalise ? 'true' : 'false' }},
        numero_fne: {!! json_encode($achat->numero_fne) !!},
        qr_code_data: {!! json_encode($achat->qr_code_data) !!},
        qr_code_image: {!! json_encode(\App\Modules\Admin\Services\QrCodeFneService::imageDeVerification($achat->qr_code_data, 110)) !!},
        raison_avoir: {!! json_encode($achat->raison_avoir) !!},
        mode: {!! json_encode($achat->mode_paiement) !!},
        moyen_bancaire: {!! json_encode($achat->moyen_bancaire) !!},
        reference_paiement: {!! json_encode($achat->reference_paiement) !!},
        statut: {!! json_encode($achat->statut) !!},
        montant_ht: {{ $achat->montant_ht }},
        montant_tva: 0,
        montant_ttc: {{ $achat->montant_ttc }},
        // Droit de timbre du sur un bordereau d'achat regle en especes. La
        // facture d'achat et le bordereau portent sur la meme operation : ils
        // doivent annoncer le meme montant a payer.
        timbre_fiscal: {{ (float) \App\Modules\Admin\Services\TimbreQuittanceService::pourAchat($achat) }},
        timbre_bareme: {{ $achat->fne_timbre_fiscal === null ? 'true' : 'false' }},
        fournisseur: {
            nom: {!! json_encode($achat->fournisseur?->nom ?? 'Fournisseur divers') !!},
            telephone: {!! json_encode($achat->fournisseur?->telephone ?? '') !!},
            email: {!! json_encode($achat->fournisseur?->email ?? '') !!},
            secteur: {!! json_encode($achat->fournisseur?->secteur ?? '') !!},
            ncc: {!! json_encode($achat->fournisseur?->ncc ?? '') !!},
            regime: {!! json_encode($achat->fournisseur?->regime_imposition ?? '') !!},
            adresse: {!! json_encode($achat->fournisseur?->adresse ?? '') !!}
        },
        items: [
            @foreach($achat->details as $i => $detail)
            {
                idx: {{ $i + 1 }},
                ref: {!! json_encode($detail->produit?->reference ?? 'REF-VIR-' . str_pad($detail->id, 3, '0', STR_PAD_LEFT)) !!},
                nom: {!! json_encode($detail->libelle_virtuel ?? ($detail->produit?->nom ?? 'Article')) !!},
                qty: {{ $detail->quantite }},
                unite: {!! json_encode($detail->unite ?? 'Unité') !!},
                pu: {{ $detail->prix_unitaire }},
                ht: {{ $detail->quantite * $detail->prix_unitaire }}
            },
            @endforeach
        ]
    }
};

var COMPANY = {
    nom: {!! json_encode($entreprise->nom) !!},
    pdv: {!! json_encode($achat->pointDeVente->nom) !!},
    adresse: {!! json_encode($entreprise->adresse ?? '') !!},
    tel: {!! json_encode($entreprise->telephone ?? '') !!},
    email: {!! json_encode($entreprise->email ?? '') !!},
    rccm: {!! json_encode($entreprise->rccm ?? '') !!},
    ncc: {!! json_encode($entreprise->ncc ?? '') !!},
    regime: {!! json_encode($entreprise->regime_imposition ?? '') !!},
    centre_impots: {!! json_encode($entreprise->centre_impots ?? '') !!},
    ref_bancaire: {!! json_encode($entreprise->ref_bancaire ?? '') !!},
    logo: {!! json_encode($logoUrl) !!}
};

function fmt(n) {
    return Math.round(n).toLocaleString('fr-FR') + ' F';
}

function fmtFcfa(n) {
    return Math.round(n).toLocaleString('fr-FR') + ' FCFA';
}

function getFormattedMode(d) {
    if (d.mode && d.mode.startsWith('Banque')) {
        if (d.moyen_bancaire) {
            let label = d.moyen_bancaire;
            if (d.moyen_bancaire === 'carte') label = 'carte';
            else if (d.moyen_bancaire === 'cheque') label = 'chèque';
            else if (d.moyen_bancaire === 'virement') label = 'virement';
            return 'Banque : ' + label;
        }
        return 'Banque';
    }
    return d.mode;
}

function executerAction(url) {
    if (confirm("Confirmer cette action ?")) {
        var form = document.getElementById('action-form');
        form.action = url;
        form.submit();
    }
}

function toggleReceiptMode() {
    isReceiptMode = !isReceiptMode;
    var btn = document.getElementById('receipt-toggle-btn');
    if (isReceiptMode) {
        btn.classList.add('act');
        btn.innerHTML = '<i class="fas fa-truck-ramp-box"></i> Mode Facture / Commande';
    } else {
        btn.classList.remove('act');
        btn.innerHTML = '<i class="fas fa-truck-ramp-box"></i> Passer en Bon de réception';
    }
    render();
}

/** Nom de fichier propose par le navigateur dans la boite d'impression. */
function nomFichierPdf() {
    return (isReceiptMode ? 'BR_' : 'Achat_') + DATA.achat.num;
}

@include('admin::factures.partials.script-impression')

/**
 * Signature electronique exigee par la DGI sur toute piece certifiee :
 * « le QR Code, le visuel FNE et le format de la numerotation ».
 *
 * Le code QR n'est que l'encodage du jeton renvoye par la plateforme, que le
 * referentiel decrit comme le « code de verification a convertir en QR code ».
 * Sans jeton, aucun visuel n'est produit.
 */
function blocCertificationFneAchat(d) {
    if (!d.normalise || !d.numero_fne) return '';

    return `
    <div style="display:flex; align-items:center; gap:14px; border:1px solid var(--border); border-radius:8px; padding:12px 14px; margin-top:16px; background:#fff;">
        ${d.qr_code_image ? `<img src="${d.qr_code_image}" style="width:110px; height:110px; flex-shrink:0;" alt="Code de vérification FNE">` : ''}
        <img src="/logo-FNE.png" style="height:52px; object-fit:contain; flex-shrink:0;" alt="Facture normalisée électronique">
        <div style="font-size:10px; line-height:1.6; color:var(--tx); word-break:break-all;">
            <div style="font-weight:800; text-transform:uppercase; letter-spacing:.04em;">Facture normalisée électronique</div>
            <div>N° FNE : <strong style="font-family:monospace;">${d.numero_fne}</strong></div>
            ${d.qr_code_data ? `<div>Vérification : <span style="font-family:monospace;">${d.qr_code_data}</span></div>` : ''}
        </div>
    </div>`;
}

/**
 * Droit de timbre de quittance sur un bordereau d'achat.
 *
 * La DGI l'applique aussi aux bordereaux : sur celui du 5 aout 2026, un total
 * HT de 13 200 F regle en especes s'est vu ajouter 100 F, montant exact de la
 * deuxieme tranche de l'article 873 du CGI. Sur un achat, l'article 875 met
 * le droit a la charge du debiteur : c'est l'entreprise qui l'acquitte.
 *
 * Tant que la piece n'est pas certifiee, le montant vient du bareme et
 * l'intitule le precise.
 */
function libelleTimbreAchat(d) {
    return d.timbre_bareme
        ? 'Timbre de quittance (barème, avant certification)'
        : 'Timbre de quittance';
}

function blocTimbreAchat(d) {
    if (!d.timbre_fiscal || d.timbre_fiscal <= 0) return '';
    return `
    <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:12px;border-bottom:0.5px solid var(--border)">
        <span style="color:var(--mu)">${libelleTimbreAchat(d)}</span><span>${fmtFcfa(d.timbre_fiscal)}</span>
    </div>
    <div style="display:flex;justify-content:space-between;padding:8px 0 6px;font-size:14px;font-weight:800;">
        <span>NET A PAYER</span><span>${fmtFcfa(d.montant_ttc + d.timbre_fiscal)}</span>
    </div>`;
}

/** Meme bloc, en lignes de tableau pour le modele standard. */
function blocTimbreAchatTr(d, colonnes) {
    if (!d.timbre_fiscal || d.timbre_fiscal <= 0) return '';
    return `
            <tr style="background:#fff; color:#000;">
                <td colspan="${colonnes}" style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:600;">${libelleTimbreAchat(d)}</td>
                <td style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:600; white-space:nowrap;">${fmt(d.timbre_fiscal)}</td>
            </tr>
            <tr style="background:#fff; color:#000;">
                <td colspan="${colonnes}" style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:900; text-transform:uppercase;">Net a payer</td>
                <td style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:900; white-space:nowrap;">${fmt(d.montant_ttc + d.timbre_fiscal)}</td>
            </tr>`;
}

function logoHtml(src, alt, maxH) {
    if (!src) return '';
    return `<img src="${src}" alt="${alt}" style="max-height:${maxH}px;max-width:140px;object-fit:contain;">`;
}

function fiscalLinesSeller(sep) {
    var lines = [];
    if (COMPANY.ncc)           lines.push('NCC : ' + COMPANY.ncc);
    if (COMPANY.regime)        lines.push('Régime : ' + COMPANY.regime);
    if (COMPANY.centre_impots) lines.push('Centre : ' + COMPANY.centre_impots);
    if (COMPANY.rccm)          lines.push('RCCM : ' + COMPANY.rccm);
    return lines.join(sep || ' · ');
}

function fiscalLinesClient(c, sep) {
    var lines = [];
    if (c.adresse)   lines.push(c.adresse);
    if (c.telephone) lines.push('Tél : ' + c.telephone);
    if (c.email)     lines.push(c.email);
    if (c.ncc)       lines.push('NCC : ' + c.ncc);
    if (c.regime)    lines.push('Régime : ' + c.regime);
    return lines.join(sep || '<br>');
}

function getAvoirBlock(d) {
    if (d.type_facture !== 'avoir') return '';
    return `
    <div style="background:#fff7ed; border:1px solid #ffedd5; border-left:4px solid #ea580c; border-radius:8px; padding:10px 14px; margin-bottom:14px; font-size:11px; line-height:1.4; color:#c2410c; text-align:left;">
        <i class="fas fa-circle-info" style="margin-right:4px;"></i> Facture d'avoir émise en référence à la facture d'origine <strong>${d.parent_ref}</strong>${d.parent_fne ? ` (N° Fiscal DGI: ${d.parent_fne})` : ''}.<br>
        <strong>Motif :</strong> ${d.raison_avoir}
    </div>
    `;
}

function getDocTitle(d) {
    if (isReceiptMode) return "BON DE RÉCEPTION";
    if (d.type_facture === 'avoir') return "FACTURE D'AVOIR FOURNISSEUR";
    if (d.etape === 'Demande de prix') return "DEMANDE DE PRIX";
    if (d.etape === 'Bon de commande') return "BON DE COMMANDE FOURNISSEUR";
    return "FACTURE D'ACHAT";
}

function getBadgeText(d) {
    if (isReceiptMode) return "RÉCEPTION";
    if (d.type_facture === 'avoir') return "AVOIR";
    if (d.etape === 'Demande de prix') return "DEMANDE";
    if (d.etape === 'Bon de commande') return "COMMANDE";
    return "ACHAT";
}

function statusColor(statut, themeColor) {
    if (statut === 'Payé')   return '#059669';
    if (statut === 'Crédit') return '#dc2626';
    return themeColor;
}

function getThemeColors() {
    var themes = {
        1: { color: '#0F6E56', bg: '#E1F5EE', tx: '#085041' },
        2: { color: '#185FA5', bg: '#E6F1FB', tx: '#0C447C' },
        3: { color: '#4F46E5', bg: '#EEF2FF', tx: '#3730A3' },
        4: { color: '#1E293B', bg: '#F1F5F9', tx: '#0F172A' }
    };
    return themes[curModel];
}

function model1(d) {
    var theme = getThemeColors();
    var sColor = statusColor(d.statut, theme.color);
    var title = getDocTitle(d);
    var badge = getBadgeText(d);
    
    return `
<div class="invoice" style="border: 1px solid #e2e8f0; font-family: 'Inter', sans-serif; color: #1e293b; background:#fff;">
    <div style="padding:28px 32px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px">
            <div style="display:flex;align-items:flex-start;gap:12px;">
                ${COMPANY.logo ? `<div style="flex-shrink:0;">${logoHtml(COMPANY.logo, COMPANY.nom, 52)}</div>` : `<div style="width:38px;height:38px;border-radius:9px;background:${theme.color};display:flex;align-items:center;justify-content:center"><i class="fas fa-bolt" style="color:#fff;font-size:17px"></i></div>`}
                <div>
                    <div style="font-size:15px;font-weight:700;color:var(--tx)">${COMPANY.nom}</div>
                    <div style="font-size:11px;color:var(--mu);margin-top:1px">${COMPANY.pdv}</div>
                    <div style="font-size:10.5px;color:var(--mu);line-height:1.7;margin-top:5px">
                        ${COMPANY.adresse ? COMPANY.adresse + '<br>' : ''}
                        ${COMPANY.tel ? 'Tél : ' + COMPANY.tel : ''}${COMPANY.email ? ' · ' + COMPANY.email : ''}<br>
                        ${fiscalLinesSeller('<br>')}
                    </div>
                </div>
            </div>
            <div style="text-align:right;flex-shrink:0;">
                <div style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:600;background:${theme.bg};color:${theme.tx};margin-bottom:6px">${badge}</div>
                <div style="font-size:22px;font-weight:800;color:var(--tx)">${title}</div>
                <div style="font-size:13px;color:var(--mu);margin-top:2px;font-weight:600;">${d.num}</div>
            </div>
        </div>
        <div style="height:0.5px;background:var(--border);margin-bottom:18px;"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:22px">
            <div style="background:var(--white);border-radius:8px;padding:12px 14px;border:0.5px solid var(--border)">
                <div style="font-size:10px;font-weight:700;color:${theme.color};text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px">Fournisseur</div>
                <div style="font-size:13px;font-weight:700;color:var(--tx)">${d.fournisseur.nom}</div>
                <div style="font-size:11px;color:var(--mu);margin-top:4px;line-height:1.7">${fiscalLinesClient(d.fournisseur, '<br>')}</div>
            </div>
            <div style="background:var(--white);border-radius:8px;padding:12px 14px;border:0.5px solid var(--border)">
                <div style="font-size:10px;font-weight:700;color:${theme.color};text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px">Informations</div>
                ${[
                    ["Date d'émission", d.date],
                    ["Mode de paiement", isReceiptMode ? null : getFormattedMode(d)],
                    ["Point de vente", COMPANY.pdv]
                ].map(r => r[1] ? `<div style="display:flex;justify-content:space-between;font-size:11px;padding:4px 0;border-bottom:0.5px solid var(--border)"><span style="color:var(--mu)">${r[0]}</span><span style="font-weight:600;color:var(--tx)">${r[1]}</span></div>` : '').join('')}
                ${isReceiptMode ? '' : `
                <div style="display:flex;justify-content:space-between;font-size:11px;padding:5px 0">
                    <span style="color:var(--mu)">Statut</span>
                    <span style="font-weight:700;color:${sColor};background:${sColor}18;padding:2px 10px;border-radius:20px">${d.statut}</span>
                </div>
                `}
            </div>
        </div>
        
        ${getAvoirBlock(d)}
        <table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:16px">
            <thead>
                <tr style="background:${theme.color};color:#fff;">
                    <th style="padding:9px 12px;text-align:left;color:#fff;background:${theme.color};font-weight:600;width:15%;white-space:nowrap;">Réf.</th>
                    <th style="padding:9px 12px;text-align:left;color:#fff;background:${theme.color};font-weight:600">Désignation</th>
                    <th style="padding:9px 12px;text-align:center;color:#fff;background:${theme.color};font-weight:600;width:12%;white-space:nowrap;">Unité</th>
                    <th style="padding:9px 12px;text-align:right;color:#fff;background:${theme.color};font-weight:600;width:10%;white-space:nowrap;">Qté</th>
                    ${isReceiptMode ? '' : `
                    <th style="padding:9px 12px;text-align:right;color:#fff;background:${theme.color};font-weight:600;width:18%;white-space:nowrap;">P.U. HT</th>
                    <th style="padding:9px 12px;text-align:right;color:#fff;background:${theme.color};font-weight:600;width:20%;white-space:nowrap;">Total HT</th>
                    `}
                </tr>
            </thead>
            <tbody>
                ${d.items.map((r, i) => `<tr style="background:${i % 2 === 0 ? '#fff' : '#F9FAFB'}">
                    <td style="padding:9px 12px;color:var(--mu);font-weight:500;white-space:nowrap;">${r.ref}</td>
                    <td style="padding:9px 12px;font-weight:600;color:var(--tx)">${r.nom}</td>
                    <td style="padding:9px 12px;text-align:center;color:var(--tx);white-space:nowrap;">${r.unite || 'Unité'}</td>
                    <td style="padding:9px 12px;text-align:right;color:var(--tx);white-space:nowrap;">${r.qty}</td>
                    ${isReceiptMode ? '' : `
                    <td style="padding:9px 12px;text-align:right;color:var(--tx);white-space:nowrap;">${fmt(r.pu)}</td>
                    <td style="padding:9px 12px;text-align:right;font-weight:700;color:var(--tx);white-space:nowrap;">${fmt(r.ht)}</td>
                    `}
                </tr>`).join('')}
            </tbody>
        </table>
        
        ${isReceiptMode ? `
        <div style="border-top:0.5px solid var(--border);padding-top:14px;margin-top:40px;display:flex;justify-content:space-between;align-items:flex-end">
            <div style="font-size:11px;color:var(--mu);line-height:1.7">Merci pour votre confiance.<br>Document généré par <strong>Selflow</strong></div>
            <div style="display:flex; gap: 20px;">
                <div style="text-align:center;font-size:10px;color:var(--mu)">Réceptionné par (Magasinier)<br><div style="width:120px;height:50px;border:0.5px dashed var(--br);border-radius:4px;margin-top:4px"></div></div>
                <div style="text-align:center;font-size:10px;color:var(--mu)">Livré par (Fournisseur)<br><div style="width:120px;height:50px;border:0.5px dashed var(--br);border-radius:4px;margin-top:4px"></div></div>
            </div>
        </div>
        ` : `
        <div style="display:flex;justify-content:space-between;margin-bottom:20px;align-items:flex-start;">
            <div style="flex:1;"></div>
            <div style="width:240px">
                <div style="display:flex;justify-content:space-between;padding:10px 0 6px;font-size:15px;font-weight:800;color:var(--tx);border-top:1.5px solid ${theme.color};"><span>TOTAL TTC</span><span>${fmtFcfa(d.montant_ttc)}</span></div>
                ${blocTimbreAchat(d)}
            </div>
        </div>
        ${blocCertificationFneAchat(d)}
        <div style="border-top:0.5px solid var(--border);padding-top:14px;display:flex;justify-content:space-between;align-items:flex-end">
            <div style="font-size:11px;color:var(--mu);line-height:1.7">Merci pour votre confiance.<br>Document généré par <strong>Selflow</strong></div>
            <div style="text-align:right;font-size:11px;color:var(--mu)">Signature / Cachet<br><div style="width:100px;height:40px;border:0.5px dashed var(--border);border-radius:4px;margin-top:4px"></div></div>
        </div>
        `}
    </div>
</div>`;
}

function model2(d) {
    var theme = getThemeColors();
    var sColor = statusColor(d.statut, theme.color);
    var title = getDocTitle(d);
    var badge = getBadgeText(d);
    
    return `
<div class="invoice" style="background:#fff;">
    <div style="display:flex">
        <div style="width:210px;background:${theme.color};padding:28px 20px;flex-shrink:0;display:flex;flex-direction:column">
            <div style="margin-bottom:16px;text-align:center">
                ${COMPANY.logo ? logoHtml(COMPANY.logo, COMPANY.nom, 54) : `<div style="width:36px;height:36px;border-radius:9px;background:rgba(255,255,255,.2);display:inline-flex;align-items:center;justify-content:center"><i class="fas fa-bolt" style="color:#fff;font-size:16px"></i></div>`}
            </div>
            <div style="font-size:13px;font-weight:700;color:#fff;margin-bottom:4px">${COMPANY.nom}</div>
            <div style="font-size:10px;color:rgba(255,255,255,.6);margin-bottom:14px">${COMPANY.pdv}</div>
            <div style="font-size:10.5px;color:rgba(255,255,255,.75);line-height:1.8;font-weight:500;">
                ${COMPANY.adresse ? COMPANY.adresse + '<br>' : ''}
                ${COMPANY.tel ? 'Tél : ' + COMPANY.tel + '<br>' : ''}
                ${COMPANY.email ? COMPANY.email + '<br>' : ''}
                ${COMPANY.ncc ? '<br>NCC : ' + COMPANY.ncc : ''}
                ${COMPANY.regime ? '<br>Rég. : ' + COMPANY.regime : ''}
                ${COMPANY.centre_impots ? '<br>' + COMPANY.centre_impots : ''}
                ${COMPANY.rccm ? '<br>RCCM : ' + COMPANY.rccm : ''}
            </div>
        </div>
        <div style="flex:1;padding:28px 24px">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px">
                <div>
                    <div style="font-size:22px;font-weight:800;color:var(--tx)">${title}</div>
                    <div style="font-size:12px;color:var(--mu);margin-top:2px;font-weight:600;">${d.num}</div>
                </div>
                <div style="padding:5px 14px;border-radius:20px;font-size:11px;font-weight:600;background:${theme.bg};color:${theme.tx}">${badge}</div>
            </div>
            <div style="margin-bottom:16px;padding:12px 14px;background:var(--white);border-radius:8px;border:0.5px solid var(--border)">
                <div style="font-size:10px;color:${theme.color};text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px;font-weight:700;">Fournisseur</div>
                <div style="font-size:13px;font-weight:700;color:var(--tx)">${d.fournisseur.nom}</div>
                <div style="font-size:11px;color:var(--mu);margin-top:4px;line-height:1.7;">${fiscalLinesClient(d.fournisseur, '<br>')}</div>
                <div style="display:flex;gap:14px;margin-top:10px;flex-wrap:wrap;">
                    <div style="font-size:11px"><span style="color:var(--mu)">Date : </span><span style="color:var(--tx);font-weight:600;">${d.date}</span></div>
                    ${isReceiptMode ? '' : `
                    <div style="font-size:11px"><span style="color:var(--mu)">Paiement : </span><span style="color:var(--tx);font-weight:600;">${getFormattedMode(d)}</span></div>
                    <div style="font-size:11px"><span style="color:var(--mu)">Statut : </span><span style="font-weight:700;color:${sColor}">${d.statut}</span></div>
                    `}
                </div>
            </div>
            ${getAvoirBlock(d)}
            <table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:14px">
                <thead>
                    <tr style="border-bottom:1.5px solid ${theme.color}">
                        <th style="padding:7px 0;text-align:left;color:${theme.color};font-weight:700;width:18%;white-space:nowrap;">Réf.</th>
                        <th style="padding:7px 6px;text-align:left;color:${theme.color};font-weight:700">Désignation</th>
                        <th style="padding:7px 6px;text-align:center;color:${theme.color};font-weight:700;width:12%;white-space:nowrap;">Unité</th>
                        <th style="padding:7px 6px;text-align:right;color:${theme.color};font-weight:700;width:10%;white-space:nowrap;">Qté</th>
                        ${isReceiptMode ? '' : `
                        <th style="padding:7px 6px;text-align:right;color:${theme.color};font-weight:700;width:18%;white-space:nowrap;">P.U. HT</th>
                        <th style="padding:7px 0;text-align:right;color:${theme.color};font-weight:700;width:20%;white-space:nowrap;">Total HT</th>
                        `}
                    </tr>
                </thead>
                <tbody>
                    ${d.items.map(r => `<tr style="border-bottom:0.5px solid var(--border)">
                        <td style="padding:8px 0;color:var(--mu);font-weight:500;white-space:nowrap;">${r.ref}</td>
                        <td style="padding:8px 6px;color:var(--tx);font-weight:600;">${r.nom}</td>
                        <td style="padding:8px 6px;text-align:center;color:var(--mu);white-space:nowrap;">${r.unite || 'Unité'}</td>
                        <td style="padding:8px 6px;text-align:right;color:var(--mu);white-space:nowrap;">${r.qty}</td>
                        ${isReceiptMode ? '' : `
                        <td style="padding:8px 6px;text-align:right;color:var(--mu);white-space:nowrap;">${fmt(r.pu)}</td>
                        <td style="padding:8px 0;text-align:right;font-weight:700;color:var(--tx);white-space:nowrap;">${fmt(r.ht)}</td>
                        `}
                    </tr>`).join('')}
                </tbody>
            </table>
            
            ${isReceiptMode ? `
            <div style="border-top:0.5px solid var(--border);padding-top:14px;margin-top:40px;display:flex;justify-content:space-between;align-items:flex-end">
                <div style="font-size:10px;color:var(--mu)">Généré automatiquement par <strong>Selflow</strong></div>
                <div style="display:flex; gap: 15px;">
                    <div style="text-align:center;font-size:9px;color:var(--mu)">Réceptionné par (Magasinier)<br><div style="width:100px;height:45px;border:0.5px dashed var(--br);border-radius:4px;margin-top:3px"></div></div>
                    <div style="text-align:center;font-size:9px;color:var(--mu)">Livré par (Fournisseur)<br><div style="width:100px;height:45px;border:0.5px dashed var(--br);border-radius:4px;margin-top:3px"></div></div>
                </div>
            </div>
            ` : `
            <div style="display:flex;justify-content:flex-end;margin-bottom:14px">
                <div style="width:220px;background:var(--white);border-radius:8px;padding:10px 12px;border:0.5px solid var(--border)">
                    <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:800;padding:6px 0 0;margin-top:4px"><span style="color:var(--tx)">Total TTC</span><span style="color:${theme.color}">${fmtFcfa(d.montant_ttc)}</span></div>
                    ${blocTimbreAchat(d)}
                </div>
            </div>
            ${blocCertificationFneAchat(d)}
            <div style="font-size:11px;color:var(--mu)">Généré automatiquement par <strong>Selflow</strong></div>
            `}
        </div>
    </div>
</div>`;
}

function model3(d) {
    var theme = getThemeColors();
    var sColor = statusColor(d.statut, theme.color);
    var title = getDocTitle(d);
    var badge = getBadgeText(d);
    
    return `
<div class="invoice" style="background:#fff;">
    <div style="border-top:4px solid ${theme.color};padding:28px 32px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px">
            <div style="display:flex;align-items:flex-start;gap:12px">
                ${COMPANY.logo ? `<div style="flex-shrink:0;">${logoHtml(COMPANY.logo, COMPANY.nom, 48)}</div>` : `<div style="width:38px;height:38px;border-radius:10px;background:${theme.color};display:flex;align-items:center;justify-content:center"><i class="fas fa-bolt" style="color:#fff;font-size:18px"></i></div>`}
                <div>
                    <div style="font-size:15px;font-weight:700;color:var(--tx)">${COMPANY.nom}</div>
                    <div style="font-size:11px;color:var(--mu)">${COMPANY.pdv}</div>
                    <div style="font-size:10.5px;color:var(--mu);line-height:1.7;margin-top:4px">
                        ${COMPANY.adresse ? COMPANY.adresse + '<br>' : ''}
                        ${COMPANY.tel ? 'Tél : ' + COMPANY.tel : ''}${COMPANY.email ? ' · ' + COMPANY.email : ''}
                    </div>
                </div>
            </div>
            <div style="text-align:right">
                <div style="font-size:24px;font-weight:800;color:var(--tx);letter-spacing:-0.5px;">${title}</div>
                <div style="font-size:12px;margin-top:2px"><span style="color:var(--mu)">N° </span><span style="font-weight:700;color:${theme.color}">${d.num}</span></div>
                <div style="display:inline-block;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:600;background:${theme.bg};color:${theme.tx};margin-top:6px">${badge}</div>
            </div>
        </div>

        <div style="height:1px;background:var(--border);margin-bottom:18px"></div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px">
            <div style="padding:12px 14px;background:var(--white);border-radius:8px;border:0.5px solid var(--border)">
                <div style="font-size:10px;color:${theme.color};font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px">Fournisseur</div>
                <div style="font-size:13px;font-weight:700;color:var(--tx)">${d.fournisseur.nom}</div>
                <div style="font-size:11px;color:var(--mu);margin-top:4px;line-height:1.7">${fiscalLinesClient(d.fournisseur, '<br>')}</div>
            </div>
            <div style="padding:12px 14px;background:var(--white);border-radius:8px;border:0.5px solid var(--border)">
                <div style="font-size:10px;color:${theme.color};font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px">Détails</div>
                <div style="font-size:11px;line-height:1.9">
                    <span style="color:var(--mu)">Date : </span><span style="color:var(--tx);font-weight:600;">${d.date}</span><br>
                    ${isReceiptMode ? '' : `<span style="color:var(--mu)">Paiement : </span><span style="color:var(--tx);font-weight:600;">${getFormattedMode(d)}</span><br>`}
                    ${isReceiptMode ? '' : `<span style="color:var(--mu)">Statut : </span><span style="font-weight:700;color:${sColor}">${d.statut}</span>`}
                </div>
            </div>
        </div>

        <div style="margin-bottom:14px;padding:10px 14px;background:var(--white);border-radius:8px;border:0.5px solid var(--border);font-size:10.5px;color:var(--mu)">
            <div style="display:flex;flex-wrap:wrap;gap:10px 20px;">
                ${COMPANY.ncc ? `<span>NCC : <strong style="color:var(--tx)">${COMPANY.ncc}</strong></span>` : ''}
                ${COMPANY.regime ? `<span>Rég. : <strong style="color:var(--tx)">${COMPANY.regime}</strong></span>` : ''}
                ${COMPANY.centre_impots ? `<span>Centre : <strong style="color:var(--tx)">${COMPANY.centre_impots}</strong></span>` : ''}
                ${COMPANY.rccm ? `<span>RCCM : <strong style="color:var(--tx)">${COMPANY.rccm}</strong></span>` : ''}
            </div>
        </div>

        ${getAvoirBlock(d)}
        <table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:16px;table-layout:fixed">
            <thead>
                <tr>
                    <th style="padding:9px 10px;text-align:left;border-bottom:2px solid ${theme.color};color:var(--tx);font-weight:700;width:15%;white-space:nowrap;">Réf.</th>
                    <th style="padding:9px 10px;text-align:left;border-bottom:2px solid ${theme.color};color:var(--tx);font-weight:700;width:30%">Désignation</th>
                    <th style="padding:9px 10px;text-align:center;border-bottom:2px solid ${theme.color};color:var(--tx);font-weight:700;width:12%;white-space:nowrap;">Unité</th>
                    <th style="padding:9px 10px;text-align:right;border-bottom:2px solid ${theme.color};color:var(--tx);font-weight:700;width:10%;white-space:nowrap;">Qté</th>
                    ${isReceiptMode ? '' : `
                    <th style="padding:9px 10px;text-align:right;border-bottom:2px solid ${theme.color};color:var(--tx);font-weight:700;width:16%;white-space:nowrap;">P.U. HT</th>
                    <th style="padding:9px 10px;text-align:right;border-bottom:2px solid ${theme.color};color:var(--tx);font-weight:700;width:17%;white-space:nowrap;">Total HT</th>
                    `}
                </tr>
            </thead>
            <tbody>
                ${d.items.map((r, i) => `<tr style="background:${i % 2 === 1 ? '#F9FAFB' : '#fff'}">
                    <td style="padding:9px 10px;color:var(--mu);font-size:11px;font-weight:500;white-space:nowrap;">${r.ref}</td>
                    <td style="padding:9px 10px;font-weight:600;color:var(--tx)">${r.nom}</td>
                    <td style="padding:9px 10px;text-align:center;color:var(--tx);white-space:nowrap;">${r.unite || 'Unité'}</td>
                    <td style="padding:9px 10px;text-align:right;color:var(--tx);white-space:nowrap;">${r.qty}</td>
                    ${isReceiptMode ? '' : `
                    <td style="padding:9px 10px;text-align:right;color:var(--mu);white-space:nowrap;">${fmt(r.pu)}</td>
                    <td style="padding:9px 10px;text-align:right;font-weight:700;color:var(--tx);white-space:nowrap;">${fmt(r.ht)}</td>
                    `}
                </tr>`).join('')}
            </tbody>
        </table>
        
        ${isReceiptMode ? `
        <div style="border-top:0.5px solid var(--border);padding-top:12px;margin-top:40px;display:flex;justify-content:space-between;align-items:center">
            <div style="font-size:11px;color:var(--mu)">Généré par <strong>Selflow</strong> · Document officiel</div>
            <div style="display:flex;gap:16px">
                <div style="text-align:center;font-size:10px;color:var(--mu)">Magasinier<br><div style="width:110px;height:45px;border:0.5px dashed var(--br);border-radius:0;margin-top:3px"></div></div>
                <div style="text-align:center;font-size:10px;color:var(--mu)">Livreur / Société<br><div style="width:110px;height:45px;border:0.5px dashed var(--br);border-radius:0;margin-top:3px"></div></div>
            </div>
        </div>
        ` : `
        <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:20px">
            <div style="font-size:11px;color:var(--mu);max-width:320px;line-height:1.7">
                <span style="color:var(--tx);font-weight:700">Acheteur :</span><br>
                ${COMPANY.adresse ? COMPANY.adresse + '<br>' : ''}
                ${COMPANY.tel ? 'Tél : ' + COMPANY.tel : ''}${COMPANY.email ? ' · ' + COMPANY.email : ''}<br>
            </div>
            <div style="width:235px">
                <div style="display:flex;justify-content:space-between;padding:8px;margin-top:4px;background:${theme.color};border-radius:6px">
                    <span style="font-size:13px;font-weight:700;color:#fff">Total TTC</span>
                    <span style="font-size:15px;font-weight:800;color:#fff">${fmtFcfa(d.montant_ttc)}</span>
                </div>
                ${blocTimbreAchat(d)}
            </div>
        </div>
        ${blocCertificationFneAchat(d)}
        <div style="border-top:0.5px solid var(--border);padding-top:12px;display:flex;justify-content:space-between;align-items:center">
            <div style="font-size:11px;color:var(--mu)">Généré par <strong>Selflow</strong> · Document officiel</div>
            <div style="display:flex;gap:16px">
                <div style="text-align:center;font-size:10px;color:var(--mu)">Signature Fournisseur<br><div style="width:80px;height:36px;border:0.5px dashed var(--border);border-radius:4px;margin-top:3px"></div></div>
                <div style="text-align:center;font-size:10px;color:var(--mu)">Signature Acheteur<br><div style="width:80px;height:36px;border:0.5px dashed var(--border);border-radius:4px;margin-top:3px"></div></div>
            </div>
        </div>
        `}
    </div>
</div>`;
}

function modelStandard(d) {
    var title = getDocTitle(d);
    
    var rowsHtml = '';
    if (isReceiptMode) {
        rowsHtml = d.items.map(r => `
            <tr style="background:#fff; color:#000;">
                <td style="padding:8px 10px; border:1px solid #000; font-weight:500; white-space:nowrap;">${r.ref}</td>
                <td style="padding:8px 10px; border:1px solid #000; font-weight:700;">${r.nom}</td>
                <td style="padding:8px 10px; border:1px solid #000; text-align:right; white-space:nowrap;">${r.qty}</td>
                <td style="padding:8px 10px; border:1px solid #000; text-align:center; white-space:nowrap;">${r.unite || 'Unité'}</td>
            </tr>
        `).join('');
    } else {
        rowsHtml = d.items.map(r => `
            <tr style="background:#fff; color:#000;">
                <td style="padding:8px 10px; border:1px solid #000; font-weight:500; white-space:nowrap;">${r.ref}</td>
                <td style="padding:8px 10px; border:1px solid #000; font-weight:700;">${r.nom}</td>
                <td style="padding:8px 10px; border:1px solid #000; text-align:right; white-space:nowrap;">${fmt(r.pu)}</td>
                <td style="padding:8px 10px; border:1px solid #000; text-align:right; white-space:nowrap;">${r.qty}</td>
                <td style="padding:8px 10px; border:1px solid #000; text-align:center; white-space:nowrap;">${r.unite || 'Unité'}</td>
                <td style="padding:8px 10px; border:1px solid #000; text-align:right; font-weight:700; white-space:nowrap;">${fmt(r.ht)}</td>
            </tr>
        `).join('');
        
        rowsHtml += `
            <tr style="background:#fff; color:#000;">
                <td colspan="5" style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:700; text-transform:uppercase;">TOTAL HT</td>
                <td style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:700; white-space:nowrap;">${fmt(d.montant_ht)}</td>
            </tr>
            <tr style="background:#fff; color:#000;">
                <td colspan="5" style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:700; text-transform:uppercase;">TOTAL TTC</td>
                <td style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:700; white-space:nowrap;">${fmt(d.montant_ttc)}</td>
            </tr>
            ${blocTimbreAchatTr(d, 5)}
        `;
    }

    return `
<div class="invoice" style="border: 1px solid #000; border-radius: 0; background: #fff; box-shadow: none;">
    <div style="padding:40px; color:#000; font-family: 'Inter', sans-serif;">
        <!-- En-tête -->
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px;">
            <div style="flex:1; font-size:11px; line-height:1.6; color:#000; padding-right: 20px;">
                <div style="border: 1.5px solid #000; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                    <div style="font-size:14px; font-weight:800; text-transform:uppercase; margin-bottom:4px;">${COMPANY.nom}</div>
                    <div>NCC : <strong>${COMPANY.ncc || '—'}</strong></div>
                    <div>Régime d'imposition : <strong>${COMPANY.regime || '—'}</strong></div>
                    <div>Centre des impôts : <strong>${COMPANY.centre_impots || '—'}</strong></div>
                </div>
                <div style="margin-top:10px;">
                    RCCM : <strong>${COMPANY.rccm || '—'}</strong><br>
                    Adresse : ${COMPANY.adresse || '—'}<br>
                    Nº Tel : ${COMPANY.tel || '—'}<br>
                    Mail : ${COMPANY.email || '—'}
                </div>
            </div>
            
            <div style="text-align:right; flex-shrink:0; width:280px; display:flex; flex-direction:column; align-items:flex-end;">
                <div style="height:60px; margin-bottom:15px; display:flex; align-items:center; justify-content:flex-end;">
                    ${COMPANY.logo ? `<img src="${COMPANY.logo}" alt="${COMPANY.nom}" style="max-height:100%; object-fit:contain;">` : `<div style="font-size:16px; font-weight:800; text-transform:uppercase; border:1px solid #000; padding:8px 12px;">${COMPANY.nom}</div>`}
                </div>
                <div style="font-size:16px; font-weight:800; letter-spacing:0.5px; color:#000; text-transform:uppercase; margin-bottom:4px;">${title}</div>
                <div style="font-size:12px; font-weight:700; color:#000;">N° ${d.num}</div>
                ${isReceiptMode ? '' : `<div style="font-size:11px; color:#333; margin-top:2px;">Statut : <span style="font-weight:700; text-transform:uppercase;">${d.statut}</span></div>`}
            </div>
        </div>
        
        <div style="border-bottom:1.5px solid #000; margin-bottom:20px;"></div>
        
        <!-- Section Fournisseur -->
        <div style="font-size:11px; line-height:1.6; color:#000; margin-bottom:25px; border-top:1.5px solid #000; padding-top:10px;">
            <div style="font-size:11px; font-weight:800; text-transform:uppercase; color:#000; margin-bottom:5px; letter-spacing:0.5px;">FOURNISSEUR</div>
            <div>Nom : <strong>${d.fournisseur.nom}</strong></div>
            <div>Adresse : <strong>${d.fournisseur.adresse || '—'}</strong></div>
            <div>NCC : <strong>${d.fournisseur.ncc || '—'}</strong></div>
            <div>Régime d'imposition : <strong>${d.fournisseur.regime || '—'}</strong></div>
        </div>
        
        ${getAvoirBlock(d)}
        <!-- Tableau des Articles -->
        <table class="table-m4">
            <thead>
                ${isReceiptMode ? `
                <tr style="background:#000; color:#fff; text-transform:uppercase;">
                    <th style="text-align:left; font-weight:700; width:15%; white-space:nowrap;">Réf.</th>
                    <th style="text-align:left; font-weight:700;">Désignation</th>
                    <th style="text-align:right; font-weight:700; width:15%; white-space:nowrap;">Qté</th>
                    <th style="text-align:center; font-weight:700; width:15%; white-space:nowrap;">Unité</th>
                </tr>
                ` : `
                <tr style="background:#000; color:#fff; text-transform:uppercase;">
                    <th style="text-align:left; font-weight:700; width:15%; white-space:nowrap;">Réf.</th>
                    <th style="text-align:left; font-weight:700;">Désignation</th>
                    <th style="text-align:right; font-weight:700; width:15%; white-space:nowrap;">P.U. HT</th>
                    <th style="text-align:right; font-weight:700; width:10%; white-space:nowrap;">Qté</th>
                    <th style="text-align:center; font-weight:700; width:15%; white-space:nowrap;">Unité</th>
                    <th style="text-align:right; font-weight:700; width:20%; white-space:nowrap;">Montant HT</th>
                </tr>
                `}
            </thead>
            <tbody>
                ${rowsHtml}
            </tbody>
        </table>
        
        ${isReceiptMode ? `
        <div style="border-top:1px solid #000; padding-top:25px; margin-top:50px; display:flex; justify-content:space-between; align-items:flex-end;">
            <div style="font-size:9px; color:#444; line-height:1.6; font-weight:500; max-width:70%;">
                Document officiel généré par <strong>Selflow</strong>.
            </div>
            <div style="display:flex; gap: 30px;">
                <div style="text-align:center;">
                    <div style="font-size:9px; font-weight:700; text-transform:uppercase; color:#333; margin-bottom:5px;">Magasinier</div>
                    <div style="width:120px; height:50px; border:1px dashed #000; border-radius:0; background:#fff;"></div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:9px; font-weight:700; text-transform:uppercase; color:#333; margin-bottom:5px;">Livreur</div>
                    <div style="width:120px; height:50px; border:1px dashed #000; border-radius:0; background:#fff;"></div>
                </div>
            </div>
        </div>
        ` : `
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-top: 25px;">
            <div style="flex:1;"></div>
            <div style="text-align:center; width:200px;">
                <div style="font-size:9px; font-weight:700; text-transform:uppercase; color:#333; margin-bottom:5px;">Cachet & Signature</div>
                <div style="width:140px; height:60px; border:1px dashed #000; border-radius:0; background:#fff; display:inline-block;"></div>
            </div>
        </div>
        ${blocCertificationFneAchat(d)}
        <div style="border-top:1px solid #000; padding-top:15px; margin-top:20px; font-size:9px; color:#444; line-height:1.6; font-weight:500; text-align:center;">
            Document généré par <strong>Selflow</strong>.
        </div>
        `}
    </div>
</div>
    `;
}

function render() {
    var d = DATA['achat'];
    var theme = getThemeColors();
    d.badge_color = theme.color;
    d.badge_bg = theme.bg;
    d.badge_tx = theme.tx;

    var html = '';
    if (curModel === 1) html = model1(d);
    else if (curModel === 2) html = model2(d);
    else if (curModel === 3) html = model3(d);
    else html = modelStandard(d);
    
    document.getElementById('invoice-wrap').innerHTML = html;
}

function setModel(n, el) {
    curModel = n;
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('act'));
    el.classList.add('act');
    render();
}

// Initial render
render();

// Auto-download if ?download=1 is in URL
const urlParams = new URLSearchParams(window.location.search);
if (urlParams.get('download') === '1') {
    setTimeout(() => {
        telechargerPdf();
    }, 800);
}

function ouvrirModalAvoir() {
    document.getElementById('modalAvoir').style.display = 'flex';
}
function fermerModalAvoir() {
    document.getElementById('modalAvoir').style.display = 'none';
}
</script>
@endsection

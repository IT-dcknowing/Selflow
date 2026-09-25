@extends('admin::gabarits.application')
@section('titre', isset($bl) ? 'Bon de Livraison ' . $bl->numero_bl : ($vente->type_facture === 'avoir' ? 'Facture d\'avoir ' . $vente->numero_facture : 'Facture ' . $vente->numero_facture))
@section('topbar_titre', isset($bl) ? 'Ventes — Bons de Livraison' : ($vente->type_facture === 'avoir' ? 'Vente — Facture d\'avoir' : 'Vente — Facture'))

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

    /* Styles pour l'affichage de la facture */
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

    /*
     * Tableaux d'articles.
     *
     * `overflow-wrap: anywhere` (et son equivalent `word-break: break-word`)
     * autorise le navigateur a couper un mot n'importe ou. Effet de bord :
     * la largeur minimale d'une colonne tombe a UN caractere. Avec dix
     * colonnes de montants en `nowrap`, la colonne Description se faisait
     * ecraser jusqu'a afficher une lettre par ligne — c'est ce qu'on voyait
     * sur les modeles 1 a 3.
     *
     * On revient donc a une coupure au mot (`break-word`, qui ne reduit pas
     * la largeur minimale calculee) et on impose la repartition des colonnes
     * via un <colgroup> plutot que de la laisser au navigateur.
     */
    .invoice table {
        width: 100%;
    }
    /* A l'ecran, un tableau trop large defile dans son cadre plutot que d'etre
       ampute par le masquage de debordement de la facture. */
    .invoice .table-wrap {
        overflow-x: auto;
    }
    .invoice table th,
    .invoice table td {
        overflow-wrap: break-word;
        white-space: normal;
    }
    /* Seuls les montants et pourcentages restent sur une seule ligne */
    .invoice table td.montant,
    .invoice table th.montant {
        white-space: nowrap;
    }

    /*
     * Tableau des articles : largeurs pilotees par le <colgroup> associe.
     * Les en-tetes ont le droit de passer sur deux lignes (« Autres / taxes »)
     * plutot que de deborder de leur colonne.
     */
    .invoice table.tbl-articles {
        table-layout: fixed;
    }
    .invoice table.tbl-articles th {
        white-space: normal !important;
        /* Un intitule se replie entre ses mots (« Autres / taxes ») mais jamais
           a l'interieur d'un mot : « TV / A » etait illisible. */
        overflow-wrap: normal;
        word-break: normal;
        hyphens: none;
        font-size: 10px;
        line-height: 1.25;
    }
    /* La designation se replie sur plusieurs lignes, jamais en colonne. */
    .invoice table.tbl-articles td.col-desc,
    .invoice table.tbl-articles td.col-ref {
        white-space: normal !important;
        overflow-wrap: break-word;
        word-break: normal;
    }
    /*
     * Libelles de taxes : ils se replient entre les mots. On interdit la
     * coupure a l'interieur d'un mot, qui donnait des « Taxe spécifiqu / e 5% »
     * illisibles dans une colonne etroite.
     */
    .invoice table.tbl-articles td.col-taxes {
        white-space: normal !important;
        overflow-wrap: normal;
        word-break: normal;
        hyphens: none;
    }
    /*
     * Volontairement pas de `overflow: hidden` ici : mieux vaut un montant qui
     * mord legerement sur sa voisine qu'un montant ampute de ses chiffres.
     */

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
        /* Huit colonnes : une gouttiere de 10 px de chaque cote ne laissait plus
           de place au libelle de l'article. */
        padding: 7px 6px;
    }

    /* QR Code styling */
    #qrcode img {
        display: inline-block;
    }

@include('admin::factures.partials.styles-impression')
</style>
@endsection

@section('contenu')
<div class="no-print controls-card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        @php
            $routeRetour = isset($bl)
                ? (request()->routeIs('caissier.*') ? route('caissier.ventes.factures', ['etape' => 'Bon de livraison']) : route('admin.ventes.factures', ['etape' => 'Bon de livraison']))
                : (request()->routeIs('caissier.*') ? route('caissier.ventes.factures') : route('admin.ventes.factures'));
        @endphp
        <a href="{{ $routeRetour }}" class="print-btn">
            <i class="fas fa-arrow-left"></i> {{ isset($bl) ? 'Retour aux bons de livraison' : 'Retour aux factures' }}
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
            @if($vente->etape === 'Bon de commande' && !isset($bl))
            <button class="tab" id="delivery-toggle-btn" onclick="toggleDeliveryMode()" style="border-color: var(--primary); color: var(--primary);">
                <i class="fas fa-truck-delivery"></i> Passer en Bon de livraison
            </button>
            @endif
        </div>
        
        @php
            // Le prefixe de route de l'ecran courant. Il etait calcule a
            // l'interieur du bloc des factures ; les boutons du PDF en ont
            // besoin avant.
            $prefixeRoutePdf = request()->routeIs('caissier.*') ? 'caissier' : 'admin';
        @endphp
        <div style="display: flex; gap: 8px; align-items: center;">
            @if(isset($bl))
                @if(!in_array($bl->statut, ['livre', 'facture']))
                <button type="button" class="print-btn" style="background:#0369a1; color:#fff; border-color:#0369a1; font-weight:700;" onclick="executerAction('{{ request()->routeIs('caissier.*') ? route('caissier.ventes.livraison.livrer', $bl) : route('admin.ventes.livraison.livrer', $bl) }}', true)">
                    <i class="fas fa-check"></i> Marquer Livré
                </button>
                @endif
                @if($bl->statut !== 'facture')
                <button type="button" class="print-btn" style="background:#047857; color:#fff; border-color:#047857; font-weight:700;" onclick="document.getElementById('modal-facturer').style.display='flex'">
                    <i class="fas fa-file-invoice-dollar"></i> → Facturer
                </button>
                @endif
            @endif


            @if($vente->etape === 'Facture' && !isset($bl) && $vente->type_facture !== 'avoir')
                @php
                    $prefixeRoute = request()->routeIs('caissier.*') ? 'caissier' : 'admin';
                @endphp

                {{-- La pièce, et non son numéro de ligne : `Vente` porte
                     `IdentifiantOpaque`, et ses adresses se lient par `uuid`.
                     Avec `->id`, ces trois boutons menaient à une page
                     « Not Found » (404 — introuvable), en ligne comme en local. --}}
                <a href="{{ route($prefixeRoute . '.ventes.ticket', $vente) }}" target="_blank" class="print-btn">
                    <i class="fas fa-receipt"></i> {{ $vente->estRecu() ? 'Imprimer le reçu' : 'Aperçu reçu' }}
                </a>

                {{-- Conversion recu <-> facture, dans les deux sens --}}
                @if($vente->pieceLiee)
                    <a href="{{ route($prefixeRoute . '.ventes.imprimer', $vente->pieceLiee) }}" class="print-btn"
                       title="Pièce liée déjà créée">
                        <i class="fas fa-link"></i> {{ $vente->pieceLiee->libelleTypeDocument() }} {{ $vente->pieceLiee->numero_facture }}
                    </a>
                @elseif($vente->estRecu())
                    {{-- Seul le sens reçu → facture est prévu par la DGI --}}
                    <button type="button" class="print-btn"
                            onclick="executerAction('{{ route($prefixeRoute . '.ventes.convertir_piece', $vente) }}')"
                            title="Établir la facture correspondant à ce reçu">
                        <i class="fas fa-file-invoice"></i> Créer la facture
                    </button>
                @endif
            @endif
            {{-- Un vrai PDF, etabli cote serveur. Le premier essai rendait
                 une page HTML autonome, faute de moteur PDF dans
                 l'application : dompdf a ete installe le 25/09/2026, et le
                 bouton remet desormais un `.pdf`. --}}
            <a class="print-btn" href="{{ route($prefixeRoutePdf . '.ventes.pdf', $vente) }}"
               title="Enregistrer le document au format PDF.">
                <i class="fas fa-download"></i> Télécharger le PDF
            </a>
            <button class="print-btn main" onclick="telechargerPdf()"
                    title="Choisissez la destination « Enregistrer au format PDF » pour obtenir le fichier, ou votre imprimante pour une sortie papier.">
                <i class="fas fa-file-pdf"></i> Imprimer / PDF
            </button>
            
            @if(!isset($bl))
                {{-- Ce qui rend un devis opposable : l'accord du client, daté et
                     nommé, et un terme qu'on peut repousser explicitement. --}}
                @if($vente->estUneOffre() && !$vente->estAccepte() && !$vente->estConverti() && !$vente->estExpire())
                    <button class="print-btn" style="background:#1d4ed8; color:#fff; border-color:#1d4ed8;" onclick="enregistrerAcceptation()">
                        <i class="fas fa-file-signature"></i> Le client a accepté
                    </button>
                @endif
                @if($vente->estUneOffre() && !$vente->estConverti())
                    <button class="print-btn" onclick="prolongerValidite()">
                        <i class="fas fa-hourglass-half"></i> Prolonger la validité
                    </button>
                @endif
                @if($vente->etape === 'Devis' && !$vente->estConverti())
                    <button class="print-btn" style="background:var(--warning); color:#fff; border-color:var(--warning);" onclick="executerAction('{{ route('admin.ventes.confirmer', $vente) }}')">
                        <i class="fas fa-check-circle"></i> Confirmer la commande
                    </button>
                @elseif($vente->etape === 'Bon de commande' && !$vente->estConverti())
                    <button class="print-btn" style="background:#10b981; color:#fff; border-color:#10b981;" onclick="executerAction('{{ route('admin.ventes.facturer', $vente) }}')">
                        <i class="fas fa-file-invoice-dollar"></i> Valider & Facturer
                    </button>
                @endif
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

{{-- Modal de confirmation de l'avoir --}}
<div class="modal-overlay" id="modalAvoir" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div class="modal" style="background:#fff; border-radius:12px; max-width:480px; width:100%; box-shadow:0 10px 30px rgba(0,0,0,0.15); overflow:hidden;">
        <div class="modal-header" style="padding:16px 20px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
            <h3 style="font-size:16px; font-weight:700; color:var(--text-1); margin:0;"><i class="fas fa-rotate-left" style="color:var(--danger)"></i> Générer une facture d'avoir</h3>
            <button type="button" class="modal-close" onclick="fermerModalAvoir()" style="background:none; border:none; font-size:24px; cursor:pointer; color:var(--text-3);">&times;</button>
        </div>
        <form method="POST" action="{{ route(request()->routeIs('caissier.*') ? 'caissier.ventes.avoir' : 'admin.ventes.avoir', $vente) }}" style="margin:0; padding:20px;">
            @csrf
            <div style="font-size:13px; color:var(--text-2); margin-bottom:14px; line-height:1.5;">
                Cette action va générer une facture d'avoir pour un montant total de <strong>{{ number_format($vente->montant_ttc, 0, ',', ' ') }} FCFA</strong>. Les stocks des articles stockables associés seront ré-incrémentés en stock.
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label">Motif ou Raison de l'avoir <span style="color:var(--danger)">*</span></label>
                <input type="text" name="raison" class="form-control" required placeholder="Ex: Retour d'article défectueux, erreur de facturation..." maxlength="255">
            </div>

            <div style="border-top:1px solid var(--border); padding-top:14px; margin-top:14px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-outline" onclick="fermerModalAvoir()">Annuler</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-check-circle"></i> Confirmer & Créer l'avoir</button>
            </div>
        </form>
    </div>
</div>

@if(isset($bl))
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
                action="{{ request()->routeIs('caissier.*') ? route('caissier.ventes.livraison.facturer', $bl) : route('admin.ventes.livraison.facturer', $bl) }}"
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
@endif
@endsection

@section('scripts')

<script>
var curModel = 1;
var isDeliveryMode = {{ isset($bl) ? 'true' : 'false' }};

@php
    $entreprise = $vente->pointDeVente->entreprise;
    $logoUrl = $entreprise->logo_path;
    if ($logoUrl && !str_starts_with($logoUrl, 'http://') && !str_starts_with($logoUrl, 'https://')) {
        $logoUrl = Storage::disk('public')->url($logoUrl);
    }
@endphp

var DATA = {
    vente: {
        num: {!! json_encode($vente->numero_facture) !!},
        date: {!! json_encode(\Carbon\Carbon::parse($vente->date_vente)->isoFormat('D MMMM YYYY')) !!},
        etape: {!! json_encode($vente->etape) !!},
        // Le terme de l'offre — devis et bons de commande seulement. Nul sur
        // une facture, qui engage des son emission et n'expire pas.
        date_validite: {!! json_encode($vente->date_validite ? $vente->date_validite->isoFormat('D MMMM YYYY') : null) !!},
        date_acceptation: {!! json_encode($vente->date_acceptation ? $vente->date_acceptation->isoFormat('D MMMM YYYY') : null) !!},
        accepte_par: {!! json_encode($vente->accepte_par) !!},
        est_expire: {{ $vente->estExpire() ? 'true' : 'false' }},
        remise: {{ $vente->remise ?? 0 }},
        remise_taux: {{ (float) ($vente->remise_taux ?? 0) }},
        autres_mentions: {!! json_encode($vente->autres_mentions ?: $vente->pointDeVente->entreprise->facture_autres_mentions) !!},
        pied_de_page: {!! json_encode($vente->pied_de_page ?: $vente->pointDeVente->entreprise->pied_de_page_facture) !!},
        taxes_ttc: [
            @foreach($vente->taxesPersonnalisees as $taxe)
            { nom: {!! json_encode($taxe->nom) !!}, taux: {{ (float) $taxe->taux }} },
            @endforeach
        ],
        normalise: {{ $vente->normalise ? 'true' : 'false' }},
        type_facture: {!! json_encode($vente->type_facture) !!},
        parent_ref: {!! json_encode($vente->parent ? $vente->parent->numero_facture : null) !!},
        parent_fne: {!! json_encode($vente->parent ? $vente->parent->numero_fne : null) !!},
        // Timbre acquitte sur la facture d'origine. Il n'est pas recredite par
        // l'avoir — la DGI ne prevoit aucun champ pour cela — mais le taire
        // laisserait croire a une erreur de calcul.
        parent_timbre: {{ $vente->parent ? (float) \App\Modules\Admin\Services\TimbreQuittanceService::pourVente($vente->parent) : 0 }},
        raison_avoir: {!! json_encode($vente->raison_avoir) !!},
        qr_code_data: {!! json_encode($vente->qr_code_data) !!},
        // Encodage graphique du jeton de verification renvoye par la FNE.
        // « token | string | Code de verification a convertir en QR code »
        // (procedure d'integration DGI). Vide tant que la piece n'est pas
        // certifiee : rien n'est fabrique en l'absence de jeton.
        qr_code_image: {!! json_encode(\App\Modules\Admin\Services\QrCodeFneService::imageDeVerification($vente->qr_code_data)) !!},
        numero_fne: {!! json_encode($vente->numero_fne) !!},
        signature_dgi: {!! json_encode($vente->signature_dgi) !!},
        moyen_bancaire: {!! json_encode($vente->moyen_bancaire) !!},
        reference_paiement: {!! json_encode($vente->reference_paiement) !!},
        client: {
            nom: {!! json_encode($vente->client?->nom ?? 'Client de passage') !!},
            adresse: {!! json_encode($vente->client?->adresse ?? '') !!},
            tel: {!! json_encode($vente->client?->telephone ?? '') !!},
            email: {!! json_encode($vente->client?->email ?? '') !!},
            ncc: {!! json_encode($vente->client?->ncc ?? '') !!},
            regime: {!! json_encode($vente->client?->regime_imposition ?? '') !!},
            template: {!! json_encode($vente->client?->type_facturation ?? ($vente->client?->ncc ? 'B2B' : 'B2C')) !!},
            rccm: {!! json_encode($vente->client?->rccm ?? '') !!}
        },
        items: [
            @foreach($vente->details as $detail)
            {
                ref: {!! json_encode($detail->produit?->reference ?? 'REF-VIR-' . str_pad($detail->id, 3, '0', STR_PAD_LEFT)) !!},
                desc: {!! json_encode($detail->libelle_virtuel ?? ($detail->produit?->nom ?? 'Article')) !!},
                qty: {{ isset($bl) ? ($bl->details->firstWhere('produit_id', $detail->produit_id)?->qte_livree ?? 0) : $detail->quantite }},
                unite: {!! json_encode($detail->unite ?? 'Unité') !!},
                pu: {{ $detail->prix_unitaire }},
                remise_taux: {{ (float) ($detail->remise_taux ?? 0) }},
                taxes: [
                    @foreach($detail->taxes as $taxe)
                    { nom: {!! json_encode($taxe->nom) !!}, taux: {{ (float) $taxe->taux }} },
                    @endforeach
                ],
                @php
                    $htLigne = (float) $detail->quantite * (float) $detail->prix_unitaire
                        * (1 - (float) ($detail->remise_taux ?? 0) / 100);
                    $tauxLigne = $htLigne > 0
                        ? round((float) $detail->montant_tva / $htLigne * 100, 2)
                        : (float) ($detail->produit->taux_tva ?? 0);
                @endphp
                tva: {{ $tauxLigne }},
                code_tva: {!! json_encode(\App\Modules\Admin\Modeles\Produit::deduireCodeTva(
                    $tauxLigne,
                    $vente->pointDeVente->entreprise->regime_imposition
                )) !!}
            },
            @endforeach
        ],
        mode: {!! json_encode($vente->mode_paiement) !!},
        statut: {!! json_encode($vente->statut) !!},
        deja_paye: {{ $vente->type_facture === 'avoir' ? $vente->montant_ttc : ($dejaPaye ?? 0) }},
        // Ce que le client a tendu, et ce qu'on lui a rendu. Le second se
        // calcule cote serveur -- `Vente::monnaieRendue()` -- plutot qu'ici :
        // deux calculs finiraient par diverger, et c'est un chiffre que le
        // client verifie.
        montant_recu: {{ $vente->montant_recu !== null ? (float) $vente->montant_recu : 'null' }},
        monnaie_rendue: {{ $vente->monnaieRendue() !== null ? $vente->monnaieRendue() : 'null' }},
        autres_taxes_montant: {{ (float) ($vente->montant_autres_taxes ?? 0) }},
        // Droit de timbre de quittance. Le montant renvoye par la plateforme
        // fait foi ; avant normalisation, il est etabli au bareme de
        // l'article 873 du CGI (voir TimbreQuittanceService).
        timbre_fiscal: {{ (float) \App\Modules\Admin\Services\TimbreQuittanceService::pourVente($vente) }},
        timbre_provenance: {!! json_encode(\App\Modules\Admin\Services\TimbreQuittanceService::provenance($vente)) !!},
        ref_bl: {!! json_encode(isset($bl) ? $bl->numero_bl : ($vente->etape === 'Bon de commande' ? ($vente->bonLivraison?->numero_bl ?? '') : ($vente->bonLivraisonSource?->numero_bl ?? ''))) !!},
        ref_bc: {!! json_encode(isset($bl) ? $bl->bonDeCommande->numero_facture : ($vente->etape === 'Bon de commande' ? $vente->numero_facture : (optional($vente->bonLivraisonSource?->bonDeCommande)->numero_facture ?? ''))) !!}
    }
};

/**
 * Le bloc de la monnaie rendue, commun aux quatre modeles.
 *
 * Il ne parait que si quelque chose a ete rendu : une ligne a zero sur un
 * reglement a l'appoint annoncerait une operation qui n'a pas eu lieu.
 */
function blocMonnaieRendue(d, petit) {
    if (!d.monnaie_rendue || d.monnaie_rendue <= 0) return '';

    var taille = petit ? '11px' : '12px';
    return `
    <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:${taille};border-bottom:0.5px solid var(--border)"><span style="color:var(--mu)">Reçu</span><span>${fmtFcfa(d.montant_recu)}</span></div>
    <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:${taille};font-weight:800;color:#15803d;"><span style="text-transform:uppercase;">Monnaie rendue</span><span>${fmtFcfa(d.monnaie_rendue)}</span></div>
    `;
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

var COMPANY = {
    nom: {!! json_encode($entreprise->nom) !!},
    pdv: {!! json_encode($vente->pointDeVente->nom) !!},
    adresse: {!! json_encode($entreprise->adresse ?? '') !!},
    tel: {!! json_encode($entreprise->telephone ?? '') !!},
    email: {!! json_encode($entreprise->email ?? '') !!},
    rccm: {!! json_encode($entreprise->rccm ?? '') !!},
    ncc: {!! json_encode($entreprise->ncc ?? '') !!},
    regime: {!! json_encode($entreprise->regime_imposition ?? '') !!},
    centre_impots: {!! json_encode($entreprise->centre_impots ?? '') !!},
    ref_bancaire: {!! json_encode($entreprise->ref_bancaire ?? '') !!},
    logo: {!! json_encode($logoUrl) !!},
    vendeur: {!! json_encode($vendeur->nom ?? '') !!}
};

function fmt(n) {
    return Math.round(n).toLocaleString('fr-FR') + ' F';
}

function fmtFcfa(n) {
    return Math.round(n).toLocaleString('fr-FR') + ' FCFA';
}

/**
 * Repartition des colonnes du tableau des articles (modeles 1 a 3).
 *
 * Laissee au navigateur, cette repartition sacrifiait la designation : c'est
 * la seule colonne « compressible » face a neuf colonnes de montants en
 * `nowrap`, d'ou le libelle affiche une lettre par ligne. Les largeurs sont
 * donc fixees ici, une bonne fois, et totalisent exactement 100 %.
 */
function colgroupArticles() {
    // Total exactement egal a 100 %. Les colonnes de montants sont calibrees
    // pour un chiffre a sept positions (« 1 234 567 F »), courant en FCFA.
    var largeurs = isDeliveryMode
        ? ['14%', '58%', '14%', '14%']
        : ['8%', '23.5%', '6.5%', '5.5%', '11.5%', '6%', '9.5%', '5%', '12%', '12.5%'];

    return '<colgroup>' + largeurs.map(function (l) {
        return '<col style="width:' + l + '">';
    }).join('') + '</colgroup>';
}

/** Meme principe pour le modele 4, dont le tableau a un jeu de colonnes propre. */
function colgroupArticlesM4() {
    var largeurs = isDeliveryMode
        ? ['16%', '56%', '14%', '14%']
        : ['10%', '26%', '13%', '6%', '8%', '12%', '10%', '15%'];

    return '<colgroup>' + largeurs.map(function (l) {
        return '<col style="width:' + l + '">';
    }).join('') + '</colgroup>';
}

/**
 * Rappel des taxes propres a un article sous sa designation. Elles n'etaient
 * visibles que dans le recapitulatif, sans qu'on sache a quelle ligne elles se
 * rattachaient.
 */
function detailTaxesLigne(r) {
    if (!r.taxes || !r.taxes.length) return '—';
    return r.taxes.map(t => `${t.nom} ${t.taux}%`).join('<br>');
}

/**
 * Libelles des codes TVA de la DGI.
 */
var LIBELLES_CODE_TVA = {
    TVA:  'TVA normale',
    TVAB: 'TVA taux reduit',
    TVAC: 'TVA exo. conventionnelle',
    TVAD: 'TVA exo. legale'
};

/**
 * Ventile le chiffre d'affaires par code TVA reel.
 *
 * Le recapitulatif affichait une seule ligne « TVA normale 18,00% » quel que
 * soit le contenu de la facture : une facture melangeant 18 %, 9 % et des
 * articles exoneres etait donc presentee a tort comme entierement taxee a 18 %.
 */
function ventilerParCodeTva(c) {
    var ratio = c.tot_ht > 0 ? c.tot_ht_net / c.tot_ht : 0;
    var groupes = {};

    c.rows.forEach(function (r) {
        var code = r.code_tva || 'TVA';
        if (!groupes[code]) {
            groupes[code] = { code: code, taux: r.tva, base: 0, taxe: 0 };
        }
        groupes[code].base += r.ht * ratio;
        groupes[code].taxe += r.ht * ratio * r.tva / 100;
    });

    return Object.values(groupes);
}

/**
 * Libelle du total de TVA : le taux n'est affiche que s'il est unique sur la
 * facture, sinon la mention resterait fausse des qu'un article differe.
 */
function libelleTotalTva(c) {
    var taux = [...new Set(c.rows.map(r => parseFloat(r.tva || 0)))];
    if (taux.length === 1) {
        return 'TVA (' + String(taux[0]).replace('.', ',') + '%)';
    }
    return 'Total TVA';
}

/**
 * Lignes « Autres taxes » + « Net a payer » du recapitulatif, au format des
 * modeles 1 a 3 (blocs en <div>).
 */
/**
 * Intitule du droit de timbre.
 *
 * Tant que la piece n'est pas certifiee, le montant vient du bareme de
 * l'article 873 du CGI : c'est une estimation, fidele au texte mais que la
 * plateforme n'a pas encore confirmee. Le dire evite qu'un ecart de tranche
 * passe pour une erreur de caisse.
 */
function libelleTimbre(c) {
    return c.timbre_provenance === 'dgi'
        ? 'Timbre de quittance'
        : 'Timbre de quittance (barème, avant certification)';
}

function blocAutresTaxesDiv(c, couleur) {
    if (c.tot_autres_taxes <= 0 && c.timbre <= 0) return '';

    var lignes = c.lignes_autres_taxes.map(t =>
        `<div style="display:flex;justify-content:space-between;padding:6px 0;font-size:12px;border-bottom:0.5px solid var(--border)"><span style="color:var(--mu)">${t.nom}</span><span>${fmtFcfa(t.montant)}</span></div>`
    ).join('');

    if (c.timbre > 0) {
        lignes += `<div style="display:flex;justify-content:space-between;padding:6px 0;font-size:12px;border-bottom:0.5px solid var(--border)"><span style="color:var(--mu)">${libelleTimbre(c)}</span><span>${fmtFcfa(c.timbre)}</span></div>`;
    }

    return lignes +
    `<div style="display:flex;justify-content:space-between;padding:8px 0 6px;font-size:14px;font-weight:800;color:${couleur || 'var(--tx)'};"><span>NET A PAYER</span><span>${fmtFcfa(c.net_a_payer)}</span></div>`;
}

/**
 * Idem pour le modele 4, construit en lignes de tableau.
 */
function blocAutresTaxesTr(c, colonnes) {
    if (c.tot_autres_taxes <= 0 && c.timbre <= 0) return '';
    return c.lignes_autres_taxes.map(t => `
            <tr>
                <td colspan="${colonnes}" style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:600;">${t.nom}</td>
                <td style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:600; white-space:nowrap;">${fmt(t.montant)}</td>
            </tr>`).join('') + (c.timbre > 0 ? `
            <tr>
                <td colspan="${colonnes}" style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:600;">${libelleTimbre(c)}</td>
                <td style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:600; white-space:nowrap;">${fmt(c.timbre)}</td>
            </tr>` : '') + `
            <tr>
                <td colspan="${colonnes}" style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:900; text-transform:uppercase;">Net a payer</td>
                <td style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:900; white-space:nowrap;">${fmt(c.net_a_payer)}</td>
            </tr>`;
}

/**
 * Message commercial (`commercialMessage` de la FNE) et rattachement a un recu.
 */
function blocMentions(d) {
    var morceaux = [];
    if (d.autres_mentions) {
        morceaux.push(`<div>${d.autres_mentions}</div>`);
    }
    if (!morceaux.length) return '';
    return `<div style="margin-bottom:14px;padding:10px 14px;background:var(--white);border-radius:7px;border:0.5px solid var(--border);font-size:11px;color:var(--mu);line-height:1.7;">${morceaux.join('')}</div>`;
}

/**
 * Pied de page personnalise (`footer` de la FNE).
 */
function blocPiedDePage(d) {
    if (!d.pied_de_page) return '';
    return `<div style="margin-top:10px;font-size:11px;color:var(--mu);line-height:1.6;text-align:center;">${d.pied_de_page}</div>`;
}

/**
 * Ordre de calcul aligne sur celui de la FNE :
 *   remise de ligne -> remise globale -> TVA -> TTC -> autres taxes.
 *
 * La TVA est calculee ligne par ligne au taux reel de chaque article : un
 * article exonere ne doit pas se voir appliquer 18 %.
 */
function calcItems(items, remiseGlobal, taxesTtc, timbreFiscal) {
    remiseGlobal = parseFloat(remiseGlobal || 0);
    timbreFiscal = parseFloat(timbreFiscal || 0);

    var rows = items.map(it => {
        var remiseLigne = parseFloat(it.remise_taux || 0);
        var ht_brut = it.qty * it.pu;
        var ht = ht_brut * (1 - remiseLigne / 100);
        var tva = ht * it.tva / 100;
        return { ...it, remise_taux: remiseLigne, ht_brut, ht, tva_amt: tva, ttc: ht + tva };
    });

    // Trois niveaux de HT, a ne pas confondre :
    //   tot_ht_brut  = avant toute remise                (« TOTAL HT » de la DGI)
    //   tot_ht       = apres les remises de ligne
    //   tot_ht_net   = apres la remise globale           (« TOTAL HT APRES REMISE »)
    //
    // Le « Sous-total Brut HT » affichait jusqu'ici tot_ht, deja net des
    // remises de ligne, et la ligne « Remise » ne reprenait que la remise
    // globale. Une facture remisee ligne par ligne annoncait donc un brut
    // inferieur a celui de la facture certifiee, sans que la difference
    // apparaisse nulle part.
    var tot_ht_brut = rows.reduce((s, r) => s + r.ht_brut, 0);
    var tot_ht = rows.reduce((s, r) => s + r.ht, 0);
    var remise_lignes = tot_ht_brut - tot_ht;
    var tot_ht_net = Math.max(0, tot_ht - remiseGlobal);
    var ratio = tot_ht > 0 ? tot_ht_net / tot_ht : 0;
    var remise_totale = remise_lignes + remiseGlobal;
    var tot_tva = rows.reduce((s, r) => s + (r.ht * ratio * r.tva / 100), 0);
    var tot_ttc = tot_ht_net + tot_tva;

    // Autres taxes : celles des articles (sur le HT net de la ligne) et
    // celles portant sur le total TTC.
    var lignes_autres_taxes = [];
    rows.forEach(r => {
        (r.taxes || []).forEach(t => {
            lignes_autres_taxes.push({ nom: t.nom, montant: r.ht * ratio * t.taux / 100 });
        });
    });
    (taxesTtc || []).forEach(t => {
        lignes_autres_taxes.push({ nom: t.nom, montant: tot_ttc * t.taux / 100 });
    });

    var tot_autres_taxes = lignes_autres_taxes.reduce((s, t) => s + t.montant, 0);

    return {
        rows, tot_ht_brut, tot_ht, tot_ht_net, tot_tva, tot_ttc,
        remiseGlobal, remise_lignes, remise_totale,
        lignes_autres_taxes, tot_autres_taxes,
        timbre: timbreFiscal,
        net_a_payer: tot_ttc + tot_autres_taxes + timbreFiscal
    };
}

function executerAction(url, sansConfirmation = false) {
    if (sansConfirmation || confirm("Confirmer cette action ?")) {
        var form = document.getElementById('action-form');
        form.action = url;
        form.submit();
    }
}

/**
 * L'acceptation du client : la date et le nom de qui a accepte de son cote.
 * C'est ce qu'on oppose en cas de contestation ; sans cela, un devis
 * « accepte » ne repose que sur la memoire de deux personnes.
 */
function enregistrerAcceptation() {
    var qui = prompt("Qui a accepté ce devis, du côté du client ?\n(nom de la personne — laissez vide pour retenir le nom du client)");
    if (qui === null) return;

    var form = document.getElementById('action-form');
    form.action = @json(route('admin.ventes.accepter', $vente));
    ajouterChamp(form, 'accepte_par', qui);
    form.submit();
}

/**
 * Prolonger, c'est refaire l'offre : un geste explicite, trace, et impossible
 * sur un document deja converti.
 */
function prolongerValidite() {
    var terme = prompt("Nouvelle date de validité (AAAA-MM-JJ) :", @json(now()->addDays(\App\Modules\Admin\Modeles\Vente::VALIDITE_PAR_DEFAUT)->toDateString()));
    if (!terme) return;

    var form = document.getElementById('action-form');
    form.action = @json(route('admin.ventes.prolonger', $vente));
    ajouterChamp(form, 'date_validite', terme);
    form.submit();
}

function ajouterChamp(form, nom, valeur) {
    var champ = form.querySelector('[name="' + nom + '"]');
    if (!champ) {
        champ = document.createElement('input');
        champ.type = 'hidden';
        champ.name = nom;
        form.appendChild(champ);
    }
    champ.value = valeur;
}

function toggleDeliveryMode() {
    isDeliveryMode = !isDeliveryMode;
    var btn = document.getElementById('delivery-toggle-btn');
    if (isDeliveryMode) {
        btn.classList.add('act');
        btn.innerHTML = '<i class="fas fa-truck-delivery"></i> Mode Facture / Commande';
    } else {
        btn.classList.remove('act');
        btn.innerHTML = '<i class="fas fa-truck-delivery"></i> Passer en Bon de livraison';
    }
    render();
}

/** Nom de fichier propose par le navigateur dans la boite d'impression. */
function nomFichierPdf() {
    return (isDeliveryMode ? 'BL_' : 'Facture_') + DATA.vente.num;
}

@include('admin::factures.partials.script-impression')

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
    if (c.adresse)  lines.push(c.adresse);
    if (c.tel)      lines.push('Tél : ' + c.tel);
    if (c.email)    lines.push(c.email);
    if (c.ncc)      lines.push('NCC : ' + c.ncc);
    if (c.rccm)     lines.push('RCCM : ' + c.rccm);
    if (c.regime)   lines.push('Régime : ' + c.regime);
    if (c.template) lines.push('Facturation : ' + c.template);
    return lines.join(sep || '<br>');
}

/**
 * Signature électronique exigée par la DGI sur toute pièce certifiée.
 *
 * La procédure d'intégration la définit « en trois éléments : le QR Code, le
 * visuel FNE et le format de la numérotation » — les trois figurent ici.
 *
 * Rien n'est inventé pour autant : le code QR n'est que l'encodage graphique
 * du jeton renvoyé par la plateforme, que le référentiel décrit comme le
 * « code de vérification à convertir en QR code ». Sans jeton, pas d'image.
 */
function blocCertificationFne(d) {
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

function getAvoirBlock(d) {
    if (d.type_facture !== 'avoir') return '';

    // Le droit de timbre suit la quittance, pas la marchandise : il est du sur
    // l'acte qui a constate l'encaissement, et l'annulation de la vente ne
    // defait pas cet acte. La plateforme le confirme — sa reponse de
    // remboursement ne comporte aucun champ de timbre. L'avoir credite donc
    // le TTC sans le timbre, et le dit plutot que de laisser un ecart
    // inexplique entre les deux pieces.
    var mentionTimbre = d.parent_timbre > 0
        ? `<br><span style="font-size:10.5px;">Le droit de timbre de ${fmtFcfa(d.parent_timbre)} acquitté sur la facture d'origine n'est pas recrédité : il est dû sur la quittance émise, que l'annulation ne défait pas.</span>`
        : '';

    return `
    <div style="background:#fff7ed; border:1px solid #ffedd5; border-left:4px solid #ea580c; border-radius:8px; padding:10px 14px; margin-bottom:14px; font-size:11px; line-height:1.4; color:#c2410c; text-align:left;">
        <i class="fas fa-circle-info" style="margin-right:4px;"></i> Facture d'avoir émise en référence à la facture d'origine <strong>${d.parent_ref}</strong>${d.parent_fne ? ` (N° Fiscal DGI: ${d.parent_fne})` : ''}.<br>
        <strong>Motif :</strong> ${d.raison_avoir}${mentionTimbre}
    </div>
    `;
}

/**
 * Ce qui rend un devis opposable : son terme, et l'accord du client.
 *
 * Un devis sans terme engage indefiniment celui qui l'a fait — il reste
 * presentable des mois plus tard, aux prix du jour ou il a ete etabli, et le
 * client qui l'accepte a raison de le faire. Le terme doit donc figurer sur le
 * document remis, non seulement dans la base.
 *
 * Rien ici ne touche a la certification : un devis n'est pas une piece
 * fiscale, il n'est ni normalise, ni transmis, ni certifie.
 */
function getValiditeBlock(d) {
    if (d.etape !== 'Devis' && d.etape !== 'Bon de commande') return '';
    if (!d.date_validite) return '';

    var accord = d.date_acceptation
        ? `<br><strong>Accepte le ${d.date_acceptation}</strong>${d.accepte_par ? ` par ${d.accepte_par}` : ''}.`
        : '';

    var couleur = d.est_expire ? '#b91c1c' : '#1d4ed8';
    var fond    = d.est_expire ? '#fef2f2' : '#eff6ff';
    var bordure = d.est_expire ? '#fecaca' : '#dbeafe';

    var terme = d.est_expire
        ? `Offre expiree le <strong>${d.date_validite}</strong> : les prix indiques n'engagent plus.`
        : `Offre valable jusqu'au <strong>${d.date_validite}</strong> inclus. Passe ce terme, les prix indiques ne nous engagent plus.`;

    return `
    <div style="background:${fond}; border:1px solid ${bordure}; border-left:4px solid ${couleur}; border-radius:8px; padding:10px 14px; margin-bottom:14px; font-size:11px; line-height:1.4; color:${couleur}; text-align:left;">
        <i class="fas fa-hourglass-half" style="margin-right:4px;"></i> ${terme}${accord}
    </div>
    `;
}

function getDocTitle(d) {
    if (isDeliveryMode) return "BON DE LIVRAISON";
    if (d.type_facture === 'avoir') return "FACTURE D'AVOIR";
    if (d.etape === 'Devis') return "DEVIS ESTIMATIF";
    if (d.etape === 'Bon de commande') return "BON DE COMMANDE";
    return "FACTURE";
}

function getBadgeText(d) {
    if (isDeliveryMode) return "LIVRAISON";
    if (d.type_facture === 'avoir') return "AVOIR";
    if (d.etape === 'Devis') return "DEVIS";
    if (d.etape === 'Bon de commande') return "COMMANDE";
    return "VENTE";
}

function statusColor(statut, themeColor) {
    if (statut === 'Payé')   return '#059669';
    if (statut === 'Crédit') return '#dc2626';
    if (statut === 'Avance') return '#d97706';
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
    var c = calcItems(d.items, d.remise, d.taxes_ttc, d.timbre_fiscal);
    c.timbre_provenance = d.timbre_provenance;
    var theme = getThemeColors();
    var sColor = statusColor(d.statut, theme.color);
    var title = getDocTitle(d);
    var badge = getBadgeText(d);
    
    return `
<div class="invoice">
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
                <div style="font-size:13px;color:var(--mu);margin-top:2px;font-weight:600;">N° ${isDeliveryMode ? (d.ref_bl || 'BL-EN-COURS') : d.num}</div>
            </div>
        </div>
        <div style="height:0.5px;background:var(--border);margin-bottom:18px;"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:22px">
            <div style="background:var(--white);border-radius:8px;padding:12px 14px;border:0.5px solid var(--border)">
                <div style="font-size:10px;font-weight:700;color:${theme.color};text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px">Client</div>
                <div style="font-size:13px;font-weight:700;color:var(--tx)">${d.client.nom}</div>
                <div style="font-size:11px;color:var(--mu);margin-top:4px;line-height:1.7">${fiscalLinesClient(d.client, '<br>')}</div>
            </div>
            <div style="background:var(--white);border-radius:8px;padding:12px 14px;border:0.5px solid var(--border)">
                <div style="font-size:10px;font-weight:700;color:${theme.color};text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px">Informations</div>
                ${[
                    ["Date d'émission", d.date],
                    ["N° Bon de commande", isDeliveryMode ? d.ref_bc : null],
                    ["Mode de paiement", isDeliveryMode ? null : getFormattedMode(d)],
                    ["Vendeur", COMPANY.vendeur],
                    ["Point de vente", COMPANY.pdv]
                ].map(r => r[1] ? `<div style="display:flex;justify-content:space-between;font-size:11px;padding:4px 0;border-bottom:0.5px solid var(--border)"><span style="color:var(--mu)">${r[0]}</span><span style="font-weight:600;color:var(--tx)">${r[1]}</span></div>` : '').join('')}
                ${isDeliveryMode ? '' : `
                <div style="display:flex;justify-content:space-between;font-size:11px;padding:5px 0">
                    <span style="color:var(--mu)">Statut</span>
                    <span style="font-weight:700;color:${sColor};background:${sColor}18;padding:2px 10px;border-radius:20px">${d.statut}</span>
                </div>
                `}
            </div>
        </div>
        
        ${getValiditeBlock(d)}${getAvoirBlock(d)}
        <table class="tbl-articles" style="border-collapse:collapse;font-size:11px;margin-bottom:16px">
            ${colgroupArticles()}
            <thead>
                <tr style="background:${theme.color};color:#fff;">
                    <th style="padding:8px 6px;text-align:left;color:#fff;background:${theme.color};font-weight:600;">Réf.</th>
                    <th style="padding:8px 6px;text-align:left;color:#fff;background:${theme.color};font-weight:600">Description</th>
                    <th style="padding:8px 6px;text-align:center;color:#fff;background:${theme.color};font-weight:600;">Unité</th>
                    <th style="padding:8px 6px;text-align:right;color:#fff;background:${theme.color};font-weight:600;">Qté</th>
                    ${isDeliveryMode ? '' : `
                    <th style="padding:8px 6px;text-align:right;color:#fff;background:${theme.color};font-weight:600;">P.U. HT</th>
                    <th style="padding:8px 6px;text-align:right;color:#fff;background:${theme.color};font-weight:600;">Rem. (%)</th>
                    <th style="padding:8px 6px;text-align:right;color:#fff;background:${theme.color};font-weight:600;">Autres taxes</th>
                    <th style="padding:8px 6px;text-align:right;color:#fff;background:${theme.color};font-weight:600;">TVA</th>
                    <th style="padding:8px 6px;text-align:right;color:#fff;background:${theme.color};font-weight:600;">Montant HT</th>
                    <th style="padding:8px 6px;text-align:right;color:#fff;background:${theme.color};font-weight:600;">Total TTC</th>
                    `}
                </tr>
            </thead>
            <tbody>
                ${c.rows.map((r, i) => `<tr style="background:${i % 2 === 0 ? '#fff' : '#F9FAFB'}">
                    <td class="col-ref" style="padding:8px 6px;color:var(--mu);font-weight:500;">${r.ref}</td>
                    <td class="col-desc" style="padding:8px 6px;font-weight:600;color:var(--tx)">${r.desc}</td>
                    <td style="padding:8px 6px;text-align:center;color:var(--tx);white-space:nowrap;">${r.unite || 'Unité'}</td>
                    <td style="padding:8px 6px;text-align:right;color:var(--tx);white-space:nowrap;">${r.qty}</td>
                    ${isDeliveryMode ? '' : `
                    <td style="padding:8px 6px;text-align:right;color:var(--tx);white-space:nowrap;">${fmt(r.pu)}</td>
                    <td style="padding:8px 6px;text-align:right;color:${r.remise_taux > 0 ? '#dc2626' : 'var(--mu)'};white-space:nowrap;">${r.remise_taux || 0}%</td>
                    <td class="col-taxes" style="padding:8px 6px;text-align:right;color:var(--mu);font-size:10px;">${detailTaxesLigne(r)}</td>
                    <td style="padding:8px 6px;text-align:right;color:var(--mu);white-space:nowrap;">${r.tva}%</td>
                    <td style="padding:8px 6px;text-align:right;color:var(--tx);white-space:nowrap;">${fmt(r.ht)}</td>
                    <td style="padding:8px 6px;text-align:right;font-weight:700;color:var(--tx);white-space:nowrap;">${fmt(r.ttc)}</td>
                    `}
                </tr>`).join('')}
            </tbody>
        </table>
        
        ${isDeliveryMode ? `
        <div style="border-top:0.5px solid var(--border);padding-top:14px;margin-top:40px;display:flex;justify-content:space-between;align-items:flex-end">
            <div style="font-size:11px;color:var(--mu);line-height:1.7">Merci pour votre confiance.<br>Document généré par <strong>Selflow</strong> · selflow.app</div>
            <div style="display:flex; gap: 20px;">
                <div style="text-align:center;font-size:10px;color:var(--mu)">Signature Client (Décharge)<br><div style="width:120px;height:50px;border:0.5px dashed var(--br);border-radius:4px;margin-top:4px"></div></div>
                <div style="text-align:center;font-size:10px;color:var(--mu)">Livreur / Société<br><div style="width:120px;height:50px;border:0.5px dashed var(--br);border-radius:4px;margin-top:4px"></div></div>
            </div>
        </div>
        ` : `
        <div style="display:flex;justify-content:space-between;margin-bottom:20px;align-items:flex-start;">
            <div style="flex:1;">

            </div>
            <div style="width:240px">
                <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:12px;border-bottom:0.5px solid var(--border)"><span style="color:var(--mu)">Sous-total Brut HT</span><span>${fmtFcfa(c.tot_ht_brut)}</span></div>
                ${c.remise_totale > 0 ? `<div style="display:flex;justify-content:space-between;padding:6px 0;font-size:12px;border-bottom:0.5px solid var(--border);color:#dc2626;font-weight:600;"><span>Remise</span><span>-${fmtFcfa(c.remise_totale)}</span></div>` : ''}
                <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:12px;border-bottom:0.5px solid var(--border)"><span style="color:var(--mu)">Total Net HT</span><span>${fmtFcfa(c.tot_ht_net)}</span></div>
                <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:12px;border-bottom:0.5px solid var(--border)"><span style="color:var(--mu)">${libelleTotalTva(c)}</span><span>${fmtFcfa(c.tot_tva)}</span></div>
                <div style="display:flex;justify-content:space-between;padding:10px 0 6px;font-size:15px;font-weight:800;color:var(--tx);border-top:1.5px solid ${theme.color};"><span>TOTAL TTC</span><span>${fmtFcfa(c.tot_ttc)}</span></div>
                ${blocAutresTaxesDiv(c, theme.color)}
                ${d.etape === 'Facture' ? `
                <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:12px;border-bottom:0.5px solid var(--border)"><span style="color:var(--mu)">Montant Réglé</span><span>${fmtFcfa(d.deja_paye)}</span></div>
                <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:12px;color:${d.deja_paye < c.net_a_payer ? '#dc2626' : '#059669'};font-weight:700;"><span style="text-transform:uppercase;">Reste à payer</span><span>${fmtFcfa(Math.max(0, c.net_a_payer - d.deja_paye))}</span></div>
                ${blocMonnaieRendue(d)}
                ` : ''}
            </div>
        </div>
        ${blocCertificationFne(d)}
        ${blocMentions(d)}
        ${COMPANY.ref_bancaire ? `<div style="margin-bottom:14px;padding:10px 14px;background:var(--white);border-radius:7px;border:0.5px solid var(--border);font-size:11px;color:var(--mu);line-height:1.7;"><span style="font-weight:600;color:var(--tx)">Références bancaires : </span>${COMPANY.ref_bancaire}</div>` : ''}
        <div style="border-top:0.5px solid var(--border);padding-top:14px;display:flex;justify-content:space-between;align-items:flex-end">
            <div style="font-size:11px;color:var(--mu);line-height:1.7">Merci pour votre confiance.<br>Document généré par <strong>Selflow</strong> · selflow.app</div>
            <div style="text-align:right;font-size:11px;color:var(--mu)">Signature / Cachet<br><div style="width:100px;height:40px;border:0.5px dashed var(--border);border-radius:4px;margin-top:4px"></div></div>
        </div>
        ${blocPiedDePage(d)}
        `}
    </div>
</div>`;
}

function model2(d) {
    var c = calcItems(d.items, d.remise, d.taxes_ttc, d.timbre_fiscal);
    c.timbre_provenance = d.timbre_provenance;
    var theme = getThemeColors();
    var sColor = statusColor(d.statut, theme.color);
    var title = getDocTitle(d);
    var badge = getBadgeText(d);
    
    return `
<div class="invoice">
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

            <div style="margin-top:auto;padding-top:16px;border-top:1px solid rgba(255,255,255,.15)">
                <div style="font-size:9.5px;color:rgba(255,255,255,.5);margin-bottom:3px;text-transform:uppercase;letter-spacing:.5px">Vendeur</div>
                <div style="font-size:11px;color:rgba(255,255,255,.85);font-weight:600;">${COMPANY.vendeur || '—'}</div>
            </div>
        </div>
        <div style="flex:1;padding:28px 24px">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px">
                <div>
                    <div style="font-size:22px;font-weight:800;color:var(--tx)">${title}</div>
                    <div style="font-size:12px;color:var(--mu);margin-top:2px;font-weight:600;">N° ${isDeliveryMode ? (d.ref_bl || 'BL-EN-COURS') : d.num}</div>
                </div>
                <div style="padding:5px 14px;border-radius:20px;font-size:11px;font-weight:600;background:${theme.bg};color:${theme.tx}">${badge}</div>
            </div>
            <div style="margin-bottom:16px;padding:12px 14px;background:var(--white);border-radius:8px;border:0.5px solid var(--border)">
                <div style="font-size:10px;color:${theme.color};text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px;font-weight:700;">Client</div>
                <div style="font-size:13px;font-weight:700;color:var(--tx)">${d.client.nom}</div>
                <div style="font-size:11px;color:var(--mu);margin-top:4px;line-height:1.7;">${fiscalLinesClient(d.client, '<br>')}</div>
                <div style="display:flex;gap:14px;margin-top:10px;flex-wrap:wrap;">
                    <div style="font-size:11px"><span style="color:var(--mu)">Date : </span><span style="color:var(--tx);font-weight:600;">${d.date}</span></div>
                    ${isDeliveryMode ? '' : `
                    <div style="font-size:11px"><span style="color:var(--mu)">Paiement : </span><span style="color:var(--tx);font-weight:600;">${getFormattedMode(d)}</span></div>
                    <div style="font-size:11px"><span style="color:var(--mu)">Statut : </span><span style="font-weight:700;color:${sColor}">${d.statut}</span></div>
                    `}
                </div>
                ${d.ref_bl || d.ref_bc ? `
                <div style="margin-top:8px;padding-top:8px;border-top:0.5px solid var(--border);font-size:10.5px;color:var(--mu);line-height:1.8;">
                    ${d.ref_bc ? `<span style="font-weight:600;">Réf. commande : </span>${d.ref_bc}` : ''}
                    ${d.ref_bc && d.ref_bl ? ' &nbsp;·&nbsp; ' : ''}
                    ${d.ref_bl ? `<span style="font-weight:600;">Livré selon BL : </span>${d.ref_bl}` : ''}
                </div>` : ''}
            </div>
            ${getValiditeBlock(d)}${getAvoirBlock(d)}
            <table class="tbl-articles" style="border-collapse:collapse;font-size:11px;margin-bottom:14px">
                ${colgroupArticles()}
                <thead>
                    <tr style="border-bottom:1.5px solid ${theme.color}">
                        <th style="padding:7px 4px 7px 0;text-align:left;color:${theme.color};font-weight:700;">Réf.</th>
                        <th style="padding:7px 4px;text-align:left;color:${theme.color};font-weight:700">Article</th>
                        <th style="padding:7px 4px;text-align:center;color:${theme.color};font-weight:700;">Unité</th>
                        <th style="padding:7px 4px;text-align:right;color:${theme.color};font-weight:700;">Qté</th>
                        ${isDeliveryMode ? '' : `
                        <th style="padding:7px 4px;text-align:right;color:${theme.color};font-weight:700;">P.U. HT</th>
                        <th style="padding:7px 4px;text-align:right;color:${theme.color};font-weight:700;">Rem. (%)</th>
                        <th style="padding:7px 4px;text-align:right;color:${theme.color};font-weight:700;">Autres taxes</th>
                        <th style="padding:7px 4px;text-align:right;color:${theme.color};font-weight:700;">TVA</th>
                        <th style="padding:7px 4px;text-align:right;color:${theme.color};font-weight:700;">Montant HT</th>
                        <th style="padding:7px 0 7px 4px;text-align:right;color:${theme.color};font-weight:700;">TTC</th>
                        `}
                    </tr>
                </thead>
                <tbody>
                    ${c.rows.map(r => `<tr style="border-bottom:0.5px solid var(--border)">
                        <td class="col-ref" style="padding:8px 4px 8px 0;color:var(--mu);font-weight:500;">${r.ref}</td>
                        <td class="col-desc" style="padding:8px 4px;color:var(--tx);font-weight:600;">${r.desc}</td>
                        <td style="padding:8px 4px;text-align:center;color:var(--mu);white-space:nowrap;">${r.unite || 'Unité'}</td>
                        <td style="padding:8px 4px;text-align:right;color:var(--mu);white-space:nowrap;">${r.qty}</td>
                        ${isDeliveryMode ? '' : `
                        <td style="padding:8px 4px;text-align:right;color:var(--mu);white-space:nowrap;">${fmt(r.pu)}</td>
                        <td style="padding:8px 4px;text-align:right;color:${r.remise_taux > 0 ? '#dc2626' : 'var(--mu)'};white-space:nowrap;">${r.remise_taux || 0}%</td>
                        <td class="col-taxes" style="padding:8px 4px;text-align:right;color:var(--mu);font-size:10px;">${detailTaxesLigne(r)}</td>
                        <td style="padding:8px 4px;text-align:right;color:var(--mu);white-space:nowrap;">${r.tva}%</td>
                        <td style="padding:8px 4px;text-align:right;color:var(--tx);white-space:nowrap;">${fmt(r.ht)}</td>
                        <td style="padding:8px 0 8px 4px;text-align:right;font-weight:700;color:var(--tx);white-space:nowrap;">${fmt(r.ttc)}</td>
                        `}
                    </tr>`).join('')}
                </tbody>
            </table>
            
            ${isDeliveryMode ? `
            <div style="border-top:0.5px solid var(--border);padding-top:14px;margin-top:40px;display:flex;justify-content:space-between;align-items:flex-end">
                <div style="font-size:10px;color:var(--mu)">Généré automatiquement par <strong>Selflow</strong></div>
                <div style="display:flex; gap: 15px;">
                    <div style="text-align:center;font-size:9px;color:var(--mu)">Signature Client (Décharge)<br><div style="width:100px;height:45px;border:0.5px dashed var(--br);border-radius:4px;margin-top:3px"></div></div>
                    <div style="text-align:center;font-size:9px;color:var(--mu)">Livreur / Société<br><div style="width:100px;height:45px;border:0.5px dashed var(--br);border-radius:4px;margin-top:3px"></div></div>
                </div>
            </div>
            ` : `
            <div style="display:flex;justify-content:flex-end;margin-bottom:14px">
                <div style="width:220px;background:var(--white);border-radius:8px;padding:10px 12px;border:0.5px solid var(--border)">
                    <div style="display:flex;justify-content:space-between;font-size:11px;padding:4px 0"><span style="color:var(--mu)">Brut HT</span><span>${fmtFcfa(c.tot_ht_brut)}</span></div>
                    ${c.remise_totale > 0 ? `<div style="display:flex;justify-content:space-between;font-size:11px;padding:4px 0;color:#dc2626;font-weight:600;"><span>Remise</span><span>-${fmtFcfa(c.remise_totale)}</span></div>` : ''}
                    <div style="display:flex;justify-content:space-between;font-size:11px;padding:4px 0"><span style="color:var(--mu)">Net HT</span><span>${fmtFcfa(c.tot_ht_net)}</span></div>
                    <div style="display:flex;justify-content:space-between;font-size:11px;padding:4px 0"><span style="color:var(--mu)">${libelleTotalTva(c)}</span><span>${fmtFcfa(c.tot_tva)}</span></div>
                    <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:800;padding:6px 0 0;border-top:0.5px solid var(--border);margin-top:4px"><span style="color:var(--tx)">Total TTC</span><span style="color:${theme.color}">${fmtFcfa(c.tot_ttc)}</span></div>
                    ${blocAutresTaxesDiv(c, theme.color)}
                    ${d.etape === 'Facture' ? `
                    <div style="display:flex;justify-content:space-between;font-size:11px;padding:4px 0;border-top:0.5px solid var(--border);margin-top:4px"><span style="color:var(--mu)">Montant Réglé</span><span>${fmtFcfa(d.deja_paye)}</span></div>
                    <div style="display:flex;justify-content:space-between;font-size:11px;padding:4px 0;font-weight:700;color:${d.deja_paye < c.net_a_payer ? '#dc2626' : '#059669'}"><span>Reste à payer</span><span>${fmtFcfa(Math.max(0, c.net_a_payer - d.deja_paye))}</span></div>
                    ${blocMonnaieRendue(d, true)}
                    ` : ''}
                </div>
            </div>
            ${COMPANY.ref_bancaire ? `<div style="font-size:10.5px;color:var(--mu);line-height:1.7;margin-bottom:10px;"><strong>Réf. bancaires : </strong>${COMPANY.ref_bancaire}</div>` : ''}
            <div style="font-size:11px;color:var(--mu)">Généré automatiquement par <strong>Selflow</strong></div>
            ${blocCertificationFne(d)}
            ${blocMentions(d)}
            ${blocPiedDePage(d)}
            `}
        </div>
    </div>
</div>`;
}

function model3(d) {
    var c = calcItems(d.items, d.remise, d.taxes_ttc, d.timbre_fiscal);
    c.timbre_provenance = d.timbre_provenance;
    var theme = getThemeColors();
    var sColor = statusColor(d.statut, theme.color);
    var title = getDocTitle(d);
    var badge = getBadgeText(d);
    
    return `
<div class="invoice">
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
                <div style="font-size:12px;margin-top:2px"><span style="color:var(--mu)">N° </span><span style="font-weight:700;color:${theme.color}">${isDeliveryMode ? (d.ref_bl || 'BL-EN-COURS') : d.num}</span></div>
                <div style="display:inline-block;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:600;background:${theme.bg};color:${theme.tx};margin-top:6px">${badge}</div>
            </div>
        </div>

        <div style="height:1px;background:var(--border);margin-bottom:18px"></div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px">
            <div style="padding:12px 14px;background:var(--white);border-radius:8px;border:0.5px solid var(--border)">
                <div style="font-size:10px;color:${theme.color};font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px">Client</div>
                <div style="font-size:13px;font-weight:700;color:var(--tx)">${d.client.nom}</div>
                <div style="font-size:11px;color:var(--mu);margin-top:4px;line-height:1.7">${fiscalLinesClient(d.client, '<br>')}</div>
            </div>
            <div style="padding:12px 14px;background:var(--white);border-radius:8px;border:0.5px solid var(--border)">
                <div style="font-size:10px;color:${theme.color};font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px">Détails</div>
                <div style="font-size:11px;line-height:1.9">
                    <span style="color:var(--mu)">Date : </span><span style="color:var(--tx);font-weight:600;">${d.date}</span><br>
                    ${isDeliveryMode ? '' : `<span style="color:var(--mu)">Paiement : </span><span style="color:var(--tx);font-weight:600;">${getFormattedMode(d)}</span><br>`}
                    <span style="color:var(--mu)">Vendeur : </span><span style="color:var(--tx);font-weight:600;">${COMPANY.vendeur || '—'}</span><br>
                    ${isDeliveryMode ? '' : `<span style="color:var(--mu)">Statut : </span><span style="font-weight:700;color:${sColor}">${d.statut}</span>`}
                    ${d.ref_bc ? `<br><span style="color:var(--mu)">Réf. commande : </span><span style="color:var(--tx);font-weight:600;">${d.ref_bc}</span>` : ''}
                    ${d.ref_bl ? `<br><span style="color:var(--mu)">Livré selon BL : </span><span style="color:var(--tx);font-weight:600;">${d.ref_bl}</span>` : ''}
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

        ${getValiditeBlock(d)}${getAvoirBlock(d)}
        <table class="tbl-articles" style="border-collapse:collapse;font-size:11px;margin-bottom:16px">
            ${colgroupArticles()}
            <thead>
                <tr>
                    <th style="padding:9px 5px;text-align:left;border-bottom:2px solid ${theme.color};color:var(--tx);font-weight:700;">Réf.</th>
                    <th style="padding:9px 5px;text-align:left;border-bottom:2px solid ${theme.color};color:var(--tx);font-weight:700;">Article</th>
                    <th style="padding:9px 5px;text-align:center;border-bottom:2px solid ${theme.color};color:var(--tx);font-weight:700;">Unité</th>
                    <th style="padding:9px 5px;text-align:right;border-bottom:2px solid ${theme.color};color:var(--tx);font-weight:700;">Qté</th>
                    ${isDeliveryMode ? '' : `
                    <th style="padding:9px 5px;text-align:right;border-bottom:2px solid ${theme.color};color:var(--tx);font-weight:700;">P.U. HT</th>
                    <th style="padding:9px 5px;text-align:right;border-bottom:2px solid ${theme.color};color:var(--tx);font-weight:700;">Rem. (%)</th>
                    <th style="padding:9px 5px;text-align:right;border-bottom:2px solid ${theme.color};color:var(--tx);font-weight:700;">Autres taxes</th>
                    <th style="padding:9px 5px;text-align:right;border-bottom:2px solid ${theme.color};color:var(--tx);font-weight:700;">TVA</th>
                    <th style="padding:9px 5px;text-align:right;border-bottom:2px solid ${theme.color};color:var(--tx);font-weight:700;">Montant HT</th>
                    <th style="padding:9px 5px;text-align:right;border-bottom:2px solid ${theme.color};color:var(--tx);font-weight:700;">TTC</th>
                    `}
                </tr>
            </thead>
            <tbody>
                ${c.rows.map((r, i) => `<tr style="background:${i % 2 === 1 ? '#F9FAFB' : '#fff'}">
                    <td class="col-ref" style="padding:9px 5px;color:var(--mu);font-size:10px;font-weight:500;">${r.ref}</td>
                    <td class="col-desc" style="padding:9px 5px;font-weight:600;color:var(--tx)">${r.desc}</td>
                    <td style="padding:9px 5px;text-align:center;color:var(--tx);white-space:nowrap;">${r.unite || 'Unité'}</td>
                    <td style="padding:9px 5px;text-align:right;color:var(--tx);white-space:nowrap;">${r.qty}</td>
                    ${isDeliveryMode ? '' : `
                    <td style="padding:9px 5px;text-align:right;color:var(--mu);white-space:nowrap;">${fmt(r.pu)}</td>
                    <td style="padding:9px 5px;text-align:right;color:${r.remise_taux > 0 ? '#dc2626' : 'var(--mu)'};white-space:nowrap;">${r.remise_taux || 0}%</td>
                    <td class="col-taxes" style="padding:9px 5px;text-align:right;color:var(--mu);font-size:10px;">${detailTaxesLigne(r)}</td>
                    <td style="padding:9px 5px;text-align:right;color:var(--mu);white-space:nowrap;">${r.tva}%</td>
                    <td style="padding:9px 5px;text-align:right;color:var(--tx);white-space:nowrap;">${fmt(r.ht)}</td>
                    <td style="padding:9px 5px;text-align:right;font-weight:700;color:var(--tx);white-space:nowrap;">${fmt(r.ttc)}</td>
                    `}
                </tr>`).join('')}
            </tbody>
        </table>
        
        ${isDeliveryMode ? `
        <div style="border-top:0.5px solid var(--border);padding-top:12px;margin-top:40px;display:flex;justify-content:space-between;align-items:center">
            <div style="font-size:11px;color:var(--mu)">Généré par <strong>Selflow</strong> · selflow.app · Document officiel</div>
            <div style="display:flex;gap:16px">
                <div style="text-align:center;font-size:10px;color:var(--mu)">Signature client<br><div style="width:110px;height:45px;border:0.5px dashed var(--br);border-radius:0;margin-top:3px"></div></div>
                <div style="text-align:center;font-size:10px;color:var(--mu)">Livreur / Cachet<br><div style="width:110px;height:45px;border:0.5px dashed var(--br);border-radius:0;margin-top:3px"></div></div>
            </div>
        </div>
        ` : `
        <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:20px">
            <div style="font-size:11px;color:var(--mu);max-width:320px;line-height:1.7">
                <span style="color:var(--tx);font-weight:700">Émetteur :</span><br>
                ${COMPANY.adresse ? COMPANY.adresse + '<br>' : ''}
                ${COMPANY.tel ? 'Tél : ' + COMPANY.tel : ''}${COMPANY.email ? ' · ' + COMPANY.email : ''}<br>
                ${COMPANY.ref_bancaire ? '<strong>Réf. bancaires : </strong>' + COMPANY.ref_bancaire : ''}

            </div>
            <div style="width:235px">
                <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:12px;border-bottom:0.5px solid var(--border)"><span style="color:var(--mu)">Sous-total Brut HT</span><span>${fmtFcfa(c.tot_ht_brut)}</span></div>
                ${c.remise_totale > 0 ? `<div style="display:flex;justify-content:space-between;padding:4px 0;font-size:12px;border-bottom:0.5px solid var(--border);color:#dc2626;font-weight:600;"><span>Remise</span><span>-${fmtFcfa(c.remise_totale)}</span></div>` : ''}
                <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:12px;border-bottom:0.5px solid var(--border)"><span style="color:var(--mu)">Total Net HT</span><span>${fmtFcfa(c.tot_ht_net)}</span></div>
                <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:12px;border-bottom:0.5px solid var(--border)"><span style="color:var(--mu)">${libelleTotalTva(c)}</span><span>${fmtFcfa(c.tot_tva)}</span></div>
                <div style="display:flex;justify-content:space-between;padding:8px;margin-top:4px;background:${theme.color};border-radius:6px">
                    <span style="font-size:13px;font-weight:700;color:#fff">Total TTC</span>
                    <span style="font-size:15px;font-weight:800;color:#fff">${fmtFcfa(c.tot_ttc)}</span>
                </div>
                ${blocAutresTaxesDiv(c, theme.color)}
                ${d.etape === 'Facture' ? `
                <div style="display:flex;justify-content:space-between;padding:6px 8px;font-size:12px;border-bottom:0.5px solid var(--border)"><span style="color:var(--mu)">Montant Réglé</span><span style="font-weight:600;">${fmtFcfa(d.deja_paye)}</span></div>
                <div style="display:flex;justify-content:space-between;padding:6px 8px;font-size:12px;color:${d.deja_paye < c.net_a_payer ? '#dc2626' : '#059669'};font-weight:700;"><span>Reste à payer</span><span>${fmtFcfa(Math.max(0, c.net_a_payer - d.deja_paye))}</span></div>
                ${blocMonnaieRendue(d)}
                ` : ''}
            </div>
        </div>
        <div style="border-top:0.5px solid var(--border);padding-top:12px;display:flex;justify-content:space-between;align-items:center">
            <div style="font-size:11px;color:var(--mu)">Généré par <strong>Selflow</strong> · selflow.app · Document officiel</div>
            <div style="display:flex;gap:16px">
                <div style="text-align:center;font-size:10px;color:var(--mu)">Signature client<br><div style="width:80px;height:36px;border:0.5px dashed var(--border);border-radius:4px;margin-top:3px"></div></div>
                <div style="text-align:center;font-size:10px;color:var(--mu)">Cachet société<br><div style="width:80px;height:36px;border:0.5px dashed var(--border);border-radius:4px;margin-top:3px"></div></div>
            </div>
        </div>
        ${blocCertificationFne(d)}
        ${blocMentions(d)}
        ${blocPiedDePage(d)}
        `}
    </div>
</div>`;
}

function modelStandard(d) {
    var c = calcItems(d.items, d.remise, d.taxes_ttc, d.timbre_fiscal);
    c.timbre_provenance = d.timbre_provenance;
    var isNorm = d.normalise;
    var title = getDocTitle(d);
    var hasTva = d.items.some(r => r.tva > 0);
    
    // Contenu des lignes de la table
    var rowsHtml = '';
    if (isDeliveryMode) {
        rowsHtml = c.rows.map(r => `
            <tr style="background:#fff; color:#000;">
                <td class="col-ref" style="padding:8px 6px; border:1px solid #000; font-weight:500;">${r.ref}</td>
                <td class="col-desc" style="padding:8px 6px; border:1px solid #000; font-weight:700;">${r.desc}</td>
                <td style="padding:8px 10px; border:1px solid #000; text-align:right; white-space:nowrap;">${r.qty}</td>
                <td style="padding:8px 10px; border:1px solid #000; text-align:center; white-space:nowrap;">${r.unite || 'Unité'}</td>
            </tr>
        `).join('');
    } else {
        rowsHtml = c.rows.map(r => `
            <tr style="background:#fff; color:#000;">
                <td class="col-ref" style="padding:8px 6px; border:1px solid #000; font-weight:500;">${r.ref}</td>
                <td class="col-desc" style="padding:8px 6px; border:1px solid #000; font-weight:700;">${r.desc}</td>
                <td style="padding:8px 10px; border:1px solid #000; text-align:right; white-space:nowrap;">${fmt(r.pu)}${r.remise_taux > 0 ? `<br><span style="color:#dc2626;font-size:10px;">Remise ${r.remise_taux}%</span>` : ''}</td>
                <td style="padding:8px 10px; border:1px solid #000; text-align:right; white-space:nowrap;">${r.qty}</td>
                <td style="padding:8px 10px; border:1px solid #000; text-align:center; white-space:nowrap;">${r.unite || 'Unité'}</td>
                <td style="padding:8px 10px; border:1px solid #000; text-align:right; color:#000;">${r.code_tva || 'TVA'} (${r.tva}%)${(r.taxes || []).length ? '<br>' + detailTaxesLigne(r) : ''}</td>
                <td style="padding:8px 10px; border:1px solid #000; text-align:right; color:#000; white-space:nowrap;">${r.remise_taux > 0 ? r.remise_taux + '%' : '0%'}</td>
                <td style="padding:8px 10px; border:1px solid #000; text-align:right; font-weight:700; white-space:nowrap;">${fmt(r.ht)}</td>
            </tr>
        `).join('');
        
        // Ajouter les totaux fusionnés au tableau principal
        rowsHtml += `
            <tr style="background:#fff; color:#000;">
                <td colspan="7" style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:700; text-transform:uppercase;">TOTAL HT</td>
                <td style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:700; white-space:nowrap;">${fmt(c.tot_ht_brut)}</td>
            </tr>
            ${c.remise_totale > 0 ? `
            <tr style="background:#fff; color:#000;">
                <td colspan="7" style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:700; text-transform:uppercase; color:#dc2626;">Remise</td>
                <td style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:700; color:#dc2626; white-space:nowrap;">-${fmt(c.remise_totale)}</td>
            </tr>
            <tr style="background:#fff; color:#000;">
                <td colspan="7" style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:700; text-transform:uppercase;">TOTAL HT NET</td>
                <td style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:700; white-space:nowrap;">${fmt(c.tot_ht_net)}</td>
            </tr>
            ` : ''}
            <tr style="background:#fff; color:#000;">
                <td colspan="7" style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:700; text-transform:uppercase;">TVA</td>
                <td style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:700; white-space:nowrap;">${fmt(c.tot_tva)}</td>
            </tr>
            <tr style="background:#fff; color:#000;">
                <td colspan="7" style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:700; text-transform:uppercase;">TOTAL TTC</td>
                <td style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:700; white-space:nowrap;">${fmt(c.tot_ttc)}</td>
            </tr>
            <tr style="background:#fff; color:#000;">
                <td colspan="7" style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:700; text-transform:uppercase;">AUTRES TAXES</td>
                <td style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:700; white-space:nowrap;">0 F</td>
            </tr>
            ${d.etape === 'Facture' ? `
            <tr style="background:#fff; color:#000;">
                <td colspan="7" style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:900; text-transform:uppercase;">TOTAL A PAYER</td>
                <td style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:900; white-space:nowrap;">${fmt(c.tot_ttc)}</td>
            </tr>
            ${blocAutresTaxesTr(c, 7)}
            <tr style="background:#fff; color:#000;">
                <td colspan="7" style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:700; text-transform:uppercase;">MONTANT REGLE</td>
                <td style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:700; white-space:nowrap;">${fmt(d.deja_paye)}</td>
            </tr>
            <tr style="background:#fff; color:#000;">
                <td colspan="7" style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:900; text-transform:uppercase; color:${d.deja_paye < c.net_a_payer ? '#dc2626' : '#000'};">RESTE A PAYER</td>
                <td style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:900; color:${d.deja_paye < c.net_a_payer ? '#dc2626' : '#000'}; white-space:nowrap;">${fmt(Math.max(0, c.net_a_payer - d.deja_paye))}</td>
            </tr>
            ${d.monnaie_rendue > 0 ? `
            <tr style="background:#fff; color:#000;">
                <td colspan="7" style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:700; text-transform:uppercase;">RECU</td>
                <td style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:700; white-space:nowrap;">${fmt(d.montant_recu)}</td>
            </tr>
            <tr style="background:#fff; color:#000;">
                <td colspan="7" style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:900; text-transform:uppercase; color:#15803d;">MONNAIE RENDUE</td>
                <td style="padding:6px 10px; border:1px solid #000; text-align:right; font-weight:900; color:#15803d; white-space:nowrap;">${fmt(d.monnaie_rendue)}</td>
            </tr>
            ` : ''}
            ` : ''}
        `;
    }

    return `
<div class="invoice" style="border: 1px solid #000; border-radius: 0; background: #fff; box-shadow: none;">
    <div style="padding:40px; color:#000; font-family: 'Inter', sans-serif;">
        <!-- En-tête -->
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px;">
            <!-- Gauche : Infos Vendeur -->
            <div style="flex:1; font-size:11px; line-height:1.6; color:#000; padding-right: 20px;">
                <div style="border: 1.5px solid #000; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                    <div style="font-size:14px; font-weight:800; text-transform:uppercase; margin-bottom:4px;">${COMPANY.nom}</div>
                    <div>NCC : <strong>${COMPANY.ncc || '—'}</strong></div>
                    <div>Compte contribuable : <strong>${COMPANY.ncc || '—'}</strong></div>
                    <div>Régime d'imposition : <strong>${COMPANY.regime || '—'}</strong></div>
                    <div>Centre des impôts : <strong>${COMPANY.centre_impots || '—'}</strong></div>
                </div>
                
                <div style="margin-top:10px;">
                    RCCM : <strong>${COMPANY.rccm || '—'}</strong><br>
                    <strong>Références bancaires :</strong><br>
                    ${COMPANY.ref_bancaire || '—'}<br>
                    Adresse : ${COMPANY.adresse || '—'}<br>
                    Nº Tel : ${COMPANY.tel || '—'}<br>
                    Mail : ${COMPANY.email || '—'}
                </div>
                
                <div style="margin-top:10px; border-top:1px dashed #000; padding-top:6px;">
                    Nom du vendeur : <strong>${COMPANY.vendeur || '—'}</strong><br>
                    Nom de PDV : <strong>${COMPANY.pdv}</strong><br>
                    Date et heure : <strong>${d.date}</strong><br>
                    ${isDeliveryMode ? `N° Commande : <strong>${d.ref_bc}</strong>` : `Mode de paiement : <strong>${getFormattedMode(d)}</strong>`}
                </div>
            </div>
            
            <!-- Droite : Logo FNE, Logo Entreprise & Infos Document -->
            <div style="text-align:right; flex-shrink:0; width:280px; display:flex; flex-direction:column; align-items:flex-end;">
                <!-- Logo Entreprise -->
                <div style="height:60px; margin-bottom:15px; display:flex; align-items:center; justify-content:flex-end;">
                    ${COMPANY.logo ? `<img src="${COMPANY.logo}" alt="${COMPANY.nom}" style="max-height:100%; object-fit:contain;">` : `<div style="font-size:16px; font-weight:800; text-transform:uppercase; border:1px solid #000; padding:8px 12px;">${COMPANY.nom}</div>`}
                </div>
                
                <div style="font-size:16px; font-weight:800; letter-spacing:0.5px; color:#000; text-transform:uppercase; margin-bottom:4px;">${title}</div>
                <div style="font-size:12px; font-weight:700; color:#000;">N° ${isDeliveryMode ? (d.ref_bl || 'BL-EN-COURS') : d.num}</div>
                ${isDeliveryMode ? '' : `<div style="font-size:11px; color:#333; margin-top:2px;">Statut : <span style="font-weight:700; text-transform:uppercase;">${d.statut}</span></div>`}
                

            </div>
        </div>
        
        <div style="border-bottom:1.5px solid #000; margin-bottom:20px;"></div>
        
        <!-- Section Client (Sans bordures, comme le modèle original) -->
        <div style="font-size:11px; line-height:1.6; color:#000; margin-bottom:25px; border-top:1.5px solid #000; padding-top:10px;">
            <div style="font-size:11px; font-weight:800; text-transform:uppercase; color:#000; margin-bottom:5px; letter-spacing:0.5px;">CLIENT</div>
            <div>Nom : <strong>${d.client.nom}</strong></div>
            <div>Adresse : <strong>${d.client.adresse || '—'}</strong></div>
            <div>Nº Tel : <strong>${d.client.tel || '—'}</strong></div>
            <div>Mail : <strong>${d.client.email || '—'}</strong></div>
            <div>NCC : <strong>${d.client.ncc || '—'}</strong></div>
            <div>Régime d'imposition : <strong>${d.client.regime || '—'}</strong></div>
            <div>Type de facturation : <strong>${d.client.template || '—'}</strong></div>
        </div>
        
        ${getValiditeBlock(d)}${getAvoirBlock(d)}
        <!-- Tableau des Articles -->
        <table class="table-m4 tbl-articles">
            ${colgroupArticlesM4()}
            <thead>
                ${isDeliveryMode ? `
                <tr style="background:#000; color:#fff; text-transform:uppercase;">
                    <th style="text-align:left; font-weight:700;">Réf.</th>
                    <th style="text-align:left; font-weight:700;">Désignation</th>
                    <th style="text-align:right; font-weight:700;">Qté</th>
                    <th style="text-align:center; font-weight:700;">Unité</th>
                </tr>
                ` : `
                <tr style="background:#000; color:#fff; text-transform:uppercase;">
                    <th style="text-align:left; font-weight:700;">Réf.</th>
                    <th style="text-align:left; font-weight:700;">Désignation</th>
                    <th style="text-align:right; font-weight:700;">P.U. HT</th>
                    <th style="text-align:right; font-weight:700;">Qté</th>
                    <th style="text-align:center; font-weight:700;">Unité</th>
                    <th style="text-align:right; font-weight:700;">Taxes (%)</th>
                    <th style="text-align:right; font-weight:700;">Rem. (%)</th>
                    <th style="text-align:right; font-weight:700;">Montant HT</th>
                </tr>
                `}
            </thead>
            <tbody>
                ${rowsHtml}
            </tbody>
        </table>
        
        ${isDeliveryMode ? `
        <!-- Pied de page pour Bon de livraison -->
        <div style="border-top:1px solid #000; padding-top:25px; margin-top:50px; display:flex; justify-content:space-between; align-items:flex-end;">
            <div style="font-size:9px; color:#444; line-height:1.6; font-weight:500; max-width:70%;">
                Document officiel généré par <strong>Selflow</strong>.
            </div>
            <div style="display:flex; gap: 30px;">
                <div style="text-align:center;">
                    <div style="font-size:9px; font-weight:700; text-transform:uppercase; color:#333; margin-bottom:5px;">Livreur / Société</div>
                    <div style="width:120px; height:50px; border:1px dashed #000; border-radius:0; background:#fff;"></div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:9px; font-weight:700; text-transform:uppercase; color:#333; margin-bottom:5px;">Décharge Client</div>
                    <div style="width:120px; height:50px; border:1px dashed #000; border-radius:0; background:#fff;"></div>
                </div>
            </div>
        </div>
        ` : `
        <!-- Résumé fiscal de la facture & Signature -->
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-top: 25px;">
            <!-- Résumé fiscal à gauche -->
            <div style="flex:1; margin-right:30px;">
                <div style="font-size:10px; font-weight:700; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.5px;">RÉSUMÉ DE LA FACTURE</div>
                <table style="width:100%; border-collapse:collapse; font-size:10px; border:1px solid #000;">
                    <thead>
                        <tr style="background:#fff; border-bottom:1px solid #000;">
                            <th style="padding:5px 8px; border:1px solid #000; text-align:left;">CATEGORIE</th>
                            <th style="padding:5px 8px; border:1px solid #000; text-align:right;">SOUS-TOTAL</th>
                            <th style="padding:5px 8px; border:1px solid #000; text-align:right;">TAUX (%)</th>
                            <th style="padding:5px 8px; border:1px solid #000; text-align:right;">TOTAL TAXES</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${ventilerParCodeTva(c).map(g => `
                        <tr>
                            <td style="padding:5px 8px; border:1px solid #000;">${LIBELLES_CODE_TVA[g.code] || g.code} — ${g.code}</td>
                            <td style="padding:5px 8px; border:1px solid #000; text-align:right;">${fmtFcfa(g.base)}</td>
                            <td style="padding:5px 8px; border:1px solid #000; text-align:right;">${Number(g.taux).toFixed(2).replace('.', ',')}%</td>
                            <td style="padding:5px 8px; border:1px solid #000; text-align:right;">${fmtFcfa(g.taxe)}</td>
                        </tr>`).join('')}
                        ${c.lignes_autres_taxes.map(t => `
                        <tr>
                            <td style="padding:5px 8px; border:1px solid #000;">${t.nom}</td>
                            <td style="padding:5px 8px; border:1px solid #000; text-align:right;">—</td>
                            <td style="padding:5px 8px; border:1px solid #000; text-align:right;">—</td>
                            <td style="padding:5px 8px; border:1px solid #000; text-align:right;">${fmtFcfa(t.montant)}</td>
                        </tr>`).join('')}
                        ${c.timbre > 0 ? `
                        <tr>
                            <td style="padding:5px 8px; border:1px solid #000;">${libelleTimbre(c)}</td>
                            <td style="padding:5px 8px; border:1px solid #000; text-align:right;">—</td>
                            <td style="padding:5px 8px; border:1px solid #000; text-align:right;">—</td>
                            <td style="padding:5px 8px; border:1px solid #000; text-align:right;">${fmtFcfa(c.timbre)}</td>
                        </tr>` : ''}
                    </tbody>
                </table>
            </div>
            
            <!-- Cachet et signature à droite -->
            <div style="text-align:center; width:200px;">
                <div style="font-size:9px; font-weight:700; text-transform:uppercase; color:#333; margin-bottom:5px;">Cachet & Signature</div>
                <div style="width:140px; height:60px; border:1px dashed #000; border-radius:0; background:#fff; display:inline-block;"></div>
            </div>
        </div>
        
        ${blocCertificationFne(d)}

        <!-- Pied de page : mentions de l'entreprise emettrice -->
        <div style="border-top:1px solid #000; padding-top:15px; margin-top:20px; font-size:9px; color:#444; line-height:1.6; font-weight:500; text-align:center;">
            ${[
                COMPANY.nom,
                COMPANY.adresse,
                COMPANY.rccm ? 'RCCM N&deg; ' + COMPANY.rccm : '',
                COMPANY.ncc ? 'NCC : ' + COMPANY.ncc : '',
                COMPANY.tel ? 'Tel : ' + COMPANY.tel : '',
                COMPANY.email ? 'email : ' + COMPANY.email : ''
            ].filter(Boolean).join(', ')}
            ${d.autres_mentions ? `<div style="margin-top:6px;">${d.autres_mentions}</div>` : ''}
            ${d.pied_de_page ? `<div style="margin-top:6px;">${d.pied_de_page}</div>` : ''}
        </div>
        `}
    </div>
</div>
    `;
}

function render() {
    var d = DATA['vente'];
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
localStorage.removeItem('selflow_vente_panier');

// Auto-download if ?download=1 is in URL
const urlParams = new URLSearchParams(window.location.search);
if (urlParams.get('download') === '1') {
    setTimeout(() => {
        telechargerPdf();
    }, 800);
}
if (urlParams.get('facturer') === '1' && document.getElementById('modal-facturer')) {
    document.getElementById('modal-facturer').style.display = 'flex';
}

function ouvrirModalAvoir() {
    document.getElementById('modalAvoir').style.display = 'flex';
}
function fermerModalAvoir() {
    document.getElementById('modalAvoir').style.display = 'none';
}

function selectionnerMode(mode) {
    ['Caisse', 'Banque', 'Crédit'].forEach(m => {
        const label = document.getElementById('label-mode-' + m.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, ""));
        if (label) {
            label.style.border = '1px solid #e2e5ec';
            label.style.background = '#fff';
            label.querySelector('span').style.color = '#475569';
        }
    });

    const actLabel = document.getElementById('label-mode-' + mode.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, ""));
    if (actLabel) {
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
}

function majBaseFacturation() {
    const rLivree = document.getElementById('r-livree');
    if (rLivree) {
        const isLivree = rLivree.checked;
        document.getElementById('label-livree').style.borderColor = isLivree ? '#047857' : '#e2e5ec';
        document.getElementById('label-livree').style.background = isLivree ? '#f0fdf4' : '#fff';
        document.getElementById('label-commandee').style.borderColor = !isLivree ? '#047857' : '#e2e5ec';
        document.getElementById('label-commandee').style.background = !isLivree ? '#f0fdf4' : '#fff';
    }
}

@if(isset($bl))
document.addEventListener('DOMContentLoaded', function() {
    majBaseFacturation();
    selectionnerMode('Caisse');
});
@endif
</script>
@endsection

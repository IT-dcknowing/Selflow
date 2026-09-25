@extends('admin::gabarits.application')
@section('titre', 'Nouveau bon d\'achat')
@section('topbar_titre', 'Achats — Nouveau')

@section('styles')
<style>
    .achat-grid { display: grid; grid-template-columns: 1fr 360px; gap: 22px; align-items: start; }
    #lignesAchat .ligne { display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 10px; align-items: end; margin-bottom: 10px; }
    .btn-remove-ligne {
        background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3);
        color: #fca5a5; border-radius: 8px; padding: 10px 14px; cursor: pointer;
        transition: all .12s; font-size: 14px;
    }
    .btn-remove-ligne:hover { background: var(--danger); color: #fff; }
    .sous-total-row { text-align: right; font-size: 13px; color: var(--text-3); margin-top: 4px; }
    
    .payment-toggle-btn {
        border: 1px solid var(--border);
        background: #fff;
        color: var(--text-2);
        font-weight: 600;
        cursor: pointer;
        transition: all 0.15s;
    }
    .payment-toggle-btn.active {
        background: #002B5C !important;
        color: #ffffff !important;
        border-color: #002B5C !important;
    }
</style>
@endsection

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-truck-loading"></i> Nouvel achat fournisseur</h1>
        <p>Enregistrez un bon de commande / réception de marchandises</p>
    </div>
    <a href="{{ route('admin.achats.factures') }}" class="btn btn-outline">
        <i class="fas fa-list"></i> Voir les achats
    </a>
</div>

<form method="POST" action="{{ route('admin.achats.enregistrer') }}" id="formAchat">
@csrf
<div class="achat-grid">

    <div>
        <div class="card">
            <div class="card-header"><h2><i class="fas fa-list-check"></i> Articles achetés</h2></div>
            <div class="card-body">
                <div id="lignesAchat"></div>
                <button type="button" class="btn btn-outline" onclick="ajouterLigne()" style="margin-top:8px;">
                    <i class="fas fa-plus"></i> Ajouter une ligne
                </button>
            </div>
        </div>
    </div>

    <div>
        <div class="card" style="position:sticky; top:calc(var(--topbar-h) + 16px);">
            <div class="card-header"><h2><i class="fas fa-file-invoice"></i> Informations</h2></div>
            <div class="card-body">
                {{-- Ce qu'on enregistre, choisi d'abord. Ces deux boutons étaient
                     en bas de la colonne, sous les totaux, dans un encadré
                     pointillé : on découvrait après avoir tout saisi qu'on
                     n'avait pas dit de quelle pièce il s'agissait. Ils prennent
                     la place et la forme des étapes, puisqu'ils décident de la
                     même chose — ce que la saisie va produire. --}}
                <div class="form-group">
                    <label class="form-label">Nature de l'achat</label>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                        <button type="button" class="btn payment-toggle-btn" id="btnFacturePhysique" onclick="toggleFacturePhysique()"
                                style="justify-content:center; font-size:12px; padding:8px 6px; text-align:center;">
                            <i class="fas fa-file-invoice" id="iconFacturePhysique"></i>
                            <span id="labelFacturePhysique">Facture physique fournisseur</span>
                        </button>
                        <button type="button" class="btn payment-toggle-btn" id="btnBapa" onclick="toggleBapa()"
                                style="justify-content:center; font-size:12px; padding:8px 6px; text-align:center;">
                            <i class="fas fa-file-invoice" id="iconBapa"></i>
                            <span id="labelBapa">BAPA (DGI)</span>
                        </button>
                    </div>
                    <small style="color:var(--text-3); font-size:11px;" id="aideNatureAchat">
                        Sans choix, l'achat reste une demande de prix ou un bon de commande.
                        <strong>Seul le bordereau (BAPA) se normalise auprès de la DGI</strong> :
                        une facture fournisseur est certifiée par son émetteur, pas par vous.
                    </small>
                </div>

                <div class="form-group" id="groupeFournisseurSelect">
                    <label class="form-label">Fournisseur <span style="color:var(--danger)">*</span></label>
                    <select name="fournisseur_id" id="fournisseurSelect" class="form-control" required onchange="basculerB2b()">
                        <option value="">— Choisir un fournisseur —</option>
                        @foreach($fournisseurs as $f)
                        <option value="{{ $f->id }}">{{ $f->nom }}</option>
                        @endforeach
                    </select>
                </div>
                {{-- Champ BAPA : nom libre du vendeur non déclaré (tiers) --}}
                <div class="form-group" id="groupeFournisseurBapa" style="display:none;">
                    <label class="form-label">
                        <i class="fas fa-user-slash" style="color:var(--danger);"></i>
                        Nom du vendeur <span style="color:var(--text-3); font-weight:400; font-size:11px;">(Tiers non immatriculé — sans NCC)</span>
                        <span style="color:var(--danger)">*</span>
                    </label>
                    <input type="text" name="fournisseur_nom_bapa" id="fournisseurNomBapa" class="form-control"
                           placeholder="Ex: KONAN Kofi, Marché de Treichville..."
                           style="border-color: var(--danger);">
                    <div style="font-size:11px; color:var(--text-3); margin-top:4px;">
                        <i class="fas fa-info-circle"></i> Ce vendeur sera déclaré comme «&nbsp;tiers non identifié&nbsp;» auprès de la DGI.
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Date d'achat <span style="color:var(--danger)">*</span></label>
                    <input type="date" name="date_achat" class="form-control" value="{{ date('Y-m-d') }}" required>
                </div>



                {{-- Étape du document - Lot F. Masqué dès que le bordereau est
                     choisi : un BAPA n'est ni une demande de prix ni un bon de
                     commande, c'est une pièce qu'on établit et qu'on normalise
                     d'un coup. --}}
                <div class="form-group" id="blocTypeDocument">
                    <label class="form-label">Type de document</label>
                    <input type="hidden" name="etape" id="etapeInput" value="Bon de commande">
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:8px;">
                        <button type="button" class="btn payment-toggle-btn" data-etape="Demande de prix" onclick="selectionnerEtape(this)" style="justify-content:center; font-size:12px; padding:8px 6px;">
                            <i class="fas fa-file-invoice"></i> Demande de prix
                        </button>
                        <button type="button" class="btn payment-toggle-btn active" data-etape="Bon de commande" onclick="selectionnerEtape(this)" style="justify-content:center; font-size:12px; padding:8px 6px;">
                            <i class="fas fa-shopping-basket"></i> Bon de commande
                        </button>
                    </div>
                </div>

                {{-- Le détail de la pièce. Les deux boutons qui l'ouvraient sont
                     passés en tête de la colonne, avec les autres décisions. --}}
                <div style="border:1.5px dashed var(--border); border-radius:8px; padding:12px; margin-bottom:14px; background:#fafafa; display:none;" id="cadreFacturePhysique">
                    <input type="hidden" name="type_facture" id="typeFactureInput" value="normale">
                    <div id="blocFacturePhysique" style="display:none; margin-top:14px;">
                        {{-- Champ etape overridé quand facture physique --}}
                        <div class="form-group" style="margin-bottom:10px;">
                            <label class="form-label" style="font-size:12px; color:var(--text-2);">N° Facture fournisseur (papier)</label>
                            <input type="text" name="numero_facture_fournisseur" id="numeroFactureFournisseur" class="form-control" 
                                   placeholder="Ex: F2025-00142" style="font-family:monospace;" value="{{ old('numero_facture_fournisseur') }}">
                        </div>
                        {{-- Mode de paiement --}}
                        <input type="hidden" name="mode_paiement" id="modePaiementInput" value="Caisse">
                        <input type="hidden" name="mobile_money_operateur" id="mobileMoneyOperateurInput" value="">
                        <input type="hidden" name="devise" id="deviseInput" value="XOF">
                        <input type="hidden" name="taux_change" id="tauxChangeInput" value="">
                        <div class="form-group" style="margin-bottom:10px;">
                            <label class="form-label" style="font-size:12px; color:var(--text-2);">Mode de paiement <span style="color:var(--danger)">*</span></label>
                            <div style="display:flex; gap:8px; margin-bottom:12px;">
                                <button type="button" class="btn payment-toggle-btn active" data-mode="Caisse" onclick="selectionnerModePaiement(this)" style="flex:1; justify-content:center; padding:8px 0; font-size:11px; font-weight:700; border-radius:8px;">Caisse</button>
                                <button type="button" class="btn payment-toggle-btn" data-mode="Banque" onclick="selectionnerModePaiement(this)" style="flex:1; justify-content:center; padding:8px 0; font-size:11px; font-weight:700; border-radius:8px;">Banque</button>
                                <button type="button" class="btn payment-toggle-btn" data-mode="Mobile Money" onclick="selectionnerModePaiement(this)" style="flex:1.2; justify-content:center; padding:8px 0; font-size:11px; font-weight:700; border-radius:8px; white-space:nowrap;">Mobile Money</button>
                                <button type="button" class="btn payment-toggle-btn" data-mode="Crédit" onclick="selectionnerModePaiement(this)" style="flex:1; justify-content:center; padding:8px 0; font-size:11px; font-weight:700; border-radius:8px;">Crédit</button>
                            </div>
                        </div>
                        {{-- Sélection banque --}}
                        <div id="selectionBanqueContainer" style="display:none; margin-bottom:10px;">
                            <div class="form-group" style="margin-bottom:8px;">
                                <label class="form-label" style="font-size:12px; color:var(--text-2);">Banque</label>
                                <div style="display:flex; gap:8px;">
                                    <select name="banque_id" id="banqueSelect" class="form-control" style="flex:1;">
                                        <option value="">— Choisir —</option>
                                        @foreach($banques as $b)
                                        <option value="{{ $b->id }}">{{ $b->intitule }} ({{ $b->code }})</option>
                                        @endforeach
                                    </select>
                                    <button type="button" class="btn btn-primary" onclick="ouvrirModalNouvelleBanque()" style="padding:0 12px;"><i class="fas fa-plus"></i></button>
                                </div>
                            </div>
                            <div class="form-group" style="margin-bottom:8px;">
                                <label class="form-label" style="font-size:12px; color:var(--text-2);">Moyen bancaire</label>
                                <select name="moyen_bancaire" id="moyenBancaireSelect" class="form-control">
                                    <option value="">— Moyen —</option>
                                    <option value="carte">Carte bancaire</option>
                                    <option value="virement">Virement</option>
                                    <option value="cheque">Chèque</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label" style="font-size:12px; color:var(--text-2);">Référence / Numéro</label>
                                <input type="text" name="reference_paiement" id="refPaiementInput" class="form-control" placeholder="Numéro de carte, virement ou chèque">
                            </div>
                        </div>
                        {{-- Sélection opérateur Mobile Money --}}
                        <div id="selectionMobileMoneyContainer" style="display:none; margin-bottom:10px;">
                            <div class="form-group">
                                <label class="form-label" style="font-size:12px; color:var(--text-2);">Opérateur Mobile Money</label>
                                <select id="mobileMoneyOperateurSelect" class="form-control" onchange="document.getElementById('mobileMoneyOperateurInput').value = this.value">
                                    <option value="">— Sélectionner l'opérateur —</option>
                                    <option value="MTN">MTN Mobile Money</option>
                                    <option value="MOOV">MOOV Money</option>
                                    <option value="ORANGE">Orange Money</option>
                                    <option value="WAVE">Wave</option>
                                </select>
                            </div>
                        </div>
                        {{-- Devise --}}
                        <div class="form-group" style="margin-bottom:8px;">
                            <label class="form-label" style="font-size:12px; color:var(--text-2);">Devise</label>
                            <select id="deviseSelect" class="form-control" onchange="changerDevise(this.value)">
                                <option value="XOF" selected>FCFA — Franc CFA (XOF)</option>
                                <option value="EUR">Euro (EUR)</option>
                                <option value="USD">Dollar Américain (USD)</option>
                                <option value="GBP">Livre Sterling (GBP)</option>
                                <option value="CHF">Franc Suisse (CHF)</option>
                                <option value="CAD">Dollar Canadien (CAD)</option>
                                <option value="JPY">Yen Japonais (JPY)</option>
                                <option value="CNH">Yuan Chinois (CNH)</option>
                            </select>
                        </div>
                        <div id="blocTauxChange" style="display:none; margin-bottom:8px;">
                            <label class="form-label" style="font-size:12px; color:var(--text-2);">Taux de change (1 <span id="deviseCodeLabel">EUR</span> = ? FCFA)</label>
                            <div style="display:flex; gap:8px; align-items:center;">
                                <input type="number" id="tauxChangeDisplayInput" class="form-control" placeholder="Ex: 655" step="0.0001" min="0"
                                    onchange="document.getElementById('tauxChangeInput').value = this.value; calculerMontantEnFcfa();">
                                <button type="button" class="btn btn-outline" id="btnActualiserTaux" onclick="actualiserTauxChange()" style="white-space:nowrap; font-size:11px; padding:5px 10px;">
                                    <i class="fas fa-sync-alt"></i> Actualiser
                                </button>
                            </div>
                            <div style="font-size:11px; color:var(--text-3); margin-top:4px;">Taux récupéré automatiquement. Modifiable si nécessaire.</div>
                        </div>

                        {{-- Montant en devise étrangère --}}
                        <div class="form-group" id="blocMontantDevise" style="display:none; margin-bottom:8px;">
                            <label class="form-label" style="font-size:12px; color:var(--text-2);">Montant payé en <span class="deviseCodeText">EUR</span></label>
                            <input type="number" id="montantDeviseInput" class="form-control" placeholder="Saisir le montant en devise étrangère" step="0.01" min="0" oninput="calculerMontantEnFcfa()">
                        </div>

                        {{-- Montant en FCFA (champ principal) --}}
                        <div class="form-group" id="blocMontantFcfa" style="display:none; margin-bottom:8px;">
                            <label class="form-label" style="font-size:12px; color:var(--text-2);">Montant payé (FCFA) <span style="color:var(--danger)">*</span></label>
                            <input type="number" name="montant_paye" id="montantPayeInput" class="form-control" placeholder="Montant en FCFA"
                                   oninput="calculerMontantEnDevise(); calculerRenduMonnaie();">
                        </div>

                        {{-- La monnaie rendue. Jamais saisie : elle se déduit de
                             ce qui a été remis et de ce qui est dû. La laisser
                             saisissable, c'est permettre qu'elle contredise les
                             deux montants entre lesquels elle se tient. --}}
                        <div class="form-group" id="blocMonnaieRendue" style="display:none; margin-bottom:8px;">
                            <label class="form-label" style="font-size:12px; color:var(--text-2);">Monnaie rendue</label>
                            <input type="text" id="monnaieRendueInput" class="form-control" readonly tabindex="-1" value="0 F"
                                   style="background:#f0fdf4; border-color:#86efac; color:#15803d; font-weight:800; cursor:default;">
                            <small style="color:var(--text-3); font-size:11px;">
                                Calculée : ce qui a été remis, moins le net à payer. Elle figure sur la pièce imprimée.
                            </small>
                        </div>
                    </div>
                </div>

                <div class="total-box" style="border-top: 1px solid var(--border); padding-top:12px; margin-top:12px;">
                    <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--text-2);padding:4px 0;">
                        <span>Total HT</span><span id="totHt">0 F</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;color:var(--text-2);padding:4px 0;">
                        <span>Remise (%)</span>
                        <input type="number" id="remiseTauxInput" name="remise_taux" class="form-control"
                               value="{{ old('remise_taux', 0) }}" min="0" max="100" step="0.01" oninput="recalculer()"
                               style="width:100px;height:28px;text-align:right;font-weight:700;padding:2px 8px;font-size:13px;margin:0;">
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:13px;color:#dc2626;padding:4px 0;">
                        <span>Montant de la remise sur le total HT</span><span id="totRemise">0 F</span>
                    </div>
                    {{-- La TVA manquait au pave, et le total affiche valait
                         le seul HT net : sur un achat a 18 %, l'ecran
                         annoncait 18 % de moins que la piece enregistree. --}}
                    <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--text-2);padding:4px 0;">
                        <span>TVA</span><span id="totTva">0 F</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--text-2);padding:4px 0;" id="ligneTimbreAchat">
                        <span>Timbre de quittance</span><span id="totTimbreAchat">0 F</span>
                    </div>
                    <div class="total-row" style="display:flex;justify-content:space-between;font-size:17px;font-weight:800;color:var(--text);padding:8px 0 4px;">
                        <span>Total</span><span id="totTtc">0 F</span>
                    </div>
                </div>

                {{-- Le bloc « Mentions DGI (bordereau d'achat) » vivait ici, avec
                     sa case RNE et le numéro du reçu d'origine. Retiré le
                     25/09/2026 à la demande du propriétaire : un bordereau
                     d'achat constate un achat auprès d'un producteur qui n'émet
                     rien — il n'existe aucun reçu normalisé auquel le rattacher,
                     et la case invitait à en déclarer un qui n'a jamais été
                     délivré. Les colonnes `est_rne` et `numero_rne` restent en
                     base et dans le payload, inchangées : la conformité FNE ne
                     bouge pas, c'est l'écran qui cesse de demander ce qui n'a
                     pas lieu d'être. --}}
                @php $entrepriseCourante = Auth::user()->entreprise; @endphp

                <div style="display:flex; flex-direction:column; gap:8px; margin-top:12px;">
                    <button type="submit" id="btnValiderAchat" class="btn btn-primary" style="width:100%;justify-content:center;" disabled>
                        <i class="fas fa-check-circle"></i> <span id="labelBtnValider">Enregistrer le bon de commande</span>
                    </button>

                    {{-- Le B2B transmet la demande à un fournisseur : sans
                         fournisseur choisi, il n'y a personne à qui l'adresser.
                         L'option s'affichait quand même, et un bordereau — dont
                         le vendeur n'est pas immatriculé — pouvait être coché
                         pour un envoi qui n'aurait eu aucun destinataire. --}}
                    <div id="blocB2b" style="border-top:1px solid var(--border); padding-top:10px; margin-top:10px; display:none;">
                        <label style="display:flex; align-items:center; gap:8px; font-size:12.5px; font-weight:700; color:var(--text-2); margin-bottom:6px; cursor:pointer;">
                            <input type="checkbox" name="envoyer_rfq_b2b" value="1" id="chkEnvoyerB2b" onchange="toggleB2bOptions()">
                            <i class="fas fa-paper-plane" style="color:var(--primary);"></i> Transmettre en B2B (Inter-Entreprise)
                        </label>
                        
                        <div id="optionsB2b" style="display:none; padding-left:22px; margin-top:4px; margin-bottom:8px;">
                            <label style="display:flex; align-items:center; gap:8px; font-size:12px; font-weight:600; color:var(--text-3); cursor:pointer;">
                                <input type="checkbox" name="masquer_prix_conseilles" value="1" id="chkMasquerPrix">
                                Masquer les prix suggérés (RFQ sans prix)
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</form>


<!-- Modal Banque -->
<div class="modal-overlay" id="modalNouvelleBanque">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-building-columns"></i> Nouveau code journal banque</h3>
            <button type="button" class="modal-close" onclick="fermerModalNouvelleBanque()">&times;</button>
        </div>
        <form id="formNouvelleBanque" onsubmit="soumettreNouvelleBanque(event)">
            <div class="form-group">
                <label class="form-label">Code <span style="color:var(--danger)">*</span></label>
                <input type="text" id="banqueCodeInput" class="form-control" placeholder="Ex: BQE, SGCI" required>
            </div>
            <div class="form-group">
                <label class="form-label">Intitulé <span style="color:var(--danger)">*</span></label>
                <input type="text" id="banqueIntituleInput" class="form-control" placeholder="Ex: Journal Société Générale" required>
            </div>
            <div class="form-group">
                <label class="form-label">Compte comptable <span style="color:var(--danger)">*</span></label>
                <input type="text" id="banqueCompteInput" class="form-control" placeholder="Ex: 521100" required>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:24px;">
                <button type="button" class="btn btn-outline" onclick="fermerModalNouvelleBanque()">Annuler</button>
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

{{-- Options produits pour JS --}}
<script>
const produits = {!! json_encode($produits->map(function($p) {
    return [
        'id' => $p->id,
        'nom' => $p->nom,
        'prix' => $p->prix_achat,
        // Le taux du catalogue : c'est celui que le serveur applique, et
        // l'ecran doit annoncer le meme total que la piece enregistree.
        'tva' => (float) ($p->taux_tva ?? 0),
        'ref' => $p->reference
    ];
})->toArray()) !!};
let idx = 0;
// Declare ici, et non pres de `toggleBapa()` : `recalculer()` le lit pour
// savoir s'il doit compter la TVA, et une declaration plus bas dans le script
// le laisserait dans sa zone morte si le pave se calculait avant.
let bapaActive = false;

function savePanier() {
    const panierItems = [];
    document.querySelectorAll('.ligne').forEach(l => {
        const chk = l.querySelector('.libre-chk');
        const pSel = l.querySelector('.produit-sel');
        const nameInp = l.querySelector('.name-inp');
        const unitInp = l.querySelector('.unit-inp');
        const qInp = l.querySelector('.qte-inp');
        const prInp = l.querySelector('.prix-inp');
        
        const isLibre = chk && chk.checked;
        if (isLibre || pSel.value) {
            panierItems.push({
                produit_id: isLibre ? '' : pSel.value,
                libelle_virtuel: isLibre ? nameInp.value : '',
                unite: isLibre ? unitInp.value : 'Unité',
                quantite: qInp.value,
                prix_unitaire: prInp.value
            });
        }
    });
    localStorage.setItem('selflow_achat_panier', JSON.stringify(panierItems));
}

function loadPanier() {
    const saved = localStorage.getItem('selflow_achat_panier');
    if (saved) {
        try {
            const items = JSON.parse(saved);
            if (items && items.length > 0) {
                document.getElementById('lignesAchat').innerHTML = '';
                items.forEach(item => {
                    ajouterLigne(item);
                });
                return;
            }
        } catch (e) {
            console.error("Erreur chargement panier d'achat", e);
        }
    }
    ajouterLigne();
}

function ajouterLigne(prefill = null) {
    const container = document.getElementById('lignesAchat');
    const opts = produits.map(p => `<option value="${p.id}" data-prix="${p.prix}" data-tva="${p.tva}">${p.nom} (${p.ref})</option>`).join('');
    const div = document.createElement('div');
    div.className = 'ligne'; div.dataset.idx = idx;
    
    const isLibre = prefill && (prefill.libelle_virtuel || !prefill.produit_id && prefill.produit_id === '');
    
    div.innerHTML = `
        <div class="form-group" style="margin-bottom:0; min-width: 250px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                <label class="form-label" style="margin-bottom:0;">Produit</label>
                <label style="font-size:11px; margin-bottom:0; font-weight:normal; cursor:pointer; color:var(--primary);">
                    <input type="checkbox" class="libre-chk" onchange="toggleLibre(${idx})" ${isLibre ? 'checked' : ''}> Saisie libre
                </label>
            </div>
            
            <div class="sel-container" style="${isLibre ? 'display:none;' : 'display:block;'}">
                <select name="articles[${idx}][produit_id]" class="form-control produit-sel" onchange="majPrix(this, ${idx}); savePanier();" ${isLibre ? '' : 'required'}>
                    <option value="">— Choisir —</option>${opts}
                </select>
            </div>
            
            <div class="libre-container" style="${isLibre ? 'display:flex;' : 'display:none;'} gap: 6px;">
                <input type="text" name="articles[${idx}][libelle_virtuel]" class="form-control name-inp" placeholder="Nom du produit" value="${prefill ? (prefill.libelle_virtuel || '') : ''}" ${isLibre ? 'required' : ''} oninput="savePanier();">
                <input type="text" name="articles[${idx}][unite]" class="form-control unit-inp" placeholder="Unité" value="${prefill ? (prefill.unite || 'Unité') : 'Unité'}" oninput="savePanier();" style="width: 80px;">
            </div>
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Quantité</label>
            <input type="number" name="articles[${idx}][quantite]" class="form-control qte-inp" min="0.001" step="0.001" value="${prefill ? prefill.quantite : 1}" oninput="recalculer(); savePanier();" required>
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Prix unitaire</label>
            <input type="number" name="articles[${idx}][prix_unitaire]" class="form-control prix-inp" min="0" step="1" value="${prefill ? prefill.prix_unitaire : 0}" oninput="recalculer(); savePanier();" required>
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label">Remise (%)</label>
            <input type="number" name="articles[${idx}][remise_taux]" class="form-control remise-inp" min="0" max="100" step="0.01" value="${prefill ? (prefill.remise_taux || 0) : 0}" oninput="recalculer(); savePanier();" style="width: 90px;">
        </div>
        <button type="button" class="btn-remove-ligne" onclick="supprimerLigne(${idx})"><i class="fas fa-trash"></i></button>
    `;
    container.appendChild(div);
    
    if (prefill) {
        if (prefill.produit_id) {
            div.querySelector('.produit-sel').value = prefill.produit_id;
        }
    }

    idx++;
    recalculer();
}

function toggleLibre(i) {
    const row = document.querySelector(`[data-idx="${i}"]`);
    const chk = row.querySelector('.libre-chk');
    const selContainer = row.querySelector('.sel-container');
    const libreContainer = row.querySelector('.libre-container');
    const sel = row.querySelector('.produit-sel');
    const nameInp = row.querySelector('.name-inp');
    
    if (chk.checked) {
        selContainer.style.display = 'none';
        sel.required = false;
        sel.value = '';
        
        libreContainer.style.display = 'flex';
        nameInp.required = true;
    } else {
        selContainer.style.display = 'block';
        sel.required = true;
        
        libreContainer.style.display = 'none';
        nameInp.required = false;
        nameInp.value = '';
    }
    recalculer();
    savePanier();
}

function majPrix(sel, i) {
    const opt = sel.options[sel.selectedIndex];
    const prix = opt.dataset.prix || 0;
    const ligne = document.querySelector(`[data-idx="${i}"]`);
    ligne.querySelector('.prix-inp').value = prix;
    recalculer();
}

function supprimerLigne(i) {
    const ligne = document.querySelector(`[data-idx="${i}"]`);
    if (ligne) {
        ligne.remove();
    }
    recalculer();
    savePanier();
}

// `basculerChampRne()` vivait ici. Elle ouvrait le champ du numero de recu
// sous la case RNE du bordereau, tous deux retires le 25/09/2026 : un
// bordereau d'achat constate un achat aupres d'un producteur qui n'emet rien.
// La fonction n'avait plus rien a ouvrir.

/**
 * Ordre de calcul aligne sur celui de la FNE : remise de ligne, puis remise
 * globale sur le total HT, puis taxes sur le total.
 */
{{-- Le timbre manquait a cet ecran. Le pave des totaux annoncait donc un
     net inferieur a ce qu'on remet vraiment au vendeur sur un bordereau
     regle en especes, et la monnaie rendue etait fausse d'autant. --}}
@include('admin::factures.partials.script-timbre')

function recalculer() {
    let ht  = 0;
    let tva = 0;

    document.querySelectorAll('.ligne').forEach(l => {
        const q  = parseFloat(l.querySelector('.qte-inp').value) || 0;
        const p  = parseFloat(l.querySelector('.prix-inp').value) || 0;
        const r  = Math.min(Math.max(parseFloat(l.querySelector('.remise-inp')?.value) || 0, 0), 100);
        const htLigne = q * p * (1 - r / 100);
        ht += htLigne;

        // Le serveur n'applique la TVA que sur une ligne rattachee a un
        // article du catalogue, et jamais sur un bordereau d'achat : le tiers
        // n'y facture aucune taxe. L'ecran suit la meme regle, sans quoi il
        // annoncerait un total que la piece enregistree ne porte pas.
        const sel = l.querySelector('.produit-sel');
        const opt = sel && sel.value ? sel.options[sel.selectedIndex] : null;
        const taux = opt ? parseFloat(opt.dataset.tva) || 0 : 0;
        if (!bapaActive && taux > 0) {
            tva += Math.round(htLigne * taux / 100 * 100) / 100;
        }
    });

    const fmt = n => new Intl.NumberFormat('fr-FR').format(Math.round(n)) + ' F';

    let remiseTaux = parseFloat(document.getElementById('remiseTauxInput')?.value) || 0;
    remiseTaux = Math.min(Math.max(remiseTaux, 0), 100);
    const montantRemise = ht * (remiseTaux / 100);
    const htNet = Math.max(0, ht - montantRemise);

    // La remise globale reduit la TVA dans la meme proportion : c'est ce que
    // fait le serveur, au meme endroit du calcul.
    const ratio = ht > 0 ? htNet / ht : 0;
    const tvaNette = Math.round(tva * ratio * 100) / 100;

    const total = htNet + tvaNette;

    const devise = document.getElementById('deviseInput').value;
    const taux = parseFloat(document.getElementById('tauxChangeDisplayInput').value) || 0;
    let totalText = fmt(total);
    if (devise !== 'XOF' && taux > 0) {
        const totalDevise = (total / taux).toFixed(2);
        totalText += ` (${totalDevise} ${devise})`;
    }

    const elHt = document.getElementById('totHt');
    if (elHt) elHt.textContent = fmt(ht);
    const elRemise = document.getElementById('totRemise');
    if (elRemise) elRemise.textContent = fmt(montantRemise);
    const elTva = document.getElementById('totTva');
    if (elTva) elTva.textContent = fmt(tvaNette);

    // Le timbre ne frappe que le bordereau, et seulement en especes :
    // `TimbreQuittanceService::pourAchat()` dit la meme chose cote serveur.
    // L'article 875 du CGI met le droit a la charge du debiteur -- sur un
    // achat, c'est l'entreprise qui l'acquitte.
    const modePaiementAchat = document.getElementById('modePaiementInput')?.value;
    const timbreAchat = bapaActive ? timbreDeQuittance(total, modePaiementAchat) : 0;
    const ligneTimbreAchat = document.getElementById('ligneTimbreAchat');
    if (ligneTimbreAchat) ligneTimbreAchat.style.display = timbreAchat > 0 ? 'flex' : 'none';
    const totTimbreAchat = document.getElementById('totTimbreAchat');
    if (totTimbreAchat) totTimbreAchat.textContent = fmt(timbreAchat);

    document.getElementById('totTtc').textContent = totalText;

    // Le net sert deux fois : a l'afficher, et a etablir la monnaie rendue.
    // Il se depose ici plutot que d'etre recalcule ailleurs, ou les deux
    // finiraient par diverger.
    const blocRendu = document.getElementById('blocMonnaieRendue');
    if (blocRendu) {
        blocRendu.dataset.net = total + timbreAchat;
        calculerRenduMonnaie();
    }

    const noItems = document.querySelectorAll('.ligne').length === 0;
    document.getElementById('btnValiderAchat').disabled = noItems;
}

document.addEventListener('DOMContentLoaded', function () {
});

function toggleB2bOptions() {
    const chkEnvoyer = document.getElementById('chkEnvoyerB2b');
    const optionsDiv = document.getElementById('optionsB2b');
    if (chkEnvoyer && optionsDiv) {
        optionsDiv.style.display = chkEnvoyer.checked ? 'block' : 'none';
    }
}

// Lot F : sélectionner l'étape du document
function selectionnerEtape(btn) {
    document.querySelectorAll('[data-etape]').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const etape = btn.dataset.etape;
    document.getElementById('etapeInput').value = etape;
    const labelMap = {
        'Demande de prix': 'Enregistrer la demande de prix',
        'Bon de commande': 'Enregistrer le bon de commande',
    };
    document.getElementById('labelBtnValider').textContent = labelMap[etape] || 'Enregistrer';
}

// Lot F : bascule affichage bloc facture physique & BAPA
let facturePhysiqueActive = false;

/**
 * Ce que la saisie va produire.
 *
 * Les deux boutons se comportent comme les etapes : celui qui est choisi porte
 * la classe `active`, l'autre reste visible et cliquable. Avant, chacun cachait
 * l'autre, et l'on ne voyait plus ce qu'on aurait pu choisir a la place.
 */
function marquerNature(actif) {
    ['btnFacturePhysique', 'btnBapa'].forEach(function (id) {
        document.getElementById(id).classList.toggle('active', id === actif);
    });
    // Le detail de la piece -- numero, reglement, montants -- n'a de sens que
    // si l'une des deux natures est choisie.
    document.getElementById('cadreFacturePhysique').style.display = actif ? 'block' : 'none';
    document.getElementById('blocFacturePhysique').style.display = actif ? 'block' : 'none';
    // Un BAPA n'est ni une demande de prix ni un bon de commande.
    document.getElementById('blocTypeDocument').style.display = actif === 'btnBapa' ? 'none' : 'block';
    basculerB2b();
}

function toggleFacturePhysique() {
    facturePhysiqueActive = !facturePhysiqueActive;
    if (facturePhysiqueActive) {
        if (bapaActive) { bapaActive = false; restaurerFournisseur(); }
        marquerNature('btnFacturePhysique');

        document.getElementById('etapeInput').value = 'Facture';
        document.getElementById('typeFactureInput').value = 'normale';
        document.getElementById('labelBtnValider').textContent = 'Enregistrer la facture';
        document.getElementById('numeroFactureFournisseur').closest('.form-group').style.display = 'block';
    } else {
        marquerNature(null);
        restaurerEtapeParDefaut();
    }
    recalculer();
}

/** Le dropdown des fournisseurs reprend sa place apres un bordereau. */
function restaurerFournisseur() {
    document.getElementById('groupeFournisseurSelect').style.display = 'block';
    document.getElementById('fournisseurSelect').required = true;
    document.getElementById('groupeFournisseurBapa').style.display = 'none';
    document.getElementById('fournisseurNomBapa').required = false;
    document.getElementById('fournisseurNomBapa').value = '';
}

function toggleBapa() {
    bapaActive = !bapaActive;
    // Un bordereau d'achat ne porte aucune TVA : le pave doit le montrer des
    // la bascule, pas au prochain changement de quantite.
    recalculer();
    if (bapaActive) {
        facturePhysiqueActive = false;
        marquerNature('btnBapa');

        // Swap fournisseur : masquer le dropdown, afficher le champ libre tiers
        document.getElementById('groupeFournisseurSelect').style.display = 'none';
        document.getElementById('fournisseurSelect').required = false;
        document.getElementById('groupeFournisseurBapa').style.display = 'block';
        document.getElementById('fournisseurNomBapa').required = true;

        document.getElementById('etapeInput').value = 'Facture';
        document.getElementById('typeFactureInput').value = 'bapa';
        document.getElementById('labelBtnValider').textContent = 'Enregistrer le BAPA (DGI)';
        document.getElementById('numeroFactureFournisseur').closest('.form-group').style.display = 'none';
        document.getElementById('numeroFactureFournisseur').value = '';
    } else {
        marquerNature(null);
        restaurerFournisseur();
        restaurerEtapeParDefaut();
    }
    basculerB2b();
}

/**
 * Le B2B n'a de destinataire que si un fournisseur est choisi.
 *
 * Il s'affichait toujours, bordereau compris -- ou le vendeur n'est justement
 * pas immatricule : on pouvait cocher un envoi qui n'aurait atteint personne.
 */
function basculerB2b() {
    var bloc = document.getElementById('blocB2b');
    if (!bloc) return;

    var choisi = !bapaActive && !!document.getElementById('fournisseurSelect').value;
    bloc.style.display = choisi ? 'block' : 'none';

    if (!choisi) {
        var coche = document.getElementById('chkEnvoyerB2b');
        if (coche && coche.checked) { coche.checked = false; toggleB2bOptions(); }
    }
}

/**
 * Ce qu'on rend au fournisseur.
 *
 * Jamais saisie, toujours calculee : la somme remise moins le net a payer.
 * Negative, elle n'est pas une monnaie a rendre mais un solde restant du --
 * le bloc disparait alors, plutot que d'annoncer une dette de la caisse.
 */
function calculerRenduMonnaie() {
    var bloc = document.getElementById('blocMonnaieRendue');
    var champ = document.getElementById('monnaieRendueInput');
    if (!bloc || !champ) return;

    var remis = parseFloat(document.getElementById('montantPayeInput')?.value) || 0;
    var du = parseFloat(bloc.dataset.net || '0') || 0;
    var rendu = remis - du;

    if (remis <= 0 || rendu <= 0) {
        bloc.style.display = 'none';
        champ.value = '0 F';
        return;
    }

    bloc.style.display = 'block';
    champ.value = Math.round(rendu).toLocaleString('fr-FR') + ' F';
}

function restaurerEtapeParDefaut() {
    document.getElementById('typeFactureInput').value = 'normale';
    const activeEtapeBtn = document.querySelector('[data-etape].active');
    if (activeEtapeBtn) {
        document.getElementById('etapeInput').value = activeEtapeBtn.dataset.etape;
        document.getElementById('labelBtnValider').textContent = 
            activeEtapeBtn.dataset.etape === 'Demande de prix' 
                ? 'Enregistrer la demande de prix' 
                : 'Enregistrer le bon de commande';
    }
}

function selectionnerModePaiement(btn) {
    // Cibler uniquement les boutons de mode paiement
    btn.closest('div').querySelectorAll('.payment-toggle-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    const mode = btn.dataset.mode;
    document.getElementById('modePaiementInput').value = mode;

    const banqueContainer      = document.getElementById('selectionBanqueContainer');
    const mobileMoneyContainer = document.getElementById('selectionMobileMoneyContainer');
    const banqueSelect         = document.getElementById('banqueSelect');
    const moyenBancaireSelect  = document.getElementById('moyenBancaireSelect');
    const refPaiementInput     = document.getElementById('refPaiementInput');

    // Tout réinitialiser
    banqueContainer.style.display      = 'none';
    mobileMoneyContainer.style.display = 'none';
    banqueSelect.required        = false;
    moyenBancaireSelect.required = false;
    refPaiementInput.required    = false;
    banqueSelect.value           = '';
    moyenBancaireSelect.value    = '';
    refPaiementInput.value       = '';
    document.getElementById('mobileMoneyOperateurInput').value = '';

    if (mode === 'Banque') {
        banqueContainer.style.display = 'block';
        banqueSelect.required         = true;
        moyenBancaireSelect.required  = true;
        refPaiementInput.required     = true;
    } else if (mode === 'Mobile Money') {
        mobileMoneyContainer.style.display = 'block';
    }

    // Le timbre ne frappe que les especes : changer de mode change le net, et
    // donc la monnaie rendue. Sans ce rappel, le pave gardait le timbre d'un
    // reglement en especes apres un passage en banque.
    recalculer();
}

function changerDevise(code) {
    document.getElementById('deviseInput').value = code;
    const blocTaux   = document.getElementById('blocTauxChange');
    const blocDev    = document.getElementById('blocMontantDevise');
    const blocFcfa   = document.getElementById('blocMontantFcfa');
    const devTexts   = document.querySelectorAll('.deviseCodeText');

    devTexts.forEach(el => el.textContent = code);

    if (code && code !== 'XOF') {
        blocTaux.style.display  = 'block';
        blocDev.style.display   = 'block';
        blocFcfa.style.display  = 'block';
        document.getElementById('deviseCodeLabel').textContent = code;
        document.getElementById('tauxChangeDisplayInput').value = '';
        document.getElementById('tauxChangeInput').value = '';
        document.getElementById('montantDeviseInput').value = '';
        document.getElementById('montantPayeInput').value = '';
        actualiserTauxChange();
    } else {
        blocTaux.style.display  = 'none';
        blocDev.style.display   = 'none';
        blocFcfa.style.display  = 'none';
        document.getElementById('tauxChangeInput').value = '';
        document.getElementById('tauxChangeDisplayInput').value = '';
        if (document.getElementById('montantDeviseInput')) document.getElementById('montantDeviseInput').value = '';
        if (document.getElementById('montantPayeInput'))   document.getElementById('montantPayeInput').value = '';
        recalculer();
    }
}

const TAUX_FALLBACK = { EUR: 655, USD: 600, GBP: 760, CHF: 670, CAD: 445, JPY: 4, CNH: 83 };

async function actualiserTauxChange() {
    const code = document.getElementById('deviseInput').value;
    if (!code || code === 'XOF') return;
    const btn = document.getElementById('btnActualiserTaux');
    if (btn) btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    try {
        const res = await fetch(`https://open.er-api.com/v6/latest/${code}`);
        const data = await res.json();
        if (data && data.rates && data.rates['XOF']) {
            const taux = Math.round(data.rates['XOF']);
            document.getElementById('tauxChangeDisplayInput').value = taux;
            document.getElementById('tauxChangeInput').value = taux;
            recalculer();
        } else { throw new Error('no rate'); }
    } catch (e) {
        const fallback = TAUX_FALLBACK[code] ?? '';
        document.getElementById('tauxChangeDisplayInput').value = fallback;
        document.getElementById('tauxChangeInput').value = fallback;
        recalculer();
    }
    if (btn) btn.innerHTML = '<i class="fas fa-sync-alt"></i> Actualiser';
}

function calculerMontantEnFcfa() {
    const devInput  = document.getElementById('montantDeviseInput');
    const fcfaInput = document.getElementById('montantPayeInput');
    const tauxInput = document.getElementById('tauxChangeDisplayInput');
    const devise    = document.getElementById('deviseInput').value;
    if (devise === 'XOF' || !devInput || !fcfaInput) return;
    const devVal  = parseFloat(devInput.value) || 0;
    const taux    = parseFloat(tauxInput.value) || 0;
    if (taux > 0) fcfaInput.value = Math.round(devVal * taux);
    else fcfaInput.value = '';
}

function calculerMontantEnDevise() {
    const devInput  = document.getElementById('montantDeviseInput');
    const fcfaInput = document.getElementById('montantPayeInput');
    const tauxInput = document.getElementById('tauxChangeDisplayInput');
    const devise    = document.getElementById('deviseInput').value;
    if (devise === 'XOF' || !devInput || !fcfaInput) return;
    const fcfaVal = parseFloat(fcfaInput.value) || 0;
    const taux    = parseFloat(tauxInput.value) || 0;
    if (taux > 0) devInput.value = (fcfaVal / taux).toFixed(2);
    else devInput.value = '';
}

function ouvrirModalNouvelleBanque() {
    document.getElementById('modalNouvelleBanque').classList.add('open');
}

function fermerModalNouvelleBanque() {
    document.getElementById('modalNouvelleBanque').classList.remove('open');
    document.getElementById('formNouvelleBanque').reset();
}

function soumettreNouvelleBanque(e) {
    e.preventDefault();
    const code = document.getElementById('banqueCodeInput').value;
    const intitule = document.getElementById('banqueIntituleInput').value;
    const compte = document.getElementById('banqueCompteInput').value;
    
    const routeCreation = "{{ route('admin.banques.creer') }}";
    
    fetch(routeCreation, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        },
        body: JSON.stringify({ code, intitule, compte })
    })
    .then(res => res.json())
    .then(data => {
        if (data.succes) {
            const select = document.getElementById('banqueSelect');
            const opt = document.createElement('option');
            opt.value = data.banque.id;
            opt.textContent = `${data.banque.nom} (${data.banque.numero_compte})`;
            opt.selected = true;
            select.appendChild(opt);
            
            fermerModalNouvelleBanque();
        } else {
            alert("Erreur lors de la création du code journal banque.");
        }
    })
    .catch(err => {
        console.error(err);
        alert("Une erreur est survenue.");
    });
}

document.getElementById('formAchat').addEventListener('submit', function() {
    setTimeout(() => {
        localStorage.removeItem('selflow_achat_panier');
    }, 100);
});

// Charger le panier au chargement
loadPanier();
</script>

{{-- Bornage immediat de tout taux saisi : aucune remise ni taxe ne peut sortir
     de l'intervalle 0-100 %, regle imposee par la DGI. La validation serveur
     reste la garantie ; ce script evite seulement a l'utilisateur de saisir une
     valeur qui sera refusee. --}}
<script>
document.addEventListener('input', function (e) {
    const champ = e.target;
    if (champ.tagName !== 'INPUT' || champ.type !== 'number') return;
    if (champ.max !== '100') return;

    const valeur = parseFloat(champ.value);
    if (isNaN(valeur)) return;

    if (valeur > 100) champ.value = 100;
    if (valeur < parseFloat(champ.min || 0)) champ.value = champ.min || 0;
});
</script>

@endsection

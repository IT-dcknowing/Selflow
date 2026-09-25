@extends('admin::gabarits.application')

@section('titre', 'Nouvelle vente')
@section('topbar_titre', 'Nouvelle vente')

@section('styles')
<style>
    .pos-grid { display: grid; grid-template-columns: 1fr 360px; gap: 22px; align-items: start; }

    .produit-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; }
    .produit-card {
        background: var(--bg3); border: 2px solid var(--border);
        border-radius: 10px; padding: 14px; cursor: pointer;
        transition: all .15s; text-align: center;
        user-select: none;
        /* La photo de l'article se pose derriere la carte : il faut donc un
           reperage local, et de quoi couper ce qui deborde des angles. */
        position: relative; overflow: hidden; isolation: isolate;
        /* De quoi voir l'image. Posee sur toutes les cartes et non sur les
           seules illustrees, pour que la grille garde des hauteurs egales. */
        min-height: 138px;
        display: flex; flex-direction: column; justify-content: flex-end;
    }
    /* La photo elle-meme. Elle n'est posee que sur les articles qui en ont une
       vraiment : l'image d'attente couvrirait toutes les autres cartes d'un
       meme gris, ce qui n'apprendrait rien et brouillerait le texte. */
    .produit-card.avec-photo::before {
        content: ''; position: absolute; inset: 0; z-index: 0;
        background-image: var(--fond-produit);
        background-size: cover; background-position: center;
        /* Elle etait a .45, sous un voile a .88 : il n'en restait presque rien,
           et l'ecran paraissait n'avoir pas change. On reconnait un article a
           sa photo ou on ne la met pas. */
        opacity: 1; transition: opacity .15s;
    }
    /* Le voile. Il est pris sur le fond de la carte, non ecrit en dur : le
       texte reste lisible en theme clair comme en theme sombre. Le haut reste
       degage — c'est la qu'on reconnait l'article ; le bas devient opaque, la
       ou se trouvent le nom, le prix et le stock. */
    .produit-card.avec-photo::after {
        content: ''; position: absolute; inset: 0; z-index: 1;
        background: linear-gradient(to bottom,
                    rgba(0,0,0,0) 0%, rgba(0,0,0,0) 30%,
                    var(--bg3) 64%, var(--bg3) 100%);
        opacity: .94; pointer-events: none;
    }
    .produit-card.avec-photo:hover::after { opacity: .86; }

    /* L'article sans photo ne porte plus de dessin de remplacement. Le
       filigrane tire de la nature de l'article se voulait un repere ; il
       n'en etait pas un — la meme silhouette revenait sur des articles
       sans rapport, et la grille en paraissait salie. Une carte nette dit
       moins, mais elle ne dit rien de faux. La vraie photo, elle, reste :
       elle porte une information que le texte ne porte pas. */
    /* Sans cela, le texte passerait sous le voile. */
    .produit-card > * { position: relative; z-index: 2; }
    .produit-card:hover { border-color: var(--primary); background: rgba(99,102,241,.08); transform: translateY(-2px); }
    .produit-card.out-of-stock { opacity: .65; border-color: rgba(239,68,68,.3); }
    .produit-card.out-of-stock:hover { border-color: var(--warning); background: rgba(245,158,11,.08); }
    .produit-card .produit-nom { font-weight: 600; font-size: 13px; margin-bottom: 4px; }
    .produit-card .produit-prix { color: var(--success); font-weight: 700; font-size: 15px; }
    .produit-card .produit-stock { font-size: 11px; color: var(--text-3); margin-top: 4px; }
    .produit-card .produit-cat { font-size: 10px; color: var(--primary); text-transform: uppercase; margin-bottom: 6px; }

    .panier-item {
        display: flex; align-items: center; gap: 10px;
        background: var(--bg3); border-radius: 8px; padding: 10px 12px;
        margin-bottom: 8px;
        /* Le contenu ne doit jamais deborder de la carte du panier. */
        overflow: hidden;
    }
    /* Sans `min-width:0`, un element flexible refuse de passer sous la largeur
       de son contenu : la ligne Unite / Remise / TVA debordait vers la droite. */
    .panier-item > .item-corps { flex: 1; min-width: 0; }
    .panier-item .item-nom { flex: 1; font-weight: 600; font-size: 13px; overflow-wrap: break-word; }
    .panier-item .item-prix { color: var(--text-3); font-size: 12px; }

    /* Champs de ligne : ils se replient sur plusieurs rangees si la colonne du
       panier est etroite, plutot que de sortir de la carte. */
    .panier-item .champs-ligne {
        margin-top: 6px;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 6px 12px;
    }
    .panier-item .champs-ligne label {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        margin: 0;
        font-size: 11px;
        color: var(--text-3);
        white-space: nowrap;
    }
    .panier-item .champs-ligne input {
        height: 24px;
        font-size: 11px;
        padding: 2px 6px;
        max-width: 100%;
    }
    .qte-ctrl { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
    .qte-btn {
        width: 26px; height: 26px; border-radius: 6px;
        border: none; cursor: pointer; font-size: 14px; font-weight: 700;
        display: flex; align-items: center; justify-content: center;
        background: var(--border); color: var(--text); transition: background .12s;
    }
    .qte-btn:hover { background: var(--primary); color: #fff; }
    .qte-input {
        width: 44px; height: 26px; text-align: center; font-weight: 700;
        border: 1px solid var(--border); border-radius: 6px; background: #fff;
        outline: none; font-size: 13px;
    }
    .qte-input:focus { border-color: var(--primary); }
    .remove-btn {
        background: none; border: none; color: var(--text-3);
        cursor: pointer; font-size: 14px; transition: color .12s;
    }
    .remove-btn:hover { color: var(--danger); }

    .total-box {
        border-top: 1px solid var(--border); margin-top: 12px; padding-top: 12px;
    }
    .total-row { display: flex; justify-content: space-between; font-size: 13px; padding: 4px 0; color: var(--text-2); }
    .total-row.grand { font-size: 17px; font-weight: 800; color: var(--text); }

    .categorie-filter { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
    .cat-btn {
        padding: 5px 14px; border-radius: 20px; border: 1px solid var(--border);
        background: var(--bg3); color: var(--text-2); font-size: 12px;
        font-weight: 600; cursor: pointer; transition: all .12s;
    }
    .cat-btn.active, .cat-btn:hover { background: var(--primary); color: #fff; border-color: var(--primary); }

    .search-produit {
        width: 100%; margin-bottom: 14px;
    }

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

    .btn-info { background: var(--info); color: #fff; }
    .btn-info:hover { background: #2563eb; }

    @keyframes slideIn {
        from { transform: translateX(-100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
</style>
@endsection

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-cash-register"></i> Nouvelle vente</h1>
        <p>Sélectionnez les articles, ajustez les quantités et finalisez la vente</p>
    </div>
    @php
        $routeHistorique = request()->routeIs('caissier.*') ? route('caissier.ventes.factures') : route('admin.ventes.factures');
        $routeEnregistrer = request()->routeIs('caissier.*') ? route('caissier.ventes.enregistrer') : route('admin.ventes.enregistrer');
    @endphp
    <a href="{{ $routeHistorique }}" class="btn btn-outline">
        <i class="fas fa-list"></i> Voir les ventes
    </a>
</div>

<form method="POST" action="{{ $routeEnregistrer }}" id="formVente">
@csrf
<div class="pos-grid">

    {{-- ── COLONNE GAUCHE : Catalogue produits ── --}}
    <div>
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-barcode"></i> Catalogue produits</h2>
                <span style="color:var(--text-3); font-size:12px;">{{ $produits->count() }} article(s) disponibles</span>
            </div>
            <div class="card-body">
                {{-- Filtre catégorie --}}
                <div class="categorie-filter">
                    <button type="button" class="cat-btn active" data-cat="all">Tous</button>
                    @foreach($categories as $cat)
                    <button type="button" class="cat-btn" data-cat="{{ $cat }}">{{ $cat }}</button>
                    @endforeach
                </div>

                {{-- Recherche --}}
                <input type="text" id="rechercheInput" class="form-control search-produit" placeholder="🔍 Rechercher un produit…">

                {{-- Grille produits --}}
                <div class="produit-grid" id="grilleProduits">
                    @foreach($produits as $produit)
                    @php $suitLeStock = $produit->estStockable(); @endphp
                    @php $photo = $produit->photoReelle(); @endphp
                    {{-- Une vraie photo tient tout le fond. A defaut, la carte
                         reste nette : aucun fond n'est pose. --}}
                    <div class="produit-card {{ $suitLeStock && $produit->stock_actuel <= 0 ? 'out-of-stock' : '' }} {{ $photo ? 'avec-photo' : '' }}"
                         style="{{ $photo ? '--fond-produit: url(\'' . $photo . '\');' : '' }}"
                         data-id="{{ $produit->id }}"
                         data-nom="{{ $produit->nom }}"
                         data-prix="{{ $produit->prix_vente }}"
                         data-stockable="{{ $suitLeStock ? '1' : '0' }}"
                         data-stock="{{ $produit->stock_actuel }}"
                         data-stock-min="{{ $produit->stock_minimum }}"
                         data-cat="{{ $produit->categorie }}"
                         data-unite="{{ $produit->unite ?? 'Unité' }}"
                         data-tva="{{ $produit->taux_tva }}"
                         data-remise="{{ $produit->remise_taux ?? 0 }}"
                         data-taxes="{{ $produit->taxes->map(fn ($t) => ['nom' => $t->nom, 'taux' => (float) $t->taux])->toJson() }}"
                         onclick="ajouterAuPanier(this)">
                        <div class="produit-cat">{{ $produit->categorie }}</div>
                        <div class="produit-nom">{{ $produit->nom }}</div>
                        <div class="produit-prix">{{ number_format($produit->prix_vente, 0, ',', ' ') }} F</div>
                        {{-- Un service n'a pas de stock : il ne peut pas etre en
                             rupture. On dit ce qu'il est, pas ce qui lui manque. --}}
                        <div class="produit-stock" style="{{ $suitLeStock && $produit->stock_actuel <= 0 ? 'color:var(--danger);font-weight:700;' : '' }}">
                            @if(!$suitLeStock)
                                {{ $produit->type === 'service' ? 'Service' : 'Sans gestion de stock' }}
                            @elseif($produit->stock_actuel <= 0)
                                Rupture de stock
                            @else
                                Stock : {{ $produit->stock_actuel }} {{ $produit->unite ?? 'unités' }}
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- ── COLONNE DROITE : Panier ── --}}
    <div>
        <div class="card" style="position: sticky; top: calc(var(--topbar-h) + 16px);">
            <div class="card-header">
                <h2><i class="fas fa-shopping-cart"></i> Panier</h2>
                <span id="nbArticles" style="color:var(--text-3); font-size:12px;">0 article(s)</span>
            </div>
            <div class="card-body">

                {{-- Articles du panier --}}
                <div id="panierVide" style="text-align:center; padding:24px 0; color:var(--text-3);">
                    <i class="fas fa-cart-plus" style="font-size:28px; display:block; margin-bottom:8px; opacity:.3;"></i>
                    Cliquez sur un produit pour l'ajouter
                </div>
                <div id="panierItems"></div>

                {{-- Bouton Saisie Libre --}}
                <button type="button" class="btn btn-outline btn-sm" onclick="ouvrirSaisieLibre()" style="width:100%; justify-content:center; margin-top:10px; margin-bottom:15px; border-style:dashed;">
                    <i class="fas fa-plus"></i> Saisie libre / Service
                </button>

                {{-- Totaux --}}
                <div class="total-box">
                    <div class="total-row"><span>Total HT</span><span id="totalHt">0 F</span></div>
                    <div class="total-row" style="align-items:center;">
                        <span>Remise (%)</span>
                        <input type="number" id="remiseTauxInput" name="remise_taux" class="form-control" value="0" min="0" max="100" step="0.01" oninput="calculerTotaux()" style="width: 100px; height: 28px; text-align: right; font-weight: 700; padding: 2px 8px; font-size: 13px; margin: 0;">
                    </div>
                    <div class="total-row" style="color:#dc2626;">
                        <span>Montant de la remise sur le total HT</span>
                        <span id="montantRemise">0 F</span>
                    </div>
                    <div class="total-row"><span>Total HT après remise</span><span id="totalHtNet">0 F</span></div>
                    <div class="total-row" style="align-items:center;">
                        <span>Total TVA</span>
                        <span id="totalTva">0 F</span>
                    </div>
                    <div class="total-row"><span>Total TTC</span><span id="totalTtc">0 F</span></div>
                    <div class="total-row"><span>Autres taxes</span><span id="totalAutresTaxes">0 F</span></div>
                    {{-- Droit de timbre de quittance, bareme de l'article 873 du CGI.
                         N'apparait que sur un reglement en especes, et seulement si
                         l'option est declaree active dans les parametres. --}}
                    <div class="total-row" id="ligneTimbre" style="display:none;">
                        <span>Timbre de quittance</span><span id="totalTimbre">0 F</span>
                    </div>
                    <div class="total-row grand"><span>Net à payer</span><span id="netAPayer">0 F</span></div>
                </div>

                {{-- Taxes sur total TTC (champ `customTaxes` de la FNE) --}}
                <div style="margin-top:14px; border:1px solid var(--border); border-radius:10px; padding:12px;">
                    <div style="font-size:12px; font-weight:700; color:var(--text-2); margin-bottom:8px;">
                        <i class="fas fa-percent" style="color:var(--primary);"></i> Taxes sur total TTC
                    </div>
                    <div id="taxesTtcConteneur" style="display:flex; flex-direction:column; gap:8px;"></div>
                    <button type="button" class="btn btn-outline btn-sm" onclick="ajouterTaxeTtc()"
                            style="width:100%; justify-content:center; margin-top:8px; border-style:dashed; font-size:12px;">
                        <i class="fas fa-plus"></i> Ajouter une taxe
                    </button>
                </div>

                {{-- Client & paiement --}}
                <div style="margin-top: 18px;">

                    {{-- Le rattachement à un reçu déjà émis ne se saisit plus ici.
                         Selflow *émet* la pièce : il n'existe pas de reçu antérieur
                         à confirmer. Le reçu est une mise en page de la facture déjà
                         certifiée — voir `factures/ticket.blade.php`. --}}
                    @php $entrepriseCourante = Auth::user()->entreprise; @endphp

                    <div class="form-group">
                        <label class="form-label">Client (optionnel)</label>
                        <select name="client_id" class="form-control">
                            <option value="">— Client de passage —</option>
                            @foreach($clients as $client)
                            <option value="{{ $client->id }}">{{ $client->nom }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Lot G : Sélecteur du type de document --}}
                    <input type="hidden" name="etape" id="etapeInput" value="Facture">
                    <div class="form-group">
                        <label class="form-label">Type de document</label>
                        <input type="hidden" name="type_piece" id="typePieceInput" value="facture">
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:4px;">
                            <button type="button" class="btn payment-toggle-btn" data-etape-vente="Devis" onclick="selectionnerEtapeVente(this)" style="justify-content:center; font-size:12px; padding:8px 4px;">
                                <i class="fas fa-file-invoice"></i> Devis
                            </button>
                            <button type="button" class="btn payment-toggle-btn" data-etape-vente="Bon de commande" onclick="selectionnerEtapeVente(this)" style="justify-content:center; font-size:12px; padding:8px 4px;">
                                <i class="fas fa-shopping-basket"></i> Commande
                            </button>
                            {{-- Un seul choix là où il y en avait deux. Le reçu
                                 n'est pas une autre pièce : ce sont les
                                 informations de la facture, mises en page pour
                                 le ticket. Les proposer séparément faisait
                                 croire qu'il fallait choisir, et une caisse qui
                                 choisissait « Reçu » se retrouvait sans facture.
                                 Une pièce, un envoi à la DGI, un sticker — et
                                 deux documents à imprimer. --}}
                            <button type="button" class="btn payment-toggle-btn active" data-etape-vente="Facture" onclick="selectionnerEtapeVente(this)" style="grid-column:1 / -1; justify-content:center; font-size:12px; padding:8px 4px;">
                                <i class="fas fa-check-double"></i> Facture + Reçu
                            </button>
                        </div>
                        <small id="infoEtapeVente" style="color:var(--text-3); font-size:11px;">Facture et reçu établis ensemble, avec règlement</small>

                        {{-- Le terme de l'offre. Un devis sans terme engage
                             indéfiniment celui qui l'a fait : il reste
                             présentable des mois plus tard, aux prix du jour où
                             il a été établi. Une facture, elle, n'expire pas. --}}
                        <div id="blocValidite" style="display:none; margin-top:10px;">
                            <label class="form-label" for="dateValidite" style="font-size:12px;">Valable jusqu'au</label>
                            <input type="date" name="date_validite" id="dateValidite" class="form-control"
                                   min="{{ now()->toDateString() }}"
                                   value="{{ old('date_validite', now()->addDays(\App\Modules\Admin\Modeles\Vente::VALIDITE_PAR_DEFAUT)->toDateString()) }}">
                            <small style="color:var(--text-3); font-size:11px;">
                                Passé ce terme, les prix indiqués ne vous engagent plus. Trente jours par défaut.
                            </small>
                        </div>
                        {{-- Cet encadré annonçait une certification « suspendue
                             tant que la FNE n'a pas fourni les champs de mappage
                             du reçu normalisé électronique ». C'était vrai avant
                             la refonte du reçu : depuis, le reçu emprunte la
                             porte de la facture — mêmes champs, même envoi,
                             même sticker —, et rien ne le retient. Le texte est
                             resté, et disait à l'utilisateur que ses reçus
                             n'étaient pas certifiés alors qu'ils l'étaient. --}}
                        <div id="noteRecu" style="margin-top:8px; padding:10px 12px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; font-size:11px; color:#1e40af; line-height:1.5;">
                            <i class="fas fa-circle-info"></i>
                            <strong>Une seule pièce, deux documents.</strong>
                            Le reçu reprend les informations de la facture : un seul envoi
                            à la DGI, un seul code QR, un seul sticker consommé. Ce qui les
                            distingue est le format d'impression.
                            @php
                                // `null` vaut « automatique » : c'était le seul
                                // comportement avant que le réglage existe.
                                $recuAuto = $entrepriseCourante?->normalisation_auto_factures ?? true;
                            @endphp
                            @if($recuAuto)
                                La pièce part à la certification dès son émission.
                            @else
                                La normalisation automatique est décochée dans vos paramètres :
                                la pièce s'enregistrera sans être certifiée, et portera la
                                mention <em>En attente</em> dans la liste des ventes jusqu'à ce
                                que vous la normalisiez.
                            @endif
                        </div>
                    </div>

                    {{-- Mode de paiement style buttons --}}
                    <input type="hidden" name="mode_paiement" id="modePaiementInput" value="Caisse">
                    <input type="hidden" name="mobile_money_operateur" id="mobileMoneyOperateurInput" value="">
                    <input type="hidden" name="devise" id="deviseInput" value="XOF">
                    <input type="hidden" name="taux_change" id="tauxChangeInput" value="">

                    {{-- Bloc Paiement : visible uniquement à l'étape Facture --}}
                    <div id="blocPaiementVente">
                        <div class="form-group">
                            <label class="form-label">Mode de paiement <span style="color:var(--danger)">*</span></label>
                            <div style="display:flex; gap:8px; margin-bottom:12px;">
                                <button type="button" class="btn payment-toggle-btn active" data-mode="Caisse" onclick="selectionnerModePaiement(this)" style="flex:1; justify-content:center; padding:8px 0; font-size:12px; font-weight:700; border-radius:8px;">Caisse</button>
                                <button type="button" class="btn payment-toggle-btn" data-mode="Banque" onclick="selectionnerModePaiement(this)" style="flex:1; justify-content:center; padding:8px 0; font-size:12px; font-weight:700; border-radius:8px;">Banque</button>
                                <button type="button" class="btn payment-toggle-btn" data-mode="Mobile Money" onclick="selectionnerModePaiement(this)" style="flex:1.2; justify-content:center; padding:8px 0; font-size:12px; font-weight:700; border-radius:8px; white-space:nowrap;">Mobile Money</button>
                                <button type="button" class="btn payment-toggle-btn" data-mode="Crédit" onclick="selectionnerModePaiement(this)" style="flex:1; justify-content:center; padding:8px 0; font-size:12px; font-weight:700; border-radius:8px;">Crédit</button>
                            </div>
                        </div>

                        {{-- Sélection de la banque --}}
                        <div id="selectionBanqueContainer" style="display:none; margin-bottom:16px;">
                            <div class="form-group" style="margin-bottom: 12px;">
                                <label class="form-label">Sélectionner la Banque <span style="color:var(--danger)">*</span></label>
                                <div style="display:flex; gap:8px;">
                                    <select name="banque_id" id="banqueSelect" class="form-control" style="flex:1;">
                                        <option value="">— Choisir un compte banque —</option>
                                        @foreach($banques as $b)
                                        <option value="{{ $b->id }}">{{ $b->intitule }} ({{ $b->code }} - {{ $b->compte }})</option>
                                        @endforeach
                                    </select>
                                    <button type="button" class="btn btn-primary" onclick="ouvrirModalNouvelleBanque()" style="padding:0 14px;"><i class="fas fa-plus"></i></button>
                                </div>
                            </div>
                            <div class="form-group" style="margin-bottom: 12px;">
                                <label class="form-label">Moyen de paiement bancaire <span style="color:var(--danger)">*</span></label>
                                <select name="moyen_bancaire" id="moyenBancaireSelect" class="form-control">
                                    <option value="">— Moyen de paiement —</option>
                                    <option value="carte">Carte bancaire</option>
                                    <option value="virement">Virement</option>
                                    <option value="cheque">Chèque</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Référence / Numéro <span style="color:var(--danger)">*</span></label>
                                <input type="text" name="reference_paiement" id="refPaiementInput" class="form-control" placeholder="Numéro de carte, virement ou chèque">
                            </div>
                        </div>

                        {{-- Sélection opérateur Mobile Money (logiciel uniquement) --}}
                        <div id="selectionMobileMoneyContainer" style="display:none; margin-bottom:16px;">
                            <div class="form-group">
                                <label class="form-label">Opérateur Mobile Money</label>
                                <select id="mobileMoneyOperateurSelect" class="form-control" onchange="document.getElementById('mobileMoneyOperateurInput').value = this.value">
                                    <option value="">— Sélectionner l'opérateur —</option>
                                    <option value="MTN">MTN Mobile Money</option>
                                    <option value="MOOV">MOOV Money</option>
                                    <option value="ORANGE">Orange Money</option>
                                    <option value="WAVE">Wave</option>
                                </select>
                            </div>
                        </div>

                        {{-- Devise (par défaut FCFA / XOF) --}}
                        <div class="form-group" style="margin-bottom:12px;">
                            <label class="form-label">Devise</label>
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
                        {{-- Taux de change (visible uniquement si devise != XOF) --}}
                        <div id="blocTauxChange" style="display:none; margin-bottom:12px;">
                            <label class="form-label">Taux de change (1 <span id="deviseCodeLabel">EUR</span> = ? FCFA)</label>
                            <div style="display:flex; gap:8px; align-items:center;">
                                <input type="number" id="tauxChangeDisplayInput" class="form-control" placeholder="Ex: 655" step="0.0001" min="0"
                                    onchange="document.getElementById('tauxChangeInput').value = this.value">
                                <button type="button" class="btn btn-outline" id="btnActualiserTaux" onclick="actualiserTauxChange()" style="white-space:nowrap; font-size:12px; padding:6px 12px;">
                                    <i class="fas fa-sync-alt"></i> Actualiser
                                </button>
                            </div>
                            <div style="font-size:11px; color:var(--text-3); margin-top:4px;">Taux récupéré automatiquement. Modifiable si nécessaire.</div>
                        </div>

                        {{-- Montant reçu en Devise Étrangère --}}
                        <div class="form-group" id="blocMontantPayeDevise" style="display:none; margin-bottom:12px;">
                            <label class="form-label">Montant reçu en <span class="deviseCodeText">EUR</span></label>
                            <input type="number" id="montantPayeDeviseInput" class="form-control" placeholder="Saisir le montant dans la devise étrangère" step="0.01" min="0" oninput="calculerMontantEnFcfa()">
                        </div>

                        {{-- Montant reçu --}}
                        <div class="form-group">
                            <label class="form-label" id="labelMontantPaye">Montant à encaisser / reçu (FCFA) <span style="color:var(--danger)">*</span></label>
                            <input type="number" name="montant_paye" id="montantPayeInput" class="form-control" placeholder="Saisir le montant reçu / payé"
                                   oninput="calculerMontantEnDevise(); calculerRenduMonnaie();">
                        </div>

                        {{-- La monnaie rendue. Jamais saisie : elle se déduit de
                             ce qui a été tendu et de ce qui est dû. La laisser
                             saisissable, c'est permettre qu'elle contredise les
                             deux montants entre lesquels elle se tient. --}}
                        <div class="form-group" id="blocMonnaieRendue" style="display:none;">
                            <label class="form-label">Monnaie rendue</label>
                            <input type="text" id="monnaieRendueInput" class="form-control" readonly tabindex="-1"
                                   value="0 F"
                                   style="background:#f0fdf4; border-color:#86efac; color:#15803d; font-weight:800; cursor:default;">
                            <small style="color:var(--text-3); font-size:11px;">
                                Calculée : ce qui a été reçu, moins le net à payer. Elle figure sur la facture et sur le reçu.
                            </small>
                        </div>

                    </div>
                </div>

                <div style="display:flex; flex-direction:column; gap:8px; margin-top:8px;">
                    <button type="submit" class="btn btn-success" id="btnValider" style="width:100%; justify-content:center;" disabled>
                        <i class="fas fa-check-circle"></i> <span id="labelBtnValiderVente">Valider et facturer</span>
                    </button>
                </div>

            </div>
        </div>
    </div>
</div>
</form>

<!-- Modal Saisie Libre -->
<div class="modal-overlay" id="modalSaisieLibre">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-pen-to-square"></i> Saisie libre / Service</h3>
            <button type="button" class="modal-close" onclick="fermerSaisieLibre()">&times;</button>
        </div>
        <form onsubmit="ajouterSaisieLibre(event, true)">
            <div class="form-group">
                <label class="form-label">Désignation / Service <span style="color:var(--danger)">*</span></label>
                <input type="text" id="saisieNomInput" class="form-control" placeholder="Ex: Prestation de service, Produit hors stock" required>
            </div>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label">Prix unitaire (F) <span style="color:var(--danger)">*</span></label>
                    <input type="number" id="saisiePrixInput" class="form-control" min="0" placeholder="Ex: 5000" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Quantité <span style="color:var(--danger)">*</span></label>
                    <input type="number" id="saisieQteInput" class="form-control" min="0.001" step="0.001" value="1" required>
                </div>
            </div>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label">Unité</label>
                    <input type="text" id="saisieUniteInput" class="form-control" value="Unité" placeholder="Ex: Kg, Heure">
                </div>
                {{-- Un seul champ pour la TVA : le code DGI porte deja son taux.
                     La saisie libre du pourcentage laissait passer des valeurs
                     qu'aucun code ne represente (5 %), que la plateforme taxait
                     alors a 18 %. --}}
                <div class="form-group">
                    <label class="form-label">Code TVA transmis à la DGI</label>
                    <select id="saisieCodeTvaInput" class="form-control">
                        @foreach(\App\Modules\Admin\Modeles\Produit::CODES_TVA as $code => $infos)
                            <option value="{{ $code }}" data-taux="{{ $infos['taux'] }}">{{ $infos['libelle'] }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Memes possibilites que pour un article du catalogue : remise de
                 ligne et taxes personnalisees transmises a la FNE. --}}
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label">Remise (%)</label>
                    <input type="number" id="saisieRemiseInput" class="form-control" value="0" min="0" max="100" step="0.01" placeholder="0">
                </div>
                <div class="form-group">
                    <label class="form-label">&nbsp;</label>
                    <small style="display:block; color:var(--text-3); font-size:11px; line-height:1.5;">
                        Le taux appliqué découle du code choisi ci-dessus : la DGI
                        ne reçoit pas un pourcentage, mais un code auquel elle
                        attache elle-même son taux.
                    </small>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Autres taxes</label>
                <div id="saisieTaxesConteneur" style="display:flex; flex-direction:column; gap:8px;"></div>
                <button type="button" class="btn btn-outline btn-sm" onclick="ajouterTaxeSaisieLibre()"
                        style="margin-top:8px; font-size:12px;">
                    <i class="fas fa-plus"></i> Ajouter d'autres taxes
                </button>
                <small style="display:block; margin-top:6px; color:var(--text-3); font-size:11px;">
                    Taux strictement supérieur à 0 et au plus égal à 100 %.
                </small>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:24px;">
                <button type="button" class="btn btn-outline" onclick="fermerSaisieLibre()">Annuler</button>
                <button type="button" class="btn btn-info" onclick="ajouterSaisieLibre(null, false)">Ajouter et continuer</button>
                <button type="submit" class="btn btn-primary">Ajouter au panier</button>
            </div>
        </form>
    </div>
</div>

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

<!-- Modal Rupture de Stock -->
<div class="modal-overlay" id="modalRuptureStock">
    <div class="modal" style="max-width: 480px; text-align: center;">
        <div style="font-size: 48px; color: var(--warning); margin-bottom: 16px;">
            <i class="fas fa-triangle-exclamation"></i>
        </div>
        <h3 style="font-size:18px; font-weight:700; margin-bottom:12px;">Stock local épuisé</h3>
        <p style="color:var(--text-2); font-size:13.5px; line-height:1.6; margin-bottom:20px;">
            Le produit <strong id="ruptureNomProduit" style="color:var(--primary);">—</strong> est en rupture de stock en local (Stock dispo : <span id="ruptureStockDispo">0</span>, Demandé : <span id="ruptureQteDemandee">0</span>).<br>
            Voulez-vous tout de même continuer et autoriser la vente ?
        </p>
        <div style="display:flex; justify-content:center; gap:12px;">
            <button type="button" class="btn btn-outline" onclick="fermerModalRupture()">Annuler</button>
            <button type="button" class="btn btn-primary" id="btnConfirmerRupture">Oui, continuer</button>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
const panier = {};
const stocks = {};

function savePanier() {
    localStorage.setItem('selflow_vente_panier', JSON.stringify(panier));
}

function loadPanier() {
    const stored = localStorage.getItem('selflow_vente_panier');
    if (stored) {
        try {
            const parsed = JSON.parse(stored);
            Object.keys(parsed).forEach(k => {
                panier[k] = parsed[k];
            });
            renderPanier();
        } catch(e) {
            console.error("Erreur chargement panier local:", e);
        }
    }
}

// Initialisation des stocks
@foreach($produits as $p)
stocks[{{ $p->id }}] = {{ $p->stock_actuel }};
@endforeach

document.addEventListener('DOMContentLoaded', () => {
    loadPanier();
});

function formatFcfa(n) {
    return new Intl.NumberFormat('fr-FR').format(Math.round(n)) + ' F';
}

// Lot G : Sélection de l'étape du document de vente
function selectionnerEtapeVente(btn) {
    document.querySelectorAll('[data-etape-vente]').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const etape = btn.dataset.etapeVente;

    // Le reçu n'est plus une pièce à part : il accompagne la facture.
    // `type_piece` reste « facture » — c'est ce que la plateforme reçoit
    // aujourd'hui, et ce que la règle d'or interdit de changer.
    const estRecu = false;
    document.getElementById('etapeInput').value = etape;
    document.getElementById('typePieceInput').value = 'facture';

    const blocPaiement = document.getElementById('blocPaiementVente');
    const infoEtape = document.getElementById('infoEtapeVente');
    const labelBtn = document.getElementById('labelBtnValiderVente');
    const montantPayeInput = document.getElementById('montantPayeInput');

    // La note ne concerne que la pièce encaissée : elle n'a rien à dire
    // d'un devis ni d'un bon de commande, qui ne se certifient pas.
    const noteRecu = document.getElementById('noteRecu');
    if (noteRecu) noteRecu.style.display = (etape === 'Facture') ? 'block' : 'none';

    // Seules les offres ont un terme : une facture engage des son emission.
    const blocValidite = document.getElementById('blocValidite');
    if (blocValidite) {
        blocValidite.style.display = (etape === 'Devis' || etape === 'Bon de commande') ? 'block' : 'none';
    }

    if (etape === 'Facture') {
        blocPaiement.style.display = 'block';
        infoEtape.textContent = 'Facture et reçu établis ensemble, avec règlement';
        labelBtn.textContent = 'Valider et facturer';
        montantPayeInput.removeAttribute('disabled');
    } else if (etape === 'Devis') {
        blocPaiement.style.display = 'none';
        infoEtape.textContent = 'Aucun paiement requis pour un devis';
        labelBtn.textContent = 'Enregistrer le devis';
        montantPayeInput.setAttribute('disabled', '');
    } else {
        blocPaiement.style.display = 'none';
        infoEtape.textContent = 'Aucun paiement requis pour un bon de commande';
        labelBtn.textContent = 'Enregistrer & Envoyer le bon de commande';
        montantPayeInput.setAttribute('disabled', '');
    }
}

function selectionnerModePaiement(btn) {
    // Cibler uniquement les boutons de mode paiement dans le même parent
    btn.closest('div').querySelectorAll('.payment-toggle-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    const mode = btn.dataset.mode;
    document.getElementById('modePaiementInput').value = mode;

    const banqueContainer      = document.getElementById('selectionBanqueContainer');
    const mobileMoneyContainer = document.getElementById('selectionMobileMoneyContainer');
    const banqueSelect         = document.getElementById('banqueSelect');
    const moyenBancaireSelect  = document.getElementById('moyenBancaireSelect');
    const refPaiementInput     = document.getElementById('refPaiementInput');
    const montantInput         = document.getElementById('montantPayeInput');
    const labelMontant         = document.getElementById('labelMontantPaye');

    // Tout masquer d'abord
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

    if (mode === 'Crédit') {
        montantInput.required     = false;
        montantInput.placeholder  = "Laisser vide (Crédit)";
        montantInput.value        = "";
        labelMontant.innerHTML    = 'Montant à encaisser / reçu';
    } else {
        montantInput.required    = true;
        montantInput.placeholder = "Saisir le montant reçu / payé";
        labelMontant.innerHTML   = 'Montant à encaisser / reçu <span style="color:var(--danger)">*</span>';
    }

    // Le droit de timbre ne frappe que les reglements en especes : changer de
    // mode de paiement le fait apparaitre ou disparaitre du net a payer.
    calculerTotaux();
}

function changerDevise(code) {
    document.getElementById('deviseInput').value = code;
    const blocTaux = document.getElementById('blocTauxChange');
    const blocDeviseAmt = document.getElementById('blocMontantPayeDevise');
    const devCodeTexts = document.querySelectorAll('.deviseCodeText');
    
    devCodeTexts.forEach(el => el.textContent = code);

    if (code && code !== 'XOF') {
        blocTaux.style.display = 'block';
        blocDeviseAmt.style.display = 'block';
        document.getElementById('deviseCodeLabel').textContent = code;
        // Réinitialiser et lancer la récupération auto du taux
        document.getElementById('tauxChangeDisplayInput').value = '';
        document.getElementById('tauxChangeInput').value = '';
        document.getElementById('montantPayeDeviseInput').value = '';
        actualiserTauxChange();
    } else {
        blocTaux.style.display = 'none';
        blocDeviseAmt.style.display = 'none';
        document.getElementById('tauxChangeInput').value = '';
        document.getElementById('tauxChangeDisplayInput').value = '';
        document.getElementById('montantPayeDeviseInput').value = '';
    }
}

// Taux de change approximatifs (fallback statique si l'API externe échoue)
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
            // Lancer le recalcul auto après récupération du taux
            calculerMontantEnFcfa();
        } else {
            throw new Error('rate not found');
        }
    } catch (e) {
        const fallback = TAUX_FALLBACK[code] ?? '';
        document.getElementById('tauxChangeDisplayInput').value = fallback;
        document.getElementById('tauxChangeInput').value = fallback;
        calculerMontantEnFcfa();
    }
    if (btn) btn.innerHTML = '<i class="fas fa-sync-alt"></i> Actualiser';
}

function calculerMontantEnFcfa() {
    const devInput = document.getElementById('montantPayeDeviseInput');
    const fcfaInput = document.getElementById('montantPayeInput');
    const tauxInput = document.getElementById('tauxChangeDisplayInput');
    const devise = document.getElementById('deviseInput').value;

    if (devise === 'XOF') return;

    const devValue = parseFloat(devInput.value) || 0;
    const taux = parseFloat(tauxInput.value) || 0;

    if (taux > 0) {
        fcfaInput.value = Math.round(devValue * taux);
    } else {
        fcfaInput.value = '';
    }
    // Déclencher le recalcul interne de la monnaie de rendu de monnaie si disponible
    if (typeof calculerRenduMonnaie === 'function') {
        calculerRenduMonnaie();
    }
}

/**
 * Ce qu'on rend au client.
 *
 * Jamais saisie, toujours calculee : la somme tendue moins le net a payer.
 * Negative, elle n'est pas une monnaie a rendre mais une avance -- le bloc
 * disparait alors, plutot que d'annoncer une dette de la caisse.
 */
function calculerRenduMonnaie() {
    var bloc = document.getElementById('blocMonnaieRendue');
    var champ = document.getElementById('monnaieRendueInput');
    if (!bloc || !champ) return;

    var tendu = parseFloat(document.getElementById('montantPayeInput')?.value) || 0;
    var du = parseFloat(bloc.dataset.net || '0') || 0;
    var rendu = tendu - du;

    if (tendu <= 0 || rendu <= 0) {
        bloc.style.display = 'none';
        champ.value = '0 F';
        return;
    }

    bloc.style.display = 'block';
    champ.value = Math.round(rendu).toLocaleString('fr-FR') + ' F';
}

function calculerMontantEnDevise() {
    const devInput = document.getElementById('montantPayeDeviseInput');
    const fcfaInput = document.getElementById('montantPayeInput');
    const tauxInput = document.getElementById('tauxChangeDisplayInput');
    const devise = document.getElementById('deviseInput').value;

    if (devise === 'XOF') return;

    const fcfaValue = parseFloat(fcfaInput.value) || 0;
    const taux = parseFloat(tauxInput.value) || 0;

    if (taux > 0) {
        devInput.value = (fcfaValue / taux).toFixed(2);
    } else {
        devInput.value = '';
    }
}

function ouvrirSaisieLibre() {
    document.getElementById('modalSaisieLibre').classList.add('open');
}

function fermerSaisieLibre() {
    document.getElementById('modalSaisieLibre').classList.remove('open');
    document.getElementById('saisieNomInput').value = '';
    document.getElementById('saisiePrixInput').value = '';
    document.getElementById('saisieQteInput').value = '1';
    document.getElementById('saisieUniteInput').value = 'Unité';
    document.getElementById('saisieCodeTvaInput').value = 'TVA';
}

/**
 * Borne un taux saisi entre 0 et 100 : aucune remise ni taxe ne peut sortir
 * de cet intervalle, c'est la regle imposee par la DGI.
 */
function bornerTaux(valeur) {
    let taux = parseFloat(valeur);
    if (isNaN(taux) || taux < 0) return 0;
    return Math.min(taux, 100);
}

function ajouterTaxeSaisieLibre(nom, taux) {
    const conteneur = document.getElementById('saisieTaxesConteneur');
    if (!conteneur) return;

    const ligne = document.createElement('div');
    ligne.style.cssText = 'display:flex; gap:8px; align-items:center;';
    ligne.innerHTML = `
        <input type="text" class="form-control taxe-nom" placeholder="Nom (ex : GRA)" maxlength="100"
               value="${nom ? String(nom).replace(/"/g, '&quot;') : ''}" style="flex:2;">
        <input type="number" class="form-control taxe-taux" placeholder="Taux (%)" min="0.01" max="100" step="0.01"
               value="${taux !== undefined ? taux : ''}" style="flex:1;">
        <button type="button" class="remove-btn" title="Supprimer" onclick="this.closest('div').remove()">
            <i class="fas fa-trash"></i>
        </button>
    `;
    conteneur.appendChild(ligne);
}

function lireTaxesSaisieLibre() {
    const taxes = [];
    document.querySelectorAll('#saisieTaxesConteneur > div').forEach(ligne => {
        const nom  = ligne.querySelector('.taxe-nom')?.value.trim();
        const taux = bornerTaux(ligne.querySelector('.taxe-taux')?.value);
        if (nom && taux > 0) taxes.push({ nom, taux });
    });
    return taxes;
}

function ajouterSaisieLibre(e, fermer = true) {
    if (e) e.preventDefault();
    
    const form = document.querySelector('#modalSaisieLibre form');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const nomInput = document.getElementById('saisieNomInput');
    const prixInput = document.getElementById('saisiePrixInput');
    const qteInput = document.getElementById('saisieQteInput');
    const uniteInput = document.getElementById('saisieUniteInput');
    const codeTvaSelect = document.getElementById('saisieCodeTvaInput');
    
    const nom = nomInput.value.trim();
    const prix = parseFloat(prixInput.value);
    const qte = quantiteSaisie(qteInput.value);
    const unite = uniteInput.value.trim() || 'Unité';
    // Le code choisi porte son taux : c'est lui qui fait foi, des deux cotes.
    const codeTva = codeTvaSelect?.value || 'TVA';
    const tva = parseFloat(CODES_TVA_DGI[codeTva]?.taux ?? 18);

    // Remise de ligne et taxes propres a la saisie libre, comme pour un
    // article du catalogue.
    const remise = bornerTaux(document.getElementById('saisieRemiseInput')?.value);
    const taxes = lireTaxesSaisieLibre();

    const id = 'v_' + Date.now();
    panier[id] = {
        nom, prix, qte, stock: 99999, stock_minimum: 0, unite, tva,
        remise_taux: remise, code_tva: codeTva, taxes: taxes, isVirtual: true
    };

    if (fermer) {
        fermerSaisieLibre();
    } else {
        nomInput.value = '';
        prixInput.value = '';
        qteInput.value = '1';
        uniteInput.value = 'Unité';
        document.getElementById('saisieRemiseInput').value = '0';
        document.getElementById('saisieCodeTvaInput').value = 'TVA';
        document.getElementById('saisieTaxesConteneur').innerHTML = '';
        nomInput.focus();
    }
    savePanier();
    renderPanier();
}

function ajouterAuPanier(card) {
    const id        = parseInt(card.dataset.id);
    const nom       = card.dataset.nom;
    const prix      = parseFloat(card.dataset.prix);
    const stock     = parseInt(card.dataset.stock);
    const stock_min = parseInt(card.dataset.stockMin || 5);
    // Un service ne suit aucun stock : lui opposer une rupture n'a pas de sens,
    // et le serveur ne le decremente pas davantage.
    const suitLeStock = card.dataset.stockable !== '0';
    const unite     = card.dataset.unite || 'Unité';
    const tva       = parseFloat(card.dataset.tva || 18);
    // Remise par defaut du produit, reprise telle quelle sur la ligne
    const remise    = parseFloat(card.dataset.remise || 0);
    // Taxes propres au produit : elles s'ajoutent au montant a payer
    let taxesProduit = [];
    try { taxesProduit = JSON.parse(card.dataset.taxes || '[]'); } catch (e) { taxesProduit = []; }

    if (panier[id]) {
        const nouvelleQte = panier[id].qte + 1;
        if (suitLeStock && nouvelleQte > stock) {
            ouvrirModalRupture(id, nouvelleQte);
        } else {
            panier[id].qte++;
            verifierLimiteMinimale(panier[id]);
            savePanier();
            renderPanier();
        }
    } else {
        if (suitLeStock && stock <= 0) {
            ouvrirModalRuptureVirtual(id, nom, prix, stock, stock_min, unite, tva, remise, taxesProduit);
        } else {
            panier[id] = { nom, prix, qte: 1, stock, stock_minimum: stock_min, unite: unite, tva, remise_taux: remise, taxes: taxesProduit, isVirtual: false, suitLeStock };
            verifierLimiteMinimale(panier[id]);
            savePanier();
            renderPanier();
        }
    }
}

// Un panier restaure depuis le navigateur peut dater d'avant l'ajout de la
// cle : dans le doute on suppose que l'article suit le stock, ce qui est le
// comportement d'avant.
function articleSuitLeStock(item) {
    return item.suitLeStock !== false;
}

function changerQte(id, delta) {
    if (!panier[id]) return;
    const item = panier[id];

    if (item.isVirtual || !articleSuitLeStock(item)) {
        item.qte += delta;
        if (item.qte <= 0) {
            delete panier[id];
        }
        savePanier();
        renderPanier();
        return;
    }

    const nouvelleQte = item.qte + delta;

    if (nouvelleQte <= 0) {
        delete panier[id];
        savePanier();
        renderPanier();
        return;
    }

    if (nouvelleQte > item.stock) {
        ouvrirModalRupture(id, nouvelleQte);
    } else {
        item.qte = nouvelleQte;
        verifierLimiteMinimale(item);
        savePanier();
        renderPanier();
    }
}

function saisirQte(id, val) {
    let q = quantiteSaisie(val);

    const item = panier[id];
    if (!item) return;

    if (item.isVirtual || !articleSuitLeStock(item)) {
        item.qte = q;
        savePanier();
        renderPanier();
        return;
    }

    if (q > item.stock) {
        ouvrirModalRupture(id, q);
    } else {
        item.qte = q;
        verifierLimiteMinimale(item);
        savePanier();
        renderPanier();
    }
}

function supprimerItem(id) {
    delete panier[id];
    savePanier();
    renderPanier();
}

// Ouvrir modal rupture standard
function ouvrirModalRupture(id, qte) {
    const item = panier[id];
    document.getElementById('ruptureNomProduit').textContent = item.nom;
    document.getElementById('ruptureQteDemandee').textContent = qte;
    document.getElementById('ruptureStockDispo').textContent = item.stock;
    
    document.getElementById('btnConfirmerRupture').onclick = function() {
        item.qte = qte;
        fermerModalRupture();
        verifierLimiteMinimale(item);
        savePanier();
        renderPanier();
    };
    
    document.getElementById('modalRuptureStock').classList.add('open');
}

// Ouvrir modal rupture virtuel
function ouvrirModalRuptureVirtual(id, nom, prix, stock, stock_min, unite, tva, remise, taxesProduit) {
    document.getElementById('ruptureNomProduit').textContent = nom;
    document.getElementById('ruptureQteDemandee').textContent = 1;
    document.getElementById('ruptureStockDispo').textContent = stock;
    
    document.getElementById('btnConfirmerRupture').onclick = function() {
        panier[id] = { nom, prix, qte: 1, stock, stock_minimum: stock_min, unite: unite, tva, remise_taux: remise || 0, taxes: taxesProduit || [], isVirtual: false };
        fermerModalRupture();
        savePanier();
        renderPanier();
    };
    
    document.getElementById('modalRuptureStock').classList.add('open');
}

function fermerModalRupture() {
    document.getElementById('modalRuptureStock').classList.remove('open');
}

function verifierLimiteMinimale(item) {
    if (!articleSuitLeStock(item)) return;
    const stockRestant = item.stock - item.qte;
    if (stockRestant <= item.stock_minimum) {
        afficherAlerteStockMin(item.nom, item.stock_minimum);
    }
}

function afficherAlerteStockMin(nom, limite) {
    let alertContainer = document.getElementById('alertesStocksContainer');
    if (!alertContainer) {
        alertContainer = document.createElement('div');
        alertContainer.id = 'alertesStocksContainer';
        alertContainer.style.position = 'fixed';
        alertContainer.style.bottom = '20px';
        alertContainer.style.left = '20px';
        alertContainer.style.zIndex = '9999';
        alertContainer.style.display = 'flex';
        alertContainer.style.flexDirection = 'column';
        alertContainer.style.gap = '10px';
        document.body.appendChild(alertContainer);
    }
    
    const existing = document.querySelector(`[data-alert-prod="${nom}"]`);
    if (existing) return;

    const toast = document.createElement('div');
    toast.className = 'alert alert-warning';
    toast.setAttribute('data-alert-prod', nom);
    toast.style.margin = '0';
    toast.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
    toast.style.animation = 'slideIn 0.3s ease';
    toast.innerHTML = `<i class="fas fa-triangle-exclamation"></i> <div><strong>${nom}</strong> : Stock minimum atteint (${limite}) !</div>`;
    
    alertContainer.appendChild(toast);
    
    setTimeout(() => {
        toast.style.transition = 'opacity 0.4s';
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 400);
    }, 3500);
}

function renderPanier() {
    const container = document.getElementById('panierItems');
    const vide      = document.getElementById('panierVide');
    const nbArt     = document.getElementById('nbArticles');
    const btnVal    = document.getElementById('btnValider');
    const btnDevis  = document.getElementById('btnDevis');

    const ids = Object.keys(panier);
    if (ids.length === 0) {
        container.innerHTML = '';
        vide.style.display = 'block';
        nbArt.textContent = '0 article(s)';
        btnVal.disabled = true;
        if (btnDevis) btnDevis.disabled = true;
        
        document.querySelectorAll('.article-input').forEach(e => e.remove());
        calculerTotaux();
        return;
    }

    vide.style.display = 'none';
    container.innerHTML = '';
    document.querySelectorAll('.article-input').forEach(e => e.remove());

    ids.forEach((id, idx) => {
        const item = panier[id];
        const sousTotal = item.prix * item.qte;

        const remiseLigne = parseFloat(item.remise_taux || 0);
        const sousTotalNet = sousTotal * (1 - remiseLigne / 100);

        const div = document.createElement('div');
        div.className = 'panier-item';
        div.innerHTML = `
            <div class="item-corps">
                <div class="item-nom">${item.nom}</div>
                <div class="item-prix">${formatFcfa(item.prix)} × ${item.qte} = <strong>${formatFcfa(sousTotalNet)}</strong>${remiseLigne > 0 ? ` <span style="color:#dc2626;">(−${remiseLigne}%)</span>` : ''}</div>
                ${(item.taxes || []).length ? `<div style="font-size:10px; color:var(--text-3);">${item.taxes.map(t => `${t.nom} ${t.taux}%`).join(' · ')}</div>` : ''}
                <div class="champs-ligne">
                    <label>Unité
                        <input type="text" class="form-control form-control-sm" value="${item.unite || 'Unité'}" onchange="saisirUnite('${id}', this.value)" style="width: 76px;">
                    </label>
                    <label>Remise&nbsp;(%)
                        <input type="number" class="form-control form-control-sm" value="${remiseLigne}" min="0" max="100" step="0.01" oninput="saisirRemiseLigne('${id}', this.value)" style="width: 64px;">
                    </label>
                    <label>TVA
                        ${selecteurCodeTva(id, item)}
                    </label>
                </div>
                ${alerteCodeTva(item)}
            </div>
            <div class="qte-ctrl">
                <button type="button" class="qte-btn" onclick="changerQte('${id}', -1)">−</button>
                <input type="number" class="qte-input" value="${item.qte}" min="0.001" step="0.001" onchange="saisirQte('${id}', this.value)">
                <button type="button" class="qte-btn" onclick="changerQte('${id}', 1)">+</button>
            </div>
            <button type="button" class="remove-btn" onclick="supprimerItem('${id}')">
                <i class="fas fa-trash"></i>
            </button>
        `;
        container.appendChild(div);

        const form = document.getElementById('formVente');
        ['produit_id', 'quantite', 'libelle_virtuel', 'prix_unitaire', 'unite', 'tva', 'remise_taux', 'code_tva'].forEach(field => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `articles[${idx}][${field}]`;

            if (field === 'produit_id') {
                input.value = item.isVirtual ? '' : id;
            } else if (field === 'quantite') {
                input.value = item.qte;
            } else if (field === 'libelle_virtuel') {
                input.value = item.isVirtual ? item.nom : '';
            } else if (field === 'prix_unitaire') {
                input.value = item.isVirtual ? item.prix : '';
            } else if (field === 'unite') {
                input.value = item.unite || 'Unité';
            } else if (field === 'tva') {
                input.value = item.tva || 0;
            } else if (field === 'remise_taux') {
                input.value = item.remise_taux || 0;
            } else if (field === 'code_tva') {
                input.value = item.code_tva || '';
            }

            input.className = 'article-input';
            form.appendChild(input);
        });

        // Taxes personnalisées saisies sur une ligne libre : celles d'un
        // article du catalogue sont reprises côté serveur depuis sa fiche.
        (item.taxes || []).forEach((taxe, rang) => {
            [['nom', taxe.nom], ['taux', taxe.taux]].forEach(([champ, valeur]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `articles[${idx}][taxes][${rang}][${champ}]`;
                input.value = valeur;
                input.className = 'article-input';
                form.appendChild(input);
            });
        });
    });

    nbArt.textContent = ids.length + ' article(s)';
    btnVal.disabled = false;
    if (btnDevis) btnDevis.disabled = false;
    calculerTotaux();
}

function saisirUnite(id, val) {
    if (panier[id]) {
        panier[id].unite = val;
        savePanier();
        renderPanier();
    }
}

/**
 * Taux de TVA de la ligne, modifiable au cas par cas comme la remise.
 *
 * Le taux vient de la fiche produit, mais une vente peut relever d'un régime
 * différent (exonération conventionnelle, marché public…) : il reste donc
 * ajustable sur la pièce sans toucher au catalogue.
 */
/**
 * Codes TVA reconnus par la DGI, avec le taux que la plateforme applique a
 * chacun. C'est la liste de reference : la FNE ne recoit pas un pourcentage
 * mais un code, et un taux hors de cette table n'a nulle part ou se ranger.
 */
const CODES_TVA_DGI = {!! json_encode(\App\Modules\Admin\Modeles\Produit::CODES_TVA) !!};
const REGIME_ENTREPRISE = {!! json_encode(Auth::user()->entreprise->regime_imposition ?? null) !!};
// La constante gelee du modele, et non une copie : celle qui vivait ici
// valait ['TEE', 'RNE'] quand le serveur retient TEE, TCE et RME. L'ecran
// annoncait donc un code TVA que le payload ne transmettait pas.
const REGIMES_EXONERATION_LEGALE = {!! json_encode(\App\Modules\Admin\Modeles\Produit::REGIMES_EXONERATION_LEGALE) !!};

/**
 * Meme regle que Produit::deduireCodeTva() cote serveur : a 0 %, seul le
 * regime distingue l'exoneration conventionnelle (TVAC) de l'exoneration
 * legale (TVAD, reservee aux regimes que la DGI enumere).
 */
function deduireCodeTvaDgi(taux) {
    const t = Math.round(parseFloat(taux || 0) * 100) / 100;
    if (t === 18) return 'TVA';
    if (t === 9)  return 'TVAB';
    if (t === 0)  return REGIMES_EXONERATION_LEGALE.includes(REGIME_ENTREPRISE) ? 'TVAD' : 'TVAC';
    return null; // taux hors bareme : aucun code ne le represente
}

/** Code retenu pour une ligne : le choix explicite prime sur la deduction. */
function codeTvaDeLaLigne(item) {
    if (item.code_tva && CODES_TVA_DGI[item.code_tva]) return item.code_tva;
    return deduireCodeTvaDgi(item.tva);
}

/**
 * Liste deroulante du code TVA d'une ligne du panier.
 *
 * Elle remplace la saisie libre du taux : celle-ci laissait passer des valeurs
 * comme 5 %, qu'aucun code DGI ne represente. La plateforme les taxait alors a
 * 18 % et la facture certifiee ne correspondait plus a celle etablie ici.
 *
 * Une ligne portant deja un taux hors bareme (article ancien, panier
 * enregistre) garde une option supplementaire, desactivee, qui rend le
 * probleme visible au lieu de le corriger dans le dos de l'utilisateur.
 */
function selecteurCodeTva(id, item) {
    const codeCourant = codeTvaDeLaLigne(item);
    let options = '';

    if (codeCourant === null) {
        const taux = String(item.tva || 0).replace('.', ',');
        options += `<option value="" selected disabled>Hors barème : ${taux} %</option>`;
    }

    options += Object.keys(CODES_TVA_DGI).map(function (code) {
        const infos = CODES_TVA_DGI[code];
        const taux = String(infos.taux).replace('.', ',').replace(',00', '');
        return `<option value="${code}"${code === codeCourant ? ' selected' : ''} title="${infos.libelle}">`
             + `${code} — ${taux} %</option>`;
    }).join('');

    return `<select class="form-control form-control-sm" style="width:132px;"
                    onchange="saisirCodeTvaLigne('${id}', this.value)">${options}</select>`;
}

/** Avertissement affiche sous une ligne dont le taux ne correspond a aucun code DGI. */
function alerteCodeTva(item) {
    if (codeTvaDeLaLigne(item) !== null) return '';
    return '<div style="font-size:10px;color:#b45309;background:#fef3c7;border-radius:4px;'
         + 'padding:3px 6px;margin-top:4px;">'
         + '<i class="fas fa-triangle-exclamation"></i> '
         + 'Taux hors barème DGI : choisissez un code ci-dessus, sinon cette facture '
         + 'ne pourra pas être normalisée.'
         + '</div>';
}

/**
 * Le code choisi porte son taux : les deux sont mis a jour ensemble, faute de
 * quoi la facture afficherait un taux et transmettrait un autre code.
 */
function saisirCodeTvaLigne(id, code) {
    if (!panier[id] || !CODES_TVA_DGI[code]) return;

    panier[id].code_tva = code;
    panier[id].tva = parseFloat(CODES_TVA_DGI[code].taux);
    savePanier();

    renderPanier();
    calculerTotaux();
}

function saisirRemiseLigne(id, val) {
    if (!panier[id]) return;

    let taux = parseFloat(val);
    if (isNaN(taux) || taux < 0) taux = 0;
    if (taux > 100) taux = 100;

    panier[id].remise_taux = taux;
    savePanier();

    // On ne reconstruit pas le panier : cela rendrait la saisie impossible en
    // remplaçant le champ à chaque frappe. Seuls les totaux et le sous-total de
    // la ligne sont rafraîchis.
    rafraichirSousTotalLigne(id);
    majChampsArticles();
    calculerTotaux();
}

/**
 * Met à jour l'affichage « P.U. × Qté = Total » de la ligne concernée.
 */
function rafraichirSousTotalLigne(id) {
    const item = panier[id];
    if (!item) return;

    const champ = document.querySelector(`#panierItems input[oninput*="saisirRemiseLigne('${id}'"]`);
    const ligne = champ ? champ.closest('.panier-item') : null;
    const affichage = ligne ? ligne.querySelector('.item-prix') : null;
    if (!affichage) return;

    const remise = parseFloat(item.remise_taux || 0);
    const net = item.prix * item.qte * (1 - remise / 100);
    affichage.innerHTML = `${formatFcfa(item.prix)} × ${item.qte} = <strong>${formatFcfa(net)}</strong>`
        + (remise > 0 ? ` <span style="color:#dc2626;">−${remise}%</span>` : '');
}

/**
 * Réécrit les champs cachés envoyés avec le formulaire, sans toucher au DOM
 * visible du panier.
 */
function majChampsArticles() {
    const ids = Object.keys(panier);

    document.querySelectorAll('.article-input[name*="[remise_taux]"]').forEach((champ, rang) => {
        const id = ids[rang];
        if (id !== undefined) champ.value = panier[id].remise_taux || 0;
    });

    document.querySelectorAll('.article-input[name*="[tva]"]').forEach((champ, rang) => {
        const id = ids[rang];
        if (id !== undefined) champ.value = panier[id].tva || 0;
    });
}

// ─── Taxes sur total TTC (champ `customTaxes` de la FNE) ────────────────────

function ajouterTaxeTtc(nom, taux) {
    const conteneur = document.getElementById('taxesTtcConteneur');
    if (!conteneur) return;

    const index = conteneur.children.length;
    const ligne = document.createElement('div');
    ligne.style.cssText = 'display:flex; gap:6px; align-items:center;';
    ligne.innerHTML = `
        <input type="text" name="taxes_ttc[${index}][nom]" class="form-control form-control-sm"
               placeholder="Nom (ex : DTD)" maxlength="100" value="${nom ? String(nom).replace(/"/g, '&quot;') : ''}"
               style="flex:2; height:28px; font-size:12px;">
        <input type="number" name="taxes_ttc[${index}][taux]" class="form-control form-control-sm"
               placeholder="Taxe (%)" min="0.01" max="100" step="0.01" value="${taux !== undefined ? taux : ''}"
               oninput="calculerTotaux()" style="flex:1; height:28px; font-size:12px;">
        <span class="montant-taxe-ttc" style="flex:1; font-size:12px; font-weight:700; text-align:right; white-space:nowrap;">0 F</span>
        <button type="button" class="remove-btn" title="Supprimer cette taxe" onclick="supprimerTaxeTtc(this)">
            <i class="fas fa-trash"></i>
        </button>
    `;
    conteneur.appendChild(ligne);
    calculerTotaux();
}

function supprimerTaxeTtc(bouton) {
    bouton.closest('div').remove();

    // Réindexer pour que Laravel reçoive bien un tableau continu
    const conteneur = document.getElementById('taxesTtcConteneur');
    Array.from(conteneur.children).forEach((ligne, index) => {
        const champs = ligne.querySelectorAll('input');
        if (champs[0]) champs[0].name = `taxes_ttc[${index}][nom]`;
        if (champs[1]) champs[1].name = `taxes_ttc[${index}][taux]`;
    });

    calculerTotaux();
}


/**
 * Ordre de calcul aligné sur le récapitulatif de la FNE :
 *   Total HT → remise ligne → remise globale → TVA → TTC → autres taxes.
 */
/**
 * Droit de timbre de quittance — bareme de l'article 873 du CGI.
 *
 * Meme table que TimbreQuittanceService cote serveur, qui fait foi. Le calcul
 * est repris ici pour que le caissier voie la somme a encaisser sans attendre
 * la normalisation. Les bornes sont inclusives : 5 000 F n'est pas timbre.
 */
{{-- Le bareme vient de `TimbreQuittanceService`, et non d'une copie
     ecrite ici : c'est le perimetre gele de la conformite FNE, et une
     seconde copie finit par deriver. --}}
@include('admin::factures.partials.script-timbre')

function calculerTotaux() {
    let totalHt = 0;

    Object.keys(panier).forEach(id => {
        const item = panier[id];
        const remiseLigne = parseFloat(item.remise_taux || 0);
        totalHt += item.prix * item.qte * (1 - remiseLigne / 100);
    });

    let remiseTaux = parseFloat(document.getElementById('remiseTauxInput')?.value || 0);
    if (isNaN(remiseTaux) || remiseTaux < 0) remiseTaux = 0;
    if (remiseTaux > 100) remiseTaux = 100;

    const montantRemise = totalHt * (remiseTaux / 100);
    const totalHtNet    = Math.max(0, totalHt - montantRemise);
    const ratio         = totalHt > 0 ? totalHtNet / totalHt : 0;

    let totalTva = 0;
    Object.keys(panier).forEach(id => {
        const item = panier[id];
        const remiseLigne = parseFloat(item.remise_taux || 0);
        const itemHtNet = item.prix * item.qte * (1 - remiseLigne / 100) * ratio;
        totalTva += itemHtNet * ((item.tva || 0) / 100);
    });

    const totalTtc = totalHtNet + totalTva;

    // Autres taxes. Deux niveaux, comme la FNE : celles propres a chaque
    // article, calculees sur son HT net, et celles portant sur le total TTC.
    let totalAutresTaxes = 0;

    Object.keys(panier).forEach(id => {
        const item = panier[id];
        const remiseLigne = parseFloat(item.remise_taux || 0);
        const htNet = item.prix * item.qte * (1 - remiseLigne / 100) * ratio;
        (item.taxes || []).forEach(t => {
            totalAutresTaxes += htNet * (parseFloat(t.taux) || 0) / 100;
        });
    });

    document.querySelectorAll('#taxesTtcConteneur > div').forEach(ligne => {
        const taux = parseFloat(ligne.querySelectorAll('input')[1]?.value || 0);
        const montant = (isNaN(taux) ? 0 : Math.min(Math.max(taux, 0), 100)) / 100 * totalTtc;
        totalAutresTaxes += montant;
        const affichage = ligne.querySelector('.montant-taxe-ttc');
        if (affichage) affichage.textContent = formatFcfa(montant);
    });

    document.getElementById('totalHt').textContent          = formatFcfa(totalHt);
    document.getElementById('montantRemise').textContent    = formatFcfa(montantRemise);
    document.getElementById('totalHtNet').textContent       = formatFcfa(totalHtNet);
    document.getElementById('totalTva').textContent         = formatFcfa(totalTva);
    document.getElementById('totalTtc').textContent         = formatFcfa(totalTtc);
    document.getElementById('totalAutresTaxes').textContent = formatFcfa(totalAutresTaxes);

    // Le timbre porte sur la somme reellement encaissee, taxes comprises.
    const modePaiement = document.getElementById('modePaiementInput')?.value;
    const timbre = timbreDeQuittance(totalTtc + totalAutresTaxes, modePaiement);
    const ligneTimbre = document.getElementById('ligneTimbre');
    if (ligneTimbre) ligneTimbre.style.display = timbre > 0 ? '' : 'none';
    document.getElementById('totalTimbre').textContent = formatFcfa(timbre);

    const netAPayer = totalTtc + totalAutresTaxes + timbre;
    document.getElementById('netAPayer').textContent = formatFcfa(netAPayer);

    // Le net sert deux fois : a l'afficher, et a etablir la monnaie rendue.
    // Il se depose ici plutot que d'etre recalcule ailleurs, ou les deux
    // finiraient par diverger.
    const blocRendu = document.getElementById('blocMonnaieRendue');
    if (blocRendu) {
        blocRendu.dataset.net = netAPayer;
        calculerRenduMonnaie();
    }

    const inputMontant = document.getElementById('montantPayeInput');
    if (inputMontant) {
        inputMontant.placeholder = `${Math.round(netAPayer)}`;
    }
}

document.addEventListener('DOMContentLoaded', function () {
});

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
    
    const routeCreation = "{{ request()->routeIs('caissier.*') ? route('caissier.banques.creer') : route('admin.banques.creer') }}";
    
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

// Filtre catégorie
document.querySelectorAll('.cat-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        const cat = this.dataset.cat;
        document.querySelectorAll('.produit-card').forEach(card => {
            card.style.display = (cat === 'all' || card.dataset.cat === cat) ? '' : 'none';
        });
    });
});

// Recherche en temps réel
document.getElementById('rechercheInput').addEventListener('input', function() {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('.produit-card').forEach(card => {
        card.style.display = card.dataset.nom.toLowerCase().includes(q) ? '' : 'none';
    });
});

// Validation du montant payé avant soumission pour ne pas vider le panier
document.getElementById('formVente').addEventListener('submit', function(e) {
    const etape = document.getElementById('etapeInput').value;
    if (etape === 'Facture') {
        const mode = document.getElementById('modePaiementInput').value;
        if (mode !== 'Crédit') {
            const montantInput = document.getElementById('montantPayeInput');
            const montant = parseFloat(montantInput.value);
            if (isNaN(montant) || montant <= 0) {
                e.preventDefault();
                alert("Le montant payé est obligatoire et doit être strictement supérieur à 0 pour ce mode de paiement (Caisse / Banque).");
                montantInput.focus();
                return false;
            }
        }
    }
});

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

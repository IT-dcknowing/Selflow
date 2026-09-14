@extends('admin::gabarits.application')
@section('titre', 'Factures — Achats')
@section('topbar_titre', $type === 'avoir' ? 'Achats — Avoirs' : 'Achats — Factures & Commandes')

@section('contenu')
<div class="page-header">
    <div>
        @if($type === 'avoir')
            <h1><i class="fas fa-file-circle-minus" style="color:#e17055;"></i> Avoirs Fournisseurs</h1>
            <p>{{ $achats->total() }} avoir(s) fournisseur(s) au total</p>
        @else
            <h1><i class="fas fa-file-invoice-dollar"></i> Cycles d'achat</h1>
            <p>Suivi complet des demandes de prix, bons de commande et factures</p>
        @endif
    </div>
    @if($type === 'avoir')
        <button type="button" class="btn" style="background:#e17055; color:#fff;" onclick="ouvrirModalNouveauAvoir()">
            <i class="fas fa-plus"></i> Créer une facture d'avoir
        </button>
    @else
        <a href="{{ route('admin.achats.nouveau') }}" class="btn btn-primary">
            <i class="fas fa-plus"></i> Nouvel achat
        </a>
    @endif
</div>

{{-- Si ce n'est pas un Avoir, on affiche les onglets cliquables du Workflow Achat --}}
@if($type !== 'avoir')
<div style="display:flex; gap:10px; margin-bottom:20px; border-bottom:1.5px solid var(--border); padding-bottom:12px; flex-wrap:wrap;">
    <a href="{{ route('admin.achats.factures', ['etape' => 'Demande de prix']) }}" 
       style="display:flex; align-items:center; gap:8px; padding:10px 20px; border-radius:8px; text-decoration:none; font-weight:700; font-size:13.5px; transition:all 0.2s; 
              {{ $etapeActive === 'Demande de prix' ? 'background:#0D1B3E; color:#fff;' : 'background:#fff; color:var(--text-2); border:0.5px solid var(--border);' }}">
        <i class="fas fa-file-invoice" style="font-size:14px; {{ $etapeActive === 'Demande de prix' ? 'color:#fff;' : 'color:#0D1B3E;' }}"></i>
        Demande de prix
        <span style="font-size:11px; padding:2px 8px; border-radius:20px; font-weight:800;
                     {{ $etapeActive === 'Demande de prix' ? 'background:rgba(255,255,255,0.2); color:#fff;' : 'background:var(--bg3); color:var(--primary);' }}">
            {{ $nbDP }}
        </span>
    </a>

    <a href="{{ route('admin.achats.factures', ['etape' => 'Bon de commande']) }}" 
       style="display:flex; align-items:center; gap:8px; padding:10px 20px; border-radius:8px; text-decoration:none; font-weight:700; font-size:13.5px; transition:all 0.2s; 
              {{ $etapeActive === 'Bon de commande' ? 'background:#0D1B3E; color:#fff;' : 'background:#fff; color:var(--text-2); border:0.5px solid var(--border);' }}">
        <i class="fas fa-shopping-basket" style="font-size:14px; {{ $etapeActive === 'Bon de commande' ? 'color:#fff;' : 'color:#0D1B3E;' }}"></i>
        Bon de commande
        <span style="font-size:11px; padding:2px 8px; border-radius:20px; font-weight:800;
                     {{ $etapeActive === 'Bon de commande' ? 'background:rgba(255,255,255,0.2); color:#fff;' : 'background:var(--bg3); color:var(--primary);' }}">
            {{ $nbBC }}
        </span>
    </a>

    <a href="{{ route('admin.achats.factures', ['etape' => 'Facture']) }}" 
       style="display:flex; align-items:center; gap:8px; padding:10px 20px; border-radius:8px; text-decoration:none; font-weight:700; font-size:13.5px; transition:all 0.2s; 
              {{ $etapeActive === 'Facture' ? 'background:#0D1B3E; color:#fff;' : 'background:#fff; color:var(--text-2); border:0.5px solid var(--border);' }}">
        <i class="fas fa-check-double" style="font-size:14px; {{ $etapeActive === 'Facture' ? 'color:#fff;' : 'color:#0D1B3E;' }}"></i>
        Facture
        <span style="font-size:11px; padding:2px 8px; border-radius:20px; font-weight:800;
                     {{ $etapeActive === 'Facture' ? 'background:rgba(255,255,255,0.2); color:#fff;' : 'background:var(--bg3); color:var(--primary);' }}">
            {{ $nbFacture }}
        </span>
    </a>
</div>
@endif

<form method="GET" action="{{ route('admin.achats.factures') }}" id="formFiltreAchats" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end; margin-bottom:16px; background:#fff; border:1px solid var(--border); border-radius:12px; padding:14px 16px;">
    <input type="hidden" name="etape" value="{{ $etapeActive }}">
    @if($type) <input type="hidden" name="type" value="{{ $type }}"> @endif

    <div class="form-group" style="margin-bottom:0; flex:1; min-width:200px;">
        <label class="form-label" style="font-size:11px; text-transform:uppercase; font-weight:700; color:var(--text-3); margin-bottom:4px; display:block;">Recherche</label>
        <input type="text" name="recherche" value="{{ request('recherche') }}" class="form-control" placeholder="N° facture, N° FNE, fournisseur..."
               oninput="clearTimeout(window._filtreAchatsTimer); window._filtreAchatsTimer = setTimeout(() => document.getElementById('formFiltreAchats').submit(), 500);">
    </div>
    @if($etapeActive === 'Facture')
    <div class="form-group" style="margin-bottom:0;">
        <label class="form-label" style="font-size:11px; text-transform:uppercase; font-weight:700; color:var(--text-3); margin-bottom:4px; display:block;">Statut</label>
        <select name="statut_filtre" class="form-control" onchange="document.getElementById('formFiltreAchats').submit();">
            <option value="">— Tous —</option>
            @foreach(['Payé', 'Avance', 'Crédit'] as $s)
                <option value="{{ $s }}" {{ request('statut_filtre') === $s ? 'selected' : '' }}>{{ $s }}</option>
            @endforeach
        </select>
    </div>
    <div class="form-group" style="margin-bottom:0;">
        <label class="form-label" style="font-size:11px; text-transform:uppercase; font-weight:700; color:var(--text-3); margin-bottom:4px; display:block;">Statut DGI</label>
        <select name="dgi_filtre" class="form-control" onchange="document.getElementById('formFiltreAchats').submit();">
            <option value="">— Tous —</option>
            <option value="oui" {{ request('dgi_filtre') === 'oui' ? 'selected' : '' }}>Normalisée (DGI)</option>
            <option value="non" {{ request('dgi_filtre') === 'non' ? 'selected' : '' }}>Non normalisée</option>
        </select>
    </div>
    @endif
    <div class="form-group" style="margin-bottom:0;">
        <label class="form-label" style="font-size:11px; text-transform:uppercase; font-weight:700; color:var(--text-3); margin-bottom:4px; display:block;">Du</label>
        <input type="date" name="date_debut" value="{{ request('date_debut') }}" class="form-control" onchange="document.getElementById('formFiltreAchats').submit();">
    </div>
    <div class="form-group" style="margin-bottom:0;">
        <label class="form-label" style="font-size:11px; text-transform:uppercase; font-weight:700; color:var(--text-3); margin-bottom:4px; display:block;">Au</label>
        <input type="date" name="date_fin" value="{{ request('date_fin') }}" class="form-control" onchange="document.getElementById('formFiltreAchats').submit();">
    </div>
    @if(request('recherche') || request('statut_filtre') || request('dgi_filtre') || request('date_debut') || request('date_fin'))
        <a href="{{ route('admin.achats.factures', array_filter(['etape' => $etapeActive, 'type' => $type])) }}" class="btn btn-outline" style="white-space:nowrap;"><i class="fas fa-times"></i> Effacer</a>
    @endif
</form>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const champ = document.querySelector('#formFiltreAchats input[name="recherche"]');
        if (champ && champ.value) { champ.focus(); champ.setSelectionRange(champ.value.length, champ.value.length); }
    });
</script>

@php
    $activerSelectionGroup = ($etapeActive === 'Facture' || $type === 'avoir');
@endphp

@if($activerSelectionGroup)
    <div id="background-job-tracker" style="display: none; background: #fff; border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); padding: 16px; margin-bottom: 16px; position: relative;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
            <h4 style="margin: 0; font-size: 14px; font-weight: 700; color: var(--text);">
                <i class="fas fa-rotate fa-spin" style="color: var(--primary); margin-right: 6px;"></i>
                Normalisation en cours en arrière-plan...
            </h4>
            <button type="button" onclick="annulerJobArrierePlan()" class="btn btn-sm" style="background: #fef2f2; color: #dc2626; border: 0.5px solid #fca5a5; font-size: 11px; padding: 2px 8px;">
                Annuler
            </button>
        </div>
        <div style="background: #e2e8f0; height: 8px; border-radius: 4px; overflow: hidden; margin-bottom: 6px;">
            <div id="job-progress-bar" style="background: var(--primary); width: 0%; height: 100%; transition: width 0.3s;"></div>
        </div>
        <div style="display: flex; justify-content: space-between; font-size: 11px; color: var(--text-3);">
            <span id="job-progress-text">0 / 0 factures</span>
            <span id="job-current-invoice"></span>
        </div>
    </div>
@endif

<div class="card">
    @if($activerSelectionGroup)
        <div id="batch-actions-panel" style="background: #f8fafc; border-bottom: 1px solid var(--border); padding: 12px 16px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <span style="font-weight: 700; color: var(--text-2); font-size: 13px;">
                <i class="fas fa-list-check" style="color: var(--primary); margin-right: 6px;"></i> 
                Actions par lot : <span id="batch-selected-count" style="color: var(--primary);">0</span> sélectionné(s) (max 15)
            </span>
            <button type="button" class="btn btn-outline btn-sm" onclick="selectFirst15NonNormalized()" style="padding: 6px 12px; font-size: 12px; font-weight: 700;">
                Sélectionner les 15 premiers
            </button>
            <button type="button" id="btn-batch-normalise" class="btn btn-success btn-sm" onclick="lancerBatchNormalisation()" style="padding: 6px 12px; font-size: 12px; font-weight: 700; color: #fff;" disabled>
                <i class="fas fa-share-nodes"></i> Normaliser la sélection
            </button>
            <button type="button" class="btn btn-sm" onclick="ouvrirModalPlanification()" style="background: var(--bg3); color: var(--primary); border: 0.5px solid var(--border); padding: 6px 12px; font-size: 12px; font-weight: 700; margin-left: auto;">
                <i class="fas fa-clock"></i> Planifier une normalisation automatique
            </button>
        </div>
    @endif
    <div class="table-wrap">
        @if($achats->isEmpty())
        <div style="padding:48px; text-align:center; color:var(--text-3);">
            <i class="fas fa-file" style="font-size:48px; display:block; margin-bottom:12px; opacity:.2;"></i>
            Aucun élément disponible pour cette étape.
        </div>
        @else
        <table>
            <thead>
                <tr>
                    @if($activerSelectionGroup)
                    <th style="width: 40px; text-align: center; white-space: nowrap;">
                        <input type="checkbox" id="check-all-achats" onclick="toggleAllAchats(this)">
                    </th>
                    @endif
                    {{-- Colonne dynamique en fonction de l'étape active --}}
                    <th>
                        @if($type === 'avoir')
                            N° Avoir
                        @elseif($etapeActive === 'Demande de prix')
                            N° Demande
                        @elseif($etapeActive === 'Bon de commande')
                            N° Bon
                        @else
                            N° Facture
                        @endif
                    </th>
                    <th>Date</th>
                    <th>Fournisseur</th>
                    <th>Point de vente</th> {{-- Tâche 1 : Ajout de la colonne Point de vente --}}
                    <th>Articles</th>
                    <th>HT</th>
                    <th>TVA</th>
                    <th>TTC</th>
                    <th>Mode</th>
                    <th>Étape</th>
                    <th style="text-align: center;">Normalisé (DGI)</th>
                    <th>Fichier DGI</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($achats as $achat)
                @php
                    $rejetEnCours = !$achat->normalise && $achat->aRejetEnCours();
                @endphp
                <tr @if($rejetEnCours) style="background:#fffbeb; border-left:4px solid #f59e0b;" @elseif($achat->normalise) style="background:#ecfdf5; border-left:4px solid #10b981;" @endif>
                    @if($activerSelectionGroup)
                    <td style="text-align: center; white-space: nowrap;">
                        @if(!$achat->normalise)
                            <input type="checkbox" class="achat-checkbox" value="{{ $achat->id }}" onchange="onAchatCheckboxChange(this)">
                        @else
                            <i class="fas fa-check-circle" style="color: #10b981;" title="Facture déjà normalisée"></i>
                        @endif
                    </td>
                    @endif
                    <td style="font-weight:700; color:var(--info);">{{ $achat->numero_facture }}</td>
                    <td>{{ \Carbon\Carbon::parse($achat->date_achat)->format('d/m/Y') }}</td>
                    <td style="font-weight:600;">{{ $achat->fournisseur->nom }}</td>
                    <td style="font-weight:500; color:var(--text-2);"><i class="fas fa-store" style="font-size:11px; margin-right:4px;"></i>{{ $achat->pointDeVente->nom }}</td>
                    <td style="color:var(--text-2);">{{ $achat->details->count() }}</td>
                    <td>{{ number_format($achat->montant_ht, 0, ',', ' ') }} F</td>
                    <td>{{ number_format($achat->montant_tva, 0, ',', ' ') }} F</td>
                    <td style="font-weight:700; color:var(--danger);">{{ number_format($achat->montant_ttc, 0, ',', ' ') }} F</td>
                    <td>{{ $achat->mode_paiement }}</td>
                    <td>
                        @if($achat->statut === 'En attente B2B')
                            <span class="badge" style="background:#fff7ed; color:#ea580c; padding:4px 10px; border-radius:20px; font-weight:700; border:1px solid rgba(234,88,12,0.2);">Attente B2B</span>
                        @elseif($achat->etape === 'Demande de prix')
                            <span class="badge" style="background:#fffbeb; color:#d97706; padding:4px 10px; border-radius:20px; font-weight:700;">Demande de prix</span>
                        @elseif($achat->etape === 'Bon de commande')
                            <span class="badge" style="background:#eff6ff; color:#2563eb; padding:4px 10px; border-radius:20px; font-weight:700;">Bon de commande</span>
                        @else
                            <span class="badge" style="background:#e6fdf5; color:#059669; padding:4px 10px; border-radius:20px; font-weight:700;">Facture</span>
                        @endif
                    </td>
                    <td style="text-align: center;">
                        @if($achat->normalise)
                            <span style="background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:4px 10px; border-radius:20px; font-weight:800; font-size:12px; display:inline-flex; align-items:center; gap:5px;" title="Facture normalisée avec succès par la DGI">
                                <i class="fas fa-check-circle" style="color:#16a34a;"></i> Oui
                            </span>
                        @elseif($rejetEnCours)
                            <span style="background:#fff7ed; color:#c2410c; border:1px solid #fed7aa; padding:4px 10px; border-radius:20px; font-weight:700; font-size:12px; display:inline-flex; align-items:center; gap:5px;" title="Rejet DGI, relève du portail / correction FNE en cours">
                                <i class="fas fa-spinner fa-spin" style="font-size:11px; color:#ea580c;"></i> En cours
                            </span>
                        @else
                            <span style="background:#f3f4f6; color:#6b7280; padding:4px 10px; border-radius:20px; font-weight:600; font-size:12px;">Non</span>
                        @endif
                    </td>
                    <td>
                        @php
                            $dgiVoirUrl = $achat->fichier_fne_pdf_url;
                        @endphp
                        <div style="display:flex; gap:6px; align-items:center;">
                            @if($dgiVoirUrl)
                                <a href="{{ $dgiVoirUrl }}" target="_blank" class="btn btn-outline btn-sm" style="padding:4px 8px; font-size:11px;" title="Voir le document DGI">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="{{ $dgiVoirUrl }}" target="_blank" class="btn btn-outline btn-sm" style="padding:4px 8px; font-size:11px;" title="Télécharger le fichier DGI">
                                    <i class="fas fa-download"></i>
                                </a>
                            @else
                                <button type="button" class="btn btn-outline btn-sm" style="padding:4px 8px; font-size:11px; opacity:.5; cursor:not-allowed;" title="Aucun document DGI retourné" disabled>
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button type="button" class="btn btn-outline btn-sm" style="padding:4px 8px; font-size:11px; opacity:.5; cursor:not-allowed;" title="Aucun document DGI retourné" disabled>
                                    <i class="fas fa-download"></i>
                                </button>
                            @endif
                        </div>
                    </td>
                    <td>
                        <div style="display:flex; gap:6px; align-items:center;">
                            <a href="{{ route('admin.achats.imprimer', $achat) }}" class="btn btn-primary btn-sm">
                                <i class="fas fa-eye"></i> Voir
                            </a>

                            {{-- Normalisation manuelle (BAPA uniquement) --}}
                            @if(!$achat->normalise && $achat->etape === 'Facture' && $achat->type_facture === 'bapa')
                                <form method="POST" action="{{ route('admin.achats.normaliser', $achat) }}" style="display:inline; margin:0;">
                                    @csrf
                                    <button type="submit" class="btn btn-success btn-sm" style="font-weight:700; font-size:11px; padding:4px 8px;" title="Normaliser manuellement auprès de la DGI BAPA">
                                        <i class="fas fa-share-nodes"></i> Normaliser
                                    </button>
                                </form>
                            @endif

                            @if($achat->type_facture === 'bapa' && $achat->etape === 'Facture')
                                <a href="{{ route('admin.achats.bapa', $achat) }}" class="btn btn-outline btn-sm" style="color:var(--danger); border-color:var(--danger); font-size:11px; padding:4px 8px;" title="Générer le Bordereau d'Achat BAPA">
                                    <i class="fas fa-file-invoice"></i> BAPA
                                </a>
                            @endif

                            @if($achat->statut === 'En attente B2B')
                                <form method="POST" action="{{ route('admin.b2b.achat.accepter', $achat) }}" style="display:inline; margin:0;">
                                    @csrf
                                    <button type="submit" class="btn btn-success btn-sm" style="font-weight:700; font-size:11px; padding:4px 8px;" title="Accepter la livraison et comptabiliser l'achat">
                                        <i class="fas fa-check-double"></i> Accepter B2B
                                    </button>
                                </form>
                            @endif

                            @if(in_array($achat->etape, ['Demande de prix', 'Bon de commande']) && !empty($achat->fournisseur?->ncc))
                                @php
                                    $dejaEnvoyeB2b = \App\Modules\Admin\Modeles\B2bNegotiation::where('reference_commande', $achat->numero_facture)->exists();
                                @endphp
                                @if(!$dejaEnvoyeB2b)
                                    <form method="POST" action="{{ route('admin.achats.transmettre_b2b', $achat) }}" style="display:inline; margin:0;">
                                        @csrf
                                        <button type="submit" class="btn btn-outline btn-sm" style="font-size:11px; padding:4px 8px; border-color:var(--primary); color:var(--primary);" title="Transmettre cette demande en B2B (Inter-Entreprise)">
                                            <i class="fas fa-paper-plane"></i> Transmettre B2B
                                        </button>
                                    </form>
                                @else
                                    <span style="font-size:11px; color:var(--success); font-weight:700;"><i class="fas fa-check-circle"></i> B2B Envoyé</span>
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <div style="padding: 0 16px;">{{ $achats->appends(request()->query())->links() }}</div>
        @endif
    </div>
</div>
@if($type === 'avoir')
<div class="modal-overlay" id="modalNouveauAvoir" style="display:none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 9999; align-items: center; justify-content: center; overflow-y: auto; padding: 20px;">
    <div style="background: #fff; border-radius: 16px; max-width: 800px; width: 100%; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); overflow: hidden; display: flex; flex-direction: column; max-height: 90vh;">
        <div style="padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: #f8fafc;">
            <h3 style="font-size: 18px; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-file-circle-minus" style="color:#e17055;"></i> Nouvelle Facture d'Avoir Fournisseur
            </h3>
            <button type="button" onclick="fermerModalNouveauAvoir()" style="background:none; border:none; font-size:20px; color:#64748b; cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="{{ route('admin.achats.avoir.creer_nouveau') }}" style="margin:0; display:flex; flex-direction:column; overflow:hidden;">
            @csrf
            <div style="padding: 24px; overflow-y: auto; flex-grow: 1; max-height: 60vh;">
                <!-- Choix de la facture d'origine -->
                <div style="margin-bottom: 20px;">
                    <label style="font-weight: 700; font-size: 13px; display: block; margin-bottom: 6px; color: #334155;">Choisir la facture de doit d'origine *</label>
                    <select id="selectFactureAvoir" onchange="if(this.value) { selectionnerFacturePourAvoir(this.value); } else { masquerDetailsFactureAvoir(); }" class="form-control" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-weight: 600; color: #0f172a; background: #fff;">
                        <option value="">-- Sélectionner une facture --</option>
                        {{-- Même défaut qu'à l'avoir de vente : le numéro de
                             ligne partait dans une adresse qui résout par
                             `uuid`, la requête tombait en 404 (Not Found —
                             introuvable), et le script recevait la page
                             d'erreur en HTML là où il attendait du JSON.
                             Personne ne l'avait signalé de ce côté. --}}
                        @foreach($facturesDispo as $f)
                            <option value="{{ $f->uuid }}">{{ $f->numero_facture }} - {{ $f->fournisseur?->nom ?? 'Fournisseur inconnu' }} ({{ number_format($f->montant_ttc, 0, ',', ' ') }} F)</option>
                        @endforeach
                    </select>
                    <div style="font-size:11.5px; color:#64748b; margin-top:6px; line-height:1.5;">
                        <i class="fas fa-circle-info"></i>
                        Les bordereaux d'achat aux producteurs agricoles (BAPA) ne figurent pas
                        dans cette liste : la DGI ne normalise pas encore leur avoir.
                    </div>
                </div>

                <div id="factureDetailsAvoir" style="display: none;">
                    <input type="hidden" name="parent_id" id="avoir_parent_id">
                    
                    <div style="background: #f1f5f9; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; border-left: 4px solid #e17055;">
                        <div>
                            <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase;">Facture Sélectionnée</div>
                            <div id="avoir_facture_ref" style="font-size: 16px; font-weight: 800; color: #0f172a;">FAC-0000</div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase;">Fournisseur d'origine</div>
                            <div id="avoir_fournisseur_nom" style="font-size: 15px; font-weight: 700; color: #0f172a;">—</div>
                        </div>
                    </div>

                    <!-- Raison / Motif -->
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label style="font-weight: 700; font-size: 13px; display: block; margin-bottom: 6px; color: #334155;">Motif / Raison de l'avoir *</label>
                        <input type="text" name="raison" class="form-control" required placeholder="Ex: Retour de marchandise abîmée, erreur de tarif..." style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px;">
                    </div>

                    <!-- Articles -->
                    <label style="font-weight: 700; font-size: 13px; display: block; margin-bottom: 10px; color: #334155;">Sélectionner les articles et ajuster les quantités à créditer</label>
                    <table class="table" style="width: 100%; border-collapse: collapse; font-size: 13px;">
                        <thead>
                            <tr style="background:#f8fafc; border-bottom: 1.5px solid #cbd5e1; text-align: left;">
                                <th style="padding: 10px;">Désignation</th>
                                <th style="padding: 10px; text-align: center; width: 100px;">Qté Initiale</th>
                                <th style="padding: 10px; text-align: center; width: 120px;">Qté Avoir</th>
                                <th style="padding: 10px; text-align: right; width: 120px;">Prix Unit.</th>
                                <th style="padding: 10px; width: 180px;">Action sur Stock</th>
                            </tr>
                        </thead>
                        <tbody id="avoirItemsTableBody">
                            <!-- Rempli dynamiquement -->
                        </tbody>
                    </table>

                    <!-- Ajout d'autres articles / Saisies libres -->
                    <div style="margin-top: 24px; border-top: 1.5px solid #cbd5e1; padding-top: 16px;">
                        <h4 style="font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 12px; display: flex; align-items: center; gap: 6px; text-align: left;">
                            <i class="fas fa-plus-circle" style="color:#e17055;"></i> Ajouter d'autres articles
                        </h4>
                        
                        <div style="display: flex; gap: 8px; margin-bottom: 14px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
                            <button type="button" id="tab_catalog_btn" onclick="switchAddMode('catalog')" style="padding: 6px 12px; font-size: 11px; font-weight:700; border-radius: 6px; border: none; cursor: pointer; background: #e17055; color: #fff;">Catalogue Produits</button>
                            <button type="button" id="tab_free_btn" onclick="switchAddMode('free')" style="padding: 6px 12px; font-size: 11px; font-weight:700; border-radius: 6px; border: 1px solid #cbd5e1; cursor: pointer; background: #fff; color: #475569;">Saisie Libre (Hors Catalogue)</button>
                        </div>

                        <!-- Panel Catalogue -->
                        <div id="panel_catalog" style="display: block; background: #f8fafc; padding: 14px; border-radius: 8px; border: 1px solid #e2e8f0;">
                            <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 10px; align-items: end;">
                                <div style="text-align: left;">
                                    <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Sélectionner le produit *</label>
                                    <select id="catalog_product_id" onchange="onCatalogProductChange()" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; background:#fff; font-size: 12px;">
                                        <option value="">-- Choisir un produit --</option>
                                    </select>
                                </div>
                                <div style="text-align: left;">
                                    <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Quantité *</label>
                                    <input type="number" id="catalog_qty" value="1" min="1" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; text-align: center;">
                                </div>
                                <div style="text-align: left;">
                                    <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Prix U. Avoir *</label>
                                    <input type="number" id="catalog_price" value="0" min="0" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; text-align: right;">
                                </div>
                                <div>
                                    <button type="button" onclick="ajouterArticleDuCatalogue()" style="width: 100%; padding: 8px 12px; background: #e17055; color: #fff; border: none; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 4px; height: 35px;">
                                        <i class="fas fa-plus"></i> Ajouter
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Panel Saisie Libre -->
                        <div id="panel_free" style="display: none; background: #f8fafc; padding: 14px; border-radius: 8px; border: 1px solid #e2e8f0;">
                            <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr; gap: 10px; align-items: end;">
                                <div style="text-align: left;">
                                    <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Description / Libellé *</label>
                                    <input type="text" id="free_label" placeholder="Ex: Remise ou correction" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px;">
                                </div>
                                <div style="text-align: left;">
                                    <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Quantité *</label>
                                    <input type="number" id="free_qty" value="1" min="1" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; text-align: center;">
                                </div>
                                <div style="text-align: left;">
                                    <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Prix U. Avoir *</label>
                                    <input type="number" id="free_price" value="0" min="0" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; text-align: right;">
                                </div>
                                <div style="text-align: left;">
                                    <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Taux TVA</label>
                                    <select id="free_tva_rate" style="width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; background:#fff; font-size: 12px;">
                                        <option value="18">TVA 18%</option>
                                        <option value="0">TVA 0%</option>
                                    </select>
                                </div>
                                <div>
                                    <button type="button" onclick="ajouterSaisieLibre()" style="width: 100%; padding: 8px 12px; background: #e17055; color: #fff; border: none; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 4px; height: 35px;">
                                        <i class="fas fa-plus"></i> Ajouter
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div style="padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px; background: #f8fafc;">
                <button type="button" class="btn btn-outline" onclick="fermerModalNouveauAvoir()">Annuler</button>
                <button type="submit" id="btnValiderAvoir" class="btn" style="background:#e17055; color:#fff;" disabled>Créer la Facture d'Avoir</button>
            </div>
        </form>
    </div>
</div>

<script>
let searchTimeout = null;
let catalogProducts = {};
let customItemCounter = 0;

function switchAddMode(mode) {
    const tabCatalog = document.getElementById('tab_catalog_btn');
    const tabFree = document.getElementById('tab_free_btn');
    const panelCatalog = document.getElementById('panel_catalog');
    const panelFree = document.getElementById('panel_free');
    
    if (mode === 'catalog') {
        tabCatalog.style.background = '#e17055';
        tabCatalog.style.color = '#fff';
        tabCatalog.style.border = 'none';
        
        tabFree.style.background = '#fff';
        tabFree.style.color = '#475569';
        tabFree.style.border = '1px solid #cbd5e1';
        
        panelCatalog.style.display = 'block';
        panelFree.style.display = 'none';
    } else {
        tabFree.style.background = '#e17055';
        tabFree.style.color = '#fff';
        tabFree.style.border = 'none';
        
        tabCatalog.style.background = '#fff';
        tabCatalog.style.color = '#475569';
        tabCatalog.style.border = '1px solid #cbd5e1';
        
        panelCatalog.style.display = 'none';
        panelFree.style.display = 'block';
    }
}

function chargerProduitsParCategorie() {
    const select = document.getElementById('catalog_product_id');
    const url = "{{ route('admin.achats.factures.produits_categories') }}";
    
    fetch(url)
        .then(res => res.json())
        .then(data => {
            catalogProducts = data;
            select.innerHTML = '<option value="">-- Choisir un produit --</option>';
            
            for (const [catName, list] of Object.entries(data)) {
                const group = document.createElement('optgroup');
                group.label = catName;
                
                list.forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p.id;
                    opt.dataset.price = p.prix_achat;
                    opt.dataset.unit = p.unite;
                    opt.dataset.stockable = p.est_stockable ? 1 : 0;
                    opt.dataset.tva = p.taux_tva;
                    opt.dataset.name = p.nom;
                    opt.textContent = `${p.nom} (${p.prix_achat} F CFA / ${p.unite})`;
                    group.appendChild(opt);
                });
                
                select.appendChild(group);
            }
        });
}

function onCatalogProductChange() {
    const select = document.getElementById('catalog_product_id');
    const selectedOpt = select.options[select.selectedIndex];
    if (!selectedOpt || selectedOpt.value === "") {
        document.getElementById('catalog_price').value = 0;
        return;
    }
    document.getElementById('catalog_price').value = selectedOpt.dataset.price;
}

function ajouterArticleDuCatalogue() {
    const select = document.getElementById('catalog_product_id');
    const selectedOpt = select.options[select.selectedIndex];
    if (!selectedOpt || selectedOpt.value === "") {
        alert("Veuillez sélectionner un produit du catalogue.");
        return;
    }
    
    const productId = selectedOpt.value;
    const price = parseFloat(document.getElementById('catalog_price').value) || 0;
    const qty = parseFloat(document.getElementById('catalog_qty').value) || 1;
    const isStockable = parseInt(selectedOpt.dataset.stockable);
    const tva = selectedOpt.dataset.tva;
    const name = selectedOpt.dataset.name;
    
    customItemCounter++;
    const rowId = `new_catalog_${customItemCounter}`;
    
    const tbody = document.getElementById('avoirItemsTableBody');
    const tr = document.createElement('tr');
    tr.style.borderBottom = '0.5px solid #e2e8f0';
    
    // Designation
    const tdNom = document.createElement('td');
    tdNom.style.padding = '10px';
    tdNom.innerHTML = `
        <strong>${name}</strong> <span style="font-size: 10px; background:#dcfce7; color:#15803d; padding:2px 6px; border-radius:4px; margin-left:4px;">Catalogue</span>
        <input type="hidden" name="items[${rowId}][est_nouveau]" value="1">
        <input type="hidden" name="items[${rowId}][produit_id]" value="${productId}">
        <input type="hidden" name="items[${rowId}][libelle_virtuel]" value="${name}">
        <input type="hidden" name="items[${rowId}][taux_tva]" value="${tva}">
    `;
    tr.appendChild(tdNom);
    
    // Original Qty
    const tdOriginalQty = document.createElement('td');
    tdOriginalQty.style.padding = '10px';
    tdOriginalQty.style.textAlign = 'center';
    tdOriginalQty.style.fontWeight = '600';
    tdOriginalQty.textContent = `—`;
    tr.appendChild(tdOriginalQty);
    
    // Return Qty
    const tdReturnQty = document.createElement('td');
    tdReturnQty.style.padding = '10px';
    tdReturnQty.innerHTML = `<input type="number" name="items[${rowId}][quantite]" class="form-control" value="${qty}" min="0.001" step="0.001" required style="width: 100%; padding: 6px; border: 1px solid #cbd5e1; border-radius: 6px; text-align: center;">`;
    tr.appendChild(tdReturnQty);
    
    // Price
    const tdPrice = document.createElement('td');
    tdPrice.style.padding = '10px';
    tdPrice.style.textAlign = 'right';
    tdPrice.innerHTML = `<input type="number" name="items[${rowId}][prix_unitaire]" class="form-control" value="${price}" min="0" required style="width: 100%; padding: 6px; border: 1px solid #cbd5e1; border-radius: 6px; text-align: right;">`;
    tr.appendChild(tdPrice);
    
    // Stock Action
    const tdStockAction = document.createElement('td');
    tdStockAction.style.padding = '10px';
    if (isStockable) {
        tdStockAction.innerHTML = `
            <select name="items[${rowId}][stock_action]" style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:6px; background:#fff;">
                <option value="reinject">Retour physique (Déduire du stock)</option>
                <option value="none">Aucun impact de stock</option>
            </select>
        `;
    } else {
        tdStockAction.innerHTML = `<span style="color:#64748b; font-style:italic;">Non stockable</span><input type="hidden" name="items[${rowId}][stock_action]" value="none">`;
    }
    tr.appendChild(tdStockAction);
    
    tbody.appendChild(tr);
    
    // Reset selections
    select.value = "";
    document.getElementById('catalog_price').value = 0;
    document.getElementById('catalog_qty').value = 1;
}

function ajouterSaisieLibre() {
    const labelInput = document.getElementById('free_label');
    const label = labelInput.value.trim();
    if (label === "") {
        alert("Veuillez saisir un libellé pour la ligne libre.");
        return;
    }
    
    const price = parseFloat(document.getElementById('free_price').value) || 0;
    const qty = parseFloat(document.getElementById('free_qty').value) || 1;
    const tva = document.getElementById('free_tva_rate').value;
    
    customItemCounter++;
    const rowId = `new_free_${customItemCounter}`;
    
    const tbody = document.getElementById('avoirItemsTableBody');
    const tr = document.createElement('tr');
    tr.style.borderBottom = '0.5px solid #e2e8f0';
    
    // Designation
    const tdNom = document.createElement('td');
    tdNom.style.padding = '10px';
    tdNom.innerHTML = `
        <strong>${label}</strong> <span style="font-size: 10px; background:#e0f2fe; color:#0369a1; padding:2px 6px; border-radius:4px; margin-left:4px;">Saisie libre</span>
        <input type="hidden" name="items[${rowId}][est_nouveau]" value="1">
        <input type="hidden" name="items[${rowId}][libelle_virtuel]" value="${label}">
        <input type="hidden" name="items[${rowId}][taux_tva]" value="${tva}">
    `;
    tr.appendChild(tdNom);
    
    // Original Qty
    const tdOriginalQty = document.createElement('td');
    tdOriginalQty.style.padding = '10px';
    tdOriginalQty.style.textAlign = 'center';
    tdOriginalQty.style.fontWeight = '600';
    tdOriginalQty.textContent = `—`;
    tr.appendChild(tdOriginalQty);
    
    // Return Qty
    const tdReturnQty = document.createElement('td');
    tdReturnQty.style.padding = '10px';
    tdReturnQty.innerHTML = `<input type="number" name="items[${rowId}][quantite]" class="form-control" value="${qty}" min="0.001" step="0.001" required style="width: 100%; padding: 6px; border: 1px solid #cbd5e1; border-radius: 6px; text-align: center;">`;
    tr.appendChild(tdReturnQty);
    
    // Price
    const tdPrice = document.createElement('td');
    tdPrice.style.padding = '10px';
    tdPrice.style.textAlign = 'right';
    tdPrice.innerHTML = `<input type="number" name="items[${rowId}][prix_unitaire]" class="form-control" value="${price}" min="0" required style="width: 100%; padding: 6px; border: 1px solid #cbd5e1; border-radius: 6px; text-align: right;">`;
    tr.appendChild(tdPrice);
    
    // Stock Action
    const tdStockAction = document.createElement('td');
    tdStockAction.style.padding = '10px';
    tdStockAction.innerHTML = `<span style="color:#64748b; font-style:italic;">Non stockable</span><input type="hidden" name="items[${rowId}][stock_action]" value="none">`;
    tr.appendChild(tdStockAction);
    
    tbody.appendChild(tr);
    
    // Reset selections
    labelInput.value = "";
    document.getElementById('free_price').value = 0;
    document.getElementById('free_qty').value = 1;
}

function ouvrirModalNouveauAvoir() {
    document.getElementById('modalNouveauAvoir').style.display = 'flex';
    chargerProduitsParCategorie();
}

function fermerModalNouveauAvoir() {
    document.getElementById('modalNouveauAvoir').style.display = 'none';
    const selectEl = document.getElementById('selectFactureAvoir');
    if (selectEl) selectEl.value = '';
    document.getElementById('factureDetailsAvoir').style.display = 'none';
    document.getElementById('btnValiderAvoir').disabled = true;
}

function masquerDetailsFactureAvoir() {
    document.getElementById('factureDetailsAvoir').style.display = 'none';
    document.getElementById('btnValiderAvoir').disabled = true;
}

function chercherFactures(query) {
    clearTimeout(searchTimeout);
    if (query.length < 2) {
        document.getElementById('autocompleteResults').style.display = 'none';
        return;
    }

    searchTimeout = setTimeout(() => {
        const url = "{{ route('admin.achats.factures.rechercher') }}?q=" + encodeURIComponent(query);
        fetch(url)
            .then(res => res.json())
            .then(data => {
                const container = document.getElementById('autocompleteResults');
                container.innerHTML = '';
                if (data.length === 0) {
                    container.innerHTML = '<div style="padding: 10px; color: #64748b; font-style: italic;">Aucune facture trouvée</div>';
                } else {
                    data.forEach(item => {
                        const div = document.createElement('div');
                        div.style.padding = '10px 14px';
                        div.style.cursor = 'pointer';
                        div.style.borderBottom = '0.5px solid #f1f5f9';
                        div.style.fontSize = '13px';
                        div.style.fontWeight = '600';
                        div.style.color = '#0f172a';
                        div.innerHTML = item.text;
                        div.onclick = () => selectionnerFacturePourAvoir(item.id);
                        div.onmouseover = () => div.style.background = '#f1f5f9';
                        div.onmouseout = () => div.style.background = '#fff';
                        container.appendChild(div);
                    });
                }
                container.style.display = 'block';
            });
    }, 300);
}

function selectionnerFacturePourAvoir(id) {
    const baseUrl = "{{ route('admin.achats.factures.details', ':id') }}";
    const url = baseUrl.replace(':id', id);

    fetch(url)
        .then(res => res.json())
        .then(data => {
            document.getElementById('avoir_parent_id').value = data.id;
            document.getElementById('avoir_facture_ref').textContent = data.numero_facture;
            document.getElementById('avoir_fournisseur_nom').textContent = data.fournisseur_nom;
            
            const tbody = document.getElementById('avoirItemsTableBody');
            tbody.innerHTML = '';
            
            data.details.forEach(item => {
                const tr = document.createElement('tr');
                tr.style.borderBottom = '0.5px solid #e2e8f0';
                
                // Designation
                const tdNom = document.createElement('td');
                tdNom.style.padding = '10px';
                tdNom.innerHTML = `<strong>${item.libelle}</strong><input type="hidden" name="items[${item.id}][id]" value="${item.id}">`;
                tr.appendChild(tdNom);
                
                // Original Qty
                const tdOriginalQty = document.createElement('td');
                tdOriginalQty.style.padding = '10px';
                tdOriginalQty.style.textAlign = 'center';
                tdOriginalQty.style.fontWeight = '600';
                tdOriginalQty.textContent = `${item.quantite} ${item.unite}`;
                tr.appendChild(tdOriginalQty);
                
                // Return Qty
                const tdReturnQty = document.createElement('td');
                tdReturnQty.style.padding = '10px';
                tdReturnQty.innerHTML = `<input type="number" name="items[${item.id}][quantite]" class="form-control" value="${item.quantite}" min="0" step="0.001" max="${item.quantite}" required style="width: 100%; padding: 6px; border: 1px solid #cbd5e1; border-radius: 6px; text-align: center;">`;
                tr.appendChild(tdReturnQty);
                
                // Price
                const tdPrice = document.createElement('td');
                tdPrice.style.padding = '10px';
                tdPrice.style.textAlign = 'right';
                tdPrice.innerHTML = `<input type="number" name="items[${item.id}][prix_unitaire]" class="form-control" value="${item.prix_unitaire}" min="0" required style="width: 100%; padding: 6px; border: 1px solid #cbd5e1; border-radius: 6px; text-align: right;">`;
                tr.appendChild(tdPrice);
                
                // Stock Action
                const tdStockAction = document.createElement('td');
                tdStockAction.style.padding = '10px';
                if (item.est_stockable) {
                    tdStockAction.innerHTML = `
                        <select name="items[${item.id}][stock_action]" style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:6px; background:#fff;">
                            <option value="reinject">Sortie de stock (Retour fournisseur)</option>
                            <option value="none">Aucun retour physique</option>
                        </select>
                    `;
                } else {
                    tdStockAction.innerHTML = `<span style="color:#64748b; font-style:italic;">Non stockable</span><input type="hidden" name="items[${item.id}][stock_action]" value="none">`;
                }
                tr.appendChild(tdStockAction);
                
                tbody.appendChild(tr);
            });
            
            document.getElementById('factureDetailsAvoir').style.display = 'block';
            document.getElementById('btnValiderAvoir').disabled = false;
        });
}
</script>
@endif

@if($activerSelectionGroup)
<script>
let selectedAchatIds = [];

function toggleAllAchats(master) {
    const checkboxes = document.querySelectorAll('.achat-checkbox');
    let count = 0;
    checkboxes.forEach(cb => {
        if (master.checked) {
            if (count < 15) {
                cb.checked = true;
                count++;
            } else {
                cb.checked = false;
            }
        } else {
            cb.checked = false;
        }
    });
    
    if (master.checked && checkboxes.length > 15) {
        alert("La normalisation par lot est limitée à 15 factures maximum. Seules les 15 premières ont été sélectionnées.");
    }
    
    updateSelectedAchatsCount();
}

function onAchatCheckboxChange(cb) {
    const checked = document.querySelectorAll('.achat-checkbox:checked');
    if (checked.length > 15) {
        alert("Vous ne pouvez pas sélectionner plus de 15 factures pour la normalisation par lot.");
        cb.checked = false;
    }
    updateSelectedAchatsCount();
}

function updateSelectedAchatsCount() {
    const checked = document.querySelectorAll('.achat-checkbox:checked');
    selectedAchatIds = Array.from(checked).map(cb => cb.value);
    
    document.getElementById('batch-selected-count').textContent = selectedAchatIds.length;
    const btn = document.getElementById('btn-batch-normalise');
    btn.disabled = selectedAchatIds.length === 0;
}

function selectFirst15NonNormalized() {
    document.querySelectorAll('.achat-checkbox').forEach(cb => cb.checked = false);
    
    const checkboxes = document.querySelectorAll('.achat-checkbox');
    let count = 0;
    for (let i = 0; i < checkboxes.length; i++) {
        if (count < 15) {
            checkboxes[i].checked = true;
            count++;
        } else {
            break;
        }
    }
    
    updateSelectedAchatsCount();
}

function lancerBatchNormalisation() {
    if (selectedAchatIds.length === 0) return;
    
    const btn = document.getElementById('btn-batch-normalise');
    const oldHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Traitement...';
    
    fetch("{{ route('admin.fne.batch_normaliser') }}", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": "{{ csrf_token() }}"
        },
        body: JSON.stringify({
            ids: selectedAchatIds,
            flux: 'achats'
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(`Succès ! ${data.success_count} facture(s) d'achat normalisée(s) avec succès.`);
            window.location.reload();
        } else {
            const errorMsg = data.errors && data.errors.length > 0 ? data.errors.join('\n') : (data.message ?? 'Une erreur est survenue.');
            alert(`Traitement arrêté. ${data.success_count} facture(s) traitée(s) avant l'erreur suivante :\n\n${errorMsg}`);
            window.location.reload();
        }
    })
    .catch(err => {
        alert("Erreur de connexion lors du traitement par lot : " + err.message);
        btn.disabled = false;
        btn.innerHTML = oldHtml;
    });
}

function ouvrirModalPlanification() {
    document.getElementById('modal-planifier-fne').style.display = 'flex';
}

function fermerModalPlanification() {
    document.getElementById('modal-planifier-fne').style.display = 'none';
    document.getElementById('modal-error-msg').style.display = 'none';
}

function soumettrePlanification(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    
    const errorDiv = document.getElementById('modal-error-msg');
    errorDiv.style.display = 'none';
    
    const submitBtn = form.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Lancement...';
    
    fetch("{{ route('admin.fne.schedule_batch') }}", {
        method: "POST",
        headers: {
            "X-CSRF-TOKEN": "{{ csrf_token() }}"
        },
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            fermerModalPlanification();
            startPollingJobStatus();
        } else {
            errorDiv.textContent = data.message ?? 'Erreur lors du lancement.';
            errorDiv.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.textContent = 'Lancer le traitement';
        }
    })
    .catch(err => {
        errorDiv.textContent = "Erreur de connexion : " + err.message;
        errorDiv.style.display = 'block';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Lancer le traitement';
    });
}

let jobPollInterval = null;

function startPollingJobStatus() {
    const tracker = document.getElementById('background-job-tracker');
    tracker.style.display = 'block';
    
    if (jobPollInterval) clearInterval(jobPollInterval);
    jobPollInterval = setInterval(pollJobStatus, 2000);
    pollJobStatus();
}

function pollJobStatus() {
    fetch("{{ route('admin.fne.batch_status') }}")
    .then(r => r.json())
    .then(data => {
        const tracker = document.getElementById('background-job-tracker');
        
        if (data.status === 'idle') {
            tracker.style.display = 'none';
            clearInterval(jobPollInterval);
            return;
        }
        
        tracker.style.display = 'block';
        
        const total = data.total_to_process ?? 0;
        const processed = data.processed_count ?? 0;
        const percent = total > 0 ? Math.round((processed / total) * 100) : 0;
        
        document.getElementById('job-progress-bar').style.width = percent + '%';
        document.getElementById('job-progress-text').textContent = `${processed} / ${total} facture(s) normalisée(s) (${percent}%)`;
        document.getElementById('job-current-invoice').textContent = data.current_invoice ? `En cours : ${data.current_invoice}` : '';
        
        if (data.status === 'completed') {
            clearInterval(jobPollInterval);
            setTimeout(() => {
                alert("Normalisation en arrière-plan terminée avec succès !");
                window.location.reload();
            }, 1000);
        } else if (data.status === 'failed') {
            clearInterval(jobPollInterval);
            setTimeout(() => {
                alert(`Normalisation en arrière-plan arrêtée en raison de l'erreur suivante :\n\n${data.error}`);
                window.location.reload();
            }, 1000);
        } else if (data.status === 'cancelled') {
            clearInterval(jobPollInterval);
            setTimeout(() => {
                alert("Normalisation en arrière-plan annulée par l'utilisateur.");
                window.location.reload();
            }, 1000);
        }
    })
    .catch(err => {
        console.error("Erreur lors de la récupération du statut :", err);
    });
}

function annulerJobArrierePlan() {
    if (!confirm("Annuler le traitement en cours ? La normalisation s'arrêtera après la facture en cours de traitement.")) return;
    
    fetch("{{ route('admin.fne.batch_status') }}?cancel=1")
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            pollJobStatus();
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    fetch("{{ route('admin.fne.batch_status') }}")
    .then(r => r.json())
    .then(data => {
        if (data && data.status === 'running') {
            startPollingJobStatus();
        }
    });
});
</script>

<div id="modal-planifier-fne" class="modal-backdrop" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(4px);">
    <div style="background: #fff; border-radius: 12px; width: 100%; max-width: 450px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); overflow: hidden; animation: modalFadeIn 0.3s;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: var(--bg2);">
            <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: var(--text);"><i class="fas fa-clock" style="margin-right: 6px; color: var(--primary);"></i> Planifier la normalisation</h3>
            <button type="button" onclick="fermerModalPlanification()" style="background: none; border: none; font-size: 20px; color: var(--text-3); cursor: pointer;"><i class="fas fa-times"></i></button>
        </div>
        <form id="form-planifier-fne" onsubmit="soumettrePlanification(event)">
            @csrf
            <input type="hidden" name="flux" value="achats">
            <div style="padding: 20px;">
                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label" style="font-weight: 700; margin-bottom: 6px;">Date de début</label>
                    <input type="date" name="date_debut" class="form-control" required value="{{ date('Y-m-01') }}">
                </div>
                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label" style="font-weight: 700; margin-bottom: 6px;">Date de fin</label>
                    <input type="date" name="date_fin" class="form-control" required value="{{ date('Y-m-d') }}">
                </div>
                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label" style="font-weight: 700; margin-bottom: 6px;">Nombre maximum à normaliser</label>
                    <input type="number" name="batch_size" class="form-control" required min="1" max="100" value="15">
                    <span style="font-size: 11px; color: var(--text-3);">Taille maximale du lot à traiter en arrière-plan.</span>
                </div>
                <div id="modal-error-msg" style="display: none; background: #fef2f2; color: #dc2626; padding: 10px; border-radius: 6px; font-size: 12px; margin-top: 10px;"></div>
            </div>
            <div style="padding: 14px 20px; border-top: 1px solid var(--border); display: flex; gap: 10px; justify-content: flex-end; background: var(--bg2);">
                <button type="button" class="btn btn-outline" onclick="fermerModalPlanification()">Annuler</button>
                <button type="submit" class="btn btn-primary">Lancer le traitement</button>
            </div>
        </form>
    </div>
</div>

<style>
@keyframes modalFadeIn {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>
@endif
@endsection

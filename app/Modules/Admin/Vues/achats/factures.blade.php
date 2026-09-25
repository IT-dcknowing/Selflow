@extends('admin::gabarits.application')
@section('titre', 'Factures — Achats')
@section('topbar_titre', 'Achats — Factures & Commandes')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-file-invoice-dollar"></i> Cycles d'achat</h1>
        <p>Suivi complet des demandes de prix, bons de commande et factures</p>
    </div>
    <a href="{{ route('admin.achats.nouveau') }}" class="btn btn-primary">
        <i class="fas fa-plus"></i> Nouvel achat
    </a>
</div>

{{-- Les onglets du cycle d'achat --}}
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

@php
    /*
     * Trois natures d'achat, trois sections. Elles ne se ressemblent pas :
     * l'une n'est jamais normalisée, l'autre est la seule que nous
     * normalisions, la troisième arrive déjà certifiée par le fournisseur.
     * Les mêler obligeait chaque ligne à expliquer ce qu'elle était.
     */
    $sections = [
        'enregistrees' => ['Factures enregistrées', 'fa-file-invoice', $nbEnregistrees],
        'bapa'         => ['Factures BAPA', 'fa-file-signature', $nbBapa],
        'dgi'          => ['Factures achat DGI', 'fa-cloud-arrow-down', $nbDgi],
    ];
@endphp

@if($etapeActive === 'Facture')
<div style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap;">
    @foreach($sections as $cle => [$libelle, $icone, $compte])
    <a href="{{ route('admin.achats.factures', ['etape' => 'Facture', 'section' => $cle]) }}"
       style="display:flex; align-items:center; gap:8px; padding:8px 16px; border-radius:8px; text-decoration:none; font-weight:700; font-size:12.5px;
              {{ $section === $cle ? 'background:var(--bg3); color:var(--primary); border:1.5px solid var(--primary);' : 'background:#fff; color:var(--text-2); border:1px solid var(--border);' }}">
        <i class="fas {{ $icone }}" style="font-size:13px;"></i>
        {{ $libelle }}
        <span style="font-size:11px; padding:2px 8px; border-radius:20px; font-weight:800; background:{{ $section === $cle ? '#fff' : 'var(--bg3)' }}; color:var(--primary);">{{ $compte }}</span>
    </a>
    @endforeach
</div>

<div style="margin-bottom:16px; padding:10px 14px; border-radius:8px; font-size:12px; line-height:1.5;
            background:{{ $section === 'bapa' ? '#fff7ed' : ($section === 'dgi' ? '#eff6ff' : '#f8fafc') }};
            border:1px solid {{ $section === 'bapa' ? '#fed7aa' : ($section === 'dgi' ? '#bfdbfe' : 'var(--border)') }};
            color:var(--text-2);">
    @if($section === 'bapa')
        <i class="fas fa-circle-info" style="color:#c2410c;"></i>
        <strong>Le bordereau d'achat est la seule pièce d'achat que vous normalisez.</strong>
        Vous l'établissez auprès d'un producteur qui n'émet rien : c'est vous qui
        le déclarez à la DGI. Vérifiez que l'option BAPA est cochée sur votre
        espace FNE, sans quoi la plateforme le refusera.
    @elseif($section === 'dgi')
        <i class="fas fa-circle-info" style="color:#1d4ed8;"></i>
        <strong>Ces factures viennent du portail de la DGI.</strong>
        Vos fournisseurs les ont établies et certifiées ; le relevé les rapporte.
        Il n'y a rien à leur faire — seulement à les rapprocher de vos achats.
        {{-- Écarter n'est pas supprimer : la pièce reste, et le portail la
             redéposera. Ce qu'on a mis de côté doit pouvoir revenir, sinon
             l'écartement serait une suppression déguisée. --}}
        @if($nbEcartees > 0 || request('statut') === 'ecartees')
            <div style="margin-top:6px;">
                @if(request('statut') === 'ecartees')
                    <a href="{{ route('admin.achats.factures', ['etape' => 'Facture', 'section' => 'dgi']) }}" style="font-weight:700;">
                        &larr; Revenir aux factures à rapprocher
                    </a>
                @else
                    <a href="{{ route('admin.achats.factures', ['etape' => 'Facture', 'section' => 'dgi', 'statut' => 'ecartees']) }}" style="font-weight:700;">
                        Voir les {{ $nbEcartees }} facture(s) écartée(s)
                    </a>
                @endif
            </div>
        @endif
    @else
        <i class="fas fa-circle-info" style="color:var(--text-3);"></i>
        <strong>Ces factures ne partent pas à la DGI.</strong>
        Vous les enregistrez pour suivre vos dépenses ; c'est le fournisseur qui
        certifie la sienne, pas vous. Les colonnes DGI restent donc vides : plus
        tard, elles porteront le rapprochement avec les factures relevées au portail.
    @endif
</div>
@endif

<form method="GET" action="{{ route('admin.achats.factures') }}" id="formFiltreAchats" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end; margin-bottom:16px; background:#fff; border:1px solid var(--border); border-radius:12px; padding:14px 16px;">
    <input type="hidden" name="etape" value="{{ $etapeActive }}">
    <input type="hidden" name="section" value="{{ $section }}">

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
    {{-- Le filtre DGI n'a de sens que sur les bordereaux : eux seuls
         peuvent être normalisés ou ne pas l'être encore. --}}
    @if($section === 'bapa')
    <div class="form-group" style="margin-bottom:0;">
        <label class="form-label" style="font-size:11px; text-transform:uppercase; font-weight:700; color:var(--text-3); margin-bottom:4px; display:block;">Statut DGI</label>
        <select name="dgi_filtre" class="form-control" onchange="document.getElementById('formFiltreAchats').submit();">
            <option value="">— Tous —</option>
            <option value="oui" {{ request('dgi_filtre') === 'oui' ? 'selected' : '' }}>Normalisé (DGI)</option>
            <option value="non" {{ request('dgi_filtre') === 'non' ? 'selected' : '' }}>Non normalisé</option>
        </select>
    </div>
    @endif
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
        <a href="{{ route('admin.achats.factures', ['etape' => $etapeActive, 'section' => $section]) }}" class="btn btn-outline" style="white-space:nowrap;"><i class="fas fa-times"></i> Effacer</a>
    @endif
</form>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const champ = document.querySelector('#formFiltreAchats input[name="recherche"]');
        if (champ && champ.value) { champ.focus(); champ.setSelectionRange(champ.value.length, champ.value.length); }
    });
</script>

@php
    // La normalisation par lot n'a d'objet que sur les bordereaux : ce sont les
    // seules pièces d'achat qui partent à la plateforme. Elle s'affichait sur
    // toutes les factures, et proposait d'envoyer des pièces que la DGI n'aurait
    // pas acceptées.
    $activerSelectionGroup = ($etapeActive === 'Facture' && $section === 'bapa');

    /*
     * La colonne « Action » ne subsiste que là où il y a quelque chose à faire.
     *
     *  - sur une facture enregistrée : rien. Elle ne part pas à la DGI, et elle
     *    ne se reprend pas — le bouton n'aurait rien à commander ;
     *  - sur une facture relevée au portail : pas de normalisation — elle est
     *    déjà certifiée par son émetteur —, mais le rapprochement, lui, est un
     *    geste : rattacher, écarter, remettre. C'est pour lui que l'écran
     *    séparé des factures reçues peut disparaître ;
     *  - sur un bordereau, et aux étapes de commande : oui.
     */
    $afficherActions = ($etapeActive !== 'Facture') || $section !== 'enregistrees';
    $sectionSansDgi = ($etapeActive === 'Facture' && $section === 'enregistrees');
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
        {{-- Une facture relevée au portail suffit à faire exister le tableau :
             sans cela, une entreprise dont la DGI détient des factures mais qui
             n'a encore rien saisi lisait « aucun élément » alors qu'il y avait
             justement quelque chose à rapprocher. --}}
        @if($achats->isEmpty() && $facturesPortail->isEmpty())
        <div style="padding:48px; text-align:center; color:var(--text-3);">
            <i class="fas fa-file" style="font-size:48px; display:block; margin-bottom:12px; opacity:.2;"></i>
            @if($etapeActive !== 'Facture')
                Aucun élément disponible pour cette étape.
            @elseif($section === 'bapa')
                Aucun bordereau d'achat. Vous en établirez un depuis
                « Nouvel achat », en choisissant <strong>BAPA (DGI)</strong>.
            @elseif($section === 'dgi')
                {{-- Le message disait « lancer node achats.js <NCC> ». C'est la
                     commande du relevé, qui n'a rien à faire sous les yeux d'un
                     commerçant : il n'a pas de terminal, et ce n'est pas à lui
                     de lancer le scraper. Ce qu'il doit savoir, c'est que la
                     DGI ne détient encore aucune facture à son nom, ou que le
                     relevé n'est pas encore passé. --}}
                Aucune facture d'achat relevée au portail de la DGI.
                Elles y apparaîtront d'elles-mêmes dès qu'un fournisseur aura
                certifié une pièce à votre nom et que le relevé sera passé.
            @else
                Aucune facture enregistrée. Saisissez-en une depuis
                « Nouvel achat », en choisissant
                <strong>Facture physique fournisseur</strong>.
            @endif
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
                        @if($etapeActive === 'Demande de prix')
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
                    {{-- Ce que Selflow établit, avant tout passage par la
                         plateforme : le même partage que sur les ventes. --}}
                    <th>Originale</th>
                    @if($afficherActions)
                    <th>Action</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                {{-- Les factures que la DGI détient et que Selflow n'a pas encore
                     rattachées à un achat. En tête du tableau parce qu'elles
                     appellent un geste : tant qu'elles ne sont pas rapprochées,
                     la comptabilité ignore une charge que le fisc, lui, connaît.
                     Bleues, et non vertes ni oranges : ni normalisées par nous,
                     ni en attente de l'être — elles ne sont pas notre pièce. --}}
                @foreach($facturesPortail as $recue)
                @php
                    $propose = $recue->rapprochementPropose();
                @endphp
                <tr style="background:#eff6ff; border-left:4px solid #3b82f6;">
                    @if($activerSelectionGroup)
                    <td style="text-align: center; white-space: nowrap;">
                        <i class="fas fa-cloud-arrow-down" style="color:#3b82f6;" title="Relevée au portail de la DGI — la normalisation groupée ne la concerne pas"></i>
                    </td>
                    @endif
                    <td style="font-weight:700; color:#1d4ed8;">
                        {{ $recue->reference }}
                        <span style="display:block; font-size:10px; font-weight:700; color:#3b82f6; text-transform:uppercase; letter-spacing:.03em;">Portail DGI</span>
                    </td>
                    <td>{{ $recue->date_facture?->format('d/m/Y') ?? '—' }}</td>
                    <td style="font-weight:600;">
                        {{ $recue->emetteur_nom ?: '—' }}
                        @if($recue->emetteur_ncc)
                            <span style="display:block; font-size:11px; color:var(--text-3);">NCC {{ $recue->emetteur_ncc }}</span>
                        @endif
                    </td>
                    <td>
                        @if($recue->pointDeVente)
                            <span style="font-weight:500; color:var(--text-2);"><i class="fas fa-store" style="font-size:11px; margin-right:4px;"></i>{{ $recue->pointDeVente->nom }}</span>
                        @else
                            {{-- Le portail ne dit pas de quel site relève une
                                 facture reçue — son `clientPointOfSale` décrit
                                 l'émetteur. C'est donc une décision, et on la
                                 demande plutôt que de la deviner. --}}
                            <form method="POST" action="{{ route('admin.achats.factures_recues.affecter', $recue) }}" style="margin:0;">
                                @csrf
                                <select name="point_de_vente_id" onchange="this.form.submit()"
                                        style="font-size:11px; padding:3px 6px; border:1px solid #bfdbfe; border-radius:6px; background:#fff; color:#1d4ed8; font-weight:600; max-width:160px;"
                                        title="Ranger cette facture sous un site : le portail ne le dit pas.">
                                    <option value="">à affecter…</option>
                                    @foreach($sitesDisponibles as $site)
                                        <option value="{{ $site->id }}">{{ $site->nom }}</option>
                                    @endforeach
                                </select>
                            </form>
                        @endif
                    </td>
                    <td style="color:var(--text-2);">{{ $recue->lignes->count() }}</td>
                    <td>{{ number_format($recue->montant_ht, 0, ',', ' ') }} F</td>
                    <td>{{ number_format($recue->montant_tva, 0, ',', ' ') }} F</td>
                    <td style="font-weight:700; color:var(--danger);">{{ number_format($recue->montant_ttc, 0, ',', ' ') }} F</td>
                    <td>{{ $recue->moyen_paiement ?: '—' }}</td>
                    <td>
                        <span class="badge" style="background:#eff6ff; color:#1d4ed8; padding:4px 10px; border-radius:20px; font-weight:700; border:1px solid #bfdbfe;">
                            {{ $recue->libelleDuSousType() }}
                        </span>
                    </td>
                    <td style="text-align: center;">
                        {{-- Certifiée, mais par le fournisseur. L'afficher « en
                             cours » laisserait croire qu'un envoi nous incombe. --}}
                        <span style="background:#dbeafe; color:#1e40af; border:1px solid #93c5fd; padding:4px 10px; border-radius:20px; font-weight:800; font-size:12px; display:inline-flex; align-items:center; gap:5px;" title="Pièce certifiée par le fournisseur et détenue par la DGI">
                            <i class="fas fa-check-circle"></i> Fournisseur
                        </span>
                    </td>
                    <td>
                        @php
                            // Le relevé ne rend pas d'adresse toute faite,
                            // contrairement à la réponse de certification de nos
                            // propres pièces : elle se reconstruit à partir du
                            // code de vérification. Sans cela, ces deux boutons
                            // restaient vides sur une pièce pourtant consultable.
                            $verifUrl = $recue->urlDeVerification();
                        @endphp
                        <div style="display:flex; gap:6px; align-items:center;">
                            @if($verifUrl)
                                <a href="{{ $verifUrl }}" target="_blank" class="btn btn-outline btn-sm" style="padding:4px 8px; font-size:11px;" title="Voir la pièce chez la DGI, qui la détient">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="{{ $verifUrl }}" target="_blank" class="btn btn-outline btn-sm" style="padding:4px 8px; font-size:11px;" title="Ouvrir la page de vérification DGI de cette pièce">
                                    <i class="fas fa-download"></i>
                                </a>
                            @else
                                <button type="button" class="btn btn-outline btn-sm" style="padding:4px 8px; font-size:11px; opacity:.5; cursor:not-allowed;" title="Le relevé ne porte aucun code de vérification pour cette pièce" disabled>
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button type="button" class="btn btn-outline btn-sm" style="padding:4px 8px; font-size:11px; opacity:.5; cursor:not-allowed;" title="Le relevé ne porte aucun code de vérification pour cette pièce" disabled>
                                    <i class="fas fa-download"></i>
                                </button>
                            @endif
                        </div>
                    </td>
                    <td>
                        <div style="display:flex; gap:6px; align-items:center;">
                            {{-- La pièce se lit comme n'importe quelle autre, au
                                 lieu de n'être qu'une ligne de tableau.

                                 Le document de la DGI quand le scraper l'a
                                 rapporté, la reconstruction de Selflow sinon :
                                 lire une facture reçue ne doit plus demander
                                 d'aller l'exporter du portail à la main. --}}
                            @if($recue->pdfDisponible())
                                <a href="{{ route('admin.achats.factures_recues.pdf', $recue) }}" target="_blank" class="btn btn-primary btn-sm"
                                   title="Le document de la DGI, tel que le fournisseur l'a établi">
                                    <i class="fas fa-file-pdf"></i> Voir
                                </a>
                            @else
                                <a href="{{ route('admin.achats.factures_recues.imprimer', $recue) }}" class="btn btn-primary btn-sm"
                                   title="Reconstitué du relevé : le document de la DGI n'a pas encore été rapporté">
                                    <i class="fas fa-eye"></i> Voir
                                </a>
                            @endif

                            @if($propose['achat'])
                                <form method="POST" action="{{ route('admin.achats.factures_recues.rattacher', $recue) }}" style="display:inline; margin:0;">
                                    @csrf
                                    <button type="submit" class="btn btn-primary btn-sm" style="font-size:11px; padding:4px 8px;"
                                            title="Rattacher à l'achat {{ $propose['achat']->numero_facture }}@if($propose['ecart_ttc']) — écart de {{ number_format((float) $propose['ecart_ttc'], 0, ',', ' ') }} F sur le TTC @endif">
                                        <i class="fas fa-link"></i> Rattacher
                                    </button>
                                </form>
                                @if($propose['ecart_ttc'])
                                    <span style="font-size:11px; color:var(--danger); font-weight:700;" title="Le montant de l'achat saisi diffère de celui que la DGI détient">
                                        écart {{ number_format((float) $propose['ecart_ttc'], 0, ',', ' ') }} F
                                    </span>
                                @endif
                            @elseif(!$propose['fournisseur'])
                                {{-- Le NCC de l'emetteur ne designe aucun de vos
                                     fournisseurs : il n'y a meme pas de qui
                                     rapprocher. Le dire, plutot que de proposer
                                     un geste qui ne menerait nulle part. --}}
                                <span style="font-size:11px; color:var(--text-3);"
                                      title="Creez d'abord la fiche de ce fournisseur, avec son NCC.">
                                    <i class="fas fa-circle-question"></i> Aucun fournisseur ne porte ce NCC
                                </span>
                            @else
                                <a href="{{ route('admin.achats.nouveau') }}" class="btn btn-outline btn-sm" style="font-size:11px; padding:4px 8px;"
                                   title="Aucun achat de Selflow ne correspond encore. Saisissez-le, puis revenez le rattacher.">
                                    <i class="fas fa-magnifying-glass"></i> Saisir l'achat
                                </a>
                            @endif
                            @if($recue->statut_rapprochement === \App\Modules\Admin\Modeles\PortailFneFactureRecue::ECARTEE)
                            {{-- La remettre. C'est le seul geste qui compte sur
                                 une pièce écartée, et il n'existait que sur
                                 l'écran séparé. --}}
                            <form method="POST" action="{{ route('admin.achats.factures_recues.reintegrer', $recue) }}" style="display:inline; margin:0;">
                                @csrf
                                <button type="submit" class="btn btn-outline btn-sm" style="font-size:11px; padding:4px 8px;"
                                        title="Remettre cette pièce parmi les factures à rapprocher">
                                    <i class="fas fa-rotate-left"></i> Remettre
                                </button>
                            </form>
                            @else
                            {{-- Confirmation, parce que le geste était à sens
                                 unique et tenait à une icône : le 07/09/2026 la
                                 seule facture réelle du dossier a disparu de
                                 tous les écrans d'un clic, et il a fallu la base
                                 pour comprendre pourquoi. --}}
                            <form method="POST" action="{{ route('admin.achats.factures_recues.ecarter', $recue) }}" style="display:inline; margin:0;"
                                  onsubmit="return confirm('Écarter {{ $recue->reference }} ?\n\nElle disparaîtra de cet écran et du registre FNE. Vous pourrez la remettre depuis cette même section.');">
                                @csrf
                                <button type="submit" class="btn btn-outline btn-sm" style="font-size:11px; padding:4px 8px; color:var(--text-3);"
                                        title="Écarter cette pièce : elle ne remontera plus ici, sans être supprimée">
                                    <i class="fas fa-eye-slash"></i>
                                </button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @endforeach

                @foreach($achats as $achat)
                <tr @if($achat->normalise) style="background:#ecfdf5; border-left:4px solid #10b981;" @else style="background:#fffbeb; border-left:4px solid #f59e0b;" @endif>
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
                        @if($sectionSansDgi)
                            {{-- Rien à dire, et le dire ainsi. Cette facture
                                 n'est pas la nôtre : c'est le fournisseur qui
                                 la certifie. Le rapprochement avec les pièces
                                 relevées au portail viendra plus tard, et c'est
                                 lui qui remplira cette colonne. --}}
                            <span style="color:var(--text-3); font-size:12px;" title="Cette facture ne part pas à la DGI : c'est votre fournisseur qui certifie la sienne.">Aucune donnée</span>
                        @elseif($achat->normalise)
                            <span style="background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:4px 10px; border-radius:20px; font-weight:800; font-size:12px; display:inline-flex; align-items:center; gap:5px;" title="Facture normalisée avec succès par la DGI">
                                <i class="fas fa-check-circle" style="color:#16a34a;"></i> Oui
                            </span>
                        @elseif($achat->estBapa() && $achat->etape === 'Facture')
                            {{-- Le bordereau attend la main qui le normalisera :
                                 il n'y a pas d'envoi automatique à l'achat. --}}
                            <span style="background:#f8fafc; color:#475569; border:1px solid #cbd5e1; padding:4px 10px; border-radius:20px; font-weight:700; font-size:12px; display:inline-flex; align-items:center; gap:5px;" title="Le bordereau se normalise par le bouton « Normaliser ».">
                                <i class="fas fa-hourglass-half" style="font-size:11px;"></i> En attente
                            </span>
                        @else
                            {{-- Une facture d'achat ordinaire ne part jamais à la
                                 DGI : c'est le fournisseur qui l'a certifiée. La
                                 roue qui tournait ici annonçait indéfiniment un
                                 envoi qui n'existait pas. --}}
                            <span style="color:var(--text-3); font-size:12px;" title="Seul le bordereau d'achat (BAPA) se normalise à l'achat : la facture du fournisseur est certifiée par lui.">—</span>
                        @endif
                    </td>
                    <td>
                        @php
                            $dgiVoirUrl = $achat->fichier_fne_pdf_url;
                        @endphp
                        @if($sectionSansDgi)
                            <span style="color:var(--text-3); font-size:12px;">Aucune donnée</span>
                        @else
                        {{-- Le même partage qu'aux ventes : le document rendu
                             par la plateforme, à voir et à emporter. --}}
                        <div style="display:flex; gap:6px; align-items:center;">
                            <span style="font-size:10px; color:var(--text-3); width:62px;">Bordereau</span>
                            @if($dgiVoirUrl)
                                <a href="{{ $dgiVoirUrl }}" target="_blank" class="btn btn-outline btn-sm" style="padding:4px 8px; font-size:11px;" title="Voir le document DGI">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="{{ $dgiVoirUrl }}" target="_blank" download class="btn btn-outline btn-sm" style="padding:4px 8px; font-size:11px;" title="Télécharger le fichier DGI">
                                    <i class="fas fa-download"></i>
                                </a>
                            @else
                                <span style="color:var(--text-3); font-size:11px;" title="La plateforme n'a rendu aucun fichier">—</span>
                            @endif
                        </div>
                        @endif
                    </td>
                    {{-- « Originale » : les documents établis par Selflow. Ce
                         ne sont pas des actions, et ils encombraient la colonne
                         qui en porte. --}}
                    <td>
                        <div style="display:flex; gap:6px; align-items:center;">
                            <a href="{{ route('admin.achats.imprimer', $achat) }}" class="btn btn-outline btn-sm" style="padding:4px 8px; font-size:11px;" title="Voir la facture d'achat établie par Selflow">
                                <i class="fas fa-file-invoice"></i> Facture
                            </a>
                            @if($achat->estBapa() && $achat->etape === 'Facture')
                                <a href="{{ route('admin.achats.bapa', $achat) }}" class="btn btn-outline btn-sm" style="color:var(--danger); border-color:var(--danger); font-size:11px; padding:4px 8px;" title="Bordereau d'achat de produits agricoles">
                                    <i class="fas fa-file-lines"></i> BAPA
                                </a>
                            @endif
                        </div>
                    </td>

                    @if($afficherActions)
                    <td>
                        <div style="display:flex; gap:6px; align-items:center;">
                            {{-- Normalisation manuelle (BAPA uniquement) --}}
                            @if(!$achat->normalise && $achat->etape === 'Facture' && $achat->estBapa())
                                <form method="POST" action="{{ route('admin.achats.normaliser', $achat) }}" style="display:inline; margin:0;">
                                    @csrf
                                    <button type="submit" class="btn btn-success btn-sm" style="font-weight:700; font-size:11px; padding:4px 8px;" title="Normaliser manuellement auprès de la DGI BAPA">
                                        <i class="fas fa-share-nodes"></i> Normaliser
                                    </button>
                                </form>
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
                    @endif
                </tr>
                @endforeach
            </tbody>
        </table>
        <div style="padding: 0 16px;">{{ $achats->appends(request()->query())->links() }}</div>
        @endif
    </div>
</div>
{{-- Le modal « Créer une facture d'avoir » vivait ici, avec ses quatre cents
     lignes de script. Retiré le 25/09/2026 à la demande du propriétaire :
     **un acheteur n'établit pas l'avoir de son fournisseur.** La DGI ne le
     prévoit pas — la plateforme ne certifie l'avoir que du côté de celui qui a
     émis la facture —, et Selflow offrait donc un document que rien ne rendait
     opposable. Les avoirs déjà enregistrés restent en base : ils sortent des
     listes, ils ne sont pas détruits. --}}

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

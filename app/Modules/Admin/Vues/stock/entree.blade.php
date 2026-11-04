@extends('admin::gabarits.application')
@use('App\Modules\Admin\Modeles\MouvementStock', 'Mvt')
@section('titre', 'Entrée de stock')
@section('topbar_titre', 'Stock — Entrée de stock')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-dolly"></i> Entrée de stock</h1>
        <p>Faites entrer de la marchandise arrivée sans achat saisi : achat sans facture,
           retour, don. Elle entre à son coût réel et le coût moyen se recalcule.</p>
        @if($modeTous)
            <div style="margin-top:8px; color:#6d28d9; font-size:13px; font-weight:700;"><i class="fas fa-store"></i> Tous les sites : chaque article conserve son site de réception.</div>
        @elseif($pointDeVenteId)
            <div style="margin-top:8px; color:{{ $couleursSites[$pointDeVenteId] ?? 'var(--text-2)' }}; font-size:13px;"><i class="fas fa-store"></i> Articles et historique du point de vente <strong>{{ $pointsDeVente->firstWhere('id', $pointDeVenteId)?->nom ?? 'sélectionné' }}</strong>.</div>
        @endif
    </div>
    <a href="{{ route('admin.stock.index') }}" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour au stock
    </a>
</div>

@if(session('succes'))
    <div class="alert alert-success" style="margin-bottom:16px; padding:12px 16px; background:#ecfdf5; border:1px solid #6ee7b7; border-radius:10px; color:#065f46; display:flex; align-items:center; gap:10px;">
        <i class="fas fa-check-circle"></i> {{ session('succes') }}
    </div>
@endif
@if(session('erreur'))
    <div class="alert alert-error" style="margin-bottom:16px; padding:12px 16px; background:#fef2f2; border:1px solid #fca5a5; border-radius:10px; color:#991b1b; display:flex; align-items:center; gap:10px;">
        <i class="fas fa-exclamation-circle"></i> {{ session('erreur') }}
    </div>
@endif
@if($errors->any())
    <div class="alert alert-error" style="margin-bottom:16px; padding:12px 16px; background:#fef2f2; border:1px solid #fca5a5; border-radius:10px; color:#991b1b;">
        @foreach(collect($errors->all())->unique() as $message)
            <div><i class="fas fa-exclamation-circle"></i> {{ $message }}</div>
        @endforeach
    </div>
@endif

{{-- « Tous » est une vue catalogue ; le site réel est affecté à chaque ligne. --}}
<form method="GET" action="{{ route('admin.stock.entree') }}" style="margin-bottom:18px;">
    <div class="card" style="padding:14px 16px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; border-left:4px solid {{ $modeTous ? '#7c3aed' : ($couleursSites[$pointDeVenteId] ?? 'var(--border)') }};">
        <label class="form-label" style="margin:0; font-weight:700;"><i class="fas fa-store" style="color:var(--text-2);"></i> Vue des articles</label>
        <select name="point_de_vente_id" id="filtreSiteEntree" class="form-control" style="width:auto; min-width:240px; font-weight:700; border-width:2px;" onchange="this.form.submit()">
            <option value="" {{ $selectionSite === '' ? 'selected' : '' }}>— Choisir un point de vente —</option>
            <option value="tout" data-couleur="#7c3aed" style="color:#7c3aed; font-weight:700;" {{ $modeTous ? 'selected' : '' }}>TOUS LES POINTS DE VENTE</option>
            @foreach($pointsDeVente as $site)
                <option value="{{ $site->id }}" data-couleur="{{ $couleursSites[$site->id] ?? '#475569' }}" style="color:{{ $couleursSites[$site->id] ?? '#475569' }}; font-weight:700;" {{ (string) $selectionSite === (string) $site->id ? 'selected' : '' }}>{{ $site->nom }}</option>
            @endforeach
        </select>
        @if($modeTous)
            <span style="padding:5px 10px; border-radius:999px; color:#6d28d9; background:#f3e8ff; border:1px solid #d8b4fe; font-size:12px; font-weight:800;">MODE MULTI-SITES</span>
        @elseif($pointDeVenteId)
            <span style="padding:5px 10px; border-radius:999px; color:#fff; background:{{ $couleursSites[$pointDeVenteId] ?? '#475569' }}; font-size:12px; font-weight:800;">{{ $pointsDeVente->firstWhere('id', $pointDeVenteId)?->nom }}</span>
        @endif
        <noscript><button type="submit" class="btn btn-outline">Afficher</button></noscript>
    </div>
</form>

@if(!$modeTous && !$pointDeVenteId)
    <div class="alert alert-info" style="padding:14px 16px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; color:#1e40af; display:flex; align-items:center; gap:10px;"><i class="fas fa-circle-info"></i><span>Choisissez un point de vente ou « Tous les points de vente » pour afficher les articles.</span></div>
    <div class="alert alert-info" style="padding:14px 16px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; color:#1e40af; display:flex; align-items:center; gap:10px;">
        <i class="fas fa-circle-info"></i>
@elseif($produits->isEmpty())
    <div class="alert alert-info" style="padding:14px 16px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; color:#1e40af; display:flex; align-items:center; gap:10px;">
        <i class="fas fa-circle-info"></i>
        <span>Aucun article stockable n'est référencé sur ce point de vente. Faites d'abord référencer les articles souhaités sur ce site depuis leur fiche produit.</span>
    </div>
@else
<form method="POST" action="{{ route('admin.stock.entree.enregistrer') }}" id="formEntree">
    @csrf
    <input type="hidden" name="vue_tous_sites" value="{{ $modeTous ? 1 : 0 }}">

    <div class="card" style="padding:16px 18px; margin-bottom:16px; display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:14px;">
        <div>
            <label class="form-label" for="raison_entree">Raison de l'entrée <span style="color:var(--danger);">*</span></label>
            <select name="raison_entree" id="raison_entree" class="form-control" required>
                @foreach(Mvt::RAISONS_ENTREE as $code => $libelle)
                    <option value="{{ $code }}" {{ old('raison_entree', 'achat_sans_facture') === $code ? 'selected' : '' }}>{{ $libelle }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="commentaire">Commentaire <span id="commentaireExige" style="color:var(--danger); display:none;">*</span></label>
            <input type="text" name="commentaire" id="commentaire" class="form-control" maxlength="255"
                   value="{{ old('commentaire') }}" placeholder="Fournisseur, donateur, bon de dépôt…">
        </div>
    </div>

    <div class="card" style="padding:0; overflow:hidden;">
        <div style="padding:14px 18px; border-bottom:1px solid var(--border); background:var(--bg3);">
            <strong style="font-size:13.5px;">Articles reçus</strong>
            <div style="font-size:12.5px; color:var(--text-2); margin-top:3px;">
                Le coût proposé est le coût moyen actuel (ou le prix d'achat de la fiche) : remplacez-le
                par ce que la marchandise a réellement coûté. Pour un don, indiquez sa valeur estimée.
            </div>
        </div>

        <div style="overflow-x:auto;">
            <table class="table" style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="background:var(--bg3); text-align:left;">
                        <th style="padding:10px 14px;">Article</th>
                        <th style="padding:10px 14px;">Point de vente</th>
                        <th style="padding:10px 14px; text-align:right;">En stock</th>
                        <th style="padding:10px 14px; width:150px;">Quantité</th>
                        <th style="padding:10px 14px; width:170px;">Coût unitaire (FCFA)</th>
                        <th style="padding:10px 14px; text-align:right; width:150px;">Valeur</th>
                        <th style="padding:10px 14px; width:50px;"></th>
                    </tr>
                </thead>
                <tbody id="lignesEntree"></tbody>
                <tfoot>
                    <tr style="border-top:2px solid var(--border);">
                        <td colspan="5" style="padding:10px 14px; text-align:right; font-weight:700;">Valeur totale de l'entrée</td>
                        <td style="padding:10px 14px; text-align:right; font-weight:800;" id="totalEntree">0</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div style="padding:14px 18px; border-top:1px solid var(--border); display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <button type="button" class="btn btn-outline" id="ajouterLigne">
                <i class="fas fa-plus"></i> Ajouter un article
            </button>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-check"></i> Enregistrer l'entrée
            </button>
            <span style="font-size:12.5px; color:var(--text-2);">
                Chaque ligne devient un mouvement « Entrée de stock », conservé au journal des mouvements.
            </span>
        </div>
    </div>
</form>

<template id="modeleLigne">
    <tr style="border-top:1px solid var(--border);">
        <td style="padding:8px 14px; min-width:260px;">
            <select class="form-control champ-produit" required>
                <option value="">— Choisir un article —</option>
                @foreach($produits as $produit)
                    @foreach($produit->stocks as $fiche)
                        @php
                            $siteOption = $fiche->pointDeVente;
                            if (!$siteOption) continue;
                            $couleurSite = $couleursSites[$fiche->point_de_vente_id] ?? '#475569';
                            $coutPropose = (float) $fiche->cump > 0 ? (float) $fiche->cump : (float) ($produit->prix_achat ?? 0);
                        @endphp
                        <option value="{{ $produit->id }}:{{ $fiche->point_de_vente_id }}" data-produit-id="{{ $produit->id }}"
                            data-site-id="{{ $fiche->point_de_vente_id }}" data-site-nom="{{ $siteOption->nom }}"
                            data-site-couleur="{{ $couleurSite }}" data-cout="{{ round($coutPropose, 2) }}"
                            data-stock="{{ (float) $fiche->quantite_disponible }}" data-unite="{{ $produit->unite }}"
                            style="color:{{ $couleurSite }}; font-weight:700;">
                            {{ $produit->nom }}{{ $produit->reference ? ' — ' . $produit->reference : '' }} — {{ $siteOption->nom }}
                        </option>
                    @endforeach
                @endforeach
            </select>
            <input type="hidden" class="champ-produit-id"><input type="hidden" class="champ-site-id">
        </td>
        <td style="padding:8px 14px;"><span class="badge-site-ligne" style="display:inline-block; padding:4px 9px; border-radius:999px; font-size:11px; font-weight:800;">—</span></td>
        <td style="padding:8px 14px; text-align:right;" class="cellule-stock">—</td>
        <td style="padding:8px 14px;"><input type="number" class="form-control champ-quantite" min="0.001" step="0.001" required style="width:100%; text-align:right;"></td>
        <td style="padding:8px 14px;"><input type="number" class="form-control champ-cout" min="0.01" step="0.01" required style="width:100%; text-align:right;"></td>
        <td style="padding:8px 14px; text-align:right; font-weight:700;" class="cellule-valeur">0</td>
        <td style="padding:8px 14px; text-align:center;"><button type="button" class="btn btn-outline retirer-ligne" title="Retirer la ligne" style="color:var(--danger); border-color:var(--danger); padding:4px 10px;"><i class="fas fa-trash-can"></i></button></td>
    </tr>
</template>

<script>
(function () {
    const corps=document.getElementById('lignesEntree'),modele=document.getElementById('modeleLigne');
    const total=document.getElementById('totalEntree'),anciennes=@json(array_values(old('lignes',[])));let rang=0;
    const montant=v=>Math.round(v).toLocaleString('fr-FR');
    function recalculer(){let somme=0;corps.querySelectorAll('tr').forEach(tr=>{const q=parseFloat(tr.querySelector('.champ-quantite').value)||0,c=parseFloat(tr.querySelector('.champ-cout').value)||0;tr.querySelector('.cellule-valeur').textContent=montant(q*c);somme+=q*c;});total.textContent=montant(somme)+' FCFA';}
    function ajouter(valeurs){
        const ligne=modele.content.firstElementChild.cloneNode(true),i=rang++;
        const produit=ligne.querySelector('.champ-produit'),produitId=ligne.querySelector('.champ-produit-id'),siteId=ligne.querySelector('.champ-site-id');
        const quantite=ligne.querySelector('.champ-quantite'),cout=ligne.querySelector('.champ-cout'),badge=ligne.querySelector('.badge-site-ligne');
        produit.name=`lignes[${i}][choix]`;produitId.name=`lignes[${i}][produit_id]`;siteId.name=`lignes[${i}][point_de_vente_id]`;
        quantite.name=`lignes[${i}][quantite]`;cout.name=`lignes[${i}][cout_unitaire]`;
        produit.addEventListener('change',()=>{
            const o=produit.selectedOptions[0];
            if(!o||!o.dataset.produitId){produitId.value='';siteId.value='';badge.textContent='—';badge.style.background='transparent';badge.style.color='inherit';ligne.querySelector('.cellule-stock').textContent='—';recalculer();return;}
            produitId.value=o.dataset.produitId;siteId.value=o.dataset.siteId;const couleur=o.dataset.siteCouleur||'#475569';
            badge.textContent=o.dataset.siteNom;badge.style.color=couleur;badge.style.backgroundColor=couleur+'18';badge.style.border='1px solid '+couleur;
            ligne.querySelector('.cellule-stock').textContent=parseFloat(o.dataset.stock||0).toLocaleString('fr-FR')+' '+(o.dataset.unite||'');
            if(!cout.value&&parseFloat(o.dataset.cout)>0)cout.value=o.dataset.cout;recalculer();
        });
        quantite.addEventListener('input',recalculer);cout.addEventListener('input',recalculer);
        ligne.querySelector('.retirer-ligne').addEventListener('click',()=>{if(corps.children.length>1){ligne.remove();recalculer();}});
        corps.appendChild(ligne);
        if(valeurs){produit.value=(valeurs.produit_id||'')+':'+(valeurs.point_de_vente_id||'');quantite.value=valeurs.quantite||'';cout.value=valeurs.cout_unitaire||'';produit.dispatchEvent(new Event('change'));}
        recalculer();
    }
    document.getElementById('ajouterLigne').addEventListener('click',()=>ajouter());(anciennes.length?anciennes:[null]).forEach(ajouter);
    const selectSite=document.getElementById('filtreSiteEntree');if(selectSite){const o=selectSite.selectedOptions[0],couleur=o?.dataset.couleur;if(couleur){selectSite.style.borderColor=couleur;selectSite.style.color=couleur;selectSite.style.backgroundColor=o.value==='tout'?'#f3e8ff':'#fff';}}
    const raison=document.getElementById('raison_entree'),commentaire=document.getElementById('commentaire');
    const exige=()=>{const autre=raison.value==='autre';commentaire.required=autre;document.getElementById('commentaireExige').style.display=autre?'inline':'none';};
    raison.addEventListener('change',exige);exige();
})();
</script>
@endif

@if($dernieres->isNotEmpty())
<div class="card" style="margin-top:20px; padding:0; overflow:hidden;">
    <div style="padding:14px 18px; border-bottom:1px solid var(--border);">
        <strong>Dernières entrées</strong>
    </div>
    <div style="overflow-x:auto;">
        <table class="table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:var(--bg3); text-align:left;">
                    <th style="padding:10px 14px;">Date</th>
                    <th style="padding:10px 14px;">Référence</th>
                    <th style="padding:10px 14px;">Article</th>
                    <th style="padding:10px 14px;">Raison</th>
                    <th style="padding:10px 14px; text-align:right;">Quantité</th>
                    <th style="padding:10px 14px; text-align:right;">Coût unitaire</th>
                    <th style="padding:10px 14px; text-align:right;">Coût moyen après</th>
                    <th style="padding:10px 14px;">Site</th>
                </tr>
            </thead>
            <tbody>
                @foreach($dernieres as $m)
                    <tr style="border-top:1px solid var(--border);">
                        <td style="padding:8px 14px; font-size:12px; color:var(--text-3);">{{ $m->created_at->format('d/m/Y H:i') }}</td>
                        <td style="padding:8px 14px; font-family:monospace; font-size:12px;">{{ $m->reference_document }}</td>
                        <td style="padding:8px 14px; font-weight:600;">{{ $m->produit->nom ?? '—' }}</td>
                        <td style="padding:8px 14px;">
                            {{ Mvt::RAISONS_ENTREE[$m->raison_entree] ?? '—' }}
                            @if($m->commentaire)
                                <div style="font-size:11.5px; color:var(--text-3);">{{ $m->commentaire }}</div>
                            @endif
                        </td>
                        <td style="padding:8px 14px; text-align:right;">@qte($m->quantite) <span style="font-size:11px; color:var(--text-3);">{{ $m->produit->unite ?? '' }}</span></td>
                        <td style="padding:8px 14px; text-align:right;">{{ number_format((float) $m->cout_unitaire, 0, ',', ' ') }}</td>
                        <td style="padding:8px 14px; text-align:right;">{{ number_format((float) $m->cump_apres, 0, ',', ' ') }}</td>
                        <td style="padding:8px 14px;">@php $couleurHistorique=$couleursSites[$m->point_de_vente_id]??'#475569'; @endphp<span style="display:inline-block;padding:4px 9px;border-radius:999px;color:#fff;background:{{ $couleurHistorique }};font-size:11px;font-weight:800;">{{ $m->pointDeVente->nom ?? '—' }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection

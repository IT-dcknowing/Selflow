@extends('admin::gabarits.application')
@section('titre', 'Nouvel ordre de production')
@section('topbar_titre', 'Production — Nouvel ordre')

@section('styles')
<style>
    .besoin-card {
        background: #f8fafc;
        border: 0.5px solid var(--border);
        border-radius: 8px;
        padding: 12px 14px;
        margin-bottom: 10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: all 0.2s;
    }
    .status-badge {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        padding: 3px 8px;
        border-radius: 4px;
    }
    .status-ok {
        background: rgba(16,185,129,0.1);
        color: var(--success);
    }
    .status-error {
        background: rgba(239,68,68,0.1);
        color: var(--danger);
    }
</style>
@endsection

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-industry" style="color:var(--primary); margin-right:8px;"></i> Nouvel ordre de production</h1>
        <p>Déterminez la quantité à fabriquer et vérifiez la disponibilité des composants en temps réel.</p>
    </div>
    <a href="{{ route('admin.production.ordres.index') }}" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger" style="margin-bottom:20px;">
        <ul style="margin:0; padding-left:20px;">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('admin.production.ordres.enregistrer') }}" id="production-form">
    @csrf

    <div class="grid-3-1">
        
        {{-- Formulaire à gauche --}}
        <div>
            <div class="card">
                <div class="card-header">
                    <h2>Paramètres de Fabrication</h2>
                </div>
                <div class="card-body">
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Produit à Fabriquer</label>
                            <select name="produit_fini_id" id="produit_fini_id" class="form-control" required onchange="mettreAJourBesoins()">
                                <option value="">Choisir un produit fini...</option>
                                @foreach($produitsFini as $pf)
                                    <option value="{{ $pf->id }}" {{ old('produit_fini_id') == $pf->id ? 'selected' : '' }}>
                                        {{ $pf->nom }} ({{ $pf->reference }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Point de Vente / Site de Production</label>
                            <select name="point_de_vente_id" id="point_de_vente_id" class="form-control" required onchange="mettreAJourBesoins()">
                                <option value="">Choisir le site...</option>
                                @foreach($pdvs as $pdv)
                                    <option value="{{ $pdv->id }}" {{ (int) old('point_de_vente_id', $siteActif) === $pdv->id ? 'selected' : '' }}>
                                        {{ $pdv->nom }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="form-grid-2" style="margin-top:16px;">
                        <div class="form-group">
                            <label class="form-label">Quantité cible</label>
                            <input type="number" step="0.001" min="0.001" name="quantite_cible" id="quantite_cible" value="{{ old('quantite_cible') }}" placeholder="Quantité à produire" class="form-control" required oninput="mettreAJourBesoins()">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Date de Production</label>
                            <input type="date" name="date_production" value="{{ old('date_production', now()->toDateString()) }}" class="form-control" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" id="btn-submit" style="width:100%; display:flex; justify-content:center; margin-top:24px; height:44px; font-size:14px;">
                        <i class="fas fa-hammer"></i> Créer l'Ordre de Production
                    </button>
                </div>
            </div>
        </div>

        {{-- Panel de besoins théoriques / stocks en direct --}}
        <div>
            <div class="card" style="height:100%; min-height:300px;">
                <div class="card-header" style="background:#f8fafc;">
                    <h2>Besoins théoriques en Matières Premières</h2>
                </div>
                <div class="card-body" id="besoins-panel" style="padding:20px;">
                    <div style="text-align:center; color:var(--text-3); padding-top:40px;" id="no-selection-msg">
                        <i class="fas fa-info-circle" style="font-size:32px; display:block; margin-bottom:12px; opacity:.4;"></i>
                        Veuillez choisir un produit fini et renseigner la quantité pour simuler les besoins de production.
                    </div>
                    <div id="besoins-list" style="display:none;"></div>
                </div>
            </div>
        </div>

    </div>
</form>

<script>
    const produitsFini = @json($produitsFini);
    document.addEventListener('DOMContentLoaded', mettreAJourBesoins);

    function mettreAJourBesoins() {
        const pfId = document.getElementById('produit_fini_id').value;
        const pdvId = document.getElementById('point_de_vente_id').value;
        const qteCible = parseFloat(document.getElementById('quantite_cible').value) || 0;

        const msgBox = document.getElementById('no-selection-msg');
        const listDiv = document.getElementById('besoins-list');

        if (!pfId || qteCible <= 0) {
            msgBox.style.display = 'block';
            listDiv.style.display = 'none';
            return;
        }

        msgBox.style.display = 'none';
        listDiv.style.display = 'block';
        listDiv.innerHTML = '';

        // Retrouver le produit fini sélectionné
        const product = produitsFini.find(p => p.id == pfId);
        if (!product || !product.fiche_technique) {
            listDiv.innerHTML = '<div style="color:var(--danger); font-size:12.5px;">Erreur: aucune fiche technique trouvée.</div>';
            return;
        }

        const details = product.fiche_technique.details || [];
        let stockOk = true;

        details.forEach(d => {
            // Le besoin se compte dans l'unité de stock de l'ingrédient, à la
            // précision du stock (3 décimales) — comme à la validation.
            const facteur = d.facteur_stock;
            const uniteStock = d.unite_stock;
            if (facteur === null || facteur === undefined) {
                stockOk = false;
                listDiv.innerHTML += `<div class="besoin-card" style="border-left: 4px solid var(--danger);"><div><strong style="font-size:12.5px;">${d.ingredient ? d.ingredient.nom : '?'}</strong><div style="font-size:11px; color:var(--danger);">L'unité « ${d.unite} » de la recette ne se convertit pas en ${uniteStock}.</div></div></div>`;
                return;
            }
            const besoinTotal = Math.round(parseFloat(d.quantite) * facteur * qteCible * 1000) / 1000;
            
            // Retrouver le stock de l'ingrédient sur le point de vente
            let dispo = 0;
            const ingredient = d.ingredient;
            if (ingredient && ingredient.stocks && pdvId) {
                const stockObj = ingredient.stocks.find(s => s.point_de_vente_id == pdvId);
                if (stockObj) {
                    dispo = parseFloat(stockObj.quantite_disponible) || 0;
                }
            }

            const isInsuffisant = Math.round(dispo * 1000) < Math.round(besoinTotal * 1000);
            if (isInsuffisant) {
                stockOk = false;
            }

            const itemHtml = `
                <div class="besoin-card" style="border-left: 4px solid ${isInsuffisant ? 'var(--danger)' : 'var(--success)'};">
                    <div>
                        <strong style="font-size:12.5px; color:var(--text);">${ingredient.nom}</strong>
                        <div style="font-size:11px; color:var(--text-2); margin-top:2px;">
                            Besoin : <strong>${besoinTotal.toFixed(3)} ${uniteStock}</strong> · Stock : ${dispo.toFixed(3)} ${uniteStock}
                        </div>
                    </div>
                    <div>
                        <span class="status-badge ${isInsuffisant ? 'status-error' : 'status-ok'}">
                            ${isInsuffisant ? 'Insuffisant' : 'Disponible'}
                        </span>
                    </div>
                </div>
            `;

            listDiv.innerHTML += itemHtml;
        });

        // Alerte globale si rupture de stock
        const alertHtml = `
            <div style="margin-top:16px; padding:10px 14px; border-radius:6px; font-size:11.5px; font-weight:700; text-align:center;
                background:${stockOk ? 'rgba(16,185,129,0.1)' : 'rgba(239,68,68,0.1)'};
                color:${stockOk ? 'var(--success)' : 'var(--danger)'};">
                <i class="fas ${stockOk ? 'fa-check-circle' : 'fa-exclamation-triangle'}"></i>
                ${stockOk ? 'Matières Premières Suffisantes' : 'Rupture de Stock Détectée'}
            </div>
        `;
        listDiv.innerHTML += alertHtml;
    }
</script>
@endsection

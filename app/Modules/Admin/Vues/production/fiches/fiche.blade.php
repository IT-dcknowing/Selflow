@extends('admin::gabarits.application')
@section('titre', ($mode === 'creation' ? 'Nouvelle Recette' : 'Modifier la Recette'))
@section('topbar_titre', 'Production — Fiche Technique')

@section('styles')
<style>
    .ingredient-row {
        background: #f8fafc;
        border: 0.5px solid var(--border);
        border-radius: 8px;
        padding: 12px 16px;
        margin-bottom: 12px;
        display: grid;
        grid-template-columns: 3fr 1.5fr 1fr auto;
        gap: 16px;
        align-items: center;
        transition: all 0.2s;
    }
    .ingredient-row:hover {
        border-color: var(--primary-l);
        background: #f1f5f9;
    }
    .btn-delete-row {
        background: none;
        border: none;
        color: var(--danger);
        font-size: 16px;
        cursor: pointer;
        padding: 6px;
        border-radius: 4px;
        transition: background 0.15s;
    }
    .btn-delete-row:hover {
        background: rgba(239,68,68,0.1);
    }
</style>
@endsection

@section('contenu')
<div class="page-header">
    <div>
        <h1>
            <i class="fas fa-flask" style="color:var(--primary); margin-right:8px;"></i>
            {{ $mode === 'creation' ? 'Nouvelle Recette' : 'Modifier la Recette' }}
        </h1>
        <p>Associez des matières premières et ingrédients au produit fabriqué.</p>
    </div>
    <a href="{{ route('admin.production.fiches_techniques.index') }}" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour
    </a>
</div>

{{-- Zone d'erreurs --}}
@if($errors->any())
    <div class="alert alert-danger" style="margin-bottom:20px;">
        <ul style="margin:0; padding-left:20px;">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ $mode === 'creation' ? route('admin.production.fiches_techniques.enregistrer') : route('admin.production.fiches_techniques.modifier.enregistrer', $fiche) }}">
    @csrf
    @if($mode === 'edition')
        @method('PUT')
    @endif

    <div class="grid-3-1">
        
        {{-- Section Principale --}}
        <div>
            <div class="card" style="margin-bottom: 24px;">
                <div class="card-header">
                    <h2>Composition et Ingrédients</h2>
                </div>
                <div class="card-body">
                    <div id="ingredients-container">
                        @php
                            $details = old('ingredients', $fiche->details ?? []);
                        @endphp

                        @php
                            // Une ligne vide pour démarrer une recette.
                            if (count($details) === 0) {
                                $details = [['ingredient_id' => null, 'quantite' => null, 'unite' => null]];
                            }
                            $parId = $ingredients->keyBy('id');
                        @endphp

                        @foreach($details as $index => $detail)
                            @php
                                $detailObj = is_array($detail) ? (object) $detail : $detail;
                                $choisi = $parId->get((int) ($detailObj->ingredient_id ?? 0));
                                // Seules les unités qui se convertissent dans l'unité de
                                // stock de l'ingrédient : 200 g d'une farine stockée en kg,
                                // jamais des litres.
                                $unites = $choisi ? $choisi->unites_compatibles : [];
                            @endphp
                            <div class="ingredient-row" id="row-{{ $index }}">
                                <div>
                                    <label class="form-label" style="font-size:10px;">Ingrédient / Matière Première</label>
                                    <select name="ingredients[{{ $index }}][ingredient_id]" class="form-control" required onchange="majUnites(this)">
                                        <option value="">Sélectionner un ingrédient...</option>
                                        @foreach($ingredients as $ing)
                                            <option value="{{ $ing->id }}" data-unites='@json($ing->unites_compatibles)' {{ $ing->id == $detailObj->ingredient_id ? 'selected' : '' }}>
                                                {{ $ing->nom }} ({{ $ing->reference }}) — Stock : {{ $ing->stock_actuel }} {{ $ing->unite }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label" style="font-size:10px;">Quantité requise (pour 1 unité produite)</label>
                                    <input type="number" step="0.0001" min="0.0001" name="ingredients[{{ $index }}][quantite]" value="{{ $detailObj->quantite }}" placeholder="Ex: 0.350" class="form-control" required>
                                </div>
                                <div>
                                    <label class="form-label" style="font-size:10px;">Unité</label>
                                    <select name="ingredients[{{ $index }}][unite]" class="form-control unite-select" required>
                                        @forelse($unites as $u)
                                            <option value="{{ $u }}" {{ $detailObj->unite == $u ? 'selected' : '' }}>{{ $u }}</option>
                                        @empty
                                            <option value="">—</option>
                                        @endforelse
                                    </select>
                                </div>
                                <div style="padding-top:20px;">
                                    <button type="button" onclick="supprimerLigne({{ $index }})" class="btn-delete-row" title="Supprimer cet ingrédient">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <button type="button" onclick="ajouterLigne()" class="btn btn-outline" style="margin-top:8px;">
                        <i class="fas fa-plus"></i> Ajouter un ingrédient
                    </button>
                </div>
            </div>
        </div>

        {{-- Barre latérale de configuration --}}
        <div>
            <div class="card" style="margin-bottom: 24px;">
                <div class="card-header">
                    <h2>Produit Fini</h2>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <label class="form-label" style="margin-bottom:0;">Produit fabriqué</label>
                            @if($mode === 'creation')
                                <label style="font-size:11.5px; font-weight:600; cursor:pointer; color:var(--primary); display:flex; align-items:center; gap:4px; margin-bottom:0;">
                                    <input type="checkbox" id="chkNouveauProduit" onchange="toggleNouveauProduit()" style="cursor:pointer;"> Saisie libre (Nouveau)
                                </label>
                            @endif
                        </div>
                        @if($mode === 'creation')
                            <div id="selectProduitContainer">
                                <select name="produit_fini_id" id="produitFiniSelect" class="form-control" required>
                                    <option value="">Choisir un produit fini...</option>
                                    @foreach($produitsFini as $pf)
                                        <option value="{{ $pf->id }}" {{ old('produit_fini_id') == $pf->id ? 'selected' : '' }}>
                                            {{ $pf->nom }} ({{ $pf->reference }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div id="inputProduitContainer" style="display:none;">
                                <input type="text" name="nouveau_produit_fini_nom" id="nouveauProduitInput" class="form-control" placeholder="Nom du nouveau produit fini à créer..." value="{{ old('nouveau_produit_fini_nom') }}">
                                <select name="nouveau_produit_fini_categorie_id" class="form-control" style="margin-top:8px;" title="Le rayon donne au produit sa référence et ses comptes">
                                    <option value="">Rayon : celui des produits finis existants</option>
                                    @foreach($categories ?? [] as $cat)
                                        <option value="{{ $cat->id }}" {{ old('nouveau_produit_fini_categorie_id') == $cat->id ? 'selected' : '' }}>Rayon : {{ $cat->nom }}</option>
                                    @endforeach
                                </select>
                                <div style="font-size:11px; color:var(--text-3); margin-top:6px;">
                                    Si un produit fini porte déjà ce nom, la recette lui est rattachée ; sinon il est créé.
                                </div>
                            </div>
                        @else
                            <input type="text" class="form-control" value="{{ $fiche->produitFini->nom }} ({{ $fiche->produitFini->reference }})" disabled>
                            <input type="hidden" name="produit_fini_id" value="{{ $fiche->produit_fini_id }}">
                        @endif
                    </div>

                    <div class="form-group" style="margin-top:16px;">
                        <label class="form-label">Notes de fabrication</label>
                        <textarea name="description" rows="5" class="form-control" placeholder="Ajoutez des détails sur la recette, le temps de cuisson, ou des consignes pour l'atelier...">{{ old('description', $fiche->description) }}</textarea>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%; display:flex; justify-content:center; margin-top:20px;">
                        <i class="fas fa-save"></i> Enregistrer la Recette
                    </button>
                </div>
            </div>
        </div>

    </div>
</form>

<script>
    let rowIndex = {{ count($details) > 0 ? count($details) : 1 }};
    const ingredientsData = @json($ingredients);

    function ajouterLigne() {
        const container = document.getElementById('ingredients-container');
        
        let selectOptions = '<option value="">Sélectionner un ingrédient...</option>';
        ingredientsData.forEach(ing => {
            const unites = JSON.stringify(ing.unites_compatibles || []).replace(/'/g, '&#39;');
            selectOptions += `<option value="${ing.id}" data-unites='${unites}'>${ing.nom} (${ing.reference}) — Stock : ${ing.stock_actuel} ${ing.unite || ''}</option>`;
        });

        const newRow = document.createElement('div');
        newRow.className = 'ingredient-row';
        newRow.id = `row-${rowIndex}`;
        newRow.innerHTML = `
            <div>
                <label class="form-label" style="font-size:10px;">Ingrédient / Matière Première</label>
                <select name="ingredients[${rowIndex}][ingredient_id]" class="form-control" required onchange="majUnites(this)">
                    ${selectOptions}
                </select>
            </div>
            <div>
                <label class="form-label" style="font-size:10px;">Quantité requise (pour 1 unité produite)</label>
                <input type="number" step="0.0001" min="0.0001" name="ingredients[${rowIndex}][quantite]" placeholder="Ex: 0.350" class="form-control" required>
            </div>
            <div>
                <label class="form-label" style="font-size:10px;">Unité</label>
                <select name="ingredients[${rowIndex}][unite]" class="form-control unite-select" required>
                    <option value="">—</option>
                </select>
            </div>
            <div style="padding-top:20px;">
                <button type="button" onclick="supprimerLigne(${rowIndex})" class="btn-delete-row" title="Supprimer cet ingrédient">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </div>
        `;

        container.appendChild(newRow);
        rowIndex++;
    }

    // Les unités proposées suivent l'ingrédient choisi : son unité de stock
    // et ses équivalentes, rien d'autre.
    function majUnites(select) {
        const row = select.closest('.ingredient-row');
        const uniteSelect = row ? row.querySelector('.unite-select') : null;
        if (!uniteSelect) return;
        const option = select.options[select.selectedIndex];
        let unites = [];
        try { unites = JSON.parse(option && option.dataset.unites ? option.dataset.unites : '[]'); } catch (e) { unites = []; }
        const actuelle = uniteSelect.value;
        uniteSelect.innerHTML = '';
        if (unites.length === 0) {
            uniteSelect.add(new Option('—', ''));
            return;
        }
        unites.forEach(u => uniteSelect.add(new Option(u, u, false, u === actuelle)));
    }

    function supprimerLigne(index) {
        const row = document.getElementById(`row-${index}`);
        if (row) {
            // S'il reste au moins une ligne, on supprime, sinon on alerte
            const allRows = document.querySelectorAll('.ingredient-row');
            if (allRows.length > 1) {
                row.remove();
            } else {
                alert('Une fiche technique doit comporter au moins un ingrédient.');
            }
        }
    }

    function toggleNouveauProduit() {
        const chk = document.getElementById('chkNouveauProduit');
        const selContainer = document.getElementById('selectProduitContainer');
        const inpContainer = document.getElementById('inputProduitContainer');
        const sel = document.getElementById('produitFiniSelect');
        const inp = document.getElementById('nouveauProduitInput');
        
        if (chk && chk.checked) {
            if (selContainer) selContainer.style.display = 'none';
            if (sel) {
                sel.required = false;
                sel.value = '';
            }
            if (inpContainer) inpContainer.style.display = 'block';
            if (inp) {
                inp.required = true;
                inp.focus();
            }
        } else {
            if (selContainer) selContainer.style.display = 'block';
            if (sel) sel.required = true;
            if (inpContainer) inpContainer.style.display = 'none';
            if (inp) {
                inp.required = false;
                inp.value = '';
            }
        }
    }
</script>
@endsection

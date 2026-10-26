@extends('admin::gabarits.application')
@section('titre', 'Inventaire physique')
@section('topbar_titre', 'Stock — Inventaire physique')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-clipboard-check"></i> Inventaire physique</h1>
        <p>Comptez ce qui est réellement en magasin. L'écart avec le stock théorique
           devient un mouvement, dans le sens qu'il faut.</p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        {{-- L'export de l'inventaire du site affiché (26/10/2026). --}}
        @if($pointDeVenteId)
            <a href="{{ route('admin.stock.inventaire.exporter', ['format' => 'pdf', 'point_de_vente_id' => $pointDeVenteId]) }}" class="btn btn-outline" id="export-inventaire-pdf">
                <i class="fas fa-file-pdf" style="color:#dc2626;"></i> PDF
            </a>
            <a href="{{ route('admin.stock.inventaire.exporter', ['format' => 'excel', 'point_de_vente_id' => $pointDeVenteId]) }}" class="btn btn-outline" id="export-inventaire-excel">
                <i class="fas fa-file-excel" style="color:#16a34a;"></i> Excel
            </a>
        @endif
        {{-- Le stock d'ouverture d'un catalogue deja en place. Sans lui, un
             magasin qui migre saisit ses deux mille quantites a la main : c'est
             le moment ou l'on abandonne. --}}
        <button type="button" class="btn" onclick="ouvrirImport('modalImportStockInitial')">
            <i class="fas fa-file-import"></i> Importer un stock d'ouverture
        </button>
        <a href="{{ route('admin.stock.index') }}" class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> Retour à l'inventaire
        </a>
    </div>
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

{{-- Le site de comptage. Un inventaire se fait dans un magasin, pas dans une
     entreprise : « tous les sites » n'a pas de sens ici. --}}
<form method="GET" action="{{ route('admin.stock.inventaire') }}" style="margin-bottom:18px;">
    <div class="card" style="padding:14px 16px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <label class="form-label" style="margin:0; font-weight:700;">
            <i class="fas fa-store" style="color:var(--text-2);"></i> Site à compter
        </label>
        <select name="point_de_vente_id" class="form-control" style="width:auto; min-width:220px;"
                onchange="this.form.submit()">
            <option value="">— Choisir un site —</option>
            @foreach($pointsDeVente as $site)
                <option value="{{ $site->id }}" {{ (int) $pointDeVenteId === (int) $site->id ? 'selected' : '' }}>
                    {{ $site->nom }}
                </option>
            @endforeach
        </select>
        <noscript><button type="submit" class="btn btn-outline">Afficher</button></noscript>
    </div>
</form>

@if(!$pointDeVenteId)
    <div class="alert alert-info" style="padding:14px 16px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; color:#1e40af; display:flex; align-items:center; gap:10px;">
        <i class="fas fa-circle-info"></i>
        <span>Choisissez le site où le comptage a lieu.</span>
    </div>
@elseif($produits->isEmpty())
    <div class="alert alert-info" style="padding:14px 16px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; color:#1e40af; display:flex; align-items:center; gap:10px;">
        <i class="fas fa-circle-info"></i>
        <span>Aucun article qui se compte dans votre catalogue. Les prestations ne s'inventorient pas.</span>
    </div>
@else
<form method="POST" action="{{ route('admin.stock.inventaire.enregistrer') }}">
    @csrf
    <input type="hidden" name="point_de_vente_id" value="{{ $pointDeVenteId }}">

    <div class="card" style="padding:0; overflow:hidden;">
        <div style="padding:14px 18px; border-bottom:1px solid var(--border); background:var(--bg3);">
            <strong style="font-size:13.5px;">
                Laissez vide ce que vous n'avez pas compté
            </strong>
            <div style="font-size:12.5px; color:var(--text-2); margin-top:3px;">
                Un champ vide veut dire « pas compté », pas « zéro ». Seules les lignes
                renseignées seront ajustées.
            </div>
        </div>

        <div style="overflow-x:auto;">
            <table class="table" style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="background:var(--bg3); text-align:left;">
                        <th style="padding:10px 14px;">Référence</th>
                        <th style="padding:10px 14px;">Article</th>
                        <th style="padding:10px 14px; text-align:right;">Stock théorique</th>
                        <th style="padding:10px 14px; width:170px;">Compté</th>
                        <th style="padding:10px 14px; text-align:right; width:120px;">Écart</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($produits as $produit)
                        @php $theorique = $produit->stockSur($pointDeVenteId); @endphp
                        <tr style="border-top:1px solid var(--border);" data-theorique="{{ $theorique }}">
                            <td style="padding:10px 14px;">
                                <span class="cpt" style="font-family:monospace; font-size:12px; color:var(--text-3);">
                                    {{ $produit->reference }}
                                </span>
                            </td>
                            <td style="padding:10px 14px; font-weight:600;">{{ $produit->nom }}</td>
                            <td style="padding:10px 14px; text-align:right;">
                                @qte($theorique)
                                <span style="font-size:11px; color:var(--text-3);">{{ $produit->unite }}</span>
                            </td>
                            <td style="padding:10px 14px;">
                                <input type="number" name="comptages[{{ $produit->id }}]"
                                       class="form-control champ-compte" min="0" step="0.001"
                                       placeholder="—" style="width:100%; text-align:right;">
                            </td>
                            <td style="padding:10px 14px; text-align:right; font-weight:700;" class="cellule-ecart">—</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div style="padding:14px 18px; border-top:1px solid var(--border); display:flex; align-items:center; gap:12px;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-check"></i> Enregistrer le comptage
            </button>
            <span style="font-size:12.5px; color:var(--text-2);">
                Chaque écart produit un mouvement d'inventaire, conservé au journal.
            </span>
        </div>
    </div>
</form>

<script>
// L'écart s'affiche à la saisie : c'est le moment où l'on peut encore
// recompter, pas après l'enregistrement.
document.querySelectorAll('.champ-compte').forEach(champ => {
    champ.addEventListener('input', () => {
        const ligne = champ.closest('tr');
        const cellule = ligne.querySelector('.cellule-ecart');
        const theorique = parseFloat(ligne.dataset.theorique) || 0;

        if (champ.value === '') {
            cellule.textContent = '—';
            cellule.style.color = '';
            return;
        }

        // Pas `quantiteSaisie` ici : elle ramene toute valeur nulle au plus
        // petit pas, alors qu'un comptage a zero est legitime — un rayon
        // vide se compte, il ne s'ignore pas.
        const compte = parseFloat(champ.value);
        if (isNaN(compte)) { cellule.textContent = '—'; cellule.style.color = ''; return; }

        const ecart = Math.round((compte - theorique) * 1000) / 1000;

        cellule.textContent = ecart === 0 ? '0' : (ecart > 0 ? '+' : '') + quantiteAffichee(ecart);
        cellule.style.color = ecart === 0 ? 'var(--text-3)'
                            : (ecart > 0 ? 'var(--success)' : 'var(--danger)');
    });
});
</script>
@endif
@include('admin::composants.modal-import', [
    'type'  => 'stock-initial',
    'label' => 'Stock d\'ouverture',
    'id'    => 'modalImportStockInitial',
])
@endsection

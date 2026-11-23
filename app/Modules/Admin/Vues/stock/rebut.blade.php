@extends('admin::gabarits.application')
@section('titre', 'Gestion des Rebuts')
@section('topbar_titre', 'Stock — Rebuts')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-trash-can"></i> Gestion des Rebuts & Pertes</h1>
        <p>Retirez du stock les produits périmés ou endommagés.</p>
    </div>
    <a href="{{ route('admin.stock.index') }}" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour à l'inventaire
    </a>
</div>

{{-- Flash messages --}}
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

@if($pointDeVenteId === 'tout')
    <div class="alert alert-info" style="margin-bottom:20px; padding:12px 16px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; color:var(--marque-800, #1e40af); display:flex; align-items:center; gap:10px;">
        <i class="fas fa-circle-info"></i>
        <span>Veuillez filtrer par site spécifique sur la page principale de l'inventaire pour pouvoir jeter un article d'un entrepôt en particulier.</span>
    </div>
@endif

{{-- ══════════ Les lots ══════════

     C'est ici que la péremption se joue vraiment. `produits.date_peremption`
     ne porte qu'une date par article : une pharmacie qui reçoit trois arrivages
     de paracétamol n'en enregistre qu'un, la saisie du troisième écrasant les
     deux premiers, et les boîtes de mars restent en rayon sans que rien ne les
     signale. Les deux tableaux plus bas restent pour les articles qui ne sont
     pas suivis par lot, où une date unique suffit. --}}
@if($lotsPerimes->isNotEmpty() || $lotsProches->isNotEmpty())
<div class="card" style="margin-bottom:20px;">
    <div class="card-header" style="border-bottom:1px solid var(--border); padding-bottom:12px;">
        <h2 style="font-size:16px; font-weight:700;">
            <i class="fas fa-layer-group"></i> Lots — suivi par arrivage
        </h2>
        <p style="font-size:12px; color:var(--text-3); margin-top:4px;">
            Chaque arrivage porte sa propre date. La sortie sert d'abord ce qui périme le plus tôt.
        </p>
    </div>
    <div class="table-wrap" style="padding-top:10px;">
        <table>
            <thead>
                <tr>
                    <th>Article</th>
                    <th>Lot</th>
                    <th>Site</th>
                    <th>Péremption</th>
                    <th>Reste</th>
                    <th>État</th>
                </tr>
            </thead>
            <tbody>
                @foreach($lotsPerimes as $lot)
                    <tr style="background:#fef2f2;">
                        <td>{{ $lot->produit?->nom }}</td>
                        <td style="font-family:monospace;">{{ $lot->numero_lot }}</td>
                        <td>{{ $lot->pointDeVente?->nom }}</td>
                        <td>{{ $lot->date_peremption?->format('d/m/Y') }}</td>
                        <td>@qte($lot->quantite) {{ $lot->produit?->unite }}</td>
                        <td>
                            <span style="color:var(--danger); font-weight:700;">
                                <i class="fas fa-skull"></i>
                                Périmé depuis {{ abs($lot->joursRestants()) }} j
                            </span>
                        </td>
                    </tr>
                @endforeach
                @foreach($lotsProches as $lot)
                    <tr style="background:#fffbeb;">
                        <td>{{ $lot->produit?->nom }}</td>
                        <td style="font-family:monospace;">{{ $lot->numero_lot }}</td>
                        <td>{{ $lot->pointDeVente?->nom }}</td>
                        <td>{{ $lot->date_peremption?->format('d/m/Y') }}</td>
                        <td>@qte($lot->quantite) {{ $lot->produit?->unite }}</td>
                        <td>
                            <span style="color:var(--warning); font-weight:700;">
                                <i class="fas fa-hourglass-half"></i>
                                Dans {{ $lot->joursRestants() }} j
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">

    {{-- Produits déjà périmés --}}
    <div class="card">
        <div class="card-header" style="border-bottom:1px solid var(--border); padding-bottom:12px;">
            <h2 style="color:var(--danger); font-size:16px; font-weight:700;"><i class="fas fa-skull"></i> Produits Périmés</h2>
        </div>
        <div class="table-wrap" style="padding-top:10px;">
            @if($perimes->isEmpty())
                <div style="text-align:center; padding:40px; color:var(--text-3); font-style:italic;">
                    Aucun produit périmé en stock.
                </div>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Produit</th>
                            <th>Péremption</th>
                            <th>Stock</th>
                            @if($pointDeVenteId !== 'tout')
                                <th>Action</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($perimes as $p)
                            <tr>
                                <td style="font-weight:600;">
                                    <span style="font-size:11px; font-family:monospace; color:var(--text-3); display:block;">{{ $p->reference }}</span>
                                    {{ $p->nom }}
                                </td>
                                <td style="color:var(--danger); font-weight:600;">
                                    {{ $p->date_peremption->format('d/m/Y') }}
                                </td>
                                <td>
                                    <strong>{{ $p->stock_actuel }}</strong> <span style="font-size:11px; color:var(--text-3);">{{ $p->unite }}</span>
                                </td>
                                @if($pointDeVenteId !== 'tout')
                                    <td>
                                        @if($p->stock_actuel > 0)
                                            <button class="btn btn-primary btn-sm" onclick="ouvrirModalRetrait({{ $p->id }}, @js($p->nom), {{ $p->stock_actuel }})" style="background:var(--danger); border-color:var(--danger);">
                                                <i class="fas fa-trash"></i> Retirer
                                            </button>
                                        @else
                                            <span style="color:var(--text-3); font-size:12px;">Aucun stock</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    {{-- Produits proches de la péremption --}}
    <div class="card">
        <div class="card-header" style="border-bottom:1px solid var(--border); padding-bottom:12px;">
            <h2 style="color:var(--warning); font-size:16px; font-weight:700;"><i class="fas fa-clock"></i> Péremption Proche (< 30 jours)</h2>
        </div>
        <div class="table-wrap" style="padding-top:10px;">
            @if($proches->isEmpty())
                <div style="text-align:center; padding:40px; color:var(--text-3); font-style:italic;">
                    Aucun produit proche de la date d'expiration.
                </div>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Produit</th>
                            <th>Péremption</th>
                            <th>Stock</th>
                            @if($pointDeVenteId !== 'tout')
                                <th>Action</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($proches as $p)
                            @php
                                // `diffInDays()` rend une difference signee : le
                                // sens compte, et l'ordre des operandes aussi.
                                // Ecrit dans l'autre sens, le compte a rebours
                                // affichait « -200 j restants ».
                                $restant = (int) now()->startOfDay()->diffInDays($p->date_peremption->startOfDay(), false);
                            @endphp
                            <tr>
                                <td style="font-weight:600;">
                                    <span style="font-size:11px; font-family:monospace; color:var(--text-3); display:block;">{{ $p->reference }}</span>
                                    {{ $p->nom }}
                                </td>
                                <td style="color:var(--warning); font-weight:600;">
                                    {{ $p->date_peremption->format('d/m/Y') }}
                                    <span style="font-size:10px; display:block; color:var(--text-3);">({{ $restant }} j restants)</span>
                                </td>
                                <td>
                                    <strong>{{ $p->stock_actuel }}</strong> <span style="font-size:11px; color:var(--text-3);">{{ $p->unite }}</span>
                                </td>
                                @if($pointDeVenteId !== 'tout')
                                    <td>
                                        @if($p->stock_actuel > 0)
                                            <button class="btn btn-primary btn-sm" onclick="ouvrirModalRetrait({{ $p->id }}, @js($p->nom), {{ $p->stock_actuel }})" style="background:var(--warning); border-color:var(--warning); color:#333;">
                                                <i class="fas fa-trash"></i> Retirer
                                            </button>
                                        @else
                                            <span style="color:var(--text-3); font-size:12px;">Aucun stock</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

</div>

{{-- Modal Retrait Rebut --}}
<div class="modal-overlay" id="modalRetraitRebut" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.5); justify-content:center; align-items:center; z-index:9999;">
    <div class="modal" style="background:var(--bg1); padding:24px; border-radius:12px; max-width:460px; width:100%;">
        <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
            <h3 style="margin:0; font-size:18px; font-weight:700;"><i class="fas fa-trash-can" style="color:var(--danger)"></i> Confirmer le rebut</h3>
            <button onclick="fermerModalRetrait()" style="background:none; border:none; font-size:18px; cursor:pointer; color:var(--text-3);">✕</button>
        </div>
        <form method="POST" action="{{ route('admin.stock.rebut.retirer') }}">
            @csrf
            <input type="hidden" name="produit_id" id="rebut_produit_id">
            
            <p style="font-size:14px; color:var(--text-2); margin-bottom:16px;">
                Vous allez retirer le produit <strong id="rebut_nom_produit" style="color:var(--text-1);"></strong> du stock actif pour le jeter/perte.
            </p>

            <div class="form-group" style="margin-bottom:20px;">
                <label class="form-label" style="font-weight:600; margin-bottom:6px; display:block;">Quantité à retirer (<span id="rebut_max_quantite"></span> max)</label>
                <input type="number" name="quantite" id="rebut_quantite" class="form-control" min="0.001" step="0.001" required style="width:100%; padding:10px; border-radius:8px; border:1px solid var(--border); background:var(--bg2); color:var(--text-1);">
            </div>

            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" class="btn btn-outline" onclick="fermerModalRetrait()">Annuler</button>
                <button type="submit" class="btn btn-primary" style="background:var(--danger); border-color:var(--danger);">Déclarer perte</button>
            </div>
        </form>
    </div>
</div>

<script>
function ouvrirModalRetrait(id, nom, maxQty) {
    document.getElementById('rebut_produit_id').value = id;
    document.getElementById('rebut_nom_produit').innerText = nom;
    document.getElementById('rebut_max_quantite').innerText = maxQty;
    document.getElementById('rebut_quantite').max = maxQty;
    document.getElementById('rebut_quantite').value = maxQty;
    
    document.getElementById('modalRetraitRebut').style.display = 'flex';
}

function fermerModalRetrait() {
    document.getElementById('modalRetraitRebut').style.display = 'none';
}
</script>
@endsection

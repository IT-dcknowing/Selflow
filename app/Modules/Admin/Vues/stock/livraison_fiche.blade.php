@extends('admin::gabarits.application')
@section('titre', 'Vérification Livraison — ' . $vente->numero_facture)
@section('topbar_titre', 'Stock — Livraison')

@section('contenu')
<div style="display:flex; align-items:center; gap:12px; margin-bottom:20px;">
    <a href="{{ route('admin.stock.livraisons') }}" class="btn btn-outline btn-sm">
        <i class="fas fa-arrow-left"></i> Retour aux livraisons
    </a>
    <span style="color:var(--text-3); font-size:13px;">
        Expédition / Livraison de la commande <strong>{{ $vente->numero_facture }}</strong> pour <strong>{{ $vente->client?->nom ?? 'Client de passage' }}</strong>
    </span>
</div>

<div class="card" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:20px; padding:10px 14px;">
        <div>
            <div style="font-size:11px; color:var(--text-3); text-transform:uppercase; letter-spacing:.5px;">Client</div>
            {{-- Une vente au comptoir n'a pas de fiche client : `client_id` est
                 nul, et la lecture directe repondait 500. --}}
            <strong style="font-size:16px; color:var(--text-1);">{{ $vente->client?->nom ?? 'Client de passage' }}</strong>
        </div>
        <div>
            <div style="font-size:11px; color:var(--text-3); text-transform:uppercase; letter-spacing:.5px;">Entrepôt de départ</div>
            <strong style="font-size:16px; color:var(--text-1);"><i class="fas fa-store"></i> {{ $vente->pointDeVente->nom }}</strong>
        </div>
        <div>
            <div style="font-size:11px; color:var(--text-3); text-transform:uppercase; letter-spacing:.5px;">Date vente</div>
            <strong style="font-size:16px; color:var(--text-1);">{{ $vente->date_vente->format('d/m/Y') }}</strong>
        </div>
        <div>
            <div style="font-size:11px; color:var(--text-3); text-transform:uppercase; letter-spacing:.5px;">Statut</div>
            <span class="badge badge-info">{{ $vente->etape }}</span>
        </div>
    </div>
</div>

@php
    // Rien à expédier : toutes les lignes sont des services, ou tout est déjà
    // sorti. Le dire ici plutôt que de laisser cliquer sur un formulaire vide.
    $rienALivrer = $vente->details->every(
        fn ($d) => !$d->produit || !$d->produit->estStockable() || $d->quantite_livree >= $d->quantite
    );
@endphp
@if($rienALivrer)
<div class="card" style="padding:18px; margin-bottom:16px; background:#f8fafc; border:1px solid var(--border);">
    <div style="font-size:13px; color:var(--text-2); line-height:1.6;">
        <i class="fas fa-circle-info" style="color:var(--primary);"></i>
        <strong>Il n'y a rien à expédier sur cette pièce.</strong>
        Soit tout est déjà sorti, soit elle ne porte que des prestations de
        service — et une prestation ne sort pas d'un stock.
    </div>
</div>
@endif

<form method="POST" action="{{ route('admin.stock.livraisons.valider', $vente) }}">
    @csrf
    
    <div class="card" style="padding:20px;">
        <div class="card-header" style="border-bottom:1px solid var(--border); padding-bottom:12px; margin-bottom:14px;">
            <h2 style="font-size:16px; font-weight:700;"><i class="fas fa-dolly"></i> Articles vendus & expédition physique</h2>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Référence</th>
                        <th>Désignation article</th>
                        <th style="text-align:center;">Quantité commandée</th>
                        <th style="text-align:center;">Déjà livrée</th>
                        <th style="text-align:center;">Stock disponible</th>
                        <th style="text-align:center; width:180px;">Saisir livraison actuelle</th>
                        <th>Unité</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($vente->details as $d)
                        @php
                            $produit = $d->produit;
                            $restant = $d->quantite - $d->quantite_livree;
                            $stockObj = $produit ? $produit->stocks->where('point_de_vente_id', $vente->point_de_vente_id)->first() : null;
                            $dispo = $stockObj ? $stockObj->quantite_disponible : 0;
                            $maxPossible = min($restant, $dispo);
                        @endphp
                        <tr>
                            <td style="font-family:monospace; font-size:12px; color:var(--text-3);">
                                {{ $produit ? $produit->reference : 'Virtuel' }}
                            </td>
                            <td>
                                <strong style="font-size:14px;">{{ $produit ? $produit->nom : $d->libelle_virtuel }}</strong>
                                @if($produit && !$produit->estStockable())
                                    <span style="display:block; font-size:11px; color:var(--text-3); font-style:italic;">
                                        <i class="fas fa-info-circle"></i> Service / Consommable non-stockable (pas de suivi d'inventaire)
                                    </span>
                                @endif
                            </td>
                            <td style="text-align:center; font-weight:700;">{{ $d->quantite }}</td>
                            <td style="text-align:center; font-weight:600; color:var(--success);">{{ $d->quantite_livree }}</td>
                            <td style="text-align:center;">
                                @if($produit && $produit->estStockable())
                                    <span class="badge {{ $dispo == 0 ? 'badge-danger' : ($dispo <= $stockObj->stock_minimum ? 'badge-warning' : 'badge-success') }}">
                                        {{ $dispo }}
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
                            <td style="text-align:center;">
                                @if($produit && $produit->estStockable() && $restant > 0)
                                    @if($dispo > 0)
                                        <input type="number" name="livraison[{{ $d->id }}]" class="form-control" 
                                               min="0" max="{{ $maxPossible }}" value="{{ $maxPossible }}" 
                                               style="width:100px; text-align:center; display:inline-block; font-weight:700; border-color:var(--primary);">
                                    @else
                                        <span class="badge badge-danger" style="font-size:11px;"><i class="fas fa-triangle-exclamation"></i> Rupture</span>
                                    @endif
                                @elseif($restant <= 0)
                                    <span class="badge badge-success" style="font-size:11px;"><i class="fas fa-check-circle"></i> Livré</span>
                                @else
                                    <span style="color:var(--text-3); font-style:italic; font-size:12px;">Non applicable</span>
                                @endif
                            </td>
                            <td style="color:var(--text-2);">{{ $d->unite }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:20px; border-top:1px solid var(--border); padding-top:16px;">
            <a href="{{ route('admin.stock.livraisons') }}" class="btn btn-outline">Annuler</a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-check"></i> Enregistrer et valider l'expédition
            </button>
        </div>
    </div>
</form>
@endsection

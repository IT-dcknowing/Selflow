@extends('admin::gabarits.application')
@section('titre', 'Vérification Réception — ' . $achat->numero_facture)
@section('topbar_titre', 'Stock — Réception')

@section('contenu')
<div style="display:flex; align-items:center; gap:12px; margin-bottom:20px;">
    <a href="{{ route('admin.stock.receptions') }}" class="btn btn-outline btn-sm">
        <i class="fas fa-arrow-left"></i> Retour aux réceptions
    </a>
    <span style="color:var(--text-3); font-size:13px;">
        Réception de la commande <strong>{{ $achat->numero_facture }}</strong> de <strong>{{ $achat->fournisseur->nom }}</strong>
    </span>
</div>

<div class="card" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:20px; padding:10px 14px;">
        <div>
            <div style="font-size:11px; color:var(--text-3); text-transform:uppercase; letter-spacing:.5px;">Fournisseur</div>
            <strong style="font-size:16px; color:var(--text-1);">{{ $achat->fournisseur->nom }}</strong>
        </div>
        <div>
            <div style="font-size:11px; color:var(--text-3); text-transform:uppercase; letter-spacing:.5px;">Entrepôt de stockage</div>
            <strong style="font-size:16px; color:var(--text-1);"><i class="fas fa-store"></i> {{ $achat->pointDeVente->nom }}</strong>
        </div>
        <div>
            <div style="font-size:11px; color:var(--text-3); text-transform:uppercase; letter-spacing:.5px;">Date achat</div>
            <strong style="font-size:16px; color:var(--text-1);">{{ $achat->date_achat->format('d/m/Y') }}</strong>
        </div>
        <div>
            <div style="font-size:11px; color:var(--text-3); text-transform:uppercase; letter-spacing:.5px;">Statut</div>
            <span class="badge badge-info">{{ $achat->etape }}</span>
        </div>
    </div>
</div>

@php
    // Rien à recevoir : toutes les lignes sont des services, ou tout a déjà
    // été reçu. Le dire ici plutôt que de laisser cliquer sur un formulaire
    // vide, qui répondait « le champ reception est obligatoire ».
    $riensARecevoir = $achat->details->every(
        fn ($d) => !$d->produit || !$d->produit->estStockable() || $d->quantite_receptionnee >= $d->quantite
    );
@endphp
@if($riensARecevoir)
<div class="card" style="padding:18px; margin-bottom:16px; background:#f8fafc; border:1px solid var(--border);">
    <div style="font-size:13px; color:var(--text-2); line-height:1.6;">
        <i class="fas fa-circle-info" style="color:var(--primary);"></i>
        <strong>Il n'y a rien à réceptionner sur cette commande.</strong>
        Soit tout a déjà été reçu, soit elle ne porte que des prestations de
        service — et une prestation n'entre pas en stock.
    </div>
</div>
@endif

<form method="POST" action="{{ route('admin.stock.receptions.valider', $achat) }}">
    @csrf
    
    <div class="card" style="padding:20px;">
        <div class="card-header" style="border-bottom:1px solid var(--border); padding-bottom:12px; margin-bottom:14px;">
            <h2 style="font-size:16px; font-weight:700;"><i class="fas fa-clipboard-check"></i> Articles commandés & vérification physique</h2>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Référence</th>
                        <th>Désignation article</th>
                        <th style="text-align:center;">Quantité commandée</th>
                        <th style="text-align:center;">Déjà réceptionnée</th>
                        <th style="text-align:center; width:180px;">Saisir réception actuelle</th>
                        <th>Unité</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($achat->details as $d)
                        @php
                            $produit = $d->produit;
                            $restant = $d->quantite - $d->quantite_receptionnee;
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
                            <td style="text-align:center; font-weight:600; color:var(--success);">{{ $d->quantite_receptionnee }}</td>
                            <td style="text-align:center;">
                                @if($produit && $produit->estStockable() && $restant > 0)
                                    <input type="number" name="reception[{{ $d->id }}]" class="form-control" 
                                           min="0" max="{{ $restant }}" value="{{ $restant }}" 
                                           style="width:100px; text-align:center; display:inline-block; font-weight:700; border-color:var(--primary);">
                                @elseif($restant <= 0)
                                    <span class="badge badge-success" style="font-size:11px;"><i class="fas fa-check-circle"></i> Terminé</span>
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
            <a href="{{ route('admin.stock.receptions') }}" class="btn btn-outline">Annuler</a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-check"></i> Enregistrer la réception en stock
            </button>
        </div>
    </div>
</form>
@endsection

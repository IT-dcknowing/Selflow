@extends('admin::gabarits.application')
@section('titre', 'Brouillons de vente')
@section('topbar_titre', 'Ventes · Brouillons')

@section('contenu')
@php $__prefixe = request()->routeIs('caissier.*') ? 'caissier' : 'admin'; @endphp
<div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
    <h1 style="font-size:20px; margin:0;"><i class="fas fa-file-pen" style="color:#b45309;"></i> Brouillons de vente</h1>
    <div style="display:flex; gap:6px;">
        <a href="{{ route($__prefixe . '.ventes.factures') }}" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Factures vente</a>
        <a href="{{ route($__prefixe . '.ventes.nouvelle') }}" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Nouvelle vente</a>
    </div>
</div>
<p style="font-size:12.5px; color:var(--text-3); margin:-4px 0 12px;">Des ventes préparées, pas encore facturées : aucun stock n'a bougé, aucune écriture n'est passée, rien n'est parti à la DGI. « Passer en facture » rouvre la vente pour la vérifier et la valider.</p>
@if(session('succes'))<div class="alert alert-success" style="margin-bottom:12px;"><i class="fas fa-circle-check"></i> {{ session('succes') }}</div>@endif

<div class="card" style="padding:0; overflow-x:auto;">
    <table class="table" style="width:100%;">
        <thead><tr><th>Enregistré le</th><th>Client</th><th>Point de vente</th><th style="text-align:right;">Articles</th><th style="text-align:right;">Montant estimé</th><th>Par</th><th></th></tr></thead>
        <tbody>
        @forelse($brouillons as $b)
            <tr>
                <td style="white-space:nowrap;">{{ $b->updated_at->format('d/m/Y H:i') }}</td>
                <td><strong>{{ $b->libelle }}</strong></td>
                <td>{{ $b->pointDeVente?->nom }}</td>
                <td style="text-align:right;">{{ $b->lignes }}</td>
                <td style="text-align:right; font-weight:700;">{{ number_format($b->total, 0, ',', ' ') }} F</td>
                <td>{{ trim(($b->utilisateur?->prenom ?? '') . ' ' . ($b->utilisateur?->nom ?? '')) }}</td>
                <td style="text-align:right; white-space:nowrap;">
                    <a href="{{ route($__prefixe . '.ventes.nouvelle', ['brouillon' => $b->id]) }}" class="btn btn-primary btn-sm"><i class="fas fa-file-invoice"></i> Passer en facture</a>
                    <form method="POST" action="{{ route($__prefixe . '.ventes.brouillons.supprimer', $b) }}" style="display:inline;" onsubmit="return confirm('Supprimer ce brouillon ?');">
                        @csrf @method('DELETE')
                        <button class="btn btn-outline btn-sm" style="color:var(--danger);"><i class="fas fa-trash"></i></button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" style="text-align:center; color:var(--text-3); padding:24px;">Aucun brouillon. Dans l'écran de vente, « Enregistrer en brouillon » met une vente de côté sans la facturer.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection

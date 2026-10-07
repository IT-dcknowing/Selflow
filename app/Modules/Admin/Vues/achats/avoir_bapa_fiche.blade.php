@extends('admin::gabarits.application')
@section('titre', 'Avoir interne ' . $avoir->numero)
@section('topbar_titre', 'Achats — Avoir interne de BAPA')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-rotate-left"></i> Avoir interne {{ $avoir->numero }}</h1>
        <p>Sur le bordereau {{ $avoir->bapa->numero_facture }} · {{ $avoir->date_avoir->format('d/m/Y') }}</p>
    </div>
    <button type="button" class="btn btn-outline" onclick="window.print()"><i class="fas fa-print"></i> Imprimer</button>
</div>

@if(session('succes'))
    <div style="margin-bottom:16px;padding:12px 16px;background:#ecfdf5;border:1px solid #6ee7b7;border-radius:10px;color:#065f46;font-size:13px;">{{ session('succes') }}</div>
@endif

<div class="card" style="padding:22px;">
    {{-- Le document le dit lui-même : il ne doit jamais passer pour une pièce
         certifiée. --}}
    <div style="padding:10px 14px;border:1.5px solid #b45309;border-radius:8px;color:#92400e;font-weight:700;font-size:13px;margin-bottom:16px;text-align:center;">
        AVOIR INTERNE — NON CERTIFIÉ PAR LA DGI · ne vaut pas facture normalisée
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px;margin-bottom:14px;">
        <div><span style="color:var(--text-3)">Vendeur : </span><strong>{{ $avoir->bapa->fournisseur?->nom ?? '—' }}</strong></div>
        <div><span style="color:var(--text-3)">Site : </span><strong>{{ $avoir->pointDeVente?->nom }}</strong></div>
        <div style="grid-column:1/-1"><span style="color:var(--text-3)">Motif : </span><strong>{{ $avoir->motif }}</strong></div>
    </div>
    <table class="table" style="width:100%;">
        <thead><tr><th>Article</th><th>Quantité rendue</th><th>Prix unitaire</th><th>Montant</th><th>Retour stock</th></tr></thead>
        <tbody>
        @foreach($avoir->lignes as $l)
            <tr>
                <td>{{ $l->libelle }}</td>
                <td>{{ rtrim(rtrim(number_format($l->quantite, 3, ',', ' '), '0'), ',') }}</td>
                <td>{{ number_format($l->prix_unitaire, 0, ',', ' ') }} F</td>
                <td>{{ number_format($l->montant, 0, ',', ' ') }} F</td>
                <td>{{ $l->retour_stock ? 'Oui' : 'Non' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div style="text-align:right;font-size:15px;font-weight:800;margin-top:10px;">Total : {{ number_format($avoir->montant_ttc, 0, ',', ' ') }} F</div>
</div>
@endsection

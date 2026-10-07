@extends('admin::gabarits.application')
@section('titre', 'Avoir interne — ' . $bapa->numero_facture)
@section('topbar_titre', 'Achats — Avoir interne de BAPA')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-rotate-left"></i> Avoir interne sur {{ $bapa->numero_facture }}</h1>
        <p>{{ $bapa->fournisseur?->nom ?? 'Vendeur non immatriculé' }} · {{ number_format($bapa->montant_ttc, 0, ',', ' ') }} F</p>
    </div>
</div>

{{-- Ce que l'utilisateur doit savoir avant de le faire : la DGI ne normalise
     pas l'avoir d'un BAPA (confirmé par le propriétaire le 07/10/2026). --}}
<div style="margin-bottom:18px;padding:14px 18px;background:#fffbeb;border:1px solid #fcd34d;border-radius:10px;color:#92400e;font-size:13px;line-height:1.6;">
    <strong><i class="fas fa-triangle-exclamation"></i> Document interne, non certifié.</strong>
    La DGI ne normalise pas l'avoir d'un bordereau d'achat : cet avoir n'est pas transmis à la plateforme et ne porte
    ni numéro fiscal ni code QR. Il corrige votre comptabilité et votre stock, rien de plus.
</div>

@if($errors->any())
    <div style="margin-bottom:18px;padding:12px 16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;color:#991b1b;font-size:13px;">
        @foreach($errors->all() as $erreur)<div>{{ $erreur }}</div>@endforeach
    </div>
@endif

@if($dejaAvoire > 0.01)
    <div style="margin-bottom:14px;font-size:13px;color:var(--text-2);">
        Déjà avoiré : <strong>{{ number_format($dejaAvoire, 0, ',', ' ') }} F</strong> —
        reste <strong>{{ number_format($reste, 0, ',', ' ') }} F</strong> sur {{ number_format($bapa->montant_ttc, 0, ',', ' ') }} F.
    </div>
@endif

<form method="POST" action="{{ route('admin.achats.avoir_bapa.enregistrer', $bapa) }}" class="card" style="padding:18px;">
    @csrf
    <table class="table" style="width:100%;">
        <thead>
            <tr><th>Article</th><th>Acheté</th><th>Déjà rendu</th><th>Prix unitaire</th><th>À rendre</th><th>Repart du stock</th></tr>
        </thead>
        <tbody>
        @foreach($bapa->details as $d)
            @php $rendu = $dejaRendu[$d->id] ?? 0; $resteLigne = max(0, $d->quantite - $rendu); @endphp
            <tr>
                <td style="font-weight:600;">{{ $d->produit?->nom ?? $d->libelle_virtuel }}</td>
                <td>{{ rtrim(rtrim(number_format($d->quantite, 3, ',', ' '), '0'), ',') }} {{ $d->unite }}</td>
                <td>{{ rtrim(rtrim(number_format($rendu, 3, ',', ' '), '0'), ',') }}</td>
                <td>{{ number_format($d->prix_unitaire * (1 - ($d->remise_taux ?? 0) / 100), 0, ',', ' ') }} F</td>
                <td><input type="number" name="lignes[{{ $d->id }}][quantite]" class="form-control" min="0" max="{{ $resteLigne }}" step="0.001" value="0" {{ $resteLigne <= 0 ? 'disabled' : '' }} style="max-width:120px;"></td>
                <td>
                    @if($d->produit?->estStockable())
                        <input type="hidden" name="lignes[{{ $d->id }}][retour_stock]" value="0">
                        <input type="checkbox" name="lignes[{{ $d->id }}][retour_stock]" value="1" checked>
                    @else — @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="form-group" style="margin-top:12px;">
        <label class="form-label">Motif <span style="color:var(--danger)">*</span></label>
        <input type="text" name="motif" class="form-control" required maxlength="255" value="{{ old('motif') }}" placeholder="Ex : marchandise refusée à la pesée">
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px;">
        <a href="{{ route('admin.achats.bapa', $bapa) }}" class="btn btn-outline">Retour au bordereau</a>
        <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Établir l'avoir interne</button>
    </div>
</form>

@if($avoirs->isNotEmpty())
    <div class="card" style="padding:16px;margin-top:16px;">
        <h3 style="font-size:14px;margin:0 0 8px;">Avoirs déjà établis</h3>
        @foreach($avoirs as $a)
            <div style="font-size:13px;"><a href="{{ route('admin.achats.avoir_bapa.voir', $a) }}">{{ $a->numero }}</a>
                — {{ $a->date_avoir->format('d/m/Y') }} — {{ number_format($a->montant_ttc, 0, ',', ' ') }} F</div>
        @endforeach
    </div>
@endif
@endsection

@extends('admin::gabarits.application')
@section('titre', 'Proposition entrée/sortie')
@section('topbar_titre', 'Stock · Proposition entrée/sortie')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-right-left"></i> Proposition entrée/sortie</h1>
        <p>Les mouvements de stock qu'appellent les factures reprises du portail FNE. Rien ne bouge le stock tant que vous n'avez pas validé la ligne.</p>
    </div>
    <a href="{{ route('admin.stock.mouvements') }}" class="btn btn-outline"><i class="fas fa-arrows-up-down"></i> Mouvements</a>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr><th>Date</th><th>Pièce</th><th>Point de vente</th><th>Article</th><th>Sens</th><th style="text-align:right;">Quantité</th><th></th></tr>
        </thead>
        <tbody>
        @forelse($enAttente as $prop)
            <tr>
                <td>{{ $prop->vente?->date_vente?->format('d/m/Y') }}</td>
                <td>{{ $prop->vente?->numero_facture ?? $prop->reference }}<br><small style="color:var(--text-3);">{{ $prop->vente?->numero_fne }}</small></td>
                <td>{{ $prop->pointDeVente?->nom }}</td>
                <td>{{ $prop->produit?->nom }}</td>
                <td>
                    @if($prop->sens === 'sortie')
                        <span style="color:#b91c1c; font-weight:700;"><i class="fas fa-arrow-up"></i> Sortie</span>
                    @else
                        <span style="color:#047857; font-weight:700;"><i class="fas fa-arrow-down"></i> Entrée</span>
                    @endif
                </td>
                <td style="text-align:right;">{{ rtrim(rtrim(number_format($prop->quantite, 3, ',', ' '), '0'), ',') }} {{ $prop->produit?->unite }}</td>
                <td style="white-space:nowrap; text-align:right;">
                    <form method="POST" action="{{ route('admin.stock.propositions.valider', $prop) }}" style="display:inline;">
                        @csrf
                        <button class="btn btn-primary btn-sm"><i class="fas fa-check"></i> Valider</button>
                    </form>
                    <form method="POST" action="{{ route('admin.stock.propositions.refuser', $prop) }}" style="display:inline;"
                          onsubmit="return confirm('Refuser ce mouvement ? Le stock ne bougera pas.');">
                        @csrf
                        <button class="btn btn-outline btn-sm"><i class="fas fa-xmark"></i> Refuser</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" style="text-align:center; color:var(--text-3); padding:20px;">Aucune proposition en attente.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if($traitees->isNotEmpty())
    <h3 style="margin:24px 0 8px; font-size:14px;">Dernières propositions traitées</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Traitée le</th><th>Pièce</th><th>Article</th><th>Sens</th><th style="text-align:right;">Quantité</th><th>Décision</th></tr></thead>
            <tbody>
            @foreach($traitees as $prop)
                <tr>
                    <td>{{ $prop->traite_le?->format('d/m/Y H:i') }}</td>
                    <td>{{ $prop->vente?->numero_facture ?? $prop->reference }}</td>
                    <td>{{ $prop->produit?->nom }}</td>
                    <td>{{ $prop->sens === 'sortie' ? 'Sortie' : 'Entrée' }}</td>
                    <td style="text-align:right;">{{ rtrim(rtrim(number_format($prop->quantite, 3, ',', ' '), '0'), ',') }}</td>
                    <td>{{ $prop->statut === 'validee' ? 'Validée' : 'Refusée' }}@if($prop->motif) — {{ $prop->motif }}@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection

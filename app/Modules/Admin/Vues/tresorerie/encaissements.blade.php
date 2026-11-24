@extends('admin::gabarits.application')
@section('titre', 'Encaissements')
@section('topbar_titre', 'Trésorerie · Encaissements')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-arrow-down" style="color:var(--success)"></i> Encaissements</h1>
        <p>Toutes les entrées d'argent</p>
    </div>
    <a href="{{ route('admin.tresorerie.journal') }}" class="btn btn-outline">
        <i class="fas fa-book"></i> Journal complet
    </a>
</div>
<div class="card">
    <div class="table-wrap">
        @if($operations->isEmpty())
        <div style="padding:48px; text-align:center; color:var(--text-3);">
            <i class="fas fa-arrow-down" style="font-size:48px; display:block; margin-bottom:12px; opacity:.2;"></i>
            Aucun encaissement.
        </div>
        @else
        <table>
            <thead><tr><th>Date</th><th>Libellé</th><th>Point de vente</th><th>Mode</th><th>Montant</th><th>Référence</th></tr></thead>
            <tbody>
                @foreach($operations as $op)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($op->date_operation)->format('d/m/Y') }}</td>
                    <td>{{ $op->libelle }}</td>
                    <td>{{ $op->pointDeVente->nom }}</td>
                    <td>{{ $op->mode_paiement }}</td>
                    <td style="font-weight:700; color:var(--success);">{{ number_format($op->montant_entree, 0, ',', ' ') }} F</td>
                    <td style="font-family:monospace; font-size:11px; color:var(--primary);">{{ $op->reference_document }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <div style="padding: 0 16px;">{{ $operations->links() }}</div>
        @endif
    </div>
</div>
@endsection

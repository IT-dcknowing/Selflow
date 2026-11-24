@extends('admin::gabarits.application')
@section('titre', 'Livraisons clients')
@section('topbar_titre', 'Stock · Livraisons')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-dolly"></i> Livraisons clients à effectuer</h1>
        <p>Gérez les sorties de stock pour l'expédition vers vos clients.</p>
    </div>
    <a href="{{ route('admin.stock.index') }}" class="btn btn-outline">
        <i class="fas fa-boxes-stacked"></i> Retour à l'inventaire
    </a>
</div>

{{-- Flash messages --}}
@if(session('succes'))
    <div class="alert alert-success" style="margin-bottom:16px; padding:12px 16px; background:#ecfdf5; border:1px solid #6ee7b7; border-radius:10px; color:#065f46; display:flex; align-items:center; gap:10px;">
        <i class="fas fa-check-circle"></i> {{ session('succes') }}
    </div>
@endif

<div class="card">
    <div class="table-wrap">
        @if($ventes->isEmpty())
            <div style="padding:48px; text-align:center; color:var(--text-3);">
                <i class="fas fa-dolly" style="font-size:48px; display:block; margin-bottom:12px; opacity:.2;"></i>
                Aucune livraison client à effectuer en attente.
            </div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Date vente</th>
                        <th>Numéro doc</th>
                        <th>Client</th>
                        <th>Point de départ</th>
                        <th>Statut compta</th>
                        <th>Étape</th>
                        <th>Articles à livrer</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($ventes as $v)
                        @php
                            $totalCmd = $v->details->sum('quantite');
                            $totalLivre = $v->details->sum('quantite_livree');
                            $restant = $totalCmd - $totalLivre;
                        @endphp
                        <tr>
                            <td style="color:var(--text-3); font-size:12px;">{{ $v->date_vente->format('d/m/Y') }}</td>
                            <td style="font-family:monospace; font-weight:600; color:var(--primary);">{{ $v->numero_facture }}</td>
                            <td style="font-weight:600;">{{ $v->client ? $v->client->nom : 'Client divers' }}</td>
                            <td><span class="badge badge-purple"><i class="fas fa-store"></i> {{ $v->pointDeVente->nom }}</span></td>
                            <td>
                                @if($v->statut === 'Payé')
                                    <span class="badge badge-success">Payé</span>
                                @elseif($v->statut === 'Crédit')
                                    <span class="badge badge-danger">Crédit</span>
                                @else
                                    <span class="badge badge-warning">{{ $v->statut }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-info" style="font-weight:700;">{{ $v->etape }}</span>
                            </td>
                            <td>
                                <div style="font-weight:600;">
                                    {{ $totalLivre }} / {{ $totalCmd }}
                                </div>
                                <div style="font-size:11px; color:var(--text-3);">
                                    {{ $restant }} restant(s)
                                </div>
                            </td>
                            <td>
                                <a href="{{ route('admin.stock.livraisons.fiche', $v) }}" class="btn btn-primary btn-sm">
                                    <i class="fas fa-dolly"></i> Expédier / Livrer
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="padding:14px 20px;">
                {{ $ventes->links() }}
            </div>
        @endif
    </div>
</div>
@endsection

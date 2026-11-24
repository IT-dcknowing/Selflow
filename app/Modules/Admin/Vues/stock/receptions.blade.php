@extends('admin::gabarits.application')
@section('titre', 'Réceptions fournisseurs')
@section('topbar_titre', 'Stock · Réceptions')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-truck-loading"></i> Réceptions fournisseurs à traiter</h1>
        <p>Gérez les entrées de stock en provenance de vos fournisseurs.</p>
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
        @if($achats->isEmpty())
            <div style="padding:48px; text-align:center; color:var(--text-3);">
                <i class="fas fa-truck-loading" style="font-size:48px; display:block; margin-bottom:12px; opacity:.2;"></i>
                Aucune réception fournisseur en attente de traitement.
            </div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Date commande</th>
                        <th>Numéro doc</th>
                        <th>Fournisseur</th>
                        <th>Point de stockage</th>
                        <th>Statut compta</th>
                        <th>Étape</th>
                        <th>Articles à recevoir</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($achats as $a)
                        @php
                            $totalCmd = $a->details->sum('quantite');
                            $totalRecu = $a->details->sum('quantite_receptionnee');
                            $restant = $totalCmd - $totalRecu;
                        @endphp
                        <tr>
                            <td style="color:var(--text-3); font-size:12px;">{{ $a->date_achat->format('d/m/Y') }}</td>
                            <td style="font-family:monospace; font-weight:600; color:var(--primary);">{{ $a->numero_facture }}</td>
                            <td style="font-weight:600;">{{ $a->fournisseur ? $a->fournisseur->nom : 'Fournisseur divers' }}</td>
                            <td><span class="badge badge-purple"><i class="fas fa-store"></i> {{ $a->pointDeVente->nom }}</span></td>
                            <td>
                                @if($a->statut === 'Payé')
                                    <span class="badge badge-success">Payé</span>
                                @elseif($a->statut === 'Crédit')
                                    <span class="badge badge-danger">Crédit</span>
                                @else
                                    <span class="badge badge-warning">{{ $a->statut }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-info" style="font-weight:700;">{{ $a->etape }}</span>
                            </td>
                            <td>
                                <div style="font-weight:600;">
                                    {{ $totalRecu }} / {{ $totalCmd }}
                                </div>
                                <div style="font-size:11px; color:var(--text-3);">
                                    {{ $restant }} restant(s)
                                </div>
                            </td>
                            <td>
                                <a href="{{ route('admin.stock.receptions.fiche', $a) }}" class="btn btn-primary btn-sm">
                                    <i class="fas fa-clipboard-check"></i> Réceptionner
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="padding:14px 20px;">
                {{ $achats->links() }}
            </div>
        @endif
    </div>
</div>
@endsection

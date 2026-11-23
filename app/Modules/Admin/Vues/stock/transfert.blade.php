@extends('admin::gabarits.application')
@section('titre', 'Transferts de stock')
@section('topbar_titre', 'Stock — Transferts')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-exchange-alt"></i> Transferts de stock internes</h1>
        <p>Gérez les mouvements de stock entre vos différents points de vente.</p>
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
@if(session('erreur'))
    <div class="alert alert-error" style="margin-bottom:16px; padding:12px 16px; background:#fef2f2; border:1px solid #fca5a5; border-radius:10px; color:#991b1b; display:flex; align-items:center; gap:10px;">
        <i class="fas fa-exclamation-circle"></i> {{ session('erreur') }}
    </div>
@endif
@if($errors->any())
    <div class="alert alert-error" style="margin-bottom:16px; padding:12px 16px; background:#fef2f2; border:1px solid #fca5a5; border-radius:10px; color:#991b1b;">
        <ul style="margin:0; padding-left:18px;">
            @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
        </ul>
    </div>
@endif

<div style="display:grid; grid-template-columns:320px 1fr; gap:20px;">

    {{-- Formulaire de demande --}}
    <div class="card" style="align-self:flex-start; padding:20px;">
        <h3 style="font-size:15px; font-weight:700; color:var(--text-3); text-transform:uppercase; letter-spacing:.5px; margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:8px;">
            <i class="fas fa-plus"></i> Nouveau transfert
        </h3>
        <form method="POST" action="{{ route('admin.stock.transferts.creer') }}">
            @csrf
            
            <div class="form-group" style="margin-bottom:12px;">
                <label class="form-label">Produit <span style="color:var(--danger)">*</span></label>
                <select name="produit_id" class="form-control" required>
                    <option value="">-- Sélectionner --</option>
                    @foreach($produits as $p)
                        <option value="{{ $p->id }}">{{ $p->nom }} ({{ $p->reference }})</option>
                    @endforeach
                </select>
            </div>

            <div class="form-group" style="margin-bottom:12px;">
                <label class="form-label">Entrepôt Source <span style="color:var(--danger)">*</span></label>
                <select name="point_de_vente_source_id" class="form-control" required>
                    <option value="">-- Sélectionner --</option>
                    @foreach($pointsDeVente->when($siteSource ?? null, fn ($liste, $site) => $liste->where('id', $site)) as $pdv)
                        <option value="{{ $pdv->id }}" {{ session('point_de_vente_actif_id') == $pdv->id ? 'selected' : '' }}>
                            {{ $pdv->nom }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="form-group" style="margin-bottom:12px;">
                <label class="form-label">Entrepôt Destination <span style="color:var(--danger)">*</span></label>
                <select name="point_de_vente_destination_id" class="form-control" required>
                    <option value="">-- Sélectionner --</option>
                    @foreach($pointsDeVente as $pdv)
                        <option value="{{ $pdv->id }}">
                            {{ $pdv->nom }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="form-group" style="margin-bottom:12px;">
                <label class="form-label">Quantité <span style="color:var(--danger)">*</span></label>
                <input type="number" name="quantite" class="form-control" min="0.001" step="0.001" placeholder="10" required>
            </div>

            <div class="form-group" style="margin-bottom:18px;">
                <label class="form-label">Note / Motif</label>
                <textarea name="note" class="form-control" rows="2" placeholder="Raison du transfert..."></textarea>
            </div>

            @if(Auth::user()->role === 'admin')
                <button type="submit" class="btn btn-primary" style="width:100%;">
                    <i class="fas fa-check"></i> Transférer directement
                </button>
            @else
                <button type="submit" class="btn btn-primary" style="width:100%;">
                    <i class="fas fa-paper-plane"></i> Demander le transfert
                </button>
            @endif
        </form>
    </div>

    {{-- Historique des transferts --}}
    <div class="card" style="padding:20px;">
        <h3 style="font-size:15px; font-weight:700; color:var(--text-3); text-transform:uppercase; letter-spacing:.5px; margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:8px;">
            <i class="fas fa-history"></i> Historique des transferts
        </h3>
        <div class="table-wrap">
            @if($transferts->isEmpty())
                <div style="text-align:center; padding:40px; color:var(--text-3); font-style:italic;">
                    Aucun transfert enregistré pour le moment.
                </div>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Produit</th>
                            <th>Provenance</th>
                            <th>Destination</th>
                            <th>Qté</th>
                            <th>Demandeur</th>
                            <th>Statut</th>
                            @if(Auth::user()->role === 'admin')
                                <th>Action</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($transferts as $t)
                            @php
                                $badge = $t->badgeStatut();
                            @endphp
                            <tr>
                                <td style="color:var(--text-3); font-size:12px;">{{ $t->created_at->format('d/m/Y H:i') }}</td>
                                <td>
                                    <div style="font-weight:600;">{{ $t->produit->nom }}</div>
                                    <div style="font-size:11px; color:var(--text-3); font-family:monospace;">{{ $t->produit->reference }}</div>
                                </td>
                                <td><span class="badge badge-purple">{{ $t->source->nom ?? '—' }}</span></td>
                                <td><span class="badge badge-purple">{{ $t->destination->nom ?? '—' }}</span></td>
                                <td style="font-weight:700; color:var(--primary);">
                                    {{ $t->quantite }} <span style="font-weight:400; font-size:11px; color:var(--text-3);">{{ $t->produit->unite }}</span>
                                </td>
                                <td>
                                    <div style="font-size:13px; font-weight:500;">{{ $t->demandeur->nom ?? '—' }}</div>
                                    @if($t->note)
                                        <div style="font-size:11px; color:var(--text-3);" title="{{ $t->note }}">
                                            <i class="fas fa-comment-dots"></i> {{ Str::limit($t->note, 25) }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge" style="background:{{ $badge['bg'] }}; color:{{ $badge['color'] }}; font-weight:700; font-size:11px; padding:4px 10px; border-radius:20px;">
                                        {{ $badge['label'] }}
                                    </span>
                                </td>
                                @if(Auth::user()->role === 'admin')
                                    <td>
                                        @if($t->estEnAttente())
                                            <div style="display:flex; gap:6px;">
                                                <form method="POST" action="{{ route('admin.stock.transferts.valider', $t) }}" style="margin:0;">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-primary" style="padding:4px 10px; font-size:11px;" title="Approuver">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" action="{{ route('admin.stock.transferts.rejeter', $t) }}" style="margin:0;">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline" style="padding:4px 10px; font-size:11px; color:var(--danger); border-color:var(--danger);" title="Rejeter">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        @else
                                            <span style="font-size:11px; color:var(--text-3);">
                                                Traité par: {{ $t->approbateur->nom ?? 'Système' }}
                                            </span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div style="padding-top:14px;">
                    {{ $transferts->links() }}
                </div>
            @endif
        </div>
    </div>

</div>
@endsection

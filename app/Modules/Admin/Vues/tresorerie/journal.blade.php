@extends('admin::gabarits.application')
@section('titre', 'Journal de trésorerie')
@section('topbar_titre', 'Trésorerie — Journal')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-wallet"></i> Journal de trésorerie</h1>
        <p>Toutes les opérations financières</p>
    </div>
    <div style="display:flex;gap:10px;">
        <a href="{{ route('admin.tresorerie.encaissements') }}" class="btn btn-outline">
            <i class="fas fa-arrow-down" style="color:var(--success)"></i> Encaissements
        </a>
        <a href="{{ route('admin.tresorerie.decaissements') }}" class="btn btn-outline">
            <i class="fas fa-arrow-up" style="color:var(--danger)"></i> Décaissements
        </a>
    </div>
</div>

{{-- Résumé solde --}}
<div class="stats-grid" style="grid-template-columns: repeat(3,1fr); margin-bottom:22px;">
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-arrow-down"></i></div>
        <div>
            <div class="stat-value" style="color:var(--success)">{{ number_format($totalEntrees, 0, ',', ' ') }} F</div>
            <div class="stat-label">Total encaissements</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-arrow-up"></i></div>
        <div>
            <div class="stat-value" style="color:var(--danger)">{{ number_format($totalSorties, 0, ',', ' ') }} F</div>
            <div class="stat-label">Total décaissements</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-scale-balanced"></i></div>
        <div>
            <div class="stat-value" style="{{ $soldeFinal >= 0 ? 'color:var(--success)' : 'color:var(--danger)' }}">{{ number_format($soldeFinal, 0, ',', ' ') }} F</div>
            <div class="stat-label">Solde net</div>
        </div>
    </div>
</div>

<div class="card" style="margin-bottom:20px; padding:16px 20px; background:var(--bg2);">
    <form method="GET" action="{{ route('admin.tresorerie.journal') }}" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)) 120px 100px; gap:12px; align-items:end; margin:0;" id="formFiltreTresorerie">
        {{-- Point de Vente --}}
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:var(--text-3); margin-bottom:6px;"><i class="fas fa-store"></i> Point de vente</label>
            <select name="point_de_vente_id" class="form-control" style="padding:8px 12px; font-size:13px;" onchange="document.getElementById('formFiltreTresorerie').submit();" @if(Auth::user()->estCaissier()) disabled @endif>
                <option value="tous" {{ $pointDeVenteId === 'tous' ? 'selected' : '' }}>Tous les sites</option>
                @foreach($pointsDeVente as $pdv)
                    <option value="{{ $pdv->id }}" {{ $pointDeVenteId == $pdv->id ? 'selected' : '' }}>{{ $pdv->nom }}</option>
                @endforeach
            </select>
            @if(Auth::user()->estCaissier())
                <input type="hidden" name="point_de_vente_id" value="{{ $pointDeVenteId }}">
            @endif
        </div>

        {{-- Mode de Paiement --}}
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:var(--text-3); margin-bottom:6px;"><i class="fas fa-credit-card"></i> Moyen de Paiement</label>
            <select name="mode_paiement" class="form-control" style="padding:8px 12px; font-size:13px;" onchange="document.getElementById('formFiltreTresorerie').submit();">
                <option value="">Tous</option>
                @foreach($modesDisponibles as $m)
                    <option value="{{ $m }}" {{ request('mode_paiement') === $m ? 'selected' : '' }}>{{ $m }}</option>
                @endforeach
            </select>
        </div>

        {{-- Moyen Bancaire / Banque --}}
        <div class="form-group" style="margin-bottom:0;">
            <label class="form-label" style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:var(--text-3); margin-bottom:6px;"><i class="fas fa-bank"></i> Banque / Moyen bancaire</label>
            <select name="moyen_bancaire" class="form-control" style="padding:8px 12px; font-size:13px;" onchange="document.getElementById('formFiltreTresorerie').submit();">
                <option value="">Toutes</option>
                @foreach($moyensBancairesDisponibles as $mb)
                    <option value="{{ $mb }}" {{ request('moyen_bancaire') === $mb ? 'selected' : '' }}>{{ $mb }}</option>
                @endforeach
            </select>
        </div>

        <button type="submit" class="btn btn-primary" style="padding: 10px; font-weight: 600; justify-content: center; border-radius: 8px;" title="Les filtres s'appliquent déjà automatiquement — utile uniquement si JavaScript est désactivé">
            <i class="fas fa-filter"></i> Filtrer
        </button>
        <a href="{{ route('admin.tresorerie.journal') }}" class="btn btn-outline" style="padding: 10px; font-weight: 600; justify-content: center; border-radius: 8px;">
            Réinitialiser
        </a>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        @if($operations->isEmpty())
        <div style="padding:48px; text-align:center; color:var(--text-3);">
            <i class="fas fa-wallet" style="font-size:48px; display:block; margin-bottom:12px; opacity:.2;"></i>
            Aucune opération enregistrée.
        </div>
        @else
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Libellé</th>
                    <th>Point de vente</th>
                    <th>Mode</th>
                    <th>Entrée</th>
                    <th>Sortie</th>
                    <th>Solde cumulé</th>
                    <th>Référence</th>
                </tr>
            </thead>
            <tbody>
                @foreach($operations as $op)
                <tr>
                    <td style="font-size:12px; color:var(--text-3);">{{ \Carbon\Carbon::parse($op->date_operation)->format('d/m/Y') }}</td>
                    <td>
                        @if($op->type_operation === 'Encaissement')
                            <span class="badge badge-success"><i class="fas fa-arrow-down"></i> Encaissement</span>
                        @else
                            <span class="badge badge-danger"><i class="fas fa-arrow-up"></i> Décaissement</span>
                        @endif
                    </td>
                    <td style="font-weight:500;">{{ $op->libelle }}</td>
                    <td style="font-size:12px; color:var(--text-3);">{{ $op->pointDeVente->nom }}</td>
                    <td style="font-size:12px;">{{ $op->mode_paiement }}</td>
                    <td style="font-weight:700; color:var(--success);">
                        {{ $op->montant_entree > 0 ? number_format($op->montant_entree, 0, ',', ' ') . ' F' : '—' }}
                    </td>
                    <td style="font-weight:700; color:var(--danger);">
                        {{ $op->montant_sortie > 0 ? number_format($op->montant_sortie, 0, ',', ' ') . ' F' : '—' }}
                    </td>
                    <td style="font-weight:800; {{ $op->solde_resultat >= 0 ? 'color:var(--success)' : 'color:var(--danger)' }}">
                        {{ number_format($op->solde_resultat, 0, ',', ' ') }} F
                    </td>
                    <td style="font-family:monospace; font-size:11px; color:var(--primary);">{{ $op->reference_document ?? '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <div style="padding: 0 16px;">{{ $operations->links() }}</div>
        @endif
    </div>
</div>
@endsection

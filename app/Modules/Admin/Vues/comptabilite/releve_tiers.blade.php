@extends('admin::gabarits.application')
@section('titre', 'Relevé de compte — ' . $tier->nom)
@section('topbar_titre', 'Comptabilité — Relevé Tiers')

@section('styles')
<style>
    .releve-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }
    
    .releve-card {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.02);
    }
    
    .details-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 20px;
        margin-top: 16px;
    }

    .detail-item {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .detail-label {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--text-3);
    }

    .detail-val {
        font-size: 14px;
        font-weight: 600;
        color: var(--text);
    }

    /* Print styles */
    @media print {
        .sidebar, header, .topbar, .no-print, .banner-alert {
            display: none !important;
        }
        body, .main-wrap, .main-content, main {
            margin: 0 !important;
            padding: 0 !important;
            background: #fff !important;
            width: 100% !important;
            position: static !important;
        }
        .releve-card {
            border: none !important;
            box-shadow: none !important;
            padding: 0 !important;
        }
    }
</style>
@endsection

@section('contenu')
<div class="no-print releve-header">
    <div>
        <h1><i class="fas fa-file-invoice"></i> Relevé de compte : {{ $tier->nom }}</h1>
        <p>Fiche tiers et historique des factures et règlements associés.</p>
    </div>
    
    <div style="display: flex; gap: 8px;">
        <a href="{{ route('admin.comptabilite.creances') }}" class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> Retour aux créances
        </a>
        <button class="btn btn-primary" onclick="window.print()">
            <i class="fas fa-print"></i> Imprimer le relevé
        </button>
    </div>
</div>

<div class="releve-card">
    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--primary); letter-spacing: 0.5px; border-bottom: 2px solid var(--primary); padding-bottom: 6px; margin-bottom: 15px;">
        Informations du Tiers ({{ $type === 'client' ? 'Client' : 'Fournisseur' }})
    </div>
    
    <div class="details-grid">
        <div class="detail-item">
            <span class="detail-label">Nom / Raison Sociale</span>
            <span class="detail-val">{{ $tier->nom }}</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">N° Téléphone</span>
            <span class="detail-val">{{ $tier->telephone ?: '—' }}</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Adresse Email</span>
            <span class="detail-val">{{ $tier->email ?: '—' }}</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">N° RCCM</span>
            <span class="detail-val">{{ $tier->rccm ?: '—' }}</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Compte Comptable</span>
            <span class="detail-val" style="font-family: monospace; color: var(--primary);">{{ $tier->compte_comptable ?: ($type === 'client' ? '411000' : '401000') }}</span>
        </div>
        <div class="detail-item">
            <span class="detail-label">Solde actuel</span>
            <span class="detail-val {{ $solde > 0 ? 'text-danger' : 'text-success' }}" style="font-size: 16px; font-weight: 800;">
                {{ number_format($solde, 0, ',', ' ') }} F Dû
            </span>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-history"></i> Historique des mouvements</h2>
    </div>
    <div class="table-wrap">
        @if(empty($operations))
            <div style="padding: 48px; text-align:center; color:var(--text-3);">
                <i class="fas fa-history" style="font-size:48px; display:block; margin-bottom:12px; opacity:.2;"></i>
                Aucune opération enregistrée pour ce tiers.
            </div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>N° Pièce</th>
                        <th>Désignation / Libellé de l'opération</th>
                        <th style="text-align: right;">Débit (Dû)</th>
                        <th style="text-align: right;">Crédit (Payé)</th>
                        <th style="text-align: right;">Solde Progressif</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $runningSolde = 0;
                    @endphp
                    @foreach($operations as $op)
                    @php
                        if ($type === 'client') {
                            $runningSolde += ($op['debit'] - $op['credit']);
                        } else {
                            $runningSolde += ($op['credit'] - $op['debit']);
                        }
                    @endphp
                    <tr>
                        <td>{{ \Carbon\Carbon::parse($op['date'])->format('d/m/Y') }}</td>
                        <td style="font-weight: 700; color: var(--primary);">{{ $op['piece'] }}</td>
                        <td style="font-weight: 600;">{{ $op['libelle'] }}</td>
                        <td style="text-align: right; color: var(--marque-900, #1e3a8a); font-weight: 600;">
                            {{ $op['debit'] > 0 ? number_format($op['debit'], 0, ',', ' ') . ' F' : '—' }}
                        </td>
                        <td style="text-align: right; color: #0f766e; font-weight: 600;">
                            {{ $op['credit'] > 0 ? number_format($op['credit'], 0, ',', ' ') . ' F' : '—' }}
                        </td>
                        <td style="text-align: right; font-weight: 700; color: var(--text); font-size: 14px;">
                            {{ number_format($runningSolde, 0, ',', ' ') }} F
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection

@extends('admin::gabarits.application')
@section('titre', 'Créer un Bon de Livraison')
@section('topbar_titre', 'Bon de Livraison — Création')

@section('contenu')
@php $isCaissier = request()->routeIs('caissier.*'); @endphp

<div class="page-header">
    <div>
        <h1><i class="fas fa-truck"></i> Créer le Bon de Livraison</h1>
        <p>Basé sur le Bon de Commande <strong>{{ $vente->numero_facture }}</strong>
           — {{ $vente->client?->nom ?? '— Passage —' }}</p>
    </div>
    <a href="{{ $isCaissier ? route('caissier.ventes.factures', ['etape'=>'Bon de commande']) : route('admin.ventes.factures', ['etape'=>'Bon de commande']) }}"
       class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour
    </a>
</div>

{{-- Alerte stock insuffisant --}}
@if($stockInsuffisant > 0)
<div style="background:#fefce8; border:1px solid #fde68a; border-radius:10px; padding:16px 20px; margin-bottom:20px; display:flex; align-items:flex-start; gap:12px;">
    <i class="fas fa-triangle-exclamation" style="color:#d97706; font-size:20px; margin-top:2px;"></i>
    <div>
        <div style="font-weight:700; color:#92400e; margin-bottom:4px;">
            Stock insuffisant pour {{ $stockInsuffisant }} article(s)
        </div>
        <div style="color:#78350f; font-size:13px;">
            Les quantités suggérées ont été ajustées selon le stock disponible.
            Vous pouvez modifier manuellement les quantités à livrer ou choisir de faire une livraison partielle.
            <br><strong>Voulez-vous continuer avec les quantités disponibles ?</strong>
        </div>
    </div>
</div>
@endif

<form method="POST" action="{{ $isCaissier ? route('caissier.ventes.livraison.enregistrer', $vente) : route('admin.ventes.livraison.enregistrer', $vente) }}"
      id="form-bl">
    @csrf

    @if($errors->any())
        <div style="margin-bottom:16px;padding:12px 16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;color:#991b1b;font-size:13px;">
            @foreach($errors->all() as $erreur)<div>{{ $erreur }}</div>@endforeach
        </div>
    @endif

    @include('admin::composants.transport_depart', ['livreurs' => $livreurs, 'adresse' => $vente->client?->adresse])

    <div class="card" style="margin-bottom:20px;">
        <div style="padding:20px; border-bottom:1px solid var(--border); display:flex; gap:24px; flex-wrap:wrap;">
            <div style="flex:1; min-width:200px;">
                <label style="font-weight:600; font-size:13px; color:var(--text-2); display:block; margin-bottom:6px;">
                    <i class="fas fa-calendar"></i> Date de livraison *
                </label>
                <input type="date" name="date_livraison" value="{{ now()->format('Y-m-d') }}"
                       required
                       style="width:100%; padding:10px 14px; border:1px solid var(--border); border-radius:8px; font-size:14px; background:var(--bg2);">
            </div>
            <div style="flex:2; min-width:300px;">
                <label style="font-weight:600; font-size:13px; color:var(--text-2); display:block; margin-bottom:6px;">
                    <i class="fas fa-note-sticky"></i> Notes / Observations
                </label>
                <input type="text" name="notes" placeholder="Instructions pour le livreur..."
                       style="width:100%; padding:10px 14px; border:1px solid var(--border); border-radius:8px; font-size:14px; background:var(--bg2);">
            </div>
        </div>

        {{-- Tableau des articles --}}
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Article</th>
                        <th>Unité</th>
                        <th style="text-align:center;">Qté commandée</th>
                        <th style="text-align:center; color:#0369a1;">Stock disponible</th>
                        <th style="text-align:center; color:#047857;">Qté à livrer *</th>
                        <th style="text-align:center;">Reliquat</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($lignes as $i => $ligne)
                    <input type="hidden" name="lignes[{{ $i }}][produit_id]"    value="{{ $ligne['produit_id'] }}">
                    <input type="hidden" name="lignes[{{ $i }}][libelle]"       value="{{ $ligne['libelle'] }}">
                    <input type="hidden" name="lignes[{{ $i }}][unite]"         value="{{ $ligne['unite'] }}">
                    <input type="hidden" name="lignes[{{ $i }}][qte_commandee]" value="{{ $ligne['qte_commandee'] }}">
                    <tr id="ligne-{{ $i }}" class="{{ $ligne['est_insuffisant'] ? 'ligne-alerte' : '' }}">
                        <td>
                            <div style="font-weight:600; color:var(--text);">{{ $ligne['libelle'] }}</div>
                            @if($ligne['est_insuffisant'])
                            <div style="font-size:11px; color:#d97706; margin-top:2px;">
                                <i class="fas fa-triangle-exclamation"></i> Stock insuffisant
                            </div>
                            @endif
                        </td>
                        <td style="color:var(--text-2);">{{ $ligne['unite'] ?? '—' }}</td>
                        <td style="text-align:center; font-weight:700;">{{ $ligne['qte_commandee'] }}</td>
                        <td style="text-align:center;">
                            <span style="font-weight:700; color:{{ $ligne['est_insuffisant'] ? '#d97706' : '#059669' }};">
                                {{ $ligne['stock_dispo'] }}
                            </span>
                        </td>
                        <td style="text-align:center;">
                            <input type="number"
                                   name="lignes[{{ $i }}][qte_livree]"
                                   id="qte-livree-{{ $i }}"
                                   value="{{ $ligne['qte_suggere'] }}"
                                   min="0"
                                   max="{{ $ligne['qte_commandee'] }}"
                                   data-commande="{{ $ligne['qte_commandee'] }}"
                                   data-index="{{ $i }}"
                                   onchange="calculerReliquat({{ $i }})"
                                   style="width:80px; text-align:center; padding:6px; border:1px solid {{ $ligne['est_insuffisant'] ? '#fbbf24' : 'var(--border)' }}; border-radius:6px; background:var(--bg2); font-weight:700;">
                        </td>
                        <td style="text-align:center;">
                            <span id="reliquat-{{ $i }}" style="font-weight:700; color:{{ ($ligne['qte_commandee'] - $ligne['qte_suggere']) > 0 ? '#d97706' : '#059669' }};">
                                {{ $ligne['qte_commandee'] - $ligne['qte_suggere'] }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div style="padding:20px; display:flex; justify-content:space-between; align-items:center; border-top:1px solid var(--border);">
            <div id="recap-partiel" style="font-size:13px; color:var(--text-2);"></div>
            <div style="display:flex; gap:10px;">
                <a href="{{ $isCaissier ? route('caissier.ventes.factures', ['etape'=>'Bon de commande']) : route('admin.ventes.factures', ['etape'=>'Bon de commande']) }}"
                   class="btn btn-outline">
                    Annuler
                </a>
                <button type="submit" class="btn btn-primary" style="font-weight:700;">
                    <i class="fas fa-truck"></i> Générer le Bon de Livraison
                </button>
            </div>
        </div>
    </div>
</form>

<style>
.ligne-alerte td { background: #fffbeb !important; }
</style>

<script>
function calculerReliquat(i) {
    const qteL    = parseInt(document.getElementById('qte-livree-' + i).value) || 0;
    const qteCom  = parseInt(document.getElementById('qte-livree-' + i).dataset.commande) || 0;
    const reliquat = Math.max(0, qteCom - qteL);
    const span    = document.getElementById('reliquat-' + i);
    span.textContent = reliquat;
    span.style.color = reliquat > 0 ? '#d97706' : '#059669';
    mettreAJourRecap();
}

function mettreAJourRecap() {
    const inputs  = document.querySelectorAll('[id^="qte-livree-"]');
    let totalCom  = 0, totalLivre = 0;
    inputs.forEach(inp => {
        totalCom   += parseInt(inp.dataset.commande) || 0;
        totalLivre += parseInt(inp.value) || 0;
    });
    const recap = document.getElementById('recap-partiel');
    if (totalLivre < totalCom) {
        recap.innerHTML = '<i class="fas fa-info-circle" style="color:#d97706;"></i> <strong>Livraison partielle :</strong> '
            + totalLivre + ' / ' + totalCom + ' articles — reliquat : ' + (totalCom - totalLivre);
        recap.style.color = '#92400e';
    } else {
        recap.innerHTML = '<i class="fas fa-check-circle" style="color:#059669;"></i> <strong>Livraison complète</strong>';
        recap.style.color = '#047857';
    }
}

document.addEventListener('DOMContentLoaded', mettreAJourRecap);
</script>
@endsection

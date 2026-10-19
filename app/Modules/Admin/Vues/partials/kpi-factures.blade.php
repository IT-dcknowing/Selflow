{{--
    Les KPI d'une liste de factures (19/10/2026) : le total, l'annulé, le
    normalisé et le non normalisé — chacun en nombre et en montant TTC, sur la
    période et le site affichés.

    La vue appelante fournit `$kpiFactures` : liste de
    ['libelle', 'nombre', 'montant', 'couleur', 'icone', 'note'?].
--}}
@if(!empty($kpiFactures))
<div class="kpi-factures" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:16px;">
    @foreach($kpiFactures as $carte)
    <div class="card" style="padding:12px 14px; margin:0; border-left:4px solid {{ $carte['couleur'] }};">
        <div style="display:flex; align-items:center; gap:8px; font-size:11px; text-transform:uppercase; font-weight:700; color:var(--text-3); letter-spacing:.3px;">
            <i class="fas {{ $carte['icone'] }}" style="color:{{ $carte['couleur'] }};"></i> {{ $carte['libelle'] }}
        </div>
        <div style="font-size:19px; font-weight:800; margin-top:4px; color:var(--text);">{{ number_format((float) $carte['montant'], 0, ',', ' ') }} F</div>
        <div style="font-size:12px; color:var(--text-3);">{{ number_format((int) $carte['nombre'], 0, ',', ' ') }} pièce(s){{ !empty($carte['note']) ? ' · ' . $carte['note'] : '' }}</div>
    </div>
    @endforeach
</div>
@endif

@extends('admin::gabarits.application')
@section('titre', 'Situation Générale')
@section('topbar_titre', 'Situation Générale · Activité réelle de l\'entreprise')

@section('styles')
<style>
    .fne-filtres { display:flex; gap:12px; align-items:end; flex-wrap:wrap; background:#fff; border:1px solid var(--border); border-radius:14px; padding:16px 20px; margin-bottom:22px; }
    .fne-filtres .form-group { margin-bottom:0; }
    .fne-filtres label { font-size:11px; text-transform:uppercase; font-weight:700; color:var(--text-3); display:block; margin-bottom:4px; }

    .kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:26px; }
    .kpi-card { background:#fff; border:1px solid var(--border); border-radius:14px; padding:18px 20px; }
    .kpi-card .lbl { font-size:11px; text-transform:uppercase; font-weight:700; color:var(--text-3); letter-spacing:.4px; margin-bottom:8px; }
    .kpi-card .val { font-size:24px; font-weight:800; color:var(--text-1); }
    .kpi-card .sub { font-size:12px; color:var(--text-3); margin-top:4px; }

    .section-titre { font-size:14px; font-weight:700; color:var(--text-1); text-transform:uppercase; letter-spacing:.4px; margin:26px 0 14px; display:flex; align-items:center; gap:8px; }

    .decl-table { width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--border); border-radius:14px; overflow:hidden; }
    .decl-table th { text-align:left; padding:10px 14px; font-size:11px; text-transform:uppercase; color:var(--text-3); background:#f8fafc; border-bottom:1px solid var(--border); }
    .decl-table td { padding:12px 14px; border-bottom:1px solid var(--border); font-size:13px; }
    .decl-table tr:last-child td { border-bottom:none; }

    .taux-badge { display:inline-flex; align-items:center; gap:6px; padding:4px 12px; border-radius:20px; font-size:13px; font-weight:700; }
</style>
@endsection

@section('contenu')

@include('admin::fne.partials.filtre-periode', ['onChange' => 'rafraichirSituation()'])

<div class="kpi-grid">
    <div class="kpi-card"><div class="lbl">Chiffre d'affaires réel (TTC)</div><div class="val" id="k-ca">0 F</div><div class="sub">Normalisé + non normalisé, avoirs déduits</div></div>
    <div class="kpi-card"><div class="lbl">Achats réels (TTC)</div><div class="val" id="k-achats">0 F</div><div class="sub">Normalisé + non normalisé, avoirs déduits</div></div>
    <div class="kpi-card"><div class="lbl">Trésorerie nette encaissée</div><div class="val" id="k-treso">0 F</div><div class="sub"><span id="k-treso-detail">—</span></div></div>
    <div class="kpi-card"><div class="lbl">Taux de conformité FNE</div><div class="val"><span id="k-taux" class="taux-badge">0%</span></div><div class="sub">Ventes normalisées / ventes émises, hors avoirs</div></div>
</div>

<div class="section-titre"><i class="fas fa-file-circle-check"></i> Déclaré vs Non déclaré</div>
<table class="decl-table">
    <thead>
        <tr>
            <th>Flux</th>
            <th>Normalisé (nombre)</th>
            <th>Normalisé (montant)</th>
            <th>Non normalisé (nombre)</th>
            <th>Non normalisé (montant)</th>
            <th>Total</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td style="font-weight:700;"><i class="fas fa-arrow-down" style="color:#10b981;"></i> Ventes</td>
            <td id="d-v-n-nb">0</td>
            <td id="d-v-n-m">0 F</td>
            <td id="d-v-nn-nb">0</td>
            <td id="d-v-nn-m">0 F</td>
            <td id="d-v-tot" style="font-weight:700;">0 F</td>
        </tr>
        <tr>
            <td style="font-weight:700;"><i class="fas fa-arrow-up" style="color:#ef4444;"></i> Achats</td>
            <td id="d-a-n-nb">0</td>
            <td id="d-a-n-m">0 F</td>
            <td id="d-a-nn-nb">0</td>
            <td id="d-a-nn-m">0 F</td>
            <td id="d-a-tot" style="font-weight:700;">0 F</td>
        </tr>
    </tbody>
</table>

<div class="section-titre"><i class="fas fa-boxes-stacked"></i> Mouvements de stock</div>
<div class="kpi-grid">
    <div class="kpi-card"><div class="lbl">Ordres de production</div><div class="val" id="k-s-prod">0</div></div>
    <div class="kpi-card"><div class="lbl">Valeur produite</div><div class="val" id="k-s-valprod">0 F</div></div>
    <div class="kpi-card"><div class="lbl">Transferts internes validés</div><div class="val" id="k-s-transf">0</div></div>
    <div class="kpi-card"><div class="lbl">Pertes / Rebuts</div><div class="val">0</div><div class="sub">Non tracké dans Selflow actuellement</div></div>
</div>

<script>
function formatF(n) {
    return new Intl.NumberFormat('fr-FR').format(Math.round(n || 0)) + ' F';
}

function appliquerDonneesSituation(d) {
    document.getElementById('k-ca').textContent = formatF(d.ca_reel);
    document.getElementById('k-achats').textContent = formatF(d.achats_reel);
    document.getElementById('k-treso').textContent = formatF(d.tresorerie_nette);
    document.getElementById('k-treso-detail').textContent = `Entrées ${formatF(d.tresorerie_entrees)} — Sorties ${formatF(d.tresorerie_sorties)}`;

    const taux = document.getElementById('k-taux');
    taux.textContent = d.taux_conformite_fne + '%';
    taux.style.background = d.taux_conformite_fne >= 80 ? '#ecfdf5' : (d.taux_conformite_fne >= 40 ? '#fffbeb' : '#fef2f2');
    taux.style.color = d.taux_conformite_fne >= 80 ? '#065f46' : (d.taux_conformite_fne >= 40 ? '#92400e' : '#991b1b');

    document.getElementById('d-v-n-nb').textContent = d.declaration.ventes.normalisees.nombre;
    document.getElementById('d-v-n-m').textContent = formatF(d.declaration.ventes.normalisees.montant);
    document.getElementById('d-v-nn-nb').textContent = d.declaration.ventes.non_normalisees.nombre;
    document.getElementById('d-v-nn-m').textContent = formatF(d.declaration.ventes.non_normalisees.montant);
    document.getElementById('d-v-tot').textContent = formatF(d.declaration.ventes.total.montant) + ` (${d.declaration.ventes.total.nombre})`;

    document.getElementById('d-a-n-nb').textContent = d.declaration.achats.normalises.nombre;
    document.getElementById('d-a-n-m').textContent = formatF(d.declaration.achats.normalises.montant);
    document.getElementById('d-a-nn-nb').textContent = d.declaration.achats.non_normalises.nombre;
    document.getElementById('d-a-nn-m').textContent = formatF(d.declaration.achats.non_normalises.montant);
    document.getElementById('d-a-tot').textContent = formatF(d.declaration.achats.total.montant) + ` (${d.declaration.achats.total.nombre})`;

    document.getElementById('k-s-prod').textContent = d.stock.nb_ordres_production;
    document.getElementById('k-s-valprod').textContent = formatF(d.stock.valeur_produite);
    document.getElementById('k-s-transf').textContent = d.stock.nb_transferts;
}

function rafraichirSituation() {
    const params = parametresFiltreFne();

    fetch("{{ route('admin.fne.situation.donnees') }}?" + params.toString())
        .then(r => r.json())
        .then(appliquerDonneesSituation)
        .catch(() => {});
}

document.addEventListener('DOMContentLoaded', () => {
    appliquerDonneesSituation(@json($kpis));
});
</script>
@endsection

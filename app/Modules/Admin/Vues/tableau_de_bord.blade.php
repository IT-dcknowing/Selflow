@extends('admin::gabarits.application')

@section('titre', 'Mon tableau de bord')
@section('topbar_titre', 'Mon tableau de bord')

@section('contenu')

{{-- ── EN-TÊTE ─────────────────────────────────────────────────────────── --}}
<div class="page-header" style="margin-bottom:12px;">
    <div>
        <h1 style="font-size:22px; font-weight:800; display:flex; align-items:center; gap:10px;">
            <span style="width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#002B5C,#1e40af);display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:18px;">
                <i class="fas fa-chart-pie"></i>
            </span>
            Bonjour, {{ auth()->user()->prenom ?? auth()->user()->nom }}
        </h1>
        <p style="color:var(--text-2); margin-top:6px; font-size:13px;">
            <i class="fas fa-calendar-day"></i> {{ now()->locale('fr')->isoFormat('dddd D MMMM YYYY') }}
            &nbsp;·&nbsp; Période : <strong>{{ session('active_periode_nom', 'Non définie') }}</strong>
            @if($pointDeVenteId)
                &nbsp;·&nbsp; <i class="fas fa-store"></i> {{ session('point_de_vente_actif_nom') }}
            @endif
        </p>
    </div>
    @if(auth()->user()->aHabilitation('nouvelle_vente'))
    <a href="{{ route('admin.ventes.nouvelle') }}" class="btn btn-primary">
        <i class="fas fa-plus"></i> Nouvelle vente
    </a>
    @endif
</div>

{{-- ── FILTRES TEMPORELS PERSISTANTS ─────────────────────────────────────── --}}
<div id="tdb-filtres-bar" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--bg-card,#fff);border:1px solid var(--border,#E5E7EB);border-radius:12px;padding:12px 16px;margin-bottom:18px;box-shadow:0 1px 4px rgba(0,0,0,.05);">
    <span style="font-size:12px;font-weight:700;color:var(--text-3,#9CA3AF);text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;">
        <i class="fas fa-filter" style="margin-right:4px;"></i> Filtrer par
    </span>

    {{-- Mois --}}
    <select id="filtre-mois" style="padding:7px 12px;border:1.5px solid #E5E7EB;border-radius:8px;font-size:13px;font-family:inherit;color:#374151;background:#fff;cursor:pointer;outline:none;" onchange="appliquerFiltresTdb()">
        <option value="tous">— Tous les mois —</option>
        @foreach(['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'] as $i => $m)
            <option value="{{ $i + 1 }}">{{ $m }}</option>
        @endforeach
    </select>

    {{-- Semaine --}}
    <select id="filtre-semaine" style="padding:7px 12px;border:1.5px solid #E5E7EB;border-radius:8px;font-size:13px;font-family:inherit;color:#374151;background:#fff;cursor:pointer;outline:none;" onchange="appliquerFiltresTdb()">
        <option value="tous">— Toutes les semaines —</option>
        @for($s = 1; $s <= 53; $s++)
            <option value="{{ $s }}">Semaine {{ $s }}</option>
        @endfor
    </select>

    {{-- Jour --}}
    <select id="filtre-jour" style="padding:7px 12px;border:1.5px solid #E5E7EB;border-radius:8px;font-size:13px;font-family:inherit;color:#374151;background:#fff;cursor:pointer;outline:none;" onchange="appliquerFiltresTdb()">
        <option value="tous">— Tous les jours —</option>
        @for($j = 1; $j <= 31; $j++)
            <option value="{{ $j }}">Jour {{ $j }}</option>
        @endfor
    </select>

    <button onclick="reinitialiserFiltresTdb()" style="padding:7px 14px;border-radius:8px;background:none;border:1.5px solid #E5E7EB;color:#6B7280;font-size:12.5px;font-weight:600;cursor:pointer;font-family:inherit;" title="Réinitialiser">
        <i class="fas fa-rotate-left"></i> Reset
    </button>

    <span id="tdb-filtre-badge" style="display:none;background:#EFF6FF;color:#1D4ED8;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;border:1px solid #BFDBFE;">
        <i class="fas fa-circle-dot" style="font-size:8px;margin-right:4px;"></i>
        <span id="tdb-filtre-label"></span>
    </span>
</div>

@if(Auth::check() && Auth::user()->entreprise && !Auth::user()->entreprise->estInscriptionComplete())
<div class="alert alert-warning" style="display:flex; align-items:center; justify-content:space-between; gap:16px; background:#FFFBEB; border:1px solid #FCD34D; border-radius:12px; padding:16px 20px; margin-bottom:20px; color:#92400E; box-shadow:0 1px 3px rgba(0,0,0,0.05); flex-wrap:wrap;">
    <div style="display:flex; align-items:center; gap:12px; min-width:280px; flex:1;">
        <span style="font-size:20px; color:#D97706; display:flex; align-items:center; justify-content:center; flex-shrink:0;"><i class="fas fa-triangle-exclamation"></i></span>
        <div>
            <h4 style="font-weight:700; font-size:14px; margin-bottom:2px;">Finalisation de l'inscription requise</h4>
            <p style="font-size:13px; color:#B45309; line-height:1.4;">Finaliser l'inscription complète pour pouvoir exécuter des actions concrètes et profiter au mieux de l'application.</p>
        </div>
    </div>
    <a href="{{ route('admin.entreprise.parametres') }}" style="white-space:nowrap; background:#D97706; color:#ffffff; border:none; padding:8px 14px; border-radius:8px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:6px; font-size:12.5px; transition: background .15s;" onmouseover="this.style.background='#B45309'" onmouseout="this.style.background='#D97706'">
        <i class="fas fa-pen-to-square"></i> Compléter l'inscription complète
    </a>
</div>
@endif



{{-- ── KPI LIGNE 1 : AUJOURD'HUI ──────────────────────────────────────── --}}
<div style="display:grid; grid-template-columns: repeat(4,1fr); gap:14px; margin-bottom:20px;">
    <div class="tdb-kpi tdb-kpi-blue">
        <div class="tdb-kpi-icon"><i class="fas fa-arrow-trend-up"></i></div>
        <div>
            <div class="tdb-kpi-val">{{ number_format($montantVentesJour, 0, ',', ' ') }} <span>FCFA</span></div>
            <div class="tdb-kpi-lbl">Mes ventes</div>
            <div class="tdb-kpi-sub">{{ $nbVentesJour }} vente{{ $nbVentesJour > 1 ? 's' : '' }} · {{ $periodeLabel }}</div>
        </div>
    </div>
    <div class="tdb-kpi tdb-kpi-red">
        <div class="tdb-kpi-icon"><i class="fas fa-cart-shopping"></i></div>
        <div>
            <div class="tdb-kpi-val">{{ number_format($montantAchatsJour, 0, ',', ' ') }} <span>FCFA</span></div>
            <div class="tdb-kpi-lbl">Mes achats</div>
            <div class="tdb-kpi-sub">{{ $periodeLabel }}</div>
        </div>
    </div>
    <div class="tdb-kpi {{ $solde >= 0 ? 'tdb-kpi-green' : 'tdb-kpi-danger' }}">
        <div class="tdb-kpi-icon"><i class="fas fa-wallet"></i></div>
        <div>
            <div class="tdb-kpi-val">{{ number_format($solde, 0, ',', ' ') }} <span>FCFA</span></div>
            <div class="tdb-kpi-lbl">Mon solde trésorerie</div>
        </div>
    </div>
    <div class="tdb-kpi {{ $produitsEnAlerte->count() > 0 ? 'tdb-kpi-warning' : 'tdb-kpi-green' }}">
        <div class="tdb-kpi-icon"><i class="fas fa-boxes-stacked"></i></div>
        <div>
            <div class="tdb-kpi-val">{{ $produitsEnAlerte->count() }}</div>
            <div class="tdb-kpi-lbl">Alertes stock</div>
            <div class="tdb-kpi-sub">produit{{ $produitsEnAlerte->count() > 1 ? 's' : '' }} en alerte</div>
        </div>
    </div>
</div>

{{-- ── KPI LIGNE 2 : PÉRIODE ───────────────────────────────────────────── --}}
<div style="display:grid; grid-template-columns: repeat(3,1fr); gap:14px; margin-bottom:24px;">
    <div class="tdb-kpi tdb-kpi-purple" style="grid-column: span 2;">
        <div class="tdb-kpi-icon" style="width:52px;height:52px;"><i class="fas fa-chart-line"></i></div>
        <div style="flex:1;">
            <div class="tdb-kpi-lbl" style="font-size:11px; margin-bottom:4px;">CA de la période · {{ session('active_periode_nom', '—') }}</div>
            <div class="tdb-kpi-val" style="font-size:24px;">{{ number_format($totalVentesPeriode, 0, ',', ' ') }} <span>FCFA</span></div>
            <div class="tdb-kpi-sub">{{ $nbVentesPeriode }} ventes facturées</div>
        </div>
    </div>
    @if($meilleurProduit)
    <div class="tdb-kpi tdb-kpi-gold">
        <div class="tdb-kpi-icon"><i class="fas fa-trophy"></i></div>
        <div>
            <div class="tdb-kpi-lbl">Meilleur produit (période)</div>
            <div class="tdb-kpi-val" style="font-size:15px; margin-top:4px;">{{ $meilleurProduit->libelle_virtuel ?? '—' }}</div>
            <div class="tdb-kpi-sub">{{ number_format($meilleurProduit->ca, 0, ',', ' ') }} FCFA · {{ $meilleurProduit->qte }} unités</div>
        </div>
    </div>
    @else
    <div class="tdb-kpi tdb-kpi-gold">
        <div class="tdb-kpi-icon"><i class="fas fa-trophy"></i></div>
        <div>
            <div class="tdb-kpi-lbl">Meilleur produit</div>
            <div class="tdb-kpi-val" style="font-size:15px;">—</div>
            <div class="tdb-kpi-sub">Aucune vente sur la période</div>
        </div>
    </div>
    @endif
</div>

{{-- ── GRAPHIQUE 7 JOURS + DERNIERES VENTES ──────────────────────────── --}}
<div style="display:grid; grid-template-columns:1fr 1.6fr; gap:16px; margin-bottom:20px;">

    {{-- Graphique évolution 7j --}}
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-chart-area" style="color:#3b82f6"></i> Mes ventes — 7 derniers jours</h2>
        </div>
        <div class="card-body" style="padding:16px;">
            <div style="position:relative; height:200px;">
                <canvas id="chartEvolution7j"></canvas>
            </div>
            <div style="display:flex; justify-content:space-around; margin-top:12px; padding-top:10px; border-top:1px solid var(--border);">
                @php
                    $totSemaine = $jours7->sum('total');
                    $nbSemaine  = $jours7->sum('nb');
                    $maxJour    = $jours7->max('total');
                @endphp
                <div style="text-align:center;">
                    <div style="font-size:16px; font-weight:800; color:#3b82f6;">{{ number_format($totSemaine, 0, ',', ' ') }}</div>
                    <div style="font-size:10px; color:var(--text-3); text-transform:uppercase;">CA semaine</div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:16px; font-weight:800; color:#10b981;">{{ $nbSemaine }}</div>
                    <div style="font-size:10px; color:var(--text-3); text-transform:uppercase;">Ventes</div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:16px; font-weight:800; color:#f59e0b;">{{ $nbSemaine > 0 ? number_format($totSemaine/$nbSemaine, 0, ',', ' ') : '—' }}</div>
                    <div style="font-size:10px; color:var(--text-3); text-transform:uppercase;">Panier moy.</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Dernières ventes --}}
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-receipt" style="color:var(--success)"></i> Mes dernières ventes</h2>
            @if(auth()->user()->aHabilitation('factures_vente'))
            <a href="{{ route('admin.ventes.factures') }}" class="btn btn-outline btn-sm">Tout voir</a>
            @endif
        </div>
        <div class="table-wrap" style="max-height:280px; overflow-y:auto;">
            @if($dernieresVentes->isEmpty())
            <div style="padding:32px; text-align:center; color:var(--text-3);">
                <i class="fas fa-receipt" style="font-size:28px; margin-bottom:8px; opacity:.3; display:block;"></i>
                Aucune vente enregistrée
            </div>
            @else
            <table>
                <thead>
                    <tr>
                        <th>Facture</th>
                        <th>Client</th>
                        <th>Montant TTC</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($dernieresVentes as $vente)
                    <tr>
                        <td>
                            <a href="{{ route('admin.ventes.imprimer', $vente) }}" style="color:var(--primary); text-decoration:none; font-weight:600;">
                                {{ $vente->numero_facture }}
                            </a>
                            <div style="font-size:11px; color:var(--text-3);">{{ $vente->created_at->diffForHumans() }}</div>
                        </td>
                        <td>{{ $vente->client?->nom ?? 'Client de passage' }}</td>
                        <td style="font-weight:700; color:var(--success);">{{ number_format($vente->montant_ttc, 0, ',', ' ') }} F</td>
                        <td>
                            @php
                                $badgeCls = match($vente->statut) {
                                    'Facturée','Payé' => 'badge-success',
                                    'Avance'          => 'badge-warning',
                                    'Crédit'          => 'badge-danger',
                                    default           => 'badge-gray',
                                };
                            @endphp
                            <span class="badge {{ $badgeCls }}">{{ $vente->statut }}</span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @endif
        </div>
    </div>
</div>

{{-- ── ALERTES STOCK ─────────────────────────────────────────────────── --}}
@if($produitsEnAlerte->count())
<div class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <h2><i class="fas fa-triangle-exclamation" style="color:var(--warning)"></i> Alertes stock ({{ $produitsEnAlerte->count() }})</h2>
        @if(auth()->user()->aHabilitation('stock_articles'))
        <a href="{{ route('admin.stock.index') }}" class="btn btn-outline btn-sm"><i class="fas fa-boxes-stacked"></i> Gérer le stock</a>
        @endif
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Produit</th>
                    <th>Référence</th>
                    <th>Stock actuel</th>
                    <th>Minimum</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
                @foreach($produitsEnAlerte as $p)
                <tr>
                    <td style="font-weight:600;">{{ $p->nom }}</td>
                    <td style="color:var(--text-3); font-size:12px;">{{ $p->reference ?? '—' }}</td>
                    <td class="{{ $p->stock_actuel <= 0 ? 'stock-alerte' : 'stock-warning' }}" style="font-weight:700;">{{ $p->stock_actuel }}</td>
                    <td style="color:var(--text-3);">{{ $p->stock_minimum }}</td>
                    <td>
                        @if($p->stock_actuel <= 0)
                            <span class="badge badge-danger"><i class="fas fa-xmark"></i> Rupture</span>
                        @else
                            <span class="badge badge-warning"><i class="fas fa-triangle-exclamation"></i> Faible</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- ── STYLES ─────────────────────────────────────────────────────────── --}}
<style>
.tdb-kpi {
    display:flex; align-items:flex-start; gap:14px;
    padding:18px 20px; border-radius:14px;
    background: var(--surface);
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    transition:transform .2s, box-shadow .2s;
}
.tdb-kpi:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(0,0,0,.08); }
.tdb-kpi-icon {
    width:46px; height:46px; border-radius:12px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-size:18px;
}
.tdb-kpi-val { font-size:20px; font-weight:800; line-height:1.1; color: var(--text); }
.tdb-kpi-val span { font-size:12px; font-weight:500; color: var(--text-2); }
.tdb-kpi-lbl { font-size:11.5px; font-weight:600; color: var(--text-2); text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px; }
.tdb-kpi-sub { font-size:11px; color: var(--text-3); margin-top:3px; }

/* Icônes colorées sur fond pastel — arrière-plan de la carte toujours blanc */
.tdb-kpi-blue    .tdb-kpi-icon { background:rgba(59,130,246,.12);  color:#3b82f6; }
.tdb-kpi-red     .tdb-kpi-icon { background:rgba(239,68,68,.12);   color:#ef4444; }
.tdb-kpi-green   .tdb-kpi-icon { background:rgba(16,185,129,.12);  color:#10b981; }
.tdb-kpi-danger  .tdb-kpi-icon { background:rgba(239,68,68,.12);   color:#ef4444; }
.tdb-kpi-warning .tdb-kpi-icon { background:rgba(245,158,11,.12);  color:#f59e0b; }
.tdb-kpi-purple  .tdb-kpi-icon { background:rgba(139,92,246,.12);  color:#8b5cf6; }
.tdb-kpi-gold    .tdb-kpi-icon { background:rgba(245,158,11,.12);  color:#f59e0b; }

/* Valeur colorée seulement pour les montants négatifs */
.tdb-kpi-danger  .tdb-kpi-val { color: #ef4444; }

@media(max-width:1200px) {
    [style*="grid-template-columns: repeat(4"] { grid-template-columns: repeat(2,1fr) !important; }
    [style*="grid-template-columns: repeat(3"] { grid-template-columns: 1fr !important; }
    [style*="grid-template-columns:1fr 1.6fr"] { grid-template-columns: 1fr !important; }
}
</style>

{{-- ── CHART.JS ─────────────────────────────────────────────────────── --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const jours7 = @json($jours7);
const labels = jours7.map(j => {
    const d = new Date(j.jour);
    return d.toLocaleDateString('fr-FR', {weekday:'short', day:'numeric'});
});
const vals = jours7.map(j => parseFloat(j.total) || 0);

new Chart(document.getElementById('chartEvolution7j'), {
    type: 'line',
    data: {
        labels,
        datasets: [{
            label: 'Ventes TTC',
            data: vals,
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59,130,246,0.12)',
            fill: true, tension: 0.4,
            pointRadius: 5, pointBackgroundColor: '#3b82f6',
            borderWidth: 2.5
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 } } },
            y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString('fr') } }
        }
    }
});
</script>

<script>
// ── FILTRES TDB PERSISTANTS (localStorage) ──────────────────────────────────
const TDB_KEY = 'selflow_tdb_filtres_personnel';

function updateWeeksSelect() {
    const moisSelect = document.getElementById('filtre-mois');
    const semaineSelect = document.getElementById('filtre-semaine');
    if (!moisSelect || !semaineSelect) return;

    const moisVal = moisSelect.value;
    const selectedWeek = semaineSelect.value;

    semaineSelect.innerHTML = '<option value="tous">— Toutes les semaines —</option>';

    if (moisVal === 'tous') {
        for (let s = 1; s <= 53; s++) {
            semaineSelect.innerHTML += `<option value="${s}">Semaine ${s}</option>`;
        }
    } else {
        // Annee de l'exercice ouvert, et non celle de l'horloge du navigateur :
        // c'est elle qui donne son sens au numero de semaine cote serveur.
        const year = {{ (int) date('Y', strtotime(session('active_periode_debut') ?: 'now')) }};
        const m = parseInt(moisVal) - 1;
        const weeks = new Set();
        const firstDay = new Date(year, m, 1);
        const lastDay = new Date(year, m + 1, 0);

        for (let d = new Date(firstDay); d <= lastDay; d.setDate(d.getDate() + 1)) {
            const tempDate = new Date(d.valueOf());
            tempDate.setDate(tempDate.getDate() + 4 - (tempDate.getDay() || 7));
            const yearStart = new Date(tempDate.getFullYear(), 0, 1);
            const weekNo = Math.ceil((((tempDate - yearStart) / 86400000) + 1) / 7);
            weeks.add(weekNo);
        }

        Array.from(weeks).sort((a,b) => a - b).forEach((s, idx) => {
            semaineSelect.innerHTML += `<option value="${s}">Semaine ${idx + 1}</option>`;
        });
    }

    if (semaineSelect.querySelector(`option[value="${selectedWeek}"]`)) {
        semaineSelect.value = selectedWeek;
    } else {
        semaineSelect.value = 'tous';
    }
}

function appliquerFiltresTdb() {
    updateWeeksSelect();
    const moisSelect = document.getElementById('filtre-mois');
    const semaineSelect = document.getElementById('filtre-semaine');
    const jourSelect = document.getElementById('filtre-jour');

    const mois    = moisSelect.value;
    const semaine = semaineSelect.value;
    const jour    = jourSelect.value;

    // Sauvegarder dans localStorage
    localStorage.setItem(TDB_KEY, JSON.stringify({ mois, semaine, jour }));

    // Mettre à jour le badge
    const actifs = [];
    if (mois    !== 'tous') actifs.push(moisSelect.options[moisSelect.selectedIndex].text);
    if (semaine !== 'tous') {
        const semaineText = semaineSelect.options[semaineSelect.selectedIndex].text;
        actifs.push(semaineText);
    }
    if (jour    !== 'tous') actifs.push('Jour ' + jour);
    afficherBadge(actifs);

    // Recharger la page avec les paramètres de filtre
    const url = new URL(window.location.href);
    url.searchParams.set('filtre_mois',    mois);
    url.searchParams.set('filtre_semaine', semaine);
    url.searchParams.set('filtre_jour',    jour);
    window.location.href = url.toString();
}

function reinitialiserFiltresTdb() {
    localStorage.removeItem(TDB_KEY);
    document.getElementById('filtre-mois').value    = 'tous';
    document.getElementById('filtre-semaine').value = 'tous';
    document.getElementById('filtre-jour').value    = 'tous';
    afficherBadge([]);

    const url = new URL(window.location.href);
    url.searchParams.delete('filtre_mois');
    url.searchParams.delete('filtre_semaine');
    url.searchParams.delete('filtre_jour');
    window.location.href = url.toString();
}

function afficherBadge(actifs) {
    const badge = document.getElementById('tdb-filtre-badge');
    const label = document.getElementById('tdb-filtre-label');
    if (actifs.length > 0) {
        label.textContent = actifs.join(' · ');
        badge.style.display = 'inline-flex';
        badge.style.alignItems = 'center';
    } else {
        badge.style.display = 'none';
    }
}

// Restaurer les filtres au chargement
(function restaurerFiltres() {
    const urlParams = new URLSearchParams(window.location.search);
    const moisUrl    = urlParams.get('filtre_mois');
    const semaineUrl = urlParams.get('filtre_semaine');
    const jourUrl    = urlParams.get('filtre_jour');

    if (moisUrl || semaineUrl || jourUrl) {
        if (moisUrl)    document.getElementById('filtre-mois').value    = moisUrl;
        updateWeeksSelect();
        if (semaineUrl) document.getElementById('filtre-semaine').value = semaineUrl;
        if (jourUrl)    document.getElementById('filtre-jour').value    = jourUrl;

        const actifs = [];
        if (moisUrl    && moisUrl !== 'tous')    actifs.push(document.getElementById('filtre-mois').options[document.getElementById('filtre-mois').selectedIndex].text);
        if (semaineUrl && semaineUrl !== 'tous') actifs.push('Semaine ' + semaineUrl);
        if (jourUrl    && jourUrl !== 'tous')    actifs.push('Jour ' + jourUrl);
        afficherBadge(actifs);
        return;
    }

    // Fallback : localStorage
    const saved = localStorage.getItem(TDB_KEY);
    if (!saved) {
        updateWeeksSelect();
        return;
    }
    try {
        const { mois, semaine, jour } = JSON.parse(saved);
        if (mois)    document.getElementById('filtre-mois').value    = mois;
        updateWeeksSelect();
        if (semaine) document.getElementById('filtre-semaine').value = semaine;
        if (jour)    document.getElementById('filtre-jour').value    = jour;
        const actifs = [];
        if (mois    && mois !== 'tous')    actifs.push(document.getElementById('filtre-mois').options[document.getElementById('filtre-mois').selectedIndex]?.text ?? mois);
        if (semaine && semaine !== 'tous') actifs.push('Semaine ' + semaine);
        if (jour    && jour !== 'tous')    actifs.push('Jour ' + jour);
        afficherBadge(actifs);
    } catch (e) { localStorage.removeItem(TDB_KEY); updateWeeksSelect(); }
})();
</script>

@endsection

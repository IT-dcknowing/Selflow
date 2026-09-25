@extends('admin::gabarits.application')

@section('titre', 'Analyse d\'activité')
@section('topbar_titre', 'Analyse d\'activité')

@section('contenu')
<div class="rapport-page">

    {{-- ══ EN-TÊTE ══════════════════════════════════════════════════════════ --}}
    <div class="rapport-header">
        <div class="rapport-header-left">
            <div class="rapport-icon-wrap">
                <i class="fas fa-chart-mixed"></i>
            </div>
            <div>
                <h1 class="rapport-title">Analyse d'activité</h1>
                <p class="rapport-subtitle">
                    Période active :
                    <strong>{{ session('active_periode_nom', 'Non définie') }}</strong>
                    · {{ $entreprise->nom }}
                </p>
            </div>
        </div>
        <div class="rapport-header-right">
            <div class="kpi-badge kpi-badge-blue">
                <i class="fas fa-store"></i>
                {{ $pointsDeVente->count() }} point{{ $pointsDeVente->count() > 1 ? 's' : '' }} de vente
            </div>
        </div>
    </div>

    {{-- ══ ONGLETS ══════════════════════════════════════════════════════════ --}}
    <div class="rapport-tabs" id="rapportTabs">
        <button class="rtab active" onclick="switchTab('kpi', this)">
            <i class="fas fa-gauge-high"></i> Indicateurs clés
        </button>
        <button class="rtab" onclick="switchTab('produits', this)">
            <i class="fas fa-boxes-stacked"></i> Produits
        </button>
        <button class="rtab" onclick="switchTab('pdv', this)">
            <i class="fas fa-store"></i> Points de vente
        </button>
        <button class="rtab" onclick="switchTab('employes', this)">
            <i class="fas fa-users"></i> Employés
        </button>
        <button class="rtab" onclick="switchTab('evolution', this)">
            <i class="fas fa-chart-line"></i> Évolution
        </button>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════════
         TAB 1 — INDICATEURS CLÉS (KPI)
    ══════════════════════════════════════════════════════════════════════════ --}}
    <div id="tab-kpi" class="rtab-pane active">

        {{-- Ligne KPI principale --}}
        <div class="kpi-grid kpi-grid-5">
            <div class="kpi-card kpi-blue">
                <div class="kpi-icon"><i class="fas fa-arrow-trend-up"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Chiffre d'affaires TTC</div>
                    <div class="kpi-value">{{ number_format($totalVentes, 0, ',', ' ') }} FCFA</div>
                    <div class="kpi-sub">{{ $nbVentes }} ventes facturées</div>
                </div>
            </div>
            <div class="kpi-card kpi-red">
                <div class="kpi-icon"><i class="fas fa-cart-shopping"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Dépenses achats TTC</div>
                    <div class="kpi-value">{{ number_format($totalAchats, 0, ',', ' ') }} FCFA</div>
                    <div class="kpi-sub">{{ $nbAchats }} achats facturés</div>
                </div>
            </div>
            <div class="kpi-card {{ $margeBrute >= 0 ? 'kpi-green' : 'kpi-danger' }}">
                <div class="kpi-icon"><i class="fas fa-scale-balanced"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Marge brute</div>
                    <div class="kpi-value">{{ number_format($margeBrute, 0, ',', ' ') }} FCFA</div>
                    <div class="kpi-sub">Taux : {{ $tauxMarge }}%</div>
                </div>
            </div>
            <div class="kpi-card kpi-purple">
                <div class="kpi-icon"><i class="fas fa-receipt"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Panier moyen</div>
                    <div class="kpi-value">{{ number_format($panierMoyen, 0, ',', ' ') }} FCFA</div>
                    <div class="kpi-sub">Par vente facturée</div>
                </div>
            </div>
            <div class="kpi-card {{ $soldeTresorerie >= 0 ? 'kpi-teal' : 'kpi-danger' }}">
                <div class="kpi-icon"><i class="fas fa-vault"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Solde trésorerie</div>
                    <div class="kpi-value">{{ number_format($soldeTresorerie, 0, ',', ' ') }} FCFA</div>
                    <div class="kpi-sub">Enc. {{ number_format($totalEncaissements,0,',',' ') }} / Déc. {{ number_format($totalDecaissements,0,',',' ') }}</div>
                </div>
            </div>
        </div>

        {{-- Jauge rentabilité --}}
        <div class="rapport-row">
            <div class="rapport-card" style="flex:1;">
                <div class="rc-header">
                    <i class="fas fa-percent"></i>
                    Rentabilité globale
                </div>
                <div class="rentabilite-wrap">
                    @php $pct = min(max($tauxMarge, 0), 100); @endphp
                    <div class="rent-score {{ $tauxMarge >= 20 ? 'score-green' : ($tauxMarge >= 5 ? 'score-yellow' : 'score-red') }}">
                        {{ $tauxMarge }}%
                    </div>
                    <div class="rent-bar-wrap">
                        <div class="rent-bar">
                            <div class="rent-fill {{ $tauxMarge >= 20 ? 'fill-green' : ($tauxMarge >= 5 ? 'fill-yellow' : 'fill-red') }}"
                                 style="width: {{ $pct }}%"></div>
                        </div>
                        <div class="rent-legends">
                            <span style="color:#ef4444">Déficit</span>
                            <span style="color:#f59e0b">Faible (5%)</span>
                            <span style="color:#10b981">Bon (20%+)</span>
                        </div>
                    </div>
                    <div class="rent-verdict {{ $tauxMarge >= 20 ? 'verdict-green' : ($tauxMarge >= 5 ? 'verdict-yellow' : 'verdict-red') }}">
                        @if($tauxMarge >= 20)
                            <i class="fas fa-circle-check"></i> Activité rentable — bonne marge
                        @elseif($tauxMarge >= 5)
                            <i class="fas fa-triangle-exclamation"></i> Marge faible — optimisez les coûts
                        @else
                            <i class="fas fa-circle-xmark"></i> Activité déficitaire — action requise
                        @endif
                    </div>
                </div>
            </div>

            {{-- Répartition paiements --}}
            <div class="rapport-card" style="flex:1;">
                <div class="rc-header">
                    <i class="fas fa-credit-card"></i>
                    Modes de paiement
                </div>
                @if($repartitionPaiements->count())
                    {{-- Le beignet prenait toute la largeur de sa carte : le
                         `height` du canvas ne vaut rien sans
                         `maintainAspectRatio: false`, et Chart.js recalculait
                         la hauteur sur la largeur du parent. D'où un graphique
                         qui écrasait tout le reste de la page. --}}
                    <div class="pie-wrap" style="position:relative; height:190px;">
                        <canvas id="chartPaiements"></canvas>
                    </div>
                    <div class="pie-legend">
                        @foreach($repartitionPaiements as $i => $rp)
                        <div class="pie-legend-item">
                            <span class="pie-dot" style="background: {{ ['#3b82f6','#10b981','#f59e0b','#8b5cf6','#ef4444'][$i % 5] }}"></span>
                            <span>{{ $rp->mode_paiement ?? 'Autre' }}</span>
                            <span class="pie-pct">{{ $rp->nb }} ventes</span>
                        </div>
                        @endforeach
                    </div>
                @else
                    <div class="empty-state">Aucune donnée disponible</div>
                @endif
            </div>
        </div>

        {{-- Tableau comparatif Ventes vs Achats par mois --}}
        <div class="rapport-card">
            <div class="rc-header">
                <i class="fas fa-chart-bar"></i>
                Comparaison Ventes vs Dépenses (12 mois glissants)
            </div>
            <div style="position:relative; height:260px;">
                <canvas id="chartVentesAchats"></canvas>
            </div>
        </div>

    </div>

    {{-- ══════════════════════════════════════════════════════════════════════
         TAB 2 — PRODUITS
    ══════════════════════════════════════════════════════════════════════════ --}}
    <div id="tab-produits" class="rtab-pane" style="display:none;">

        <div class="rapport-row">
            {{-- TOP 10 produits vendus --}}
            <div class="rapport-card" style="flex:2;">
                <div class="rc-header">
                    <i class="fas fa-trophy" style="color:#f59e0b;"></i>
                    Top 10 — Produits les plus vendus
                </div>
                @if($topProduits->count())
                <div class="table-responsive">
                    <table class="rtable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Produit</th>
                                <th class="text-right">Qté vendue</th>
                                <th class="text-right">CA TTC</th>
                                <th class="text-right">Coût achat</th>
                                <th class="text-right">Marge</th>
                                <th class="text-right">Taux</th>
                                <th>Barre</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $maxCA = $topProduits->max('ca_ttc') ?: 1; @endphp
                            @foreach($topProduits as $i => $prod)
                            <tr>
                                <td>
                                    @if($i === 0) <span class="rank gold">🥇</span>
                                    @elseif($i === 1) <span class="rank silver">🥈</span>
                                    @elseif($i === 2) <span class="rank bronze">🥉</span>
                                    @else <span class="rank">{{ $i+1 }}</span>
                                    @endif
                                </td>
                                <td class="fw-600">{{ $prod->produit_nom }}</td>
                                <td class="text-right">{{ number_format($prod->qte_vendue, 0, ',', ' ') }}</td>
                                <td class="text-right fw-600">{{ number_format($prod->ca_ttc, 0, ',', ' ') }}</td>
                                <td class="text-right text-muted">{{ number_format($prod->cout_achat, 0, ',', ' ') }}</td>
                                <td class="text-right {{ $prod->marge_produit >= 0 ? 'text-success' : 'text-danger' }}">
                                    {{ number_format($prod->marge_produit, 0, ',', ' ') }}
                                </td>
                                <td class="text-right">
                                    <span class="badge-taux {{ $prod->taux_marge >= 20 ? 'badge-green' : ($prod->taux_marge >= 5 ? 'badge-yellow' : 'badge-red') }}">
                                        {{ $prod->taux_marge }}%
                                    </span>
                                </td>
                                <td style="width:100px;">
                                    <div class="mini-bar">
                                        <div class="mini-fill" style="width:{{ round(($prod->ca_ttc/$maxCA)*100) }}%; background:#3b82f6;"></div>
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                    <div class="empty-state">Aucune vente enregistrée sur cette période.</div>
                @endif
            </div>

            {{-- Produits les moins vendus --}}
            <div class="rapport-card" style="flex:1;">
                <div class="rc-header">
                    <i class="fas fa-arrow-trend-down" style="color:#ef4444;"></i>
                    Produits les moins performants
                </div>
                @if($basVendus->count())
                <ul class="bottom-list">
                    @foreach($basVendus as $i => $prod)
                    <li>
                        <div class="bl-rank">{{ $i + 1 }}</div>
                        <div class="bl-info">
                            <div class="bl-name">{{ $prod->produit_nom }}</div>
                            <div class="bl-sub">{{ number_format($prod->qte_vendue, 0, ',', ' ') }} unité(s)</div>
                        </div>
                        <div class="bl-amount text-danger">
                            {{ number_format($prod->ca_ttc, 0, ',', ' ') }} FCFA
                        </div>
                    </li>
                    @endforeach
                </ul>
                @else
                    <div class="empty-state">Aucune donnée.</div>
                @endif

                {{-- Top dépenses achats --}}
                <div class="rc-header" style="margin-top:24px;">
                    <i class="fas fa-money-bill-wave" style="color:#ef4444;"></i>
                    Top dépenses (achats)
                </div>
                @if($topDepenses->count())
                <ul class="bottom-list">
                    @php $maxDep = $topDepenses->max('total_depense') ?: 1; @endphp
                    @foreach($topDepenses->take(5) as $dep)
                    <li>
                        <div class="bl-info">
                            <div class="bl-name">{{ $dep->libelle_virtuel ?? 'Article' }}</div>
                            <div class="mini-bar" style="margin-top:4px;">
                                <div class="mini-fill" style="width:{{ round(($dep->total_depense/$maxDep)*100) }}%; background:#ef4444;"></div>
                            </div>
                        </div>
                        <div class="bl-amount text-danger">
                            {{ number_format($dep->total_depense, 0, ',', ' ') }}
                        </div>
                    </li>
                    @endforeach
                </ul>
                @else
                    <div class="empty-state">Aucun achat enregistré.</div>
                @endif
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════════
         TAB 3 — POINTS DE VENTE
    ══════════════════════════════════════════════════════════════════════════ --}}
    <div id="tab-pdv" class="rtab-pane" style="display:none;">

        {{-- KPI grille PDV --}}
        @if($performancesPdv->count())
        <div class="kpi-grid kpi-grid-3" style="margin-bottom:20px;">
            @php
                $meilleurPdv = $performancesPdv->first();
                $moinsBonPdv = $performancesPdv->last();
                $caTotalPdv  = $performancesPdv->sum('ca_ttc');
            @endphp
            <div class="kpi-card kpi-gold">
                <div class="kpi-icon"><i class="fas fa-medal"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Meilleur PDV</div>
                    <div class="kpi-value" style="font-size:18px;">{{ $meilleurPdv['nom'] }}</div>
                    <div class="kpi-sub">{{ number_format($meilleurPdv['ca_ttc'], 0, ',', ' ') }} FCFA</div>
                </div>
            </div>
            <div class="kpi-card kpi-blue">
                <div class="kpi-icon"><i class="fas fa-chart-pie"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">CA total tous PDV</div>
                    <div class="kpi-value">{{ number_format($caTotalPdv, 0, ',', ' ') }} FCFA</div>
                    <div class="kpi-sub">{{ $performancesPdv->count() }} points de vente actifs</div>
                </div>
            </div>
            <div class="kpi-card kpi-purple">
                <div class="kpi-icon"><i class="fas fa-store-slash"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">PDV à améliorer</div>
                    <div class="kpi-value" style="font-size:18px;">{{ $moinsBonPdv['nom'] }}</div>
                    <div class="kpi-sub">{{ number_format($moinsBonPdv['ca_ttc'], 0, ',', ' ') }} FCFA</div>
                </div>
            </div>
        </div>

        {{-- Graphique comparatif PDV --}}
        <div class="rapport-card" style="margin-bottom:20px;">
            <div class="rc-header"><i class="fas fa-chart-bar"></i> Comparaison CA par point de vente</div>
            <div style="position:relative; height:250px;">
                <canvas id="chartPdvCA"></canvas>
            </div>
        </div>

        {{-- Tableau détaillé PDV --}}
        <div class="rapport-card">
            <div class="rc-header"><i class="fas fa-table"></i> Tableau détaillé par point de vente</div>
            <div class="table-responsive">
                <table class="rtable">
                    <thead>
                        <tr>
                            <th>Point de vente</th>
                            <th>Ville</th>
                            <th class="text-right">CA TTC</th>
                            <th class="text-right">Achats</th>
                            <th class="text-right">Marge</th>
                            <th class="text-right">Nb ventes</th>
                            <th class="text-right">Panier moy.</th>
                            <th class="text-right">Solde tréso.</th>
                            <th class="text-center">Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($performancesPdv as $i => $pdv)
                        <tr>
                            <td class="fw-600">
                                @if($i === 0) 🥇 @elseif($i === 1) 🥈 @elseif($i === 2) 🥉 @endif
                                {{ $pdv['nom'] }}
                            </td>
                            <td class="text-muted">{{ $pdv['ville'] ?? '—' }}</td>
                            <td class="text-right fw-600">{{ number_format($pdv['ca_ttc'], 0, ',', ' ') }}</td>
                            <td class="text-right text-danger">{{ number_format($pdv['depenses'], 0, ',', ' ') }}</td>
                            <td class="text-right {{ $pdv['marge'] >= 0 ? 'text-success' : 'text-danger' }} fw-600">
                                {{ number_format($pdv['marge'], 0, ',', ' ') }}
                            </td>
                            <td class="text-right">{{ $pdv['nb_ventes'] }}</td>
                            <td class="text-right">{{ number_format($pdv['panier_moy'], 0, ',', ' ') }}</td>
                            <td class="text-right {{ $pdv['solde_tres'] >= 0 ? 'text-success' : 'text-danger' }}">
                                {{ number_format($pdv['solde_tres'], 0, ',', ' ') }}
                            </td>
                            <td class="text-center">
                                @if($pdv['rentable'])
                                    <span class="badge-taux badge-green">Rentable</span>
                                @else
                                    <span class="badge-taux badge-red">Déficitaire</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @else
            <div class="empty-state" style="padding:60px 0;">
                <i class="fas fa-store fa-3x" style="color:var(--text-3); margin-bottom:12px;"></i>
                <div>Aucun point de vente configuré.</div>
            </div>
        @endif
    </div>

    {{-- ══════════════════════════════════════════════════════════════════════
         TAB 4 — EMPLOYÉS
    ══════════════════════════════════════════════════════════════════════════ --}}
    <div id="tab-employes" class="rtab-pane" style="display:none;">

        @if($performancesEmployes->count())
        @php
            $meilleurEmp = $performancesEmployes->first();
            $moinsBonEmp = $performancesEmployes->last();
        @endphp

        {{-- KPI employés --}}
        <div class="kpi-grid kpi-grid-3" style="margin-bottom:20px;">
            <div class="kpi-card kpi-gold">
                <div class="kpi-icon"><i class="fas fa-star"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Meilleur vendeur</div>
                    <div class="kpi-value" style="font-size:17px;">{{ $meilleurEmp->nom_employe }}</div>
                    <div class="kpi-sub">{{ number_format($meilleurEmp->total_ventes, 0, ',', ' ') }} FCFA · {{ $meilleurEmp->nb_ventes }} ventes</div>
                </div>
            </div>
            <div class="kpi-card kpi-blue">
                <div class="kpi-icon"><i class="fas fa-users"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Vendeurs actifs</div>
                    <div class="kpi-value">{{ $performancesEmployes->count() }}</div>
                    <div class="kpi-sub">ayant réalisé au moins une vente</div>
                </div>
            </div>
            <div class="kpi-card kpi-orange">
                <div class="kpi-icon"><i class="fas fa-person-circle-minus"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Vendeur à soutenir</div>
                    <div class="kpi-value" style="font-size:17px;">{{ $moinsBonEmp->nom_employe }}</div>
                    <div class="kpi-sub">{{ number_format($moinsBonEmp->total_ventes, 0, ',', ' ') }} FCFA · {{ $moinsBonEmp->nb_ventes }} ventes</div>
                </div>
            </div>
        </div>

        {{-- Graphique employés --}}
        <div class="rapport-card" style="margin-bottom:20px;">
            <div class="rc-header"><i class="fas fa-chart-bar"></i> Performance des vendeurs</div>
            <div style="position:relative; height:250px;">
                <canvas id="chartEmployes"></canvas>
            </div>
        </div>

        {{-- Tableau employés --}}
        <div class="rapport-card">
            <div class="rc-header"><i class="fas fa-ranking-star"></i> Classement des vendeurs</div>
            <div class="table-responsive">
                <table class="rtable">
                    <thead>
                        <tr>
                            <th>Rang</th>
                            <th>Employé</th>
                            <th>Point de vente</th>
                            <th class="text-right">Total ventes</th>
                            <th class="text-right">Nb ventes</th>
                            <th class="text-right">Panier moyen</th>
                            <th>Performance</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $maxVentes = $performancesEmployes->max('total_ventes') ?: 1;
                        @endphp
                        @foreach($performancesEmployes as $i => $emp)
                        <tr>
                            <td>
                                @if($i === 0) <span class="rank gold">🥇</span>
                                @elseif($i === 1) <span class="rank silver">🥈</span>
                                @elseif($i === 2) <span class="rank bronze">🥉</span>
                                @else <span class="rank">{{ $i + 1 }}</span>
                                @endif
                            </td>
                            <td class="fw-600">{{ $emp->nom_employe }}</td>
                            <td class="text-muted">{{ $emp->pdv_nom }}</td>
                            <td class="text-right fw-600">{{ number_format($emp->total_ventes, 0, ',', ' ') }} FCFA</td>
                            <td class="text-right">{{ $emp->nb_ventes }}</td>
                            <td class="text-right">{{ number_format($emp->panier_moyen, 0, ',', ' ') }} FCFA</td>
                            <td style="width:150px;">
                                <div class="mini-bar">
                                    <div class="mini-fill"
                                         style="width:{{ round(($emp->total_ventes/$maxVentes)*100) }}%;
                                                background: {{ $i === 0 ? '#f59e0b' : ($i < 3 ? '#3b82f6' : '#94a3b8') }};"></div>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @else
            <div class="empty-state" style="padding:60px 0;">
                <i class="fas fa-users fa-3x" style="color:var(--text-3); margin-bottom:12px;"></i>
                <div>Aucune vente attribuée à un employé sur cette période.</div>
            </div>
        @endif
    </div>

    {{-- ══════════════════════════════════════════════════════════════════════
         TAB 5 — ÉVOLUTION
    ══════════════════════════════════════════════════════════════════════════ --}}
    <div id="tab-evolution" class="rtab-pane" style="display:none;">

        <div class="rapport-card">
            <div class="rc-header"><i class="fas fa-chart-area"></i> Évolution mensuelle sur 12 mois</div>
            <div style="position:relative; height:320px;">
                <canvas id="chartEvolution"></canvas>
            </div>
        </div>

        {{-- Tableau mensuel --}}
        <div class="rapport-card" style="margin-top:20px;">
            <div class="rc-header"><i class="fas fa-calendar-days"></i> Détail mensuel</div>
            <div class="table-responsive">
                <table class="rtable">
                    <thead>
                        <tr>
                            <th>Mois</th>
                            <th class="text-right">Ventes TTC</th>
                            <th class="text-right">Nb ventes</th>
                            <th class="text-right">Achats TTC</th>
                            <th class="text-right">Marge estimée</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $moisLabels = $evolutionMensuelle->pluck('mois');
                        @endphp
                        @foreach($evolutionMensuelle as $mois)
                        @php
                            $achatMois = $evolutionAchatsMensuelle->where('mois', $mois->mois)->first();
                            $depMois   = $achatMois ? $achatMois->depenses : 0;
                            $margMois  = $mois->ca - $depMois;
                        @endphp
                        <tr>
                            <td class="fw-600">{{ \Carbon\Carbon::createFromFormat('Y-m', $mois->mois)->locale('fr')->isoFormat('MMMM YYYY') }}</td>
                            <td class="text-right fw-600">{{ number_format($mois->ca, 0, ',', ' ') }} FCFA</td>
                            <td class="text-right">{{ $mois->nb }}</td>
                            <td class="text-right text-danger">{{ number_format($depMois, 0, ',', ' ') }} FCFA</td>
                            <td class="text-right {{ $margMois >= 0 ? 'text-success' : 'text-danger' }} fw-600">
                                {{ number_format($margMois, 0, ',', ' ') }} FCFA
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

{{-- ══ STYLES ══════════════════════════════════════════════════════════════ --}}
<style>
/* ── Page wrapper ── */
.rapport-page { padding: 0; }

/* ── Header ── */
.rapport-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 20px; gap: 16px;
}
.rapport-header-left { display: flex; align-items: center; gap: 16px; }
.rapport-icon-wrap {
    width: 52px; height: 52px; border-radius: 14px;
    background: linear-gradient(135deg, #002B5C, #1e40af);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 22px;
    box-shadow: 0 4px 16px rgba(0,43,92,0.3);
    flex-shrink: 0;
}
.rapport-title { font-size: 22px; font-weight: 800; color: var(--text); margin:0; }
.rapport-subtitle { font-size: 13px; color: var(--text-2); margin-top:4px; }

.kpi-badge { display:flex; align-items:center; gap:7px; padding:8px 14px; border-radius:30px; font-size:12.5px; font-weight:600; }
.kpi-badge-blue { background:#EBF5FF; color:#1d4ed8; border:1px solid #bfdbfe; }

/* ── Onglets ── */
.rapport-tabs {
    display: flex; gap: 4px; background: var(--surface);
    border: 1px solid var(--border); border-radius: 12px;
    padding: 6px; margin-bottom: 20px; overflow-x: auto;
}
.rtab {
    display: flex; align-items: center; gap: 7px;
    padding: 8px 16px; border: none; background: transparent;
    border-radius: 8px; cursor: pointer; font-family: inherit;
    font-size: 13px; font-weight: 500; color: var(--text-2);
    white-space: nowrap; transition: all 0.2s;
}
.rtab:hover { background: var(--bg3); color: var(--text); }
.rtab.active { background: var(--primary); color: #fff; font-weight: 600; }

/* ── KPI Grid ── */
.kpi-grid { display: grid; gap: 16px; margin-bottom: 20px; }
.kpi-grid-5 { grid-template-columns: repeat(5, 1fr); }
.kpi-grid-3 { grid-template-columns: repeat(3, 1fr); }
@media (max-width:1200px) { .kpi-grid-5 { grid-template-columns: repeat(3, 1fr); } }
@media (max-width:768px)  { .kpi-grid-5, .kpi-grid-3 { grid-template-columns: 1fr 1fr; } }

.kpi-card {
    border-radius: 14px; padding: 18px 20px; display: flex;
    align-items: flex-start; gap: 14px;
    background: var(--surface);
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    transition: transform 0.2s, box-shadow 0.2s;
}
.kpi-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.08); }

.kpi-icon {
    width: 46px; height: 46px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
}
.kpi-label { font-size: 11.5px; font-weight: 600; color: var(--text-2); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom:5px; }
.kpi-value { font-size: 20px; font-weight: 800; line-height:1.1; color: var(--text); }
.kpi-sub   { font-size: 11px; color: var(--text-3); margin-top:4px; }

/* Icônes colorées sur fond pastel — fond de carte toujours blanc */
.kpi-blue   .kpi-icon { background:rgba(59,130,246,.12);  color:#3b82f6; }
.kpi-red    .kpi-icon { background:rgba(239,68,68,.12);   color:#ef4444; }
.kpi-green  .kpi-icon { background:rgba(16,185,129,.12);  color:#10b981; }
.kpi-danger .kpi-icon { background:rgba(239,68,68,.12);   color:#ef4444; }
.kpi-danger .kpi-value { color:#ef4444; }
.kpi-purple .kpi-icon { background:rgba(139,92,246,.12);  color:#8b5cf6; }
.kpi-teal   .kpi-icon { background:rgba(13,148,136,.12);  color:#0d9488; }
.kpi-gold   .kpi-icon { background:rgba(245,158,11,.12);  color:#f59e0b; }
.kpi-orange .kpi-icon { background:rgba(249,115,22,.12);  color:#f97316; }

/* ── Rapport Row ── */
.rapport-row { display: flex; gap: 16px; margin-bottom: 20px; }
@media(max-width:900px) { .rapport-row { flex-direction: column; } }

/* ── Rapport Card ── */
.rapport-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 14px; padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    margin-bottom: 0;
}
.rc-header {
    font-size: 13.5px; font-weight: 700; color: var(--text);
    margin-bottom: 16px; display: flex; align-items: center; gap: 8px;
}
.rc-header i { color: var(--primary); }

/* ── Rentabilité ── */
.rentabilite-wrap { display: flex; flex-direction: column; gap: 12px; align-items: flex-start; }
.rent-score { font-size: 48px; font-weight: 900; line-height:1; }
.score-green { color: #10b981; }
.score-yellow { color: #f59e0b; }
.score-red { color: #ef4444; }

.rent-bar-wrap { width: 100%; }
.rent-bar { height: 12px; background: var(--border); border-radius: 6px; overflow: hidden; margin-bottom: 6px; }
.rent-fill { height: 100%; border-radius: 6px; transition: width 1s ease; }
.fill-green  { background: linear-gradient(90deg, #10b981, #059669); }
.fill-yellow { background: linear-gradient(90deg, #f59e0b, #d97706); }
.fill-red    { background: linear-gradient(90deg, #ef4444, #dc2626); }
.rent-legends { display: flex; justify-content: space-between; font-size: 10px; color: var(--text-3); }

.rent-verdict {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; width: 100%;
}
.verdict-green  { background: #f0fdf4; color: #15803d; }
.verdict-yellow { background: #fffbeb; color: #b45309; }
.verdict-red    { background: #fef2f2; color: #b91c1c; }

/* ── Pie chart ── */
.pie-wrap { display: flex; justify-content: center; margin-bottom: 12px; }
.pie-wrap canvas { max-height: 190px; }
.pie-legend { display: flex; flex-direction: column; gap: 6px; }
.pie-legend-item { display: flex; align-items: center; gap: 8px; font-size: 12px; }
.pie-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.pie-pct { margin-left: auto; font-weight: 600; color: var(--text-2); }

/* ── Table ── */
.table-responsive { overflow-x: auto; }
.rtable { width: 100%; border-collapse: collapse; font-size: 13px; }
.rtable th {
    padding: 10px 12px; background: var(--bg3); color: var(--text-2);
    font-weight: 600; font-size: 11.5px; text-transform: uppercase;
    letter-spacing: 0.4px; border-bottom: 2px solid var(--border);
    white-space: nowrap;
}
.rtable td { padding: 10px 12px; border-bottom: 1px solid var(--border); }
.rtable tr:last-child td { border-bottom: none; }
.rtable tr:hover td { background: var(--bg3); }

.text-right  { text-align: right; }
.text-center { text-align: center; }
.text-muted  { color: var(--text-3); }
.text-success { color: #10b981; }
.text-danger  { color: #ef4444; }
.fw-600 { font-weight: 600; }

/* ── Mini bar ── */
.mini-bar { height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; }
.mini-fill { height: 100%; border-radius: 3px; transition: width 0.8s ease; }

/* ── Ranks ── */
.rank { font-size: 12px; font-weight: 700; color: var(--text-3); }

/* ── Badges ── */
.badge-taux { display: inline-block; padding: 3px 8px; border-radius: 20px; font-size: 11px; font-weight: 700; }
.badge-green  { background: #dcfce7; color: #166534; }
.badge-yellow { background: #fef3c7; color: #92400e; }
.badge-red    { background: #fee2e2; color: #991b1b; }

/* ── Bottom list ── */
.bottom-list { list-style: none; display: flex; flex-direction: column; gap: 8px; }
.bottom-list li { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid var(--border); }
.bottom-list li:last-child { border-bottom: none; }
.bl-rank { width: 22px; height: 22px; border-radius: 6px; background: var(--bg3); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: var(--text-2); flex-shrink: 0; }
.bl-info { flex: 1; }
.bl-name { font-size: 12.5px; font-weight: 600; color: var(--text); }
.bl-sub  { font-size: 11px; color: var(--text-3); }
.bl-amount { font-size: 12px; font-weight: 700; flex-shrink: 0; }

/* ── Empty state ── */
.empty-state {
    text-align: center; padding: 40px 20px;
    color: var(--text-3); font-size: 14px;
    display: flex; flex-direction: column; align-items: center; gap: 12px;
}
</style>

{{-- ══ SCRIPTS Chart.js ══════════════════════════════════════════════════ --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
/* ── Tab switching ─────────────────────────────────────────────────────── */
function switchTab(id, btn) {
    document.querySelectorAll('.rtab-pane').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.rtab').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + id).style.display = 'block';
    btn.classList.add('active');
}

/* ── Data from PHP ─────────────────────────────────────────────────────── */
const evolutionData = @json($evolutionMensuelle);
const evolutionAchats = @json($evolutionAchatsMensuelle);
const repartitionPaiements = @json($repartitionPaiements);
const performancesPdv = @json($performancesPdv);
const performancesEmployes = @json($performancesEmployes);

/* ── Palette ───────────────────────────────────────────────────────────── */
const COLORS = ['#3b82f6','#10b981','#f59e0b','#8b5cf6','#ef4444','#0d9488','#f97316','#ec4899'];

/* ── Chart : Paiements (Doughnut) ─────────────────────────────────────── */
const ctxPay = document.getElementById('chartPaiements');
if (ctxPay && repartitionPaiements.length) {
    new Chart(ctxPay, {
        type: 'doughnut',
        data: {
            labels: repartitionPaiements.map(r => r.mode_paiement || 'Autre'),
            datasets: [{
                data: repartitionPaiements.map(r => r.total),
                backgroundColor: COLORS,
                borderWidth: 2, borderColor: '#fff',
            }]
        },
        options: {
            // Sans cela, la hauteur du cadre est ignoree et le graphique
            // s'etire sur la largeur du parent.
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            plugins: { legend: { display: false } },
            animation: { animateScale: true }
        }
    });
}

/* ── Chart : Ventes vs Achats (Bar) ───────────────────────────────────── */
const allMonths = [...new Set([
    ...evolutionData.map(d => d.mois),
    ...evolutionAchats.map(d => d.mois)
])].sort();

const ventesMap  = Object.fromEntries(evolutionData.map(d => [d.mois, parseFloat(d.ca) || 0]));
const achatsMap  = Object.fromEntries(evolutionAchats.map(d => [d.mois, parseFloat(d.depenses) || 0]));

const ctxVA = document.getElementById('chartVentesAchats');
if (ctxVA) {
    new Chart(ctxVA, {
        type: 'bar',
        data: {
            labels: allMonths.map(m => { const [y,mo]=m.split('-'); return ['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Aoû','Sep','Oct','Nov','Déc'][parseInt(mo)-1]+' '+y; }),
            datasets: [
                {
                    label: 'Ventes TTC',
                    data: allMonths.map(m => ventesMap[m] || 0),
                    backgroundColor: 'rgba(59,130,246,0.85)',
                    borderRadius: 6,
                },
                {
                    label: 'Achats TTC',
                    data: allMonths.map(m => achatsMap[m] || 0),
                    backgroundColor: 'rgba(239,68,68,0.75)',
                    borderRadius: 6,
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'top' } },
            scales: {
                y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString('fr') + ' FCFA' } }
            }
        }
    });
}

/* ── Chart : PDV (Horizontal Bar) ─────────────────────────────────────── */
const ctxPdv = document.getElementById('chartPdvCA');
if (ctxPdv && performancesPdv.length) {
    new Chart(ctxPdv, {
        type: 'bar',
        data: {
            labels: performancesPdv.map(p => p.nom),
            datasets: [
                {
                    label: 'CA TTC',
                    data: performancesPdv.map(p => parseFloat(p.ca_ttc) || 0),
                    backgroundColor: performancesPdv.map((p,i) => COLORS[i % COLORS.length]),
                    borderRadius: 8,
                },
                {
                    label: 'Dépenses',
                    data: performancesPdv.map(p => parseFloat(p.depenses) || 0),
                    backgroundColor: 'rgba(239,68,68,0.5)',
                    borderRadius: 8,
                }
            ]
        },
        options: {
            indexAxis: 'y',
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'top' } },
            scales: { x: { beginAtZero: true, ticks: { callback: v => v.toLocaleString('fr') } } }
        }
    });
}

/* ── Chart : Employés (Bar) ────────────────────────────────────────────── */
const ctxEmp = document.getElementById('chartEmployes');
if (ctxEmp && performancesEmployes.length) {
    new Chart(ctxEmp, {
        type: 'bar',
        data: {
            labels: performancesEmployes.map(e => e.nom_employe),
            datasets: [{
                label: 'Total ventes (FCFA)',
                data: performancesEmployes.map(e => parseFloat(e.total_ventes) || 0),
                backgroundColor: performancesEmployes.map((e,i) =>
                    i === 0 ? '#f59e0b' : (i < 3 ? '#3b82f6' : 'rgba(148,163,184,0.7)')
                ),
                borderRadius: 8,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString('fr') } } }
        }
    });
}

/* ── Chart : Évolution (Area) ──────────────────────────────────────────── */
const ctxEvo = document.getElementById('chartEvolution');
if (ctxEvo) {
    new Chart(ctxEvo, {
        type: 'line',
        data: {
            labels: allMonths.map(m => { const [y,mo]=m.split('-'); return ['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Aoû','Sep','Oct','Nov','Déc'][parseInt(mo)-1]+' '+y; }),
            datasets: [
                {
                    label: 'Ventes TTC',
                    data: allMonths.map(m => ventesMap[m] || 0),
                    borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.12)',
                    fill: true, tension: 0.4, pointRadius: 5, pointBackgroundColor: '#3b82f6',
                },
                {
                    label: 'Achats TTC',
                    data: allMonths.map(m => achatsMap[m] || 0),
                    borderColor: '#ef4444', backgroundColor: 'rgba(239,68,68,0.08)',
                    fill: true, tension: 0.4, pointRadius: 5, pointBackgroundColor: '#ef4444',
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'top' } },
            scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString('fr') + ' FCFA' } } }
        }
    });
}

/* ── Animate bars on load ──────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.mini-fill, .rent-fill').forEach(el => {
        const w = el.style.width;
        el.style.width = '0';
        setTimeout(() => el.style.width = w, 100);
    });
});
</script>
@endsection

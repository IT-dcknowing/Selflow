@extends('admin::gabarits.application')

@section('titre', 'Tableau de bord — SuperAdmin')
@section('topbar_titre', 'Tableau de bord SuperAdmin')

@section('contenu')
<div style="padding-bottom: 40px;">

{{-- ═══════════════════════════════════════ EN-TÊTE ══════════════════════════════════════ --}}
<div class="page-header" style="margin-bottom: 28px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 800; display: flex; align-items: center; gap: 10px; margin: 0;">
            <span style="width: 42px; height: 42px; border-radius: 12px; background: linear-gradient(135deg, #002B5C, #6C5CE7); display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: 20px;">
                <i class="fas fa-chart-pie"></i>
            </span>
            Tableau de bord global
        </h1>
        <p style="color: var(--text-2); margin-top: 6px; font-size: 13px;">
            Vue d'ensemble en temps réel de toutes les entreprises sur la plateforme Selflow
        </p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="{{ route('superadmin.entreprises') }}" class="btn btn-outline">
            <i class="fas fa-building"></i> Gérer les entreprises
        </a>
        <a href="{{ route('superadmin.entreprises.creer') }}" class="btn btn-primary">
            <i class="fas fa-plus"></i> Nouvelle entreprise
        </a>
    </div>
</div>

{{-- ═══════════════════════════════ LIGNE 1 : KPI PRINCIPAUX ═════════════════════════════ --}}
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px;">

    {{-- Entreprises --}}
    <div style="background: linear-gradient(135deg, #002B5C 0%, #004099 100%); border-radius: 16px; padding: 22px; color: #fff; position: relative; overflow: hidden;">
        <div style="position: absolute; right: -10px; bottom: -10px; font-size: 64px; opacity: 0.1;"><i class="fas fa-building"></i></div>
        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; opacity: 0.8; margin-bottom: 8px;">Entreprises</div>
        <div style="font-size: 42px; font-weight: 900; line-height: 1;">{{ $totalEntreprises }}</div>
        <div style="font-size: 12px; opacity: 0.75; margin-top: 6px;">structures enregistrées</div>
        @if($entreprisesSansUsers > 0)
            <div style="margin-top: 10px; font-size: 11px; background: rgba(255,255,255,0.15); border-radius: 6px; padding: 4px 8px; display: inline-block;">
                <i class="fas fa-exclamation-triangle"></i> {{ $entreprisesSansUsers }} sans utilisateurs
            </div>
        @endif
    </div>

    {{-- Points de vente --}}
    <div style="background: linear-gradient(135deg, #6C5CE7 0%, #a29bfe 100%); border-radius: 16px; padding: 22px; color: #fff; position: relative; overflow: hidden;">
        <div style="position: absolute; right: -10px; bottom: -10px; font-size: 64px; opacity: 0.1;"><i class="fas fa-store"></i></div>
        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; opacity: 0.8; margin-bottom: 8px;">Points de vente</div>
        <div style="font-size: 42px; font-weight: 900; line-height: 1;">{{ $totalPdvs }}</div>
        <div style="font-size: 12px; opacity: 0.75; margin-top: 6px;">sites & dépôts actifs</div>
        <div style="margin-top: 10px; font-size: 11px; background: rgba(255,255,255,0.15); border-radius: 6px; padding: 4px 8px; display: inline-block;">
            <i class="fas fa-calculator"></i> moy. {{ $avgPdvParEntreprise }} / entreprise
        </div>
    </div>

    {{-- Utilisateurs --}}
    <div style="background: linear-gradient(135deg, #00B894 0%, #00cec9 100%); border-radius: 16px; padding: 22px; color: #fff; position: relative; overflow: hidden;">
        <div style="position: absolute; right: -10px; bottom: -10px; font-size: 64px; opacity: 0.1;"><i class="fas fa-users"></i></div>
        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; opacity: 0.8; margin-bottom: 8px;">Utilisateurs</div>
        <div style="font-size: 42px; font-weight: 900; line-height: 1;">{{ $totalUtilisateurs }}</div>
        <div style="font-size: 12px; opacity: 0.75; margin-top: 6px;">comptes créés</div>
        <div style="margin-top: 10px; font-size: 11px; background: rgba(255,255,255,0.15); border-radius: 6px; padding: 4px 8px; display: inline-block;">
            <i class="fas fa-circle" style="font-size: 8px;"></i> {{ $totalActifsJour }} actifs aujourd'hui
        </div>
    </div>

    {{-- Actifs ce jour --}}
    <div style="background: linear-gradient(135deg, #FDCB6E 0%, #e17055 100%); border-radius: 16px; padding: 22px; color: #fff; position: relative; overflow: hidden;">
        <div style="position: absolute; right: -10px; bottom: -10px; font-size: 64px; opacity: 0.1;"><i class="fas fa-user-clock"></i></div>
        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; opacity: 0.8; margin-bottom: 8px;">Actifs Aujourd'hui</div>
        <div style="font-size: 42px; font-weight: 900; line-height: 1;">{{ $totalActifsJour }}</div>
        <div style="font-size: 12px; opacity: 0.75; margin-top: 6px;">sessions actives</div>
        <div style="margin-top: 10px; font-size: 11px; background: rgba(255,255,255,0.15); border-radius: 6px; padding: 4px 8px; display: inline-block;">
            {{ now()->format('d/m/Y') }}
        </div>
    </div>

</div>

{{-- ═══════════════════════════════ LIGNE 2 : RÉPARTITION DES RÔLES + PLANS ════════════ --}}
<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 20px;">

    {{-- Répartition rôles --}}
    <div class="card" style="margin: 0;">
        <div class="card-header">
            <h2 style="font-size: 14px;"><i class="fas fa-id-badge" style="color: #6C5CE7;"></i> Répartition des rôles</h2>
        </div>
        <div style="padding: 16px;">
            {{-- Admin --}}
            <div style="margin-bottom: 14px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                    <span style="font-size: 13px; font-weight: 600; color: var(--text-1);">
                        <i class="fas fa-shield-halved" style="color: #002B5C; margin-right: 4px;"></i> Administrateurs
                    </span>
                    <span style="font-weight: 800; color: #002B5C; font-size: 14px;">{{ $totalAdmins }}</span>
                </div>
                @php $pctAdmin = $totalUtilisateurs > 0 ? round($totalAdmins / $totalUtilisateurs * 100) : 0; @endphp
                <div style="background: #e9ecef; border-radius: 6px; height: 8px; overflow: hidden;">
                    <div style="width: {{ $pctAdmin }}%; background: #002B5C; height: 100%; border-radius: 6px; transition: width 0.5s;"></div>
                </div>
                <span style="font-size: 11px; color: var(--text-3);">{{ $pctAdmin }}% du total</span>
            </div>
            {{-- Caissiers --}}
            <div style="margin-bottom: 14px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                    <span style="font-size: 13px; font-weight: 600; color: var(--text-1);">
                        <i class="fas fa-cash-register" style="color: #6C5CE7; margin-right: 4px;"></i> Caissiers
                    </span>
                    <span style="font-weight: 800; color: #6C5CE7; font-size: 14px;">{{ $totalCaissiers }}</span>
                </div>
                @php $pctCaissier = $totalUtilisateurs > 0 ? round($totalCaissiers / $totalUtilisateurs * 100) : 0; @endphp
                <div style="background: #e9ecef; border-radius: 6px; height: 8px; overflow: hidden;">
                    <div style="width: {{ $pctCaissier }}%; background: #6C5CE7; height: 100%; border-radius: 6px; transition: width 0.5s;"></div>
                </div>
                <span style="font-size: 11px; color: var(--text-3);">{{ $pctCaissier }}% du total</span>
            </div>
            {{-- Autres --}}
            @php $autresUsers = $totalUtilisateurs - $totalAdmins - $totalCaissiers; $pctAutres = $totalUtilisateurs > 0 ? round($autresUsers / $totalUtilisateurs * 100) : 0; @endphp
            <div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                    <span style="font-size: 13px; font-weight: 600; color: var(--text-1);">
                        <i class="fas fa-user-gear" style="color: #00B894; margin-right: 4px;"></i> Autres rôles
                    </span>
                    <span style="font-weight: 800; color: #00B894; font-size: 14px;">{{ $autresUsers }}</span>
                </div>
                <div style="background: #e9ecef; border-radius: 6px; height: 8px; overflow: hidden;">
                    <div style="width: {{ $pctAutres }}%; background: #00B894; height: 100%; border-radius: 6px; transition: width 0.5s;"></div>
                </div>
                <span style="font-size: 11px; color: var(--text-3);">{{ $pctAutres }}% du total</span>
            </div>
        </div>
    </div>

    {{-- Plans d'abonnement --}}
    <div class="card" style="margin: 0;">
        <div class="card-header">
            <h2 style="font-size: 14px;"><i class="fas fa-crown" style="color: #FDCB6E;"></i> Plans d'abonnement</h2>
        </div>
        <div style="padding: 16px;">
            @forelse($parPlan as $plan => $nb)
            @php
                $couleurs = ['Starter' => '#00B894', 'Pro' => '#6C5CE7', 'Enterprise' => '#002B5C', 'Gratuit' => '#636e72'];
                $couleur  = $couleurs[$plan] ?? '#636e72';
                $pct      = $totalEntreprises > 0 ? round($nb / $totalEntreprises * 100) : 0;
            @endphp
            <div style="margin-bottom: 14px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                    <span style="font-size: 13px; font-weight: 600; color: var(--text-1); display: flex; align-items: center; gap: 6px;">
                        <span style="width: 10px; height: 10px; border-radius: 50%; background: {{ $couleur }}; display: inline-block;"></span>
                        {{ $plan }}
                    </span>
                    <span style="font-weight: 800; color: {{ $couleur }}; font-size: 14px;">{{ $nb }}</span>
                </div>
                <div style="background: #e9ecef; border-radius: 6px; height: 8px; overflow: hidden;">
                    <div style="width: {{ $pct }}%; background: {{ $couleur }}; height: 100%; border-radius: 6px;"></div>
                </div>
                <span style="font-size: 11px; color: var(--text-3);">{{ $pct }}% des entreprises</span>
            </div>
            @empty
            <p style="color: var(--text-3); font-size: 13px; text-align: center; padding: 20px 0;">Aucun plan défini</p>
            @endforelse
        </div>
    </div>

    {{-- Modules populaires --}}
    <div class="card" style="margin: 0;">
        <div class="card-header">
            <h2 style="font-size: 14px;"><i class="fas fa-puzzle-piece" style="color: #e17055;"></i> Modules populaires</h2>
        </div>
        <div style="padding: 16px;">
            @forelse($modulesPopulaires as $module => $count)
            @php
                $icones = [
                    'ventes' => 'fa-file-invoice-dollar', 'achats' => 'fa-shopping-cart',
                    'stock' => 'fa-boxes-stacked', 'tresorerie' => 'fa-wallet',
                    'comptabilite' => 'fa-calculator', 'rh' => 'fa-people-group',
                    'production' => 'fa-industry', 'b2b' => 'fa-handshake',
                    'fne' => 'fa-file-contract',
                ];
                $icone = $icones[$module] ?? 'fa-circle-dot';
            @endphp
            <div style="display: flex; align-items: center; gap: 10px; padding: 7px 0; border-bottom: 1px solid var(--border);">
                <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(108,92,231,0.1); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i class="fas {{ $icone }}" style="font-size: 13px; color: #6C5CE7;"></i>
                </div>
                <div style="flex: 1;">
                    <div style="font-size: 13px; font-weight: 700; color: var(--text-1); ">{{ \App\Modules\Admin\Modeles\Entreprise::libelleModule($module) }}</div>
                    <div style="font-size: 11px; color: var(--text-3);">{{ $count }} entreprise{{ $count > 1 ? 's' : '' }}</div>
                </div>
                <span style="background: var(--bg3); color: var(--primary); font-size: 11px; font-weight: 800; padding: 2px 8px; border-radius: 20px;">{{ $count }}</span>
            </div>
            @empty
            <p style="color: var(--text-3); font-size: 13px; text-align: center; padding: 20px 0;">Aucune donnée</p>
            @endforelse
        </div>
    </div>

</div>

{{-- ══════════════════════════════ LIGNE 3 : INSCRIPTIONS MENSUELLES ══════════════════════ --}}
@if($inscriptionsParMois->isNotEmpty())
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header">
        <h2 style="font-size: 14px;"><i class="fas fa-chart-line" style="color: #00B894;"></i> Nouvelles inscriptions — 6 derniers mois</h2>
    </div>
    <div style="padding: 20px;">
        <div style="display: flex; align-items: flex-end; gap: 12px; height: 100px;">
            @php $maxInscriptions = $inscriptionsParMois->max('total') ?: 1; @endphp
            @foreach($inscriptionsParMois as $mois)
            @php $hauteur = round($mois['total'] / $maxInscriptions * 80); @endphp
            <div style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px;">
                <span style="font-size: 12px; font-weight: 800; color: #002B5C;">{{ $mois['total'] }}</span>
                <div style="width: 100%; height: {{ $hauteur }}px; min-height: 6px; background: linear-gradient(135deg, #002B5C, #6C5CE7); border-radius: 6px 6px 0 0; transition: height 0.5s;"></div>
                <span style="font-size: 10px; color: var(--text-3); text-align: center;">{{ $mois['label'] }}</span>
            </div>
            @endforeach
        </div>
    </div>
</div>
@endif

{{-- ══════════════════════════════ LIGNE 4 : ENTREPRISES RÉCENTES ═══════════════════════ --}}
<div class="card" style="margin-bottom: 0;">
    <div class="card-header">
        <h2 style="font-size: 14px;"><i class="fas fa-clock-rotate-left" style="color: #002B5C;"></i> Entreprises récemment inscrites</h2>
        <a href="{{ route('superadmin.entreprises') }}" style="font-size: 12.5px; font-weight: 600; color: var(--primary); text-decoration: none;">
            Voir tout <i class="fas fa-arrow-right"></i>
        </a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Entreprise</th>
                    <th>Secteur</th>
                    <th>Plan</th>
                    <th style="text-align: center;">PDV</th>
                    <th>Modules actifs</th>
                    <th>Inscription</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($entreprisesRecentes as $ent)
                <tr>
                    <td>
                        <div style="font-weight: 700; color: var(--text-1); display: flex; align-items: center; gap: 6px;">
                            {{ $ent->nom }}
                            @if($ent->statut === 'bloque')
                                <span class="badge" style="font-size: 8px; padding: 1px 4px; background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; font-weight: 700; border-radius: 3px;"><i class="fas fa-lock"></i> Suspendue</span>
                            @endif
                        </div>
                        <div style="font-size: 11px; color: var(--text-3);">{{ $ent->email ?? '—' }}</div>
                    </td>
                    <td>
                        @php
                            $secteurs = $ent->secteur_activite ?? [];
                            if (is_string($secteurs)) $secteurs = [$secteurs];
                        @endphp
                        @foreach($secteurs as $s)
                            <span class="badge badge-info" style="font-size: 10px;">{{ $s }}</span>
                        @endforeach
                    </td>
                    <td>
                        <span class="badge badge-purple" style="font-size: 11px;">{{ $ent->plan_abonnement }}</span>
                    </td>
                    <td style="text-align: center;">
                        <span style="font-weight: 800; color: #6C5CE7; font-size: 15px;">{{ $ent->points_de_vente_count }}</span>
                    </td>
                    <td>
                        <div style="display: flex; gap: 3px; flex-wrap: wrap; max-width: 220px;">
                            @if($ent->modules_actifs)
                                @foreach($ent->modules_actifs as $mod)
                                    <span style="font-size: 10px; background: rgba(0,43,92,0.06); border: 1px solid rgba(0,43,92,0.12); color: var(--primary); padding: 2px 5px; border-radius: 4px; font-weight: 600;">{{ \App\Modules\Admin\Modeles\Entreprise::libelleModule($mod) }}</span>
                                @endforeach
                            @else
                                <span style="font-size: 11px; color: var(--text-3);">—</span>
                            @endif
                        </div>
                    </td>
                    <td style="color: var(--text-3); font-size: 12px; white-space: nowrap;">
                        {{ $ent->created_at->format('d/m/Y') }}<br>
                        <span style="font-size: 10px;">{{ $ent->created_at->diffForHumans() }}</span>
                    </td>
                    <td>
                        <a href="{{ route('superadmin.entreprises.modifier', $ent) }}" class="btn btn-outline btn-sm">
                            <i class="fas fa-pen"></i>
                        </a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" style="text-align: center; color: var(--text-3); padding: 30px 0;">
                        <i class="fas fa-building" style="font-size: 32px; opacity: 0.2; display: block; margin-bottom: 8px;"></i>
                        Aucune entreprise enregistrée pour le moment.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

</div>
@endsection

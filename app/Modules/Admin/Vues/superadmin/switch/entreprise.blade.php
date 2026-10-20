@extends('admin::gabarits.application')
@section('titre', $entreprise->nom)
@section('topbar_titre', 'Gouvernance — ' . $entreprise->nom)

@section('contenu')
@php
    $f = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $roles = ['admin' => 'Administrateur', 'admin_secondaire' => 'Admin secondaire', 'responsable_pdv' => 'Responsable PDV', 'caissier' => 'Caissier'];
@endphp
<div style="max-width:1200px; margin:0 auto;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
        <div>
            <a href="{{ route('superadmin.switch.index') }}" style="font-size:12.5px; color:var(--text-3);"><i class="fas fa-arrow-left"></i> Switch entreprise</a>
            <h2 style="font-size:21px; font-weight:800; margin-top:4px;">{{ $entreprise->nom }}
                @if($entreprise->statut === 'bloque')
                    <span style="background:#fee2e2; color:#b91c1c; border-radius:6px; padding:2px 8px; font-size:12px;">Bloquée</span>
                @else
                    <span style="background:#22c55e; color:#fff; border-radius:6px; padding:2px 8px; font-size:12px;">Active</span>
                @endif
            </h2>
        </div>
        <form method="POST" action="{{ route('superadmin.switch.acceder', $entreprise) }}" style="margin:0;">
            @csrf
            <button type="submit" class="btn btn-primary" @disabled($utilisateurs->isEmpty())><i class="fas fa-right-to-bracket"></i> Accéder à son espace</button>
        </form>
    </div>

    {{-- L'essentiel du dossier --}}
    <div class="card" style="padding:16px 20px; margin-bottom:16px;">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px 24px; font-size:13px;">
            @foreach([
                'NCC' => $entreprise->ncc ?: '—',
                'Régime' => $entreprise->regime_imposition ?: '—',
                'RCCM' => $entreprise->rccm ?: '—',
                'Adresse' => $entreprise->adresse ?: '—',
                'Courriel' => $entreprise->email ?: '—',
                'Téléphone' => $entreprise->telephone ?: '—',
                'Offre' => \App\Modules\Admin\Regles\Abonnements::libelle($entreprise->plan_abonnement) . ($entreprise->abonnement_expire_le ? ' · jusqu\'au ' . $entreprise->abonnement_expire_le->format('d/m/Y') : ''),
                'Créée le' => $entreprise->created_at?->format('d/m/Y') ?? '—',
                'Comptabilité' => $entreprise->comptabiliteOuverte() ? 'ouverte' : 'fermée',
                'Articles au catalogue' => $f($articles),
            ] as $libelle => $valeur)
            <div>
                <div style="font-size:11px; text-transform:uppercase; font-weight:700; color:var(--text-3);">{{ $libelle }}</div>
                <div style="font-weight:600;">{{ $valeur }}</div>
            </div>
            @endforeach
        </div>
        <div style="margin-top:12px; font-size:12px; color:var(--text-3);">
            Modules ouverts :
            @foreach((array) ($entreprise->modules_actifs ?? []) as $module)
                <span style="background:var(--bg3, #f1f5f9); border-radius:5px; padding:1px 7px; margin-right:4px; color:var(--text-2); font-weight:600;">{{ \App\Modules\Admin\Modeles\Entreprise::libelleModule($module) }}</span>
            @endforeach
        </div>
    </div>

    {{-- Les points de vente --}}
    <div class="card" style="padding:0; overflow-x:auto; margin-bottom:16px;">
        <h3 style="font-size:14px; font-weight:800; padding:14px 18px; margin:0; border-bottom:1px solid var(--border);">
            <i class="fas fa-store" style="color:#0891b2;"></i> Points de vente ({{ $sites->count() }})
        </h3>
        <table class="table" style="width:100%; font-size:13px;">
            <thead><tr><th>Point de vente</th><th>Lieu</th><th>Affectés</th><th style="text-align:right;">Articles en stock</th><th style="text-align:right;">Entrées de stock</th><th style="text-align:right;">Factures</th><th style="text-align:right;">Chiffre (TTC)</th></tr></thead>
            <tbody>
            @forelse($sites as $site)
                <tr>
                    <td style="font-weight:700;">{{ $site->nom }}</td>
                    <td>{{ trim(($site->commune ?? '') . ', ' . ($site->ville ?? ''), ', ') ?: '—' }}</td>
                    <td style="font-size:12px;">
                        @forelse($site->membres as $m)
                            <div>{{ trim($m->prenom . ' ' . $m->nom) ?: $m->email }} <span style="color:var(--text-3);">· {{ $roles[$m->role] ?? $m->role }}</span></div>
                        @empty
                            <span style="color:var(--text-3);">—</span>
                        @endforelse
                    </td>
                    <td style="text-align:right;">{{ $f($site->articles_en_stock) }}</td>
                    <td style="text-align:right;">{{ $f($site->entrees) }}</td>
                    <td style="text-align:right;">{{ $f($site->ventes) }}</td>
                    <td style="text-align:right; font-weight:700;">{{ $f($site->chiffre) }} F</td>
                </tr>
            @empty
                <tr><td colspan="7" style="padding:24px; text-align:center; color:var(--text-3);">Aucun point de vente.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- Les utilisateurs --}}
    <div class="card" style="padding:0; overflow-x:auto;">
        <h3 style="font-size:14px; font-weight:800; padding:14px 18px; margin:0; border-bottom:1px solid var(--border);">
            <i class="fas fa-users" style="color:#4f46e5;"></i> Utilisateurs ({{ $utilisateurs->count() }})
        </h3>
        <table class="table" style="width:100%; font-size:13px;">
            <thead><tr><th>Nom</th><th>Courriel</th><th>Rôle</th><th>Point de vente</th><th>Connecté</th><th>Dernière connexion</th><th style="text-align:right;"></th></tr></thead>
            <tbody>
            @foreach($utilisateurs as $u)
                <tr>
                    <td style="font-weight:700;">{{ trim($u->prenom . ' ' . $u->nom) ?: '—' }}</td>
                    <td>{{ $u->email }}</td>
                    <td>{{ $roles[$u->role] ?? ucfirst((string) $u->role) }}</td>
                    <td>{{ $u->pointDeVente?->nom ?? '—' }}</td>
                    <td>
                        @if($u->en_ligne)
                            <span class="presence presence-oui" title="Actif il y a moins de {{ \App\Modules\Admin\Controleurs\SuperadminSwitchControleur::MINUTES_EN_LIGNE }} min" style="display:inline-flex; align-items:center; gap:6px; background:#dcfce7; color:#15803d; border-radius:20px; padding:3px 10px; font-size:12px; font-weight:700;"><span style="width:9px; height:9px; border-radius:50%; background:#16a34a;"></span> En ligne</span>
                        @else
                            <span class="presence presence-non" style="display:inline-flex; align-items:center; gap:6px; background:#fee2e2; color:#b91c1c; border-radius:20px; padding:3px 10px; font-size:12px; font-weight:700;"><span style="width:9px; height:9px; border-radius:50%; background:#dc2626;"></span> Hors ligne</span>
                        @endif
                    </td>
                    <td style="font-size:12px;">
                        @if($u->vu_le)
                            <strong>{{ \App\Modules\Admin\Controleurs\SuperadminSwitchControleur::duree($u->duree_derniere_session) }}</strong>
                            <div style="color:var(--text-3);">vu {{ $u->vu_le->diffForHumans() }}</div>
                        @elseif($u->connecte_le)
                            <div style="color:var(--text-3);">connexion le {{ \Illuminate\Support\Carbon::parse($u->connecte_le)->format('d/m/Y H:i') }}</div>
                        @else
                            <span style="color:var(--text-3);">jamais</span>
                        @endif
                    </td>
                    <td style="text-align:right;">
                        @unless($u->estSuperAdmin())
                        <form method="POST" action="{{ route('superadmin.switch.incarner', $u) }}" style="margin:0;">
                            @csrf
                            <button type="submit" class="btn btn-outline btn-sm"><i class="fas fa-user-check"></i> Se connecter</button>
                        </form>
                        @endunless
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection

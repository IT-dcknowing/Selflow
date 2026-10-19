@extends('admin::gabarits.application')
@section('titre', 'Switch entreprise')
@section('topbar_titre', 'Gouvernance — Switch entreprise')

@section('contenu')
@php $f = fn ($n) => number_format((float) $n, 0, ',', ' '); @endphp
<div style="max-width:1200px; margin:0 auto;">
    <p style="font-size:13px; color:var(--text-3); margin-bottom:14px;">
        Basculez dans l'espace d'une entreprise pour l'assister : « Accéder » ouvre le compte de son administrateur principal,
        « Se connecter » celui d'un membre précis. Chaque entrée et chaque action sont tracées à votre nom.
    </p>

    @if(session('succes'))
        <div style="background:#ecfdf5; border:1px solid #6ee7b7; color:#065f46; border-radius:10px; padding:12px 16px; margin-bottom:14px; font-weight:600;">{{ session('succes') }}</div>
    @endif
    @if(session('erreur'))
        <div style="background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; border-radius:10px; padding:12px 16px; margin-bottom:14px; font-weight:600;">{{ session('erreur') }}</div>
    @endif

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:12px; margin-bottom:16px;">
        @foreach([
            ['Total entreprises', $kpi['total'], 'fa-building', '#4f46e5', 'hors corbeille'],
            ['Actives', $kpi['actives'], 'fa-circle-check', '#16a34a', 'opérationnelles'],
            ['Bloquées', $kpi['bloquees'], 'fa-lock', '#dc2626', 'accès suspendu'],
            ['Utilisateurs', $kpi['utilisateurs'], 'fa-users', '#0891b2', 'comptes clients'],
        ] as [$libelle, $valeur, $icone, $couleur, $note])
        <div class="card" style="padding:14px 16px; margin:0;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div style="font-size:11px; text-transform:uppercase; font-weight:700; color:var(--text-3);">{{ $libelle }}</div>
                <span style="width:30px; height:30px; border-radius:8px; background:{{ $couleur }}1a; color:{{ $couleur }}; display:flex; align-items:center; justify-content:center;"><i class="fas {{ $icone }}"></i></span>
            </div>
            <div style="font-size:24px; font-weight:800;">{{ $f($valeur) }}</div>
            <div style="font-size:11px; color:var(--text-3); text-transform:uppercase;">{{ $note }}</div>
        </div>
        @endforeach
    </div>

    <div class="card" style="padding:14px 18px; margin-bottom:14px;">
        <form method="GET" action="{{ route('superadmin.switch.index') }}" style="display:grid; grid-template-columns:2fr 1fr auto; gap:10px; align-items:end;" class="switch-filtres">
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:11px;">Chercher</label>
                <input type="text" name="q" class="form-control" value="{{ $filtres['q'] ?? '' }}" placeholder="Entreprise, nom ou courriel d'un utilisateur…">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:11px;">Statut</label>
                <select name="statut" class="form-control">
                    <option value="">Tous</option>
                    <option value="actif" @selected(($filtres['statut'] ?? '') === 'actif')>Actives</option>
                    <option value="bloque" @selected(($filtres['statut'] ?? '') === 'bloque')>Bloquées</option>
                </select>
            </div>
            <div style="display:flex; gap:6px;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filtrer</button>
                <a href="{{ route('superadmin.switch.index') }}" class="btn btn-outline btn-sm">Effacer</a>
            </div>
        </form>
    </div>

    <div class="card" style="padding:0; overflow-x:auto;">
        <table class="table" style="width:100%; font-size:13px;">
            <thead><tr><th>Entreprise</th><th>Utilisateurs</th><th>Création</th><th>Statut</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            @forelse($entreprises as $e)
                <tr>
                    <td style="font-weight:700;">
                        <span style="display:inline-flex; width:28px; height:28px; border-radius:50%; background:#6366f1; color:#fff; align-items:center; justify-content:center; font-size:11px; margin-right:8px;">{{ mb_strtoupper(mb_substr($e->nom, 0, 2)) }}</span>{{ $e->nom }}
                    </td>
                    <td><span style="background:#64748b; color:#fff; border-radius:6px; padding:2px 8px; font-size:11.5px; font-weight:700;">{{ $e->utilisateurs_count }} utilisateur(s)</span></td>
                    <td>{{ $e->created_at?->format('d/m/y') }}</td>
                    <td>
                        @if($e->statut === 'bloque')
                            <span style="background:#fee2e2; color:#b91c1c; border-radius:6px; padding:2px 8px; font-size:11.5px; font-weight:700;">Bloquée</span>
                        @else
                            <span style="background:#22c55e; color:#fff; border-radius:6px; padding:2px 8px; font-size:11.5px; font-weight:700;">Active</span>
                        @endif
                    </td>
                    <td style="text-align:right; white-space:nowrap;">
                        <form method="POST" action="{{ route('superadmin.switch.acceder', $e) }}" style="display:inline; margin:0;">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-sm" @disabled($e->utilisateurs_count === 0)><i class="fas fa-right-to-bracket"></i> Accéder</button>
                        </form>
                        <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('membres-{{ $e->id }}').toggleAttribute('hidden')" title="Voir les utilisateurs"><i class="fas fa-users"></i></button>
                    </td>
                </tr>
                <tr id="membres-{{ $e->id }}" hidden>
                    <td colspan="5" style="background:var(--bg3, #f1f5f9);">
                        <div style="font-size:12px; font-weight:700; color:var(--text-3); margin-bottom:8px;">Utilisateurs de {{ $e->nom }}</div>
                        <div style="display:flex; flex-wrap:wrap; gap:10px;">
                        @forelse($e->utilisateurs->where('role', '!=', 'superadmin') as $u)
                            <div style="display:flex; align-items:center; gap:12px; background:var(--bg, #fff); border:1px solid var(--border); border-radius:10px; padding:8px 12px;">
                                <div>
                                    <div style="font-weight:700;">{{ trim($u->prenom . ' ' . $u->nom) ?: $u->email }}</div>
                                    <div style="font-size:11px; color:var(--text-3);">{{ $u->email }}</div>
                                    <span style="background:#22c55e; color:#fff; border-radius:5px; padding:1px 6px; font-size:10.5px; font-weight:700;">{{ ['admin' => 'Administrateur', 'admin_secondaire' => 'Admin secondaire', 'responsable_pdv' => 'Responsable PDV', 'caissier' => 'Caissier'][$u->role] ?? ucfirst((string) $u->role) }}</span>
                                </div>
                                <form method="POST" action="{{ route('superadmin.switch.incarner', $u) }}" style="margin:0;">
                                    @csrf
                                    <button type="submit" class="btn btn-outline btn-sm"><i class="fas fa-user-check"></i> Se connecter</button>
                                </form>
                            </div>
                        @empty
                            <span style="color:var(--text-3);">Aucun utilisateur.</span>
                        @endforelse
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" style="padding:30px; text-align:center; color:var(--text-3);">Aucune entreprise pour ces critères.</td></tr>
            @endforelse
            </tbody>
        </table>
        @if($entreprises->hasPages())<div style="padding:12px 16px;">{{ $entreprises->links() }}</div>@endif
    </div>
</div>
<style>@media (max-width: 720px){ .switch-filtres{ grid-template-columns:1fr !important; } }</style>
@endsection

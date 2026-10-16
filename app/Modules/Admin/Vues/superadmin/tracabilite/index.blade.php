@extends('admin::gabarits.application')
@section('titre', 'Maintenance & traçabilité')
@section('topbar_titre', 'SuperAdmin — Maintenance & traçabilité')

@section('contenu')
@php
    $f = fn ($n) => number_format((float) $n, 0, ',', ' ');
    // Rafraîchissement « en direct » toutes les 20 s, sauf en consultant une action.
@endphp
<div style="max-width:1200px; margin:0 auto;" id="tracabilite" data-live="1">

    @if(session('succes'))
        <div style="background:#ecfdf5; border:1px solid #6ee7b7; color:#065f46; border-radius:10px; padding:12px 16px; margin-bottom:14px; font-weight:600;">{{ session('succes') }}</div>
    @endif
    @if(session('erreur') || $errors->any())
        <div style="background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; border-radius:10px; padding:12px 16px; margin-bottom:14px; font-weight:600;">{{ session('erreur') ?? $errors->first() }}</div>
    @endif

    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
        <div style="font-size:12.5px; color:var(--text-3);">
            <i class="fas fa-circle" style="color:{{ $active ? '#16a34a' : '#94a3b8' }}; font-size:8px;"></i>
            {{ $active ? 'Traçabilité active — en direct (24 h glissantes)' : 'Traçabilité en pause' }}
            · <span id="maj-live"></span>
        </div>
        <div style="display:flex; gap:8px;">
            <label style="display:inline-flex; align-items:center; gap:6px; font-size:12.5px; color:var(--text-2);">
                <input type="checkbox" id="auto-refresh" checked> Actualisation auto
            </label>
            <form method="POST" action="{{ route('superadmin.tracabilite.basculer') }}" style="margin:0;">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline">{{ $active ? 'Mettre en pause' : 'Réactiver' }}</button>
            </form>
        </div>
    </div>

    {{-- KPI --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:12px; margin-bottom:18px;">
        @foreach([
            ['Requêtes (24 h)', $kpi['requetes'], 'fa-arrow-right-arrow-left', '#2563eb'],
            ['Visiteurs (IP)', $kpi['visiteurs'], 'fa-users', '#0891b2'],
            ['Comptes connectés', $kpi['connectes'], 'fa-user-check', '#16a34a'],
            ['Entreprises actives', $kpi['entreprises'], 'fa-building', '#7c3aed'],
            ['Temps moyen', $f($kpi['duree_moy']) . ' ms', 'fa-gauge-high', '#d97706'],
            ['Erreurs 5xx', $kpi['erreurs'], 'fa-triangle-exclamation', $kpi['erreurs'] ? '#dc2626' : '#64748b'],
            ['Refus (401/403/429)', $kpi['refus'], 'fa-ban', $kpi['refus'] ? '#b91c1c' : '#64748b'],
        ] as [$label, $valeur, $icone, $couleur])
            <div class="card" style="padding:14px 16px;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span style="width:34px; height:34px; border-radius:9px; background:{{ $couleur }}1a; color:{{ $couleur }}; display:flex; align-items:center; justify-content:center;"><i class="fas {{ $icone }}"></i></span>
                    <div>
                        <div style="font-size:19px; font-weight:800;">{{ is_string($valeur) ? $valeur : $f($valeur) }}</div>
                        <div style="font-size:11px; color:var(--text-3); text-transform:uppercase; font-weight:700; letter-spacing:.3px;">{{ $label }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Graphe : requêtes par heure (24 h) --}}
    <div class="card" style="padding:16px 20px; margin-bottom:18px;">
        <h3 style="font-size:14px; font-weight:800; margin-bottom:12px;"><i class="fas fa-chart-column" style="color:var(--primary);"></i> Requêtes par heure (24 h)</h3>
        <div style="display:flex; align-items:flex-end; gap:3px; height:140px;">
            @foreach($graphe as $barre)
                @php $h = (int) round(($barre['valeur'] / $grapheMax) * 120); @endphp
                <div style="flex:1; display:flex; flex-direction:column; align-items:center; gap:4px;" title="{{ $barre['label'] }} : {{ $f($barre['valeur']) }} requêtes">
                    <div style="font-size:9px; color:var(--text-3);">{{ $barre['valeur'] ?: '' }}</div>
                    <div style="width:100%; height:{{ max(2, $h) }}px; background:linear-gradient(180deg,#3b82f6,#1d4ed8); border-radius:3px 3px 0 0;"></div>
                    <div style="font-size:9px; color:var(--text-3);">{{ $barre['label'] }}</div>
                </div>
            @endforeach
        </div>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:18px;" class="traca-grille">
        {{-- Pages les plus consultées --}}
        <div class="card" style="padding:0; overflow:hidden;">
            <h3 style="font-size:14px; font-weight:800; padding:14px 18px; margin:0; border-bottom:1px solid var(--border);"><i class="fas fa-fire" style="color:#ea580c;"></i> Pages les plus consultées (7 j)</h3>
            <div style="overflow-x:auto;">
            <table class="table" style="width:100%; font-size:12.5px;">
                <thead><tr><th>Page</th><th>Vues</th><th>Temps moyen</th></tr></thead>
                <tbody>
                @forelse($pages as $p)
                    <tr><td style="word-break:break-all;">/{{ $p->chemin }}</td><td>{{ $f($p->vues) }}</td><td>{{ $f($p->duree_moy) }} ms</td></tr>
                @empty
                    <tr><td colspan="3" style="padding:20px; text-align:center; color:var(--text-3);">Aucune visite enregistrée.</td></tr>
                @endforelse
                </tbody>
            </table>
            </div>
        </div>

        {{-- IP bloquées --}}
        <div class="card" style="padding:0; overflow:hidden;">
            <h3 style="font-size:14px; font-weight:800; padding:14px 18px; margin:0; border-bottom:1px solid var(--border);"><i class="fas fa-shield-halved" style="color:#b91c1c;"></i> Adresses bloquées</h3>
            <div style="padding:14px 18px; border-bottom:1px solid var(--border);">
                <form method="POST" action="{{ route('superadmin.tracabilite.bloquer') }}" style="display:flex; gap:8px; flex-wrap:wrap;">
                    @csrf
                    <input type="text" name="ip" class="form-control" style="flex:1; min-width:140px;" placeholder="Adresse IP à bloquer" required>
                    <input type="text" name="motif" class="form-control" style="flex:1.5; min-width:140px;" placeholder="Motif (facultatif)">
                    <button type="submit" class="btn btn-sm" style="background:#dc2626; color:#fff; border:none;"><i class="fas fa-ban"></i> Bloquer</button>
                </form>
            </div>
            <div style="overflow-x:auto; max-height:260px;">
            <table class="table" style="width:100%; font-size:12.5px;">
                <tbody>
                @forelse($bloquees as $b)
                    <tr>
                        <td style="font-family:ui-monospace,monospace; font-weight:700;">{{ $b->ip }}</td>
                        <td style="color:var(--text-3);">{{ $b->motif ?: '—' }}</td>
                        <td style="text-align:right;">
                            <form method="POST" action="{{ route('superadmin.tracabilite.debloquer', $b->ip) }}" style="margin:0;">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline"><i class="fas fa-lock-open"></i> Débloquer</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td style="padding:20px; text-align:center; color:var(--text-3);">Aucune adresse bloquée.</td></tr>
                @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </div>

    {{-- Activité par adresse IP --}}
    <div class="card" style="padding:0; overflow:hidden;">
        <h3 style="font-size:14px; font-weight:800; padding:14px 18px; margin:0; border-bottom:1px solid var(--border);"><i class="fas fa-network-wired" style="color:var(--primary);"></i> Activité par adresse IP (24 h)</h3>
        <div style="overflow-x:auto;">
        <table class="table" style="width:100%; font-size:12.5px;">
            <thead><tr><th>Adresse IP</th><th>Requêtes</th><th>Pages</th><th>Erreurs</th><th>Dernière</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            @forelse($ips as $ip)
                @php $estBloquee = isset($listeBloquees[$ip->ip]); @endphp
                <tr style="{{ $estBloquee ? 'background:#fef2f2;' : ($ip->erreurs > 20 ? 'background:#fffbeb;' : '') }}">
                    <td style="font-family:ui-monospace,monospace; font-weight:700;">
                        <a href="{{ route('superadmin.tracabilite.ip', $ip->ip) }}" style="color:var(--primary);">{{ $ip->ip }}</a>
                        @if($estBloquee)<span style="color:#b91c1c; font-size:10px; font-weight:800;"> · BLOQUÉE</span>@endif
                    </td>
                    <td>{{ $f($ip->requetes) }}</td>
                    <td>{{ $f($ip->pages) }}</td>
                    <td style="color:{{ $ip->erreurs > 0 ? '#b91c1c' : 'var(--text-3)' }};">{{ $f($ip->erreurs) }}</td>
                    <td style="white-space:nowrap;">{{ \Illuminate\Support\Carbon::parse($ip->derniere)->format('d/m H:i') }}</td>
                    <td style="text-align:right; white-space:nowrap;">
                        @if($estBloquee)
                        <form method="POST" action="{{ route('superadmin.tracabilite.debloquer', $ip->ip) }}" style="display:inline; margin:0;">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline"><i class="fas fa-lock-open"></i> Débloquer</button>
                        </form>
                        @else
                        <form method="POST" action="{{ route('superadmin.tracabilite.bloquer') }}" style="display:inline; margin:0;"
                              onsubmit="return confirm('Bloquer {{ $ip->ip }} ? Elle n\'aura plus accès à la plateforme.');">
                            @csrf
                            <input type="hidden" name="ip" value="{{ $ip->ip }}">
                            <button type="submit" class="btn btn-sm" style="background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5;"><i class="fas fa-ban"></i> Bloquer</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" style="padding:24px; text-align:center; color:var(--text-3);">Aucune activité enregistrée sur les dernières 24 h.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>

<style>@media (max-width: 820px){ .traca-grille{ grid-template-columns:1fr !important; } }</style>
<script>
(function () {
    var maj = document.getElementById('maj-live');
    if (maj) maj.textContent = 'mis à jour à ' + new Date().toLocaleTimeString('fr-FR');
    var minuteur = setTimeout(function () {
        if (document.getElementById('auto-refresh')?.checked) location.reload();
    }, 20000);
    document.getElementById('auto-refresh')?.addEventListener('change', function () {
        if (!this.checked) clearTimeout(minuteur);
    });
})();
</script>
@endsection

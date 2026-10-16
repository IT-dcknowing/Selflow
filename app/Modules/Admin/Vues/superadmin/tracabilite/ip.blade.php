@extends('admin::gabarits.application')
@section('titre', 'Activité de ' . $ip)
@section('topbar_titre', 'SuperAdmin — Activité de ' . $ip)

@section('contenu')
@php $f = fn ($n) => number_format((float) $n, 0, ',', ' '); @endphp
<div style="max-width:1100px; margin:0 auto;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
        <div>
            <a href="{{ route('superadmin.tracabilite.index') }}" style="font-size:12.5px; color:var(--text-3);"><i class="fas fa-arrow-left"></i> Retour</a>
            <h2 style="font-size:20px; font-weight:800; font-family:ui-monospace,monospace;">{{ $ip }}
                @if($estBloquee)<span style="color:#b91c1c; font-size:13px;"> · BLOQUÉE</span>@endif
            </h2>
        </div>
        @if($estBloquee)
        <form method="POST" action="{{ route('superadmin.tracabilite.debloquer', $ip) }}" style="margin:0;">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-outline"><i class="fas fa-lock-open"></i> Débloquer</button>
        </form>
        @else
        <form method="POST" action="{{ route('superadmin.tracabilite.bloquer') }}" style="margin:0;"
              onsubmit="return confirm('Bloquer {{ $ip }} ?');">
            @csrf
            <input type="hidden" name="ip" value="{{ $ip }}">
            <button type="submit" class="btn" style="background:#dc2626; color:#fff; border:none;"><i class="fas fa-ban"></i> Bloquer cette adresse</button>
        </form>
        @endif
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:18px;" class="traca-grille">
        <div class="card" style="padding:0; overflow:hidden;">
            <h3 style="font-size:14px; font-weight:800; padding:14px 18px; margin:0; border-bottom:1px solid var(--border);">Pages consultées (7 j)</h3>
            <div style="overflow-x:auto;">
            <table class="table" style="width:100%; font-size:12.5px;">
                <thead><tr><th>Page</th><th>Vues</th><th>Temps moyen</th></tr></thead>
                <tbody>
                @forelse($pages as $p)
                    <tr><td style="word-break:break-all;">/{{ $p->chemin }}</td><td>{{ $f($p->vues) }}</td><td>{{ $f($p->duree_moy) }} ms</td></tr>
                @empty
                    <tr><td colspan="3" style="padding:18px; text-align:center; color:var(--text-3);">—</td></tr>
                @endforelse
                </tbody>
            </table>
            </div>
        </div>

        <div class="card" style="padding:0; overflow:hidden;">
            <h3 style="font-size:14px; font-weight:800; padding:14px 18px; margin:0; border-bottom:1px solid var(--border);">Temps estimé par page (7 j)</h3>
            <p style="font-size:11px; color:var(--text-3); padding:8px 18px 0; margin:0;">Estimé à partir de l'écart entre requêtes successives — approximation des journaux serveur.</p>
            <div style="overflow-x:auto;">
            <table class="table" style="width:100%; font-size:12.5px;">
                <tbody>
                @forelse($tempsEstime as $chemin => $secondes)
                    <tr><td style="word-break:break-all;">/{{ $chemin }}</td><td style="white-space:nowrap;">{{ $f(round($secondes / 60, 1)) }} min</td></tr>
                @empty
                    <tr><td style="padding:18px; text-align:center; color:var(--text-3);">—</td></tr>
                @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <div class="card" style="padding:0; overflow:hidden;">
        <h3 style="font-size:14px; font-weight:800; padding:14px 18px; margin:0; border-bottom:1px solid var(--border);">Journal des requêtes</h3>
        <div style="overflow-x:auto;">
        <table class="table" style="width:100%; font-size:12px;">
            <thead><tr><th>Heure</th><th>Méthode</th><th>Page</th><th>Statut</th><th>Durée</th><th>Compte</th></tr></thead>
            <tbody>
            @foreach($visites as $v)
                <tr>
                    <td style="white-space:nowrap;">{{ $v->cree_le->format('d/m H:i:s') }}</td>
                    <td><span style="color:var(--text-3); font-weight:700;">{{ $v->methode }}</span></td>
                    <td style="word-break:break-all;">/{{ $v->chemin }}</td>
                    <td style="color:{{ $v->statut >= 400 ? '#b91c1c' : 'var(--text-2)' }}; font-weight:700;">{{ $v->statut }}</td>
                    <td style="white-space:nowrap;">{{ $f($v->duree_ms) }} ms</td>
                    <td style="font-size:11px; color:var(--text-3);">{{ $v->utilisateur?->email ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
        <div style="padding:12px 16px;">{{ $visites->links() }}</div>
    </div>
</div>
<style>@media (max-width: 820px){ .traca-grille{ grid-template-columns:1fr !important; } }</style>
@endsection

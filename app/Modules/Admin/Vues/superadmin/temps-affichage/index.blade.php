@extends('admin::gabarits.application')
@section('titre', "Temps d'affichage")
@section('topbar_titre', "Superadmin · Temps d'affichage")

@section('contenu')
@php
    $fmt = fn ($ms) => $ms === null ? '·' : ($ms >= 1000 ? number_format($ms / 1000, 1, ',', ' ') . ' s' : number_format($ms, 0, ',', ' ') . ' ms');
    $teinte = fn ($ms) => $ms === null ? 'var(--text-3)' : ($ms >= 3000 ? '#b91c1c' : ($ms >= 1000 ? '#b45309' : '#047857'));
    // Un nom de route lisible : « admin.ventes.imprimer » → « ventes › imprimer ».
    $lisible = fn ($r) => str_replace('.', ' › ', preg_replace('/^(admin|caissier|superadmin)\./', '', (string) $r));
@endphp

<form method="GET" class="card" style="padding:12px 16px; display:flex; gap:10px; flex-wrap:wrap; align-items:end; margin-bottom:14px;">
    <label style="font-size:12px; font-weight:700;">Période
        <select name="jours" class="form-control">
            @foreach([1 => "24 heures", 7 => '7 jours', 30 => '30 jours'] as $j => $l)
                <option value="{{ $j }}" @selected($jours === $j)>{{ $l }}</option>
            @endforeach
        </select>
    </label>
    <label style="font-size:12px; font-weight:700; min-width:240px;">Entreprise
        <select name="entreprise" class="form-control">
            <option value="">Toutes les entreprises</option>
            @foreach($entreprises as $e)
                <option value="{{ $e->id }}" @selected($entrepriseId === $e->id)>{{ $e->nom }}</option>
            @endforeach
        </select>
    </label>
    <div style="font-size:12px; color:var(--text-3); line-height:1.6; flex:1; min-width:260px;">
        <strong style="color:#047857;">vert</strong> moins d'une seconde,
        <strong style="color:#b45309;">orange</strong> 1 à 3 s,
        <strong style="color:#b91c1c;">rouge</strong> plus de 3 s.
        « Serveur » : le temps de calcul de la page. « Affichage » : le temps que l'utilisateur attend avant de la voir.
    </div>
</form>

@unless($mesure)
    <div class="card" style="padding:14px 16px; color:#92400e; background:#fffbeb; margin-bottom:14px;">
        La mesure démarre après <code>php artisan migrate</code> : la colonne <code>visites.route</code> n'existe pas encore.
    </div>
@endunless

@foreach(['Pages (affichage)' => $pages, 'Boutons et actions (enregistrer, valider, supprimer…)' => $boutons] as $titre => $lignes)
<div class="card" style="padding:0; margin-bottom:16px; overflow-x:auto;">
    <div style="padding:12px 16px; border-bottom:1px solid var(--border);">
        <strong>{{ $titre }}</strong>
        <span style="font-size:12px; color:var(--text-3);"> · les plus lentes d'abord · {{ $lignes->count() }} ligne(s)</span>
    </div>
    <table class="table" style="width:100%; font-size:13px;">
        <thead><tr>
            <th>Page ou action</th><th style="text-align:right;">Nombre</th>
            <th style="text-align:right;">Serveur, moyenne</th><th style="text-align:right;">Serveur, pire</th>
            @if($loop->first)<th style="text-align:right;">Affichage, moyenne</th><th style="text-align:right;">Affichage, pire</th>@endif
            <th style="text-align:right;">Erreurs</th>
        </tr></thead>
        <tbody>
        @forelse($lignes as $l)
            @php $a = $affichage[$l->route] ?? null; $moyA = $a && $a->nb ? $a->total / $a->nb : null; @endphp
            <tr>
                <td><span style="font-weight:600;">{{ $lisible($l->route) }}</span>
                    @unless($loop->parent->first)<span style="font-size:11px; color:var(--text-3);"> · {{ $l->methode }}</span>@endunless
                    <div style="font-size:11px; color:var(--text-3); font-family:ui-monospace,monospace;">{{ $l->route }}</div></td>
                <td style="text-align:right;">{{ number_format($l->nb, 0, ',', ' ') }}</td>
                <td style="text-align:right; font-weight:700; color:{{ $teinte($l->moyenne) }};">{{ $fmt($l->moyenne) }}</td>
                <td style="text-align:right; color:{{ $teinte($l->maxi) }};">{{ $fmt($l->maxi) }}</td>
                @if($loop->parent->first)
                    <td style="text-align:right; font-weight:700; color:{{ $teinte($moyA) }};">{{ $fmt($moyA) }}</td>
                    <td style="text-align:right; color:{{ $teinte($a->maxi ?? null) }};">{{ $fmt($a->maxi ?? null) }}</td>
                @endif
                <td style="text-align:right; color:{{ $l->erreurs ? '#b91c1c' : 'var(--text-3)' }};">{{ $l->erreurs ?: '·' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" style="color:var(--text-3); padding:14px 16px;">Aucune mesure sur la période.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endforeach
@endsection

{{--
    Le choix du point de vente consulté (20/10/2026) : tous, ou un seul. Un
    membre affecté à un site voit son site, sans choix.
    La vue appelante fournit `$filtreSite` (SiteConsulte::pourLaVue).
--}}
@if(($filtreSite['sites'] ?? collect())->count() > 1)
<form method="GET" class="filtre-site" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin:0 0 14px;">
    @foreach(request()->except(['site', 'page']) as $cle => $valeur)
        @if(is_scalar($valeur))<input type="hidden" name="{{ $cle }}" value="{{ $valeur }}">@endif
    @endforeach
    <label style="font-size:12.5px; font-weight:700; color:var(--text-2);"><i class="fas fa-store" style="color:var(--primary);"></i> Point de vente :</label>
    @if($filtreSite['verrouille'])
        <strong style="font-size:13px;">{{ $filtreSite['sites']->firstWhere('id', $filtreSite['site'])?->nom }}</strong>
    @else
        <select name="site" class="form-control" style="width:auto; min-width:220px;" onchange="this.form.submit()">
            <option value="tout" @selected($filtreSite['site'] === null)>Tous les points de vente</option>
            @foreach($filtreSite['sites'] as $s)
                <option value="{{ $s->id }}" @selected($filtreSite['site'] === $s->id)>{{ $s->nom }}</option>
            @endforeach
        </select>
    @endif
</form>
@endif

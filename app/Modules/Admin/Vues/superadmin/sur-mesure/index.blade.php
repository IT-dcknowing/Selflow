@extends('admin::gabarits.application')
@section('titre', 'Selflow sur mesure')
@section('topbar_titre', 'Superadmin · Selflow sur mesure')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-wand-magic-sparkles"></i> Selflow sur mesure</h1>
        <p>Une adresse propre à l'entreprise, sa page d'accueil et sa page de connexion à ses couleurs. Même application, même base : l'adresse choisit l'habillage.</p>
    </div>
</div>

@php
    $actifs = $espaces->where('actif', true)->count();
    $enPreparation = $espaces->where('actif', false)->count();
@endphp
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; margin-bottom:18px;">
    <div class="card" style="padding:16px;"><div style="font-size:12px; color:#64748b; font-weight:700;">ESPACES ACTIFS</div><div style="font-size:26px; font-weight:800; color:#047857;">{{ $actifs }}</div></div>
    <div class="card" style="padding:16px;"><div style="font-size:12px; color:#64748b; font-weight:700;">EN PRÉPARATION</div><div style="font-size:26px; font-weight:800; color:#b45309;">{{ $enPreparation }}</div></div>
</div>

<div class="card" style="padding:0; overflow:hidden;">
    <div style="padding:14px 18px; border-bottom:1px solid #e2e8f0; display:flex; gap:10px; align-items:center;">
        <i class="fas fa-magnifying-glass" style="color:#94a3b8;"></i>
        <input id="filtre-sur-mesure" class="form-control" placeholder="Rechercher une entreprise" style="max-width:320px;">
    </div>
    <div style="overflow-x:auto;">
    <table class="table" style="width:100%;">
        <thead><tr><th>Entreprise</th><th>Adresses</th><th>Modules</th><th>État</th><th></th></tr></thead>
        <tbody>
        @foreach($entreprises as $entreprise)
            @php $espace = $espaces->get($entreprise->id); @endphp
            <tr class="ligne-sur-mesure" data-nom="{{ \Illuminate\Support\Str::lower($entreprise->nom . ' ' . $entreprise->ncc) }}">
                <td>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span style="width:12px; height:12px; border-radius:4px; background:{{ $espace->couleur_principale ?? '#e2e8f0' }};"></span>
                        <div><strong>{{ $entreprise->nom }}</strong><div style="font-size:12px; color:#64748b;">{{ $entreprise->ncc ?: 'NCC non renseigné' }}</div></div>
                    </div>
                </td>
                <td style="font-size:13px;">
                    @forelse($espace?->adresses() ?? [] as $adresse)
                        <div><i class="fas fa-globe" style="color:#94a3b8;"></i> {{ $adresse }}</div>
                    @empty
                        <span style="color:#94a3b8;">Aucune</span>
                    @endforelse
                </td>
                <td style="font-size:12px;">
                    @foreach((array) ($espace->modules ?? []) as $module)
                        <span style="display:inline-block; margin:2px; padding:2px 8px; border-radius:20px; background:#eff6ff; color:#1d4ed8; font-weight:700;">{{ \App\Modules\Admin\Modeles\EspaceSurMesure::MODULES[$module][0] ?? $module }}</span>
                    @endforeach
                </td>
                <td>
                    @if(!$espace)
                        <span style="color:#94a3b8; font-size:12px; font-weight:700;">Standard</span>
                    @elseif($espace->actif)
                        <span style="color:#047857; font-size:12px; font-weight:800;"><i class="fas fa-circle" style="font-size:8px;"></i> Actif</span>
                    @else
                        <span style="color:#b45309; font-size:12px; font-weight:800;"><i class="fas fa-circle" style="font-size:8px;"></i> En préparation</span>
                    @endif
                </td>
                <td style="text-align:right;">
                    <a href="{{ route('superadmin.sur_mesure.modifier', $entreprise) }}" class="btn {{ $espace ? 'btn-outline' : 'btn-primary' }} btn-sm">
                        <i class="fas {{ $espace ? 'fa-pen' : 'fa-plus' }}"></i> {{ $espace ? 'Configurer' : 'Créer l\'espace' }}
                    </a>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
</div>

<script>
document.getElementById('filtre-sur-mesure').addEventListener('input', function () {
    var q = this.value.trim().toLowerCase();
    document.querySelectorAll('.ligne-sur-mesure').forEach(function (l) {
        l.style.display = !q || l.dataset.nom.indexOf(q) !== -1 ? '' : 'none';
    });
});
</script>
@endsection

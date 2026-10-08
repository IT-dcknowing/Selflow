@extends('admin::gabarits.application')
@section('titre', 'Ordre de production ' . $ordre->code_ordre)
@section('topbar_titre', 'Production — ' . $ordre->code_ordre)

@section('contenu')
@use('App\Modules\Admin\Modeles\OrdreProduction')
@php
    $cout = $ordre->coutDeRevient();
    $coutUnitaire = $ordre->coutUnitaireDeRevient();
@endphp
<div class="page-header">
    <div>
        <h1><i class="fas fa-industry" style="color:var(--primary); margin-right:8px;"></i> {{ $ordre->code_ordre }}</h1>
        <p>{{ $ordre->produitFini->nom }} — {{ OrdreProduction::quantiteLisible($ordre->quantite_cible) }} {{ $ordre->produitFini->unite ?? 'Unité' }}, {{ $ordre->pointDeVente->nom }}, le {{ $ordre->date_production->format('d/m/Y') }}</p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <a href="{{ route('admin.production.ordres.index') }}" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Retour</a>
        <a href="{{ route('admin.production.ordres.imprimer', $ordre) }}" target="_blank" class="btn btn-outline"><i class="fas fa-print"></i> Bon de fabrication</a>
        @if($ordre->estBrouillon())
            <form method="POST" action="{{ route('admin.production.ordres.valider', $ordre) }}" style="display:inline;"
                  onsubmit="return confirm('Fabriquer {{ OrdreProduction::quantiteLisible($ordre->quantite_cible) }} {{ addslashes($ordre->produitFini->nom) }} ? Les matières sortiront du stock et le produit fini y entrera.')">
                @csrf
                <button type="submit" class="btn btn-success"><i class="fas fa-check-circle"></i> Produire & Valider</button>
            </form>
        @endif
        @if($ordre->estBrouillon() || $ordre->estTermine())
            <form method="POST" action="{{ route('admin.production.ordres.annuler', $ordre) }}" style="display:inline;"
                  onsubmit="return confirm('{{ $ordre->estTermine() ? 'Annuler cet ordre terminé ? La fabrication sera contre-passée : le produit fini sortira du stock et les matières y reviendront.' : 'Annuler cet ordre ? Rien ne sera fabriqué.' }}')">
                @csrf
                <button type="submit" class="btn btn-outline" style="color:var(--danger); border-color:var(--danger);"><i class="fas fa-ban"></i> Annuler l'ordre</button>
            </form>
        @endif
    </div>
</div>

@foreach(['succes' => 'alert-success', 'info' => 'alert-warning', 'erreur' => 'alert-danger'] as $cle => $classe)
    @if(session($cle))
        <div class="alert {{ $classe }}" style="margin-bottom:20px;">{{ session($cle) }}</div>
    @endif
@endforeach

<div class="card" style="margin-bottom:20px;">
    <div class="card-body" style="display:flex; gap:32px; flex-wrap:wrap;">
        <div><div style="font-size:11px; color:var(--text-3); text-transform:uppercase;">Statut</div><strong>{{ $ordre->statut }}</strong></div>
        <div><div style="font-size:11px; color:var(--text-3); text-transform:uppercase;">Quantité à produire</div><strong>{{ OrdreProduction::quantiteLisible($ordre->quantite_cible) }} {{ $ordre->produitFini->unite ?? 'Unité' }}</strong></div>
        <div><div style="font-size:11px; color:var(--text-3); text-transform:uppercase;">Coût de revient</div><strong>{{ $cout !== null ? number_format($cout, 0, ',', ' ') . ' F' : '—' }}</strong></div>
        <div><div style="font-size:11px; color:var(--text-3); text-transform:uppercase;">Coût unitaire du produit fini</div><strong>{{ $coutUnitaire !== null ? number_format($coutUnitaire, 2, ',', ' ') . ' F' : '—' }}</strong></div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Matières</h2></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Matière</th>
                    <th style="text-align:right;">Recette (par unité)</th>
                    <th style="text-align:right;">Besoin</th>
                    <th style="text-align:right;">Consommé</th>
                    <th style="text-align:right;">Coût unitaire</th>
                    <th style="text-align:right;">Coût</th>
                </tr>
            </thead>
            <tbody>
                @forelse($lignes as $l)
                    <tr>
                        <td>{{ $l['ingredient']?->nom ?? '—' }} <span style="color:var(--text-3);">{{ $l['ingredient']?->reference }}</span></td>
                        <td style="text-align:right;">{{ $l['quantite_recette'] !== null ? OrdreProduction::quantiteLisible($l['quantite_recette'], 4) . ' ' . $l['unite_recette'] : '—' }}</td>
                        <td style="text-align:right;">{{ OrdreProduction::quantiteLisible($l['quantite']) }} {{ $l['unite'] }}</td>
                        <td style="text-align:right;">{{ $l['consomme'] !== null ? OrdreProduction::quantiteLisible($l['consomme']) . ' ' . $l['unite'] : '—' }}</td>
                        <td style="text-align:right;">{{ $l['cout_unitaire'] !== null ? number_format($l['cout_unitaire'], 2, ',', ' ') . ' F' : '—' }}</td>
                        <td style="text-align:right;">{{ $l['cout'] !== null ? number_format($l['cout'], 0, ',', ' ') . ' F' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center; color:var(--text-3);">Aucune matière.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

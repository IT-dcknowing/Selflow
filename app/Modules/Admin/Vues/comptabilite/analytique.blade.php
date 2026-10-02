@extends('admin::gabarits.application')
@section('titre', 'Résultat par site')
@section('topbar_titre', 'Rapports — Résultat par site')

@php
    $sites  = $ventilation['sites'];
    $totaux = $ventilation['totaux'];
    $orphelines = $ventilation['non_ventile'];

    // L'échelle des barres se prend sur le plus gros produit ou la plus grosse
    // charge : sans référence commune, deux sites de tailles très différentes
    // se liraient comme s'ils pesaient pareil.
    $echelle = 0;
    foreach (array_merge($sites, $orphelines ? [$orphelines] : []) as $l) {
        $echelle = max($echelle, abs($l['produits']), abs($l['charges']));
    }
    $largeur = fn ($valeur) => $echelle > 0 ? round(abs($valeur) / $echelle * 100) : 0;
@endphp

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-store"></i> Résultat par site</h1>
        <p>Ce que chaque point de vente a produit et coûté. {{ $libellePeriode }}.</p>
    </div>
</div>

<div style="margin-bottom:18px; padding:14px 18px; background:#eff6ff; border:1px solid #93c5fd; border-radius:10px; color:#1e3a8a;">
    <strong><i class="fas fa-circle-info"></i> Ce que cet écran ventile, et ce qu'il ne ventile pas.</strong>
    <div style="font-size:12.5px; margin-top:6px; line-height:1.6;">
        Chaque écriture porte le site où sa pièce a été établie. Les produits (classe 7)
        et les charges (classe 6) sont donc répartis <strong>sans clé et sans hypothèse</strong> :
        ce que vous lisez a réellement été écrit là.
        <br>
        En revanche, une charge de siège — un loyer, un salaire administratif — reste au
        site où elle a été saisie. <strong>Elle n'est pas répartie entre les magasins</strong>,
        parce que la clé de répartition n'existe nulle part dans l'application et que
        l'inventer donnerait un résultat faux que rien ne signalerait.
    </div>
</div>

<form method="GET" action="{{ route('admin.comptabilite.analytique') }}" style="margin-bottom:18px;">
    <div class="card" style="padding:14px 16px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <label class="form-label" style="margin:0; font-weight:700;">Mois</label>
        <select name="filtre_mois" class="form-control" style="width:auto;" onchange="this.form.submit()">
            <option value="tous">Tout l'exercice</option>
            @foreach(range(1, 12) as $numeroMois)
                <option value="{{ $numeroMois }}"
                    {{ (string) request('filtre_mois') === (string) $numeroMois ? 'selected' : '' }}>
                    {{ ucfirst(\Carbon\Carbon::create()->month($numeroMois)->locale('fr')->monthName) }}
                </option>
            @endforeach
        </select>
        <noscript><button type="submit" class="btn btn-outline">Afficher</button></noscript>
    </div>
</form>

{{-- Une écriture sans site rend la comparaison incomplète. Le taire ferait que
     la somme des sites ne vaudrait pas le résultat de l'entreprise, sans que
     rien ne l'explique. --}}
@if($orphelines)
    <div style="margin-bottom:18px; padding:14px 18px; background:#fffbeb; border:1px solid #fcd34d; border-radius:10px; color:#92400e;">
        <strong><i class="fas fa-triangle-exclamation"></i>
            {{ $orphelines['ecritures'] }} écriture(s) ne sont rattachées à aucun site.</strong>
        <div style="font-size:12.5px; margin-top:4px;">
            Elles pèsent {{ number_format($orphelines['resultat'], 0, ',', ' ') }} F au résultat et
            figurent en bas du tableau, sous « Sans site ». Tous les écrans en posent un :
            ces lignes viennent d'une reprise d'historique ou d'un import.
        </div>
    </div>
@endif

<div class="card" style="padding:0; overflow:hidden;">
    <div style="overflow-x:auto;">
        <table class="table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:var(--bg3); text-align:left;">
                    <th style="padding:10px 14px;">Site</th>
                    <th style="padding:10px 14px; text-align:right; width:160px;">Produits</th>
                    <th style="padding:10px 14px; text-align:right; width:160px;">Charges</th>
                    <th style="padding:10px 14px; text-align:right; width:170px;">Résultat</th>
                    <th style="padding:10px 14px; width:180px;">Poids</th>
                    <th style="padding:10px 14px; text-align:right; width:100px;">Écritures</th>
                </tr>
            </thead>
            <tbody>
                @forelse(array_merge($sites, $orphelines ? [$orphelines] : []) as $ligne)
                    <tr style="border-top:1px solid var(--border);">
                        <td style="padding:9px 14px; font-weight:{{ $ligne['id'] ? 600 : 400 }};
                                   color:{{ $ligne['id'] ? 'var(--text-1)' : 'var(--text-3)' }};">
                            @if($ligne['id'])
                                <i class="fas fa-store" style="opacity:.5;"></i>
                            @else
                                <i class="fas fa-circle-question" style="opacity:.5;"></i>
                            @endif
                            {{ $ligne['nom'] }}
                        </td>
                        <td style="padding:9px 14px; text-align:right;">
                            {{ number_format($ligne['produits'], 0, ',', ' ') }} F
                        </td>
                        <td style="padding:9px 14px; text-align:right;">
                            {{ number_format($ligne['charges'], 0, ',', ' ') }} F
                        </td>
                        <td style="padding:9px 14px; text-align:right; font-weight:800;
                                   color:{{ $ligne['resultat'] >= 0 ? '#1e7a4c' : '#a32c2c' }};">
                            {{ number_format($ligne['resultat'], 0, ',', ' ') }} F
                        </td>
                        <td style="padding:9px 14px;">
                            <div style="display:flex; flex-direction:column; gap:3px;">
                                <div style="height:7px; background:#e8edf3; border-radius:4px; overflow:hidden;">
                                    <div style="height:100%; width:{{ $largeur($ligne['produits']) }}%; background:#1e7a4c;"></div>
                                </div>
                                <div style="height:7px; background:#e8edf3; border-radius:4px; overflow:hidden;">
                                    <div style="height:100%; width:{{ $largeur($ligne['charges']) }}%; background:#a8621b;"></div>
                                </div>
                            </div>
                        </td>
                        <td style="padding:9px 14px; text-align:right; color:var(--text-3);">
                            {{ $ligne['ecritures'] }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center; padding:26px; color:var(--text-3);">
                        Aucun point de vente n'est enregistré.
                    </td></tr>
                @endforelse
            </tbody>
            @if($totaux['ecritures'] > 0)
                <tfoot>
                    <tr style="border-top:2px solid var(--border); background:var(--bg3); font-weight:800;">
                        <td style="padding:12px 14px;">Entreprise entière</td>
                        <td style="padding:12px 14px; text-align:right;">{{ number_format($totaux['produits'], 0, ',', ' ') }} F</td>
                        <td style="padding:12px 14px; text-align:right;">{{ number_format($totaux['charges'], 0, ',', ' ') }} F</td>
                        <td style="padding:12px 14px; text-align:right;
                                   color:{{ $totaux['resultat'] >= 0 ? '#1e7a4c' : '#a32c2c' }};">
                            {{ number_format($totaux['resultat'], 0, ',', ' ') }} F
                        </td>
                        <td></td>
                        <td style="padding:12px 14px; text-align:right;">{{ $totaux['ecritures'] }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>

<p style="font-size:12px; color:var(--text-3); margin:12px 0 30px;">
    <span style="display:inline-block; width:10px; height:7px; background:#1e7a4c; border-radius:3px;"></span> Produits
    &nbsp;·&nbsp;
    <span style="display:inline-block; width:10px; height:7px; background:#a8621b; border-radius:3px;"></span> Charges
    &nbsp;·&nbsp;
    Les barres se lisent les unes par rapport aux autres, à l'échelle du plus gros montant de la période.
</p>
@endsection

{{--
    Le bon de fabrication : ce que l'atelier doit sortir du magasin pour un
    ordre, et — une fois l'ordre validé — ce que la fabrication a coûté.
--}}
@use('App\Modules\Admin\Modeles\OrdreProduction')
@php
    $cout = $ordre->coutDeRevient();
    $coutUnitaire = $ordre->coutUnitaireDeRevient();
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bon de fabrication {{ $ordre->code_ordre }}</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; color:#111827; margin:0; background:#f3f4f6; }
        .feuille { background:#fff; max-width:820px; margin:24px auto; padding:36px 40px; border:1px solid #e5e7eb; }
        .tete { display:flex; justify-content:space-between; gap:24px; border-bottom:2px solid #0D1B3E; padding-bottom:14px; margin-bottom:20px; }
        h1 { font-size:20px; margin:0 0 4px; color:#0D1B3E; }
        .ref { font-family: ui-monospace, Menlo, Consolas, monospace; font-weight:700; color:#1d4ed8; }
        .meta { display:flex; gap:28px; flex-wrap:wrap; margin-bottom:20px; font-size:12px; }
        .meta .c { color:#6b7280; text-transform:uppercase; font-size:10px; font-weight:700; letter-spacing:.05em; }
        .meta .v { font-weight:600; }
        table { width:100%; border-collapse:collapse; font-size:12px; margin-bottom:20px; }
        th { background:#f8fafc; text-align:left; padding:8px; border-bottom:1px solid #e5e7eb; font-size:10px; text-transform:uppercase; color:#6b7280; }
        td { padding:8px; border-bottom:1px solid #eef1f5; }
        .n { text-align:right; font-variant-numeric: tabular-nums; }
        .totaux { margin-left:auto; width:340px; font-size:13px; }
        .totaux div { display:flex; justify-content:space-between; padding:5px 0; }
        .totaux .fin { border-top:2px solid #0D1B3E; font-weight:800; padding-top:8px; margin-top:4px; }
        .signatures { display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-top:36px; font-size:12px; }
        .signatures div { border-top:1px solid #9ca3af; padding-top:6px; color:#6b7280; }
        .barre { max-width:820px; margin:16px auto 0; text-align:right; }
        .barre button { padding:8px 16px; font-size:13px; cursor:pointer; }
        @media print { body { background:#fff; } .barre { display:none; } .feuille { border:none; margin:0; max-width:none; } }
    </style>
</head>
<body>
<div class="barre"><button type="button" onclick="window.print()">Imprimer</button></div>
<div class="feuille">
    <div class="tete">
        <div>
            <h1>Bon de fabrication</h1>
            <div class="ref">{{ $ordre->code_ordre }}</div>
        </div>
        <div style="text-align:right; font-size:12px;">
            <strong>{{ $entreprise?->nom }}</strong><br>
            {{ $ordre->pointDeVente->nom }}
        </div>
    </div>

    <div class="meta">
        <div><div class="c">Produit fini</div><div class="v">{{ $ordre->produitFini->nom }} ({{ $ordre->produitFini->reference }})</div></div>
        <div><div class="c">Quantité</div><div class="v">{{ OrdreProduction::quantiteLisible($ordre->quantite_cible) }} {{ $ordre->produitFini->unite ?? 'Unité' }}</div></div>
        <div><div class="c">Date</div><div class="v">{{ $ordre->date_production->format('d/m/Y') }}</div></div>
        <div><div class="c">Statut</div><div class="v">{{ $ordre->statut }}</div></div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Matière consommée</th>
                <th class="n">Recette / unité</th>
                <th class="n">Quantité</th>
                <th class="n">Coût unitaire</th>
                <th class="n">Coût</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lignes as $l)
                <tr>
                    <td>{{ $l['ingredient']?->nom ?? '—' }}</td>
                    <td class="n">{{ $l['quantite_recette'] !== null ? OrdreProduction::quantiteLisible($l['quantite_recette'], 4) . ' ' . $l['unite_recette'] : '—' }}</td>
                    <td class="n">{{ OrdreProduction::quantiteLisible($l['consomme'] ?? $l['quantite']) }} {{ $l['unite'] }}</td>
                    <td class="n">{{ $l['cout_unitaire'] !== null ? number_format($l['cout_unitaire'], 2, ',', ' ') . ' F' : '—' }}</td>
                    <td class="n">{{ $l['cout'] !== null ? number_format($l['cout'], 0, ',', ' ') . ' F' : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totaux">
        <div><span>Coût des matières</span><strong>{{ $cout !== null ? number_format($cout, 0, ',', ' ') . ' F' : '—' }}</strong></div>
        <div class="fin"><span>Coût unitaire du produit fini</span><span>{{ $coutUnitaire !== null ? number_format($coutUnitaire, 2, ',', ' ') . ' F' : '—' }}</span></div>
    </div>

    @if(!$ordre->estTermine())
        <p style="font-size:11px; color:#6b7280;">
            {{ $ordre->estBrouillon() ? 'Ordre non encore validé : quantités prévues, coût connu à la validation.' : 'Ordre annulé.' }}
        </p>
    @endif

    <div class="signatures">
        <div>Remis par (magasin)</div>
        <div>Reçu par (atelier)</div>
    </div>
</div>
</body>
</html>

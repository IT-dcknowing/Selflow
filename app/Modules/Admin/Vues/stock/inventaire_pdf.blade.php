{{--
    L'inventaire de stock d'un site, en PDF A4 (26/10/2026).

    Tout en tableaux : dompdf n'implémente pas les dispositions modernes. La
    colonne « Compté » est laissée vide pour le comptage sur papier.
--}}
@php
    $f = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $q = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, ',', ' '), '0'), ',');
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Inventaire {{ $site->nom }}</title>
<style>
    @page { margin: 12mm 10mm; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { margin: 0; color: #1a1a1a; font-size: 8.5pt; line-height: 1.35; }
    table { border-collapse: collapse; width: 100%; }
    h1 { font-size: 14pt; margin: 0 0 2pt; }
    .sous-titre { color: #555; margin-bottom: 10pt; }
    th { background: #e2e8f0; text-align: left; padding: 5pt 4pt; font-size: 8pt; border: 0.5pt solid #cbd5e1; }
    td { padding: 4pt; border: 0.5pt solid #cbd5e1; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .ref { font-family: 'DejaVu Sans Mono', monospace; font-size: 7.5pt; color: #444; }
    .compte { width: 55pt; }
    tfoot td { font-weight: bold; background: #f8fafc; }
    .signatures td { border: none; padding-top: 24pt; width: 50%; }
</style>
</head>
<body>
    <h1>Inventaire de stock</h1>
    <div class="sous-titre">
        {{ $entreprise->nom }} — point de vente : <strong>{{ $site->nom }}</strong><br>
        Édité le {{ $date->format('d/m/Y à H:i') }} — {{ $lignes->count() }} article{{ $lignes->count() > 1 ? 's' : '' }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Référence</th>
                <th>Article</th>
                <th>Unité</th>
                <th class="num">Stock théorique</th>
                <th class="num">CUMP (F)</th>
                <th class="num">Valeur (F)</th>
                <th class="compte">Compté</th>
            </tr>
        </thead>
        <tbody>
            @forelse($lignes as $l)
                <tr>
                    <td class="ref">{{ $l['reference'] }}</td>
                    <td>{{ $l['nom'] }}@if($l['categorie'])<br><span style="color:#777; font-size:7.5pt;">{{ $l['categorie'] }}</span>@endif</td>
                    <td>{{ $l['unite'] }}</td>
                    <td class="num">{{ $q($l['quantite']) }}</td>
                    <td class="num">{{ $f($l['cump']) }}</td>
                    <td class="num">{{ $f($l['valeur']) }}</td>
                    <td class="compte"></td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center; color:#777;">Aucun article qui se compte sur ce site.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" class="num">Valeur totale du stock (CUMP — Coût Unitaire Moyen Pondéré)</td>
                <td class="num">{{ $f($total) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <table class="signatures">
        <tr>
            <td>Compté par : ____________________________</td>
            <td>Vérifié par : ____________________________</td>
        </tr>
    </table>
</body>
</html>

{{--
    Le reçu, en PDF, au format du ticket de caisse : 80 mm de large.

    Même contenu que la pièce A4 — c'est la même pièce —, mise en page pour un
    rouleau. Le bloc de certification y figure à l'identique quand la pièce est
    certifiée, et **pas du tout** quand elle ne l'est pas.
--}}
@php
    $f = fn ($n) => number_format((float) $n, 0, ',', ' ') . ' F';
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $titre }} {{ $numero }}</title>
<style>
    @page { margin: 4mm 3mm; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { margin: 0; color: #000; font-size: 7.5pt; line-height: 1.3; }
    table { border-collapse: collapse; width: 100%; }
    .centre { text-align: center; }
    .droite { text-align: right; }
    .gros { font-size: 10pt; font-weight: bold; }
    .filet { border-top: .5pt dashed #000; height: 1pt; margin: 4pt 0; }
    .discret { font-size: 6.5pt; }
    td { vertical-align: top; padding: 1pt 0; }
    .articles td { border-bottom: .3pt dotted #999; font-size: 7pt; }
    .net td { border-top: .6pt solid #000; font-size: 9pt; font-weight: bold; padding-top: 3pt; }
    .rendu td { font-weight: bold; }
</style>
</head>
<body>

<div class="centre">
    @if($entreprise['logo'])
        <img src="{{ $entreprise['logo'] }}" alt="" style="max-height: 30pt; max-width: 120pt;"><br>
    @endif
    <span class="gros">{{ $entreprise['nom'] }}</span><br>
    <span class="discret">
        {{ $entreprise['point_de_vente'] }}<br>
        @if($entreprise['adresse']){{ $entreprise['adresse'] }}<br>@endif
        @if($entreprise['tel'])Tél : {{ $entreprise['tel'] }}<br>@endif
        @if($entreprise['ncc'])NCC : {{ $entreprise['ncc'] }}@endif
        @if($entreprise['regime']) · {{ $entreprise['regime'] }}@endif
    </span>
</div>

<div class="filet"></div>

<div class="centre">
    <span class="gros">{{ $titre }}</span><br>
    N° {{ $numero }}<br>
    <span class="discret">{{ $date }}</span>
</div>

<div class="filet"></div>

<table>
    <tr><td class="discret">{{ $tiers['intitule'] }}</td><td class="droite"><strong>{{ $tiers['nom'] }}</strong></td></tr>
    @if($tiers['ncc'])
    <tr><td class="discret">NCC</td><td class="droite">{{ $tiers['ncc'] }}</td></tr>
    @endif
</table>

<div class="filet"></div>

<table class="articles">
    @foreach($lignes as $ligne)
    <tr>
        <td colspan="2">{{ $ligne['designation'] }}</td>
    </tr>
    <tr>
        <td class="discret">
            {{ rtrim(rtrim(number_format($ligne['quantite'], 2, ',', ' '), '0'), ',') }}
            {{ $ligne['unite'] }} × {{ $f($ligne['prix']) }}
            @if($ligne['remise'] > 0)
                − {{ rtrim(rtrim(number_format($ligne['remise'], 2, ',', ''), '0'), ',') }} %
            @endif
        </td>
        <td class="droite">{{ $f($ligne['ht']) }}</td>
    </tr>
    @endforeach
</table>

<table style="margin-top: 4pt;">
    <tr><td>Total HT</td><td class="droite">{{ $f($totaux['ht']) }}</td></tr>
    @if($totaux['remise'] > 0)
    <tr><td>Remise</td><td class="droite">− {{ $f($totaux['remise']) }}</td></tr>
    @endif
    @unless($totaux['sans_tva'])
    <tr><td>TVA</td><td class="droite">{{ $f($totaux['tva']) }}</td></tr>
    @endunless
    @if($totaux['autres_taxes'] > 0)
    <tr><td>Autres taxes</td><td class="droite">{{ $f($totaux['autres_taxes']) }}</td></tr>
    @endif
    @if($totaux['timbre'] > 0)
    <tr><td>Timbre de quittance</td><td class="droite">{{ $f($totaux['timbre']) }}</td></tr>
    @endif
    <tr class="net"><td>NET À PAYER</td><td class="droite">{{ $f($totaux['net']) }}</td></tr>
    @if($reglement['recu'] !== null)
    <tr><td>Reçu</td><td class="droite">{{ $f($reglement['recu']) }}</td></tr>
    @endif
    @if($reglement['rendu'] !== null && $reglement['rendu'] > 0)
    {{-- La monnaie rendue : le chiffre que le client vérifie avant de partir.
         Absente quand elle n'a pas eu lieu. --}}
    <tr class="rendu"><td>MONNAIE RENDUE</td><td class="droite">{{ $f($reglement['rendu']) }}</td></tr>
    @endif
    <tr><td class="discret">Mode</td><td class="droite discret">{{ $reglement['mode'] ?: '—' }}</td></tr>
</table>

@if($certification)
<div class="filet"></div>
<div class="centre">
    @if($certification['logo_fne'])
        <img src="{{ $certification['logo_fne'] }}" alt="Facture normalisée électronique" style="height: 18pt;"><br>
    @endif
    @if($certification['qr'])
        <img src="{{ $certification['qr'] }}" alt="Code de vérification FNE" style="width: 60pt; height: 60pt;"><br>
    @endif
    <span class="discret">
        N° FNE : {{ $certification['numero_fne'] }}<br>
        @if($certification['verification'])Vérification : {{ $certification['verification'] }}@endif
    </span>
</div>
@endif

@if($mentions)
<div class="filet"></div>
<div class="centre discret">{{ $mentions }}</div>
@endif
@if($pied)
<div class="centre discret">{{ $pied }}</div>
@endif

<div class="centre discret" style="margin-top: 5pt;">Merci de votre visite</div>

</body>
</html>

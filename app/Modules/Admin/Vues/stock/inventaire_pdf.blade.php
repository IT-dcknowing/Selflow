{{--
    L'inventaire de stock d'un site, en PDF A4 (26/10/2026, mis en forme le
    27/10/2026).

    Tout en tableaux : dompdf n'implémente pas les dispositions modernes. Le
    filigrane est une image (FiligraneService) posée en fond de chaque page ;
    le pied de page numéroté est ajouté par DocumentPdfService. La colonne
    « Compté » reste vide pour le comptage sur papier.
--}}
@php
    $f = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $q = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, ',', ' '), '0'), ',');
    $quantiteTotale = $lignes->sum('quantite');
    $ruptures = $lignes->filter(fn ($l) => $l['quantite'] <= 0)->count();
    $bleu = '#002B5C';
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Inventaire {{ $site->nom }}</title>
<style>
    @page { margin: 14mm 11mm 16mm; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { margin: 0; color: #1e293b; font-size: 8.3pt; line-height: 1.35; }
    table { border-collapse: collapse; width: 100%; }

    /* Au-dessus du contenu, mais transparent : les lignes grisées le cachaient. */
    .filigrane { position: fixed; top: 27%; left: -4%; width: 108%; text-align: center; z-index: 1000; }
    .filigrane img { width: 100%; }

    .entete td { vertical-align: middle; }
    .logo { max-height: 44pt; max-width: 120pt; }
    .entreprise { font-size: 12pt; font-weight: bold; color: {{ $bleu }}; }
    .coordonnees { font-size: 7.5pt; color: #64748b; }
    .titre-doc { text-align: right; }
    .titre-doc .titre { font-size: 17pt; font-weight: bold; color: {{ $bleu }}; letter-spacing: 1pt; }
    .titre-doc .sous { font-size: 8pt; color: #64748b; }
    .filet { height: 3pt; background: {{ $bleu }}; margin: 8pt 0 10pt; }

    .resume td { width: 25%; padding: 0 4pt; }
    .tuile { border: 0.6pt solid #cbd5e1; border-left: 3pt solid {{ $bleu }}; padding: 6pt 8pt; background: #f8fafc; }
    .tuile .etiquette { font-size: 6.8pt; color: #64748b; text-transform: uppercase; letter-spacing: .4pt; }
    .tuile .valeur { font-size: 12pt; font-weight: bold; color: {{ $bleu }}; margin-top: 2pt; }

    .liste { margin-top: 12pt; }
    .liste th { background: {{ $bleu }}; color: #fff; text-align: left; padding: 6pt 5pt; font-size: 7.6pt; font-weight: bold; text-transform: uppercase; letter-spacing: .3pt; }
    .liste td { padding: 5pt; border-bottom: 0.5pt solid #e2e8f0; vertical-align: top; }
    .liste tbody tr:nth-child(even) td { background: #f1f5f9; }
    .num { text-align: right; white-space: nowrap; }
    .ref { font-size: 7.2pt; color: #475569; }
    .categorie { font-size: 6.8pt; color: #94a3b8; }
    .rupture { color: #b91c1c; font-weight: bold; }
    .compte { width: 58pt; border-left: 0.5pt dashed #94a3b8; }
    .liste td.compte, .liste tbody tr:nth-child(even) td.compte { background: #fffbeb; }
    .liste tfoot td { background: #e2e8f0; font-weight: bold; padding: 7pt 5pt; border-top: 1.2pt solid {{ $bleu }}; }

    .signatures { margin-top: 26pt; }
    .signatures td { width: 50%; padding: 0 6pt; vertical-align: top; }
    .cadre-signature { border: 0.6pt solid #cbd5e1; height: 52pt; padding: 5pt 7pt; }
    .cadre-signature .etiquette { font-size: 7pt; color: #64748b; text-transform: uppercase; }
</style>
</head>
<body>
    @if(!empty($filigrane))
        <div class="filigrane"><img src="{{ $filigrane }}" alt=""></div>
    @endif

    {{-- En-tête : l'entreprise à gauche, le document à droite. --}}
    <table class="entete">
        <tr>
            <td style="width:55%;">
                <table>
                    <tr>
                        @if(!empty($logo))
                            <td style="width:1%; padding-right:8pt;"><img class="logo" src="{{ $logo }}" alt=""></td>
                        @endif
                        <td>
                            <div class="entreprise">{{ $entreprise->nom }}</div>
                            <div class="coordonnees">
                                {{ collect([$entreprise->adresse, $entreprise->telephone, $entreprise->email])->filter()->implode(' · ') }}
                                @if($entreprise->ncc)<br>NCC {{ $entreprise->ncc }}@endif
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
            <td class="titre-doc">
                <div class="titre">INVENTAIRE DE STOCK</div>
                <div class="sous">
                    Point de vente : <strong style="color:#1e293b;">{{ $site->nom }}</strong><br>
                    Édité le {{ $date->format('d/m/Y à H:i') }}
                </div>
            </td>
        </tr>
    </table>
    <div class="filet"></div>

    {{-- Les chiffres de l'inventaire, d'un coup d'œil. --}}
    <table class="resume">
        <tr>
            <td><div class="tuile"><div class="etiquette">Articles</div><div class="valeur">{{ $lignes->count() }}</div></div></td>
            <td><div class="tuile"><div class="etiquette">Quantité totale</div><div class="valeur">{{ $q($quantiteTotale) }}</div></div></td>
            <td><div class="tuile"><div class="etiquette">En rupture</div><div class="valeur" style="{{ $ruptures ? 'color:#b91c1c;' : '' }}">{{ $ruptures }}</div></div></td>
            <td><div class="tuile"><div class="etiquette">Valeur du stock</div><div class="valeur">{{ $f($total) }} F</div></div></td>
        </tr>
    </table>

    <table class="liste">
        <thead>
            <tr>
                <th style="width:13%;">Référence</th>
                <th>Article</th>
                <th style="width:9%;">Unité</th>
                <th class="num" style="width:11%;">Stock</th>
                <th class="num" style="width:11%;">CUMP (F)</th>
                <th class="num" style="width:13%;">Valeur (F)</th>
                <th class="compte">Compté</th>
            </tr>
        </thead>
        <tbody>
            @forelse($lignes as $l)
                <tr>
                    <td class="ref">{{ $l['reference'] }}</td>
                    <td>{{ $l['nom'] }}@if($l['categorie'])<br><span class="categorie">{{ $l['categorie'] }}</span>@endif</td>
                    <td>{{ $l['unite'] }}</td>
                    <td class="num {{ $l['quantite'] <= 0 ? 'rupture' : '' }}">{{ $q($l['quantite']) }}</td>
                    <td class="num">{{ $f($l['cump']) }}</td>
                    <td class="num">{{ $f($l['valeur']) }}</td>
                    <td class="compte"></td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center; color:#94a3b8; padding:14pt;">Aucun article qui se compte sur ce site.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">Total — CUMP (Coût Unitaire Moyen Pondéré)</td>
                <td class="num">{{ $q($quantiteTotale) }}</td>
                <td></td>
                <td class="num">{{ $f($total) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <table class="signatures">
        <tr>
            <td><div class="cadre-signature"><div class="etiquette">Compté par — nom, date et signature</div></div></td>
            <td><div class="cadre-signature"><div class="etiquette">Vérifié par — nom, date et signature</div></div></td>
        </tr>
    </table>
</body>
</html>

{{--
    Le rapport d'activité de l'entreprise, en PDF A4 (23/11/2026).

    Mêmes données que l'écran « Analyse d'activité » : le PDF est rendu à
    partir de la vue de l'écran, jamais recalculé à côté. Tout en tableaux,
    dompdf n'implémente ni flex ni grid ; les graphiques sont des barres
    dessinées en blocs de hauteur proportionnelle.
--}}
@php
    $f = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $bleu = '#002B5C';
    $bleuClair = '#2563eb';
    $orange = '#f08a1c';

    // Les douze derniers mois, ventes et achats alignés mois par mois.
    $mois = collect(range(11, 0))->map(fn ($i) => now()->subMonths($i)->format('Y-m'));
    $ventesParMois = collect($evolutionMensuelle)->keyBy('mois');
    $achatsParMois = collect($evolutionAchatsMensuelle)->keyBy('mois');
    $serie = $mois->map(fn ($m) => [
        'mois'   => \Illuminate\Support\Carbon::createFromFormat('Y-m', $m)->translatedFormat('M y'),
        'ventes' => (float) ($ventesParMois[$m]->ca ?? 0),
        'achats' => (float) ($achatsParMois[$m]->depenses ?? 0),
    ]);
    $plafond = max(1, $serie->max(fn ($s) => max($s['ventes'], $s['achats'])));
    $hauteurMax = 110; // en points

    $totalPaiements = max(1, collect($repartitionPaiements)->sum('total'));
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Rapport d'activité {{ $entreprise->nom }}</title>
<style>
    @page { margin: 14mm 11mm 16mm; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { margin: 0; color: #1e293b; font-size: 8.3pt; line-height: 1.35; }
    table { border-collapse: collapse; width: 100%; }
    .filigrane { position: fixed; top: 27%; left: -4%; width: 108%; text-align: center; z-index: 1000; }
    .filigrane img { width: 100%; }
    .entete td { vertical-align: middle; }
    .logo { max-height: 44pt; max-width: 120pt; }
    .entreprise { font-size: 12pt; font-weight: bold; color: {{ $bleu }}; }
    .coordonnees { font-size: 7.5pt; color: #64748b; }
    .titre-doc { text-align: right; }
    .titre-doc .titre { font-size: 16pt; font-weight: bold; color: {{ $bleu }}; letter-spacing: 1pt; }
    .titre-doc .sous { font-size: 8pt; color: #64748b; }
    .filet { height: 3pt; background: {{ $bleu }}; margin: 8pt 0 10pt; }

    h2 { font-size: 10.5pt; color: {{ $bleu }}; margin: 16pt 0 6pt; padding-bottom: 3pt; border-bottom: 0.8pt solid #cbd5e1; text-transform: uppercase; letter-spacing: .5pt; }

    .tuiles td { width: 33.33%; padding: 3pt 4pt; vertical-align: top; }
    .tuile { border: 0.6pt solid #cbd5e1; border-left: 3pt solid {{ $bleu }}; padding: 6pt 8pt; background: #f8fafc; }
    .tuile.vert { border-left-color: #16a34a; } .tuile.orange { border-left-color: {{ $orange }}; } .tuile.rouge { border-left-color: #dc2626; }
    .tuile .etiquette { font-size: 6.8pt; color: #64748b; text-transform: uppercase; letter-spacing: .4pt; }
    .tuile .valeur { font-size: 12.5pt; font-weight: bold; color: {{ $bleu }}; margin-top: 2pt; }
    .tuile .detail { font-size: 7pt; color: #64748b; margin-top: 1pt; }

    .graphe td { vertical-align: bottom; text-align: center; padding: 0 2pt; }
    .barre { display: inline-block; width: 11pt; }
    .graphe .etiquette-mois { font-size: 6.4pt; color: #64748b; padding-top: 3pt; border-top: 0.6pt solid #94a3b8; }
    .legende { font-size: 7pt; color: #475569; margin-top: 4pt; }
    .pastille { display: inline-block; width: 8pt; height: 8pt; margin-right: 3pt; vertical-align: middle; }

    .liste th { background: {{ $bleu }}; color: #fff; text-align: left; padding: 5pt; font-size: 7.4pt; font-weight: bold; text-transform: uppercase; letter-spacing: .3pt; }
    .liste td { padding: 4.5pt 5pt; border-bottom: 0.5pt solid #e2e8f0; vertical-align: top; }
    .liste tbody tr:nth-child(even) td { background: #f1f5f9; }
    .num { text-align: right; white-space: nowrap; }
    .positif { color: #15803d; font-weight: bold; } .negatif { color: #b91c1c; font-weight: bold; }
    .jauge { background: #e2e8f0; height: 7pt; width: 100%; }
    .jauge div { background: {{ $bleuClair }}; height: 7pt; }
    .deux td.col { width: 50%; vertical-align: top; padding: 0 5pt; }
    .note { font-size: 7pt; color: #64748b; margin-top: 14pt; }
</style>
</head>
<body>
    @if(!empty($filigrane))
        <div class="filigrane"><img src="{{ $filigrane }}" alt=""></div>
    @endif

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
                <div class="titre">RAPPORT D'ACTIVITÉ</div>
                <div class="sous">
                    {{ $libellePeriode }}<br>
                    @if(!empty($titreSite))
                        <strong style="color:#1e293b;">{{ $titreSite }}</strong><br>
                    @else
                        {{ $pointsDeVente->count() }} point(s) de vente : {{ $pointsDeVente->pluck('nom')->implode(', ') }}<br>
                    @endif
                    Édité le {{ now()->format('d/m/Y à H:i') }}
                </div>
            </td>
        </tr>
    </table>
    <div class="filet"></div>

    <h2>Les chiffres clés</h2>
    <table class="tuiles">
        <tr>
            <td><div class="tuile"><div class="etiquette">Chiffre d'affaires TTC</div><div class="valeur">{{ $f($totalVentes) }} F</div><div class="detail">{{ $nbVentes }} facture(s)</div></div></td>
            <td><div class="tuile orange"><div class="etiquette">Achats TTC</div><div class="valeur">{{ $f($totalAchats) }} F</div><div class="detail">{{ $nbAchats }} facture(s) d'achat</div></div></td>
            <td><div class="tuile {{ $margeBrute >= 0 ? 'vert' : 'rouge' }}"><div class="etiquette">Marge brute</div><div class="valeur">{{ $f($margeBrute) }} F</div><div class="detail">Taux de marge : {{ number_format((float) $tauxMarge, 1, ',', ' ') }} %</div></div></td>
        </tr>
        <tr>
            <td><div class="tuile"><div class="etiquette">Panier moyen</div><div class="valeur">{{ $f($panierMoyen) }} F</div><div class="detail">par facture</div></div></td>
            <td><div class="tuile vert"><div class="etiquette">Encaissements</div><div class="valeur">{{ $f($totalEncaissements) }} F</div><div class="detail">Décaissements : {{ $f($totalDecaissements) }} F</div></div></td>
            <td><div class="tuile {{ $soldeTresorerie >= 0 ? 'vert' : 'rouge' }}"><div class="etiquette">Solde de trésorerie</div><div class="valeur">{{ $f($soldeTresorerie) }} F</div><div class="detail">caisse, banque et mobile money</div></div></td>
        </tr>
    </table>

    @isset($fne)
    <table class="tuiles">
        <tr>
            <td><div class="tuile"><div class="etiquette">Créances clients</div><div class="valeur">{{ $f($creancesClients) }} F</div><div class="detail">reste à encaisser</div></div></td>
            <td><div class="tuile orange"><div class="etiquette">Dettes fournisseurs</div><div class="valeur">{{ $f($dettesFournisseurs) }} F</div><div class="detail">reste à payer</div></div></td>
            <td><div class="tuile"><div class="etiquette">TVA nette</div><div class="valeur">{{ $f($tvaCollectee - $tvaDeductible) }} F</div><div class="detail">collectée {{ $f($tvaCollectee) }} F, déductible {{ $f($tvaDeductible) }} F</div></div></td>
        </tr>
        <tr>
            <td><div class="tuile vert"><div class="etiquette">Factures normalisées FNE</div><div class="valeur">{{ $fne['normalisees'] }}</div><div class="detail">{{ $f($fne['montantNormalise']) }} F TTC</div></div></td>
            <td><div class="tuile {{ $fne['nonNormalisees'] > 0 ? 'rouge' : 'vert' }}"><div class="etiquette">Factures non normalisées</div><div class="valeur">{{ $fne['nonNormalisees'] }}</div><div class="detail">{{ $f($fne['montantNonNormalise']) }} F TTC</div></div></td>
            <td><div class="tuile"><div class="etiquette">Valeur du stock</div><div class="valeur">{{ $f(collect($stockParSite)->sum('valeur')) }} F</div><div class="detail">au CUMP (Coût Unitaire Moyen Pondéré)</div></div></td>
        </tr>
    </table>
    @endisset

    <h2>Ventes et achats des douze derniers mois</h2>
    <table class="graphe">
        <tr>
            @foreach($serie as $s)
                <td style="height:{{ $hauteurMax + 4 }}pt;">
                    <span class="barre" style="height:{{ max(1, round($s['ventes'] / $plafond * $hauteurMax)) }}pt; background:{{ $bleu }};"></span><span class="barre" style="height:{{ max(1, round($s['achats'] / $plafond * $hauteurMax)) }}pt; background:{{ $orange }};"></span>
                </td>
            @endforeach
        </tr>
        <tr>
            @foreach($serie as $s)
                <td class="etiquette-mois">{{ $s['mois'] }}</td>
            @endforeach
        </tr>
    </table>
    <div class="legende">
        <span class="pastille" style="background:{{ $bleu }};"></span>Ventes TTC
        &nbsp;&nbsp;<span class="pastille" style="background:{{ $orange }};"></span>Achats TTC
        &nbsp;&nbsp;Échelle : la plus haute barre vaut {{ $f($plafond) }} F.
    </div>

    <h2>La performance par point de vente</h2>
    <table class="liste">
        <thead><tr><th>Point de vente</th><th class="num">CA TTC</th><th class="num">Factures</th><th class="num">Panier moyen</th><th class="num">Achats</th><th class="num">Marge</th><th class="num">Trésorerie</th></tr></thead>
        <tbody>
        @forelse($performancesPdv as $p)
            <tr>
                <td><strong>{{ $p['nom'] }}</strong>@if($p['ville'])<br><span style="color:#64748b; font-size:7pt;">{{ $p['ville'] }}</span>@endif</td>
                <td class="num">{{ $f($p['ca_ttc']) }}</td>
                <td class="num">{{ $p['nb_ventes'] }}</td>
                <td class="num">{{ $f($p['panier_moy']) }}</td>
                <td class="num">{{ $f($p['depenses']) }}</td>
                <td class="num {{ $p['marge'] >= 0 ? 'positif' : 'negatif' }}">{{ $f($p['marge']) }}</td>
                <td class="num">{{ $f($p['solde_tres']) }}</td>
            </tr>
        @empty
            <tr><td colspan="7">Aucun point de vente.</td></tr>
        @endforelse
        </tbody>
    </table>

    @isset($analytique)
    <h2>Le résultat comptable par site</h2>
    <table class="liste">
        <thead><tr><th>Site</th><th class="num">Produits (classe 7)</th><th class="num">Charges (classe 6)</th><th class="num">Résultat</th><th class="num">Écritures</th></tr></thead>
        <tbody>
        @forelse($analytique['sites'] as $l)
            <tr>
                <td>{{ $l['nom'] }}</td>
                <td class="num">{{ $f($l['produits']) }}</td>
                <td class="num">{{ $f($l['charges']) }}</td>
                <td class="num {{ $l['resultat'] >= 0 ? 'positif' : 'negatif' }}">{{ $f($l['resultat']) }}</td>
                <td class="num">{{ $l['ecritures'] }}</td>
            </tr>
        @empty
            <tr><td colspan="5">Aucune écriture sur la période.</td></tr>
        @endforelse
        </tbody>
        @if(empty($titreSite))
        <tfoot><tr><td style="padding:5pt; font-weight:bold;">Entreprise</td><td class="num" style="font-weight:bold;">{{ $f($analytique['totaux']['produits']) }}</td><td class="num" style="font-weight:bold;">{{ $f($analytique['totaux']['charges']) }}</td><td class="num {{ $analytique['totaux']['resultat'] >= 0 ? 'positif' : 'negatif' }}">{{ $f($analytique['totaux']['resultat']) }}</td><td class="num">{{ $analytique['totaux']['ecritures'] }}</td></tr></tfoot>
        @endif
    </table>

    <h2>Le stock par site</h2>
    <table class="liste">
        <thead><tr><th>Site</th><th class="num">Articles suivis</th><th class="num">Valeur (CUMP)</th><th class="num">En alerte</th><th class="num">En rupture</th></tr></thead>
        <tbody>
        @foreach($stockParSite as $st)
            <tr>
                <td>{{ $st['nom'] }}</td>
                <td class="num">{{ $st['articles'] }}</td>
                <td class="num">{{ $f($st['valeur']) }}</td>
                <td class="num">{{ $st['alertes'] }}</td>
                <td class="num {{ $st['ruptures'] > 0 ? 'negatif' : '' }}">{{ $st['ruptures'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endisset

    <h2>Les articles</h2>
    <table class="deux">
        <tr>
            <td class="col">
                <table class="liste">
                    <thead><tr><th>Les plus vendus</th><th class="num">Qté</th><th class="num">CA HT</th><th class="num">Marge</th></tr></thead>
                    <tbody>
                    @forelse($topProduits as $t)
                        <tr><td>{{ $t->produit_nom }}</td><td class="num">{{ rtrim(rtrim(number_format((float) $t->qte_vendue, 3, ',', ' '), '0'), ',') }}</td><td class="num">{{ $f($t->ca_ht) }}</td><td class="num">{{ number_format((float) $t->taux_marge, 1, ',', ' ') }} %</td></tr>
                    @empty
                        <tr><td colspan="4">Aucune vente sur la période.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </td>
            <td class="col">
                <table class="liste">
                    <thead><tr><th>Les moins vendus</th><th class="num">Qté</th><th class="num">CA TTC</th></tr></thead>
                    <tbody>
                    @forelse($basVendus as $b)
                        <tr><td>{{ $b->produit_nom }}</td><td class="num">{{ rtrim(rtrim(number_format((float) ($b->qte_vendue ?? 0), 3, ',', ' '), '0'), ',') }}</td><td class="num">{{ $f($b->ca_ttc ?? 0) }}</td></tr>
                    @empty
                        <tr><td colspan="3">Aucune vente sur la période.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    <table class="deux" style="margin-top:4pt;">
        <tr>
            <td class="col">
                <h2>Les vendeurs</h2>
                <table class="liste">
                    <thead><tr><th>Vendeur</th><th class="num">Factures</th><th class="num">CA TTC</th></tr></thead>
                    <tbody>
                    @forelse($performancesEmployes as $e)
                        <tr><td>{{ $e->nom_employe }}<br><span style="color:#64748b; font-size:7pt;">{{ $e->pdv_nom }}</span></td><td class="num">{{ $e->nb_ventes ?? '' }}</td><td class="num">{{ $f($e->total_ventes ?? 0) }}</td></tr>
                    @empty
                        <tr><td colspan="3">Aucune vente sur la période.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </td>
            <td class="col">
                <h2>Les modes de paiement</h2>
                <table class="liste">
                    <thead><tr><th>Mode</th><th class="num">Montant</th><th style="width:40%;">Part</th></tr></thead>
                    <tbody>
                    @forelse($repartitionPaiements as $r)
                        @php
                            $part = round((float) $r->total / $totalPaiements * 100);
                        @endphp
                        <tr>
                            <td>{{ $r->mode_paiement ?: 'Non précisé' }}</td>
                            <td class="num">{{ $f($r->total) }}</td>
                            <td><div class="jauge"><div style="width:{{ $part }}%;"></div></div><span style="font-size:6.8pt; color:#64748b;">{{ $part }} %</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="3">Aucune facture.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    @if(collect($topDepenses)->isNotEmpty())
        <h2>Les principales dépenses</h2>
        <table class="liste">
            <thead><tr><th>Poste</th><th class="num">Montant TTC</th></tr></thead>
            <tbody>
            @foreach($topDepenses as $d)
                <tr><td>{{ $d->libelle_virtuel ?: 'Articles du catalogue' }}</td><td class="num">{{ $f($d->total_depense ?? 0) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <div class="note">Document établi par Selflow à partir des factures, achats et journaux de trésorerie enregistrés. Les montants TTC des ventes sont ceux des pièces au stade « Facture ».</div>
</body>
</html>

{{--
    La pièce, en PDF, format A4.

    Tout est en tableaux et rien en `flex` : dompdf n'implémente pas les
    dispositions modernes, et une mise en page qui s'appuierait dessus
    s'effondrerait sans rien dire. Les styles sont dans la page — un PDF
    n'ira chercher aucune feuille, et le chargement distant est éteint.

    Aucun montant n'est recalculé ici : ils viennent tous du service, qui les
    lit tels qu'ils ont été enregistrés.
--}}
@php
    /** Format des montants : le PDF n'a pas de `toLocaleString`. */
    $f = fn ($n) => number_format((float) $n, 0, ',', ' ') . ' F';

    /*
     * Le modèle choisi à l'écran. Le PDF rendait toujours le premier : on
     * téléchargeait un document qui n'était pas celui qu'on regardait.
     *
     * Les quatre ne diffèrent pas de structure — mêmes blocs, mêmes colonnes,
     * mêmes montants : ce serait quatre documents à vérifier au lieu d'un. Ils
     * diffèrent de **teinte** et de **trait**, ce qui est exactement ce que
     * l'écran en montre.
     */
    $modele = $modele ?? \App\Modules\Admin\Services\DocumentPdfService::modele(1);
    $teinte = $modele['couleur'];
    $aplat  = $modele['fond'];
    $encre  = $modele['texte'];
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>{{ $titre }} {{ $numero }}</title>
<style>
    @page { margin: 12mm 10mm; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { margin: 0; color: #1a1a1a; font-size: 9pt; line-height: 1.35; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; }

    .bandeau td { padding-bottom: 8pt; }
    .societe { font-size: 13pt; font-weight: bold; color: {{ $teinte }}; }
    .discret { color: #555; font-size: 8pt; }

    .etiquette {
        font-size: 15pt; font-weight: bold; color: {{ $teinte }};
        text-transform: uppercase; letter-spacing: .5pt;
    }
    .numero { font-size: 10pt; font-weight: bold; }

    .cadre { border: .6pt solid #c9d2e0; padding: 6pt 8pt; }
    .cadre .intitule {
        font-size: 7.5pt; text-transform: uppercase; letter-spacing: .4pt;
        color: #6b7686; font-weight: bold; padding-bottom: 2pt;
    }

    table.articles { margin-top: 10pt; }
    table.articles th {
        background: {{ $modele['encadre'] ? '#fff' : $teinte }};
        color: {{ $modele['encadre'] ? $encre : '#fff' }};
        border: {{ $modele['encadre'] ? '.6pt solid ' . $encre : '0' }};
        font-size: 7.5pt; font-weight: bold;
        text-transform: uppercase; padding: 5pt 4pt; text-align: left;
    }
    table.articles td { padding: 4pt; border-bottom: .4pt solid #e2e8f0; font-size: 8.5pt; }
    table.articles tr.paire td { background: {{ $modele['encadre'] ? '#fff' : $aplat }}; }
    .droite { text-align: right; }
    .centre { text-align: center; }

    table.totaux td { padding: 3pt 6pt; font-size: 9pt; }
    table.totaux tr.net td {
        border-top: .8pt solid {{ $teinte }}; border-bottom: .8pt solid {{ $teinte }};
        font-size: 11pt; font-weight: bold; color: {{ $teinte }};
    }
    table.totaux tr.rendu td { color: #15803d; font-weight: bold; }

    .certif { border: .8pt solid {{ $teinte }}; padding: 7pt; margin-top: 12pt; }
    .certif .titre {
        font-size: 8pt; font-weight: bold; color: {{ $teinte }};
        text-transform: uppercase; letter-spacing: .4pt;
    }
    .certif .valeur { font-family: 'DejaVu Sans Mono', monospace; font-size: 8pt; word-wrap: break-word; }

    .pied { margin-top: 12pt; font-size: 7.5pt; color: #555; }
</style>
</head>
<body>

{{-- ── En-tête : qui émet, et quoi ── --}}
<table class="bandeau">
    <tr>
        <td style="width: 58%;">
            @if($entreprise['logo'])
                <img src="{{ $entreprise['logo'] }}" alt="" style="max-height: 46pt; max-width: 150pt;"><br>
            @endif
            <span class="societe">{{ $entreprise['nom'] }}</span><br>
            <span class="discret">
                {{ $entreprise['point_de_vente'] }}<br>
                @if($entreprise['adresse']){{ $entreprise['adresse'] }}<br>@endif
                @if($entreprise['tel'])Tél : {{ $entreprise['tel'] }}@endif
                @if($entreprise['email']) · {{ $entreprise['email'] }}@endif<br>
                @if($entreprise['ncc'])NCC : {{ $entreprise['ncc'] }}@endif
                @if($entreprise['regime']) · Régime : {{ $entreprise['regime'] }}@endif<br>
                @if($entreprise['centre_impots'])Centre des impôts : {{ $entreprise['centre_impots'] }}<br>@endif
                @if($entreprise['rccm'])RCCM : {{ $entreprise['rccm'] }}@endif
            </span>
        </td>
        <td style="width: 42%; text-align: right;">
            <span class="etiquette">{{ $titre }}</span><br>
            <span class="numero">N° {{ $numero }}</span><br>
            <span class="discret">{{ $date }}</span>
            @if($origine)
                <br><span class="discret">{{ $origine }}</span>
            @endif
        </td>
    </tr>
</table>

{{-- ── Le tiers, et le règlement ── --}}
<table>
    <tr>
        <td style="width: 49%; padding-right: 4pt;">
            <table class="cadre">
                <tr><td class="intitule">{{ $tiers['intitule'] }}</td></tr>
                <tr><td>
                    <strong>{{ $tiers['nom'] }}</strong><br>
                    <span class="discret">
                        @if($tiers['adresse']){{ $tiers['adresse'] }}<br>@endif
                        @if($tiers['tel'])Tél : {{ $tiers['tel'] }}<br>@endif
                        @if($tiers['ncc'])NCC : {{ $tiers['ncc'] }}<br>@endif
                        @if($tiers['rccm'])RCCM : {{ $tiers['rccm'] }}@endif
                    </span>
                </td></tr>
            </table>
        </td>
        <td style="width: 2%;"></td>
        <td style="width: 49%;">
            <table class="cadre">
                <tr><td class="intitule">Règlement</td></tr>
                <tr><td>
                    <table>
                        <tr><td class="discret">Mode</td><td class="droite"><strong>{{ $reglement['mode'] ?: '—' }}</strong></td></tr>
                        @if($reglement['moyen'])
                        <tr><td class="discret">Moyen</td><td class="droite">{{ $reglement['moyen'] }}</td></tr>
                        @endif
                        @if($reglement['reference'])
                        <tr><td class="discret">Référence</td><td class="droite">{{ $reglement['reference'] }}</td></tr>
                        @endif
                        <tr><td class="discret">Statut</td><td class="droite"><strong>{{ $reglement['statut'] ?: '—' }}</strong></td></tr>
                    </table>
                </td></tr>
            </table>
        </td>
    </tr>
</table>

{{-- ── Les articles ── --}}
<table class="articles">
    <thead>
        <tr>
            <th style="width: 10%;">Réf.</th>
            <th style="width: 30%;">Désignation</th>
            <th style="width: 7%;" class="droite">Qté</th>
            <th style="width: 8%;">Unité</th>
            <th style="width: 12%;" class="droite">P.U. HT</th>
            <th style="width: 6%;" class="droite">Rem.</th>
            <th style="width: 13%;" class="droite">Total HT</th>
            @unless($totaux['sans_tva'])
            <th style="width: 6%;" class="centre">TVA</th>
            <th style="width: 8%;" class="droite">Mt TVA</th>
            @endunless
        </tr>
    </thead>
    <tbody>
        @foreach($lignes as $rang => $ligne)
        <tr class="{{ $rang % 2 ? 'paire' : '' }}">
            <td>{{ $ligne['reference'] }}</td>
            <td>{{ $ligne['designation'] }}</td>
            <td class="droite">{{ rtrim(rtrim(number_format($ligne['quantite'], 2, ',', ' '), '0'), ',') }}</td>
            <td>{{ $ligne['unite'] }}</td>
            <td class="droite">{{ $f($ligne['prix']) }}</td>
            <td class="droite">{{ $ligne['remise'] > 0 ? rtrim(rtrim(number_format($ligne['remise'], 2, ',', ''), '0'), ',') . ' %' : '—' }}</td>
            <td class="droite">{{ $f($ligne['ht']) }}</td>
            @unless($totaux['sans_tva'])
            <td class="centre">
                {{ $ligne['taux_tva'] > 0 ? rtrim(rtrim(number_format($ligne['taux_tva'], 2, ',', ''), '0'), ',') . ' %' : '—' }}
                @if($ligne['code_tva'])<br><span class="discret">{{ $ligne['code_tva'] }}</span>@endif
            </td>
            <td class="droite">{{ $f($ligne['tva']) }}</td>
            @endunless
        </tr>
        @endforeach
    </tbody>
</table>

{{-- ── Les totaux ── --}}
<table style="margin-top: 10pt;">
    <tr>
        <td style="width: 55%;"></td>
        <td style="width: 45%;">
            <table class="totaux">
                <tr><td>Total HT</td><td class="droite">{{ $f($totaux['ht']) }}</td></tr>
                @if($totaux['remise'] > 0 || $totaux['remise_taux'] > 0)
                <tr>
                    <td>Remise{{ $totaux['remise_taux'] > 0 ? ' (' . rtrim(rtrim(number_format($totaux['remise_taux'], 2, ',', ''), '0'), ',') . ' %)' : '' }}</td>
                    <td class="droite">− {{ $f($totaux['remise']) }}</td>
                </tr>
                @endif
                @if($totaux['sans_tva'])
                <tr><td colspan="2" class="discret">
                    {{-- Le bordereau d'achat ne transmet aucune TVA : l'annoncer
                         ferait espérer au vendeur une taxe que la plateforme
                         ignore. --}}
                    Bordereau d'achat : aucune TVA n'est transmise à la plateforme.
                </td></tr>
                @else
                <tr><td>TVA</td><td class="droite">{{ $f($totaux['tva']) }}</td></tr>
                @endif
                @if($totaux['autres_taxes'] > 0)
                <tr><td>Autres taxes</td><td class="droite">{{ $f($totaux['autres_taxes']) }}</td></tr>
                @endif
                <tr><td>Total TTC</td><td class="droite">{{ $f($totaux['ttc']) }}</td></tr>
                @if($totaux['timbre'] > 0)
                <tr><td>Timbre de quittance</td><td class="droite">{{ $f($totaux['timbre']) }}</td></tr>
                @endif
                <tr class="net"><td>Net à payer</td><td class="droite">{{ $f($totaux['net']) }}</td></tr>
                @if($reglement['recu'] !== null)
                <tr><td>Reçu</td><td class="droite">{{ $f($reglement['recu']) }}</td></tr>
                @endif
                @if($reglement['rendu'] !== null && $reglement['rendu'] > 0)
                {{-- Ce qu'on a rendu. Elle ne figure que si elle a eu lieu :
                     une ligne « monnaie rendue : 0 F » sur un règlement à
                     l'appoint dirait une opération qui n'a pas existé. --}}
                <tr class="rendu"><td>Monnaie rendue</td><td class="droite">{{ $f($reglement['rendu']) }}</td></tr>
                @endif
                @if($totaux['deja_paye'] > 0)
                <tr><td>Déjà réglé</td><td class="droite">{{ $f($totaux['deja_paye']) }}</td></tr>
                <tr><td><strong>Reste dû</strong></td><td class="droite"><strong>{{ $f($totaux['reste']) }}</strong></td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

{{-- ── La certification, si et seulement si elle a eu lieu ── --}}
@if($certification)
<table class="certif">
    <tr>
        <td style="width: 78pt;">
            @if($certification['qr'])
                <img src="{{ $certification['qr'] }}" alt="Code de vérification FNE" style="width: 72pt; height: 72pt;">
            @endif
        </td>
        <td>
            @if($certification['logo_fne'])
                <img src="{{ $certification['logo_fne'] }}" alt="Facture normalisée électronique" style="height: 26pt;"><br>
            @endif
            <span class="titre">Facture normalisée électronique</span><br>
            N° FNE : <span class="valeur">{{ $certification['numero_fne'] }}</span><br>
            @if($certification['verification'])
                Vérification : <span class="valeur">{{ $certification['verification'] }}</span><br>
            @endif
            @if($certification['signature'])
                Signature : <span class="valeur">{{ $certification['signature'] }}</span>
            @endif
        </td>
    </tr>
</table>
@endif

@if($mentions)
<div class="pied"><strong>{{ $mentions }}</strong></div>
@endif
@if($pied)
<div class="pied">{{ $pied }}</div>
@endif

</body>
</html>

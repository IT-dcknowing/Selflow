@extends('admin::gabarits.application')
@section('titre', 'Factures FNE à intégrer')
@section('topbar_titre', 'Ventes — Factures FNE à intégrer')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-file-import"></i> Factures FNE à intégrer</h1>
        <p>Factures émises sur le portail de la DGI, hors de Selflow. Une fois intégrées, ce sont des ventes normalisées :
           elles comptent dans les factures, le solde du client et la comptabilité. Les factures que Selflow a normalisées lui-même ne sont jamais reprises.</p>
    </div>
    <div style="display:flex; gap:8px;">
        <a href="{{ route('admin.ventes.factures') }}" class="btn btn-outline"><i class="fas fa-list"></i> Factures</a>
        @if($factures->isNotEmpty())
        <form method="POST" action="{{ route('admin.ventes.portail_fne.integrer_pretes') }}" onsubmit="return confirm('Intégrer toutes les factures dont le point de vente est reconnu ?');">
            @csrf
            <button class="btn btn-primary"><i class="fas fa-check-double"></i> Intégrer celles qui sont prêtes</button>
        </form>
        @endif
    </div>
</div>

@if($factures->isEmpty())
    <div class="card" style="padding:24px; text-align:center; color:var(--text-3);">
        <i class="fas fa-circle-check" style="font-size:28px; color:#10b981;"></i>
        <p style="margin-top:8px;">Aucune facture du portail en attente : tout est déjà dans Selflow.</p>
    </div>
@endif

@foreach($factures as $facture)
    @php($p = $propositions[$facture->id])
    <form method="POST" action="{{ route('admin.ventes.portail_fne.integrer', $facture) }}" class="card" style="padding:16px; margin-bottom:16px;">
        @csrf
        <div style="display:flex; flex-wrap:wrap; gap:16px; justify-content:space-between; align-items:flex-start;">
            <div>
                <div style="font-weight:800; font-size:15px;">{{ $facture->reference }}</div>
                <div style="font-size:12px; color:var(--text-3);">
                    {{ $facture->date_facture?->format('d/m/Y H:i') }} · {{ $p['mode_paiement'] }}
                    @if($facture->urlDeVerification())
                        · <a href="{{ $facture->urlDeVerification() }}" target="_blank" rel="noopener">Voir sur le portail</a>
                    @endif
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:12px; color:var(--text-3);">HT {{ number_format((float) $facture->montant_ht, 0, ',', ' ') }} F · TVA {{ number_format((float) $facture->montant_tva, 0, ',', ' ') }} F</div>
                <div style="font-weight:800; font-size:16px;">{{ number_format((float) $facture->montant_ttc, 0, ',', ' ') }} F TTC</div>
            </div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:12px; margin:12px 0;">
            <label style="font-size:12px;">
                <span style="font-weight:700;">Point de vente</span>
                <span style="color:var(--text-3);"> — sur la facture : « {{ $p['nom_point_de_vente'] ?: 'non indiqué' }} »</span>
                <select name="point_de_vente_id" class="form-control" required>
                    <option value="">— Choisir —</option>
                    @foreach($pointsDeVente as $site)
                        <option value="{{ $site->id }}" @selected($p['point_de_vente']?->id === $site->id)>{{ $site->nom }}</option>
                    @endforeach
                </select>
            </label>
            <div style="font-size:12px;">
                <span style="font-weight:700;">Client</span><br>
                {{ $p['client_nom'] }} @if($p['client_ncc']) · NCC {{ $p['client_ncc'] }} @endif<br>
                <span style="color:var(--text-3);">
                    @if($p['client'])
                        Client existant de Selflow.
                    @elseif($p['client_ncc'] === '' && in_array(\Illuminate\Support\Str::lower($p['client_nom']), ['client de passage', 'passage'], true))
                        Vente de passage, sans fiche client.
                    @else
                        Sera créé à l’intégration.
                    @endif
                </span>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Désignation (portail)</th><th>Qté</th><th>PU HT</th><th>TVA</th><th>Article Selflow</th><th>Compte</th></tr>
                </thead>
                <tbody>
                @foreach($p['lignes'] as $i => $l)
                    <tr @if(!$l['produit']) style="background:#fffbeb;" @endif>
                        <td>{{ $l['ligne']->designation }} @if($l['ligne']->reference_article)<br><small style="color:var(--text-3);">{{ $l['ligne']->reference_article }}</small>@endif</td>
                        <td>{{ rtrim(rtrim(number_format((float) $l['ligne']->quantite, 3, ',', ' '), '0'), ',') }} {{ $l['ligne']->unite }}</td>
                        <td>{{ number_format((float) $l['ligne']->prix_unitaire, 0, ',', ' ') }} F</td>
                        <td>{{ rtrim(rtrim(number_format($l['taux'], 2, ',', ''), '0'), ',') }} %</td>
                        <td>
                            <select name="lignes[{{ $i }}][produit_id]" class="form-control" style="min-width:200px;">
                                <option value="">Ligne libre (hors catalogue)</option>
                                @foreach($articles as $a)
                                    <option value="{{ $a->id }}" @selected($l['produit']?->id === $a->id)>{{ $a->nom }}@if($a->reference) ({{ $a->reference }})@endif</option>
                                @endforeach
                            </select>
                        </td>
                        <td>
                            @if($l['produit'])
                                <span title="Compte de l'article (exception, configuration globale ou famille)">{{ $l['compte'] }}</span>
                            @else
                                <input name="lignes[{{ $i }}][compte]" class="form-control" style="width:110px;" value="{{ $l['compte'] }}" pattern="[0-9]{3,20}"
                                       title="Compte de produit (classe 7) de cette ligne hors catalogue">
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:12px;">
            <button type="submit" formaction="{{ route('admin.ventes.portail_fne.ignorer', $facture) }}" class="btn btn-outline"
                    onclick="return confirm('Écarter cette facture ? Elle ne sera plus proposée.');">
                <i class="fas fa-ban"></i> Écarter
            </button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Intégrer comme vente normalisée</button>
        </div>
    </form>
@endforeach
@endsection

@extends('admin::gabarits.application')
@section('titre', 'Factures FNE à intégrer')
@section('topbar_titre', 'Ventes — Factures FNE à intégrer')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-file-import"></i> Factures FNE à intégrer</h1>
        <p>Les pièces certifiées sur le portail de la DGI, hors de Selflow : factures et avoirs, émis et reçus. Une fois intégrées,
           ce sont des ventes, des achats et des avoirs comme les autres : ils comptent dans les listes, les soldes des tiers et la comptabilité.
           Ce que Selflow a déjà enregistré n'est jamais repris.</p>
    </div>
    <div style="display:flex; gap:8px; align-items:flex-start;">
        <a href="{{ route('admin.ventes.factures') }}" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Retour aux factures de vente</a>
        @if($factures->isNotEmpty())
        <button type="button" class="btn btn-outline" id="btn-reconnus" onclick="toutIntegrer(true)">
            <i class="fas fa-id-card"></i> Intégrer les reconnus par NCC
        </button>
        <button type="button" class="btn btn-primary" id="btn-tout-integrer" onclick="toutIntegrer(false)">
            <i class="fas fa-check-double"></i> Tout intégrer
        </button>
        @endif
    </div>
</div>
<div id="progression-integration" style="display:none; margin:0 0 14px; padding:12px 16px; border-radius:10px; background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; font-size:14px;"></div>

<script>
/*
 * « Tout intégrer » enchaîne les lots de 20 sans boîte de confirmation, et
 * montre où il en est. Il s'arrête quand il ne reste que des pièces à
 * compléter à la main (point de vente à choisir, facture d'origine absente).
 */
async function toutIntegrer(reconnus) {
    const bouton = document.getElementById(reconnus ? 'btn-reconnus' : 'btn-tout-integrer');
    const zone = document.getElementById('progression-integration');
    bouton.disabled = true;
    zone.style.display = 'block';
    let faites = 0, aLaMain = [];
    try {
        for (;;) {
            zone.textContent = 'Intégration en cours… ' + faites + ' pièce(s) intégrée(s).';
            const reponse = await fetch(@json(route('admin.ventes.portail_fne.integrer_pretes')), {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.__csrf || '' },
                body: JSON.stringify({ a_la_main: aLaMain, reconnus: !!reconnus })
            });
            if (!reponse.ok) throw new Error('echec');
            const r = await reponse.json();
            faites += r.faites; aLaMain = aLaMain.concat(r.a_la_main);
            if (r.restantes === 0 || (r.faites === 0 && r.a_la_main.length === 0)) break;
        }
        zone.textContent = faites + ' pièce(s) intégrée(s).' + (aLaMain.length ? ' ' + aLaMain.length + ' restent à compléter à la main ci-dessous.' : ' Tout est dans Selflow.');
        setTimeout(() => window.location.reload(), 2000);
    } catch (e) {
        zone.style.display = 'none';
        bouton.disabled = false;
        if (window.selflowErreurTache) window.selflowErreurTache();
    }
}
</script>

@if($factures->isEmpty())
    <div class="card" style="padding:24px; text-align:center; color:var(--text-3);">
        <i class="fas fa-circle-check" style="font-size:28px; color:#10b981;"></i>
        <p style="margin-top:8px;">Aucune facture du portail en attente : tout est déjà dans Selflow.</p>
    </div>
@endif

@if($factures->total() > 0)
<p style="color:var(--text-3); font-size:13px; margin:0 0 8px;">{{ $factures->total() }} pièce(s) en attente.</p>
@endif
<div style="max-height:calc(100vh - 260px); overflow-y:auto; padding-right:6px; border-top:1px solid var(--border);">
@foreach($factures->getCollection()->groupBy(fn ($f) => $propositions[$f->id]['nature']) as $nature => $groupe)
<h2 style="font-size:16px; margin:24px 0 10px;">{{ \App\Modules\Admin\Services\IntegrationFacturePortailService::LIBELLES[$nature] ?? 'Autres pièces' }} <span style="color:var(--text-3); font-weight:400;">({{ $groupe->count() }})</span></h2>
@foreach($groupe as $facture)
    @php
        $p = $propositions[$facture->id];
        $estAchat = in_array($p['nature'], ['achat', 'avoir_achat'], true);
        $estAvoir = in_array($p['nature'], ['avoir_vente', 'avoir_achat'], true);
    @endphp
    <form method="POST" action="{{ route('admin.ventes.portail_fne.integrer', $facture) }}" class="card" style="padding:16px; margin-bottom:16px;">
        @csrf
        <div style="display:flex; flex-wrap:wrap; gap:16px; justify-content:space-between; align-items:flex-start;">
            <div>
                <div style="font-weight:800; font-size:15px;">{{ $facture->reference }}
                    @if($p['reconnu_ncc'])
                        <span style="margin-left:6px; font-size:11px; font-weight:800; padding:2px 8px; border-radius:20px; background:#ecfdf5; color:#047857; vertical-align:middle;">
                            <i class="fas fa-id-card"></i> {{ $estAchat ? 'Fournisseur' : 'Client' }} reconnu par NCC
                        </span>
                    @endif
                </div>
                @if($estAvoir)
                    <div style="font-size:12px; color:#b45309;">Avoir de la facture {{ $p['origine'] ?: 'non indiquée' }}</div>
                @endif
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
                @if(!$estAchat)
                    <span style="color:var(--text-3);">, sur la facture : « {{ $p['nom_point_de_vente'] ?: 'non indiqué' }} »</span>
                @else
                    <span style="color:var(--text-3);">qui a reçu la pièce</span>
                @endif
                <select name="point_de_vente_id" class="form-control" required>
                    <option value="">— Choisir —</option>
                    @foreach($pointsDeVente as $site)
                        <option value="{{ $site->id }}" @selected($p['point_de_vente']?->id === $site->id)>{{ $site->nom }}</option>
                    @endforeach
                </select>
            </label>
            <div style="font-size:12px;">
                <span style="font-weight:700;">{{ $estAchat ? 'Fournisseur' : 'Client' }}</span><br>
                {{ $p['client_nom'] }} @if($p['client_ncc']) · NCC {{ $p['client_ncc'] }} @endif<br>
                <span style="color:var(--text-3);">
                    @if($estAchat)
                        Retrouvé par son NCC, ou créé à l’intégration.
                    @elseif($estAvoir)
                        Le client de la facture d’origine.
                    @elseif($p['client'])
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
            <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i>
                {{ ['vente' => 'Intégrer comme vente normalisée', 'avoir_vente' => 'Intégrer comme avoir', 'achat' => 'Intégrer comme achat', 'avoir_achat' => 'Intégrer comme avoir fournisseur'][$p['nature']] ?? 'Intégrer' }}
            </button>
        </div>
    </form>
@endforeach
@endforeach
</div>
<div style="margin-top:12px;">{{ $factures->links() }}</div>
@endsection

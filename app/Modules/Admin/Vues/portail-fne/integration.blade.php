@extends('admin::gabarits.application')
@section('titre', 'Factures FNE à intégrer')
@section('topbar_titre', 'Ventes — Factures FNE à intégrer')

@section('contenu')
{{-- 24/11/2026 : en-tête compact. Le titre, le nombre en attente et les
     boutons sur une seule ligne ; l'explication se déplie au besoin. --}}
<div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; min-width:0;">
        <h1 style="font-size:20px; margin:0; white-space:nowrap;"><i class="fas fa-file-import" style="color:var(--primary);"></i> Factures FNE à intégrer</h1>
        @if($factures->total() > 0)
            <span style="background:#fef3c7; color:#92400e; border-radius:20px; padding:3px 10px; font-size:12px; font-weight:800; white-space:nowrap;">{{ $factures->total() }} en attente</span>
        @endif
        <details style="font-size:12.5px; color:var(--text-2);">
            <summary style="cursor:pointer; color:var(--primary); font-weight:600; list-style:none;"><i class="fas fa-circle-info"></i> De quoi s'agit-il ?</summary>
            <div style="margin-top:6px; max-width:640px; line-height:1.6;">Les pièces certifiées sur le portail de la DGI, hors de Selflow : factures et avoirs, émis et reçus. Une fois intégrées, ce sont des ventes, des achats et des avoirs comme les autres : elles comptent dans les listes, les soldes des tiers et la comptabilité. Ce que Selflow a déjà enregistré n'est jamais repris.</div>
        </details>
    </div>
    <div style="display:flex; gap:6px; flex-wrap:wrap;">
        <a href="{{ route('admin.ventes.factures') }}" class="btn btn-outline btn-sm" style="white-space:nowrap;"><i class="fas fa-arrow-left"></i> Factures de vente</a>
        @if($factures->isNotEmpty())
        <button type="button" class="btn btn-outline btn-sm" id="btn-reconnus" onclick="toutIntegrer(true)" style="white-space:nowrap;">
            <i class="fas fa-id-card"></i> Intégrer les reconnus par NCC
        </button>
        <button type="button" class="btn btn-primary btn-sm" id="btn-tout-integrer" onclick="toutIntegrer(false)" style="white-space:nowrap;">
            <i class="fas fa-check-double"></i> Tout intégrer
        </button>
        @endif
    </div>
</div>
<div id="progression-integration" style="display:none; margin:0 0 14px; padding:12px 16px; border-radius:10px; background:#eff6ff; border:1px solid #bfdbfe; color:var(--marque-800, #1e40af); font-size:14px;"></div>

<script>
/*
 * « Tout intégrer » enchaîne les lots de 20 sans boîte de confirmation, et
 * montre où il en est. Il s'arrête quand il ne reste que des pièces à
 * compléter à la main (point de vente à choisir, facture d'origine absente).
 */
// « Tout intégrer » tourne sur le serveur (23/11/2026) : on peut quitter
// la page, changer d'entreprise ; la progression se relit au retour.
function afficherEtat(e) {
    const zone = document.getElementById('progression-integration');
    if (!e) return;
    zone.style.display = 'block';
    const total = e.faites + e.restantes + e.a_la_main.length;
    const pct = total ? Math.round(100 * (e.faites + e.a_la_main.length) / total) : 100;
    if (e.etat === 'termine') {
        zone.innerHTML = '<strong>' + e.faites + ' pièce(s) intégrée(s).</strong>' + (e.a_la_main.length ? ' ' + e.a_la_main.length + ' restent à compléter à la main ci-dessous.' : ' Tout est dans Selflow.');
        return;
    }
    zone.innerHTML = '<div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap;"><strong><i class="fas fa-spinner fa-spin"></i> Intégration en cours sur le serveur</strong><span>' + e.faites + ' intégrée(s) · ' + e.restantes + ' restante(s)</span></div>'
        + '<div style="height:8px; background:var(--border); border-radius:6px; margin-top:8px; overflow:hidden;"><div style="height:100%; width:' + pct + '%; background:var(--primary); transition:width .4s;"></div></div>'
        + '<div style="font-size:12px; color:var(--text-3); margin-top:6px;">Vous pouvez quitter cette page ou changer d\'entreprise : le travail continue, et cette barre reprend à votre retour.</div>';
}
let suivi = null;
function suivre() {
    clearInterval(suivi);
    suivi = setInterval(async function () {
        try {
            const r = await fetch(@json(route('admin.ventes.portail_fne.etat')), { headers: { 'Accept': 'application/json' } });
            const e = await r.json();
            afficherEtat(e);
            if (!e || e.etat === 'termine') { clearInterval(suivi); setTimeout(() => window.location.reload(), 2500); }
        } catch (err) { /* réseau : on réessaie au tour suivant */ }
    }, 4000);
}
async function toutIntegrer(reconnus) {
    const bouton = document.getElementById(reconnus ? 'btn-reconnus' : 'btn-tout-integrer');
    bouton.disabled = true;
    try {
        const reponse = await fetch(@json(route('admin.ventes.portail_fne.arriere_plan')), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.__csrf || '' },
            body: JSON.stringify({ reconnus: !!reconnus })
        });
        if (!reponse.ok) throw new Error('echec');
        afficherEtat(await reponse.json());
        suivre();
    } catch (e) {
        bouton.disabled = false;
        if (window.selflowErreurTache) window.selflowErreurTache();
    }
}
document.addEventListener('DOMContentLoaded', function () {
    const etat = @json($arrierePlan);
    if (etat && etat.etat !== 'termine') { afficherEtat(etat); suivre(); }
});
</script>

@if($factures->isEmpty())
    <div class="card" style="padding:24px; text-align:center; color:var(--text-3);">
        <i class="fas fa-circle-check" style="font-size:28px; color:#10b981;"></i>
        <p style="margin-top:8px;">Aucune facture du portail en attente : tout est déjà dans Selflow.</p>
    </div>
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
                    <option value="">Choisir</option>
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

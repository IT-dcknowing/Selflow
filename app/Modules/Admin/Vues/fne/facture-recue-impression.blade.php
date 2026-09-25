{{--
    La facture reçue, telle que le portail la décrit.

    ## Pourquoi cette vue n'est pas dans `Vues/factures/`

    Ce dossier porte les documents que **Selflow émet** et que la DGI a
    certifiés ; il est gelé, et ses blocs de certification attestent que
    l'application a établi la pièce. Une facture reçue n'a pas été établie par
    nous : la reconstituer sous le même gabarit produirait un document qui se
    donnerait pour l'original du fournisseur.

    D'où une vue à part, et un bandeau qui dit ce que le document est : une
    **copie d'après le relevé**, avec de quoi la confronter à la source.

    Le code QR n'est pas une signature de Selflow : il encode l'adresse de
    vérification de la DGI, celle qui montre la pièce authentique.
--}}
@extends('admin::gabarits.application')
@section('titre', 'Facture reçue ' . $facture->reference)
@section('topbar_titre', 'Achats — Facture reçue du portail FNE')

@section('styles')
<style>
    .barre { background:#fff; border:1px solid var(--border); border-radius:12px; padding:16px 20px; margin-bottom:18px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }

    .avertissement { background:#fffbeb; border:1px solid #fcd34d; border-left:4px solid #f59e0b; border-radius:10px; padding:14px 18px; margin-bottom:18px; font-size:13px; color:#78350f; line-height:1.6; }
    .avertissement strong { color:#7c2d12; }

    .feuille { background:#fff; border:1px solid var(--border); border-radius:14px; padding:38px 42px; max-width:900px; margin:0 auto; }

    .tete { display:flex; justify-content:space-between; gap:30px; border-bottom:2px solid #0D1B3E; padding-bottom:18px; margin-bottom:24px; }
    .tete h1 { font-size:20px; margin:0 0 4px; color:#0D1B3E; letter-spacing:.02em; }
    .tete .ref { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:15px; font-weight:700; color:#1d4ed8; }

    .parties { display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:26px; }
    .partie { border:1px solid var(--border); border-radius:10px; padding:14px 16px; }
    .partie .r { font-size:10px; text-transform:uppercase; letter-spacing:.06em; color:var(--text-3); font-weight:700; margin-bottom:6px; }
    .partie .nom { font-weight:700; font-size:14px; margin-bottom:4px; }
    .partie div { font-size:12px; color:var(--text-2); line-height:1.6; }

    .meta { display:flex; gap:26px; flex-wrap:wrap; margin-bottom:22px; font-size:12px; }
    .meta .c { color:var(--text-3); text-transform:uppercase; font-size:10px; letter-spacing:.05em; font-weight:700; }
    .meta .v { font-weight:600; }

    table.lignes { width:100%; border-collapse:collapse; margin-bottom:22px; font-size:12px; }
    table.lignes th { background:#f8fafc; text-align:left; padding:9px 10px; border-bottom:1px solid var(--border); font-size:10px; text-transform:uppercase; letter-spacing:.04em; color:var(--text-3); }
    table.lignes td { padding:9px 10px; border-bottom:1px solid #eef1f5; }
    table.lignes .n { text-align:right; font-variant-numeric:tabular-nums; }

    .totaux { margin-left:auto; width:320px; font-size:13px; }
    .totaux div { display:flex; justify-content:space-between; padding:6px 0; }
    .totaux .fin { border-top:2px solid #0D1B3E; margin-top:6px; padding-top:10px; font-weight:800; font-size:15px; }

    .certif { margin-top:28px; border:1px solid var(--border); border-radius:10px; padding:16px; display:flex; gap:18px; align-items:center; }
    .certif .txt { font-size:11px; line-height:1.7; color:var(--text-2); }
    .certif .txt strong { font-family:ui-monospace,Menlo,Consolas,monospace; }

    @media print {
        .barre, .avertissement, .sidebar, .topbar, nav { display:none !important; }
        .feuille { border:none; border-radius:0; padding:0; max-width:none; }
        body { background:#fff; }
    }
</style>
@endsection

@section('contenu')

<div class="barre">
    <a href="{{ route('admin.achats.factures') }}" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Retour</a>
    <button type="button" onclick="window.print()" class="btn btn-primary btn-sm"><i class="fas fa-print"></i> Imprimer</button>
    @if($facture->urlDeVerification())
        <a href="{{ $facture->urlDeVerification() }}" target="_blank" class="btn btn-outline btn-sm">
            <i class="fas fa-shield-halved"></i> Vérifier chez la DGI
        </a>
    @endif
    @if(!$facture->achat_id)
        <a href="{{ route('admin.achats.factures', ['etape' => 'Facture', 'section' => 'dgi']) }}" class="btn btn-outline btn-sm"><i class="fas fa-link"></i> Rapprocher d'un achat</a>
    @endif
</div>

{{-- Le bandeau n'est pas décoratif : il sépare ce document de la pièce
     originale, que seul le fournisseur détient et que seule la DGI certifie. --}}
<div class="avertissement">
    <strong>Copie d'après le relevé du portail FNE.</strong>
    Ce document est reconstitué par Selflow à partir de ce que la DGI a communiqué le
    {{ $facture->date_scraping?->format('d/m/Y') ?? '—' }} ; il n'est <strong>pas</strong> la facture
    originale certifiée, que {{ $facture->emetteur_nom ?: "l'émetteur" }} a établie.
    @if($facture->urlDeVerification())
        La pièce authentique se consulte chez la DGI, à l'adresse de vérification ci-dessous.
    @endif
</div>

<div class="feuille">

    <div class="tete">
        <div>
            <h1>{{ $facture->libelleDuSousType() }}</h1>
            <div class="ref">{{ $facture->reference }}</div>
        </div>
        <div style="text-align:right; font-size:12px; color:var(--text-2);">
            <div style="font-weight:700; color:var(--text);">{{ $facture->date_facture?->format('d/m/Y') ?? '—' }}</div>
            @if($facture->statut_portail)
                <div>Statut portail : {{ $facture->statut_portail }}</div>
            @endif
            @if($facture->est_rne)
                <div>Reçu normalisé{{ $facture->numero_rne ? ' — ' . $facture->numero_rne : '' }}</div>
            @endif
        </div>
    </div>

    <div class="parties">
        <div class="partie">
            <div class="r">Émetteur — le fournisseur</div>
            <div class="nom">{{ $facture->emetteur_nom ?: '—' }}</div>
            @if($facture->emetteur_ncc)<div>NCC : {{ $facture->emetteur_ncc }}</div>@endif
            @if($facture->emetteur_rccm)<div>RCCM : {{ $facture->emetteur_rccm }}</div>@endif
        </div>
        <div class="partie">
            <div class="r">Destinataire — vous</div>
            <div class="nom">{{ $entreprise->nom }}</div>
            @if($entreprise->ncc)<div>NCC : {{ $entreprise->ncc }}</div>@endif
            @if($facture->pointDeVente)
                <div>Point de vente : {{ $facture->pointDeVente->nom }}</div>
            @else
                {{-- Le portail ne dit pas de quel site relève une facture reçue :
                     ses champs de point de vente décrivent l'émetteur. --}}
                <div style="font-style:italic; color:var(--text-3);">Point de vente non affecté</div>
            @endif
        </div>
    </div>

    <div class="meta">
        @if($facture->moyen_paiement)
            <div><span class="c">Paiement</span><br><span class="v">{{ $facture->moyen_paiement }}</span></div>
        @endif
        @if($facture->devise)
            <div><span class="c">Devise</span><br><span class="v">{{ $facture->devise }} @if($facture->taux_change) (taux {{ rtrim(rtrim(number_format((float) $facture->taux_change, 6, ',', ' '), '0'), ',') }}) @endif</span></div>
        @endif
        <div><span class="c">Relevé le</span><br><span class="v">{{ $facture->date_scraping?->format('d/m/Y') ?? '—' }}</span></div>
        <div>
            <span class="c">TVA déductible</span><br>
            {{-- Un reçu normalisé n'en porte pas, un bordereau d'achat non plus :
                 il constate un achat auprès d'un tiers non immatriculé. --}}
            <span class="v">{{ $facture->tvaDeductible() ? 'Oui' : 'Non' }}</span>
        </div>
    </div>

    @if($facture->lignes->isNotEmpty())
        <table class="lignes">
            <thead>
                <tr>
                    <th>Désignation</th>
                    <th>Réf.</th>
                    <th class="n">Qté</th>
                    <th>Unité</th>
                    <th class="n">P.U.</th>
                    <th class="n">Remise</th>
                    <th class="n">TVA</th>
                    <th class="n">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($facture->lignes as $ligne)
                    <tr>
                        <td>{{ $ligne->designation ?: '—' }}</td>
                        <td style="color:var(--text-3);">{{ $ligne->reference_article ?: '—' }}</td>
                        <td class="n">{{ rtrim(rtrim(number_format((float) $ligne->quantite, 3, ',', ' '), '0'), ',') }}</td>
                        <td>{{ $ligne->unite ?: '—' }}</td>
                        <td class="n">{{ number_format((float) $ligne->prix_unitaire, 0, ',', ' ') }}</td>
                        <td class="n">{{ number_format((float) $ligne->remise, 0, ',', ' ') }}</td>
                        <td class="n">{{ number_format((float) $ligne->montant_tva, 0, ',', ' ') }}</td>
                        <td class="n" style="font-weight:600;">
                            {{ number_format(((float) $ligne->quantite * (float) $ligne->prix_unitaire) - (float) $ligne->remise, 0, ',', ' ') }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p style="font-size:12px; color:var(--text-3); font-style:italic; margin-bottom:22px;">
            Le relevé ne porte aucun détail de ligne pour cette pièce — seuls les totaux ont été communiqués.
        </p>
    @endif

    <div class="totaux">
        <div><span>Montant HT</span><span>{{ number_format((float) $facture->montant_ht, 0, ',', ' ') }} F</span></div>
        @if((float) $facture->remise != 0)
            <div><span>Remise</span><span>− {{ number_format((float) $facture->remise, 0, ',', ' ') }} F</span></div>
        @endif
        <div><span>TVA</span><span>{{ number_format((float) $facture->montant_tva, 0, ',', ' ') }} F</span></div>
        @if((float) $facture->autres_taxes != 0)
            <div><span>Autres taxes</span><span>{{ number_format((float) $facture->autres_taxes, 0, ',', ' ') }} F</span></div>
        @endif
        @if((float) $facture->timbre_fiscal != 0)
            <div><span>Timbre de quittance</span><span>{{ number_format((float) $facture->timbre_fiscal, 0, ',', ' ') }} F</span></div>
        @endif
        <div class="fin"><span>Net à payer</span><span>{{ number_format((float) ($facture->net_a_payer ?: $facture->montant_ttc), 0, ',', ' ') }} F</span></div>
    </div>

    <div style="clear:both;"></div>

    @if($facture->urlDeVerification())
        <div class="certif">
            @if($qr)
                <img src="{{ $qr }}" alt="Code de vérification DGI" style="width:110px; height:110px; flex-shrink:0;">
            @endif
            <div class="txt">
                <div style="font-weight:700; color:var(--text); margin-bottom:5px;">Vérification auprès de la DGI</div>
                <div>N° FNE : <strong>{{ $facture->reference }}</strong></div>
                @if($facture->token)<div>Code de vérification : <strong>{{ $facture->token }}</strong></div>@endif
                <div style="margin-top:5px;">{{ $facture->urlDeVerification() }}</div>
                <div style="margin-top:7px; color:var(--text-3);">
                    Le code ci-contre mène à la pièce que la DGI détient. Il n'atteste rien de ce document-ci.
                </div>
            </div>
        </div>
    @endif

</div>

<script>
// Même convention que les autres documents de Selflow : `?download=1` lance
// l'impression dès l'ouverture, ce qui permet à un bouton de télécharger sans
// que l'utilisateur ait à passer par le menu du navigateur.
if (new URLSearchParams(window.location.search).get('download') === '1') {
    window.addEventListener('load', () => setTimeout(() => window.print(), 400));
}
</script>

@endsection

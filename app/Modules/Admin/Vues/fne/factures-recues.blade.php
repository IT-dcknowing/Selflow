@extends('admin::gabarits.application')
@section('titre', 'Factures reçues du portail FNE')
@section('topbar_titre', 'Achats — Factures reçues du portail FNE')

@section('styles')
<style>
    .kpi-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:26px; }
    .kpi-card {
        background:#fff; border:1px solid var(--border); border-radius:14px;
        padding:20px 24px; position:relative; overflow:hidden;
    }
    .kpi-card::before {
        content:''; position:absolute; top:0; left:0; width:4px; height:100%;
        background:var(--primary);
    }
    .kpi-card.alerte::before { background:#E53E3E; }
    .kpi-card.ok::before     { background:#10b981; }
    .kpi-card .lbl { font-size:11px; text-transform:uppercase; font-weight:700; color:var(--text-3); letter-spacing:.4px; margin-bottom:8px; }
    .kpi-card .val { font-size:32px; font-weight:800; color:var(--text-1); }
    .kpi-card .sub { font-size:12px; color:var(--text-3); margin-top:6px; }

    .fne-tabs { display:flex; gap:0; border-bottom:2px solid var(--border); margin-bottom:24px; flex-wrap:wrap; }
    .fne-tab  { padding:10px 20px; font-size:13px; font-weight:600; color:var(--text-3); cursor:pointer; text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-2px; transition:all .15s; }
    .fne-tab:hover  { color:var(--primary); }
    .fne-tab.active { color:var(--primary); border-bottom-color:var(--primary); }

    .filtres { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:22px; }
    .filtre {
        padding:7px 15px; border-radius:20px; border:1px solid var(--border);
        background:#fff; font-size:12px; font-weight:600; color:var(--text-2);
        text-decoration:none; transition:all .15s;
    }
    .filtre:hover  { border-color:var(--primary); color:var(--primary); }
    .filtre.active { background:var(--primary); border-color:var(--primary); color:#fff; }
    .filtre .n { opacity:.75; margin-left:5px; }

    .facture { border:1px solid var(--border); border-radius:14px; background:#fff; margin-bottom:16px; overflow:hidden; }
    .facture-tete {
        display:flex; align-items:center; gap:14px; flex-wrap:wrap;
        padding:16px 20px; border-bottom:1px solid var(--border); background:#fbfcfe;
    }
    .facture-ref { font-family:ui-monospace,Menlo,Consolas,monospace; font-weight:700; font-size:14px; color:var(--text-1); }
    .facture-corps { padding:18px 20px; }

    .puce { border-radius:20px; padding:3px 11px; font-size:11px; font-weight:700; letter-spacing:.2px; }
    .puce-a-rapprocher { background:#fffbeb; color:#92400e; }
    .puce-rapprochee   { background:#ecfdf5; color:#065f46; }
    .puce-orpheline    { background:#fef2f2; color:#991b1b; }
    .puce-ecartee      { background:#f3f4f6; color:#4b5563; }
    .puce-type         { background:#eff6ff; color:#1e40af; }

    .grille { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px 20px; }
    .champ .lbl { font-size:10px; text-transform:uppercase; font-weight:700; color:var(--text-3); letter-spacing:.4px; margin-bottom:3px; }
    .champ .val { font-size:14px; color:var(--text-1); font-weight:600; }

    .proposition { margin-top:16px; padding:14px 16px; border-radius:12px; border:1px solid #bfdbfe; background:#eff6ff; }
    .proposition.ecart { border-color:#fde68a; background:#fffbeb; }
    .proposition.rien  { border-color:var(--border); background:#f9fafb; }
    .proposition .titre { font-size:12px; font-weight:700; color:var(--text-2); margin-bottom:6px; }

    .lignes { width:100%; border-collapse:collapse; margin-top:14px; font-size:13px; }
    .lignes th { text-align:left; font-size:10px; text-transform:uppercase; color:var(--text-3); letter-spacing:.4px; padding:6px 10px; border-bottom:1px solid var(--border); }
    .lignes td { padding:7px 10px; border-bottom:1px solid #f1f3f7; }
    .lignes td.num { text-align:right; font-variant-numeric:tabular-nums; }

    .actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:16px; }

    .vide { text-align:center; padding:60px 20px; color:var(--text-3); }
    .vide i { font-size:44px; opacity:.35; margin-bottom:14px; display:block; }

    .tableau-scroll { overflow-x:auto; }
    @media (max-width:760px) { .kpi-grid { grid-template-columns:1fr; } }
</style>
@endsection

@section('contenu')

{{-- Onglets FNE : seulement si les ecrans qu'ils visent sont atteignables --}}
@if($aFne)
<div class="fne-tabs">
    <a href="{{ route('admin.fne.gestion') }}"   class="fne-tab"><i class="fas fa-chart-bar"></i> Gestion</a>
    <a href="{{ route('admin.fne.situation') }}" class="fne-tab"><i class="fas fa-balance-scale"></i> Situation</a>
    <a href="{{ route('admin.fne.factures') }}"  class="fne-tab"><i class="fas fa-file-invoice"></i> Factures</a>
    <a href="{{ route('admin.achats.factures_recues') }}" class="fne-tab active"><i class="fas fa-inbox"></i> Factures reçues
        @if($aRapprocher > 0)
        <span style="background:#f59e0b;color:#fff;border-radius:20px;padding:1px 7px;font-size:10px;margin-left:4px;">{{ $aRapprocher }}</span>
        @endif
    </a>
    <a href="{{ route('admin.fne.stickers') }}"  class="fne-tab"><i class="fas fa-stamp"></i> Stickers</a>
    <a href="{{ route('admin.fne.rejets') }}"    class="fne-tab"><i class="fas fa-triangle-exclamation"></i> Rejets</a>
</div>
@endif

@if(session('succes'))
<div style="padding:12px 16px;border-radius:12px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;margin-bottom:18px;font-size:13px;">
    <i class="fas fa-check-circle"></i> {{ session('succes') }}
</div>
@endif
@if(session('erreur'))
<div style="padding:12px 16px;border-radius:12px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;margin-bottom:18px;font-size:13px;">
    <i class="fas fa-circle-exclamation"></i> {{ session('erreur') }}
</div>
@endif

{{-- KPIs --}}
<div class="kpi-grid">
    <div class="kpi-card {{ $aRapprocher > 0 ? 'alerte' : 'ok' }}">
        <div class="lbl">À rapprocher</div>
        <div class="val">{{ $aRapprocher }}</div>
        <div class="sub">pièces sans achat en face</div>
    </div>
    <div class="kpi-card">
        <div class="lbl">Reçues au total</div>
        <div class="val">{{ $total }}</div>
        <div class="sub">depuis le premier relevé</div>
    </div>
    <div class="kpi-card">
        <div class="lbl">Montant TTC</div>
        <div class="val" style="font-size:26px;">{{ number_format((float) $montantTotal, 0, ',', ' ') }}</div>
        <div class="sub">francs CFA, toutes pièces</div>
    </div>
</div>

{{-- État du relevé --}}
<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--text-3);margin-bottom:20px;">
    <i class="fas fa-cloud-arrow-down"></i>
    @if($dernierPassage)
        Dernier relevé des factures reçues :
        <strong style="color:var(--text-1);">{{ $dernierPassage->format('d/m/Y') }}</strong>
    @else
        <span style="color:#92400e;">
            Aucun relevé de factures reçues — lancer
            <code>node achats.js &lt;NCC&gt;</code> dans <code>SCRAPER-PORTAIL-FNE</code>.
        </span>
    @endif
</div>

{{-- Ce que cet écran ne fait pas : dit une fois, en haut, plutôt que répété --}}
<div style="padding:12px 16px;border-radius:12px;background:#f9fafb;border:1px solid var(--border);margin-bottom:22px;font-size:12.5px;color:var(--text-2);line-height:1.6;">
    <strong>Ces factures sont un constat de la DGI, pas des achats.</strong>
    Rattacher relie une pièce du portail à un achat <em>déjà saisi</em> dans Selflow ;
    aucun achat n'est créé, aucune écriture comptable produite, aucune TVA déduite.
    Un écart de montant est montré — c'est à vous de trancher lequel des deux a raison.
</div>

{{-- Filtres --}}
<div class="filtres">
    <a href="{{ route('admin.achats.factures_recues') }}"
       class="filtre {{ $statutActif === null ? 'active' : '' }}">Toutes <span class="n">{{ $total }}</span></a>
    @foreach($filtres as $cle => $filtre)
        <a href="{{ route('admin.achats.factures_recues', ['statut' => $cle]) }}"
           class="filtre {{ $statutActif === $cle ? 'active' : '' }}">
            {{ $filtre['libelle'] }} <span class="n">{{ $filtre['total'] }}</span>
        </a>
    @endforeach
</div>

@forelse($factures as $facture)
    @php
        $propose = $propositions[$facture->id] ?? ['fournisseur' => null, 'achat' => null, 'ecart_ttc' => null];
        $puces = [
            \App\Modules\Admin\Modeles\PortailFneFactureRecue::A_RAPPROCHER => ['puce-a-rapprocher', 'À rapprocher'],
            \App\Modules\Admin\Modeles\PortailFneFactureRecue::RAPPROCHEE   => ['puce-rapprochee', 'Rapprochée'],
            \App\Modules\Admin\Modeles\PortailFneFactureRecue::ORPHELINE    => ['puce-orpheline', 'Fournisseur inconnu'],
            \App\Modules\Admin\Modeles\PortailFneFactureRecue::ECARTEE      => ['puce-ecartee', 'Écartée'],
        ];
        [$classePuce, $libellePuce] = $puces[$facture->statut_rapprochement] ?? ['puce-ecartee', $facture->statut_rapprochement];
    @endphp

    <div class="facture">
        <div class="facture-tete">
            <span class="facture-ref">{{ $facture->reference }}</span>
            <span class="puce {{ $classePuce }}">{{ $libellePuce }}</span>
            <span class="puce puce-type">{{ $facture->libelleDuSousType() }}</span>
            @if($facture->est_rne)
                <span class="puce puce-type">Reçu normalisé</span>
            @endif
            <span style="margin-left:auto;font-size:12px;color:var(--text-3);">
                {{ $facture->date_facture?->format('d/m/Y') ?? '—' }}
            </span>
        </div>

        <div class="facture-corps">
            <div class="grille">
                <div class="champ">
                    <div class="lbl">Émetteur</div>
                    <div class="val">{{ $facture->emetteur_nom ?? '—' }}</div>
                    <div style="font-size:11px;color:var(--text-3);">NCC {{ $facture->emetteur_ncc ?? '(absent)' }}</div>
                </div>
                <div class="champ">
                    <div class="lbl">Montant HT</div>
                    <div class="val">{{ number_format((float) $facture->montant_ht, 0, ',', ' ') }}</div>
                </div>
                <div class="champ">
                    <div class="lbl">TVA</div>
                    <div class="val">{{ number_format((float) $facture->montant_tva, 0, ',', ' ') }}</div>
                    <div style="font-size:11px;color:{{ $facture->tvaDeductible() ? '#065f46' : '#92400e' }};">
                        {{ $facture->tvaDeductible() ? 'déductible' : 'non déductible' }}
                    </div>
                </div>
                <div class="champ">
                    <div class="lbl">Net à payer</div>
                    <div class="val">{{ number_format((float) $facture->net_a_payer, 0, ',', ' ') }}</div>
                </div>
            </div>

            {{-- Le rapprochement proposé --}}
            @if($facture->achat_id)
                <div class="proposition">
                    <div class="titre">Rattachée</div>
                    Achat <strong>{{ $facture->achat?->numero_facture ?? '#' . $facture->achat_id }}</strong> de Selflow.
                    @if($facture->note_rapprochement)
                        <div style="margin-top:5px;font-size:12px;color:#92400e;">{{ $facture->note_rapprochement }}</div>
                    @endif
                </div>
            @elseif(!$propose['fournisseur'])
                <div class="proposition rien">
                    <div class="titre">Aucun fournisseur ne porte ce NCC</div>
                    Créez le fournisseur avec le NCC <strong>{{ $facture->emetteur_ncc ?? '(absent du relevé)' }}</strong>,
                    puis revenez : le rapprochement se fera tout seul.
                    <div style="margin-top:5px;font-size:12px;color:var(--text-3);">
                        Le rapprochement se fait par NCC, jamais par la raison sociale — deux noms se ressemblent, deux NCC non.
                    </div>
                </div>
            @elseif(!$propose['achat'])
                <div class="proposition rien">
                    <div class="titre">Fournisseur reconnu, aucun achat en face</div>
                    <strong>{{ $propose['fournisseur']->nom }}</strong> existe dans Selflow, mais aucun achat
                    n'est daté du {{ $facture->date_facture?->format('d/m/Y') ?? '—' }}.
                    Saisissez l'achat, puis revenez le rattacher.
                </div>
            @else
                <div class="proposition {{ $propose['ecart_ttc'] != 0 ? 'ecart' : '' }}">
                    <div class="titre">Achat candidat</div>
                    <strong>{{ $propose['achat']->numero_facture }}</strong>
                    chez {{ $propose['fournisseur']->nom }} —
                    {{ number_format((float) $propose['achat']->montant_ttc, 0, ',', ' ') }} F TTC.
                    @if($propose['ecart_ttc'] != 0)
                        <div style="margin-top:6px;font-weight:700;color:#92400e;">
                            Écart de {{ number_format((float) $propose['ecart_ttc'], 2, ',', ' ') }} F
                            avec ce que la DGI détient.
                        </div>
                    @else
                        <div style="margin-top:6px;color:#065f46;">Les montants concordent.</div>
                    @endif
                </div>
            @endif

            {{-- Le détail des lignes --}}
            @if($facture->lignes->isNotEmpty())
            <div class="tableau-scroll">
                <table class="lignes">
                    <thead>
                        <tr>
                            <th>Désignation</th><th>Réf.</th>
                            <th style="text-align:right;">Qté</th>
                            <th style="text-align:right;">P.U.</th>
                            <th style="text-align:right;">HT</th>
                            <th style="text-align:right;">TVA</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($facture->lignes as $ligne)
                        <tr>
                            <td>{{ $ligne->designation ?? '—' }}</td>
                            <td style="color:var(--text-3);">{{ $ligne->reference_article ?? '—' }}</td>
                            <td class="num">{{ rtrim(rtrim(number_format((float) $ligne->quantite, 3, ',', ' '), '0'), ',') }} {{ $ligne->unite }}</td>
                            <td class="num">{{ number_format((float) $ligne->prix_unitaire, 0, ',', ' ') }}</td>
                            <td class="num">{{ number_format($ligne->montantHt(), 0, ',', ' ') }}</td>
                            <td class="num">{{ number_format((float) $ligne->montant_tva, 0, ',', ' ') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @endif

            <div class="actions">
                @if($facture->achat_id)
                    <form method="POST" action="{{ route('admin.achats.factures_recues.detacher', $facture) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline btn-sm">
                            <i class="fas fa-link-slash"></i> Détacher
                        </button>
                    </form>
                @elseif($propose['achat'])
                    <form method="POST" action="{{ route('admin.achats.factures_recues.rattacher', $facture) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-link"></i> Rattacher à {{ $propose['achat']->numero_facture }}
                        </button>
                    </form>
                @endif

                @if($facture->statut_rapprochement !== \App\Modules\Admin\Modeles\PortailFneFactureRecue::ECARTEE)
                    <form method="POST" action="{{ route('admin.achats.factures_recues.ecarter', $facture) }}">
                        @csrf
                        <input type="hidden" name="motif" value="">
                        <button type="submit" class="btn btn-outline btn-sm">
                            <i class="fas fa-eye-slash"></i> Écarter
                        </button>
                    </form>
                @endif

                @if($facture->token)
                    <span style="align-self:center;font-size:11px;color:var(--text-3);font-family:ui-monospace,Menlo,Consolas,monospace;">
                        vérification : {{ $facture->token }}
                    </span>
                @endif
            </div>
        </div>
    </div>
@empty
    <div class="vide">
        <i class="fas fa-inbox"></i>
        @if($statutActif)
            Aucune facture dans « {{ $filtres[$statutActif]['libelle'] ?? $statutActif }} ».
        @else
            <div style="font-weight:600;color:var(--text-2);margin-bottom:6px;">Aucune facture reçue relevée au portail.</div>
            Une facture n'apparaît ici que si un fournisseur l'a certifiée à la DGI
            <strong>en portant votre NCC</strong>. Tant qu'aucun ne l'a fait, cet écran reste vide —
            ce n'est pas une panne.
        @endif
    </div>
@endforelse

{{ $factures->links() }}

@endsection

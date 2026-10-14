@extends('admin::gabarits.application')
@php
    $N = \App\Modules\Admin\Modeles\B2bNegotiation::class;
    $estClient = $role === $N::CLIENT;
    $autre = $estClient ? $negociation->entrepriseFournisseur : $negociation->entrepriseClient;
    $prefixe = $estClient ? 'admin.b2b.client.' : 'admin.b2b.fournisseur.';
    $statut = $negociation->statut;
    $enDiscussion = in_array($statut, $N::EN_DISCUSSION, true);
    $refusable = in_array($statut, $N::REFUSABLES, true);
    $reference = $negociation->reference_commande ?? ('B2B-' . strtoupper(substr($negociation->uuid, 0, 6)));
    $lignes = $negociation->produits_demandes ?? [];
    $etapes = ['Demande', 'Négociation', 'Commande', 'Confirmée', 'Livrée', 'Réceptionnée'];
    $rang = match ($statut) {
        'RFQ' => 0, 'Negociation_Fournisseur', 'Negociation_Client', 'Valide' => 1,
        'Commande' => 2, 'Confirme' => 3, 'Livre' => 4, 'Termine' => 5, default => -1,
    };
    $f = fn ($m) => number_format((float) $m, 0, ',', ' ') . ' F';
    $q = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', ' '), '0'), ',');
@endphp
@section('titre', 'B2B ' . $reference)
@section('topbar_titre', 'B2B — ' . ($estClient ? 'Négociation avec mon fournisseur' : 'Demande de mon client'))

@section('styles')
<style>
    .b2b-entete { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:16px; }
    .b2b-etapes { display:flex; gap:6px; margin-bottom:18px; flex-wrap:wrap; }
    .b2b-etapes span { flex:1; min-width:110px; text-align:center; padding:8px 6px; border-radius:8px; font-size:12px; font-weight:700; background:var(--bg2); border:1px solid var(--border); color:var(--text-3); }
    .b2b-etapes span.fait { background:#ecfdf5; border-color:#a7f3d0; color:#047857; }
    .b2b-etapes span.courant { background:var(--primary); border-color:var(--primary); color:#fff; }
    .b2b-grille { display:grid; grid-template-columns:minmax(0, 1.6fr) minmax(300px, 1fr); gap:18px; align-items:start; }
    @media (max-width: 1000px) { .b2b-grille { grid-template-columns: 1fr; } }
    .b2b-fil { display:flex; flex-direction:column; gap:10px; max-height:520px; overflow-y:auto; padding:4px 2px; }
    .b2b-bulle { max-width:88%; padding:10px 12px; border-radius:12px; font-size:13px; line-height:1.55; white-space:pre-line; }
    .b2b-bulle.moi { align-self:flex-end; background:#eff6ff; border:1px solid #bfdbfe; }
    .b2b-bulle.lui { align-self:flex-start; background:var(--bg2); border:1px solid var(--border); }
    .b2b-bulle.action { border-style:dashed; }
    .b2b-bulle .meta { font-size:11px; color:var(--text-3); margin-bottom:4px; font-weight:600; }
    .b2b-actions form { margin:0; }
    .b2b-actions .btn { width:100%; justify-content:center; margin-bottom:8px; }
    .b2b-total td { padding:10px 16px; border-top:1px solid var(--border); }
</style>
@endsection

@section('contenu')
<div class="b2b-entete">
    <div>
        <a href="{{ route($estClient ? 'admin.b2b.negociations.client' : 'admin.b2b.negociations.fournisseur') }}" style="font-size:12.5px; color:var(--text-3); text-decoration:none;">
            <i class="fas fa-arrow-left"></i> {{ $estClient ? 'Mes négociations B2B' : 'Demandes B2B reçues' }}
        </a>
        <h1 style="font-size:22px; font-weight:800; margin:6px 0 4px;">{{ $negociation->type_demande === 'commande' ? 'Commande' : 'Demande de prix' }} {{ $reference }}</h1>
        <div style="font-size:13px; color:var(--text-2);">
            {{ $estClient ? 'Fournisseur' : 'Client' }} : <strong>{{ $autre?->nom }}</strong> — NCC {{ $autre?->ncc }}
        </div>
    </div>
    <span style="padding:8px 14px; border-radius:20px; font-weight:800; font-size:13px;
        background:{{ ['a_faire' => '#fef3c7', 'attente' => '#eff6ff', 'fait' => '#ecfdf5', 'clos' => '#f1f5f9'][$negociation->tonStatut($role)] }};
        color:{{ ['a_faire' => '#92400e', 'attente' => '#1d4ed8', 'fait' => '#047857', 'clos' => '#64748b'][$negociation->tonStatut($role)] }};">
        {{ $negociation->libelleStatut($role) }}
    </span>
</div>

@if($statut !== 'Refuse')
<div class="b2b-etapes">
    @foreach($etapes as $i => $e)
        <span class="{{ $i < $rang ? 'fait' : ($i === $rang ? 'courant' : '') }}">{{ $e }}</span>
    @endforeach
</div>
@endif

<div class="b2b-grille">
    <div>
        {{-- ── Les lignes ── --}}
        <div class="card" style="margin-bottom:18px;">
            <div class="card-header"><h2><i class="fas fa-list"></i> Articles</h2></div>

            @php $peutProposer = $enDiscussion; @endphp
            @if($peutProposer)
            <form method="POST" action="{{ route($prefixe . 'proposer', $negociation) }}" id="formProposer">
                @csrf
            @endif
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Article</th>
                            <th style="text-align:right;">Quantité</th>
                            @if(collect($lignes)->contains(fn ($l) => isset($l['quantite_confirmee'])))<th style="text-align:right;">Confirmée</th>@endif
                            <th style="text-align:right;">Prix unitaire HT</th>
                            <th style="text-align:right;">Total HT</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($lignes as $i => $l)
                        <tr>
                            <td><strong>{{ $l['nom'] }}</strong><div style="font-size:11px; color:var(--text-3);">{{ $l['reference'] ?? '' }} · {{ $l['unite'] ?? '' }}</div></td>
                            <td style="text-align:right;">
                                @if($peutProposer && $estClient)
                                    <input type="number" name="quantites[{{ $i }}]" value="{{ (float) $l['quantite'] }}" min="0.001" step="any" class="form-control" style="width:100px; display:inline-block; text-align:right;">
                                @else
                                    {{ $q($l['quantite']) }}
                                @endif
                            </td>
                            @if(collect($lignes)->contains(fn ($x) => isset($x['quantite_confirmee'])))
                                <td style="text-align:right; font-weight:700; color:{{ (float) ($l['quantite_confirmee'] ?? $l['quantite']) < (float) $l['quantite'] ? 'var(--warning)' : 'var(--success)' }};">{{ $q($l['quantite_confirmee'] ?? $l['quantite']) }}</td>
                            @endif
                            <td style="text-align:right;">
                                @if($peutProposer)
                                    <input type="number" name="prix[{{ $i }}]" value="{{ (float) $l['prix_propose'] }}" min="0" step="any" class="form-control" style="width:130px; display:inline-block; text-align:right;">
                                @else
                                    {{ $f($l['prix_propose']) }}
                                @endif
                            </td>
                            <td style="text-align:right; font-weight:700;">{{ $f((float) ($l['quantite_confirmee'] ?? $l['quantite']) * (float) $l['prix_propose']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot class="b2b-total">
                        <tr><td colspan="{{ collect($lignes)->contains(fn ($l) => isset($l['quantite_confirmee'])) ? 4 : 3 }}" style="text-align:right; font-weight:700;">Total HT</td>
                            <td style="text-align:right; font-weight:800;">{{ $f($negociation->totalHt()) }}</td></tr>
                        @if($negociation->prix_final && in_array($statut, ['Confirme', 'Livre', 'Termine'], true))
                        <tr><td colspan="{{ collect($lignes)->contains(fn ($l) => isset($l['quantite_confirmee'])) ? 4 : 3 }}" style="text-align:right; color:var(--text-2);">Montant TTC {{ $statut === 'Confirme' ? 'du bon de commande' : 'facturé' }}</td>
                            <td style="text-align:right; font-weight:800; color:var(--primary);">{{ $f($negociation->prix_final) }}</td></tr>
                        @endif
                    </tfoot>
                </table>
            </div>
            @if($peutProposer)
                <div style="padding:14px 16px; border-top:1px solid var(--border);">
                    <textarea name="message" class="form-control" rows="2" maxlength="2000" placeholder="{{ $estClient ? 'Votre contre-proposition, en quelques mots…' : 'Votre réponse : délai, conditions…' }}"></textarea>
                    <button type="submit" class="btn btn-outline" style="margin-top:8px;">
                        <i class="fas fa-reply"></i> {{ $estClient ? 'Envoyer ma contre-proposition' : 'Envoyer ma proposition de prix' }}
                    </button>
                </div>
            </form>
            @endif
        </div>

        {{-- ── Le fournisseur confirme selon son stock ── --}}
        @if(!$estClient && $statut === 'Commande')
        <div class="card" style="margin-bottom:18px; border:2px solid var(--primary);">
            <div class="card-header"><h2><i class="fas fa-clipboard-check"></i> Confirmer selon votre stock</h2></div>
            <form method="POST" action="{{ route('admin.b2b.fournisseur.confirmer', $negociation) }}" style="padding:14px 16px;">
                @csrf
                <p style="font-size:12.5px; color:var(--text-2); margin-bottom:12px;">
                    Rattachez chaque ligne à un article de votre catalogue et dites ce que vous livrerez.
                    Une quantité à 0 retire la ligne. Votre bon de commande sera établi à ces quantités.
                </p>
                <table style="width:100%; font-size:13px;">
                    <thead><tr><th style="text-align:left;">Demandé</th><th style="text-align:left;">Votre article</th><th style="text-align:right;">Votre stock</th><th style="width:110px;">À livrer</th></tr></thead>
                    <tbody>
                    @foreach($lignes as $i => $l)
                        <tr>
                            <td>{{ $l['nom'] }} <span style="color:var(--text-3);">× {{ $q($l['quantite']) }}</span></td>
                            <td>
                                <select name="produits[{{ $i }}]" class="form-control" style="font-size:12.5px;">
                                    <option value="">— Choisir —</option>
                                    @foreach($catalogue as $p)
                                        <option value="{{ $p->id }}" @selected(($correspondances[$i] ?? null) === $p->id)>{{ $p->nom }}{{ $p->reference ? ' (' . $p->reference . ')' : '' }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td style="text-align:right; font-weight:700; color:{{ isset($stocks[$i]) && $stocks[$i] !== null && $stocks[$i] < $l['quantite'] ? 'var(--danger)' : 'var(--text-2)' }};">
                                {{ isset($stocks[$i]) && $stocks[$i] !== null ? $q($stocks[$i]) : '—' }}
                            </td>
                            <td><input type="number" name="quantites[{{ $i }}]" class="form-control" min="0" max="{{ (float) $l['quantite'] }}" step="any"
                                       value="{{ isset($stocks[$i]) && $stocks[$i] !== null ? min((float) $l['quantite'], max(0, (float) $stocks[$i])) : (float) $l['quantite'] }}"></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <div style="display:flex; gap:10px; align-items:center; margin-top:12px; flex-wrap:wrap;">
                    <label class="form-label" style="margin:0;">Site qui livre</label>
                    <select name="point_de_vente_id" class="form-control" style="max-width:260px;" required>
                        @foreach($pointsDeVente as $pdv)
                            <option value="{{ $pdv->id }}" @selected(session('point_de_vente_actif_id') == $pdv->id)>{{ $pdv->nom }}</option>
                        @endforeach
                    </select>
                </div>
                <textarea name="message" class="form-control" rows="2" maxlength="2000" placeholder="Message au client (délai de livraison…)" style="margin-top:10px;"></textarea>
                <button type="submit" class="btn btn-primary" style="margin-top:10px;"><i class="fas fa-check"></i> Confirmer la commande</button>
            </form>
        </div>
        @endif

        {{-- ── Le fil ── --}}
        <div class="card">
            <div class="card-header"><h2><i class="fas fa-comments"></i> Échanges</h2></div>
            <div style="padding:14px 16px;">
                <div class="b2b-fil" id="filB2b">
                    @forelse($negociation->historique_discussions ?? [] as $m)
                        @php $deMoi = strtolower($m['role'] ?? '') === $role; @endphp
                        <div class="b2b-bulle {{ $deMoi ? 'moi' : 'lui' }} {{ ($m['type'] ?? 'message') === 'message' ? '' : 'action' }}">
                            <div class="meta">{{ $deMoi ? 'Vous' : ($autre?->nom ?? $m['role']) }} — {{ $m['auteur'] ?? '' }} · {{ \Illuminate\Support\Carbon::parse($m['date'])->format('d/m/Y H:i') }}</div>
                            {{ $m['message'] }}
                        </div>
                    @empty
                        <p style="color:var(--text-3); font-size:13px;">Aucun échange pour l'instant.</p>
                    @endforelse
                </div>
                <form method="POST" action="{{ route($prefixe . 'message', $negociation) }}" style="margin-top:12px; display:flex; gap:8px;">
                    @csrf
                    <textarea name="message" class="form-control" rows="2" maxlength="2000" required placeholder="Écrire à {{ $autre?->nom }}…"></textarea>
                    <button type="submit" class="btn btn-primary" style="align-self:flex-end;"><i class="fas fa-paper-plane"></i></button>
                </form>
            </div>
        </div>
    </div>

    {{-- ── Ce que je peux faire maintenant ── --}}
    <div>
        <div class="card b2b-actions" style="padding:16px; margin-bottom:18px;">
            <h3 style="font-size:14px; font-weight:800; margin-bottom:12px;">Ce que vous pouvez faire</h3>

            @if($estClient && $enDiscussion)
                <form method="POST" action="{{ route('admin.b2b.client.commander', $negociation) }}" onsubmit="return confirm('Commander aux prix affichés ({{ $f($negociation->totalHt()) }} HT) ?');">
                    @csrf
                    <button class="btn btn-success"><i class="fas fa-cart-shopping"></i> Commander aux prix affichés</button>
                </form>
                <p style="font-size:11.5px; color:var(--text-3); margin:-2px 0 10px;">Pas d'accord sur un prix ? Modifiez-le dans le tableau et envoyez votre contre-proposition.</p>
            @elseif($estClient && $statut === 'Livre')
                <form method="POST" action="{{ route('admin.b2b.client.receptionner', $negociation) }}" onsubmit="return confirm('Confirmer la réception ? La marchandise entre en stock et la facture d\'achat est établie.');">
                    @csrf
                    <button class="btn btn-success"><i class="fas fa-box-open"></i> J'ai reçu la livraison</button>
                </form>
            @elseif(!$estClient && $statut === 'Confirme')
                <form method="POST" action="{{ route('admin.b2b.fournisseur.livrer', $negociation) }}" onsubmit="return confirm('Livrer et facturer ? Le stock sort, la facture est établie (et certifiée si la normalisation est automatique).');">
                    @csrf
                    <button class="btn btn-success"><i class="fas fa-truck"></i> Livrer et facturer</button>
                </form>
            @elseif(!$estClient && $enDiscussion)
                <p style="font-size:12.5px; color:var(--text-2);">Indiquez vos prix dans le tableau et envoyez votre proposition : le client pourra commander, ou vous répondre.</p>
            @elseif(!$estClient && $statut === 'Commande')
                <p style="font-size:12.5px; color:var(--text-2);">Confirmez la commande selon votre stock (cadre à gauche), ou refusez-la.</p>
            @else
                <p style="font-size:12.5px; color:var(--text-2);">
                    {{ match ($statut) {
                        'Commande' => 'Le fournisseur confirme la commande selon son stock.',
                        'Confirme' => 'Le fournisseur prépare la livraison.',
                        'Livre'    => 'Le client doit confirmer la réception.',
                        'Termine'  => 'Cette affaire est close.',
                        'Refuse'   => 'Cette négociation a été refusée.',
                        default    => 'En attente de l\'autre partie.',
                    } }}
                </p>
            @endif

            @if($refusable)
                <details style="margin-top:6px;">
                    <summary style="cursor:pointer; font-size:12.5px; color:var(--danger); font-weight:600;">{{ $estClient ? 'Annuler / refuser' : 'Refuser' }}</summary>
                    <form method="POST" action="{{ route($prefixe . 'refuser', $negociation) }}" style="margin-top:8px;">
                        @csrf
                        <textarea name="motif" class="form-control" rows="2" required maxlength="1000" placeholder="Le motif, que l'autre partie lira"></textarea>
                        <button class="btn btn-outline" style="margin-top:8px; color:var(--danger); border-color:#fecaca;"><i class="fas fa-ban"></i> Confirmer le refus</button>
                    </form>
                </details>
            @endif
        </div>

        <div class="card" style="padding:16px;">
            <h3 style="font-size:14px; font-weight:800; margin-bottom:10px;">Pièces de votre côté</h3>
            @if($estClient)
                @if($achat)
                    <a href="{{ route('admin.achats.imprimer', $achat) }}" style="display:block; font-size:13px; font-weight:600;">
                        <i class="fas fa-file-invoice"></i> {{ $achat->etape }} {{ $achat->numero_facture }}
                    </a>
                    <div style="font-size:11.5px; color:var(--text-3);">{{ $achat->statut }}</div>
                @else
                    <p style="font-size:12.5px; color:var(--text-3);">Votre bon de commande sera établi quand vous commanderez.</p>
                @endif
            @else
                @forelse($ventes as $v)
                    <a href="{{ route('admin.ventes.imprimer', $v) }}" style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">
                        <i class="fas fa-file-invoice"></i> {{ $v->etape === 'Facture' ? 'Facture' : $v->etape }} {{ $v->numero_facture }}
                        @if($v->etape === 'Facture')<span style="font-size:11px; color:{{ $v->normalise ? 'var(--success)' : 'var(--text-3)' }};">{{ $v->normalise ? '· certifiée' : '· non certifiée' }}</span>@endif
                    </a>
                @empty
                    <p style="font-size:12.5px; color:var(--text-3);">Votre bon de commande sera établi quand vous confirmerez.</p>
                @endforelse
            @endif
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    const fil = document.getElementById('filB2b');
    if (fil) fil.scrollTop = fil.scrollHeight;
</script>
@endsection

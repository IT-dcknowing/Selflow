@extends('admin::gabarits.application')
@section('titre', 'Fiche produit — ' . $produit->nom)
@section('topbar_titre', 'Fiche produit')

@section('contenu')

{{-- Barre de navigation --}}
<div style="display:flex; align-items:center; gap:12px; margin-bottom:20px;">
    <a href="{{ route('admin.produits.index') }}" class="btn btn-outline btn-sm">
        <i class="fas fa-arrow-left"></i> Retour au catalogue
    </a>
    <span style="color:var(--text-3); font-size:13px;">
        {{ $produit->category?->nom ?? '—' }}
        @if($produit->sousCategorieRelation) › {{ $produit->sousCategorieRelation->nom }} @endif
        › {{ $produit->nom }}
    </span>
</div>

{{-- Flash messages --}}
@if(session('succes'))
    <div class="alert alert-success" style="margin-bottom:16px; padding:12px 16px; background:#ecfdf5; border:1px solid #6ee7b7; border-radius:10px; color:#065f46; display:flex; align-items:center; gap:10px;">
        <i class="fas fa-check-circle"></i> {{ session('succes') }}
    </div>
@endif
@if($errors->any())
    <div class="alert alert-error" style="margin-bottom:16px; padding:12px 16px; background:#fef2f2; border:1px solid #fca5a5; border-radius:10px; color:#991b1b;">
        <ul style="margin:0; padding-left:18px;">
            @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
        </ul>
    </div>
@endif

{{-- En-tête produit --}}
<div class="card" style="padding:0; overflow:hidden; margin-bottom:20px;">
    <div style="display:grid; grid-template-columns:260px 1fr; min-height:200px;">

        {{-- Photo --}}
        <div style="background:var(--bg3); position:relative; display:flex; align-items:center; justify-content:center;">
            <img id="img-produit-principal" src="{{ $produit->photo_url }}" alt="{{ $produit->nom }}"
                style="width:100%; height:220px; object-fit:cover;"
                {{-- Plus de dessin de remplacement : voir `produits/index`. --}}
                onerror="this.style.display='none';">

            {{-- Badge statut archivé --}}
            @if($produit->statut === 'archive')
                <span style="position:absolute; top:12px; left:12px; background:#fef3c7; color:#92400e; font-weight:700; font-size:11px; padding:4px 10px; border-radius:20px;">
                    <i class="fas fa-archive"></i> Archivé
                </span>
            @endif

            {{-- Bouton upload photo --}}
            <label style="position:absolute; bottom:10px; right:10px; background:rgba(0,0,0,.55); color:#fff; padding:6px 12px; border-radius:10px; font-size:11px; cursor:pointer; backdrop-filter:blur(4px);" title="Changer la photo">
                <i class="fas fa-camera"></i> Photo
                {{-- Le numéro de ligne partait dans l'adresse — `…/produits/156/photo` —
                     alors que les adresses de l'application portent l'`uuid` : le
                     lien de route ne résolvait aucun article et l'envoi tombait en
                     404 (Not Found — introuvable), sans un mot à l'écran. On passe
                     l'adresse construite par le routeur, qui ne peut pas se tromper
                     de clé. --}}
                <input type="file" accept="image/*" style="display:none;"
                    onchange="uploaderPhotoPrincipal(this, @js(route('admin.produits.photo', $produit)))">
            </label>
        </div>

        {{-- Titre + badges --}}
        <div style="padding:24px 28px;">
            <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap;">
                <div>
                    <div style="font-family:monospace; font-size:12px; color:var(--text-3); margin-bottom:4px;">
                        {{ $produit->reference }}
                    </div>
                    <h1 style="font-size:28px; font-weight:800; color:var(--text-1); margin:0 0 8px 0; line-height:1.2;">
                        {{ $produit->nom }}
                    </h1>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px;">
                        @php
                            $typeColors = [
                                'marchandise'               => ['bg'=>'#ecfdf5','color'=>'#065f46'],
                                'matiere_premiere'          => ['bg'=>'#f0fdf4','color'=>'#166534'],
                                'produit_fini'              => ['bg'=>'#e0f2fe','color'=>'#0369a1'],
                                'consommable_stockable'     => ['bg'=>'#fef3c7','color'=>'#92400e'],
                                'consommable_non_stockable' => ['bg'=>'#fff7ed','color'=>'#c2410c'],
                                'service'                   => ['bg'=>'#eff6ff','color'=>'#1e40af'],
                            ];
                            $tc = $typeColors[$produit->type] ?? ['bg'=>'#f3f4f6','color'=>'#374151'];
                        @endphp
                        <span style="background:{{ $tc['bg'] }}; color:{{ $tc['color'] }}; font-size:11px; font-weight:700; padding:4px 12px; border-radius:20px;">
                            {{ $produit->libelleType() }}
                        </span>
                        @if($produit->category)
                            <span style="background:var(--bg3); color:var(--text-2); font-size:11px; font-weight:600; padding:4px 12px; border-radius:20px;">
                                {{ $produit->category->nom }}
                            </span>
                        @endif
                        @if($produit->estPerime())
                            <span style="background:#fef2f2; color:#991b1b; font-size:11px; font-weight:700; padding:4px 12px; border-radius:20px;">
                                <i class="fas fa-exclamation-triangle"></i> Périmé
                            </span>
                        @elseif($produit->bientotPerime())
                            <span style="background:#fff7ed; color:#c2410c; font-size:11px; font-weight:700; padding:4px 12px; border-radius:20px;">
                                <i class="fas fa-clock"></i> Péremption proche
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Actions --}}
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button class="btn btn-primary btn-sm" data-modal-open="modalModifierProduit">
                        <i class="fas fa-pen"></i> Modifier
                    </button>
                    <form method="POST" action="{{ route('admin.produits.archiver', $produit) }}" style="margin:0;">
                        @csrf @method('PATCH')
                        <button type="submit" class="btn btn-outline btn-sm" style="color:var(--warning);">
                            <i class="fas fa-archive"></i>
                            {{ $produit->statut === 'actif' ? 'Archiver' : 'Restaurer' }}
                        </button>
                    </form>
                    <button class="btn btn-outline btn-sm" data-modal-open="modalDetailLibre">
                        <i class="fas fa-tags"></i> Détail libre
                    </button>
                </div>
            </div>

            {{-- Prix --}}
            <div style="display:flex; gap:32px; flex-wrap:wrap; margin-top:4px;">
                <div>
                    <div style="font-size:11px; color:var(--text-3); text-transform:uppercase; letter-spacing:.5px;">Prix achat</div>
                    <div style="font-size:20px; font-weight:700; color:var(--text-1);">{{ number_format($produit->prix_achat, 0, ',', ' ') }} F</div>
                </div>
                <div>
                    <div style="font-size:11px; color:var(--text-3); text-transform:uppercase; letter-spacing:.5px;">Prix vente</div>
                    <div style="font-size:22px; font-weight:800; color:var(--success);">{{ number_format($produit->prix_vente, 0, ',', ' ') }} F</div>
                </div>
                <div>
                    <div style="font-size:11px; color:var(--text-3); text-transform:uppercase; letter-spacing:.5px;">Marge</div>
                    @php $marge = $produit->prix_achat > 0 ? round((($produit->prix_vente - $produit->prix_achat) / $produit->prix_achat) * 100) : 0; @endphp
                    <div style="font-size:20px; font-weight:700; color:var(--info);">+{{ $marge }}%</div>
                </div>
                <div>
                    <div style="font-size:11px; color:var(--text-3); text-transform:uppercase; letter-spacing:.5px;">TVA</div>
                    <div style="font-size:20px; font-weight:700; color:var(--text-2);">{{ $produit->taux_tva }}%</div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Corps : grille d'informations + stock --}}
<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">

    {{-- ─── Informations produit ─── --}}
    <div class="card" style="padding:24px;">
        <h3 style="font-size:14px; font-weight:700; color:var(--text-3); text-transform:uppercase; letter-spacing:.5px; margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:10px;">
            <i class="fas fa-info-circle"></i> Informations produit
        </h3>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
            @php
                $champs = [
                    ['label'=>'Unité',         'valeur'=>$produit->unite ?? '—'],
                    ['label'=>'Provenance',    'valeur'=>$produit->provenance ?? '—'],
                    ['label'=>'Date d\'arrivée', 'valeur'=>$produit->date_arrivee?->format('d/m/Y') ?? '—'],
                    ['label'=>'Date péremption', 'valeur'=>$produit->date_peremption?->format('d/m/Y') ?? '—',
                     'alert'=>$produit->estPerime() ? 'danger' : ($produit->bientotPerime() ? 'warning' : null)],
                    ['label'=>'Compte vente',  'valeur'=>$produit->compte_vente ?? '—'],
                    ['label'=>'Compte achat',  'valeur'=>$produit->compte_achat ?? '—'],
                ];
            @endphp
            @foreach($champs as $c)
                <div>
                    <div style="font-size:10px; color:var(--text-3); text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px;">{{ $c['label'] }}</div>
                    <div style="font-size:14px; font-weight:600; color:{{ isset($c['alert']) && $c['alert'] === 'danger' ? '#991b1b' : (isset($c['alert']) && $c['alert'] === 'warning' ? '#c2410c' : 'var(--text-1)') }};">
                        {{ $c['valeur'] }}
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ─── Indicateurs de stock ─── --}}
    <div class="card" style="padding:24px;">
        <h3 style="font-size:14px; font-weight:700; color:var(--text-3); text-transform:uppercase; letter-spacing:.5px; margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:10px;">
            <i class="fas fa-boxes"></i> Stock (point de vente actif)
        </h3>
        @php
            $stock      = $produit->stocks->where('point_de_vente_id', session('point_de_vente_actif_id'))->first()
                       ?? $produit->stocks->first();
            $qteDisp    = $stock?->quantite_disponible ?? 0;
            $qteCmd     = $produit->quantite_commandee ?? 0;
            $qteRecp    = $produit->quantite_a_receptionner ?? 0;
            $qteNette   = $qteDisp - $qteCmd;
            $prevision  = $qteDisp - $qteCmd + $qteRecp;
            $stockMin   = $stock?->stock_minimum ?? 0;
            $stockMax   = $stock?->stock_maximum ?? 0;
        @endphp
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
            @php
                $indicateurs = [
                    ['label'=>'Disponible',              'valeur'=>$qteDisp,  'color'=>$qteDisp == 0 ? 'var(--danger)' : ($qteDisp <= $stockMin ? 'var(--warning)' : 'var(--success)')],
                    ['label'=>'Commandée (non livrée)',  'valeur'=>$qteCmd,   'color'=>'var(--info)'],
                    ['label'=>'En attente réception',    'valeur'=>$qteRecp,  'color'=>'var(--primary)'],
                    ['label'=>'Nette (dispo − livrée)',  'valeur'=>$qteNette, 'color'=>$qteNette < 0 ? 'var(--danger)' : 'var(--text-1)'],
                    ['label'=>'Prévision (dispo−cmd+récep)', 'valeur'=>$prevision, 'color'=>'var(--primary)'],
                    ['label'=>'Stock min / max',         'valeur'=>$stockMin . ' / ' . $stockMax, 'color'=>'var(--text-2)'],
                ];
            @endphp
            @foreach($indicateurs as $ind)
                <div style="background:var(--bg3); border-radius:10px; padding:12px 14px;">
                    <div style="font-size:10px; color:var(--text-3); text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px;">{{ $ind['label'] }}</div>
                    <div style="font-size:22px; font-weight:800; color:{{ $ind['color'] }};">{{ $ind['valeur'] }} <span style="font-size:13px; font-weight:400; color:var(--text-3);">{{ $produit->unite }}</span></div>
                </div>
            @endforeach
        </div>
    </div>
</div>

{{-- ─── Description inventaire ─── --}}
<div class="card" style="padding:24px; margin-bottom:20px;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; border-bottom:1px solid var(--border); padding-bottom:10px;">
        <h3 style="font-size:14px; font-weight:700; color:var(--text-3); text-transform:uppercase; letter-spacing:.5px; margin:0;">
            <i class="fas fa-clipboard-list"></i> Description inventaire (dos de fiche)
        </h3>
        <button class="btn btn-outline btn-sm" data-modal-open="modalModifierDescription">
            <i class="fas fa-pen"></i> Modifier
        </button>
    </div>
    @if($produit->description_inventaire)
        <p style="color:var(--text-1); font-size:14px; line-height:1.7; margin:0;">{{ $produit->description_inventaire }}</p>
    @else
        <p style="color:var(--text-3); font-style:italic; margin:0;">Aucune description d'inventaire renseignée. Cliquez sur Modifier pour en ajouter une.</p>
    @endif
</div>

{{-- ─── Détails libres ─── --}}
<div class="card" style="padding:24px; margin-bottom:20px;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; border-bottom:1px solid var(--border); padding-bottom:10px;">
        <h3 style="font-size:14px; font-weight:700; color:var(--text-3); text-transform:uppercase; letter-spacing:.5px; margin:0;">
            <i class="fas fa-tags"></i> Détails libres (champs personnalisés)
        </h3>
        <button class="btn btn-outline btn-sm" data-modal-open="modalDetailLibre">
            <i class="fas fa-plus"></i> Ajouter un détail
        </button>
    </div>
    @if($produit->detailsLibres->isEmpty())
        <p style="color:var(--text-3); font-style:italic; margin:0;">Aucun détail libre. Utilisez ce champ pour noter le livreur, la taille, la couleur, etc.</p>
    @else
        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:12px;">
            @foreach($produit->detailsLibres as $detail)
                <div style="background:var(--bg3); border-radius:10px; padding:14px 16px; position:relative;">
                    <div style="font-size:11px; font-weight:700; color:var(--primary); text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px;">{{ $detail->titre }}</div>
                    <p style="margin:0; font-size:13px; color:var(--text-1); line-height:1.6;">{{ $detail->description }}</p>
                    <form method="POST" action="{{ route('admin.produits.details.supprimer', $detail) }}" style="margin:0; position:absolute; top:10px; right:10px;">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none; border:none; cursor:pointer; color:var(--danger); font-size:12px;" title="Supprimer">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif
</div>

{{-- ─── Stock par point de vente ─── --}}
@if($produit->stocks->count() > 1)
<div class="card" style="padding:24px; margin-bottom:20px;">
    <h3 style="font-size:14px; font-weight:700; color:var(--text-3); text-transform:uppercase; letter-spacing:.5px; margin-bottom:14px; border-bottom:1px solid var(--border); padding-bottom:10px;">
        <i class="fas fa-map-marker-alt"></i> Stock par site
    </h3>
    <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap:12px;">
        @foreach($produit->stocks as $s)
            @php
                $pdv = $s->pointDeVente;
                $sc  = $s->quantite_disponible == 0 ? '#fef2f2' : ($s->quantite_disponible <= $s->stock_minimum ? '#fff7ed' : '#ecfdf5');
                $cc  = $s->quantite_disponible == 0 ? '#991b1b' : ($s->quantite_disponible <= $s->stock_minimum ? '#92400e' : '#065f46');
            @endphp
            <div style="background:{{ $sc }}; border-radius:10px; padding:14px 16px;">
                <div style="font-size:11px; font-weight:700; color:{{ $cc }}; margin-bottom:6px;">{{ $pdv->nom ?? '—' }}</div>
                <div style="font-size:22px; font-weight:800; color:{{ $cc }};">{{ $s->quantite_disponible }} <span style="font-size:12px; font-weight:400;">{{ $produit->unite }}</span></div>
                <div style="font-size:11px; color:var(--text-3); margin-top:4px;">Min: {{ $s->stock_minimum }} / Max: {{ $s->stock_maximum }}</div>
            </div>
        @endforeach
    </div>
</div>
@endif


{{-- ══════════ MODALS ══════════ --}}

{{-- Modal modifier produit --}}
<div class="modal-overlay" id="modalModifierProduit">
    <div class="modal" style="max-width:620px;">
        <div class="modal-header">
            <h3><i class="fas fa-pen"></i> Modifier — {{ $produit->nom }}</h3>
            <button class="modal-close" data-modal-close>✕</button>
        </div>
        <form method="POST" action="{{ route('admin.produits.modifier', $produit) }}">
            @csrf @method('PUT')
            <div class="form-grid-2">
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Nom</label>
                    <input type="text" name="nom" class="form-control" value="{{ $produit->nom }}" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Type</label>
                    <select name="type" class="form-control" required>
                        @foreach(\App\Modules\Admin\Modeles\Produit::TYPES as $val => $lib)
                            <option value="{{ $val }}" {{ $produit->type === $val ? 'selected' : '' }}>{{ $lib }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Unité</label>
                    <input type="text" name="unite" class="form-control" list="unites-suggerees"
                           value="{{ $produit->unite }}" placeholder="pièce, kg, forfait… ou la vôtre">
                </div>
                <div class="form-group">
                    <label class="form-label">Prix achat</label>
                    <input type="number" name="prix_achat" class="form-control" value="{{ $produit->prix_achat }}" min="0" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Prix vente</label>
                    <input type="number" name="prix_vente" class="form-control" value="{{ $produit->prix_vente }}" min="0" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Taux TVA (%)</label>
                    <input type="number" name="taux_tva" class="form-control" value="{{ $produit->taux_tva }}" min="0" max="100" step="0.01" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Provenance</label>
                    <input type="text" name="provenance" class="form-control" value="{{ $produit->provenance }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Date d'arrivée</label>
                    <input type="date" name="date_arrivee" class="form-control" value="{{ $produit->date_arrivee?->format('Y-m-d') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Date de péremption</label>
                    <input type="date" name="date_peremption" class="form-control" value="{{ $produit->date_peremption?->format('Y-m-d') }}">
                    <small style="color:var(--text-3); font-size:11px;">
                        Une seule date pour l'article. Pour plusieurs arrivages, activez le suivi par lot.
                    </small>
                </div>
                {{-- Le suivi par lot : la péremption appartient à l'arrivage,
                     pas à l'article. Une pharmacie qui reçoit trois arrivages
                     de paracétamol n'enregistrait qu'une date, la saisie du
                     troisième écrasant les deux premiers. --}}
                <div class="form-group">
                    <label class="form-label">Suivi par lot</label>
                    <select name="suivi_par_lot" class="form-control">
                        <option value="0" {{ $produit->suivi_par_lot ? '' : 'selected' }}>Non — une seule date pour l'article</option>
                        <option value="1" {{ $produit->suivi_par_lot ? 'selected' : '' }}>Oui — chaque arrivage porte sa date</option>
                    </select>
                    <small style="color:var(--text-3); font-size:11px;">
                        La sortie sert alors d'abord ce qui périme le plus tôt, et un lot périmé ne peut plus être livré.
                    </small>
                </div>
                <div class="form-group">
                    <label class="form-label">Préavis de péremption (jours)</label>
                    <input type="number" name="preavis_peremption" class="form-control" min="0" max="3650"
                           value="{{ $produit->preavis_peremption ?? 30 }}">
                    <small style="color:var(--text-3); font-size:11px;">
                        Trente jours conviennent à l'alimentaire ; un médicament se retire des rayons bien plus tôt.
                    </small>
                </div>
                <div class="form-group">
                    <label class="form-label">Compte vente</label>
                    <input type="text" name="compte_vente" class="form-control" value="{{ $produit->compte_vente }}" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Compte achat</label>
                    <input type="text" name="compte_achat" class="form-control" value="{{ $produit->compte_achat }}" required>
                </div>
                {{-- La quantite ne se corrige plus ici : elle est la consequence
                     des mouvements, pas un champ qu'on retape. Une correction se
                     fait par l'inventaire, qui ecrit l'ecart, le date et le
                     signe -- et qui recalcule le CUMP. --}}
                <div class="form-group">
                    <label class="form-label">Stock actuel (site actif)</label>
                    <input type="number" class="form-control" value="{{ $stock?->quantite_disponible ?? 0 }}" readonly tabindex="-1"
                           style="background:var(--bg3); cursor:default;">
                    <small style="color:var(--text-3); font-size:11px;">
                        Pour le corriger, passez par
                        <a href="{{ route('admin.stock.inventaire') }}">l'inventaire</a>.
                    </small>
                </div>
                <div class="form-group">
                    <label class="form-label">Stock minimum</label>
                    <input type="number" name="stock_minimum" class="form-control" value="{{ $stock?->stock_minimum ?? 5 }}" min="0" required>
                </div>
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:8px;">
                <button type="button" class="btn btn-outline" data-modal-close>Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Sauvegarder</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal description inventaire --}}
<div class="modal-overlay" id="modalModifierDescription">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <h3><i class="fas fa-clipboard-list"></i> Description inventaire</h3>
            <button class="modal-close" data-modal-close>✕</button>
        </div>
        <form method="POST" action="{{ route('admin.produits.description', $produit) }}">
            @csrf @method('PATCH')
            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description_inventaire" class="form-control" rows="5" placeholder="Notes, remarques, description détaillée pour l'inventaire...">{{ $produit->description_inventaire }}</textarea>
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:8px;">
                <button type="button" class="btn btn-outline" data-modal-close>Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Sauvegarder</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal Détail libre --}}
<div class="modal-overlay" id="modalDetailLibre">
    <div class="modal" style="max-width:520px;">
        <div class="modal-header">
            <h3><i class="fas fa-tags"></i> Ajouter un détail libre</h3>
            <button class="modal-close" data-modal-close>✕</button>
        </div>
        <form method="POST" action="{{ route('admin.produits.details.ajouter', $produit) }}">
            @csrf
            <div id="details-libres-container">
                <div class="detail-libre-pair" style="background:var(--bg3); border-radius:10px; padding:14px; margin-bottom:12px;">
                    <div class="form-group">
                        <label class="form-label">Titre <span style="color:var(--danger)">*</span></label>
                        <input type="text" name="details[0][titre]" class="form-control" placeholder="ex: Livreur, Taille, Couleur..." required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="details[0][description]" class="form-control" rows="2" placeholder="Valeur ou description..."></textarea>
                    </div>
                </div>
            </div>
            <button type="button" class="btn btn-outline btn-sm" onclick="ajouterDetailLibre()" style="margin-bottom:16px;">
                <i class="fas fa-plus"></i> Ajouter un deuxième champ
            </button>
            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" class="btn btn-outline" data-modal-close>Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<script>
// ─── Upload photo principal ───────────────────────────────────────────────────
function uploaderPhotoPrincipal(input, adressePhoto) {
    if (!input.files || !input.files[0]) return;
    const formData = new FormData();
    formData.append('photo', input.files[0]);
    formData.append('_token', document.querySelector('meta[name="csrf-token"]').content);
    fetch(adressePhoto, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('img-produit-principal').src = data.photo_url + '?t=' + Date.now();
            }
        })
        .catch(() => alert('Erreur upload photo.'));
}

// ─── Détails libres répétables ────────────────────────────────────────────────
let detailIndex = 1;
function ajouterDetailLibre() {
    const container = document.getElementById('details-libres-container');
    const i = detailIndex++;
    const div = document.createElement('div');
    div.className = 'detail-libre-pair';
    div.style.cssText = 'background:var(--bg3); border-radius:10px; padding:14px; margin-bottom:12px; position:relative;';
    div.innerHTML = `
        <button type="button" onclick="this.parentElement.remove()" style="position:absolute; top:10px; right:10px; background:none; border:none; cursor:pointer; color:var(--danger); font-size:14px;"><i class="fas fa-trash"></i></button>
        <div class="form-group">
            <label class="form-label">Titre <span style="color:var(--danger)">*</span></label>
            <input type="text" name="details[${i}][titre]" class="form-control" placeholder="ex: Livreur, Taille, Couleur..." required>
        </div>
        <div class="form-group">
            <label class="form-label">Description</label>
            <textarea name="details[${i}][description]" class="form-control" rows="2" placeholder="Valeur ou description..."></textarea>
        </div>`;
    container.appendChild(div);
}
</script>
@include('admin::partials.unites-suggerees')

@endsection

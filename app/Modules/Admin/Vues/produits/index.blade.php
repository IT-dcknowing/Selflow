@extends('admin::gabarits.application')
@section('titre', 'Catalogue produits')
@section('topbar_titre', 'Catalogue — Produits')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-barcode"></i> Catalogue produits</h1>
        <p>{{ $produits->total() }} produit(s) actif(s)</p>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
        {{-- Toggle Vue --}}
        <div class="vue-toggle" style="display:flex; background:var(--bg2); border:1px solid var(--border); border-radius:8px; overflow:hidden;">
            <button id="btn-vue-tableau" onclick="setVue('tableau')"
                style="padding:8px 14px; border:none; cursor:pointer; font-size:13px; transition:all .2s;"
                title="Vue tableau">
                <i class="fas fa-list"></i>
            </button>
            <button id="btn-vue-cartes" onclick="setVue('cartes')"
                style="padding:8px 14px; border:none; cursor:pointer; font-size:13px; transition:all .2s;"
                title="Vue cartes">
                <i class="fas fa-th"></i>
            </button>
        </div>
        <button class="btn btn-outline" onclick="ouvrirImport('modalImportProduits')" style="font-size:13px;">
            <i class="fas fa-file-import"></i> Importer CSV
        </button>
        <button class="btn btn-primary" data-modal-open="modalNouveauProduit">
            <i class="fas fa-plus"></i> Ajouter un produit
        </button>
    </div>
</div>

{{-- Barre de recherche et filtres --}}
<div class="card" style="padding:14px 20px; margin-bottom:16px;">
    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
        <div style="flex:1; min-width:200px; position:relative;">
            <i class="fas fa-search" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-3);"></i>
            <input type="text" id="recherche-produit" placeholder="Rechercher un produit..."
                style="width:100%; padding:8px 12px 8px 36px; border:1px solid var(--border); border-radius:8px; background:var(--bg2); color:var(--text-1); font-size:13px;"
                oninput="filtrerProduits()">
        </div>
        <select id="filtre-categorie" onchange="filtrerProduits()"
            style="padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--bg2); color:var(--text-1); font-size:13px;">
            <option value="">Toutes les catégories</option>
            @foreach($categories as $cat)
                <option value="{{ $cat->nom }}">{{ $cat->nom }}</option>
            @endforeach
        </select>
        <select id="filtre-type" onchange="filtrerProduits()"
            style="padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--bg2); color:var(--text-1); font-size:13px;">
            <option value="">Tous les types</option>
            @foreach(\App\Modules\Admin\Modeles\Produit::TYPES as $val => $lib)
                <option value="{{ $lib }}">{{ $lib }}</option>
            @endforeach
        </select>
    </div>
</div>

{{-- ═══════════════════ VUE CARTES ═══════════════════ --}}
<div id="vue-cartes" style="display:none;">
    @if($produits->isEmpty())
        <div class="card" style="text-align:center; padding:60px; color:var(--text-3);">
            <i class="fas fa-box-open" style="font-size:48px; margin-bottom:16px; opacity:.4;"></i>
            <p>Aucun produit dans le catalogue.</p>
        </div>
    @else
    <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap:18px; margin-bottom:24px;">
        @foreach($produits as $p)
        @php
            $stock = $p->stocks->where('point_de_vente_id', session('point_de_vente_actif_id'))->first();
            $qte   = $stock?->quantite_disponible ?? 0;
            $min   = $stock?->stock_minimum ?? 0;
            $stockColor = $qte == 0 ? 'var(--danger)' : ($qte <= $min ? 'var(--warning)' : 'var(--success)');
        @endphp
        <div class="produit-carte" data-nom="{{ strtolower($p->nom) }}" data-categorie="{{ $p->category?->nom }}" data-type="{{ $p->libelleType() }}"
            style="background:var(--bg2); border:1px solid var(--border); border-radius:14px; overflow:hidden; cursor:pointer; transition:all .2s; box-shadow:0 1px 4px rgba(0,0,0,.06);"
            onmouseenter="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 8px 24px rgba(0,0,0,.12)';"
            onmouseleave="this.style.transform=''; this.style.boxShadow='0 1px 4px rgba(0,0,0,.06)';"
            onclick="window.location='{{ route('admin.produits.fiche', $p) }}'">

            {{-- Photo --}}
            @php $photoReelle = $p->photoReelle(); @endphp
            <div style="height:140px; background:var(--bg3); position:relative; overflow:hidden;">
                {{-- Le dessin de remplacement a été retiré ici comme il l'a été
                     de l'écran de caisse au lot 30 : la même silhouette revenait
                     sur des articles sans rapport. Un article sans photo garde
                     un cadre net, avec l'icône de son appareil photo qui dit ce
                     qui manque. --}}
                @if($photoReelle)
                    <img src="{{ $photoReelle }}" alt="{{ $p->nom }}"
                         style="width:100%; height:100%; object-fit:cover;"
                         onerror="this.closest('div').classList.add('sans-photo'); this.remove();">
                @else
                    <div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; color:var(--text-3); font-size:26px;">
                        <i class="fas fa-camera" style="opacity:.25;"></i>
                    </div>
                @endif
                {{-- Badge type --}}
                @php
                    $typeColors = [
                        'marchandise'               => ['bg'=>'#ecfdf5','color'=>'#065f46'],
                        'matiere_premiere'          => ['bg'=>'#f0fdf4','color'=>'#166534'],
                        'produit_fini'              => ['bg'=>'#e0f2fe','color'=>'#0369a1'],
                        'consommable_stockable'     => ['bg'=>'#fef3c7','color'=>'#92400e'],
                        'consommable_non_stockable' => ['bg'=>'#fff7ed','color'=>'#c2410c'],
                        'service'                  => ['bg'=>'#eff6ff','color'=>'#1e40af'],
                    ];
                    $tc = $typeColors[$p->type] ?? ['bg'=>'#f3f4f6','color'=>'#374151'];
                @endphp
                <span style="position:absolute; top:8px; left:8px; background:{{ $tc['bg'] }}; color:{{ $tc['color'] }}; font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; backdrop-filter:blur(4px);">
                    {{ $p->libelleType() }}
                </span>
                {{-- Upload photo rapide --}}
                <label style="position:absolute; bottom:6px; right:6px; background:rgba(0,0,0,.5); color:#fff; padding:4px 8px; border-radius:8px; font-size:10px; cursor:pointer;" title="Changer la photo">
                    <i class="fas fa-camera"></i>
                    {{-- Le numéro de ligne partait dans l'adresse — `…/produits/156/photo` —
                         alors que les adresses de l'application portent l'`uuid` : le
                         lien de route ne résolvait aucun article et l'envoi tombait en
                         404 (Not Found — introuvable), sans un mot à l'écran. --}}
                    <input type="file" accept="image/*" style="display:none;"
                        onchange="uploaderPhoto(this, @js(route('admin.produits.photo', $p)))">
                </label>
            </div>

            {{-- Infos --}}
            <div style="padding:12px 14px;">
                <div style="font-family:monospace; font-size:10px; color:var(--text-3); margin-bottom:2px;">{{ $p->reference }}</div>
                <div style="font-weight:700; font-size:14px; color:var(--text-1); margin-bottom:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $p->nom }}</div>
                <div style="font-size:11px; color:var(--text-3); margin-bottom:10px;">{{ $p->category?->nom ?? '—' }}</div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <span style="font-size:15px; font-weight:800; color:var(--success);">{{ number_format($p->prix_vente, 0, ',', ' ') }} F</span>
                    <span style="font-size:13px; font-weight:700; color:{{ $stockColor }};">
                        <i class="fas fa-boxes" style="font-size:11px;"></i> {{ $qte }} {{ $p->unite }}
                    </span>
                </div>

                <div style="display:flex; gap:6px;">
                    <a href="{{ route('admin.produits.fiche', $p) }}" id="btn-fiche-{{ $p->id }}" class="btn btn-outline btn-sm" style="flex:1; font-size:11px;" onclick="event.stopPropagation()">
                        <i class="fas fa-eye"></i> Voir fiche
                    </a>
                    <form method="POST" action="{{ route('admin.produits.archiver', $p) }}" style="margin:0;" onclick="event.stopPropagation()">
                        @csrf @method('PATCH')
                        <button type="submit" class="btn btn-outline btn-sm" style="font-size:11px; color:var(--warning);" title="Archiver">
                            <i class="fas fa-archive"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        @endforeach
    </div>
    {{-- Pagination cartes --}}
    <div style="margin-top:8px;">{{ $produits->links() }}</div>
    @endif
</div>

{{-- ═══════════════════ VUE TABLEAU (EXISTANTE) ═══════════════════ --}}
<div id="vue-tableau">


<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Référence</th>
                    <th>Produit</th>
                    <th>Type</th>
                    <th>Catégorie</th>
                    <th>Prix achat</th>
                    <th>Prix vente</th>
                    <th>Marge</th>
                    <th>Stock</th>
                    <th>Prévision</th>
                    <th>Min.</th>
                    <th>État</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($produits as $p)
                <tr class="ligne-produit" data-nom="{{ strtolower($p->nom) }}" data-categorie="{{ strtolower($p->category?->nom ?? '') }}" data-type="{{ strtolower($p->libelleType()) }}">
                    <td style="font-family:monospace; font-size:12px; color:var(--text-3);">{{ $p->reference }}</td>
                    <td style="font-weight:600;">{{ $p->nom }}</td>
                    <td>
                        @php
                            $typeColors = [
                                'marchandise'               => ['bg'=>'#ecfdf5','color'=>'#065f46'],
                                'matiere_premiere'          => ['bg'=>'#f0fdf4','color'=>'#166534'],
                                'produit_fini'              => ['bg'=>'#e0f2fe','color'=>'#0369a1'],
                                'consommable_stockable'     => ['bg'=>'#fef3c7','color'=>'#92400e'],
                                'consommable_non_stockable' => ['bg'=>'#fff7ed','color'=>'#c2410c'],
                                'service'                   => ['bg'=>'#eff6ff','color'=>'#1e40af'],
                            ];
                            $tc = $typeColors[$p->type] ?? ['bg'=>'#f3f4f6','color'=>'#374151'];
                        @endphp
                        <span class="badge" style="background:{{ $tc['bg'] }}; color:{{ $tc['color'] }}; padding:2px 8px; border-radius:20px; font-weight:600; font-size:11px;">
                            {{ $p->libelleType() }}
                        </span>
                    </td>
                    <td><span class="badge badge-purple">{{ $p->categorie ?? '—' }}</span></td>
                    <td>@if(!$p->estStockable()) — @else {{ number_format($p->prix_achat, 0, ',', ' ') }} F @endif</td>
                    <td style="color:var(--success); font-weight:600;">{{ number_format($p->prix_vente, 0, ',', ' ') }} F</td>
                    <td style="color:var(--info);">
                        @if(!$p->estStockable())
                            —
                        @else
                            @php $marge = $p->prix_achat > 0 ? round((($p->prix_vente - $p->prix_achat) / $p->prix_achat) * 100) : 0; @endphp
                            +{{ $marge }}%
                        @endif
                    </td>
                    {{-- La liste des types sans stock vivait ici en dur : une
                         seconde copie de `Produit::TYPES_STOCKABLES`, qui aurait
                         diverge au premier type ajoute. --}}
                    @php $sansStock = !$p->estStockable(); @endphp
                    <td style="font-weight:600; {{ $sansStock ? 'color:var(--text-3)' : ($p->stock_actuel == 0 ? 'color:var(--danger)' : ($p->stock_actuel <= $p->stock_minimum ? 'color:var(--warning)' : 'color:var(--success)')) }}">
                        @if($sansStock) — @else {{ $p->stock_actuel }} {{ $p->unite }} @endif
                    </td>
                    <td style="font-weight:800; color:var(--primary);">
                        @if($sansStock) — @else {{ $p->prevision }} {{ $p->unite }} @endif
                    </td>
                    <td style="color:var(--text-3);">@if($sansStock) — @else {{ $p->stock_minimum }} @endif</td>
                    <td>
                        @if($sansStock)
                            <span class="badge" style="background:#f1f5f9; color:#64748b;">Sans stock</span>
                        @elseif($p->stock_actuel == 0)
                            <span class="badge badge-danger">Rupture</span>
                        @elseif($p->stock_actuel <= $p->stock_minimum)
                            <span class="badge badge-warning">Faible</span>
                        @else
                            <span class="badge badge-success">OK</span>
                        @endif
                    </td>
                    <td>
                        <button class="btn btn-outline btn-sm" data-modal-open="modalModifier{{ $p->id }}">
                            <i class="fas fa-pen"></i>
                        </button>
                        <form method="POST" action="{{ route('admin.produits.archiver', $p) }}" style="display:inline; margin:0;">
                            @csrf @method('PATCH')
                            <button type="submit" class="btn btn-outline btn-sm" style="color:var(--warning);" title="Archiver">
                                <i class="fas fa-archive"></i>
                            </button>
                        </form>
                    </td>
                </tr>

                {{-- Modal modifier --}}
                <div class="modal-overlay" id="modalModifier{{ $p->id }}">
                    <div class="modal">
                        <div class="modal-header">
                            <h3>Modifier — {{ $p->nom }}</h3>
                            <button class="modal-close" data-modal-close>✕</button>
                        </div>
                        <form method="POST" action="{{ route('admin.produits.modifier', $p) }}">
                            @csrf @method('PUT')
                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label">Type d'article</label>
                                    <select name="type" id="type_select_{{ $p->id }}" class="form-control" onchange="toggleStockFields('{{ $p->id }}')" required>
                                        @foreach(\App\Modules\Admin\Modeles\Produit::TYPES as $val => $libelle)
                                            <option value="{{ $val }}" {{ $p->type === $val ? 'selected' : '' }}>{{ $libelle }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Nom produit <span style="color:var(--danger)">*</span></label>
                                    <input type="text" name="nom" class="form-control" value="{{ $p->nom }}" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Catégorie <span style="color:var(--danger)">*</span></label>
                                    <select name="categorie_id" id="categorie_select_{{ $p->id }}" class="form-control" onchange="toggleCategorieInput('{{ $p->id }}')" required>
                                        <option value="">-- Choisir une catégorie --</option>
                                        @foreach($categories as $cat)
                                            <option value="{{ $cat->id }}" {{ $p->categorie_id == $cat->id ? 'selected' : '' }}>{{ $cat->nom }} ({{ $cat->prefixe }})</option>
                                        @endforeach
                                        <option value="nouvelle">+ Créer une nouvelle catégorie...</option>
                                    </select>
                                </div>
                                <div class="form-group" id="nouvelle_categorie_container_{{ $p->id }}" style="display:none; grid-column: 1/-1;">
                                    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:10px; background:var(--bg3); padding:10px; border-radius:8px; border:1px dashed var(--border);">
                                        <div>
                                            <label class="form-label" style="font-size:11px;">Nom de la catégorie</label>
                                            <input type="text" name="nouvelle_categorie" id="nouvelle_categorie_{{ $p->id }}" class="form-control" placeholder="ex: Informatique">
                                        </div>
                                        <div>
                                            <label class="form-label" style="font-size:11px;">Préfixe (3-4 lettres)</label>
                                            <input type="text" name="prefixe_categorie" id="prefixe_categorie_{{ $p->id }}" class="form-control" placeholder="ex: INFO" maxlength="5">
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Sous-catégorie</label>
                                    <select name="sous_categorie_id" id="sous_categorie_select_{{ $p->id }}" class="form-control" onchange="toggleSousCategorieInput('{{ $p->id }}')">
                                        <option value="">-- Sans sous-catégorie --</option>
                                        @if($p->category)
                                            @foreach($p->category->sousCategories as $sub)
                                                <option value="{{ $sub->id }}" {{ $p->sous_categorie_id == $sub->id ? 'selected' : '' }}>{{ $sub->nom }}</option>
                                            @endforeach
                                        @endif
                                        <option value="nouvelle">+ Créer une nouvelle sous-catégorie...</option>
                                    </select>
                                </div>
                                <div class="form-group" id="nouvelle_sous_categorie_container_{{ $p->id }}" style="display:none; background:var(--bg3); padding:10px; border-radius:8px; border:1px dashed var(--border);">
                                    <label class="form-label" style="font-size:11px;">Nom de la sous-catégorie</label>
                                    <input type="text" name="nouvelle_sous_categorie" id="nouvelle_sous_categorie_{{ $p->id }}" class="form-control" placeholder="ex: Ordinateurs portables">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Unité</label>
                                    <input type="text" name="unite" class="form-control" list="unites-suggerees"
                                           value="{{ $p->unite }}" placeholder="pièce, kg, forfait… ou la vôtre">
                                </div>
                                @include('admin::composants.champs_fne_produit', [
                                    'cle'     => $p->id,
                                    'produit' => $p,
                                    'regime'  => Auth::user()->entreprise->regime_imposition ?? null,
                                ])
                                <div class="form-group">
                                    <label class="form-label">Nature de la vente (Compte de vente) <span style="color:var(--danger)">*</span></label>
                                    <select name="compte_vente" id="compte_vente_select_{{ $p->id }}" class="form-control" required>
                                        @foreach($syscohadaKws->where('type_lie', 'Vente') as $kw)
                                            <option value="{{ $kw->compte_comptable_reel }}" {{ ($p->compte_vente ?? '701000') == $kw->compte_comptable_reel ? 'selected' : '' }}>
                                                {{ $kw->libelle_affiche }} @if(Auth::user()->role === 'admin') ({{ $kw->compte_comptable_reel }}) @endif
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Nature de l'achat (Compte d'achat) <span style="color:var(--danger)">*</span></label>
                                    <select name="compte_achat" id="compte_achat_select_{{ $p->id }}" class="form-control" required>
                                        @foreach($syscohadaKws->where('type_lie', 'Achat') as $kw)
                                            <option value="{{ $kw->compte_comptable_reel }}" {{ ($p->compte_achat ?? '601000') == $kw->compte_comptable_reel ? 'selected' : '' }}>
                                                {{ $kw->libelle_affiche }} @if(Auth::user()->role === 'admin') ({{ $kw->compte_comptable_reel }}) @endif
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                @if(Auth::user()->role === 'admin')
                                <div style="grid-column: 1/-1; display:flex; align-items:center; gap:8px; margin-top:-8px; margin-bottom:8px;">
                                    <input type="checkbox" id="toggle_custom_compta_{{ $p->id }}" onchange="toggleCustomCompta('{{ $p->id }}')">
                                    <label for="toggle_custom_compta_{{ $p->id }}" style="font-size:11px; font-weight:600; cursor:pointer; color:var(--text-3);">
                                        <i class="fas fa-sliders-h"></i> Personnaliser les comptes de comptabilité (Profil Comptable)
                                    </label>
                                </div>
                                <div class="form-group custom-compta-group-{{ $p->id }}" style="display:none;">
                                    <label class="form-label">Compte de vente personnalisé</label>
                                    <select id="custom_compte_vente_{{ $p->id }}" class="form-control">
                                        @foreach($comptes as $compte)
                                            <option value="{{ $compte->numero }}" {{ ($p->compte_vente ?? '701000') == $compte->numero ? 'selected' : '' }}>
                                                {{ $compte->numero }} - {{ $compte->libelle }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-group custom-compta-group-{{ $p->id }}" style="display:none;">
                                    <label class="form-label">Compte d'achat personnalisé</label>
                                    <select id="custom_compte_achat_{{ $p->id }}" class="form-control">
                                        @foreach($comptes as $compte)
                                            <option value="{{ $compte->numero }}" {{ ($p->compte_achat ?? '601000') == $compte->numero ? 'selected' : '' }}>
                                                {{ $compte->numero }} - {{ $compte->libelle }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                @endif
                                <div class="form-group" id="group-prix-achat-container-{{ $p->id }}">
                                    <label class="form-label">Prix achat</label>
                                    <input type="number" name="prix_achat" class="form-control" value="{{ $p->prix_achat }}" min="0" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Prix vente</label>
                                    <input type="number" name="prix_vente" class="form-control" value="{{ $p->prix_vente }}" min="0" required>
                                </div>
                                    {{-- La quantite ne se corrige plus ici : elle
                                         est la consequence des mouvements, pas
                                         un champ qu'on retape. Une correction
                                         se fait par l'inventaire, qui ecrit
                                         l'ecart, le date et le signe. --}}
                                <div class="form-group group-stock-input-{{ $p->id }}">
                                    <label class="form-label">Stock actuel</label>
                                    <input type="number" class="form-control" value="{{ $p->stock_actuel }}" readonly tabindex="-1"
                                           style="background:var(--bg3); cursor:default;">
                                    <small style="color:var(--text-3); font-size:11px;">
                                        Pour le corriger, passez par
                                        <a href="{{ route('admin.stock.inventaire') }}">l'inventaire</a>.
                                    </small>
                                </div>
                                <div class="form-group group-stock-input-{{ $p->id }}">
                                    <label class="form-label">Stock minimum</label>
                                    <input type="number" name="stock_minimum" class="form-control" value="{{ $p->stock_minimum }}" min="0" required>
                                </div>
                            </div>
                            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:8px;">
                                <button type="button" class="btn btn-outline" data-modal-close>Annuler</button>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Sauvegarder</button>
                            </div>
                        </form>
                    </div>
                </div>

                @endforeach
            </tbody>
        </table>
        <div style="padding: 0 16px;">{{ $produits->links() }}</div>
    </div>
</div>

</div>
{{-- /vue-tableau --}}

{{-- Modal Nouveau Produit --}}
<div class="modal-overlay" id="modalNouveauProduit">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header">
            <h3><i class="fas fa-plus"></i> Nouveau produit</h3>
            <button class="modal-close" data-modal-close>✕</button>
        </div>
        <form method="POST" action="{{ route('admin.produits.creer') }}">
            @csrf
            <div class="form-grid-2">
                                <div class="form-group">
                                    <label class="form-label">Type d'article <span style="color:var(--danger)">*</span></label>
                                    <select name="type" id="type_select_nouveau" class="form-control" onchange="toggleStockFields('nouveau')" required>
                                        @foreach(\App\Modules\Admin\Modeles\Produit::TYPES as $val => $libelle)
                                            <option value="{{ $val }}" {{ $val === 'marchandise' ? 'selected' : '' }}>{{ $libelle }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Catégorie <span style="color:var(--danger)">*</span></label>
                                    <select name="categorie_id" id="categorie_select_nouveau" class="form-control" onchange="toggleCategorieInput('nouveau'); calculerReferenceDynamique('nouveau')" required>
                                        <option value="">-- Choisir une catégorie --</option>
                                        @foreach($categories as $cat)
                                            <option value="{{ $cat->id }}">{{ $cat->nom }} ({{ $cat->prefixe }})</option>
                                        @endforeach
                                        <option value="nouvelle">+ Créer une nouvelle catégorie...</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Nom produit <span style="color:var(--danger)">*</span></label>
                                    <input type="text" name="nom" class="form-control" placeholder="Huile Dinor 1L" required>
                                </div>
                                <div class="form-group" style="grid-column: 1/-1;">
                                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">
                                        <label class="form-label" style="margin-bottom:0;">Référence du produit</label>
                                        <label style="display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:600; cursor:pointer; color:var(--primary);">
                                            <input type="checkbox" id="reference_auto_nouveau" name="reference_auto" value="1" checked onchange="toggleReferenceManual('nouveau')">
                                            Génération automatique
                                        </label>
                                    </div>
                                    <input type="text" id="reference_input_nouveau" class="form-control" placeholder="(Sélectionnez une catégorie...)" readonly style="background:var(--bg2);">
                                </div>
                                <div class="form-group" id="nouvelle_categorie_container_nouveau" style="display:none; grid-column: 1/-1;">
                                    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:10px; background:var(--bg3); padding:10px; border-radius:8px; border:1px dashed var(--border);">
                                        <div>
                                            <label class="form-label" style="font-size:11px;">Nom de la catégorie</label>
                                            <input type="text" name="nouvelle_categorie" id="nouvelle_categorie_nouveau" class="form-control" placeholder="ex: Informatique">
                                        </div>
                                        <div>
                                            <label class="form-label" style="font-size:11px;">Préfixe (3-4 lettres)</label>
                                            <input type="text" name="prefixe_categorie" id="prefixe_categorie_nouveau" class="form-control" placeholder="ex: INFO" maxlength="5">
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Sous-catégorie</label>
                                    <select name="sous_categorie_id" id="sous_categorie_select_nouveau" class="form-control" onchange="toggleSousCategorieInput('nouveau')">
                                        <option value="">-- Sans sous-catégorie --</option>
                                        <option value="nouvelle">+ Créer une nouvelle sous-catégorie...</option>
                                    </select>
                                </div>
                                <div class="form-group" id="nouvelle_sous_categorie_container_nouveau" style="display:none; background:var(--bg3); padding:10px; border-radius:8px; border:1px dashed var(--border);">
                                    <label class="form-label" style="font-size:11px;">Nom de la sous-catégorie</label>
                                    <input type="text" name="nouvelle_sous_categorie" id="nouvelle_sous_categorie_nouveau" class="form-control" placeholder="ex: Ordinateurs portables">
                                </div>
                <div class="form-group">
                    <label class="form-label">Unité</label>
                    <input type="text" name="unite" class="form-control" list="unites-suggerees"
                           placeholder="pièce, kg, forfait… ou la vôtre">
                </div>
                @include('admin::composants.champs_fne_produit', [
                    'cle'     => 'nouveau',
                    'produit' => null,
                    'regime'  => Auth::user()->entreprise->regime_imposition ?? null,
                ])
                <div class="form-group">
                    <label class="form-label">Nature de la vente (Compte de vente) <span style="color:var(--danger)">*</span></label>
                    <select name="compte_vente" id="compte_vente_select_nouveau" class="form-control" required>
                        @foreach($syscohadaKws->where('type_lie', 'Vente') as $kw)
                            <option value="{{ $kw->compte_comptable_reel }}" {{ $kw->compte_comptable_reel == '701000' ? 'selected' : '' }}>
                                {{ $kw->libelle_affiche }} @if(Auth::user()->role === 'admin') ({{ $kw->compte_comptable_reel }}) @endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Nature de l'achat (Compte d'achat) <span style="color:var(--danger)">*</span></label>
                    <select name="compte_achat" id="compte_achat_select_nouveau" class="form-control" required>
                        @foreach($syscohadaKws->where('type_lie', 'Achat') as $kw)
                            <option value="{{ $kw->compte_comptable_reel }}" {{ $kw->compte_comptable_reel == '601000' ? 'selected' : '' }}>
                                {{ $kw->libelle_affiche }} @if(Auth::user()->role === 'admin') ({{ $kw->compte_comptable_reel }}) @endif
                            </option>
                        @endforeach
                    </select>
                </div>

                @if(Auth::user()->role === 'admin')
                <div style="grid-column: 1/-1; display:flex; align-items:center; gap:8px; margin-top:-8px; margin-bottom:8px;">
                    <input type="checkbox" id="toggle_custom_compta_nouveau" onchange="toggleCustomCompta('nouveau')">
                    <label for="toggle_custom_compta_nouveau" style="font-size:11px; font-weight:600; cursor:pointer; color:var(--text-3);">
                        <i class="fas fa-sliders-h"></i> Personnaliser les comptes de comptabilité (Profil Comptable)
                    </label>
                </div>
                <div class="form-group custom-compta-group-nouveau" style="display:none;">
                    <label class="form-label">Compte de vente personnalisé</label>
                    <select id="custom_compte_vente_nouveau" class="form-control">
                        @foreach($comptes as $compte)
                            <option value="{{ $compte->numero }}" {{ $compte->numero == '701000' ? 'selected' : '' }}>
                                {{ $compte->numero }} - {{ $compte->libelle }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group custom-compta-group-nouveau" style="display:none;">
                    <label class="form-label">Compte d'achat personnalisé</label>
                    <select id="custom_compte_achat_nouveau" class="form-control">
                        @foreach($comptes as $compte)
                            <option value="{{ $compte->numero }}" {{ $compte->numero == '601000' ? 'selected' : '' }}>
                                {{ $compte->numero }} - {{ $compte->libelle }}
                            </option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div class="form-group" id="group-prix-achat-container-nouveau">
                    <label class="form-label">Prix d'achat (FCFA) <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="prix_achat" class="form-control" min="0" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Prix de vente (FCFA) <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="prix_vente" class="form-control" min="0" required>
                </div>
                <div class="form-group group-stock-input-nouveau">
                    <label class="form-label">Stock initial <span style="color:var(--danger)">*</span></label>
                    {{-- Le stock de depart. Il entre par la porte : un mouvement
                         « stock initial » est ecrit, et son cout donne le premier
                         CUMP (Cout Unitaire Moyen Pondere). --}}
                    <input type="number" name="stock_actuel" class="form-control" min="0" value="0" required>
                </div>
                <div class="form-group group-stock-input-nouveau">
                    <label class="form-label">Stock minimum <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="stock_minimum" class="form-control" min="0" value="5" required>
                </div>
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:8px;">
                <button type="button" class="btn btn-outline" data-modal-close>Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Ajouter au catalogue</button>
            </div>
        </form>
    </div>
</div>

<script>
const categoriesData = @json($categories);

function toggleStockFields(id) {
    const typeSelect = document.getElementById('type_select_' + id);
    const stockFields = document.querySelectorAll('.group-stock-input-' + id);
    const prixAchatContainer = document.getElementById('group-prix-achat-container-' + id);

    if (!typeSelect) return;
    const isService = (typeSelect.value === 'service');
    const isNoStock = (isService || typeSelect.value === 'consommable_non_stockable');

    // Masquer/afficher champs de stock
    stockFields.forEach(field => {
        field.style.display = isNoStock ? 'none' : 'block';
        const input = field.querySelector('input');
        if (input) {
            if (isNoStock) input.removeAttribute('required');
            else input.setAttribute('required', 'required');
        }
    });

    // Masquer prix d'achat pour les services
    if (prixAchatContainer) {
        prixAchatContainer.style.display = isService ? 'none' : 'block';
        const prixInput = prixAchatContainer.querySelector('input');
        if (prixInput) {
            if (isService) prixInput.removeAttribute('required');
            else prixInput.setAttribute('required', 'required');
        }
    }
}

function toggleCategorieInput(id) {
    const select = document.getElementById('categorie_select_' + id);
    const container = document.getElementById('nouvelle_categorie_container_' + id);
    const subSelect = document.getElementById('sous_categorie_select_' + id);

    if (select.value === 'nouvelle') {
        container.style.display = 'block';
    } else {
        container.style.display = 'none';
    }

    if (subSelect) {
        subSelect.innerHTML = '<option value="">-- Sans sous-catégorie --</option>';
        if (select.value && select.value !== 'nouvelle') {
            const catId = parseInt(select.value);
            const selectedCat = categoriesData.find(c => c.id === catId);
            if (selectedCat && selectedCat.sous_categories) {
                selectedCat.sous_categories.forEach(sub => {
                    subSelect.innerHTML += `<option value="${sub.id}">${sub.nom}</option>`;
                });
            }
        }
        subSelect.innerHTML += '<option value="nouvelle">+ Créer une nouvelle sous-catégorie...</option>';
        toggleSousCategorieInput(id);
    }
}

function toggleSousCategorieInput(id) {
    const select = document.getElementById('sous_categorie_select_' + id);
    const container = document.getElementById('nouvelle_sous_categorie_container_' + id);

    if (select && select.value === 'nouvelle') {
        container.style.display = 'block';
    } else if (container) {
        container.style.display = 'none';
    }
}

// ─── Référence automatique / manuelle ───────────────────────────────────────

function toggleReferenceManual(id) {
    const checkbox = document.getElementById('reference_auto_' + id);
    const input    = document.getElementById('reference_input_' + id);
    if (!checkbox || !input) return;
    if (checkbox.checked) {
        // Mode auto : champ readonly, recalculer
        input.removeAttribute('name');
        input.setAttribute('readonly', true);
        input.style.background = 'var(--bg2)';
        calculerReferenceDynamique(id);
    } else {
        // Mode manuel : champ éditable
        input.setAttribute('name', 'reference');
        input.removeAttribute('readonly');
        input.style.background = '';
        input.placeholder = 'Saisir une référence unique...';
        input.value = '';
        input.focus();
    }
}

function calculerReferenceDynamique(id) {
    const checkbox    = document.getElementById('reference_auto_' + id);
    const input       = document.getElementById('reference_input_' + id);
    const catSelect   = document.getElementById('categorie_select_' + id);
    if (!checkbox || !checkbox.checked || !input || !catSelect) return;

    const catId = catSelect.value;
    if (!catId || catId === 'nouvelle') {
        input.value = '';
        input.placeholder = '(Sélectionnez une catégorie...)';
        return;
    }

    // Trouver le préfixe dans categoriesData
    const catData = categoriesData.find(c => String(c.id) === String(catId));
    const prefixe = catData ? catData.prefixe : 'REF';

    input.placeholder = 'Calcul en cours...';
    fetch('/admin/produits/calculer-reference?categorie_id=' + catId, {
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
    })
        .then(r => r.json())
        .then(data => {
            if (data && data.reference) {
                input.value = data.reference;
            } else {
                input.value = prefixe + '-001';
            }
        })
        .catch(() => {
            input.value = prefixe + '-001';
        });
}

function toggleCustomCompta(id) {
    const checkbox = document.getElementById('toggle_custom_compta_' + id);
    const groups = document.querySelectorAll('.custom-compta-group-' + id);
    const selectVente = document.getElementById('compte_vente_select_' + id);
    const selectAchat = document.getElementById('compte_achat_select_' + id);

    if (checkbox.checked) {
        groups.forEach(g => g.style.display = 'block');
        if (selectVente) selectVente.removeAttribute('name');
        if (selectAchat) selectAchat.removeAttribute('name');
        document.getElementById('custom_compte_vente_' + id).setAttribute('name', 'compte_vente');
        document.getElementById('custom_compte_achat_' + id).setAttribute('name', 'compte_achat');
    } else {
        groups.forEach(g => g.style.display = 'none');
        if (selectVente) selectVente.setAttribute('name', 'compte_vente');
        if (selectAchat) selectAchat.setAttribute('name', 'compte_achat');
        document.getElementById('custom_compte_vente_' + id).removeAttribute('name');
        document.getElementById('custom_compte_achat_' + id).removeAttribute('name');
    }
}

/**
 * Régime de TVA choisi manuellement : le code DGI porte le taux, sauf « Autre »
 * qui ouvre une saisie libre. Le champ caché `taux_tva` reste la seule valeur
 * envoyée au serveur.
 */
function appliquerTauxDuCodeTva(cle) {
    const select = document.getElementById('code_tva_select_' + cle);
    const libre  = document.getElementById('code_tva_libre_' + cle);
    const cache  = document.getElementById('tva_input_' + cle);
    if (!select || !cache) return;

    const estLibre = select.value === 'AUTRE';
    if (libre) libre.style.display = estLibre ? 'block' : 'none';

    if (estLibre) {
        synchroniserTauxLibre(cle);
        return;
    }

    const option = select.options[select.selectedIndex];
    cache.value = option.dataset.taux ?? 18;
}

function synchroniserTauxLibre(cle) {
    const saisie = document.getElementById('taux_libre_' + cle);
    const cache  = document.getElementById('tva_input_' + cle);
    if (saisie && cache) cache.value = saisie.value;
}

// ─── Champs FNE du produit : code TVA et autres taxes ────────────────────────

const REGIME_ENTREPRISE = @json(Auth::user()->entreprise->regime_imposition ?? null);
// La constante gelee du modele, et non une copie : celle qui vivait ici
// valait ['TEE', 'RNE'] quand le serveur retient TEE, TCE et RME. L'ecran
// annoncait donc un code TVA que le payload ne transmettait pas.
const REGIMES_EXONERATION_LEGALE = {!! json_encode(\App\Modules\Admin\Modeles\Produit::REGIMES_EXONERATION_LEGALE) !!};

/**
 * Même règle de déduction que Produit::deduireCodeTva() côté serveur : un taux
 * nul ne suffit pas à distinguer TVAC (exonération conventionnelle) de TVAD
 * (exonération légale, réservée aux régimes que la DGI énumère).
 */
function deduireCodeTva(taux) {
    const t = Math.round(parseFloat(taux || 0) * 100) / 100;
    if (t === 18) return 'TVA';
    if (t === 9)  return 'TVAB';
    if (t === 0)  return REGIMES_EXONERATION_LEGALE.includes(REGIME_ENTREPRISE) ? 'TVAD' : 'TVAC';
    return 'TVA';
}

function rafraichirApercuCodeTva(cle) {
    const apercu = document.getElementById('apercu_code_tva_' + cle);
    if (!apercu) return;

    const taux = parseFloat(document.getElementById('tva_input_' + cle)?.value || 0);
    apercu.textContent = deduireCodeTva(taux);

    // Le taux est rappelé à côté du code : « TVA » seul ne disait pas de
    // combien il s'agissait.
    const apercuTaux = document.getElementById('apercu_taux_tva_' + cle);
    if (apercuTaux) apercuTaux.textContent = String(taux).replace('.', ',');
}

function basculerCodeTva(cle) {
    const manuel    = document.getElementById('code_tva_manuel_' + cle).checked;
    const auto      = document.getElementById('code_tva_auto_' + cle);
    const conteneur = document.getElementById('code_tva_manuel_container_' + cle);

    if (auto)      auto.style.display      = manuel ? 'none' : 'block';
    if (conteneur) conteneur.style.display = manuel ? 'block' : 'none';

    if (!manuel) rafraichirApercuCodeTva(cle);
}

function ajouterTaxeProduit(cle, nom, taux) {
    const conteneur = document.getElementById('taxes_produit_' + cle);
    if (!conteneur) return;

    const index = conteneur.children.length;
    const ligne = document.createElement('div');
    ligne.style.cssText = 'display:flex; gap:8px; align-items:center;';
    ligne.innerHTML = `
        <input type="text" name="taxes_produit[${index}][nom]" class="form-control"
               placeholder="Nom de la taxe (ex : GRA)" maxlength="100"
               value="${nom ? String(nom).replace(/"/g, '&quot;') : ''}" style="flex:2;">
        <input type="number" name="taxes_produit[${index}][taux]" class="form-control"
               placeholder="Taux (%)" step="0.01" min="0.01" max="100"
               value="${taux !== undefined && taux !== null ? taux : ''}" style="flex:1;">
        <button type="button" class="btn btn-outline" title="Supprimer cette taxe"
                onclick="supprimerTaxeProduit(this, '${cle}')"
                style="color:var(--danger); border-color:var(--danger); padding:6px 10px;">
            <i class="fas fa-trash"></i>
        </button>
    `;
    conteneur.appendChild(ligne);
}

function supprimerTaxeProduit(bouton, cle) {
    bouton.closest('div').remove();
    reindexerTaxesProduit(cle);
}

/**
 * Les noms de champs doivent rester une suite continue d'index pour que
 * Laravel reçoive bien un tableau après suppression d'une ligne.
 */
function reindexerTaxesProduit(cle) {
    const conteneur = document.getElementById('taxes_produit_' + cle);
    if (!conteneur) return;

    Array.from(conteneur.children).forEach((ligne, index) => {
        const champs = ligne.querySelectorAll('input');
        if (champs[0]) champs[0].name = `taxes_produit[${index}][nom]`;
        if (champs[1]) champs[1].name = `taxes_produit[${index}][taux]`;
    });
}

document.addEventListener('DOMContentLoaded', function () {
    Object.entries(window.taxesProduitInitiales || {}).forEach(([cle, taxes]) => {
        (taxes || []).forEach(taxe => ajouterTaxeProduit(cle, taxe.nom, taxe.taux));
        rafraichirApercuCodeTva(cle);
    });
});

// ─── Toggle Vue Tableau / Cartes ────────────────────────────────────────────

function setVue(mode) {
    const cartes  = document.getElementById('vue-cartes');
    const tableau = document.getElementById('vue-tableau');
    const btnC    = document.getElementById('btn-vue-cartes');
    const btnT    = document.getElementById('btn-vue-tableau');

    if (mode === 'cartes') {
        cartes.style.display  = 'block';
        tableau.style.display = 'none';
        btnC.style.background = 'var(--primary)';
        btnC.style.color      = '#fff';
        btnT.style.background = '';
        btnT.style.color      = '';
    } else {
        cartes.style.display  = 'none';
        tableau.style.display = 'block';
        btnT.style.background = 'var(--primary)';
        btnT.style.color      = '#fff';
        btnC.style.background = '';
        btnC.style.color      = '';
    }
    localStorage.setItem('selflow_produits_vue', mode);
}

// ─── Filtre côté client (sur la page courante) ───────────────────────────────

function filtrerProduits() {
    const terme    = document.getElementById('recherche-produit').value.toLowerCase();
    const cat      = document.getElementById('filtre-categorie').value.toLowerCase();
    const type     = document.getElementById('filtre-type').value.toLowerCase();

    // Filtrer les cartes
    document.querySelectorAll('.produit-carte').forEach(function(carte) {
        const nom      = carte.dataset.nom || '';
        const catCard  = (carte.dataset.categorie || '').toLowerCase();
        const typeCard = (carte.dataset.type || '').toLowerCase();
        const matchT   = !terme || nom.includes(terme);
        const matchC   = !cat  || catCard === cat;
        const matchTy  = !type || typeCard === type;
        carte.style.display = (matchT && matchC && matchTy) ? '' : 'none';
    });

    // Filtrer les lignes du tableau
    document.querySelectorAll('#vue-tableau tbody tr.ligne-produit').forEach(function(tr) {
        const nom     = (tr.dataset.nom || '').toLowerCase();
        const catRow  = (tr.dataset.categorie || '').toLowerCase();
        const typeRow = (tr.dataset.type || '').toLowerCase();
        const matchT  = !terme || nom.includes(terme);
        const matchC  = !cat  || catRow === cat;
        const matchTy = !type || typeRow === type;
        tr.style.display = (matchT && matchC && matchTy) ? '' : 'none';
    });
}

// ─── Upload photo (AJAX) ─────────────────────────────────────────────────────

function uploaderPhoto(input, adressePhoto) {
    if (!input.files || !input.files[0]) return;

    const formData = new FormData();
    formData.append('photo', input.files[0]);
    formData.append('_token', document.querySelector('meta[name="csrf-token"]').content);

    fetch(adressePhoto, {
        method: 'POST',
        body: formData,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Mettre à jour l'image dans la carte
            const carte = input.closest('.produit-carte');
            if (carte) {
                const img = carte.querySelector('img');
                if (img) img.src = data.photo_url + '?t=' + Date.now();
            }
        }
    })
    .catch(() => alert('Erreur lors de l\'upload de la photo.'));
}

// ─── Restaurer la vue préférée ────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function() {
    const vue = localStorage.getItem('selflow_produits_vue') || 'cartes';
    setVue(vue);

    // Initialiser le masquage des champs de stock selon le type pour la création
    toggleStockFields('nouveau');

    // Initialiser le champ référence (mode auto par défaut)
    toggleReferenceManual('nouveau');

    // Initialiser pour chaque produit de la page
    document.querySelectorAll('[id^="type_select_"]').forEach(select => {
        const id = select.id.replace('type_select_', '');
        toggleStockFields(id);
    });
});
</script>
@include('admin::composants.modal-import', ['type' => 'produits', 'label' => 'Produits', 'id' => 'modalImportProduits'])

{{-- Bornage immediat de tout taux saisi : aucune remise ni taxe ne peut sortir
     de l'intervalle 0-100 %, regle imposee par la DGI. La validation serveur
     reste la garantie ; ce script evite seulement a l'utilisateur de saisir une
     valeur qui sera refusee. --}}
<script>
document.addEventListener('input', function (e) {
    const champ = e.target;
    if (champ.tagName !== 'INPUT' || champ.type !== 'number') return;
    if (champ.max !== '100') return;

    const valeur = parseFloat(champ.value);
    if (isNaN(valeur)) return;

    if (valeur > 100) champ.value = 100;
    if (valeur < parseFloat(champ.min || 0)) champ.value = champ.min || 0;
});
</script>

@include('admin::partials.unites-suggerees')

@endsection

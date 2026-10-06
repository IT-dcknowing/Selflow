@extends('admin::gabarits.application')
@section('titre', 'Gestion fournisseurs')
@section('topbar_titre', 'Catalogue — Fournisseurs')

@section('contenu')
{{-- Comptabilité éteinte : aucun compte à l'écran. Le serveur pose
     411000 / 401000 (chantier 3.1, lot 46). --}}
@php $comptaOuverte = auth()->user()->estSuperAdmin() || (bool) auth()->user()->entreprise?->comptabiliteOuverte(); @endphp
<div class="page-header">
    <div>
        <h1><i class="fas fa-handshake"></i> Liste des fournisseurs</h1>
        <p>{{ $fournisseurs->total() + $fournisseursComptaflow->total() }} fournisseur(s) au total</p>
    </div>
    <div style="display:flex; gap:12px; align-items:center;">
        {{-- Switch bouton --}}
        <div class="vue-toggle" style="display:flex; background:var(--bg2); border:1px solid var(--border); border-radius:8px; overflow:hidden; padding:2px;">
            <button type="button" id="btn-vue-local" onclick="switchVue('local')" class="btn btn-sm btn-primary"
                style="padding:6px 12px; font-size:12px; font-weight:700; border-radius:6px; border:none; cursor:pointer;">
                <i class="fas fa-database"></i> Local ({{ $fournisseurs->total() }})
            </button>
            <button type="button" id="btn-vue-comptaflow" onclick="switchVue('comptaflow')" class="btn btn-sm btn-outline"
                style="padding:6px 12px; font-size:12px; font-weight:700; border-radius:6px; border:none; cursor:pointer; background:transparent; color:var(--text-2);">
                <i class="fas fa-sync"></i> COMPTAFLOW ({{ $fournisseursComptaflow->total() }})
            </button>
        </div>
        <button class="btn btn-outline" onclick="ouvrirImport('modalImportFournisseurs')" style="font-size:13px;">
            <i class="fas fa-file-import"></i> Importer CSV
        </button>
        <button id="btn-nouveau-fournisseur" class="btn btn-primary" data-modal-open="modalNouveauFournisseur">
            <i class="fas fa-plus"></i> Ajouter un fournisseur
        </button>
    </div>
</div>

{{-- Alertes flash --}}
@if(session('erreur'))
<div style="background:#fff5f5; color:#e53e3e; border:1px solid #fed7d7; padding:12px 16px; border-radius:8px; margin-bottom:16px; font-weight:600;">
    <i class="fas fa-exclamation-triangle"></i> {{ session('erreur') }}
</div>
@endif

{{-- Barre de recherche — filtre automatiquement dès la saisie (anti-rebond 500ms) --}}
<form method="GET" action="{{ route('admin.fournisseurs.index') }}" style="margin-bottom:16px;" id="formRechercheFournisseurs">
    <div style="display:flex; gap:10px; align-items:center; max-width:450px;">
        <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Rechercher par nom, secteur, tél., n° tiers..." class="form-control" style="flex:1;"
               oninput="clearTimeout(window._rechercheFournisseursTimer); window._rechercheFournisseursTimer = setTimeout(() => document.getElementById('formRechercheFournisseurs').submit(), 500);">
        <button type="submit" class="btn btn-primary" style="white-space:nowrap;" title="Rechercher maintenant"><i class="fas fa-search"></i></button>
        @if(!empty($search))
            <a href="{{ route('admin.fournisseurs.index') }}" class="btn btn-outline" style="white-space:nowrap;"><i class="fas fa-times"></i></a>
        @endif
    </div>
</form>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const champ = document.querySelector('#formRechercheFournisseurs input[name="search"]');
        if (champ && champ.value) {
            champ.focus();
            champ.setSelectionRange(champ.value.length, champ.value.length);
        }
    });
</script>

<div id="section-local">
    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Nom du fournisseur</th>
                        <th>Type</th>
                        <th>Secteur d'activité</th>
                        <th>N° tiers</th>
                        <th>NCC</th>
                        <th>RCCM</th>
                        @if($comptaOuverte)<th>Compte général</th>@endif
                        <th>Régime</th>
                        <th>Téléphone</th>
                        <th>E-mail</th>
                        <th>Adresse</th>
                        <th>Achats</th>
                        <th>Date d'ajout</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($fournisseurs as $f)
                    <tr>
                        <td style="font-weight:600; color:var(--text);">{{ $f->nom }}</td>
                        <td>
                            @if($f->type_facturation)
                                @php $tf = $f->type_facturation; @endphp
                                <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;
                                    background:{{ $tf==='B2B' ? '#eff6ff' : ($tf==='B2C' ? '#f0fdf4' : ($tf==='B2G' ? '#fffbeb' : '#faf5ff')) }};
                                    color:{{ $tf==='B2B' ? '#1d4ed8' : ($tf==='B2C' ? '#15803d' : ($tf==='B2G' ? '#b45309' : '#7c3aed')) }};
                                    border:1px solid {{ $tf==='B2B' ? '#bfdbfe' : ($tf==='B2C' ? '#bbf7d0' : ($tf==='B2G' ? '#fde68a' : '#ddd6fe')) }};">{{ $tf }}</span>
                            @else
                                <span style="color:var(--text-3);font-size:12px;">—</span>
                            @endif
                        </td>
                        <td><span class="badge badge-purple">{{ $f->secteur ?? 'Général' }}</span></td>
                        <td style="font-family: monospace; font-weight: 700; color: var(--primary);">
                            {{ $f->numero_tiers ?? '—' }}
                            @if(!empty($f->numero_original))
                                <div style="font-size: 10px; color: var(--text-3); font-weight: normal; margin-top: 3px; display: flex; align-items: center; gap: 4px;">
                                    <i class="fas fa-file-invoice" style="font-size: 9px; opacity: 0.7;"></i> Original: {{ $f->numero_original }}
                                </div>
                            @endif
                        </td>
                        <td>{{ $f->ncc ?? '—' }}</td>
                        <td>{{ $f->rccm ?? '—' }}</td>
                        @if($comptaOuverte)
                        <td style="font-family: monospace; font-weight: 700;">
                            {{ $f->compte_comptable ?? '401000' }}
                            @php
                                $compteObj = $comptes->firstWhere('numero', $f->compte_comptable ?? '401000');
                            @endphp
                            @if($compteObj && !empty($compteObj->numero_original))
                                <div style="font-size: 10px; color: var(--text-3); font-weight: normal; margin-top: 3px; display: flex; align-items: center; gap: 4px;">
                                    <i class="fas fa-file-invoice" style="font-size: 9px; opacity: 0.7;"></i> Original: {{ $compteObj->numero_original }}
                                </div>
                            @endif
                        </td>
                        @endif
                        <td>{{ $f->regime_imposition ?? '—' }}</td>
                        <td>{{ $f->telephone ?? '—' }}</td>
                        <td>{{ $f->email ?? '—' }}</td>
                        <td>{{ $f->adresse ?? '—' }}</td>
                        <td>
                            <span class="badge badge-success">{{ $f->achats_count }} bon(s) d'achat</span>
                        </td>
                        <td style="color:var(--text-3);">{{ $f->created_at->format('d/m/Y') }}</td>
                        <td style="display:flex; gap:6px; flex-wrap:wrap;">
                            <button class="btn btn-outline btn-sm btn-modifier-fournisseur"
                                {{-- Même défaut que sur la fiche client : le numéro de ligne partait
     seul dans l'adresse, alors que les adresses portent l'`uuid`.
     Modifier un fournisseur tombait en 404 (Not Found — introuvable). --}}
                                data-action="{{ route('admin.fournisseurs.modifier', $f) }}"
                                data-nom="{{ $f->nom }}"
                                data-type="{{ $f->type_facturation }}"
                                data-secteur="{{ $f->secteur }}"
                                data-telephone="{{ $f->telephone }}"
                                data-email="{{ $f->email }}"
                                data-adresse="{{ $f->adresse }}"
                                data-ncc="{{ $f->ncc }}"
                                data-rccm="{{ $f->rccm }}"
                                data-regime="{{ $f->regime_imposition }}"
                                data-compte="{{ $f->compte_comptable ?? '401000' }}"
                                data-numero="{{ $f->numero_tiers }}"
                                data-source="{{ $f->source }}"
                                style="padding: 5px 10px;">
                                <i class="fas fa-edit"></i>
                            </button>
                            @if($f->achats_count == 0)
                            <form method="POST" action="{{ route('admin.fournisseurs.supprimer', $f) }}" onsubmit="return confirm('Supprimer ce fournisseur ?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm" style="padding:5px 10px; background:#fff5f5; color:#e53e3e; border:1px solid #fed7d7;"><i class="fas fa-trash"></i></button>
                            </form>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="13" style="text-align:center; color:var(--text-3); padding:30px;">
                            Aucun fournisseur local enregistré pour le moment.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
            @if($fournisseurs->hasPages())
            <div style="padding: 16px;">
                {{ $fournisseurs->links() }}
            </div>
            @endif
        </div>
    </div>
</div>

<div id="section-comptaflow" style="display:none;">
    @if(empty($entreprise->comptaflow_sync_key))
        <div class="card" style="padding: 30px; text-align: center;">
            <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: var(--danger); margin-bottom: 20px; opacity: 0.8;"></i>
            <h2 style="font-size: 18px; font-weight: 700; margin-bottom: 10px;">Liaison COMPTAFLOW non active</h2>
            <div style="max-width: 500px; margin: 0 auto; padding: 20px; border-radius: 8px; background: #fff5f5; border: 1px solid #feb2b2; margin-top: 15px;">
                <p style="font-size: 13px; color: #4a5568; line-height: 1.5;">
                    Veuillez configurer votre clé de synchronisation COMPTAFLOW dans les paramètres de l'entreprise pour activer la liaison et importer les comptes fournisseurs.
                </p>
                <a href="{{ route('admin.entreprise.parametres') }}" class="btn btn-primary" style="margin-top: 15px; display: inline-flex;">
                    <i class="fas fa-cog"></i> Configurer la liaison
                </a>
            </div>
        </div>
    @else
        <div style="background: linear-gradient(135deg, #eff6ff, #f0fdf4); border: 1px solid #bfdbfe; border-radius: 12px; padding: 16px; margin-bottom: 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div>
                <p style="font-weight:700; color:#1e40af; margin-bottom:4px;"><i class="fas fa-link"></i> Fournisseurs synchronisés depuis COMPTAFLOW</p>
                <p style="font-size: 13px; color: #64748b; margin-bottom:0;">
                    Leurs informations COMPTAFLOW (nom, numéro de tiers, compte collectif) sont protégées en écriture, mais vous pouvez compléter leurs données Selflow manquantes ci-dessous.
                </p>
            </div>
            <button type="button" id="btn-sync-fournisseurs" onclick="lancerSyncComptaflow()" class="btn btn-primary" style="font-weight:700; white-space:nowrap;">
                <i class="fas fa-sync-alt" id="sync-icon-fournisseurs"></i> Synchroniser les tiers
            </button>
        </div>
        <div id="sync-feedback-fournisseurs" style="display:none; margin-bottom: 16px; padding: 12px; border-radius: 6px; font-weight: 600; font-size: 13px;"></div>

        <div class="card">
            <div class="table-wrap">
                @if($fournisseursComptaflow->isEmpty())
                <div style="padding:48px; text-align:center; color:var(--text-3);">
                    <i class="fas fa-handshake" style="font-size:48px; display:block; margin-bottom:12px; opacity:.2;"></i>
                    Aucun fournisseur COMPTAFLOW synchronisé. Cliquez sur <strong>Synchroniser les tiers</strong>.
                </div>
                @else
                <table>
                    <thead>
                        <tr>
                            <th>Nom du fournisseur</th>
                            <th>Secteur d'activité</th>
                            <th>N° tiers</th>
                            <th>NCC</th>
                            <th>RCCM</th>
                            @if($comptaOuverte)<th>Compte général</th>@endif
                            <th>Régime</th>
                            <th>Téléphone</th>
                            <th>E-mail</th>
                            <th>Adresse</th>
                            <th>Achats</th>
                            <th>Date d'ajout</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($fournisseursComptaflow as $f)
                        <tr>
                            <td style="font-weight:600; color:var(--text);">{{ $f->nom }}</td>
                            <td>
                                <span class="badge badge-purple">{{ $f->secteur ?? 'Général' }}</span>
                            </td>
                            <td style="font-family: monospace; font-weight: 700; color: var(--primary);">
                                {{ $f->numero_tiers ?? '—' }}
                                @if(!empty($f->numero_original))
                                    <div style="font-size: 10px; color: var(--text-3); font-weight: normal; margin-top: 3px; display: flex; align-items: center; gap: 4px;">
                                        <i class="fas fa-file-invoice" style="font-size: 9px; opacity: 0.7;"></i> Original: {{ $f->numero_original }}
                                    </div>
                                @endif
                            </td>
                            <td>{{ $f->ncc ?? '—' }}</td>
                            <td>{{ $f->rccm ?? '—' }}</td>
                            @if($comptaOuverte)
                            <td style="font-family: monospace; font-weight: 700;">
                                {{ $f->compte_comptable ?? '401000' }}
                                @php
                                    $compteObj = $comptes->firstWhere('numero', $f->compte_comptable ?? '401000');
                                @endphp
                                @if($compteObj && !empty($compteObj->numero_original))
                                    <div style="font-size: 10px; color: var(--text-3); font-weight: normal; margin-top: 3px; display: flex; align-items: center; gap: 4px;">
                                        <i class="fas fa-file-invoice" style="font-size: 9px; opacity: 0.7;"></i> Original: {{ $compteObj->numero_original }}
                                    </div>
                                @endif
                            </td>
                            @endif
                            <td>{{ $f->regime_imposition ?? '—' }}</td>
                            <td>{{ $f->telephone ?? '—' }}</td>
                            <td>{{ $f->email ?? '—' }}</td>
                            <td>{{ $f->adresse ?? '—' }}</td>
                            <td>
                                <span class="badge badge-success">{{ $f->achats_count }} bon(s) d'achat</span>
                            </td>
                            <td style="color:var(--text-3);">{{ $f->created_at->format('d/m/Y') }}</td>
                            <td>
                                <button class="btn btn-outline btn-sm btn-modifier-fournisseur"
                                    {{-- Même défaut que sur la fiche client : le numéro de ligne partait
     seul dans l'adresse, alors que les adresses portent l'`uuid`.
     Modifier un fournisseur tombait en 404 (Not Found — introuvable). --}}
                                data-action="{{ route('admin.fournisseurs.modifier', $f) }}"
                                    data-nom="{{ $f->nom }}"
                                    data-secteur="{{ $f->secteur }}"
                                    data-telephone="{{ $f->telephone }}"
                                    data-email="{{ $f->email }}"
                                    data-adresse="{{ $f->adresse }}"
                                    data-ncc="{{ $f->ncc }}"
                                    data-rccm="{{ $f->rccm }}"
                                    data-regime="{{ $f->regime_imposition }}"
                                    data-compte="{{ $f->compte_comptable ?? '401000' }}"
                                    data-numero="{{ $f->numero_tiers }}"
                                    data-source="{{ $f->source }}"
                                    style="padding: 5px 10px;">
                                    <i class="fas fa-edit"></i> Modifier
                                </button>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @if($fournisseursComptaflow->hasPages())
                <div style="padding: 16px;">
                    {{ $fournisseursComptaflow->links() }}
                </div>
                @endif
                @endif
            </div>
        </div>
    @endif
</div>

{{-- Modal Nouveau Fournisseur --}}
<div class="modal-overlay" id="modalNouveauFournisseur">
    <div class="modal" style="max-width:580px;">
        <div class="modal-header">
            <h3><i class="fas fa-plus"></i> Nouveau fournisseur</h3>
            <button class="modal-close" data-modal-close>✕</button>
        </div>
        <form method="POST" action="{{ route('admin.fournisseurs.creer') }}">
            @csrf
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Nom du Fournisseur <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="nom" class="form-control" placeholder="Ex: CDCI Distribution" required>
                </div>
                {{-- Type de facturation --}}
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Type de facturation <span style="color:#E53E3E">*</span></label>
                    <select name="type_facturation" id="fnew_type_facturation" class="form-control" onchange="toggleNccFournisseur('fnew')">
                        <option value="">— Sélectionner le type —</option>
                        <option value="B2B" selected>B2B — Entreprise à Entreprise (NCC requis)</option>
                        <option value="B2C">B2C — Entreprise à Particulier</option>
                        <option value="B2G">B2G — Entreprise à État / Collectivité</option>
                        <option value="B2F">B2F — Entreprise à International</option>
                    </select>
                    <small style="color:var(--text-3);font-size:11px;">Les fournisseurs sont généralement B2B — NCC requis.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Secteur d'activité</label>
                    <input type="text" name="secteur" class="form-control" placeholder="Ex: Alimentation, Électronique…">
                </div>
                <div class="form-group">
                    <label class="form-label">Téléphone</label>
                    <input type="text" name="telephone" class="form-control" placeholder="Ex: +225 27 00 00 00">
                </div>
                <div class="form-group">
                    <label class="form-label">Adresse E-mail</label>
                    <input type="email" name="email" class="form-control" placeholder="Ex: contact@fournisseur.com">
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Adresse physique</label>
                    <input type="text" name="adresse" class="form-control" placeholder="Ex: Zone 4, Marcory, Abidjan">
                </div>
                {{-- Informations fiscales & Comptables --}}
                <div style="grid-column:1/-1; padding:12px 14px; background:var(--bg3); border-radius:8px; border:1px solid var(--border); margin-top:4px;">
                    <div style="font-size:11px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
                        <i class="fas fa-file-invoice" style="margin-right:6px;"></i>Informations fiscales &amp; comptables
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="form-group" style="margin-bottom:0;" id="fnew_ncc_group">
                            <label class="form-label" id="fnew_ncc_label">NCC (Nº Compte Contribuable) <span style="color:#E53E3E">*</span></label>
                            <input type="text" name="ncc" id="fnew_ncc_input" class="form-control" placeholder="Obligatoire pour B2B — Ex: 2169728N" style="border:1.5px solid #E53E3E;">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">RCCM (Registre de commerce)</label>
                            <input type="text" name="rccm" class="form-control" placeholder="Ex: CI-ABJ-03-2021-B13-05438">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Régime d'imposition</label>
                            <select name="regime_imposition" class="form-control">
                                <option value="">— Non renseigné —</option>
                                <option value="TEE">TEE (Taxe sur l'Entreprise Employeuse)</option>
                                <option value="RS">RS (Régime Simplifié)</option>
                                <option value="RSI">RSI (Régime Simplifié d'Imposition)</option>
                                <option value="RNI">RNI (Régime Normal d'Imposition)</option>
                                <option value="Exonéré">Exonéré</option>
                            </select>
                        </div>
                        @if($comptaOuverte)
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Compte comptable général collective</label>
                            <select name="compte_comptable" class="form-control" required>
                                @foreach($comptes as $compte)
                                    <option value="{{ $compte->numero }}" {{ $compte->numero == '401000' ? 'selected' : '' }}>
                                        {{ $compte->numero }} - {{ $compte->libelle }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        @endif
                        {{-- Le numéro de tiers ne se saisit pas : le système le
                             fabrique, selon la convention choisie dans les
                             paramètres. Comptaflow retrouve un tiers par
                             égalité de chaîne — une saisie libre produirait des
                             numéros que sa recherche ne trouverait jamais, et
                             chaque écriture de ce tiers retomberait sur son
                             compte collectif. --}}
                        <div class="form-group" style="margin-bottom:0; grid-column: 1/-1;">
                            <div style="display:flex;align-items:flex-start;gap:8px;padding:10px 12px;background:var(--bg3);border:1px solid var(--border);border-radius:8px;font-size:12px;color:var(--text-2);line-height:1.55;">
                                <i class="fas fa-hashtag" style="margin-top:2px;color:var(--primary);"></i>
                                <span>
                                    Le <strong>numéro de tiers</strong> est attribué automatiquement
                                    (400001), selon la convention réglée dans
                                    <em>Paramètres &rsaquo; Numérotation des tiers</em>.
                                    Il ne se confond pas avec le compte de rattachement choisi ci-dessus :
                                    celui-ci regroupe tout le monde, le numéro de tiers désigne ce fournisseur-ci.
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:16px;">
                <button type="button" class="btn btn-outline" data-modal-close>Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer le fournisseur</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal Modifier Fournisseur --}}
<div class="modal-overlay" id="modalModifierFournisseur">
    <div class="modal" style="max-width:580px;">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Modifier le fournisseur</h3>
            <button class="modal-close" data-modal-close>✕</button>
        </div>
        <form method="POST" id="formModifierFournisseur">
            @csrf
            @method('PUT')
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Nom du Fournisseur <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="nom" id="edit_nom" class="form-control" placeholder="Ex: CDCI Distribution" required>
                </div>
                {{-- Type de facturation --}}
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Type de facturation <span style="color:#E53E3E">*</span></label>
                    <select name="type_facturation" id="fedit_type_facturation" class="form-control" onchange="toggleNccFournisseur('fedit')">
                        <option value="">— Sélectionner le type —</option>
                        <option value="B2B">B2B — Entreprise à Entreprise (NCC requis)</option>
                        <option value="B2C">B2C — Entreprise à Particulier</option>
                        <option value="B2G">B2G — Entreprise à État / Collectivité</option>
                        <option value="B2F">B2F — Entreprise à International</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Secteur d'activité</label>
                    <input type="text" name="secteur" id="edit_secteur" class="form-control" placeholder="Ex: Alimentation, Électronique…">
                </div>
                <div class="form-group">
                    <label class="form-label">Téléphone</label>
                    <input type="text" name="telephone" id="edit_telephone" class="form-control" placeholder="Ex: +225 27 00 00 00">
                </div>
                <div class="form-group">
                    <label class="form-label">Adresse E-mail</label>
                    <input type="email" name="email" id="edit_email" class="form-control" placeholder="Ex: contact@fournisseur.com">
                </div>
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Adresse physique</label>
                    <input type="text" name="adresse" id="edit_adresse" class="form-control" placeholder="Ex: Zone 4, Marcory, Abidjan">
                </div>
                {{-- Informations fiscales & Comptables --}}
                <div style="grid-column:1/-1; padding:12px 14px; background:var(--bg3); border-radius:8px; border:1px solid var(--border); margin-top:4px;">
                    <div style="font-size:11px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
                        <i class="fas fa-file-invoice" style="margin-right:6px;"></i>Informations fiscales &amp; comptables
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="form-group" style="margin-bottom:0;" id="fedit_ncc_group">
                            <label class="form-label" id="fedit_ncc_label">NCC (Nº Compte Contribuable)</label>
                            <input type="text" name="ncc" id="edit_ncc" class="form-control" placeholder="Ex: 2169728N">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">RCCM (Registre de commerce)</label>
                            <input type="text" name="rccm" id="edit_rccm" class="form-control" placeholder="Ex: CI-ABJ-03-2021-B13-05438">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Régime d'imposition</label>
                            <select name="regime_imposition" id="edit_regime_imposition" class="form-control">
                                <option value="">— Non renseigné —</option>
                                <option value="TEE">TEE (Taxe sur l'Entreprise Employeuse)</option>
                                <option value="RS">RS (Régime Simplifié)</option>
                                <option value="RSI">RSI (Régime Simplifié d'Imposition)</option>
                                <option value="RNI">RNI (Régime Normal d'Imposition)</option>
                                <option value="Exonéré">Exonéré</option>
                            </select>
                        </div>
                        @if($comptaOuverte)
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Compte comptable général collective</label>
                            <select name="compte_comptable" id="edit_compte_comptable" class="form-control" required>
                                @foreach($comptes as $compte)
                                    <option value="{{ $compte->numero }}">
                                        {{ $compte->numero }} - {{ $compte->libelle }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        @endif
                        {{-- En lecture seule : changer le numéro d'un tiers déjà
                             déversé le rendrait introuvable chez Comptaflow, et
                             ses écritures futures retomberaient sur le collectif
                             tandis que les anciennes resteraient sur l'ancien
                             numéro. --}}
                        <div class="form-group" style="margin-bottom:0; grid-column: 1/-1;">
                            <label class="form-label">N° tiers auxiliaire</label>
                            <input type="text" id="edit_numero_tiers" class="form-control" disabled
                                   title="Attribué par le système ; il ne se modifie pas.">
                        </div>
                    </div>
                </div>
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:16px;">
                <button type="button" class="btn btn-outline" data-modal-close>Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer les modifications</button>
            </div>
        </form>
    </div>
</div>

@endsection

@section('scripts')
<script>

function switchVue(mode) {
    const btnLocal      = document.getElementById('btn-vue-local');
    const btnComptaflow = document.getElementById('btn-vue-comptaflow');
    const secLocal      = document.getElementById('section-local');
    const secComptaflow = document.getElementById('section-comptaflow');
    const btnNouveau    = document.getElementById('btn-nouveau-fournisseur');

    if (mode === 'local') {
        btnLocal.className = "btn btn-sm btn-primary";
        btnLocal.style.color = "#fff";
        btnLocal.style.background = "";
        btnComptaflow.className = "btn btn-sm btn-outline";
        btnComptaflow.style.color = "var(--text-2)";
        secLocal.style.display = "block";
        secComptaflow.style.display = "none";
        if(btnNouveau) btnNouveau.style.display = "inline-flex";
    } else {
        btnComptaflow.className = "btn btn-sm btn-primary";
        btnComptaflow.style.color = "#fff";
        btnComptaflow.style.background = "";
        btnLocal.className = "btn btn-sm btn-outline";
        btnLocal.style.color = "var(--text-2)";
        secLocal.style.display = "none";
        secComptaflow.style.display = "block";
        if(btnNouveau) btnNouveau.style.display = "none";
    }
}

// ── NCC show/hide selon type de facturation (Fournisseur) ──
function toggleNccFournisseur(prefix) {
    const ids = {
        fnew:  { sel: 'fnew_type_facturation',  grp: 'fnew_ncc_group',  lbl: 'fnew_ncc_label',  inp: 'fnew_ncc_input' },
        fedit: { sel: 'fedit_type_facturation', grp: 'fedit_ncc_group', lbl: 'fedit_ncc_label', inp: 'edit_ncc' }
    };
    const cfg    = ids[prefix];
    const select = document.getElementById(cfg.sel);
    const grp    = document.getElementById(cfg.grp);
    const lbl    = document.getElementById(cfg.lbl);
    const inp    = document.getElementById(cfg.inp);
    if (!select || !grp) return;
    const isB2B = select.value === 'B2B';
    grp.style.opacity = isB2B ? '1' : '0.45';
    if (lbl) {
        lbl.innerHTML = isB2B
            ? 'NCC (Nº Compte Contribuable) <span style="color:#E53E3E">*</span>'
            : 'NCC (Nº Compte Contribuable)';
    }
    if (inp) {
        inp.required = isB2B;
        if (!isB2B) inp.value = '';
        inp.placeholder = isB2B ? 'Obligatoire pour B2B — Ex: 2169728N' : 'Ex: 2169728N (facultatif)';
        inp.style.border = isB2B ? '1.5px solid #E53E3E' : '';
    }
}

// Boutons Modifier via data-* attributes (évite les erreurs de guillemets avec json_encode dans onclick)
document.querySelectorAll('.btn-modifier-fournisseur').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const data  = this.dataset;
        const modal = document.getElementById('modalModifierFournisseur');
        const form  = document.getElementById('formModifierFournisseur');

        if (!modal || !form) return;

        // URL d'action du formulaire
        form.action = data.action;

        // Remplir les champs
        document.getElementById('edit_nom').value             = data.nom      || '';
        document.getElementById('edit_secteur').value         = data.secteur  || '';
        document.getElementById('edit_telephone').value       = data.telephone || '';
        document.getElementById('edit_email').value           = data.email    || '';
        document.getElementById('edit_adresse').value         = data.adresse  || '';
        document.getElementById('edit_ncc').value             = data.ncc      || '';
        document.getElementById('edit_rccm').value            = data.rccm     || '';
        document.getElementById('edit_numero_tiers').value    = data.numero   || '';

        const selectType = document.getElementById('fedit_type_facturation');
        if (selectType) { selectType.value = data.type || ''; toggleNccFournisseur('fedit'); }

        const selectRegime = document.getElementById('edit_regime_imposition');
        if (selectRegime) selectRegime.value = data.regime || '';

        const selectCompte = document.getElementById('edit_compte_comptable');
        if (selectCompte) selectCompte.value = data.compte || '401000';

        // Champs COMPTAFLOW → lecture seule
        const lockFields = ['edit_nom', 'edit_numero_tiers'];
        const isCompta   = data.source === 'comptaflow';
        lockFields.forEach(function(id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.readOnly         = isCompta;
            el.disabled         = isCompta;
            el.style.background = isCompta ? '#e2e8f0' : '';
            el.style.cursor     = isCompta ? 'not-allowed' : '';
            el.title            = isCompta ? 'Ce champ provient de COMPTAFLOW et ne peut pas être modifié.' : '';
        });
        if (selectCompte) {
            selectCompte.disabled         = isCompta;
            selectCompte.style.background = isCompta ? '#e2e8f0' : '';
            selectCompte.style.cursor     = isCompta ? 'not-allowed' : '';
        }

        // Ouvrir le modal via la classe 'open' (système gabarit Selflow)
        modal.classList.add('open');
    });
});

function lancerSyncComptaflow() {
    const btn      = document.getElementById('btn-sync-fournisseurs');
    const feedback = document.getElementById('sync-feedback-fournisseurs');

    if (!btn) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> Synchronisation...';

    fetch("{{ route('admin.entreprise.comptaflow.sync_real') }}", {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": "{{ csrf_token() }}" }
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sync-alt"></i> Synchroniser les tiers';
        feedback.style.display = "block";
        if (data.success) {
            feedback.style.cssText = "background:#e6fffa; color:#319795; border:1px solid #b2f5ea; padding:12px; border-radius:6px; font-weight:600; font-size:13px;";
            feedback.innerText = data.message;
            setTimeout(() => { window.location.reload(); }, 1200);
        } else {
            feedback.style.cssText = "background:#fff5f5; color:#e53e3e; border:1px solid #fed7d7; padding:12px; border-radius:6px; font-weight:600; font-size:13px;";
            feedback.innerText = "Erreur : " + data.message;
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sync-alt"></i> Synchroniser les tiers';
        feedback.style.display = "block";
        feedback.style.cssText = "background:#fff5f5; color:#e53e3e; border:1px solid #fed7d7; padding:12px; border-radius:6px; font-weight:600; font-size:13px;";
        feedback.innerText = "Erreur de connexion.";
    });
}
</script>
@include('admin::composants.modal-import', ['type' => 'fournisseurs', 'label' => 'Fournisseurs', 'id' => 'modalImportFournisseurs'])
@endsection

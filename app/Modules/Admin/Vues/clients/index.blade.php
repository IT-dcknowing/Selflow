@extends('admin::gabarits.application')
@section('titre', 'Gestion clients')
@section('topbar_titre', 'Catalogue — Clients')

@section('contenu')
{{-- Comptabilité éteinte : aucun compte à l'écran. Le serveur pose
     411000 / 401000 (chantier 3.1, lot 46). --}}
@php $comptaOuverte = auth()->user()->estSuperAdmin() || (bool) auth()->user()->entreprise?->comptabiliteOuverte(); @endphp
<div class="page-header">
    <div>
        <h1><i class="fas fa-users"></i> Liste des clients</h1>
        <p>{{ $clients->total() + $clientsComptaflow->total() }} client(s) au total</p>
    </div>
    <div style="display:flex; gap:12px; align-items:center;">
        {{-- Switch bouton --}}
        <div class="vue-toggle" style="display:flex; background:var(--bg2); border:1px solid var(--border); border-radius:8px; overflow:hidden; padding:2px;">
            <button type="button" id="btn-vue-local" onclick="switchVue('local')" class="btn btn-sm btn-primary"
                style="padding:6px 12px; font-size:12px; font-weight:700; border-radius:6px; border:none; cursor:pointer;">
                <i class="fas fa-database"></i> Local ({{ $clients->total() }})
            </button>
            <button type="button" id="btn-vue-comptaflow" onclick="switchVue('comptaflow')" class="btn btn-sm btn-outline"
                style="padding:6px 12px; font-size:12px; font-weight:700; border-radius:6px; border:none; cursor:pointer; background:transparent; color:var(--text-2);">
                <i class="fas fa-sync"></i> COMPTAFLOW ({{ $clientsComptaflow->total() }})
            </button>
        </div>
        <button class="btn btn-outline" onclick="ouvrirImport('modalImportClients')" style="font-size:13px;">
            <i class="fas fa-file-import"></i> Importer CSV
        </button>
        <button id="btn-nouveau-client" class="btn btn-primary" data-modal-open="modalNouveauClient">
            <i class="fas fa-plus"></i> Ajouter un client
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
<form method="GET" action="{{ route('admin.clients.index') }}" style="margin-bottom:16px;" id="formRechercheClients">
    <div style="display:flex; gap:10px; align-items:center; max-width:450px;">
        <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Rechercher par nom, tél., email, n° tiers..." class="form-control" style="flex:1;"
               oninput="clearTimeout(window._rechercheClientsTimer); window._rechercheClientsTimer = setTimeout(() => document.getElementById('formRechercheClients').submit(), 500);">
        <button type="submit" class="btn btn-primary" style="white-space:nowrap;" title="Rechercher maintenant"><i class="fas fa-search"></i></button>
        @if(!empty($search))
            <a href="{{ route('admin.clients.index') }}" class="btn btn-outline" style="white-space:nowrap;"><i class="fas fa-times"></i></a>
        @endif
    </div>
</form>
<script>
    // Redonne le focus (curseur en fin de texte) après le rechargement déclenché par la saisie
    document.addEventListener('DOMContentLoaded', () => {
        const champ = document.querySelector('#formRechercheClients input[name="search"]');
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
                        <th>Nom &amp; Prénom</th>
                        <th>Type</th>
                        <th>N° tiers</th>
                        <th>NCC</th>
                        <th>RCCM</th>
                        @if($comptaOuverte)<th>Compte général</th>@endif
                        <th>Régime</th>
                        <th>Téléphone</th>
                        <th>E-mail</th>
                        <th>Adresse</th>
                        <th>Ventes</th>
                        <th>Date d'ajout</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($clients as $c)
                    <tr>
                        <td style="font-weight:600; color:var(--text);">{{ $c->nom }}</td>
                        <td>
                            @if($c->type_facturation)
                                @php $tf = $c->type_facturation; @endphp
                                <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;
                                    background:{{ $tf==='B2B' ? '#eff6ff' : ($tf==='B2C' ? '#f0fdf4' : ($tf==='B2G' ? '#fffbeb' : '#faf5ff')) }};
                                    color:{{ $tf==='B2B' ? '#1d4ed8' : ($tf==='B2C' ? '#15803d' : ($tf==='B2G' ? '#b45309' : '#7c3aed')) }};
                                    border:1px solid {{ $tf==='B2B' ? '#bfdbfe' : ($tf==='B2C' ? '#bbf7d0' : ($tf==='B2G' ? '#fde68a' : '#ddd6fe')) }};">{{ $tf }}</span>
                            @else
                                <span style="color:var(--text-3);font-size:12px;">—</span>
                            @endif
                        </td>
                        <td style="font-family: monospace; font-weight: 700; color: var(--primary);">
                            {{ $c->numero_tiers ?? '—' }}
                            @if(!empty($c->numero_original))
                                <div style="font-size: 10px; color: var(--text-3); font-weight: normal; margin-top: 3px; display: flex; align-items: center; gap: 4px;">
                                    <i class="fas fa-file-invoice" style="font-size: 9px; opacity: 0.7;"></i> Original: {{ $c->numero_original }}
                                </div>
                            @endif
                        </td>
                        <td>{{ $c->ncc ?? '—' }}</td>
                        <td>{{ $c->rccm ?? '—' }}</td>
                        @if($comptaOuverte)
                        <td style="font-family: monospace; font-weight: 700;">
                            {{ $c->compte_comptable ?? '411000' }}
                            @php
                                $compteObj = $comptes->firstWhere('numero', $c->compte_comptable ?? '411000');
                            @endphp
                            @if($compteObj && !empty($compteObj->numero_original))
                                <div style="font-size: 10px; color: var(--text-3); font-weight: normal; margin-top: 3px; display: flex; align-items: center; gap: 4px;">
                                    <i class="fas fa-file-invoice" style="font-size: 9px; opacity: 0.7;"></i> Original: {{ $compteObj->numero_original }}
                                </div>
                            @endif
                        </td>
                        @endif
                        <td>{{ $c->regime_imposition ?? '—' }}</td>
                        <td>{{ $c->telephone ?? '—' }}</td>
                        <td>{{ $c->email ?? '—' }}</td>
                        <td>{{ $c->adresse ?? '—' }}</td>
                        <td>
                            <span class="badge badge-purple">{{ $c->ventes_count }} vente(s)</span>
                        </td>
                        <td style="color:var(--text-3);">{{ $c->created_at->format('d/m/Y') }}</td>
                        <td style="display:flex; gap:6px; flex-wrap:wrap;">
                            <button class="btn btn-outline btn-sm btn-modifier-client"
                                {{-- L'adresse de la fiche, construite par le routeur : le numéro de
     ligne partait seul — `POST /admin/clients/52` — alors que les
     adresses portent l'`uuid`, et l'enregistrement tombait en 404
     (Not Found — introuvable). --}}
                                data-action="{{ route('admin.clients.modifier', $c) }}"
                                data-nom="{{ $c->nom }}"
                                data-type="{{ $c->type_facturation }}"
                                data-telephone="{{ $c->telephone }}"
                                data-email="{{ $c->email }}"
                                data-adresse="{{ $c->adresse }}"
                                data-ncc="{{ $c->ncc }}"
                                data-rccm="{{ $c->rccm }}"
                                data-regime="{{ $c->regime_imposition }}"
                                data-compte="{{ $c->compte_comptable ?? '411000' }}"
                                data-numero="{{ $c->numero_tiers }}"
                                data-source="{{ $c->source }}"
                                style="padding: 5px 10px;">
                                <i class="fas fa-edit"></i>
                            </button>
                            @if($c->ventes_count == 0)
                            <form method="POST" action="{{ route('admin.clients.supprimer', $c) }}" onsubmit="return confirm('Supprimer ce client ?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm" style="padding:5px 10px; background:#fff5f5; color:#e53e3e; border:1px solid #fed7d7;"><i class="fas fa-trash"></i></button>
                            </form>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="12" style="text-align:center; color:var(--text-3); padding:30px;">
                            Aucun client local enregistré pour le moment.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
            @if($clients->hasPages())
            <div style="padding: 16px;">
                {{ $clients->links() }}
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
                    Veuillez configurer votre clé de synchronisation COMPTAFLOW dans les paramètres de l'entreprise pour activer la liaison et importer les comptes clients.
                </p>
                <a href="{{ route('admin.entreprise.parametres') }}" class="btn btn-primary" style="margin-top: 15px; display: inline-flex;">
                    <i class="fas fa-cog"></i> Configurer la liaison
                </a>
            </div>
        </div>
    @else
        <div style="background: linear-gradient(135deg, #eff6ff, #f0fdf4); border: 1px solid #bfdbfe; border-radius: 12px; padding: 16px; margin-bottom: 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div>
                <p style="font-weight:700; color:#1e40af; margin-bottom:4px;"><i class="fas fa-link"></i> Clients synchronisés depuis COMPTAFLOW</p>
                <p style="font-size: 13px; color: #64748b; margin-bottom:0;">
                    Leurs informations COMPTAFLOW (nom, numéro de tiers, compte collectif) sont protégées en écriture, mais vous pouvez compléter leurs données Selflow manquantes ci-dessous.
                </p>
            </div>
            <button type="button" id="btn-sync-clients" onclick="lancerSyncComptaflow()" class="btn btn-primary" style="font-weight:700; white-space:nowrap;">
                <i class="fas fa-sync-alt" id="sync-icon-clients"></i> Synchroniser les tiers
            </button>
        </div>
        <div id="sync-feedback-clients" style="display:none; margin-bottom: 16px; padding: 12px; border-radius: 6px; font-weight: 600; font-size: 13px;"></div>

        <div class="card">
            <div class="table-wrap">
                @if($clientsComptaflow->isEmpty())
                <div style="padding:48px; text-align:center; color:var(--text-3);">
                    <i class="fas fa-users" style="font-size:48px; display:block; margin-bottom:12px; opacity:.2;"></i>
                    Aucun client COMPTAFLOW synchronisé. Cliquez sur <strong>Synchroniser les tiers</strong>.
                </div>
                @else
                <table>
                    <thead>
                        <tr>
                            <th>Nom &amp; Prénom</th>
                            <th>N° tiers</th>
                            <th>NCC</th>
                            <th>RCCM</th>
                            @if($comptaOuverte)<th>Compte général</th>@endif
                            <th>Régime</th>
                            <th>Téléphone</th>
                            <th>E-mail</th>
                            <th>Adresse</th>
                            <th>Ventes</th>
                            <th>Date d'ajout</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($clientsComptaflow as $c)
                        <tr>
                            <td style="font-weight:600; color:var(--text);">{{ $c->nom }}</td>
                            <td style="font-family: monospace; font-weight: 700; color: var(--primary);">
                                {{ $c->numero_tiers ?? '—' }}
                                @if(!empty($c->numero_original))
                                    <div style="font-size: 10px; color: var(--text-3); font-weight: normal; margin-top: 3px; display: flex; align-items: center; gap: 4px;">
                                        <i class="fas fa-file-invoice" style="font-size: 9px; opacity: 0.7;"></i> Original: {{ $c->numero_original }}
                                    </div>
                                @endif
                            </td>
                            <td>{{ $c->ncc ?? '—' }}</td>
                            <td>{{ $c->rccm ?? '—' }}</td>
                            @if($comptaOuverte)
                            <td style="font-family: monospace; font-weight: 700;">
                             {{ $c->compte_comptable ?? '411000' }}
                             @php
                                 $compteObj = $comptes->firstWhere('numero', $c->compte_comptable ?? '411000');
                             @endphp
                             @if($compteObj && !empty($compteObj->numero_original))
                                 <div style="font-size: 10px; color: var(--text-3); font-weight: normal; margin-top: 3px; display: flex; align-items: center; gap: 4px;">
                                     <i class="fas fa-file-invoice" style="font-size: 9px; opacity: 0.7;"></i> Original: {{ $compteObj->numero_original }}
                                 </div>
                             @endif
                         </td>
                            @endif
                            <td>{{ $c->regime_imposition ?? '—' }}</td>
                            <td>{{ $c->telephone ?? '—' }}</td>
                            <td>{{ $c->email ?? '—' }}</td>
                            <td>{{ $c->adresse ?? '—' }}</td>
                            <td>
                                <span class="badge badge-purple">{{ $c->ventes_count }} vente(s)</span>
                            </td>
                            <td style="color:var(--text-3);">{{ $c->created_at->format('d/m/Y') }}</td>
                            <td>
                                <button class="btn btn-outline btn-sm btn-modifier-client"
                                    {{-- L'adresse de la fiche, construite par le routeur : le numéro de
     ligne partait seul — `POST /admin/clients/52` — alors que les
     adresses portent l'`uuid`, et l'enregistrement tombait en 404
     (Not Found — introuvable). --}}
                                data-action="{{ route('admin.clients.modifier', $c) }}"
                                    data-nom="{{ $c->nom }}"
                                    data-telephone="{{ $c->telephone }}"
                                    data-email="{{ $c->email }}"
                                    data-adresse="{{ $c->adresse }}"
                                    data-ncc="{{ $c->ncc }}"
                                    data-rccm="{{ $c->rccm }}"
                                    data-regime="{{ $c->regime_imposition }}"
                                    data-compte="{{ $c->compte_comptable ?? '411000' }}"
                                    data-numero="{{ $c->numero_tiers }}"
                                    data-source="{{ $c->source }}"
                                    style="padding: 5px 10px;">
                                    <i class="fas fa-edit"></i> Modifier
                                </button>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @if($clientsComptaflow->hasPages())
                <div style="padding: 16px;">
                    {{ $clientsComptaflow->links() }}
                </div>
                @endif
                @endif
            </div>
        </div>
    @endif
</div>

{{-- Modal Nouveau Client --}}
<div class="modal-overlay" id="modalNouveauClient">
    <div class="modal" style="max-width:580px;">
        <div class="modal-header">
            <h3><i class="fas fa-plus"></i> Nouveau client</h3>
            <button class="modal-close" data-modal-close>✕</button>
        </div>
        <form method="POST" action="{{ route('admin.clients.creer') }}">
            @csrf
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Nom &amp; Prénom <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="nom" class="form-control" placeholder="Agnimel" required>
                </div>
                {{-- Type de facturation --}}
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Type de facturation <span style="color:#E53E3E">*</span></label>
                    <select name="type_facturation" id="new_type_facturation" class="form-control" onchange="toggleNccClient('new')">
                        <option value="">— Sélectionner le type —</option>
                        <option value="B2C">B2C — Entreprise à Particulier</option>
                        <option value="B2B">B2B — Entreprise à Entreprise (NCC requis)</option>
                        <option value="B2G">B2G — Entreprise à État / Collectivité</option>
                        <option value="B2F">B2F — Entreprise à International</option>
                    </select>
                    <small style="color:var(--text-3);font-size:11px;">Détermine si le NCC est obligatoire et le type de facture DGI.</small>
                </div>
                <div class="form-group" style="grid-column:1/-1;"></div>
                <div class="form-group"><label class="form-label">Téléphone</label><input type="text" name="telephone" class="form-control" placeholder="Ex: +225 07 00 00 00"></div>
                <div class="form-group"><label class="form-label">Adresse E-mail</label><input type="email" name="email" class="form-control" placeholder="Ex: koffi@mail.com"></div>
                <div class="form-group" style="grid-column:1/-1;"><label class="form-label">Adresse physique</label><input type="text" name="adresse" class="form-control" placeholder="Ex: Cocody, Abidjan"></div>
                {{-- Informations fiscales & Comptables --}}
                <div style="grid-column:1/-1; padding:12px 14px; background:var(--bg3); border-radius:8px; border:1px solid var(--border); margin-top:4px;">
                    <div style="font-size:11px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
                        <i class="fas fa-file-invoice" style="margin-right:6px;"></i>Informations fiscales &amp; comptables
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        {{-- NCC, RCCM et régime d'imposition sont les
                             identifiants d'une **entreprise**. Un particulier
                             n'en a aucun, et on les lui demandait quand même :
                             trois champs qu'il ne pouvait que laisser vides, sur
                             la fiche la plus fréquemment ouverte de la caisse.
                             `display:contents` les retire sans casser la grille
                             — le compte comptable, lui, reste : il vaut pour
                             tout le monde. --}}
                        <div id="new_bloc_fiscal" style="display:contents;">
                        <div class="form-group" style="margin-bottom:0;" id="new_ncc_group">
                            <label class="form-label" id="new_ncc_label">NCC (Nº Compte Contribuable)</label>
                            <input type="text" name="ncc" id="new_ncc_input" class="form-control" placeholder="Ex: 2302178R">
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
                        </div>
                        @if($comptaOuverte)
                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Compte comptable général collective</label>
                            <select name="compte_comptable" class="form-control" required>
                                @foreach($comptes as $compte)
                                    <option value="{{ $compte->numero }}" {{ $compte->numero == '411000' ? 'selected' : '' }}>
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
                                    (410001), selon la convention réglée dans
                                    <em>Paramètres &rsaquo; Numérotation des tiers</em>.
                                    Il ne se confond pas avec le compte de rattachement choisi ci-dessus :
                                    celui-ci regroupe tout le monde, le numéro de tiers désigne ce client-ci.
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:16px;">
                <button type="button" class="btn btn-outline" data-modal-close>Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer le client</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal Modifier Client --}}
<div class="modal-overlay" id="modalModifierClient">
    <div class="modal" style="max-width:580px;">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Modifier le client</h3>
            <button class="modal-close" data-modal-close>✕</button>
        </div>
        <form method="POST" id="formModifierClient">
            @csrf
            @method('PUT')
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Nom &amp; Prénom <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="nom" id="edit_nom" class="form-control" placeholder="Agnimel" required>
                </div>
                {{-- Type de facturation --}}
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label">Type de facturation <span style="color:#E53E3E">*</span></label>
                    <select name="type_facturation" id="edit_type_facturation" class="form-control" onchange="toggleNccClient('edit')">
                        <option value="">— Sélectionner le type —</option>
                        <option value="B2C">B2C — Entreprise à Particulier</option>
                        <option value="B2B">B2B — Entreprise à Entreprise (NCC requis)</option>
                        <option value="B2G">B2G — Entreprise à État / Collectivité</option>
                        <option value="B2F">B2F — Entreprise à International</option>
                    </select>
                </div>
                <div class="form-group" style="grid-column:1/-1;"></div>
                <div class="form-group"><label class="form-label">Téléphone</label><input type="text" name="telephone" id="edit_telephone" class="form-control" placeholder="Ex: +225 07 00 00 00"></div>
                <div class="form-group"><label class="form-label">Adresse E-mail</label><input type="email" name="email" id="edit_email" class="form-control" placeholder="Ex: koffi@mail.com"></div>
                <div class="form-group" style="grid-column:1/-1;"><label class="form-label">Adresse physique</label><input type="text" name="adresse" id="edit_adresse" class="form-control" placeholder="Ex: Cocody, Abidjan"></div>
                {{-- Informations fiscales & Comptables --}}
                <div style="grid-column:1/-1; padding:12px 14px; background:var(--bg3); border-radius:8px; border:1px solid var(--border); margin-top:4px;">
                    <div style="font-size:11px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
                        <i class="fas fa-file-invoice" style="margin-right:6px;"></i>Informations fiscales &amp; comptables
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        {{-- Comme au formulaire de création : un particulier n'a
                             ni NCC, ni RCCM, ni régime d'imposition. --}}
                        <div id="edit_bloc_fiscal" style="display:contents;">
                        <div class="form-group" style="margin-bottom:0;" id="edit_ncc_group">
                            <label class="form-label" id="edit_ncc_label">NCC (Nº Compte Contribuable)</label>
                            <input type="text" name="ncc" id="edit_ncc" class="form-control" placeholder="Ex: 2302178R">
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
    const btnLocal = document.getElementById('btn-vue-local');
    const btnComptaflow = document.getElementById('btn-vue-comptaflow');
    const secLocal = document.getElementById('section-local');
    const secComptaflow = document.getElementById('section-comptaflow');
    const btnNouveau = document.getElementById('btn-nouveau-client');

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

// ── Les identifiants fiscaux suivent le type de facturation ──
//
// Ils étaient seulement grisés à 45 % d'opacité pour un particulier : trois
// champs lisibles, cliquables, saisissables — et vides à jamais, puisqu'un
// particulier n'a ni NCC, ni RCCM, ni régime d'imposition. Sur la fiche la
// plus souvent ouverte de la caisse, c'était trois lignes de bruit à chaque
// nouveau client.
//
// Ils disparaissent pour un B2C, et reviennent pour tous les autres — B2B,
// B2G, B2F désignent tous une personne morale.
function toggleNccClient(prefix) {
    const select   = document.getElementById(prefix + '_type_facturation') || document.getElementById('edit_type_facturation') || document.getElementById('new_type_facturation');
    const bloc     = document.getElementById(prefix === 'new' ? 'new_bloc_fiscal' : 'edit_bloc_fiscal');
    const nccLabel = document.getElementById(prefix === 'new' ? 'new_ncc_label' : 'edit_ncc_label');
    const nccInput = document.getElementById(prefix === 'new' ? 'new_ncc_input' : 'edit_ncc');
    const nccGroup = document.getElementById(prefix === 'new' ? 'new_ncc_group' : 'edit_ncc_group');
    if (!select) return;

    const estB2C = select.value === 'B2C';
    const estB2B = select.value === 'B2B';

    if (bloc) bloc.style.display = estB2C ? 'none' : 'contents';

    // Ce qui n'est pas demandé n'est pas envoyé : un NCC saisi puis le type
    // basculé sur B2C partirait sinon avec le formulaire, et la pièce
    // suivante serait établie en B2B chez la DGI.
    if (estB2C) {
        ['ncc', 'rccm'].forEach(function (nom) {
            const champ = (bloc || document).querySelector('[name="' + nom + '"]');
            if (champ) champ.value = '';
        });
        const regime = (bloc || document).querySelector('[name="regime_imposition"]');
        if (regime) regime.value = '';
    }

    if (nccGroup) nccGroup.style.opacity = '1';
    if (nccLabel) {
        nccLabel.innerHTML = estB2B
            ? 'NCC (Nº Compte Contribuable) <span style="color:#E53E3E">*</span>'
            : 'NCC (Nº Compte Contribuable)';
    }
    if (nccInput) {
        // `required` sur un champ caché bloque l'envoi sans rien afficher : le
        // navigateur refuse de pointer un champ qu'il ne peut pas montrer.
        nccInput.required = estB2B && !estB2C;
        nccInput.placeholder = estB2B ? 'Obligatoire pour B2B — Ex: 2302178R' : 'Ex: 2302178R (facultatif)';
        nccInput.style.border = estB2B ? '1.5px solid #E53E3E' : '';
    }
}

// Au chargement : le formulaire de création ouvre sans type choisi, et les
// trois champs fiscaux s'affichaient alors comme si l'on facturait une
// entreprise.
document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('new_type_facturation')) toggleNccClient('new');
});

// Boutons Modifier via data-* attributes (pas de json_encode dans onclick)
document.querySelectorAll('.btn-modifier-client').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const data = this.dataset;
        const modal = document.getElementById('modalModifierClient');
        const form  = document.getElementById('formModifierClient');

        if (!modal || !form) return;

        // URL d'action du formulaire
        form.action = data.action;

        // Remplir les champs
        document.getElementById('edit_nom').value             = data.nom     || '';
        document.getElementById('edit_telephone').value       = data.telephone || '';
        document.getElementById('edit_email').value           = data.email   || '';
        document.getElementById('edit_adresse').value         = data.adresse || '';
        document.getElementById('edit_ncc').value             = data.ncc     || '';
        document.getElementById('edit_rccm').value            = data.rccm    || '';
        document.getElementById('edit_numero_tiers').value    = data.numero  || '';

        const selectType = document.getElementById('edit_type_facturation');
        if (selectType) { selectType.value = data.type || ''; toggleNccClient('edit'); }

        const selectRegime = document.getElementById('edit_regime_imposition');
        if (selectRegime) selectRegime.value = data.regime || '';

        const selectCompte = document.getElementById('edit_compte_comptable');
        if (selectCompte) selectCompte.value = data.compte || '411000';

        // Champs COMPTAFLOW → lecture seule
        const lockFields  = ['edit_nom', 'edit_compte_comptable', 'edit_numero_tiers'];
        const isCompta    = data.source === 'comptaflow';
        lockFields.forEach(function(id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.readOnly      = isCompta;
            el.disabled      = isCompta;
            el.style.background = isCompta ? '#e2e8f0' : '';
            el.style.cursor     = isCompta ? 'not-allowed' : '';
            el.title            = isCompta ? 'Ce champ provient de COMPTAFLOW et ne peut pas être modifié.' : '';
        });
        // Le select compte n'a pas readOnly → juste disabled
        if (selectCompte) {
            selectCompte.disabled     = isCompta;
            selectCompte.style.background = isCompta ? '#e2e8f0' : '';
            selectCompte.style.cursor     = isCompta ? 'not-allowed' : '';
        }

        // Ouvrir le modal via la classe 'open' (système gabarit)
        modal.classList.add('open');
    });
});

function lancerSyncComptaflow() {
    const btn      = document.getElementById('btn-sync-clients');
    const icon     = document.getElementById('sync-icon-clients');
    const feedback = document.getElementById('sync-feedback-clients');

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
@include('admin::composants.modal-import', ['type' => 'clients', 'label' => 'Clients', 'id' => 'modalImportClients'])
@endsection

@extends('admin::gabarits.application')
@section('titre', 'Personnels & Accès')
@section('topbar_titre', 'Points de vente — Personnels & Accès')

@section('styles')
<style>
    .matrix-table thead tr:first-child th {
        top: 0;
        z-index: 12;
        height: 38px;
    }
    .matrix-table thead tr:last-child th {
        top: 38px;
        z-index: 11;
    }
</style>
@endsection

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-users-gear"></i> Gestion des Personnels & Accès</h1>
        <p>Gérez les comptes des administrateurs et des caissiers, leurs contrats, notes et habilitations.</p>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
        <button class="btn btn-outline" onclick="ouvrirImport('modalImportPersonnel')" style="font-size:13px;">
            <i class="fas fa-file-import"></i> Importer CSV
        </button>
        <button class="btn btn-primary" data-modal-open="modalNouveauPersonnel">
            <i class="fas fa-user-plus"></i> Créer un accès
        </button>
    </div>
</div>

{{-- Onglets de navigation --}}
<div style="display:flex; gap:10px; margin-bottom:20px; border-bottom:1px solid var(--border); padding-bottom:10px;">
    <button class="btn @if(request('tab') !== 'habilitations') btn-primary @else btn-outline @endif" id="tab-btn-liste" onclick="changerOnglet('liste')">
        <i class="fas fa-list"></i> Liste du personnel
    </button>
    <button class="btn @if(request('tab') === 'habilitations') btn-primary @else btn-outline @endif" id="tab-btn-habs" onclick="changerOnglet('habilitations')">
        <i class="fas fa-shield-halved"></i> Grille des Habilitations
    </button>
</div>

{{-- CONTENU : ONGLET LISTE --}}
<div id="onglet-liste" class="card" style="display: @if(request('tab') !== 'habilitations') block @else none @endif;">
    <div class="card-header">
        <h2>Membres de l'entreprise</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Nom & Prénom</th>
                    <th>Rôle</th>
                    <th>Point de vente</th>
                    <th>Fonction</th>
                    <th>Contrat</th>
                    <th>Statut</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($personnels as $pers)
                <tr>
                    <td>
                        <div style="font-weight: 600;">{{ $pers->nom }} {{ $pers->prenom }}</div>
                        <div style="font-size: 11px; color: var(--text-3);">{{ $pers->email }}</div>
                    </td>
                    <td>
                        @if($pers->estSuperAdmin())
                            <span class="badge badge-purple">Super Admin</span>
                        @elseif($pers->estAdmin())
                            <span class="badge badge-info">Admin</span>
                        @else
                            <span class="badge badge-gray">Caissier</span>
                        @endif
                    </td>
                    <td>
                        @if($pers->pointDeVente)
                            <span style="font-weight: 500;"><i class="fas fa-store" style="color:var(--text-3); font-size:12px; margin-right:4px;"></i> {{ $pers->pointDeVente->nom }}</span>
                        @else
                            <span style="color:var(--text-3); font-style:italic;">Aucun (Siège)</span>
                        @endif
                    </td>
                    <td>{{ $pers->fonction ?? 'Non spécifiée' }}</td>
                    <td>
                        @if($pers->date_debut_contrat)
                            <div style="font-size: 11px;">Du {{ \Carbon\Carbon::parse($pers->date_debut_contrat)->format('d/m/Y') }}</div>
                            @if($pers->date_fin_contrat)
                                <div style="font-size: 11px; color: var(--text-3);">Au {{ \Carbon\Carbon::parse($pers->date_fin_contrat)->format('d/m/Y') }}</div>
                            @else
                                <div style="font-size: 11px; color: var(--success);">Indéterminé</div>
                            @endif
                        @else
                            <span style="color:var(--text-3); font-style:italic;">Non défini</span>
                        @endif
                    </td>
                    <td>
                        @if($pers->statut === 'actif')
                            <span class="badge badge-success"><i class="fas fa-circle-check"></i> Actif</span>
                        @else
                            <span class="badge badge-danger"><i class="fas fa-user-slash"></i> Bloqué</span>
                        @endif
                    </td>
                    <td style="text-align: right;">
                        <div style="display:inline-flex; gap:6px;">
                            <a href="{{ route('admin.personnel.details', $pers) }}" class="btn btn-outline btn-sm" title="Voir détails et modifier">
                                <i class="fas fa-user-pen"></i> Détails
                            </a>
                            @if($pers->id !== auth()->id())
                                <form method="POST" action="{{ route('admin.personnel.statut', $pers) }}">
                                    @csrf
                                    @if($pers->statut === 'actif')
                                        <button type="submit" class="btn btn-outline btn-sm" style="color:var(--danger); border-color:var(--danger);" title="Bloquer l'accès">
                                            <i class="fas fa-ban"></i> Bloquer
                                        </button>
                                    @else
                                        <button type="submit" class="btn btn-success btn-sm" title="Débloquer l'accès">
                                            <i class="fas fa-check"></i> Activer
                                        </button>
                                    @endif
                                </form>
                                <form method="POST" action="{{ route('admin.personnel.supprimer', $pers) }}" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce compte personnel ? Cette action est irréversible.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline btn-sm" style="color:var(--danger); border-color:rgba(239,68,68,0.2);" title="Supprimer définitivement">
                                        <i class="fas fa-trash-can"></i>
                                    </button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" style="text-align: center; color: var(--text-3); padding: 40px 0;">
                        <i class="fas fa-users" style="font-size: 32px; margin-bottom: 10px;"></i>
                        <div>Aucun membre du personnel enregistré.</div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- CONTENU : ONGLET GRILLE HABILITATIONS --}}
<div id="onglet-habilitations" class="card" style="display: @if(request('tab') === 'habilitations') block @else none @endif;">
    <div class="card-header">
        <h2>Matrice globale des habilitations (Accès Pages)</h2>
    </div>
    <div class="table-wrap">
        <table class="matrix-table">
            <thead>
                <tr>
                    <th rowspan="2" style="vertical-align: middle;">Personnel</th>
                    <th rowspan="2" style="vertical-align: middle;">Rôle</th>
                    <th colspan="2" style="text-align: center; border-bottom: 2px solid var(--primary); font-weight: bold; background: #f1f5f9;">Tableau de bord</th>
                    <th colspan="2" style="text-align: center; border-bottom: 2px solid var(--primary); font-weight: bold; background: #e2e8f0;">Ventes</th>
                    <th colspan="2" style="text-align: center; border-bottom: 2px solid var(--primary); font-weight: bold; background: #f1f5f9;">Achats</th>
                    <th colspan="1" style="text-align: center; border-bottom: 2px solid var(--primary); font-weight: bold; background: #e2e8f0;">Stock</th>
                    <th colspan="4" style="text-align: center; border-bottom: 2px solid var(--primary); font-weight: bold; background: #f1f5f9;">Comptabilité</th>
                    <th colspan="2" style="text-align: center; border-bottom: 2px solid var(--primary); font-weight: bold; background: #e2e8f0;">Points de vente</th>
                    <th colspan="1" style="text-align: center; border-bottom: 2px solid var(--primary); font-weight: bold; background: #f1f5f9;">Produits</th>
                    <th colspan="1" style="text-align: center; border-bottom: 2px solid var(--primary); font-weight: bold; background: #e2e8f0;">Tiers</th>
                    <th colspan="2" style="text-align: center; border-bottom: 2px solid var(--primary); font-weight: bold; background: #f1f5f9;">Production</th>
                </tr>
                <tr>
                    <th style="text-align: center;" title="Tableau de bord personnel">TDB Pers</th>
                    <th style="text-align: center;" title="Tableau de bord général">TDB Gén</th>
                    <th style="text-align: center;" title="Nouvelle vente">N.Vente</th>
                    <th style="text-align: center;" title="Factures Ventes">F.Vente</th>
                    <th style="text-align: center;" title="Nouvel achat">N.Achat</th>
                    <th style="text-align: center;" title="Factures Achats">F.Achat</th>
                    <th style="text-align: center;" title="Stock">Stock</th>
                    <th style="text-align: center;" title="Encaissements">Tréso Enc</th>
                    <th style="text-align: center;" title="Opérations & écriture globale">Ecriture Glob</th>
                    <th style="text-align: center;" title="Créances & règlements">Créances</th>
                    <th style="text-align: center;" title="Plan Comptable">Plan Compta</th>
                    <th style="text-align: center;" title="Points de vente">PDV</th>
                    <th style="text-align: center;" title="Personnel">Pers</th>
                    <th style="text-align: center;" title="Produits">Prod</th>
                    <th style="text-align: center;" title="Clients & Fournisseurs">Tiers</th>
                    <th style="text-align: center;" title="Fiches techniques / Recettes">Recettes</th>
                    <th style="text-align: center;" title="Ordres de production">Ordres</th>
                </tr>
            </thead>
            <tbody>
                @foreach($personnels as $pers)
                <tr>
                    <td>
                        <strong>{{ $pers->nom }} {{ $pers->prenom }}</strong>
                        <div style="font-size: 11px; color:var(--text-3);">{{ $pers->fonction ?? 'Non spécifiée' }}</div>
                    </td>
                    <td>
                        @if($pers->estAdmin() || $pers->estSuperAdmin())
                            <span class="badge badge-info btn-sm">Admin</span>
                        @else
                            <span class="badge badge-gray btn-sm">Caissier</span>
                        @endif
                    </td>
                    {{-- Checkboxes indicatifs --}}
                    @php
                        $pagesCheck = [
                            'tableau_de_bord_personnel', 'tableau_de_bord_general', 'nouvelle_vente', 'factures_vente',
                            'nouvel_achat', 'factures_achat', 'stock_articles',
                            'tresorerie_encaissements', 'comptabilite_globale', 'comptabilite_creances', 'comptabilite_plan_comptable', 'gestion_pdv', 'gestion_personnel',
                            'catalogue_produits', 'tiers_clients', 'production_recettes', 'production_ordres'
                        ];
                    @endphp
                    @foreach($pagesCheck as $pKey)
                    <td style="text-align: center; font-size: 16px;">
                        @if($pers->aHabilitation($pKey))
                            <i class="fas fa-square-check" style="color:var(--success);"></i>
                        @else
                            <i class="fas fa-square" style="color:var(--border);"></i>
                        @endif
                    </td>
                    @endforeach
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- MODAL : NOUVEAU PERSONNEL --}}
<div class="modal-overlay" id="modalNouveauPersonnel">
    <div class="modal" style="max-width: 780px;">
        <div class="modal-header">
            <h3><i class="fas fa-user-plus"></i> Créer un accès utilisateur</h3>
            <button class="modal-close" data-modal-close>✕</button>
        </div>
        <form method="POST" action="{{ route('admin.personnel.creer') }}">
            @csrf
            
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom: 14px;">
                <div class="form-group">
                    <label class="form-label">Nom <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="nom" class="form-control" placeholder="Agnimel" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Prénom <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="prenom" class="form-control" placeholder="Meledje Abraham" required>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom: 14px;">
                <div class="form-group">
                    <label class="form-label">Adresse Email <span style="color:var(--danger)">*</span></label>
                    <input type="email" name="email" class="form-control" placeholder="nom@entreprise.com" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Mot de passe provisoire <span style="color:var(--danger)">*</span></label>
                    <input type="password" name="password" class="form-control" placeholder="Min 6 caractères" required minlength="6">
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom: 14px;">
                <div class="form-group">
                    <label class="form-label">Rôle <span style="color:var(--danger)">*</span></label>
                    <select name="role" id="role-select" class="form-control" required onchange="verifierRoleChange()">
                        <option value="caissier" selected>Caissier (Accès restreint par défaut)</option>
                        <option value="responsable_pdv">Responsable Point de Vente (Accès restreint par défaut)</option>
                        <option value="admin_secondaire">Administrateur secondaire (Tous les droits)</option>
                        <option value="admin">Administrateur principal (Tous les droits)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Affectation Point de Vente</label>
                    <select name="point_de_vente_id" class="form-control">
                        <option value="">Aucun (Siège)</option>
                        @foreach($pointsDeVente as $pdv)
                            <option value="{{ $pdv->id }}">{{ $pdv->nom }} ({{ $pdv->ville }})</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom: 14px;">
                <div class="form-group">
                    <label class="form-label">Fonction / Poste</label>
                    <input type="text" name="fonction" class="form-control" placeholder="Ex: Caissier principal, Gérant...">
                </div>
                <div class="form-group" style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div>
                        <label class="form-label" style="font-size:10px;">Début Contrat</label>
                        <input type="date" name="date_debut_contrat" class="form-control">
                    </div>
                    <div>
                        <label class="form-label" style="font-size:10px;">Fin Contrat</label>
                        <input type="date" name="date_fin_contrat" class="form-control">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Notes internes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Suivi de recrutement, détails contractuels, notes RH..."></textarea>
            </div>

            {{-- Habilitations matrix checkboxes --}}
            <div id="habilitations-container" style="border:1px solid var(--border); border-radius:8px; padding:16px; background:#F8FAFC; margin-bottom:20px;">
                <div style="font-weight:700; font-size:13px; margin-bottom:12px; display:flex; justify-content:space-between; align-items:center;">
                    <span><i class="fas fa-shield-halved" style="color:var(--primary);"></i> Configuration des habilitations d'accès</span>
                    <label style="font-weight:500; font-size:12px; cursor:pointer; display:flex; align-items:center; gap:6px;">
                        <input type="checkbox" id="check-all-habs" onchange="toggleToutCocher(this)"> Tout cocher
                    </label>
                </div>
                
                <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:16px;" id="habs-checkboxes-grid">
                    <div>
                        <div style="font-weight:600; font-size:11px; text-transform:uppercase; color:var(--text-2); margin-bottom:6px; border-bottom:1px solid var(--border); padding-bottom:3px;">Tableau de bord</div>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="tableau_de_bord_personnel" checked> Tableau de bord personnel
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="tableau_de_bord_general"> Tableau de bord général
                        </label>
                    </div>

                    <div>
                        <div style="font-weight:600; font-size:11px; text-transform:uppercase; color:var(--text-2); margin-bottom:6px; border-bottom:1px solid var(--border); padding-bottom:3px;">Ventes</div>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="nouvelle_vente" checked> Nouvelle vente
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="factures_vente"> Factures vente
                        </label>
                        {{-- L'historique etait exige par la route et propose
                             nulle part : aucune entreprise ne pouvait
                             l'accorder a son personnel, et l'adresse restait
                             fermee a tous sauf a l'administrateur. Le superadmin
                             l'offrait pourtant depuis son propre ecran. --}}
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="historique_ventes"> Historique des ventes
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="production_recettes"> Recettes (Fiches techniques)
                        </label>
                    </div>

                    <div>
                        <div style="font-weight:600; font-size:11px; text-transform:uppercase; color:var(--text-2); margin-bottom:6px; border-bottom:1px solid var(--border); padding-bottom:3px;">Achats</div>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="nouvel_achat"> Nouvel achat
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="factures_achat"> Factures achat
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="historique_achats"> Historique des achats
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="production_ordres"> Ordres de production
                        </label>
                    </div>

                    <div>
                        <div style="font-weight:600; font-size:11px; text-transform:uppercase; color:var(--text-2); margin-bottom:6px; border-bottom:1px solid var(--border); padding-bottom:3px;">Stock</div>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="stock_articles"> Articles & stock
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="stock_mouvements"> Mouvements
                        </label>
                    </div>

                    <div>
                        <div style="font-weight:600; font-size:11px; text-transform:uppercase; color:var(--text-2); margin-bottom:6px; border-bottom:1px solid var(--border); padding-bottom:3px;">Comptabilité</div>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="tresorerie_encaissements"> Encaissements
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="tresorerie_decaissements"> Décaissements
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="tresorerie_journal"> Solde & journal
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="tresorerie_codes_journaux"> Codes Journaux
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="comptabilite_globale"> Opération & écriture globale
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="comptabilite_creances"> Créances & règlements
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="comptabilite_plan_comptable"> Plan Comptable
                        </label>
                    </div>

                    <div>
                        <div style="font-weight:600; font-size:11px; text-transform:uppercase; color:var(--text-2); margin-bottom:6px; border-bottom:1px solid var(--border); padding-bottom:3px;">Points de vente</div>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="gestion_pdv"> Points de vente
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="gestion_personnel"> Personnels & accès
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="gestion_habilitations"> Habilitations
                        </label>
                    </div>

                    <div>
                        <div style="font-weight:600; font-size:11px; text-transform:uppercase; color:var(--text-2); margin-bottom:6px; border-bottom:1px solid var(--border); padding-bottom:3px;">Produits</div>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="catalogue_produits"> Produits
                        </label>
                        </div>

                    <div>
                        <div style="font-weight:600; font-size:11px; text-transform:uppercase; color:var(--text-2); margin-bottom:6px; border-bottom:1px solid var(--border); padding-bottom:3px;">Tiers</div>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="tiers_clients"> Clients
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="tiers_fournisseurs"> Fournisseurs
                        </label>
                    </div>

                    <div>
                        <div style="font-weight:600; font-size:11px; text-transform:uppercase; color:var(--text-2); margin-bottom:6px; border-bottom:1px solid var(--border); padding-bottom:3px;">Rapports</div>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:5px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="rapports_analyse"> Analyse d'activité
                        </label>
                    </div>
                </div>
                
                <div id="admin-notice" style="display:none; color:var(--info); font-size:12.5px; font-weight:500;">
                    <i class="fas fa-circle-info"></i> Les administrateurs disposent de tous les privilèges et accès par défaut. Il n'est pas nécessaire de configurer individuellement leurs habilitations.
                </div>
            </div>

            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:8px;">
                <button type="button" class="btn btn-outline" data-modal-close>Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Enregistrer le personnel</button>
            </div>
        </form>
    </div>
</div>
@endsection

@section('scripts')
<script>
    function changerOnglet(onglet) {
        const divListe = document.getElementById('onglet-liste');
        const divHabs = document.getElementById('onglet-habilitations');
        const btnListe = document.getElementById('tab-btn-liste');
        const btnHabs = document.getElementById('tab-btn-habs');

        if (onglet === 'liste') {
            divListe.style.display = 'block';
            divHabs.style.display = 'none';
            btnListe.className = 'btn btn-primary';
            btnHabs.className = 'btn btn-outline';
        } else {
            divListe.style.display = 'none';
            divHabs.style.display = 'block';
            btnListe.className = 'btn btn-outline';
            btnHabs.className = 'btn btn-primary';
        }
    }

    function toggleToutCocher(source) {
        const checkboxes = document.querySelectorAll('#habs-checkboxes-grid input[type="checkbox"]');
        checkboxes.forEach(cb => cb.checked = source.checked);
    }

    function verifierRoleChange() {
        const role = document.getElementById('role-select').value;
        const grid = document.getElementById('habs-checkboxes-grid');
        const notice = document.getElementById('admin-notice');
        const selectAllLabel = document.querySelector('label[for="check-all-habs"]') || document.getElementById('check-all-habs').closest('label');

        if (role === 'admin' || role === 'admin_secondaire') {
            grid.style.opacity = '0.4';
            grid.style.pointerEvents = 'none';
            if (notice) {
                notice.style.display = 'block';
                notice.textContent = "Les administrateurs possèdent tous les accès d'office. La configuration manuelle est inutile.";
            }
            if (selectAllLabel) selectAllLabel.style.display = 'none';
        } else {
            grid.style.opacity = '1';
            grid.style.pointerEvents = 'auto';
            if (notice) notice.style.display = 'none';
            if (selectAllLabel) selectAllLabel.style.display = 'flex';
        }
    }

    // Appel initial au chargement
    document.addEventListener('DOMContentLoaded', () => {
        verifierRoleChange();
    });
</script>
@include('admin::composants.modal-import', ['type' => 'utilisateurs', 'label' => 'Personnel', 'id' => 'modalImportPersonnel'])
@endsection

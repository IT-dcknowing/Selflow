@extends('admin::gabarits.application')

@section('titre', 'Supervision - Habilitations & Accès')
@section('topbar_titre', 'Supervision — Habilitations & Accès')

@section('contenu')
<div class="page-header" style="margin-bottom: 20px;">
    <div>
        <h1><i class="fas fa-users-gear"></i> Habilitations &amp; Accès Utilisateurs</h1>
        <p>Gérez, attribuez et écrasez directement les rôles et permissions des utilisateurs connectés par entreprise</p>
    </div>
</div>

@if(session('succes'))
<div class="alert alert-success" style="display:flex;align-items:center;gap:10px;padding:14px 18px;background:#d1fae5;border:1px solid #6ee7b7;border-radius:10px;margin-bottom:20px;color:#065f46;font-weight:500;">
    <i class="fas fa-check-circle" style="font-size:16px;color:#10b981;"></i>
    {{ session('succes') }}
</div>
@endif

{{-- ── BLOC DE FILTRES RECHERCHE ── --}}
<div class="card" style="padding: 20px; margin-bottom: 20px; background: var(--bg2); border: 1px solid var(--border);">
    <form method="GET" action="{{ route('superadmin.utilisateurs') }}" id="formRechercheUtilisateurs" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 16px; align-items: flex-end;">
        <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label" style="font-weight: 600; margin-bottom: 6px; display: block; font-size: 12.5px;">Rechercher une entreprise</label>
            <input type="text" name="recherche_entreprise" value="{{ request('recherche_entreprise') }}" class="form-control" placeholder="Ex: Maison Dupont..." style="border-radius: 8px;"
                   title="Appuyez sur Entrée pour lancer la recherche" onkeydown="if (event.key === 'Enter') { event.preventDefault(); localStorage.setItem('selflow_user_active_focus', 'recherche_entreprise'); document.getElementById('formRechercheUtilisateurs').submit(); }">
        </div>
        
        <div class="form-group" style="margin-bottom: 0;">
            <label class="form-label" style="font-weight: 600; margin-bottom: 6px; display: block; font-size: 12.5px;">Rechercher un utilisateur</label>
            <input type="text" name="recherche_utilisateur" value="{{ request('recherche_utilisateur') }}" class="form-control" placeholder="Ex: Nom, prénom ou e-mail..." style="border-radius: 8px;"
                   title="Appuyez sur Entrée pour lancer la recherche" onkeydown="if (event.key === 'Enter') { event.preventDefault(); localStorage.setItem('selflow_user_active_focus', 'recherche_utilisateur'); document.getElementById('formRechercheUtilisateurs').submit(); }">
        </div>

        <div style="display: flex; gap: 10px;">
            <button type="submit" class="btn btn-primary" style="padding: 10px 16px; font-weight: 600; border-radius: 8px;">
                <i class="fas fa-search"></i> Filtrer
            </button>
            <a href="{{ route('superadmin.utilisateurs') }}" class="btn btn-outline" style="padding: 10px; border-radius: 8px; justify-content: center; display: inline-flex; align-items: center;">
                <i class="fas fa-rotate"></i>
            </a>
        </div>
    </form>
</div>

{{-- ── TABLEAU DES UTILISATEURS ── --}}
<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Entreprise</th>
                    <th>Utilisateur</th>
                    <th>Rôle &amp; Fonction</th>
                    <th>Statut</th>
                    <th>Habilitations actives</th>
                    <th style="text-align: center; width: 120px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($utilisateurs as $user)
                <tr>
                    <td>
                        @if($user->entreprise)
                            <div style="font-weight: 700; color: var(--text); font-size: 13.5px;">{{ $user->entreprise->nom }}</div>
                            <div style="font-size: 11px; color: var(--text-3);">ID Entreprise: #{{ $user->entreprise->id }}</div>
                        @else
                            <span class="badge" style="background: rgba(0,0,0,0.05); color: var(--text-3); font-weight: 700;">Aucune (SuperAdmin)</span>
                        @endif
                    </td>
                    <td>
                        <div style="font-weight: 600; color: var(--text-1);">{{ $user->nom }} {{ $user->prenom }}</div>
                        <div style="font-size: 12px; color: var(--text-3); font-family: monospace;">{{ $user->email }}</div>
                    </td>
                    <td>
                        <span class="badge badge-purple" style="font-weight: 700; font-size: 11px;">{{ ucfirst($user->role) }}</span>
                        @if($user->fonction)
                            <div style="font-size: 11px; color: var(--text-3); margin-top: 2px;">{{ $user->fonction }}</div>
                        @endif
                    </td>
                    <td>
                        @if($user->statut === 'actif')
                            <span class="badge badge-success" style="font-weight: 700; font-size: 10px; text-transform: uppercase;">Actif</span>
                        @elseif($user->statut === 'suspendu')
                            <span class="badge badge-danger" style="font-weight: 700; font-size: 10px; text-transform: uppercase;">Suspendu</span>
                        @else
                            <span class="badge badge-warning" style="font-weight: 700; font-size: 10px; text-transform: uppercase;">{{ $user->statut }}</span>
                        @endif
                    </td>
                    <td>
                        @if($user->estSuperAdmin() || $user->estAdmin())
                            <span style="font-size: 12px; color: var(--success); font-weight: 600;">
                                <i class="fas fa-shield-halved"></i> Tous les accès (Admin)
                            </span>
                        @else
                            @php
                                $habs = $user->habilitations ?? [];
                            @endphp
                            @if(count($habs) > 0)
                                <div style="display: flex; flex-wrap: wrap; gap: 4px; max-width: 350px;">
                                    @foreach($habs as $h)
                                        <span style="font-size: 10px; background: rgba(108, 92, 231, 0.08); border: 1px solid rgba(108, 92, 231, 0.15); color: #6c5ce7; padding: 1px 6px; border-radius: 4px; font-weight: 500;">
                                            {{ str_replace('_', ' ', $h) }}
                                        </span>
                                    @endforeach
                                </div>
                            @else
                                <span style="font-size: 11px; color: var(--text-3);">Aucune habilitation spécifique</span>
                            @endif
                        @endif
                    </td>
                    <td style="text-align: center;">
                        <button type="button" class="btn btn-primary btn-sm" onclick="ouvrirModalPermissions({{ json_encode($user) }})" style="padding: 4px 8px; font-size: 12px;">
                            <i class="fas fa-user-shield"></i> Modifier
                        </button>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" style="text-align: center; color: var(--text-3); padding: 30px 0;">
                        Aucun utilisateur trouvé correspondant à vos critères de recherche.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        @if($utilisateurs->hasPages())
        <div style="padding: 16px;">
            {{ $utilisateurs->appends(request()->query())->links() }}
        </div>
        @endif
    </div>
</div>

{{-- ── MODAL MODIFICATION DES PERMISSIONS ── --}}
<div class="modal-backdrop" id="modalPermissionsBackdrop" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div class="modal" style="width:100%; max-width:680px; background:#fff; border-radius:12px; box-shadow:0 15px 35px rgba(0,0,0,0.2); overflow:hidden; animation: slideUp 0.25s ease;">
        <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--border); background:var(--bg2);">
            <h3 style="margin:0; font-size:16px; font-weight:700; color:var(--text);"><i class="fas fa-user-shield" style="color:var(--primary); margin-right:8px;"></i>Modifier les accès de l'utilisateur</h3>
            <button type="button" onclick="fermerModalPermissions()" style="background:none; border:none; font-size:18px; cursor:pointer; color:var(--text-3);">&times;</button>
        </div>
        
        <form method="POST" id="formModifierPermissions">
            @csrf
            @method('PUT')
            
            <div style="padding: 20px; max-height: 480px; overflow-y: auto;">
                
                {{-- Informations utilisateur --}}
                <div style="padding:12px 16px; background:var(--bg3); border-radius:8px; border:1px solid var(--border); margin-bottom:20px; display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div>
                        <div style="font-size:11px; color:var(--text-3); text-transform:uppercase;">Utilisateur</div>
                        <div id="modal_user_name" style="font-weight:700; color:var(--text);">—</div>
                        <div id="modal_user_email" style="font-size:12px; color:var(--text-3); font-family:monospace;">—</div>
                    </div>
                    <div>
                        <div style="font-size:11px; color:var(--text-3); text-transform:uppercase;">Entreprise</div>
                        <div id="modal_company_name" style="font-weight:700; color:var(--text);">—</div>
                    </div>
                </div>

                {{-- Role & Statut --}}
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
                    <div class="form-group">
                        <label class="form-label" style="font-weight:600;">Rôle de l'utilisateur</label>
                        <select name="role" id="modal_role" class="form-control" required style="border-radius:8px;">
                            <option value="superadmin">SuperAdmin</option>
                            <option value="admin">Administrateur</option>
                            <option value="admin_secondaire">Administrateur Secondaire</option>
                            <option value="responsable_pdv">Responsable Point de Vente</option>
                            <option value="caissier">Caissier / Vendeur</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-weight:600;">Statut du compte</label>
                        <select name="statut" id="modal_statut" class="form-control" required style="border-radius:8px;">
                            <option value="actif">Actif</option>
                            <option value="suspendu">Suspendu / Bloqué</option>
                            <option value="inactif">Inactif</option>
                        </select>
                    </div>
                </div>

                {{-- Nouveau mot de passe --}}
                <div class="form-group" style="margin-bottom:20px;">
                    <label class="form-label" style="font-weight:600;">Nouveau mot de passe (laisser vide si inchangé)</label>
                    <input type="password" name="password" id="modal_password" class="form-control" placeholder="Entrez un nouveau mot de passe pour le mettre à jour" style="border-radius:8px;">
                </div>

                {{-- Habilitations --}}
                <div style="font-weight:700; font-size:13px; color:var(--text); text-transform:uppercase; margin-bottom:12px; border-bottom:1px solid var(--border); padding-bottom:6px;">
                    <i class="fas fa-key" style="margin-right:6px;"></i>Écraser les habilitations spécifiques
                </div>
                
                @php
                    $groupesHabilitations = [
                        'Tableaux de bord' => [
                            'tableau_de_bord_personnel' => 'TDB Personnel',
                            'tableau_de_bord_general' => 'TDB Général'
                        ],
                        'Ventes' => [
                            'nouvelle_vente' => 'Nouvelle vente',
                            'factures_vente' => 'Factures de vente',
                            'historique_ventes' => 'Historique des ventes'
                        ],
                        'Achats' => [
                            'nouvel_achat' => 'Nouvel achat',
                            'factures_achat' => 'Factures d\'achat',
                            'historique_achats' => 'Historique des achats'
                        ],
                        'Stock' => [
                            'stock_articles' => 'Articles & stock',
                            'stock_mouvements' => 'Mouvements de stock'
                        ],
                        'Trésorerie' => [
                            'tresorerie_encaissements' => 'Encaissements',
                            'tresorerie_decaissements' => 'Décaissements',
                            'tresorerie_journal' => 'Solde & journal',
                            'tresorerie_codes_journaux' => 'Codes Journaux'
                        ],
                        'Comptabilité' => [
                            'comptabilite_globale' => 'Opération & écriture globale',
                            'comptabilite_creances' => 'Créances & règlements',
                            'comptabilite_plan_comptable' => 'Plan Comptable'
                        ],
                        'Points de vente' => [
                            'gestion_pdv' => 'Points de vente',
                            'gestion_personnel' => 'Personnels & accès',
                            'gestion_habilitations' => 'Habilitations'
                        ],
                        'Produits' => [
                            'catalogue_produits' => 'Catalogue Produits'
                        ],
                        'Tiers' => [
                            'tiers_clients' => 'Clients',
                            'tiers_fournisseurs' => 'Fournisseurs'
                        ],
                        'Rapports' => [
                            'rapports_analyse' => 'Analyse d\'activité'
                        ]
                    ];
                @endphp

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    @foreach($groupesHabilitations as $groupe => $habsList)
                    <div style="background:var(--bg3); padding:10px 14px; border-radius:8px; border:1px solid var(--border);">
                        <div style="font-weight:700; font-size:11px; text-transform:uppercase; color:var(--primary); margin-bottom:8px; border-bottom:1px solid rgba(0,43,92,0.1); padding-bottom:3px;">
                            {{ $groupe }}
                        </div>
                        @foreach($habsList as $permKey => $permLabel)
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; margin-bottom:6px; cursor:pointer;">
                            <input type="checkbox" name="habilitations[]" value="{{ $permKey }}" class="perm-checkbox" id="perm_{{ $permKey }}">
                            <span>{{ $permLabel }}</span>
                        </label>
                        @endforeach
                    </div>
                    @endforeach
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; padding:16px 20px; border-top:1px solid var(--border); background:var(--bg2);">
                <button type="button" class="btn btn-outline" onclick="fermerModalPermissions()">Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Écraser les droits</button>
            </div>
        </form>
    </div>
</div>

<script>
    function ouvrirModalPermissions(user) {
        document.getElementById('modal_user_name').innerText = user.nom + ' ' + (user.prenom || '');
        document.getElementById('modal_user_email').innerText = user.email;
        document.getElementById('modal_company_name').innerText = user.entreprise ? user.entreprise.nom : 'SuperAdmin';
        
        // Rôle et Statut
        document.getElementById('modal_role').value = user.role;
        document.getElementById('modal_statut').value = user.statut;
        
        // Décocher toutes les permissions d'abord
        const checkboxes = document.querySelectorAll('.perm-checkbox');
        checkboxes.forEach(cb => cb.checked = false);
        
        // Cocher les permissions actives de l'utilisateur
        const activePermissions = user.habilitations || [];
        activePermissions.forEach(perm => {
            const cb = document.getElementById('perm_' + perm);
            if (cb) cb.checked = true;
        });

        // Configurer l'action du formulaire dynamiquement
        const form = document.getElementById('formModifierPermissions');
        form.action = "{{ url('/superadmin/utilisateurs') }}/" + user.id;

        // Afficher le modal
        const backdrop = document.getElementById('modalPermissionsBackdrop');
        backdrop.style.display = 'flex';
    }

    function fermerModalPermissions() {
        document.getElementById('modalPermissionsBackdrop').style.display = 'none';
    }

    // Fermer le modal en cliquant à l'extérieur
    window.addEventListener('click', function(e) {
        const backdrop = document.getElementById('modalPermissionsBackdrop');
        if (e.target === backdrop) {
            fermerModalPermissions();
        }
    });

    document.addEventListener('DOMContentLoaded', () => {
        const activeFocusKey = localStorage.getItem('selflow_user_active_focus');
        if (activeFocusKey) {
            const input = document.querySelector(`#formRechercheUtilisateurs input[name="${activeFocusKey}"]`);
            if (input && input.value) {
                input.focus();
                input.setSelectionRange(input.value.length, input.value.length);
            }
            localStorage.removeItem('selflow_user_active_focus');
        }
    });
</script>
@endsection

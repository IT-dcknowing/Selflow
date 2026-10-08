@extends('admin::gabarits.application')

@section('titre', 'Supervision - Entreprises')
@section('topbar_titre', 'Supervision — Entreprises')

@section('contenu')
<div class="page-header" style="margin-bottom: 20px;">
    <div>
        <h1><i class="fas fa-building"></i> Liste des Entreprises</h1>
        <p>Gérez les comptes clients de la plateforme, les quotas et les modules autorisés</p>
    </div>
    <a href="{{ route('superadmin.entreprises.creer') }}" class="btn btn-primary">
        <i class="fas fa-plus"></i> Nouvelle Entreprise
    </a>
</div>

@if(session('succes'))
<div class="alert alert-success" style="display:flex;align-items:center;gap:10px;padding:14px 18px;background:#d1fae5;border:1px solid #6ee7b7;border-radius:10px;margin-bottom:20px;color:#065f46;font-weight:500;">
    <i class="fas fa-check-circle" style="font-size:16px;color:#10b981;"></i>
    {{ session('succes') }}
</div>
@endif

<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Nom de l'entreprise</th>
                    <th>Contact</th>
                    <th>Secteur d'activité</th>
                    <th> NCC / RCCM </th>
                    <th>Abonnement &amp; Quotas</th>
                    <th>Modules activés</th>
                    <th>Compte FNE</th>
                    <th style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($entreprises as $ent)
                <tr>
                    <td>
                        <div style="font-weight: 700; color: var(--text); font-size: 14px; display: flex; align-items: center; gap: 8px;">
                            {{ $ent->nom }}
                            @if($ent->statut === 'bloque')
                                <span class="badge" style="font-size: 10px; padding: 2px 6px; background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; font-weight: 700; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px;"><i class="fas fa-lock"></i> Suspendue</span>
                            @endif
                        </div>
                    </td>
                    <td>
                        <div style="font-size: 12px; color: var(--text-2); display: flex; flex-direction: column; gap: 4px;">
                            <span><i class="fas fa-envelope" style="color: var(--primary); width: 14px;"></i> {{ $ent->email ?? '—' }}</span>
                            @if($ent->telephone)
                                <span><i class="fas fa-phone" style="color: var(--primary); width: 14px;"></i> {{ $ent->telephone }}</span>
                            @endif
                        </div>
                    </td>
                    <td>
                        @php
                            $secteurs = $ent->secteur_activite ?? [];
                            if (is_string($secteurs)) {
                                $secteurs = [$secteurs];
                            }
                        @endphp
                        @forelse($secteurs as $secteur)
                            <span class="badge badge-blue" style="margin-bottom: 2px; display: inline-block; font-size: 11px; font-weight:600; background: rgba(26,115,232,0.08); color: var(--primary); border: 1px solid rgba(0,43,92,0.12); padding: 4px 8px; border-radius: 4px;">
                                {{ $secteur }}
                            </span>
                        @empty
                            <span style="font-size: 11.5px; color: var(--text-3); font-style: italic;">Non renseigné</span>
                        @endforelse
                    </td>
                    <td>
                        <span style="font-size: 11.5px; color: var(--text-2);">
                            NCC: <strong>{{ $ent->ncc ?? '—' }}</strong><br>
                            RCCM: {{ $ent->rccm ?? '—' }}
                        </span>
                    </td>
                    <td>
                        <span class="badge badge-purple" style="margin-bottom: 4px; display: inline-block;">{{ $ent->plan_abonnement }}</span>
                        <div style="font-size: 11px; color: var(--text-3);">
                            Points de vente max : <strong>{{ $ent->quota_points_de_vente }}</strong>
                        </div>
                    </td>
                    <td>
                        @php
                            $count = $ent->modules_actifs ? count($ent->modules_actifs) : 0;
                        @endphp
                        @if($count > 0)
                            <button type="button" class="btn btn-outline btn-sm" onclick="toggleModulesList(event, 'modules-{{ $ent->id }}')" style="font-size: 11px; padding: 4px 8px; font-weight:600; display: inline-flex; align-items: center; gap: 4px; border-color: var(--border);">
                                <i class="fas fa-cubes"></i> <span>{{ $count }} modules</span> <i class="fas fa-chevron-down" style="font-size: 8px; transition: transform 0.2s;"></i>
                            </button>
                            <div id="modules-{{ $ent->id }}" style="display: none; margin-top: 6px; flex-wrap: wrap; gap: 4px; max-width: 280px; animation: fadeIn 0.2s ease;">
                                @foreach($ent->modules_actifs as $mod)
                                    <span style="font-size: 9.5px; background: rgba(0,43,92,0.06); border: 1px solid rgba(0,43,92,0.12); color: var(--primary); padding: 2px 6px; border-radius: 4px; font-weight: 600; text-transform: uppercase;">
                                        {{ $mod }}
                                    </span>
                                @endforeach
                            </div>
                        @else
                            <span style="font-size: 11px; color: var(--text-3);">Aucun module actif</span>
                        @endif
                    </td>
                    <td>
                        {{-- L'état de la connexion FNE, le même que l'entreprise lit
                             dans ses paramètres (propriétaire, 08/10/2026). --}}
                        @php
                            $etatFne = $ent->etatConnexionFne();
                            $teinte = ['vert' => '#047857', 'bleu' => '#1d4ed8', 'orange' => '#b45309', 'gris' => '#64748b'][$etatFne['couleur']];
                            $accesFourni = $ent->fneCredential && (filled($ent->fneCredential->ncc_associe) || $ent->fneCredential->acces_fourni_at);
                        @endphp
                        <div style="font-size: 11.5px; font-weight: 700; color: {{ $teinte }};" data-etat-fne="{{ $etatFne['code'] }}">{{ $etatFne['libelle'] }}</div>
                        @if($accesFourni)
                            <button type="button" class="btn btn-outline btn-sm" style="margin-top: 4px; padding: 3px 7px; font-size: 11px;"
                                    onclick="ouvrirAccesFne(@js($ent->getRouteKey()), @js($ent->nom))">
                                <i class="fas fa-key"></i> Voir les accès FNE
                            </button>
                        @endif
                    </td>
                    <td style="text-align: center;">
                        <div style="display: flex; gap: 4px; justify-content: center; align-items: center; flex-wrap: wrap;">
                            <button type="button" class="btn btn-outline btn-sm" onclick="ouvrirModalDetails({{ json_encode($ent) }}, {{ $ent->pointsDeVente()->count() }}, {{ $ent->utilisateurs()->count() }}, {{ $ent->produits()->count() }})" style="padding: 4px 8px; font-size: 12px;">
                                <i class="fas fa-eye"></i> Détails
                            </button>
                            <a href="{{ route('superadmin.entreprises.modifier', $ent) }}" class="btn btn-primary btn-sm" style="padding: 4px 8px; font-size: 12px;">
                                <i class="fas fa-pen-to-square"></i> Configurer
                            </a>
                            <form method="POST" action="{{ route('superadmin.entreprises.toggle_status', $ent) }}" style="margin: 0; display: inline;">
                                @csrf
                                <button type="submit" class="btn btn-sm" style="padding: 4px 8px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; background: {{ $ent->statut === 'bloque' ? '#d1fae5' : '#fef3c7' }}; color: {{ $ent->statut === 'bloque' ? '#065f46' : '#92400e' }}; border: 1px solid {{ $ent->statut === 'bloque' ? '#34d399' : '#fbbf24' }}; border-radius: 6px; cursor: pointer;">
                                    @if($ent->statut === 'bloque')
                                        <i class="fas fa-unlock"></i> Activer
                                    @else
                                        <i class="fas fa-lock"></i> Bloquer
                                    @endif
                                </button>
                            </form>
                            <form method="POST" action="{{ route('superadmin.entreprises.supprimer', $ent) }}" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer définitivement l\'entreprise « {{ $ent->nom }} » et toutes ses données associées (utilisateurs, pdvs) ?')" style="margin: 0; display: inline;">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm" style="padding: 4px 8px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; border-radius: 6px; cursor: pointer;">
                                    <i class="fas fa-trash"></i> Supprimer
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" style="text-align: center; color: var(--text-3); padding: 30px 0;">
                        Aucune entreprise n'est enregistrée pour le moment.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        @if($entreprises->hasPages())
        <div style="padding: 16px;">
            {{ $entreprises->links() }}
        </div>
        @endif
    </div>
</div>

<!-- Modal de Détails -->
<div id="modalDetailsEntreprise" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center; backdrop-filter: blur(4px); animation: fadeIn 0.2s ease;">
    <div style="background: var(--bg); border: 1px solid var(--border); width: 100%; max-width: 600px; border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.3); overflow: hidden; animation: slideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);">
        <!-- Header -->
        <div style="padding: 20px 24px; background: var(--bg2); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-building" style="color: var(--primary); font-size: 20px;"></i>
                <span style="font-weight: 800; font-size: 16px; color: var(--text);" id="modalNom">Nom de l'entreprise</span>
            </div>
            <button type="button" onclick="fermerModalDetails()" style="background: none; border: none; font-size: 20px; color: var(--text-3); cursor: pointer; display: flex; align-items: center; justify-content: center; outline: none; transition: color 0.15s;" onmouseover="this.style.color='var(--danger)'" onmouseout="this.style.color='var(--text-3)'">
                <i class="fas fa-xmark"></i>
            </button>
        </div>
        
        <!-- Body -->
        <div style="padding: 24px; max-height: 480px; overflow-y: auto; display: flex; flex-direction: column; gap: 20px; font-size: 13px;">
            <!-- Secteurs -->
            <div>
                <div style="font-weight: 700; color: var(--text-2); text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; margin-bottom: 8px;">Secteurs d'activité</div>
                <div id="modalSecteurs" style="display: flex; gap: 6px;"></div>
            </div>

            <!-- Contacts -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div>
                    <div style="font-weight: 700; color: var(--text-2); text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; margin-bottom: 4px;">Email</div>
                    <span id="modalEmail" style="color: var(--text); font-weight: 600;">—</span>
                </div>
                <div>
                    <div style="font-weight: 700; color: var(--text-2); text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; margin-bottom: 4px;">Téléphone</div>
                    <span id="modalTelephone" style="color: var(--text); font-weight: 600;">—</span>
                </div>
            </div>

            <!-- Fiscal -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div>
                    <div style="font-weight: 700; color: var(--text-2); text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; margin-bottom: 4px;">NCC</div>
                    <span id="modalNCC" style="color: var(--text); font-weight: 600;">—</span>
                </div>
                <div>
                    <div style="font-weight: 700; color: var(--text-2); text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; margin-bottom: 4px;">RCCM</div>
                    <span id="modalRCCM" style="color: var(--text); font-weight: 600;">—</span>
                </div>
            </div>

            <!-- Abonnement -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div>
                    <div style="font-weight: 700; color: var(--text-2); text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; margin-bottom: 4px;">Plan d'abonnement</div>
                    <span class="badge badge-purple" id="modalPlan" style="font-size: 11.5px; padding: 4px 8px;">Starter</span>
                </div>
                <div>
                    <div style="font-weight: 700; color: var(--text-2); text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; margin-bottom: 4px;">Quota Points de vente</div>
                    <span id="modalQuota" style="color: var(--text); font-weight: 600;">5 max</span>
                </div>
            </div>

            <!-- Statistiques / Compteurs -->
            <div>
                <div style="font-weight: 700; color: var(--text-2); text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; margin-bottom: 8px;">Métriques actuelles</div>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                    <div style="padding: 10px; background: var(--bg2); border: 1px solid var(--border); border-radius: 8px; text-align: center;">
                        <div style="font-size: 18px; font-weight: 800; color: var(--primary);" id="modalCountPdv">0</div>
                        <div style="font-size: 10px; color: var(--text-3); text-transform: uppercase; font-weight:600; margin-top:4px;">Points de Vente</div>
                    </div>
                    <div style="padding: 10px; background: var(--bg2); border: 1px solid var(--border); border-radius: 8px; text-align: center;">
                        <div style="font-size: 18px; font-weight: 800; color: var(--primary);" id="modalCountUsers">0</div>
                        <div style="font-size: 10px; color: var(--text-3); text-transform: uppercase; font-weight:600; margin-top:4px;">Utilisateurs</div>
                    </div>
                    <div style="padding: 10px; background: var(--bg2); border: 1px solid var(--border); border-radius: 8px; text-align: center;">
                        <div style="font-size: 18px; font-weight: 800; color: var(--primary);" id="modalCountProducts">0</div>
                        <div style="font-size: 10px; color: var(--text-3); text-transform: uppercase; font-weight:600; margin-top:4px;">Catalogue</div>
                    </div>
                </div>
            </div>

            <!-- Modules activés -->
            <div>
                <div style="font-weight: 700; color: var(--text-2); text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; margin-bottom: 8px;">Modules Actifs</div>
                <div id="modalModules" style="display: flex; flex-wrap: wrap; gap: 6px;"></div>
            </div>
            
            <!-- Future / Other details dynamic placeholder -->
            <div id="modalDetailsFuturs" style="padding: 12px; background: rgba(16,185,129,0.06); border: 1px dashed rgba(16,185,129,0.3); border-radius: 8px; color: #10b981; display: none; align-items: center; gap: 8px; font-size: 11.5px;">
                <i class="fas fa-circle-info"></i>
                <span id="modalTxtFuturs">Champs futurs et extensions</span>
            </div>
        </div>
        
        <!-- Footer -->
        <div style="padding: 16px 24px; background: var(--bg2); border-top: 1px solid var(--border); display: flex; justify-content: flex-end;">
            <button type="button" class="btn btn-outline" onclick="fermerModalDetails()" style="padding: 10px 20px; border-radius: 8px;">Fermer</button>
        </div>
    </div>
</div>

<script>
function toggleModulesList(event, id) {
    event.stopPropagation();
    const list = document.getElementById(id);
    const btn = event.currentTarget;
    const isHidden = list.style.display === 'none';
    
    // Fermer toutes les autres listes d'abord pour garder propre
    document.querySelectorAll('[id^="modules-"]').forEach(el => {
        if(el.id !== id) {
            el.style.display = 'none';
            const otherBtn = document.querySelector(`[onclick*="${el.id}"]`);
            if(otherBtn) {
                const chev = otherBtn.querySelector('.fa-chevron-down');
                if(chev) chev.style.transform = 'rotate(0deg)';
            }
        }
    });
    
    if (isHidden) {
        list.style.display = 'flex';
        btn.querySelector('.fa-chevron-down').style.transform = 'rotate(180deg)';
    } else {
        list.style.display = 'none';
        btn.querySelector('.fa-chevron-down').style.transform = 'rotate(0deg)';
    }
}

function ouvrirModalDetails(ent, countPdv, countUsers, countProducts) {
    document.getElementById('modalNom').textContent = ent.nom;
    document.getElementById('modalEmail').textContent = ent.email || '— Non renseigné —';
    document.getElementById('modalTelephone').textContent = ent.telephone || '— Non renseigné —';
    document.getElementById('modalNCC').textContent = ent.ncc || '— Non renseigné —';
    document.getElementById('modalRCCM').textContent = ent.rccm || '— Non renseigné —';
    document.getElementById('modalPlan').textContent = ent.plan_abonnement;
    document.getElementById('modalQuota').textContent = ent.quota_points_de_vente + ' max';
    
    document.getElementById('modalCountPdv').textContent = countPdv;
    document.getElementById('modalCountUsers').textContent = countUsers;
    document.getElementById('modalCountProducts').textContent = countProducts;
    
    // Secteurs
    const sectorsDiv = document.getElementById('modalSecteurs');
    sectorsDiv.innerHTML = '';
    const secteurs = Array.isArray(ent.secteur_activite) ? ent.secteur_activite : [ent.secteur_activite || 'Commercial'];
    secteurs.forEach(s => {
        const badge = document.createElement('span');
        badge.className = s === 'Commercial' ? 'badge badge-info' : (s === 'Industriel' ? 'badge badge-purple' : 'badge badge-success');
        badge.style.display = 'inline-block';
        badge.style.marginBottom = '0';
        badge.innerHTML = `<i class="fas ${s === 'Commercial' ? 'fa-tags' : (s === 'Industriel' ? 'fa-industry' : 'fa-person-digging')}"></i> ${s}`;
        sectorsDiv.appendChild(badge);
    });

    // Modules
    const modulesDiv = document.getElementById('modalModules');
    modulesDiv.innerHTML = '';
    const modules = ent.modules_actifs || [];
    if(modules.length > 0) {
        modules.forEach(m => {
            const badge = document.createElement('span');
            badge.style.cssText = "font-size: 10px; background: rgba(0,43,92,0.06); border: 1px solid rgba(0,43,92,0.12); color: var(--primary); padding: 4px 8px; border-radius: 4px; font-weight: 600; text-transform: uppercase;";
            badge.textContent = m;
            modulesDiv.appendChild(badge);
        });
    } else {
        modulesDiv.innerHTML = '<span style="color: var(--text-3);">Aucun module activé</span>';
    }
    
    // Dynamic placeholder for any future/unmapped variables
    const futureDiv = document.getElementById('modalDetailsFuturs');
    const extraFields = [];
    Object.keys(ent).forEach(key => {
        const standardKeys = ['id', 'nom', 'email', 'telephone', 'ncc', 'rccm', 'compte_contribuable', 'quota_points_de_vente', 'plan_abonnement', 'secteur_activite', 'modules_actifs', 'created_at', 'updated_at'];
        if (!standardKeys.includes(key) && ent[key] !== null && ent[key] !== undefined) {
            extraFields.push(`<strong>${key}</strong>: ${JSON.stringify(ent[key])}`);
        }
    });
    if (extraFields.length > 0) {
        futureDiv.style.display = 'flex';
        document.getElementById('modalTxtFuturs').innerHTML = `<i class="fas fa-circle-info"></i> Informations additionnelles : ${extraFields.join(', ')}`;
    } else {
        futureDiv.style.display = 'none';
    }

    document.getElementById('modalDetailsEntreprise').style.display = 'flex';
}

function fermerModalDetails() {
    document.getElementById('modalDetailsEntreprise').style.display = 'none';
}

// Close modal when clicking outside
window.addEventListener('click', function(e) {
    const modal = document.getElementById('modalDetailsEntreprise');
    if (e.target === modal) {
        fermerModalDetails();
    }
});
</script>

<style>
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
@keyframes slideUp {
    from { transform: translateY(20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
</style>

{{-- Les accès à l'espace FNE : derrière le mot de passe du superadministrateur,
     servis en JSON à la demande, jamais écrits dans la page. --}}
<div id="modalAccesFne" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:10000; align-items:center; justify-content:center;">
    <div style="background:var(--surface); border-radius:12px; padding:22px; width:min(420px, 92vw); box-shadow:0 20px 50px rgba(0,0,0,.2);">
        <div style="font-weight:800; font-size:15px; margin-bottom:4px;"><i class="fas fa-key" style="color:var(--primary);"></i> Accès FNE</div>
        <div id="accesFneNom" style="font-size:12.5px; color:var(--text-3); margin-bottom:14px;"></div>
        <div id="accesFneEtapeCode">
            <label class="form-label" for="accesFneCode">Votre mot de passe de superadministrateur</label>
            <input type="password" id="accesFneCode" class="form-control" autocomplete="current-password">
            <div id="accesFneErreur" style="display:none; color:#dc2626; font-size:12px; margin-top:6px;"></div>
            <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:14px;">
                <button type="button" class="btn btn-outline btn-sm" onclick="fermerAccesFne()">Fermer</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="revelerAccesFne()">Afficher</button>
            </div>
        </div>
        <div id="accesFneEtapeVue" style="display:none;">
            <div style="display:grid; grid-template-columns:auto 1fr; gap:8px 12px; font-size:13px;">
                <span style="color:var(--text-3);">NCC</span><strong id="accesFneNcc" style="font-family:monospace;"></strong>
                <span style="color:var(--text-3);">Mot de passe</span><strong id="accesFneMdp" style="font-family:monospace; word-break:break-all;"></strong>
                <span style="color:var(--text-3);">Fourni le</span><span id="accesFneDate"></span>
            </div>
            <div style="display:flex; justify-content:flex-end; margin-top:14px;">
                <button type="button" class="btn btn-primary btn-sm" onclick="fermerAccesFne()">Fermer</button>
            </div>
        </div>
    </div>
</div>
<script>
const URL_ACCES_FNE = "{{ route('superadmin.fne.voir_acces', ':id') }}";
let accesFneEntreprise = null;

function ouvrirAccesFne(id, nom) {
    accesFneEntreprise = id;
    document.getElementById('accesFneNom').textContent = nom;
    document.getElementById('accesFneCode').value = '';
    document.getElementById('accesFneErreur').style.display = 'none';
    document.getElementById('accesFneEtapeCode').style.display = 'block';
    document.getElementById('accesFneEtapeVue').style.display = 'none';
    document.getElementById('modalAccesFne').style.display = 'flex';
    document.getElementById('accesFneCode').focus();
}

function fermerAccesFne() {
    // Rien ne reste dans la page une fois la fenêtre fermée.
    ['accesFneNcc', 'accesFneMdp', 'accesFneDate'].forEach(id => document.getElementById(id).textContent = '');
    document.getElementById('modalAccesFne').style.display = 'none';
    accesFneEntreprise = null;
}

async function revelerAccesFne() {
    const code = document.getElementById('accesFneCode').value;
    const erreur = document.getElementById('accesFneErreur');
    if (!code) return;
    try {
        const res = await fetch(URL_ACCES_FNE.replace(':id', accesFneEntreprise), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                       'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify({ mot_de_passe: code })
        });
        let data = {};
        try { data = await res.json(); } catch (e) { data = { message: res.status === 419 ? 'Session expirée : rechargez la page.' : 'Réponse illisible du serveur (' + res.status + ').' }; }
        if (!data.success) {
            erreur.textContent = data.message || 'Accès refusé.';
            erreur.style.display = 'block';
            return;
        }
        document.getElementById('accesFneNcc').textContent = data.ncc || '—';
        document.getElementById('accesFneMdp').textContent = data.mot_de_passe || 'non fourni';
        document.getElementById('accesFneDate').textContent = data.fourni_le || '—';
        document.getElementById('accesFneEtapeCode').style.display = 'none';
        document.getElementById('accesFneEtapeVue').style.display = 'block';
    } catch (e) {
        erreur.textContent = 'Erreur réseau. Réessayez.';
        erreur.style.display = 'block';
    }
}
document.getElementById('accesFneCode')?.addEventListener('keydown', e => { if (e.key === 'Enter') revelerAccesFne(); });
</script>

@endsection

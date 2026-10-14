@extends('admin::gabarits.application')
@section('titre', 'Plan Comptable')
@section('topbar_titre', 'Comptabilité — Plan Comptable')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-book-open"></i> Plan Comptable SYSCOHADA</h1>
        <p>Gérez le plan de comptes généraux de l'entreprise.</p>
    </div>
    <div style="display:flex; gap:12px; align-items:center;">
        {{-- Switch bouton --}}
        <div class="vue-toggle" style="display:flex; background:var(--bg2); border:1px solid var(--border); border-radius:8px; overflow:hidden; padding:2px;">
            <button type="button" id="btn-vue-local" onclick="switchVue('local')" class="btn btn-sm btn-primary"
                style="padding:6px 12px; font-size:12px; font-weight:700; border-radius:6px; border:none; cursor:pointer;">
                <i class="fas fa-database"></i> Local
            </button>
            <button type="button" id="btn-vue-comptaflow" onclick="switchVue('comptaflow')" class="btn btn-sm btn-outline"
                style="padding:6px 12px; font-size:12px; font-weight:700; border-radius:6px; border:none; cursor:pointer; background:transparent; color:var(--text-2);">
                <i class="fas fa-sync"></i> COMPTAFLOW
            </button>
        </div>
        {{-- L'entreprise ne recevait que les comptes marqués « communs » — une
             quarantaine. Les 1 256 comptes de l'acte uniforme restaient un
             dictionnaire, servant à nommer sans jamais entrer dans le plan de
             personne : le compte manquait dès qu'on sortait de l'ordinaire, et
             il fallait le créer à la main en devinant son numéro. --}}
        <form method="POST" action="{{ route('admin.comptabilite.poser_plan_defaut') }}" style="margin:0;">
            @csrf
            <button type="submit" class="btn btn-outline" title="Ajoute les comptes manquants du référentiel SYSCOHADA, sans toucher aux vôtres">
                <i class="fas fa-wand-magic-sparkles"></i> Plan comptable par défaut
            </button>
        </form>
        <button id="btn-nouveau-compte" class="btn btn-primary" data-modal-open="modalNouveauCompte">
            <i class="fas fa-plus"></i> Nouveau compte
        </button>
    </div>
</div>

<div id="section-local">
    {{-- Filtres --}}
    <div class="card" style="margin-bottom: 20px; padding: 16px;">
        <form method="GET" action="{{ route('admin.comptabilite.plan_comptable') }}" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)) 100px; gap: 14px; align-items: flex-end;">
            <div class="form-group" style="margin: 0;">
                <label class="form-label">Classe (Chiffre 1-9)</label>
                <input type="text" name="classe" class="form-control" value="{{ request('classe') }}" placeholder="Ex: 4">
            </div>
            <div class="form-group" style="margin: 0;">
                <label class="form-label">N° de compte</label>
                <input type="text" name="numero" class="form-control" value="{{ request('numero') }}" placeholder="Ex: 411">
            </div>
            <div class="form-group" style="margin: 0;">
                <label class="form-label">Libellé</label>
                <input type="text" name="libelle" class="form-control" value="{{ request('libelle') }}" placeholder="Ex: Client">
            </div>
            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-primary" style="padding: 10px; justify-content: center; flex: 1;"><i class="fas fa-search"></i></button>
                <a href="{{ route('admin.comptabilite.plan_comptable') }}" class="btn btn-outline" style="padding: 10px; justify-content: center;"><i class="fas fa-rotate"></i></a>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width: 150px;">Numéro</th>
                        <th>Libellé du compte</th>
                        <th style="width: 120px;">Classe</th>
                        <th style="width: 140px;">Origine</th>
                        <th style="width: 110px; text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($comptes as $compte)
                    <tr>
                        <td style="font-family: monospace; font-weight: 700; color: var(--primary); font-size: 14px;">
                            {{ $compte->numero }}
                            @if(!empty($compte->numero_original))
                                <div style="font-size: 10px; color: var(--text-3); font-weight: normal; margin-top: 3px; display: flex; align-items: center; gap: 4px;">
                                    <i class="fas fa-file-invoice" style="font-size: 9px; opacity: 0.7;"></i> Original: {{ $compte->numero_original }}
                                </div>
                            @endif
                        </td>
                        <td style="font-weight: 600;">
                            {{ $compte->libelle }}
                        </td>
                        <td>
                            <span class="badge badge-purple">Classe {{ substr($compte->numero, 0, 1) }}</span>
                        </td>
                        <td>
                            @if(isset($compte->source) && $compte->source === 'comptaflow')
                                <span style="display:inline-flex; align-items:center; gap:5px; background:#dbeafe; color:#1d4ed8; padding:3px 9px; border-radius:20px; font-size:11px; font-weight:700;">
                                    <i class="fas fa-sync" style="font-size:9px;"></i> COMPTAFLOW
                                </span>
                            @else
                                <span style="display:inline-flex; align-items:center; gap:5px; background:#f1f5f9; color:#64748b; padding:3px 9px; border-radius:20px; font-size:11px; font-weight:700;">
                                    <i class="fas fa-database" style="font-size:9px;"></i> {{ $compte->estCreeParLEntreprise() ? 'Créé par vous' : 'Local' }}
                                </span>
                            @endif
                        </td>
                        <td style="text-align:right; white-space:nowrap;">
                            {{-- Les comptes que l'entreprise a créés se modifient et se
                                 suppriment ; le plan SYSCOHADA livré reste la référence. --}}
                            @if($compte->estCreeParLEntreprise())
                                <button type="button" class="btn btn-outline btn-sm" title="Modifier" style="padding:4px 8px;"
                                        data-modifier-compte="{{ route('admin.comptabilite.modifier_compte_comptable', $compte) }}"
                                        data-numero="{{ $compte->numero }}" data-libelle="{{ $compte->libelle }}">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <form method="POST" action="{{ route('admin.comptabilite.supprimer_compte_comptable', $compte) }}" style="display:inline; margin:0;"
                                      onsubmit="return confirm('Supprimer le compte {{ $compte->numero }} ? Il ne doit être employé nulle part.');">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-outline btn-sm" title="Supprimer" style="padding:4px 8px; color:var(--danger); border-color:#fecaca;">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--text-3); padding: 32px;">
                            Aucun compte trouvé correspondant aux critères.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
            @if($comptes->hasPages())
            <div style="padding: 16px;">
                {{ $comptes->appends(request()->query())->links() }}
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
                    Veuillez configurer votre clé de synchronisation COMPTAFLOW dans les paramètres de l'entreprise pour activer la liaison et le déversement automatique du plan de comptes.
                </p>
                <a href="{{ route('admin.entreprise.parametres') }}" class="btn btn-primary" style="margin-top: 15px; display: inline-flex;">
                    <i class="fas fa-cog"></i> Configurer la liaison
                </a>
            </div>
        </div>
    @else
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
            <p style="color: var(--text-2); font-size: 14px; margin-bottom: 0;">
                Plan comptable importé depuis <strong>COMPTAFLOW</strong>. 
                @if($comptesComptaflow->total() > 0)
                    <span style="color: var(--success); font-weight: 600;">{{ $comptesComptaflow->total() }} comptes disponibles.</span>
                @endif
            </p>
            <button type="button" id="btn-sync-plan" onclick="lancerSyncPlan()" class="btn btn-primary" style="font-weight: 700;">
                <i class="fas fa-sync-alt" id="sync-icon-plan"></i> Synchroniser avec COMPTAFLOW
            </button>
        </div>

        <div id="sync-feedback-plan" style="display:none; margin-bottom: 20px; padding: 12px; border-radius: 6px; font-weight: 600; font-size: 13px;"></div>

        @if($comptesComptaflow->isEmpty())
        <div class="card" style="padding: 48px; text-align: center; color: var(--text-3);">
            <i class="fas fa-book-open" style="font-size: 48px; display:block; margin-bottom: 12px; opacity: 0.2;"></i>
            Aucun compte COMPTAFLOW synchronisé.<br>Cliquez sur le bouton ci-dessus pour importer le plan comptable de COMPTAFLOW.
        </div>
        @else
        <div class="card">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 150px;">Numéro</th>
                            <th>Libellé du compte</th>
                            <th style="width: 120px;">Classe</th>
                            <th style="width: 110px;">Origine</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($comptesComptaflow as $compte)
                        <tr>
                            <td style="font-family: monospace; font-weight: 700; color: var(--primary); font-size: 14px;">
                                 {{ $compte->numero }}
                                 @if(!empty($compte->numero_original))
                                     <div style="font-size: 10px; color: var(--text-3); font-weight: normal; margin-top: 3px; display: flex; align-items: center; gap: 4px;">
                                         <i class="fas fa-file-invoice" style="font-size: 9px; opacity: 0.7;"></i> Original: {{ $compte->numero_original }}
                                     </div>
                                 @endif
                             </td>
                            <td style="font-weight: 600;">{{ $compte->libelle }}</td>
                            <td>
                                <span class="badge badge-purple">Classe {{ substr($compte->numero, 0, 1) }}</span>
                            </td>
                            <td>
                                <span class="badge badge-blue"><i class="fas fa-cloud"></i> COMPTAFLOW</span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @if($comptesComptaflow->hasPages())
                <div style="padding: 16px;">
                    {{ $comptesComptaflow->appends(request()->query())->links() }}
                </div>
                @endif
            </div>
        </div>
        @endif
    @endif
</div>

{{-- Modal Modifier un compte créé par l'entreprise --}}
<div class="modal-overlay" id="modalModifierCompte">
    <div class="modal" style="max-width: 480px;">
        <div class="modal-header">
            <h3><i class="fas fa-pen"></i> Modifier le compte</h3>
            <button class="modal-close" data-modal-close>✕</button>
        </div>
        <form method="POST" id="formModifierCompte">
            @csrf @method('PUT')
            <div class="form-group">
                <label class="form-label">Numéro de compte <span style="color: var(--danger)">*</span></label>
                <input type="text" name="numero" id="modifierNumero" class="form-control" required>
                <small style="color:var(--text-3); font-size:11px;">Il ne change pas si le compte est déjà employé (écritures, journal, article…).</small>
            </div>
            <div class="form-group">
                <label class="form-label">Libellé du compte <span style="color: var(--danger)">*</span></label>
                <input type="text" name="libelle" id="modifierLibelle" class="form-control" required>
            </div>
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" class="btn btn-outline" data-modal-close>Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('click', function (e) {
    var bouton = e.target.closest('[data-modifier-compte]');
    if (!bouton) return;
    document.getElementById('formModifierCompte').action = bouton.dataset.modifierCompte;
    document.getElementById('modifierNumero').value = bouton.dataset.numero;
    document.getElementById('modifierLibelle').value = bouton.dataset.libelle;
    document.getElementById('modalModifierCompte').classList.add('open');
});
</script>

{{-- Modal Nouveau Compte --}}
<div class="modal-overlay" id="modalNouveauCompte">
    <div class="modal" style="max-width: 480px;">
        <div class="modal-header">
            <h3><i class="fas fa-plus"></i> Nouveau compte général</h3>
            <button class="modal-close" data-modal-close>✕</button>
        </div>
        <form method="POST" action="{{ route('admin.comptabilite.creer_compte_comptable') }}">
            @csrf
            <div class="form-group">
                <label class="form-label">Numéro de compte <span style="color: var(--danger)">*</span></label>
                <input type="text" name="numero" class="form-control" placeholder="Ex: 411000" required>
            </div>
            <div class="form-group">
                <label class="form-label">Libellé du compte <span style="color: var(--danger)">*</span></label>
                <input type="text" name="libelle" class="form-control" placeholder="Ex: Client - Ventes locales" required>
            </div>
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" class="btn btn-outline" data-modal-close>Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer le compte</button>
            </div>
        </form>
    </div>
</div>

<script>
function switchVue(mode) {
    const btnLocal = document.getElementById('btn-vue-local');
    const btnComptaflow = document.getElementById('btn-vue-comptaflow');
    const secLocal = document.getElementById('section-local');
    const secComptaflow = document.getElementById('section-comptaflow');
    const btnNouveau = document.getElementById('btn-nouveau-compte');

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

function lancerSyncPlan() {
    const btn = document.getElementById('btn-sync-plan');
    const icon = document.getElementById('sync-icon-plan');
    const feedback = document.getElementById('sync-feedback-plan');

    btn.disabled = true;
    if (icon) icon.classList.add('fa-spin');
    btn.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> Synchronisation en cours...';

    fetch("{{ route('admin.entreprise.comptaflow.sync_real') }}", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": "{{ csrf_token() }}"
        }
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        if (icon) icon.classList.remove('fa-spin');
        btn.innerHTML = '<i class="fas fa-sync-alt"></i> Synchroniser avec COMPTAFLOW';

        feedback.style.display = "block";
        if (data.success) {
            feedback.style.background = "#e6fffa";
            feedback.style.color = "#319795";
            feedback.style.border = "1px solid #b2f5ea";
            feedback.innerText = data.message;
            setTimeout(() => { window.location.reload(); }, 1200);
        } else {
            feedback.style.background = "#fff5f5";
            feedback.style.color = "#e53e3e";
            feedback.style.border = "1px solid #fed7d7";
            feedback.innerText = "Erreur : " + data.message;
        }
    })
    .catch(err => {
        btn.disabled = false;
        if (icon) icon.classList.remove('fa-spin');
        btn.innerHTML = '<i class="fas fa-sync-alt"></i> Synchroniser avec COMPTAFLOW';
        feedback.style.display = "block";
        feedback.style.background = "#fff5f5";
        feedback.style.color = "#e53e3e";
        feedback.style.border = "1px solid #fed7d7";
        feedback.innerText = "Erreur de connexion.";
    });
}
</script>
@endsection

@extends('admin::gabarits.application')

@section('titre', 'Supervision - Créer une entreprise')
@section('topbar_titre', 'Supervision — Créer')

@section('contenu')
<div class="page-header" style="margin-bottom: 20px;">
    <div>
        <h1><i class="fas fa-plus"></i> Nouvelle Entreprise</h1>
        <p>Inscrire une nouvelle structure sur la plateforme</p>
    </div>
    <a href="{{ route('superadmin.entreprises') }}" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour
    </a>
</div>

<form method="POST" action="{{ route('superadmin.entreprises.creer.enregistrer') }}">
    @csrf
    <div style="display:grid; grid-template-columns: 1.6fr 1fr; gap:20px; align-items:start;">
        
        {{-- Fiche d'informations générales --}}
        <div style="display:flex; flex-direction:column; gap:20px;">
            <div class="card" style="padding: 24px;">
                <div style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-info-circle" style="color:var(--primary);"></i> Informations générales
                </div>
                <div style="display:flex; flex-direction:column; gap:14px;">
                    <div class="form-group">
                        <label class="form-label">Nom de l'entreprise <span style="color:var(--danger)">*</span></label>
                        <input type="text" name="nom" class="form-control" placeholder="Ex: Maison Dupont SARL" required value="{{ old('nom') }}">
                        @error('nom') <small style="color:var(--danger)">{{ $message }}</small> @enderror
                    </div>

                    {{-- La forme juridique a quitté la création, comme à
                         l'inscription : elle se renseigne depuis les paramètres,
                         avec le reste de l'identité légale (15/10/2026). --}}

                    {{-- Informations Gérant --}}
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div class="form-group">
                            <label class="form-label">Nom du Gérant / Représentant</label>
                            <input type="text" name="gerant_nom" class="form-control" placeholder="Ex: Dupont" value="{{ old('gerant_nom') }}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Prénom du Gérant</label>
                            <input type="text" name="gerant_prenom" class="form-control" placeholder="Ex: Jean" value="{{ old('gerant_prenom') }}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fonction du Gérant</label>
                        <input type="text" name="gerant_fonction" class="form-control" placeholder="Ex: Directeur Général / Gérant" value="{{ old('gerant_fonction') }}">
                    </div>
                    
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div class="form-group">
                            <label class="form-label">E-mail du responsable <span style="color:var(--danger)">*</span></label>
                            <input type="email" name="email" class="form-control" required
                                placeholder="Ex: contact@entreprise.ci" value="{{ old('email') }}">
                            <small style="font-size:11px;color:var(--text-3);">C'est avec cette adresse qu'il se connectera.</small>
                            @error('email') <small style="color:var(--danger);display:block;">{{ $message }}</small> @enderror
                        </div>
                        <div class="form-group">
                            <label class="form-label">Téléphone</label>
                            <input type="text" name="telephone" class="form-control" placeholder="Ex: +225 07 00 00 00" value="{{ old('telephone') }}">
                        </div>
                    </div>

                    {{-- Le mot de passe du responsable.

                         Il manquait. Cet écran créait une entreprise **et
                         personne pour s'y connecter** : aucun compte n'était
                         enregistré, et il fallait ensuite en fabriquer un à la
                         main. --}}
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div class="form-group">
                            <label class="form-label">Mot de passe du responsable <span style="color:var(--danger)">*</span></label>
                            <div class="champ-mdp">
                                <input type="password" name="gerant_password" id="gerant_password" class="form-control" required
                                    minlength="8" placeholder="Min. 8 caractères" autocomplete="new-password">
                                <button type="button" class="voir-mdp" onclick="basculerLesMotsDePasse(this)" aria-label="Voir les mots de passe" title="Voir">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            @error('gerant_password') <small style="color:var(--danger);display:block;">{{ $message }}</small> @enderror
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirmation <span style="color:var(--danger)">*</span></label>
                            <div class="champ-mdp">
                                <input type="password" name="gerant_password_confirmation" id="gerant_password_confirmation" class="form-control" required
                                    minlength="8" placeholder="Répéter le mot de passe" autocomplete="new-password">
                                <button type="button" class="voir-mdp" onclick="basculerLesMotsDePasse(this)" aria-label="Voir les mots de passe" title="Voir">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <small id="accord-mdp" style="font-size:11px; display:block; margin-top:4px;"></small>
                        </div>
                    </div>
                    {{-- Les règles et la jauge, comme à l'inscription. --}}
                    @include('authentification::partials.regles_mot_de_passe', ['champMdp' => 'gerant_password', 'avecJauge' => true])

                    <div style="font-size:11.5px;color:#92400E;background:#FFFBEB;border:1px solid #FCD34D;border-radius:8px;padding:10px 12px;line-height:1.6;margin-bottom:16px;">
                        <i class="fas fa-key"></i>
                        Ce mot de passe vient de vous, non de son propriétaire :
                        le responsable devra <strong>le changer à sa première connexion</strong>.
                    </div>

                    {{-- Adresse, RCCM, NCC et compte contribuable vivaient
                         ici **et** dans le bloc de la facture normalisée : deux
                         champs du même nom, dont le second écrasait le premier.
                         Ils ne restent que dans le bloc, comme à l'inscription ;
                         le compte contribuable, qui désignait le même numéro que
                         le NCC, est retiré (15/10/2026). --}}
                </div>
            </div>

            {{-- Fiche de Plan et Quotas --}}
            <div class="card" style="padding: 24px;">
                <div style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-money-bill-wave" style="color:var(--primary);"></i> Abonnement & Quotas
                </div>
                @include('admin::superadmin.entreprises.partials.abonnement')
            </div>
        </div>

        {{-- Facture normalisée, domaine, Comptaflow --}}
        <div style="display:flex; flex-direction:column; gap:20px;">
            <div class="card" style="padding: 24px;">
                {{-- La facture normalisée : la même question qu'à
                     l'inscription, dans le même bloc. Les deux écrans créent
                     une entreprise ; ils ne peuvent pas demander des choses
                     différentes. --}}
                <div style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-file-invoice" style="color:var(--primary);"></i> La facture normalisée
                    <span style="font-size:10px;font-weight:600;text-transform:none;letter-spacing:0;color:var(--text-3);">— facultatif</span>
                </div>

                <div style="margin-bottom:24px;">
                    @include('admin::partiels.compte-fne', ['prefixeId' => 'sa'])
                </div>

            </div>

            {{-- Le domaine d'activité : le même parcours que celui de
                 l'entreprise, de A à Z — domaine, métiers, modules, rayons,
                 articles (15/10/2026). La grille de cases à cocher et le
                 tableau des modules sont retirés : les modules s'ouvrent par
                 défaut, puis selon le domaine choisi. --}}
            <div class="card" style="padding: 24px;">
                <div style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-route" style="color:var(--primary);"></i> Domaine d'activité
                    <span style="font-size:10px;font-weight:600;text-transform:none;letter-spacing:0;color:var(--text-3);">— facultatif</span>
                </div>
                <p style="font-size:12.5px;color:var(--text-3);line-height:1.6;margin-bottom:14px;">
                    Le domaine décide des métiers, des modules ouverts, des rayons et des articles proposés.
                    « Suivant » crée l'entreprise puis déroule pour elle le même parcours que dans son espace.
                    Il peut aussi se faire plus tard, par l'entreprise elle-même.
                </p>
                <button type="submit" name="suite" value="parcours" class="btn btn-primary" id="btn-suivant-parcours" style="width:100%; justify-content:center; gap:8px;">
                    Suivant — choisir le domaine <i class="fas fa-arrow-right"></i>
                </button>
            </div>

            {{-- Section : Liaison COMPTAFLOW --}}
            <div class="card" id="card-comptaflow" style="padding: 24px; border: 2px solid #e8f0fe;">
                <div style="font-size:12px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-link" style="color:#1a73e8;"></i> Liaison COMPTAFLOW
                </div>
                <label style="display:flex; align-items:center; gap:10px; cursor:pointer; padding:12px; background:#f8fbff; border:1px solid #c8deff; border-radius:10px; margin-bottom:14px;">
                    <input type="checkbox" id="cb-comptaflow" name="creer_compte_comptaflow" value="1" {{ old('creer_compte_comptaflow') ? 'checked' : '' }} onchange="toggleComptaflow(this)" style="width:18px;height:18px;">
                    <span>
                        <span style="font-weight:700; color:#1a73e8;">Créer simultanément un compte COMPTAFLOW</span><br>
                        <small style="color:var(--text-3);">Le compte comptable de l'entreprise sera créé automatiquement dans COMPTAFLOW.</small>
                    </span>
                </label>

                {{-- Deux champs de mot de passe vivaient ici : le
                     superadministrateur choisissait le mot de passe du compte
                     d'un client, et il partait en clair dans le corps de la
                     requête. Comptaflow envoie son propre lien d'activation au
                     responsable, et génère lui-même la clé de liaison — Selflow
                     n'invente ni l'un ni l'autre. --}}
                <div id="comptaflow-fields" style="display:none; flex-direction:column; gap:12px;">
                    <div style="padding:10px; background:rgba(26,115,232,0.05); border:1px solid rgba(26,115,232,0.2); border-radius:8px;">
                        <div style="font-size:11.5px; color:#1a73e8; display:flex; align-items:center; gap:6px;">
                            <i class="fas fa-info-circle"></i> 
                            <span>Les informations de l'entreprise (nom, adresse, contacts, RCCM, NCC) seront transmises automatiquement à COMPTAFLOW.</span>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%; padding:14px; font-weight:700; justify-content:center; gap:8px; border-radius:12px;">
                <i class="fas fa-check-circle" style="font-size:16px;"></i> Créer la structure
            </button>
        </div>
    </div>
</form>

<style>
    .champ-mdp { position: relative; }
    .champ-mdp input { padding-right: 42px; }
    .voir-mdp { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); border: none; background: none; color: var(--text-3); cursor: pointer; padding: 4px 6px; }
    .voir-mdp:hover { color: var(--primary); }
</style>
<script>
// « Voir » : un clic ouvre les deux champs à la fois, et les referme ensemble.
function basculerLesMotsDePasse() {
    var champs = [document.getElementById('gerant_password'), document.getElementById('gerant_password_confirmation')];
    var montrer = champs[0].type === 'password';
    champs.forEach(function (c) { c.type = montrer ? 'text' : 'password'; });
    document.querySelectorAll('.voir-mdp i').forEach(function (i) { i.className = montrer ? 'fas fa-eye-slash' : 'fas fa-eye'; });
}
(function () {
    var mdp = document.getElementById('gerant_password');
    var conf = document.getElementById('gerant_password_confirmation');
    var accord = document.getElementById('accord-mdp');
    function verifier() {
        if (!conf.value) { accord.textContent = ''; return; }
        var ok = conf.value === mdp.value;
        accord.textContent = ok ? '✓ Les deux mots de passe correspondent' : '✗ Les deux mots de passe diffèrent';
        accord.style.color = ok ? '#047857' : '#DC2626';
    }
    mdp.addEventListener('input', verifier);
    conf.addEventListener('input', verifier);
})();
function toggleComptaflow(cb) {
    const fields = document.getElementById('comptaflow-fields');
    fields.style.display = cb.checked ? 'flex' : 'none';
    const pwdInput = document.getElementById('comptaflow_password');
    if (pwdInput) pwdInput.required = cb.checked;
}
// Si la validation a échoué, ré-afficher les champs
document.addEventListener('DOMContentLoaded', function() {
    const cb = document.getElementById('cb-comptaflow');
    if (cb && cb.checked) toggleComptaflow(cb);
});
</script>
@endsection

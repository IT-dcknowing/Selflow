@extends('admin::gabarits.application')

@section('titre', 'Modifier un Administrateur Interne')
@section('topbar_titre', 'Administration Interne · Modifier l\'Administrateur')

@section('contenu')
<div class="page-header" style="margin-bottom: 20px;">
    <div>
        <h1><i class="fas fa-edit"></i> Modifier l'Administrateur Interne</h1>
        <p>Mettez à jour le compte super-administrateur de « {{ $utilisateur->prenom }} {{ $utilisateur->nom }} ».</p>
    </div>
    <a href="{{ route('superadmin.admins.index') }}" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour
    </a>
</div>

<div class="card" style="max-width: 700px; padding: 28px;">
    <form method="POST" action="{{ route('superadmin.admins.mettre_a_jour', $utilisateur) }}">
        @csrf
        @method('PUT')

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
            <div class="form-group">
                <label class="form-label">Nom <span style="color:var(--danger)">*</span></label>
                <input type="text" name="nom" value="{{ old('nom', $utilisateur->nom) }}" class="form-control" placeholder="Agnimel" required style="border-radius: 8px;">
                @error('nom') <small style="color:var(--danger)">{{ $message }}</small> @enderror
            </div>
            <div class="form-group">
                <label class="form-label">Prénom <span style="color:var(--danger)">*</span></label>
                <input type="text" name="prenom" value="{{ old('prenom', $utilisateur->prenom) }}" class="form-control" placeholder="Meledje Abraham" required style="border-radius: 8px;">
                @error('prenom') <small style="color:var(--danger)">{{ $message }}</small> @enderror
            </div>
        </div>

        <div class="form-group" style="margin-bottom: 16px;">
            <label class="form-label">Adresse E-mail <span style="color:var(--danger)">*</span></label>
            <input type="email" name="email" value="{{ old('email', $utilisateur->email) }}" class="form-control" placeholder="Ex: jean.dupont@selflow.ci" required style="border-radius: 8px;">
            @error('email') <small style="color:var(--danger)">{{ $message }}</small> @enderror
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
            <div class="form-group">
                <label class="form-label">Nouveau mot de passe <span style="font-weight:400;color:var(--text-3)">(laisser vide pour ne pas modifier)</span></label>
                <input type="password" name="password" class="form-control" placeholder="Min. 8 caractères" style="border-radius: 8px;">
                @error('password') <small style="color:var(--danger)">{{ $message }}</small> @enderror
            </div>
            <div class="form-group">
                <label class="form-label">Confirmer le mot de passe</label>
                <input type="password" name="password_confirmation" class="form-control" placeholder="Répéter le mot de passe" style="border-radius: 8px;">
            </div>
        </div>

        <div class="form-group" style="margin-bottom: 20px;">
            <label class="form-label">Statut du compte <span style="color:var(--danger)">*</span></label>
            @if($utilisateur->email === 'superadmin@gmail.com')
                <input type="text" value="Actif (Propriétaire)" disabled class="form-control" style="border-radius:8px;background:var(--bg3);cursor:not-allowed;">
                <input type="hidden" name="statut" value="actif">
            @else
                <select name="statut" class="form-control" required style="border-radius: 8px;">
                    <option value="actif" {{ old('statut', $utilisateur->statut) === 'actif' ? 'selected' : '' }}>Actif</option>
                    <option value="inactif" {{ old('statut', $utilisateur->statut) === 'inactif' ? 'selected' : '' }}>Inactif (bloqué)</option>
                </select>
            @endif
            @error('statut') <small style="color:var(--danger)">{{ $message }}</small> @enderror
        </div>

        {{-- ── HABILITATIONS D'ADMINISTRATION ── --}}
        <div style="margin-bottom: 24px; border: 1px solid var(--border); border-radius: 12px; background: var(--bg3); padding: 18px;">
            <div style="font-weight: 700; font-size: 13.5px; color: var(--primary); margin-bottom: 12px; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-shield-halved"></i> Attributions d'habilitations
            </div>

            @if($utilisateur->email === 'superadmin@gmail.com')
                <div style="padding: 12px; background: #e0f2fe; border: 1px solid #bae6fd; border-radius: 8px; color: #0369a1; font-size: 12px; font-weight: 600;">
                    <i class="fas fa-info-circle"></i> Le super-administrateur principal possède obligatoirement tous les accès d'administration de la plateforme de manière irrévocable.
                </div>
            @else
                <p style="font-size: 12px; color: var(--text-3); margin-bottom: 16px; line-height: 1.4;">
                    Cochez les modules du panneau d'administration superadmin auxquels cet utilisateur aura accès.
                </p>

                @php
                    $habsDispo = [
                        'tableau_de_bord_superadmin' => [
                            'titre' => 'Tableau de bord superadmin',
                            'desc'  => 'Accès aux graphiques, statistiques et chiffres consolidés de la plateforme.'
                        ],
                        'gestion_entreprises' => [
                            'titre' => 'Gestion des entreprises & accès',
                            'desc'  => 'Création, modification, blocage et suppression des structures et de leurs utilisateurs.'
                        ],
                        'gestion_comptaflow' => [
                            'titre' => 'Liaisons COMPTAFLOW',
                            'desc'  => 'Supervision des liens comptables et création simultanée des comptes.'
                        ],
                        'gestion_fne' => [
                            'titre' => 'Gestion FNE',
                            'desc'  => 'Configuration, attribution et suppression des clés API de normalisation fiscale DGI.'
                        ],
                        'administration_interne' => [
                            'titre' => 'Administration interne',
                            'desc'  => 'Création, édition et suppression des autres super-administrateurs de la plateforme.'
                        ],
                        'identite_entreprises' => [
                            'titre' => 'Identité des entreprises (support)',
                            'desc'  => 'Modifier le nom d\'une entreprise, son e-mail et l\'adresse de connexion de son responsable.'
                        ],
                        'gestion_secteurs_modules' => [
                            'titre' => 'Secteurs & Modules',
                            'desc'  => 'Configuration globale des modules autorisés par défaut pour chaque secteur d\'activité.'
                        ],
                    ];
                    $currentHabs = $utilisateur->habilitations ?? [];
                    if (is_string($currentHabs)) {
                        $currentHabs = [$currentHabs];
                    }
                    $oldHabs = old('habilitations', $currentHabs);
                @endphp

                <div style="display:flex; flex-direction:column; gap:12px;">
                    @foreach($habsDispo as $key => $h)
                    <label style="display:flex; align-items:flex-start; gap:10px; padding:10px; background:#fff; border:1px solid var(--border); border-radius:8px; cursor:pointer; transition: border-color .15s;">
                        <input type="checkbox" name="habilitations[]" value="{{ $key }}" {{ in_array($key, $oldHabs) ? 'checked' : '' }} style="margin-top: 3px; accent-color: var(--primary);">
                        <div>
                            <div style="font-weight:600; font-size:13px; color:var(--text-1);">{{ $h['titre'] }}</div>
                            <div style="font-size:11.5px; color:var(--text-3); margin-top:2px;">{{ $h['desc'] }}</div>
                        </div>
                    </label>
                    @endforeach
                </div>
                @error('habilitations') <small style="color:var(--danger)">{{ $message }}</small> @enderror
            @endif
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px; font-weight: 700; border-radius: 12px; justify-content: center; gap: 8px;">
            <i class="fas fa-save"></i> Enregistrer les modifications
        </button>
    </form>
</div>
@endsection

@extends('admin::gabarits.application')
@section('titre', 'Mon Profil')
@section('topbar_titre', 'Utilisateur — Mon profil')

@section('contenu')
<div class="page-header" style="margin-bottom: 24px;">
    <div>
        <h1><i class="far fa-user"></i> Mon profil</h1>
        <p>Gérez vos informations personnelles et identifiants de sécurité</p>
    </div>
</div>

@if(session('succes'))
<div class="alert alert-success" style="display:flex;align-items:center;gap:10px;padding:14px 18px;background:#d1fae5;border:1px solid #6ee7b7;border-radius:10px;margin-bottom:20px;color:#065f46;font-weight:500;">
    <i class="fas fa-check-circle" style="font-size:16px;color:#10b981;"></i>
    {{ session('succes') }}
</div>
@endif

<form method="POST" action="{{ route('admin.mon_profil.enregistrer') }}" enctype="multipart/form-data">
    @csrf
    @method('PUT')
    <div style="display:grid; grid-template-columns: 1fr 2fr; gap:24px; align-items:start;">

        
        {{-- Partie gauche : Avatar & Informations système --}}
        <div class="card" style="padding:24px; text-align:center; display:flex; flex-direction:column; align-items:center; gap:16px;">
            <div style="position:relative; width:120px; height:120px; border-radius:50%; overflow:hidden; border:2px solid var(--border); background:var(--bg3);">
                <img id="avatar-preview" src="{{ $utilisateur->avatar_url }}" alt="" onerror="this.onerror=null; this.src='{{ $utilisateur->avatar_repli }}';" style="width:100%; height:100%; object-fit:cover;">
                <label for="avatar-input" style="position:absolute; bottom:0; left:0; right:0; background:rgba(0,0,0,0.6); color:#fff; font-size:11px; padding:4px 0; cursor:pointer; font-weight:600;">
                    <i class="fas fa-camera"></i> Modifier
                </label>
                <input type="file" id="avatar-input" name="avatar" accept="image/*" style="display:none;" onchange="previewAvatar(this)">
            </div>
            
            <div>
                <h2 style="font-size:16px; font-weight:700; color:var(--text-1);">{{ $utilisateur->prenom }} {{ $utilisateur->nom }}</h2>
                <p style="font-size:11px; font-weight:700; color:var(--primary); text-transform:uppercase; margin-top:4px; letter-spacing:.5px;">
                    {{ $utilisateur->fonction ?: str_replace('_', ' ', $utilisateur->role) }}
                </p>
                <p style="font-size:12px; color:var(--text-3); margin-top:2px;">{{ $utilisateur->email }}</p>
            </div>

            <div style="width:100%; border-top:1px solid var(--border); padding-top:16px; display:flex; flex-direction:column; gap:10px; text-align:left; font-size:12px; color:var(--text-2);">
                <div><i class="fas fa-building" style="width:16px; color:var(--text-3);"></i> Entreprise : <strong>{{ $utilisateur->entreprise->nom }}</strong></div>
                <div><i class="fas fa-store" style="width:16px; color:var(--text-3);"></i> Point de vente : <strong>{{ $utilisateur->pointDeVente?->nom ?: 'Siège / Principal' }}</strong></div>
                <div><i class="fas fa-calendar" style="width:16px; color:var(--text-3);"></i> Contrat depuis le : <strong>{{ $utilisateur->date_debut_contrat ? \Carbon\Carbon::parse($utilisateur->date_debut_contrat)->format('d/m/Y') : '—' }}</strong></div>
            </div>
        </div>

        {{-- Partie droite : Formulaire d'édition --}}
        <div style="display:flex; flex-direction:column; gap:20px;">
            <div class="card" style="padding:24px;">
                <h3 style="font-size:13px; font-weight:700; text-transform:uppercase; color:var(--text-2); letter-spacing:.5px; margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:8px;">
                    <i class="fas fa-id-card" style="color:var(--primary);"></i> Informations personnelles
                </h3>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <div class="form-group">
                        <label class="form-label">Prénom <span style="color:var(--danger)">*</span></label>
                        <input type="text" name="prenom" class="form-control" value="{{ old('prenom', $utilisateur->prenom) }}" required>
                        @error('prenom') <small style="color:var(--danger)">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nom <span style="color:var(--danger)">*</span></label>
                        <input type="text" name="nom" class="form-control" value="{{ old('nom', $utilisateur->nom) }}" required>
                        @error('nom') <small style="color:var(--danger)">{{ $message }}</small> @enderror
                    </div>
                </div>
                <div class="form-group" style="margin-top:14px; margin-bottom:0;">
                    <label class="form-label">Adresse E-mail (Non modifiable)</label>
                    <input type="email" class="form-control" value="{{ $utilisateur->email }}" disabled style="background:var(--bg3); cursor:not-allowed;">
                    <small style="color:var(--text-3); font-size:11px; margin-top:4px; display:block;">Pour changer votre adresse e-mail, veuillez contacter l'administrateur principal de la structure.</small>
                </div>
            </div>

            <div class="card" style="padding:24px;">
                <h3 style="font-size:13px; font-weight:700; text-transform:uppercase; color:var(--text-2); letter-spacing:.5px; margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:8px;">
                    <i class="fas fa-lock" style="color:var(--primary);"></i> Modifier mon mot de passe
                </h3>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                    <div class="form-group">
                        <label class="form-label">Nouveau mot de passe</label>
                        <input type="password" name="password" class="form-control" placeholder="Min 6 caractères">
                        @error('password') <small style="color:var(--danger)">{{ $message }}</small> @enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirmer le mot de passe</label>
                        <input type="password" name="password_confirmation" class="form-control" placeholder="Ressaisir le mot de passe">
                    </div>
                </div>
                <small style="color:var(--text-3); font-size:11px; margin-top:-4px; display:block;">Laissez ces champs vides si vous ne souhaitez pas modifier votre mot de passe actuel.</small>
            </div>

            <button type="submit" class="btn btn-primary" style="align-self:flex-end; padding:12px 24px; font-weight:700; gap:8px;">
                <i class="fas fa-save"></i> Enregistrer les modifications
            </button>
        </div>

    </div>
</form>

<script>
function previewAvatar(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('avatar-preview').src = e.target.result;
        }
        reader.readAsDataURL(input.files[0]);
    }
}
</script>
@endsection

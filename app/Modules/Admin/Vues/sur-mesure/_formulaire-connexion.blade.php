<div class="marque">
    @if($logo)<img src="{{ $logo }}" alt="{{ $nomEspace }}">@else<span>{{ $nomEspace }}</span>@endif
</div>

<h2>{{ $message }}</h2>
<p class="sous-titre">Connectez-vous à l'espace de gestion de {{ $nomEspace }}.</p>

@if(session('inscription_en_attente'))
    <div class="alerte bonne" role="status"><i class="fas fa-circle-check"></i> {{ session('inscription_en_attente') }}</div>
@endif
@if(isset($errors) && $errors->any())
    <div class="alerte" role="alert"><i class="fas fa-circle-exclamation" style="margin-top:2px;"></i> {{ $errors->first('connexion_erreur') ?: $errors->first() }}</div>
@endif
@if(session('status'))
    <div class="alerte bonne" role="status"><i class="fas fa-circle-check"></i> {{ session('status') }}</div>
@endif

<form method="POST" action="{{ route('connexion.traitement') }}" id="form-connexion" novalidate @if($apercu) data-apercu="1" @endif>
    @csrf
    <label class="etiquette" for="email">Adresse e-mail
        <input type="email" id="email" name="email" placeholder="vous@entreprise.ci" value="{{ old('email') }}" autocomplete="email" required autofocus>
    </label>
    <label class="etiquette" for="password">Mot de passe
        <span class="mdp">
            <input type="password" id="password" name="password" placeholder="Votre mot de passe" autocomplete="current-password" required>
            <button type="button" id="voir-mdp" aria-label="Afficher le mot de passe"><i class="fas fa-eye"></i></button>
        </span>
    </label>
    <div class="options">
        <label style="display:flex; gap:7px; align-items:center;"><input type="checkbox" name="se_souvenir" value="1" {{ old('se_souvenir') ? 'checked' : '' }}> Se souvenir de moi</label>
        <a href="{{ $apercu ? '#' : route('password.request') }}">Mot de passe oublié ?</a>
    </div>
    <button type="submit" class="envoyer" id="btn-soumettre">Se connecter</button>
</form>

@if($espace->telephone || $espace->email)
    <div class="contact">
        Besoin d'aide ?
        @if($espace->telephone)<br><i class="fas fa-phone"></i> {{ $espace->telephone }}@endif
        @if($espace->email)<br><i class="fas fa-envelope"></i> {{ $espace->email }}@endif
    </div>
@endif

@extends('admin::gabarits.application')
@section('titre', 'Sur mesure · ' . $entreprise->nom)
@section('topbar_titre', 'Superadmin · Selflow sur mesure')

@section('contenu')
@php
    use App\Modules\Admin\Modeles\EspaceSurMesure;
    $modulesActifs = (array) old('modules', $espace->modules ?? []);
    $atouts = old('atouts', $espace->atouts ?? []);
    $atouts = array_pad(array_values((array) $atouts), 6, ['titre' => '', 'texte' => '']);
    $couleurP = old('couleur_principale', $espace->couleur_principale ?: '#1d4ed8');
    $couleurS = old('couleur_secondaire', $espace->couleur_secondaire ?: '#0f172a');
@endphp

<div class="page-header">
    <div>
        <a href="{{ route('superadmin.sur_mesure.index') }}" style="font-size:13px; color:#64748b; text-decoration:none;"><i class="fas fa-arrow-left"></i> Toutes les entreprises</a>
        <h1 style="margin-top:6px;"><i class="fas fa-wand-magic-sparkles"></i> {{ $entreprise->nom }}</h1>
        <p>
            @if($espace->exists && $espace->actif)
                <span style="color:#047857; font-weight:800;">Espace actif</span>
                @foreach($espace->adresses() as $adresse) · <a href="https://{{ $adresse }}" target="_blank" rel="noopener">{{ $adresse }}</a>@endforeach
            @elseif($espace->exists)
                <span style="color:#b45309; font-weight:800;">En préparation</span> : rien n'est visible tant que l'espace n'est pas activé.
            @else
                Pas encore d'espace sur mesure : l'entreprise utilise Selflow standard.
            @endif
        </p>
    </div>
    @if($espace->exists)
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a class="btn btn-outline" target="_blank" href="{{ route('superadmin.sur_mesure.apercu', [$entreprise, 'accueil']) }}"><i class="fas fa-eye"></i> Aperçu accueil</a>
            <a class="btn btn-outline" target="_blank" href="{{ route('superadmin.sur_mesure.apercu', [$entreprise, 'connexion']) }}"><i class="fas fa-eye"></i> Aperçu connexion</a>
        </div>
    @endif
</div>

@if($errors->any())
    <div class="alert alert-danger" style="margin-bottom:14px;"><i class="fas fa-circle-exclamation"></i> {{ $errors->first() }}</div>
@endif

<style>
    .sm-onglets { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
    .sm-onglets button { border:1px solid #e2e8f0; background:#fff; padding:9px 16px; border-radius:10px; font-weight:700; font-size:13.5px; cursor:pointer; color:#475569; }
    .sm-onglets button.actif { background:var(--primary); color:#fff; border-color:var(--primary); }
    .sm-volet { display:none; }
    .sm-volet.actif { display:block; }
    .sm-grille { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:16px; }
    .sm-champ label { display:block; font-weight:700; font-size:13px; margin-bottom:6px; color:#334155; }
    .sm-champ small { display:block; color:#64748b; font-size:12px; margin-top:5px; line-height:1.5; }
    .sm-prefixe { display:flex; align-items:stretch; }
    .sm-prefixe .form-control { border-top-right-radius:0; border-bottom-right-radius:0; }
    .sm-prefixe span { display:flex; align-items:center; padding:0 12px; background:#f1f5f9; border:1px solid #e2e8f0; border-left:0; border-radius:0 8px 8px 0; font-size:13px; color:#475569; white-space:nowrap; }
    .sm-module { display:flex; gap:12px; align-items:flex-start; padding:14px; border:1px solid #e2e8f0; border-radius:12px; cursor:pointer; }
    .sm-module input { margin-top:3px; width:18px; height:18px; }
    .sm-image { display:flex; gap:12px; align-items:center; padding:12px; border:1px dashed #cbd5e1; border-radius:12px; }
    .sm-image .vignette { width:64px; height:64px; border-radius:10px; background:#f1f5f9 center/contain no-repeat; flex-shrink:0; border:1px solid #e2e8f0; }
    .sm-image .vignette.sombre { background-color:#0f172a; }
    .sm-dns { background:#0f172a; color:#e2e8f0; border-radius:12px; padding:14px 16px; font-family:ui-monospace,monospace; font-size:12.5px; line-height:1.8; overflow-x:auto; }
    .sm-modele { display:block; border:2px solid #e2e8f0; border-radius:12px; padding:12px 14px; cursor:pointer; font-size:13.5px; font-weight:600; }
    .sm-modele:has(input:checked) { border-color:var(--primary); background:#eff6ff; }
    .sm-modele input { margin-right:8px; }
</style>

<div class="sm-onglets" role="tablist">
    <button type="button" data-volet="domaine" class="actif"><i class="fas fa-globe"></i> Domaine</button>
    <button type="button" data-volet="identite"><i class="fas fa-palette"></i> Identité</button>
    <button type="button" data-volet="accueil"><i class="fas fa-house"></i> Page d'accueil</button>
    <button type="button" data-volet="connexion"><i class="fas fa-right-to-bracket"></i> Connexion</button>
    <button type="button" data-volet="modules"><i class="fas fa-toggle-on"></i> Modules</button>
</div>

<form method="POST" action="{{ route('superadmin.sur_mesure.enregistrer', $entreprise) }}" enctype="multipart/form-data" id="form-sur-mesure">
    @csrf

    {{-- ── Domaine ── --}}
    <div class="card sm-volet actif" data-volet="domaine" style="padding:20px;">
        <h3 style="margin-top:0;">Adresses de l'entreprise</h3>
        <p style="color:#64748b; font-size:13.5px; margin-top:-4px;">Un sous-domaine de Selflow, un domaine propre à l'entreprise, ou les deux. Pas de redirection : l'adresse pointe sur notre serveur et reste celle de l'entreprise.</p>
        <div class="sm-grille">
            <div class="sm-champ">
                <label for="sous_domaine">Sous-domaine Selflow</label>
                <div class="sm-prefixe">
                    <input class="form-control" id="sous_domaine" name="sous_domaine" value="{{ old('sous_domaine', $espace->sous_domaine) }}" placeholder="nom-entreprise">
                    <span>.{{ $racine }}</span>
                </div>
                <small>Immédiat une fois le sous-domaine générique en place sur le serveur (voir ci-dessous).</small>
            </div>
            <div class="sm-champ">
                <label for="domaine">Domaine propre</label>
                <input class="form-control" id="domaine" name="domaine" value="{{ old('domaine', $espace->domaine) }}" placeholder="gestion.entreprise.ci">
                <small>
                    @if($espace->domaine && $espace->domaine_verifie_le)
                        <span style="color:#047857; font-weight:700;"><i class="fas fa-circle-check"></i> Vérifié le {{ $espace->domaine_verifie_le->format('d/m/Y à H:i') }}</span>
                    @elseif($espace->domaine)
                        <span style="color:#b45309; font-weight:700;"><i class="fas fa-triangle-exclamation"></i> Pas encore vérifié</span>
                    @else
                        Le domaine de l'entreprise, que nous déclarons dans ses DNS.
                    @endif
                </small>
            </div>
        </div>

        <h4 style="margin:22px 0 8px;">Ce que nous inscrivons dans les DNS</h4>
        <div class="sm-dns">
            @if($ipServeur)
                <div>{{ old('domaine', $espace->domaine) ?: 'gestion.entreprise.ci' }}&nbsp;&nbsp;A&nbsp;&nbsp;{{ $ipServeur }}</div>
            @else
                <div>{{ old('domaine', $espace->domaine) ?: 'gestion.entreprise.ci' }}&nbsp;&nbsp;CNAME&nbsp;&nbsp;{{ $cibleCname }}.</div>
            @endif
            <div style="color:#94a3b8;">*.{{ $racine }}&nbsp;&nbsp;{{ $ipServeur ? 'A  ' . $ipServeur : 'CNAME  ' . $racine . '.' }}&nbsp;&nbsp;(une seule fois, pour tous les sous-domaines)</div>
        </div>
        <ol style="font-size:13px; color:#475569; line-height:1.8; padding-left:18px; margin:12px 0 0;">
            <li>cPanel, <strong>Domaines</strong> : ajouter le domaine de l'entreprise avec comme racine le dossier <code>public</code> de Selflow (le même que {{ $racine }}). Pour les sous-domaines, ajouter une seule fois <code>*.{{ $racine }}</code> vers ce même dossier.</li>
            <li>Inscrire l'enregistrement ci-dessus dans la zone DNS du domaine.</li>
            <li>cPanel, <strong>SSL/TLS Status</strong> : lancer AutoSSL pour obtenir le certificat HTTPS.</li>
            <li>Revenir ici, <strong>Vérifier les adresses</strong>, puis activer l'espace dans l'onglet Modules.</li>
        </ol>
        @if($espace->exists && $espace->adresses())
            <button type="submit" form="form-verifier" class="btn btn-outline" style="margin-top:14px;"><i class="fas fa-stethoscope"></i> Vérifier les adresses</button>
        @endif
    </div>

    {{-- ── Identité ── --}}
    <div class="card sm-volet" data-volet="identite" style="padding:20px;">
        <h3 style="margin-top:0;">Identité visuelle</h3>
        <div class="sm-grille" style="margin-bottom:18px;">
            <div class="sm-champ">
                <label>Couleur principale</label>
                <div style="display:flex; gap:10px; align-items:center;">
                    <input type="color" name="couleur_principale" value="{{ $couleurP }}" style="width:54px; height:40px; border:0; background:none; cursor:pointer;" oninput="this.nextElementSibling.textContent=this.value">
                    <code>{{ $couleurP }}</code>
                </div>
                <small>Boutons, liens, accents.</small>
            </div>
            <div class="sm-champ">
                <label>Couleur secondaire</label>
                <div style="display:flex; gap:10px; align-items:center;">
                    <input type="color" name="couleur_secondaire" value="{{ $couleurS }}" style="width:54px; height:40px; border:0; background:none; cursor:pointer;" oninput="this.nextElementSibling.textContent=this.value">
                    <code>{{ $couleurS }}</code>
                </div>
                <small>Titres, dégradés, menu de l'application.</small>
            </div>
        </div>
        <div class="sm-grille">
            @foreach(['logo' => ['Logo (sur fond clair)', false], 'logo_blanc' => ['Logo blanc (sur fond foncé)', true], 'icone' => ['Icône d\'onglet', false], 'image_fond' => ['Image de fond ou photo', false]] as $champ => [$libelle, $sombre])
                <div class="sm-champ">
                    <label>{{ $libelle }}</label>
                    <div class="sm-image">
                        <div class="vignette {{ $sombre ? 'sombre' : '' }}" @if($espace->{$champ}) style="background-image:url('{{ $espace->media($champ) }}'); {{ $champ === 'image_fond' ? 'background-size:cover;' : '' }}" @endif></div>
                        <div style="flex:1; min-width:0;">
                            <input type="file" name="{{ $champ }}" accept="image/png,image/jpeg,image/webp" style="font-size:12.5px; max-width:100%;">
                            @if($espace->{$champ})
                                <label style="font-weight:500; font-size:12px; margin-top:6px; display:flex; gap:6px; align-items:center;"><input type="checkbox" name="retirer[]" value="{{ $champ }}"> Retirer</label>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        <small style="display:block; color:#64748b; margin-top:10px;">PNG, JPEG ou WebP. Pas de SVG.</small>
    </div>

    {{-- ── Page d'accueil ── --}}
    <div class="card sm-volet" data-volet="accueil" style="padding:20px;">
        <h3 style="margin-top:0;">Page d'accueil</h3>
        <div class="sm-grille" style="margin-bottom:18px;">
            @foreach(EspaceSurMesure::MODELES_ACCUEIL as $cle => $libelle)
                <label class="sm-modele"><input type="radio" name="modele_accueil" value="{{ $cle }}" {{ old('modele_accueil', $espace->modele_accueil ?: 'horizon') === $cle ? 'checked' : '' }}>{{ $libelle }}</label>
            @endforeach
        </div>
        <small style="display:block; color:#64748b; margin:-8px 0 16px;">« Sur mesure » affiche la page dessinée pour l'entreprise (<code>sur-mesure/espaces/entreprise-{{ $entreprise->id }}.blade.php</code>) ; tant qu'elle n'existe pas, le modèle Horizon s'affiche.</small>
        <div class="sm-grille">
            <div class="sm-champ"><label>Titre</label><input class="form-control" name="titre_accueil" value="{{ old('titre_accueil', $espace->titre_accueil) }}" placeholder="{{ $entreprise->nom }}"></div>
            <div class="sm-champ"><label>Sous-titre</label><input class="form-control" name="sous_titre_accueil" value="{{ old('sous_titre_accueil', $espace->sous_titre_accueil) }}" placeholder="Une phrase qui présente l'entreprise"></div>
        </div>
        <div class="sm-champ" style="margin-top:14px;"><label>Présentation</label><textarea class="form-control" name="presentation" rows="5" placeholder="Quelques paragraphes sur l'entreprise (facultatif)">{{ old('presentation', $espace->presentation) }}</textarea></div>

        <h4 style="margin:22px 0 8px;">Atouts (jusqu'à six)</h4>
        <div class="sm-grille">
            @foreach($atouts as $i => $atout)
                <div style="border:1px solid #e2e8f0; border-radius:12px; padding:12px;">
                    <input class="form-control" name="atouts[{{ $i }}][titre]" value="{{ $atout['titre'] ?? '' }}" placeholder="Titre de l'atout {{ $i + 1 }}" style="margin-bottom:8px;">
                    <textarea class="form-control" name="atouts[{{ $i }}][texte]" rows="2" placeholder="Une ou deux phrases">{{ $atout['texte'] ?? '' }}</textarea>
                </div>
            @endforeach
        </div>
        <small style="display:block; color:#64748b; margin-top:8px;">Sans atout saisi, la page en présente trois par défaut (ventes, stock, factures certifiées).</small>

        <h4 style="margin:22px 0 8px;">Coordonnées</h4>
        <div class="sm-grille">
            <div class="sm-champ"><label>Téléphone</label><input class="form-control" name="telephone" value="{{ old('telephone', $espace->telephone) }}"></div>
            <div class="sm-champ"><label>E-mail</label><input class="form-control" type="email" name="email" value="{{ old('email', $espace->email) }}"></div>
            <div class="sm-champ"><label>Adresse</label><input class="form-control" name="adresse" value="{{ old('adresse', $espace->adresse) }}"></div>
        </div>
    </div>

    {{-- ── Connexion ── --}}
    <div class="card sm-volet" data-volet="connexion" style="padding:20px;">
        <h3 style="margin-top:0;">Page de connexion</h3>
        <div class="sm-grille" style="margin-bottom:18px;">
            @foreach(EspaceSurMesure::MODELES_CONNEXION as $cle => $libelle)
                <label class="sm-modele"><input type="radio" name="modele_connexion" value="{{ $cle }}" {{ old('modele_connexion', $espace->modele_connexion ?: 'panneau') === $cle ? 'checked' : '' }}>{{ $libelle }}</label>
            @endforeach
        </div>
        <div class="sm-champ"><label>Message d'accueil</label><input class="form-control" name="message_connexion" value="{{ old('message_connexion', $espace->message_connexion) }}" placeholder="Heureux de vous revoir !"></div>
        <small style="display:block; color:#64748b; margin-top:10px;">Seuls les utilisateurs de {{ $entreprise->nom }} (et le superadministrateur) peuvent se connecter à cette adresse. Pas d'inscription ni de connexion Google sur l'espace. « Propulsé par DC-KNOWING » figure sur toutes les pages.</small>
    </div>

    {{-- ── Modules ── --}}
    <div class="card sm-volet" data-volet="modules" style="padding:20px;">
        <h3 style="margin-top:0;">Modules</h3>
        <div style="display:grid; gap:10px; margin-bottom:18px;">
            @foreach(EspaceSurMesure::MODULES as $cle => [$libelle, $aide])
                <label class="sm-module">
                    <input type="checkbox" name="modules[]" value="{{ $cle }}" {{ in_array($cle, $modulesActifs, true) ? 'checked' : '' }}>
                    <span><strong>{{ $libelle }}</strong><br><span style="color:#64748b; font-size:13px;">{{ $aide }}</span></span>
                </label>
            @endforeach
        </div>
        <label class="sm-module" style="border-color:#10b981; background:#ecfdf5;">
            <input type="hidden" name="actif" value="0">
            <input type="checkbox" name="actif" value="1" {{ old('actif', $espace->actif) ? 'checked' : '' }}>
            <span><strong>Espace actif</strong><br><span style="color:#047857; font-size:13px;">Les adresses répondent avec l'habillage de l'entreprise. Désactivé, l'adresse retombe sur Selflow standard.</span></span>
        </label>
    </div>

    <div style="position:sticky; bottom:0; background:linear-gradient(180deg,transparent,#f8fafc 30%); padding:16px 0; margin-top:10px; display:flex; justify-content:flex-end;">
        <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Enregistrer</button>
    </div>
</form>

@if($espace->exists)
    <form method="POST" action="{{ route('superadmin.sur_mesure.verifier', $entreprise) }}" id="form-verifier">@csrf</form>
@endif

<script>
document.querySelectorAll('.sm-onglets button').forEach(function (b) {
    b.addEventListener('click', function () {
        document.querySelectorAll('.sm-onglets button').forEach(function (x) { x.classList.toggle('actif', x === b); });
        document.querySelectorAll('.sm-volet').forEach(function (v) { v.classList.toggle('actif', v.dataset.volet === b.dataset.volet); });
    });
});
</script>
@endsection

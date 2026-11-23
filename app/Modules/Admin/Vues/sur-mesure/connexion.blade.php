<!DOCTYPE html>
<html lang="fr">
<head>
@php
    $nomEspace = $espace->entreprise?->nom ?? 'Espace';
    $principale = $espace->couleur_principale ?: '#1d4ed8';
    $secondaire = $espace->couleur_secondaire ?: '#0f172a';
@endphp
@include('admin::sur-mesure._tete')
@php
    $modele = in_array($espace->modele_connexion, ['panneau', 'plein_ecran', 'carte'], true) ? $espace->modele_connexion : 'panneau';
    $logo = $espace->media('logo');
    $logoBlanc = $espace->media('logo_blanc') ?: $logo;
    $fond = $espace->media('image_fond');
    $message = $espace->message_connexion ?: 'Heureux de vous revoir !';
@endphp
<title>Connexion · {{ $nomEspace }}</title>
<style>
    html, body { min-height: 100%; }
    .page { min-height: 100vh; display: flex; }
    .visuel { flex: 1.05; position: relative; overflow: hidden; color: #fff; display: flex; flex-direction: column; justify-content: space-between; padding: 48px; background: linear-gradient(145deg, var(--principale), var(--secondaire)); }
    .visuel .fond { position: absolute; inset: 0; background-size: cover; background-position: center; }
    .visuel .voile { position: absolute; inset: 0; background: linear-gradient(160deg, rgba(2,6,23,.15), rgba(2,6,23,.7)); }
    .visuel > * { position: relative; }
    .visuel img.logo { height: 48px; max-width: 220px; object-fit: contain; align-self: flex-start; }
    .visuel h1 { font-size: clamp(28px, 3.2vw, 44px); line-height: 1.12; margin: 0 0 12px; letter-spacing: -.01em; }
    .visuel p { margin: 0; font-size: 16px; opacity: .88; max-width: 460px; line-height: 1.6; }
    .zone { flex: 1; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 40px 22px; background: #fff; }
    .carte { width: 100%; max-width: 410px; }
    .marque { display: flex; align-items: center; gap: 10px; margin-bottom: 26px; font-weight: 800; font-size: 19px; }
    .marque img { height: 42px; max-width: 200px; object-fit: contain; }
    h2 { margin: 0 0 6px; font-size: 26px; letter-spacing: -.01em; color: var(--secondaire); }
    .sous-titre { margin: 0 0 26px; color: var(--doux); font-size: 14.5px; }
    form { display: flex; flex-direction: column; gap: 15px; }
    label.etiquette { display: flex; flex-direction: column; gap: 7px; font-size: 13.5px; font-weight: 700; color: #334155; }
    input[type=email], input[type=password], input[type=text] { width: 100%; padding: 13px 14px; border: 1.5px solid var(--bord); border-radius: 11px; font: inherit; font-size: 15px; transition: border-color .15s, box-shadow .15s; background: #fff; }
    input:focus { outline: none; border-color: var(--principale); box-shadow: 0 0 0 4px var(--principale-pale); }
    .mdp { position: relative; }
    .mdp button { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); border: 0; background: none; color: var(--doux); cursor: pointer; padding: 6px; font-size: 15px; }
    .options { display: flex; justify-content: space-between; align-items: center; font-size: 13.5px; color: #475569; gap: 10px; flex-wrap: wrap; }
    .options a { color: var(--principale); font-weight: 700; text-decoration: none; }
    .envoyer { margin-top: 4px; padding: 14px; border: 0; border-radius: 11px; background: var(--principale); color: #fff; font: inherit; font-weight: 800; font-size: 15.5px; cursor: pointer; box-shadow: 0 12px 24px -12px var(--principale); transition: background .15s, transform .15s; }
    .envoyer:hover { background: var(--principale-fonce); transform: translateY(-1px); }
    .envoyer:disabled { opacity: .7; cursor: wait; }
    .alerte { display: flex; gap: 9px; padding: 12px 14px; border-radius: 10px; font-size: 13.5px; margin-bottom: 16px; line-height: 1.5; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    .alerte.bonne { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
    .contact { margin-top: 24px; font-size: 13px; color: var(--doux); text-align: center; line-height: 1.7; }

    /* Plein écran et carte : une carte centrée */
    .page.centree { justify-content: center; align-items: center; padding: 40px 16px; flex-direction: column; position: relative; overflow: hidden; }
    .page.plein_ecran { background: linear-gradient(145deg, var(--principale), var(--secondaire)); }
    .page.plein_ecran .fond { position: absolute; inset: 0; background-size: cover; background-position: center; }
    .page.plein_ecran .voile { position: absolute; inset: 0; background: rgba(2,6,23,.55); backdrop-filter: blur(2px); }
    .page.carte-unie { background: radial-gradient(1200px 600px at 10% -10%, var(--principale-pale), transparent), #f8fafc; }
    .page.centree .boite { position: relative; width: 100%; max-width: 450px; background: #fff; border-radius: 22px; padding: 40px 34px; box-shadow: 0 40px 90px -40px rgba(2,6,23,.55); }
    .page.centree .propulse { position: relative; }
    .page.plein_ecran .propulse, .page.plein_ecran .propulse strong { color: rgba(255,255,255,.85); }

    @media (max-width: 900px) {
        .page:not(.centree) .visuel { display: none; }
        .page.centree .boite { padding: 30px 22px; }
    }
</style>
</head>
<body>
@if(!empty($apercu))
    <div class="bandeau-apercu">Aperçu de la page de connexion de {{ $nomEspace }}{{ $espace->actif ? '' : ' (espace pas encore actif)' }}</div>
@endif


@if($modele === 'panneau')
    <div class="page">
        <aside class="visuel" aria-hidden="true">
            @if($fond)<div class="fond" style="background-image:url('{{ $fond }}')"></div><div class="voile"></div>@endif
            @if($logoBlanc)<img class="logo" src="{{ $logoBlanc }}" alt="">@else<strong style="font-size:22px;">{{ $nomEspace }}</strong>@endif
            <div>
                <h1>{{ $espace->titre_accueil ?: $nomEspace }}</h1>
                @if($espace->sous_titre_accueil)<p>{{ $espace->sous_titre_accueil }}</p>@endif
            </div>
            <span style="font-size:13px; opacity:.8;">Propulsé par <strong>DC-KNOWING</strong></span>
        </aside>
        <main class="zone">
            <div class="carte">@include('admin::sur-mesure._formulaire-connexion', ['apercu' => !empty($apercu)])</div>
            <div class="propulse">Propulsé par <strong>DC-KNOWING</strong></div>
        </main>
    </div>
@else
    <div class="page centree {{ $modele === 'plein_ecran' ? 'plein_ecran' : 'carte-unie' }}">
        @if($modele === 'plein_ecran')
            @if($fond)<div class="fond" style="background-image:url('{{ $fond }}')"></div>@endif
            <div class="voile"></div>
        @endif
        <div class="boite">@include('admin::sur-mesure._formulaire-connexion', ['apercu' => !empty($apercu)])</div>
        <div class="propulse">Propulsé par <strong>DC-KNOWING</strong></div>
    </div>
@endif

<script>
    (function () {
        var bouton = document.getElementById('voir-mdp');
        if (bouton) bouton.addEventListener('click', function () {
            var champ = document.getElementById('password');
            var visible = champ.type === 'password';
            champ.type = visible ? 'text' : 'password';
            this.innerHTML = visible ? '<i class="fas fa-eye-slash"></i>' : '<i class="fas fa-eye"></i>';
        });
        var form = document.getElementById('form-connexion');
        if (form) form.addEventListener('submit', function (e) {
            if (form.dataset.apercu) { e.preventDefault(); return; }
            var b = document.getElementById('btn-soumettre');
            b.disabled = true; b.textContent = 'Connexion…';
        });
    })();
</script>
</body>
</html>

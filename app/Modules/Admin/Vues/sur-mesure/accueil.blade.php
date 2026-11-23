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
    $modele = in_array($espace->modele_accueil, ['horizon', 'immersion', 'epure'], true) ? $espace->modele_accueil : 'horizon';
    $titre = $espace->titre_accueil ?: $nomEspace;
    $sousTitre = $espace->sous_titre_accueil ?: 'L\'espace de gestion de ' . $nomEspace . ' : ventes, stock, factures et comptabilité, au même endroit.';
    $atouts = collect($espace->atouts ?? [])->filter(fn ($a) => ($a['titre'] ?? '') !== '');
    if ($atouts->isEmpty()) {
        $atouts = collect([
            ['titre' => 'Ventes et factures', 'texte' => 'Devis, commandes, factures et reçus, émis en quelques clics.'],
            ['titre' => 'Stock en temps réel', 'texte' => 'Chaque point de vente suit ses entrées, ses sorties et ses inventaires.'],
            ['titre' => 'Factures certifiées', 'texte' => 'Les factures sont normalisées auprès de la DGI, sans quitter l\'application.'],
        ]);
    }
    $icones = ['fa-file-invoice', 'fa-boxes-stacked', 'fa-shield-halved', 'fa-chart-line', 'fa-users', 'fa-wallet'];
    $logo = $espace->media('logo');
    $logoBlanc = $espace->media('logo_blanc') ?: $logo;
    $fond = $espace->media('image_fond');
    $lienConnexion = !empty($apercu) ? '#' : route('connexion');
@endphp
<title>{{ $nomEspace }}</title>
<style>
    .conteneur { width: min(1160px, 100% - 32px); margin: 0 auto; }
    header.haut { position: sticky; top: 0; z-index: 20; background: rgba(255,255,255,.92); backdrop-filter: blur(10px); border-bottom: 1px solid var(--bord); }
    header.haut .conteneur { display: flex; align-items: center; justify-content: space-between; gap: 16px; height: 70px; }
    .marque { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 18px; text-decoration: none; }
    .marque img { height: 38px; max-width: 180px; object-fit: contain; }
    nav.liens { display: flex; gap: 26px; font-size: 14.5px; font-weight: 600; color: var(--doux); }
    nav.liens a { text-decoration: none; }
    nav.liens a:hover { color: var(--principale); }
    .bouton { display: inline-flex; align-items: center; gap: 9px; padding: 13px 24px; border-radius: 12px; font-weight: 700; font-size: 15px; text-decoration: none; border: 0; cursor: pointer; transition: transform .15s, box-shadow .15s, background .15s; }
    .bouton-plein { background: var(--principale); color: #fff; box-shadow: 0 10px 24px -10px var(--principale); }
    .bouton-plein:hover { background: var(--principale-fonce); transform: translateY(-1px); }
    .bouton-clair { background: #fff; color: var(--principale); border: 1px solid var(--bord); }
    .bouton-petit { padding: 10px 18px; font-size: 14px; }

    /* Horizon */
    .hero-horizon { background: linear-gradient(180deg, var(--principale-pale), #fff 75%); padding: 84px 0 70px; }
    .hero-horizon .conteneur { display: grid; grid-template-columns: 1.1fr .9fr; gap: 56px; align-items: center; }
    .hero-horizon h1 { font-size: clamp(34px, 4.6vw, 56px); line-height: 1.08; letter-spacing: -.02em; margin: 0 0 18px; color: var(--secondaire); }
    .hero-horizon p.chapeau { font-size: 18px; line-height: 1.65; color: #475569; margin: 0 0 30px; max-width: 560px; }
    .visuel { border-radius: 24px; overflow: hidden; aspect-ratio: 4/3.2; box-shadow: 0 40px 80px -40px rgba(15,23,42,.45); background: linear-gradient(135deg, var(--principale), var(--secondaire)); display: grid; place-items: center; position: relative; }
    .visuel img.photo { width: 100%; height: 100%; object-fit: cover; }
    .visuel img.logo-visuel { width: 46%; max-height: 46%; object-fit: contain; filter: drop-shadow(0 18px 30px rgba(0,0,0,.25)); }

    /* Immersion */
    .hero-immersion { position: relative; min-height: 86vh; display: grid; place-items: center; text-align: center; color: #fff; padding: 110px 0; background: linear-gradient(140deg, var(--principale), var(--secondaire)); overflow: hidden; }
    .hero-immersion .fond { position: absolute; inset: 0; background-size: cover; background-position: center; }
    .hero-immersion .voile { position: absolute; inset: 0; background: linear-gradient(180deg, rgba(2,6,23,.35), rgba(2,6,23,.72)); }
    .hero-immersion .contenu { position: relative; max-width: 820px; padding: 0 16px; }
    .hero-immersion img.logo-hero { height: 70px; max-width: 260px; object-fit: contain; margin-bottom: 26px; }
    .hero-immersion h1 { font-size: clamp(36px, 5.4vw, 66px); line-height: 1.05; letter-spacing: -.02em; margin: 0 0 18px; }
    .hero-immersion p.chapeau { font-size: 19px; line-height: 1.6; opacity: .9; margin: 0 auto 32px; max-width: 640px; }

    /* Épure */
    .hero-epure { padding: 110px 0 80px; text-align: center; border-bottom: 1px solid var(--bord); }
    .hero-epure .trait { width: 64px; height: 5px; border-radius: 4px; background: var(--principale); margin: 0 auto 30px; }
    .hero-epure h1 { font-size: clamp(36px, 5vw, 62px); font-weight: 800; letter-spacing: -.03em; line-height: 1.05; margin: 0 auto 20px; max-width: 900px; color: var(--secondaire); }
    .hero-epure p.chapeau { font-size: 18.5px; color: #475569; line-height: 1.65; max-width: 640px; margin: 0 auto 32px; }

    section.bloc { padding: 84px 0; }
    section.bloc h2 { font-size: clamp(26px, 3vw, 36px); letter-spacing: -.01em; margin: 0 0 12px; color: var(--secondaire); }
    section.bloc p.intro { color: var(--doux); font-size: 16.5px; margin: 0 0 40px; max-width: 640px; line-height: 1.6; }
    .atouts { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; }
    .atout { border: 1px solid var(--bord); border-radius: 18px; padding: 26px; background: #fff; transition: transform .2s, box-shadow .2s; }
    .atout:hover { transform: translateY(-4px); box-shadow: 0 24px 50px -30px rgba(15,23,42,.35); }
    .atout .pastille { width: 46px; height: 46px; border-radius: 13px; display: grid; place-items: center; background: var(--principale-pale); color: var(--principale); font-size: 19px; margin-bottom: 16px; }
    .atout h3 { margin: 0 0 8px; font-size: 17px; }
    .atout p { margin: 0; color: var(--doux); line-height: 1.6; font-size: 14.5px; }
    .presentation { background: #f8fafc; }
    .presentation .texte { font-size: 17px; line-height: 1.8; color: #334155; white-space: pre-line; max-width: 820px; }
    .contact { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
    .contact div { display: flex; gap: 12px; align-items: center; padding: 18px; border-radius: 14px; border: 1px solid var(--bord); font-weight: 600; }
    .contact i { color: var(--principale); font-size: 18px; width: 22px; text-align: center; }
    .appel { background: linear-gradient(135deg, var(--principale), var(--secondaire)); color: #fff; border-radius: 26px; padding: 54px 40px; text-align: center; }
    .appel h2 { color: #fff !important; }
    .appel .bouton-plein { background: #fff; color: var(--principale); }
    footer.bas { border-top: 1px solid var(--bord); }
    footer.bas .conteneur { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding: 22px 0; font-size: 13.5px; color: var(--doux); }

    @media (max-width: 860px) {
        nav.liens { display: none; }
        .hero-horizon .conteneur { grid-template-columns: 1fr; gap: 36px; }
        .hero-horizon { padding: 54px 0; }
        section.bloc { padding: 60px 0; }
        .appel { padding: 40px 22px; }
    }
</style>
</head>
<body>
@if(!empty($apercu))
    <div class="bandeau-apercu">Aperçu de la page d'accueil de {{ $nomEspace }}{{ $espace->actif ? '' : ' (espace pas encore actif)' }}</div>
@endif

<header class="haut">
    <div class="conteneur">
        <a class="marque" href="{{ !empty($apercu) ? '#' : url('/') }}">
            @if($logo)<img src="{{ $logo }}" alt="{{ $nomEspace }}">@else<span>{{ $nomEspace }}</span>@endif
        </a>
        <nav class="liens">
            <a href="#atouts">Nos atouts</a>
            @if($espace->presentation)<a href="#presentation">Présentation</a>@endif
            <a href="#contact">Contact</a>
        </nav>
        <a class="bouton bouton-plein bouton-petit" href="{{ $lienConnexion }}"><i class="fas fa-right-to-bracket"></i> Se connecter</a>
    </div>
</header>

@if($modele === 'immersion')
    <section class="hero-immersion">
        @if($fond)<div class="fond" style="background-image:url('{{ $fond }}')"></div>@endif
        <div class="voile"></div>
        <div class="contenu">
            @if($logoBlanc)<img class="logo-hero" src="{{ $logoBlanc }}" alt="">@endif
            <h1>{{ $titre }}</h1>
            <p class="chapeau">{{ $sousTitre }}</p>
            <a class="bouton bouton-plein" href="{{ $lienConnexion }}" style="background:#fff; color:var(--principale);">Accéder à mon espace <i class="fas fa-arrow-right"></i></a>
        </div>
    </section>
@elseif($modele === 'epure')
    <section class="hero-epure">
        <div class="conteneur">
            <div class="trait"></div>
            <h1>{{ $titre }}</h1>
            <p class="chapeau">{{ $sousTitre }}</p>
            <a class="bouton bouton-plein" href="{{ $lienConnexion }}">Accéder à mon espace <i class="fas fa-arrow-right"></i></a>
        </div>
    </section>
@else
    <section class="hero-horizon">
        <div class="conteneur">
            <div>
                <h1>{{ $titre }}</h1>
                <p class="chapeau">{{ $sousTitre }}</p>
                <div style="display:flex; gap:12px; flex-wrap:wrap;">
                    <a class="bouton bouton-plein" href="{{ $lienConnexion }}">Accéder à mon espace <i class="fas fa-arrow-right"></i></a>
                    <a class="bouton bouton-clair" href="#contact">Nous contacter</a>
                </div>
            </div>
            <div class="visuel">
                @if($fond)
                    <img class="photo" src="{{ $fond }}" alt="">
                @elseif($logoBlanc)
                    <img class="logo-visuel" src="{{ $logoBlanc }}" alt="">
                @else
                    <span style="color:#fff; font-size:clamp(28px,3.4vw,44px); font-weight:800; letter-spacing:-.01em; text-align:center; padding:24px; text-shadow:0 10px 30px rgba(0,0,0,.25);">{{ $nomEspace }}</span>
                @endif
            </div>
        </div>
    </section>
@endif

<section class="bloc" id="atouts">
    <div class="conteneur">
        <h2>Nos atouts</h2>
        <p class="intro">Tout ce qu'il faut pour travailler au quotidien, réuni dans un seul espace.</p>
        <div class="atouts">
            @foreach($atouts as $i => $atout)
                <div class="atout">
                    <div class="pastille"><i class="fas {{ $icones[$i % count($icones)] }}"></i></div>
                    <h3>{{ $atout['titre'] }}</h3>
                    @if(!empty($atout['texte']))<p>{{ $atout['texte'] }}</p>@endif
                </div>
            @endforeach
        </div>
    </div>
</section>

@if($espace->presentation)
    <section class="bloc presentation" id="presentation">
        <div class="conteneur">
            <h2>Présentation</h2>
            <div class="texte">{{ $espace->presentation }}</div>
        </div>
    </section>
@endif

<section class="bloc" id="contact">
    <div class="conteneur">
        @if($espace->telephone || $espace->email || $espace->adresse)
            <h2>Contact</h2>
            <p class="intro">Une question ? Nous sommes à votre écoute.</p>
            <div class="contact" style="margin-bottom:56px;">
                @if($espace->telephone)<div><i class="fas fa-phone"></i> {{ $espace->telephone }}</div>@endif
                @if($espace->email)<div><i class="fas fa-envelope"></i> {{ $espace->email }}</div>@endif
                @if($espace->adresse)<div><i class="fas fa-location-dot"></i> {{ $espace->adresse }}</div>@endif
            </div>
        @endif
        <div class="appel">
            <h2>Votre espace vous attend</h2>
            <p style="opacity:.9; margin:0 0 26px; font-size:16.5px;">Connectez-vous avec les identifiants remis par {{ $nomEspace }}.</p>
            <a class="bouton bouton-plein" href="{{ $lienConnexion }}"><i class="fas fa-right-to-bracket"></i> Se connecter</a>
        </div>
    </div>
</section>

<footer class="bas">
    <div class="conteneur">
        <span>&copy; {{ date('Y') }} {{ $nomEspace }}</span>
        <span>Propulsé par <strong style="color:var(--texte);">DC-KNOWING</strong></span>
    </div>
</footer>
</body>
</html>

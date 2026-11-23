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
                    <input class="form-control" id="sous_domaine" name="sous_domaine" value="{{ old('sous_domaine', $espace->sous_domaine) }}" placeholder="formen">
                    <span>.{{ $racine }}</span>
                </div>
                <small>Immédiat une fois le sous-domaine générique en place sur le serveur (voir ci-dessous).</small>
            </div>
            <div class="sm-champ">
                <label for="domaine">Domaine propre</label>
                <input class="form-control" id="domaine" name="domaine" value="{{ old('domaine', $espace->domaine) }}" placeholder="gestion.formen.ci">
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

        <h4 style="margin:22px 0 10px;">Marche à suivre <a href="{{ route('superadmin.sur_mesure.procedure') }}" target="_blank" style="font-size:12px; font-weight:600; margin-left:6px;">procédure complète et différences</a></h4>
        @include('admin::superadmin.sur-mesure._procedure', [
            'compact' => true,
            'sousDomaine' => old('sous_domaine', $espace->sous_domaine),
            'domaine' => old('domaine', $espace->domaine),
        ])
        @if($espace->exists && $espace->adresses())
            <button type="submit" form="form-verifier" class="btn btn-outline" style="margin-top:14px;"><i class="fas fa-stethoscope"></i> Vérifier les adresses</button>
        @endif
    </div>

    {{-- ── Identité : chaque zone numérotée, et l'aperçu qui porte les mêmes numéros ── --}}
    @php
        $menuAuto = old('menu_auto', $espace->couleur_menu ? '0' : '1') === '1';
        $accentAuto = old('accent_auto', $espace->couleur_menu_accent ? '0' : '1') === '1';
        $couleurMenu = old('couleur_menu', $espace->couleur_menu ?: $espace->ombre($couleurP, .45));
        $couleurAccent = old('couleur_menu_accent', $espace->couleur_menu_accent ?: $espace->teinte($couleurP, .55));
        $nomEntreprise = $entreprise->nom;
        $zonesCouleur = [
            ['n' => 1, 'nom' => 'couleur_menu', 'titre' => 'Fond du menu latéral', 'ou' => 'La colonne de gauche de l\'application, sur toute sa hauteur.', 'valeur' => $couleurMenu, 'auto' => 'menu_auto', 'estAuto' => $menuAuto],
            ['n' => 2, 'nom' => 'couleur_menu_accent', 'titre' => 'Élément actif du menu', 'ou' => 'L\'icône et le trait de la page ouverte, le nom du point de vente, la période.', 'valeur' => $couleurAccent, 'auto' => 'accent_auto', 'estAuto' => $accentAuto],
            ['n' => 3, 'nom' => 'couleur_principale', 'titre' => 'Boutons et liens', 'ou' => 'Boutons « Ajouter », « Enregistrer », liens, onglets, cases cochées, en-têtes des tableaux. Aussi les boutons de l\'accueil et de la connexion.', 'valeur' => $couleurP],
            ['n' => 4, 'nom' => 'couleur_secondaire', 'titre' => 'Titres des pages', 'ou' => 'Le grand titre de chaque page. Sur l\'accueil et la connexion : le fond dégradé, avec la couleur ③.', 'valeur' => $couleurS],
        ];
        $zonesImage = [
            ['n' => 5, 'champ' => 'logo_blanc', 'titre' => 'Logo blanc', 'ou' => 'En haut du menu latéral, et sur les fonds foncés de l\'accueil et de la connexion.', 'sombre' => true],
            ['n' => 6, 'champ' => 'logo', 'titre' => 'Logo couleur', 'ou' => 'En haut de la page d\'accueil, et au-dessus du formulaire de connexion.', 'sombre' => false],
            ['n' => 7, 'champ' => 'icone', 'titre' => 'Icône d\'onglet', 'ou' => 'Le petit carré dans l\'onglet du navigateur. Carré, 64 × 64 px conseillé.', 'sombre' => false],
            ['n' => 8, 'champ' => 'image_fond', 'titre' => 'Photo', 'ou' => 'Le grand visuel de l\'accueil et le côté gauche (ou le fond) de la connexion. Ex. : le salon, une coupe.', 'sombre' => false],
        ];
    @endphp
    <style>
        .zid { display:grid; grid-template-columns:minmax(300px, 1fr) minmax(360px, 1.1fr); gap:22px; align-items:start; }
        @media (max-width: 1100px) { .zid { grid-template-columns:1fr; } }
        .zone { display:flex; gap:12px; padding:12px; border:1px solid #e2e8f0; border-radius:12px; margin-bottom:10px; background:#fff; }
        .zone .num, .mq .num { width:24px; height:24px; border-radius:50%; background:#f59e0b; color:#111; font-size:12px; font-weight:800; display:grid; place-items:center; flex-shrink:0; box-shadow:0 0 0 2px #fff; }
        .zone h5 { margin:0; font-size:14px; }
        .zone p { margin:2px 0 8px; font-size:12.5px; color:#64748b; line-height:1.5; }
        .zone input[type=color] { width:46px; height:34px; border:0; background:none; cursor:pointer; padding:0; }
        .zone .auto { font-size:12px; color:#475569; display:inline-flex; gap:5px; align-items:center; margin-left:10px; }
        .mq { position:sticky; top:80px; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; background:#f4f6f9; font-family:Inter, sans-serif; box-shadow:0 20px 50px -30px rgba(15,23,42,.4); }
        .mq-cadre { display:grid; grid-template-columns:150px 1fr; min-height:330px; }
        .mq-menu { background:var(--mq-menu); padding:12px 10px; color:#fff; position:relative; }
        .mq-logo { display:flex; align-items:center; gap:7px; font-weight:800; font-size:12px; margin-bottom:12px; }
        .mq-logo .carre { width:24px; height:24px; border-radius:6px; background:rgba(255,255,255,.15); background-size:contain; background-repeat:no-repeat; background-position:center; }
        .mq-pdv { border:1px solid rgba(255,255,255,.18); border-radius:8px; padding:6px 8px; font-size:10px; margin-bottom:10px; }
        .mq-pdv b { color:var(--mq-accent); display:block; font-size:11px; }
        .mq-item { font-size:11px; padding:6px 8px; border-radius:6px; opacity:.8; display:flex; gap:6px; align-items:center; }
        .mq-item.actif { opacity:1; background:rgba(255,255,255,.1); box-shadow:inset 3px 0 0 var(--mq-accent); }
        .mq-item.actif i { color:var(--mq-accent); }
        .mq-page { padding:14px; position:relative; }
        .mq-barre { height:30px; background:#fff; border-radius:8px; margin-bottom:12px; display:flex; align-items:center; padding:0 10px; font-size:11px; font-weight:700; color:#334155; }
        .mq-titre { font-size:17px; font-weight:800; color:var(--mq-titre); margin-bottom:10px; display:flex; align-items:center; gap:8px; }
        .mq-bouton { display:inline-block; background:var(--mq-principale); color:#fff; font-size:11px; font-weight:700; padding:7px 12px; border-radius:7px; }
        .mq-lien { color:var(--mq-principale); font-size:11px; font-weight:700; margin-left:10px; }
        .mq-table { margin-top:12px; background:#fff; border-radius:8px; overflow:hidden; font-size:10.5px; }
        .mq-table div { padding:7px 10px; border-bottom:1px solid #eef2f7; display:flex; justify-content:space-between; }
        .mq-table .tete { background:var(--mq-pale); font-weight:800; color:#475569; }
        .mq .num { position:absolute; }
        .mq-pages { display:grid; grid-template-columns:1fr 1fr; gap:10px; padding:10px; border-top:1px solid #e2e8f0; background:#fff; }
        .mq-mini { border-radius:10px; height:120px; position:relative; overflow:hidden; background:linear-gradient(140deg, var(--mq-principale), var(--mq-titre)); background-size:cover; background-position:center; }
        .mq-mini .voile { position:absolute; inset:0; background:rgba(2,6,23,.35); }
        .mq-mini .carte { position:absolute; right:8px; top:12px; bottom:12px; width:46%; background:#fff; border-radius:8px; padding:8px; }
        .mq-mini .carte .l { height:16px; background-size:contain; background-repeat:no-repeat; margin-bottom:6px; font-size:8.5px; font-weight:800; color:#0f172a; }
        .mq-mini .carte .b { height:14px; border-radius:4px; background:var(--mq-principale); margin-top:20px; }
        .mq-mini .lb { position:absolute; left:10px; top:10px; height:18px; width:70px; background-size:contain; background-repeat:no-repeat; color:#fff; font-size:9px; font-weight:800; }
        .mq-legende { font-size:10.5px; color:#64748b; text-align:center; margin-top:4px; }
    </style>

    <div class="card sm-volet" data-volet="identite" style="padding:20px;">
        <h3 style="margin-top:0;">Identité visuelle : zone par zone</h3>
        <p style="color:#64748b; font-size:13.5px; margin-top:-4px;">Chaque réglage porte un numéro. Le même numéro, sur l'aperçu à droite, montre l'endroit exact qui change. L'aperçu suit vos choix en direct. Ces couleurs s'appliquent quand le module « Couleurs et logo dans l'application » est coché.</p>

        <div class="zid">
            <div>
                <h4 style="margin:6px 0 10px; font-size:13px; text-transform:uppercase; color:#475569;">Couleurs</h4>
                @foreach($zonesCouleur as $z)
                    <div class="zone">
                        <span class="num">{{ $z['n'] }}</span>
                        <div style="flex:1;">
                            <h5>{{ $z['titre'] }}</h5>
                            <p>{{ $z['ou'] }}</p>
                            <div style="display:flex; align-items:center; flex-wrap:wrap; gap:6px;">
                                <input type="color" name="{{ $z['nom'] }}" value="{{ $z['valeur'] }}" data-zone="{{ $z['nom'] }}">
                                <code data-code="{{ $z['nom'] }}">{{ $z['valeur'] }}</code>
                                @isset($z['auto'])
                                    <input type="hidden" name="{{ $z['auto'] }}" value="0">
                                    <label class="auto"><input type="checkbox" name="{{ $z['auto'] }}" value="1" data-auto="{{ $z['nom'] }}" {{ $z['estAuto'] ? 'checked' : '' }}> Automatique (tirée de ③)</label>
                                @endisset
                            </div>
                        </div>
                    </div>
                @endforeach

                <h4 style="margin:18px 0 10px; font-size:13px; text-transform:uppercase; color:#475569;">Logos et images</h4>
                @foreach($zonesImage as $z)
                    @php $champ = $z['champ']; @endphp
                    <div class="zone">
                        <span class="num">{{ $z['n'] }}</span>
                        <div style="flex:1; min-width:0;">
                            <h5>{{ $z['titre'] }}</h5>
                            <p>{{ $z['ou'] }}</p>
                            <div class="sm-image" style="padding:8px;">
                                <div class="vignette {{ $z['sombre'] ? 'sombre' : '' }}" data-vignette="{{ $champ }}" @if($espace->{$champ}) style="background-image:url('{{ $espace->media($champ) }}'); {{ $champ === 'image_fond' ? 'background-size:cover;' : '' }}" @endif></div>
                                <div style="flex:1; min-width:0;">
                                    <input type="file" name="{{ $champ }}" accept="image/png,image/jpeg,image/webp" data-fichier="{{ $champ }}" style="font-size:12.5px; max-width:100%;">
                                    @if($espace->{$champ})
                                        <label style="font-weight:500; font-size:12px; margin-top:6px; display:flex; gap:6px; align-items:center;"><input type="checkbox" name="retirer[]" value="{{ $champ }}"> Retirer</label>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
                <small style="display:block; color:#64748b;">PNG, JPEG ou WebP (pas de SVG). Logos sur fond transparent de préférence.</small>
            </div>

            {{-- L'aperçu --}}
            <div class="mq" id="maquette" style="--mq-menu:{{ $couleurMenu }}; --mq-accent:{{ $couleurAccent }}; --mq-principale:{{ $couleurP }}; --mq-titre:{{ $couleurS }}; --mq-pale:{{ $espace->teinte($couleurP, .9) }};">
                <div class="mq-cadre">
                    <div class="mq-menu">
                        <span class="num" style="left:12px; bottom:12px;">1</span>
                        <div class="mq-logo">
                            <span class="carre" data-apercu="logo_blanc" @if($espace->logo_blanc) style="background-image:url('{{ $espace->media('logo_blanc') }}')" @endif></span>
                            <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $nomEntreprise }}</span>
                            <span class="num" style="left:2px; top:2px;">5</span>
                        </div>
                        <div class="mq-pdv">POINT DE VENTE<b>Salon Cocody</b></div>
                        <div class="mq-item"><i class="fas fa-gauge-high"></i> Tableau de bord</div>
                        <div class="mq-item actif" style="position:relative;"><i class="fas fa-users"></i> Clients <span class="num" style="right:-4px; top:2px; left:auto;">2</span></div>
                        <div class="mq-item"><i class="fas fa-cart-shopping"></i> Ventes</div>
                        <div class="mq-item"><i class="fas fa-scissors"></i> Prestations</div>
                        <div class="mq-item"><i class="fas fa-wallet"></i> Trésorerie</div>
                    </div>
                    <div class="mq-page">
                        <div class="mq-barre">Clients</div>
                        <div class="mq-titre" style="position:relative;"><i class="fas fa-users" style="color:var(--mq-principale);"></i> Liste des clients <span class="num" style="position:static;">4</span></div>
                        <div style="display:flex; align-items:center; gap:8px;"><span class="mq-bouton">+ Ajouter un client</span><span class="mq-lien" style="margin-left:0;">Voir la fiche</span><span class="num" style="position:static;">3</span></div>
                        <div class="mq-table">
                            <div class="tete"><span>NOM</span><span>STYLE HABITUEL</span></div>
                            <div><span>Kouamé Yao</span><span>Dégradé américain</span></div>
                            <div><span>Aya Koné</span><span>Tresses collées</span></div>
                        </div>
                    </div>
                </div>
                <div class="mq-pages">
                    <div>
                        <div class="mq-mini" data-apercu="image_fond" @if($espace->image_fond) style="background-image:url('{{ $espace->media('image_fond') }}')" @endif>
                            <div class="voile"></div>
                            <div class="lb" data-apercu="logo_blanc2" @if($espace->logo_blanc) style="background-image:url('{{ $espace->media('logo_blanc') }}')" @endif>{{ $espace->logo_blanc ? '' : $nomEntreprise }}</div>
                            <div class="carte"><div class="l" data-apercu="logo" @if($espace->logo) style="background-image:url('{{ $espace->media('logo') }}')" @endif>{{ $espace->logo ? '' : $nomEntreprise }}</div><div class="b"></div></div>
                            <span class="num" style="left:8px; bottom:8px;">8</span>
                            <span class="num" style="right:12px; top:4px;">6</span>
                        </div>
                        <div class="mq-legende">Page de connexion</div>
                    </div>
                    <div>
                        <div style="height:120px; border-radius:10px; border:1px solid #e2e8f0; position:relative; background:#fff; padding:10px;">
                            <div style="display:flex; align-items:center; gap:6px; background:#f1f5f9; border-radius:6px 6px 0 0; padding:4px 6px; font-size:9px; width:70%;">
                                <span data-apercu="icone" style="width:12px; height:12px; border-radius:3px; background:{{ $couleurP }} center/contain no-repeat; {{ $espace->icone ? "background-image:url('" . $espace->media('icone') . "')" : '' }}"></span>
                                {{ $nomEntreprise }}
                            </div>
                            <span class="num" style="left:82%; top:6px;">7</span>
                            <div style="font-size:9.5px; color:#64748b; margin-top:10px; line-height:1.5;">L'onglet du navigateur affiche l'icône ⑦ à côté du nom de l'entreprise.</div>
                        </div>
                        <div class="mq-legende">Onglet du navigateur</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var m = document.getElementById('maquette');
        var correspond = { couleur_menu: '--mq-menu', couleur_menu_accent: '--mq-accent', couleur_principale: '--mq-principale', couleur_secondaire: '--mq-titre' };
        function rgb(hex) { var n = parseInt(hex.slice(1), 16); return [n >> 16, (n >> 8) & 255, n & 255]; }
        function clair(hex, p) { return 'rgb(' + rgb(hex).map(function (c) { return Math.round(c + (255 - c) * p); }).join(',') + ')'; }
        function fonce(hex, p) { return 'rgb(' + rgb(hex).map(function (c) { return Math.round(c * (1 - p)); }).join(',') + ')'; }
        function auto(nom) { var c = document.querySelector('[data-auto="' + nom + '"]'); return c && c.checked; }
        function rafraichir() {
            var p = document.querySelector('[data-zone="couleur_principale"]').value;
            Object.keys(correspond).forEach(function (nom) {
                var champ = document.querySelector('[data-zone="' + nom + '"]');
                var v = champ.value;
                if (nom === 'couleur_menu' && auto(nom)) { v = fonce(p, .45); }
                if (nom === 'couleur_menu_accent' && auto(nom)) { v = clair(p, .55); }
                champ.disabled = (nom === 'couleur_menu' || nom === 'couleur_menu_accent') && auto(nom);
                champ.style.opacity = champ.disabled ? .4 : 1;
                m.style.setProperty(correspond[nom], v);
                var code = document.querySelector('[data-code="' + nom + '"]'); if (code) code.textContent = champ.disabled ? 'automatique' : champ.value;
            });
            m.style.setProperty('--mq-pale', clair(p, .9));
        }
        document.querySelectorAll('[data-zone], [data-auto]').forEach(function (e) { e.addEventListener('input', rafraichir); e.addEventListener('change', rafraichir); });
        document.querySelectorAll('[data-fichier]').forEach(function (champ) {
            champ.addEventListener('change', function () {
                if (!champ.files || !champ.files[0]) return;
                var url = URL.createObjectURL(champ.files[0]), nom = champ.dataset.fichier;
                var cibles = document.querySelectorAll('[data-vignette="' + nom + '"], [data-apercu="' + nom + '"]' + (nom === 'logo_blanc' ? ', [data-apercu="logo_blanc2"]' : ''));
                cibles.forEach(function (c) { c.style.backgroundImage = "url('" + url + "')"; if (c.dataset.apercu === 'logo' || c.dataset.apercu === 'logo_blanc2') c.textContent = ''; });
            });
        });
        rafraichir();
    })();
    </script>

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
                    <span style="flex:1;"><strong>{{ $libelle }}</strong><br><span style="color:#64748b; font-size:13px;">{{ $aide }}</span>
                        @if($cle === 'facture_email')
                            <span style="display:flex; gap:8px; align-items:center; margin-top:8px; flex-wrap:wrap; font-size:13px;">
                                Envoi :
                                <select name="facture_email_moment" class="form-control" style="max-width:360px;" onclick="event.stopPropagation()">
                                    @foreach(EspaceSurMesure::MOMENTS_FACTURE_EMAIL as $m => $lib)
                                        <option value="{{ $m }}" @selected(old('facture_email_moment', $espace->facture_email_moment ?: 'emission') === $m)>{{ $lib }}</option>
                                    @endforeach
                                </select>
                            </span>
                            <span style="display:block; color:#64748b; font-size:12px; margin-top:4px;">Le client doit avoir une adresse e-mail sur sa fiche. Un bouton « E-mail » permet aussi de l'envoyer à la main depuis la liste des factures.</span>
                        @endif
                    </span>
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

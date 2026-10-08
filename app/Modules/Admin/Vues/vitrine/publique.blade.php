{{--
    La page d'accueil de DC-Knowing.

    Le contenu vient de la base, saisi depuis l'écran superadmin : cette page
    n'écrit aucun texte de présentation. Elle sait dessiner neuf dispositions,
    animer l'entrée de chacune au défilement, et s'arrête là.

    **Les animations respectent `prefers-reduced-motion`.** Un mouvement qu'on
    n'a pas demandé provoque des vertiges chez une partie des visiteurs ; le
    système d'exploitation le dit, et la page l'écoute.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Selflow — DC-Knowing</title>
    @include('partials.icones-selflow')
    <meta name="description" content="Selflow, la gestion commerciale conforme à la facture normalisée électronique de la DGI. Une application DC-Knowing.">
    <link rel="stylesheet" href="{{ asset('vendor/inter/inter.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primary:   #002B5C;
            --primary-d: #001F42;
            --primary-l: #0A4A93;
            --accent:    #f59e0b;
            --vert:      #10b981;
            --bg:        #F4F6F9;
            --surface:   #ffffff;
            --border:    #E2E8F0;
            --text:      #1E293B;
            --text-2:    #475569;
            --text-3:    #94a3b8;
            --radius:    14px;
            --shadow:    0 10px 30px rgba(0, 43, 92, .06);
            --shadow-h:  0 18px 44px rgba(0, 43, 92, .13);
            --max:       1140px;
        }

        html { scroll-behavior: smooth; scroll-padding-top: 76px; }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: var(--bg); color: var(--text);
            font-size: 15.5px; line-height: 1.65;
            overflow-x: hidden;
        }
        img { max-width: 100%; display: block; }

        /* ══════════ L'entrée au défilement ══════════ */
        .apparait {
            opacity: 0; transform: translateY(26px);
            transition: opacity .7s cubic-bezier(.16,1,.3,1), transform .7s cubic-bezier(.16,1,.3,1);
        }
        .apparait.vu { opacity: 1; transform: none; }
        /* Le décalage donne le sentiment que la section se compose sous l'œil,
           au lieu d'apparaître d'un bloc. */
        .apparait[data-retard="1"] { transition-delay: .08s; }
        .apparait[data-retard="2"] { transition-delay: .16s; }
        .apparait[data-retard="3"] { transition-delay: .24s; }
        .apparait[data-retard="4"] { transition-delay: .32s; }
        .apparait[data-retard="5"] { transition-delay: .40s; }

        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            .apparait, .apparait.vu { opacity: 1; transform: none; transition: none; }
            .piste, .pastille-fne, .onde, .flotte { animation: none !important; }
        }

        /* ══════════ La barre du haut ══════════ */
        .entete {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,255,255,.86);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid transparent;
            transition: border-color .3s, box-shadow .3s;
        }
        .entete.pose { border-bottom-color: var(--border); box-shadow: 0 4px 18px rgba(0,43,92,.05); }
        .entete .dedans {
            max-width: var(--max); margin: 0 auto; padding: 13px 24px;
            display: flex; align-items: center; gap: 18px;
        }
        .marque { display: flex; align-items: center; gap: 10px; text-decoration: none; margin-right: auto; }
        .marque .pastille {
            width: 36px; height: 36px; border-radius: 10px;
            background: linear-gradient(135deg, var(--primary), var(--primary-l));
            color: #fff; display: flex; align-items: center; justify-content: center;
            font-weight: 900; font-size: 17px;
        }
        .marque .nom { font-weight: 800; color: var(--primary); font-size: 17.5px; letter-spacing: -.02em; line-height: 1.1; }
        .marque .sous { font-size: 10.5px; color: var(--text-3); letter-spacing: .04em; text-transform: uppercase; }

        .menu { display: flex; align-items: center; gap: 4px; }
        .menu a {
            padding: 8px 13px; border-radius: 8px; text-decoration: none;
            color: var(--text-2); font-size: 13.5px; font-weight: 500;
        }
        .menu a:hover { background: var(--bg); color: var(--primary); }

        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 11px 20px; border-radius: 10px;
            border: 1px solid var(--border); background: var(--surface); color: var(--text-2);
            font-size: 14px; font-weight: 600; text-decoration: none; cursor: pointer;
            font-family: inherit; transition: transform .18s, box-shadow .18s, background .18s;
            white-space: nowrap;
        }
        .btn:hover { background: var(--bg); color: var(--primary); transform: translateY(-1px); }
        .btn-principal {
            background: var(--primary); border-color: var(--primary); color: #fff;
            box-shadow: 0 6px 18px rgba(0,43,92,.22);
        }
        .btn-principal:hover { background: var(--primary-d); color: #fff; box-shadow: var(--shadow-h); }
        /* Dans la barre du haut, « .menu a » (plus précis) imposait son gris
           au texte du bouton : « Créer un compte » restait sombre sur bleu nuit. */
        .menu a.btn-principal, .menu a.btn-principal:hover { color: #fff; }
        .menu a.btn-principal { background: var(--primary-l); border-color: var(--primary-l); }
        .menu a.btn-principal:hover { background: var(--primary); }
        .btn-clair { background: rgba(255,255,255,.12); border-color: rgba(255,255,255,.28); color: #fff; }
        .btn-clair:hover { background: rgba(255,255,255,.2); color: #fff; }

        .burger { display: none; background: none; border: 0; font-size: 20px; color: var(--primary); cursor: pointer; padding: 6px; }

        /* ══════════ Les sections ══════════ */
        .section { padding: 84px 24px; }
        .section.fond-blanc { background: var(--surface); }
        .section.fond-sombre {
            background: linear-gradient(160deg, var(--primary) 0%, var(--primary-d) 100%);
            color: #fff;
        }
        .section.fond-sombre .sous, .section.fond-sombre .texte-section { color: rgba(255,255,255,.76); }
        .section.fond-sombre h2 { color: #fff; }
        .section.fond-sombre .carte {
            background: rgba(255,255,255,.06); border-color: rgba(255,255,255,.14); color: #fff;
        }
        .section.fond-sombre .carte h3 { color: #fff; }
        .section.fond-sombre .carte p, .section.fond-sombre .carte .mention { color: rgba(255,255,255,.72); }
        .section.fond-sombre .etiquette { background: rgba(255,255,255,.12); color: #fff; }

        .dedans { max-width: var(--max); margin: 0 auto; }
        .section-tete { text-align: center; max-width: 720px; margin: 0 auto 46px; }
        .section-tete h2 {
            font-size: clamp(26px, 4vw, 38px); font-weight: 800; color: var(--primary);
            letter-spacing: -.025em; line-height: 1.18;
        }
        .section-tete .sous { font-size: 16.5px; color: var(--text-2); margin-top: 12px; }
        .texte-section { font-size: 15.5px; color: var(--text-2); margin-top: 14px; white-space: pre-line; }

        .surtitre {
            display: inline-flex; align-items: center; gap: 7px;
            font-size: 12px; font-weight: 700; letter-spacing: .09em; text-transform: uppercase;
            color: var(--primary-l); background: rgba(10,74,147,.08);
            padding: 6px 13px; border-radius: 100px; margin-bottom: 14px;
        }
        .fond-sombre .surtitre { color: #fff; background: rgba(255,255,255,.13); }

        /* ══════════ Le bandeau d'ouverture ══════════ */
        .bandeau {
            position: relative; overflow: hidden;
            background: linear-gradient(155deg, var(--primary) 0%, var(--primary-d) 62%, #001430 100%);
            color: #fff; padding: 96px 24px 104px;
        }
        .bandeau::before {
            content: ''; position: absolute; inset: 0;
            background:
                radial-gradient(900px 420px at 12% -8%, rgba(245,158,11,.20), transparent 62%),
                radial-gradient(760px 420px at 92% 108%, rgba(16,185,129,.18), transparent 60%);
            pointer-events: none;
        }
        .bandeau .dedans {
            position: relative; display: grid; grid-template-columns: 1.06fr .94fr;
            gap: 54px; align-items: center;
        }
        .bandeau h1 {
            font-size: clamp(34px, 5.4vw, 56px); font-weight: 900; line-height: 1.06;
            letter-spacing: -.035em;
        }
        .bandeau .sous { font-size: clamp(16px, 2vw, 19.5px); color: rgba(255,255,255,.82); margin-top: 18px; max-width: 32em; }
        .bandeau .texte { color: rgba(255,255,255,.68); margin-top: 14px; max-width: 34em; white-space: pre-line; }
        .bandeau .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 30px; }

        /* ══════════ La facture qui part à la DGI ══════════ */
        .scene {
            position: relative; display: grid; place-items: center; min-height: 340px;
        }
        .onde {
            position: absolute; width: 260px; height: 260px; border-radius: 50%;
            border: 1px solid rgba(255,255,255,.16);
            animation: onde 4.2s ease-out infinite;
        }
        .onde:nth-child(2) { animation-delay: 1.4s; }
        .onde:nth-child(3) { animation-delay: 2.8s; }
        @keyframes onde {
            0%   { transform: scale(.55); opacity: .9; }
            100% { transform: scale(1.55); opacity: 0; }
        }
        .facture {
            position: relative; width: min(300px, 78vw);
            background: #fff; color: var(--text);
            border-radius: 14px; padding: 20px 20px 16px;
            box-shadow: 0 26px 70px rgba(0,0,0,.36);
            animation: flotte 6.5s ease-in-out infinite;
        }
        .flotte, .facture { will-change: transform; }
        @keyframes flotte {
            0%, 100% { transform: translateY(0) rotate(-1.1deg); }
            50%      { transform: translateY(-13px) rotate(.7deg); }
        }
        .facture .fx-tete { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
        .facture .fx-titre { font-size: 12.5px; font-weight: 800; color: var(--primary); letter-spacing: -.01em; }
        .facture .fx-num { font-size: 10.5px; color: var(--text-3); margin-top: 2px; font-variant-numeric: tabular-nums; }
        .facture .fx-lignes { margin: 14px 0 12px; display: flex; flex-direction: column; gap: 7px; }
        .facture .fx-ligne { display: flex; justify-content: space-between; gap: 12px; font-size: 10.5px; color: var(--text-2); }
        .facture .fx-ligne span:last-child { font-variant-numeric: tabular-nums; font-weight: 600; color: var(--text); }
        .facture .fx-barre { height: 1px; background: var(--border); margin: 10px 0; }
        .facture .fx-total { display: flex; justify-content: space-between; font-size: 12.5px; font-weight: 800; color: var(--primary); }
        .facture .fx-pied { display: flex; align-items: center; gap: 10px; margin-top: 14px; padding-top: 12px; border-top: 1px dashed var(--border); }
        .fx-qr {
            width: 40px; height: 40px; border-radius: 5px; flex: none;
            background-image:
                linear-gradient(90deg, #0f172a 25%, transparent 25% 50%, #0f172a 50% 75%, transparent 75%),
                linear-gradient(#0f172a 25%, transparent 25% 50%, #0f172a 50% 75%, transparent 75%);
            background-size: 10px 10px;
            background-color: #fff; border: 2px solid #0f172a;
        }
        .fx-certif { font-size: 9.5px; line-height: 1.45; color: var(--text-2); }
        .fx-certif strong { display: block; color: var(--vert); font-size: 10px; }

        .pastille-fne {
            position: absolute; right: -14px; top: -16px;
            background: var(--vert); color: #fff;
            font-size: 10.5px; font-weight: 800; letter-spacing: .04em;
            padding: 7px 13px; border-radius: 100px;
            box-shadow: 0 10px 26px rgba(16,185,129,.42);
            display: inline-flex; align-items: center; gap: 6px;
            animation: pastille 3.4s ease-in-out infinite;
        }
        @keyframes pastille {
            0%, 62%, 100% { transform: scale(1); }
            70%           { transform: scale(1.09); }
        }

        .piste-dgi {
            position: absolute; bottom: 6px; left: 50%; transform: translateX(-50%);
            display: flex; align-items: center; gap: 10px;
            font-size: 11px; color: rgba(255,255,255,.62); white-space: nowrap;
        }
        .rail { position: relative; width: 92px; height: 2px; background: rgba(255,255,255,.18); border-radius: 2px; overflow: hidden; }
        .piste { position: absolute; inset: 0 auto 0 0; width: 34px; background: var(--accent); border-radius: 2px; animation: piste 2.6s ease-in-out infinite; }
        @keyframes piste {
            0%   { transform: translateX(-40px); }
            100% { transform: translateX(96px); }
        }

        /* ══════════ Les cartes ══════════ */
        .grille { display: grid; gap: 20px; }
        .grille.col-2 { grid-template-columns: repeat(2, 1fr); }
        .grille.col-3 { grid-template-columns: repeat(3, 1fr); }
        .grille.col-4 { grid-template-columns: repeat(4, 1fr); }

        .carte {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 26px 24px;
            box-shadow: var(--shadow); transition: transform .22s, box-shadow .22s, border-color .22s;
            display: flex; flex-direction: column; gap: 10px;
        }
        .carte:hover { transform: translateY(-4px); box-shadow: var(--shadow-h); border-color: rgba(10,74,147,.25); }
        .carte .ico {
            width: 46px; height: 46px; border-radius: 12px; flex: none;
            background: rgba(10,74,147,.09); color: var(--primary-l);
            display: grid; place-items: center; font-size: 19px; margin-bottom: 4px;
        }
        .fond-sombre .carte .ico { background: rgba(255,255,255,.12); color: #fff; }
        .carte h3 { font-size: 17px; font-weight: 700; color: var(--primary); letter-spacing: -.015em; }
        .carte p { font-size: 14px; color: var(--text-2); white-space: pre-line; }
        .carte .mention { font-size: 12.5px; color: var(--text-3); }
        .carte .liens { display: flex; flex-wrap: wrap; gap: 8px; margin-top: auto; padding-top: 8px; }
        .carte .lien {
            font-size: 13px; font-weight: 600; color: var(--primary-l); text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .carte .lien:hover { text-decoration: underline; }
        .fond-sombre .carte .lien { color: #fff; }

        .etiquette {
            align-self: flex-start; font-size: 11px; font-weight: 700;
            letter-spacing: .06em; text-transform: uppercase;
            background: rgba(10,74,147,.09); color: var(--primary-l);
            padding: 4px 10px; border-radius: 100px;
        }

        /* ══════════ Liste ══════════ */
        .liste { display: flex; flex-direction: column; gap: 14px; }
        .liste .carte { flex-direction: row; align-items: flex-start; gap: 18px; padding: 20px 22px; }
        .liste .carte .corps { display: flex; flex-direction: column; gap: 6px; }

        /* ══════════ Chiffres ══════════ */
        .chiffre { text-align: center; }
        .chiffre .valeur {
            font-size: clamp(30px, 4.6vw, 44px); font-weight: 900; color: var(--primary);
            letter-spacing: -.035em; line-height: 1;
        }
        .fond-sombre .chiffre .valeur { color: #fff; }
        .chiffre h3 { font-size: 14.5px; margin-top: 8px; }
        .chiffre p { font-size: 13px; }

        /* ══════════ Équipe ══════════ */
        .portrait {
            width: 96px; height: 96px; border-radius: 50%; object-fit: cover;
            border: 3px solid var(--surface); box-shadow: 0 8px 22px rgba(0,43,92,.14);
        }
        .portrait-vide {
            width: 96px; height: 96px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-l));
            color: #fff; display: grid; place-items: center;
            font-size: 30px; font-weight: 800; letter-spacing: .02em;
        }
        .equipe .carte { align-items: center; text-align: center; }
        .equipe .role { font-size: 12.5px; font-weight: 700; color: var(--accent); letter-spacing: .03em; text-transform: uppercase; }

        /* ══════════ Média ══════════ */
        .media-bloc { display: grid; grid-template-columns: 1.05fr .95fr; gap: 44px; align-items: center; }
        .media-cadre {
            border-radius: 16px; overflow: hidden; background: #0b1a30;
            box-shadow: 0 24px 60px rgba(0,43,92,.20); border: 1px solid var(--border);
        }
        .media-cadre video, .media-cadre img, .media-cadre iframe {
            width: 100%; aspect-ratio: 16 / 9; display: block; border: 0; object-fit: cover;
        }
        .media-legende { font-size: 12.5px; color: var(--text-3); margin-top: 10px; text-align: center; }
        .media-absent {
            aspect-ratio: 16 / 9; display: grid; place-items: center; gap: 10px;
            color: rgba(255,255,255,.5); font-size: 13.5px; text-align: center; padding: 24px;
        }
        .media-absent i { font-size: 30px; }

        /* ══════════ Tarifs ══════════ */
        .tarif .valeur { font-size: 30px; font-weight: 900; color: var(--primary); letter-spacing: -.03em; }

        /* ══════════ L'appel final ══════════ */
        .action-section { display: flex; justify-content: center; margin-top: 38px; }

        /* ══════════ Page d'attente ══════════ */
        .attente {
            max-width: 620px; margin: 90px auto; padding: 44px;
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); box-shadow: var(--shadow); text-align: center;
        }
        .attente i { font-size: 30px; color: var(--text-3); margin-bottom: 16px; }
        .attente h1 { font-size: 23px; font-weight: 800; color: var(--primary); margin-bottom: 10px; }
        .attente p { font-size: 14.5px; color: var(--text-2); }
        .attente .actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; margin-top: 24px; }

        /* ══════════ Pied ══════════ */
        .pied { background: var(--primary-d); color: rgba(255,255,255,.62); padding: 40px 24px; }
        .pied .dedans { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; justify-content: space-between; font-size: 13.5px; }
        .pied a { color: #fff; text-decoration: none; }
        .pied a:hover { text-decoration: underline; }
        .pied .liens-pied { display: flex; flex-wrap: wrap; gap: 18px; }

        /* ══════════ Petits écrans ══════════ */
        @media (max-width: 940px) {
            .bandeau .dedans, .media-bloc { grid-template-columns: 1fr; gap: 38px; }
            .grille.col-3, .grille.col-4 { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 700px) {
            .section { padding: 58px 18px; }
            .bandeau { padding: 62px 18px 74px; }
            .grille.col-2, .grille.col-3, .grille.col-4 { grid-template-columns: 1fr; }
            .liste .carte { flex-direction: column; gap: 12px; }
            .menu {
                display: none; position: absolute; top: 100%; left: 0; right: 0;
                flex-direction: column; align-items: stretch; gap: 2px;
                background: var(--surface); border-bottom: 1px solid var(--border);
                padding: 10px 16px 16px;
            }
            .menu.ouvert { display: flex; }
            .menu a { padding: 11px 12px; }
            .burger { display: block; }
            .entete .dedans { position: relative; flex-wrap: wrap; }
            .pied .dedans { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>

@php
    /** Les sections qui méritent une entrée au menu : celles qui portent un titre. */
    $ancres = $sections->filter(fn ($s) => $s->gabaritSur() !== 'bandeau')->take(6);
@endphp

<header class="entete" id="entete">
    <div class="dedans">
        <a href="{{ route('vitrine') }}" class="marque">
            <div class="pastille"><img src="{{ asset('images/selflow/logo-blanc.png') }}" alt="" style="width:28px;height:28px;object-fit:contain;"></div>
            <div>
                <div class="nom">Selflow</div>
                <div class="sous">DC-Knowing</div>
            </div>
        </a>

        <button class="burger" type="button" aria-label="Ouvrir le menu" aria-expanded="false"
                onclick="const m=document.getElementById('menu');const o=m.classList.toggle('ouvert');this.setAttribute('aria-expanded',o);">
            <i class="fas fa-bars"></i>
        </button>

        <nav class="menu" id="menu">
            @foreach($ancres as $ancre)
                <a href="#{{ $ancre->cle }}">{{ $ancre->titre }}</a>
            @endforeach
            <a href="{{ route('connexion') }}" class="btn">Se connecter</a>
            <a href="{{ route('inscription') }}" class="btn btn-principal">
                <i class="fas fa-arrow-right"></i> Créer un compte
            </a>
        </nav>
    </div>
</header>

@forelse($sections as $section)
    @php
        $gabarit = $section->gabaritSur();
        $cartes  = $section->cartesPubliees;
        $media   = $section->mediaUrl();
    @endphp

    {{-- ══════════ Le bandeau d'ouverture ══════════ --}}
    @if($gabarit === 'bandeau')
        <section class="bandeau" id="{{ $section->cle }}">
            <div class="dedans">
                <div>
                    @if($section->sous_titre)
                        <div class="surtitre apparait"><i class="fas fa-shield-halved"></i> {{ $section->sous_titre }}</div>
                    @endif
                    <h1 class="apparait" data-retard="1">{{ $section->titre }}</h1>
                    @if($section->texte)<p class="texte apparait" data-retard="2">{{ $section->texte }}</p>@endif

                    <div class="actions apparait" data-retard="3">
                        @if($section->action_libelle && $section->action_url)
                            <a href="{{ $section->action_url }}" class="btn btn-principal">
                                {{ $section->action_libelle }} <i class="fas fa-arrow-right"></i>
                            </a>
                        @endif
                        @foreach($cartes as $carte)
                            @if($carte->lien_url)
                                <a href="{{ $carte->lien_url }}" class="btn {{ $loop->first && !$section->action_libelle ? 'btn-principal' : 'btn-clair' }}">
                                    @if($carte->icone)<i class="{{ $carte->icone }}"></i>@endif
                                    {{ $carte->lien_libelle ?: $carte->titre }}
                                </a>
                            @endif
                        @endforeach
                    </div>
                </div>

                {{-- La facture qui part à la DGI, et en revient certifiée.
                     Tout est dessiné en CSS : aucune image à charger, aucun
                     appel sortant, et le rendu suit la taille de l'écran. --}}
                <div class="scene apparait" data-retard="2" aria-hidden="true">
                    <span class="onde"></span><span class="onde"></span><span class="onde"></span>

                    <div class="facture">
                        <span class="pastille-fne"><i class="fas fa-check"></i> Normalisée</span>

                        <div class="fx-tete">
                            <div>
                                <div class="fx-titre">FACTURE NORMALISÉE</div>
                                <div class="fx-num">N° 9909270250100000123</div>
                            </div>
                            <i class="fas fa-file-invoice" style="color:#94a3b8;"></i>
                        </div>

                        <div class="fx-lignes">
                            <div class="fx-ligne"><span>Ciment 50 kg × 12</span><span>60 000</span></div>
                            <div class="fx-ligne"><span>Fer à béton × 8</span><span>32 000</span></div>
                            <div class="fx-ligne"><span>TVA 18 %</span><span>16 560</span></div>
                        </div>

                        <div class="fx-barre"></div>
                        <div class="fx-total"><span>Total TTC</span><span>108 560 F</span></div>

                        <div class="fx-pied">
                            <div class="fx-qr"></div>
                            <div class="fx-certif">
                                <strong>Certifiée par la DGI</strong>
                                Code QR, visuel FNE et numérotation renvoyés par la plateforme.
                            </div>
                        </div>
                    </div>

                    <div class="piste-dgi">
                        <i class="fas fa-paper-plane"></i> Transmission
                        <span class="rail"><span class="piste"></span></span>
                        DGI
                    </div>
                </div>
            </div>
        </section>

    {{-- ══════════ Une image ou une vidéo, en grand ══════════ --}}
    @elseif($gabarit === 'media')
        <section class="section fond-{{ $section->fondSur() }}" id="{{ $section->cle }}">
            <div class="dedans media-bloc">
                <div class="apparait">
                    @if($section->sous_titre)<div class="surtitre">{{ $section->sous_titre }}</div>@endif
                    <h2 style="font-size:clamp(24px,3.6vw,34px);font-weight:800;color:var(--primary);letter-spacing:-.025em;">{{ $section->titre }}</h2>
                    @if($section->texte)<p class="texte-section">{{ $section->texte }}</p>@endif

                    @if($section->action_libelle && $section->action_url)
                        <a href="{{ $section->action_url }}" class="btn btn-principal" style="margin-top:22px;">
                            {{ $section->action_libelle }} <i class="fas fa-arrow-right"></i>
                        </a>
                    @endif
                </div>

                <div class="apparait" data-retard="2">
                    <div class="media-cadre">
                        @if($media && $section->media_type === 'video')
                            {{-- `muted` est ce qui permet la lecture automatique : sans
                                 lui, le navigateur la refuse. Les contrôles restent, pour
                                 que le son se choisisse. --}}
                            <video src="{{ $media }}" autoplay muted loop playsinline controls
                                   preload="metadata"></video>
                        @elseif($media)
                            <img src="{{ $media }}" alt="{{ $section->media_legende ?: $section->titre }}" loading="lazy">
                        @else
                            <div class="media-absent">
                                <i class="fas fa-photo-film"></i>
                                <span>L'illustration de cette section n'a pas encore été déposée.</span>
                            </div>
                        @endif
                    </div>
                    @if($section->media_legende)<div class="media-legende">{{ $section->media_legende }}</div>@endif
                </div>
            </div>
        </section>

    {{-- ══════════ Toutes les autres dispositions ══════════ --}}
    @else
        <section class="section fond-{{ $section->fondSur() }}" id="{{ $section->cle }}">
            <div class="dedans">
                <div class="section-tete apparait">
                    @if($section->sous_titre)<div class="surtitre">{{ $section->sous_titre }}</div>@endif
                    <h2>{{ $section->titre }}</h2>
                    @if($section->texte)<p class="texte-section">{{ $section->texte }}</p>@endif
                </div>

                @if($media)
                    <div class="apparait" data-retard="1" style="margin-bottom:40px;">
                        <div class="media-cadre">
                            @if($section->media_type === 'video')
                                <video src="{{ $media }}" autoplay muted loop playsinline controls preload="metadata"></video>
                            @else
                                <img src="{{ $media }}" alt="{{ $section->media_legende ?: $section->titre }}" loading="lazy">
                            @endif
                        </div>
                        @if($section->media_legende)<div class="media-legende">{{ $section->media_legende }}</div>@endif
                    </div>
                @endif

                @if($cartes->isNotEmpty())
                    @php
                        $colonnes = match ($gabarit) {
                            'chiffres' => 'col-4',
                            'equipe'   => $cartes->count() <= 2 ? 'col-2' : 'col-3',
                            'produits' => 'col-3',
                            'tarifs'   => $cartes->count() <= 2 ? 'col-2' : 'col-3',
                            default    => $cartes->count() <= 2 ? 'col-2' : 'col-3',
                        };
                    @endphp

                    <div class="{{ $gabarit === 'liste' ? 'liste' : 'grille ' . $colonnes }} {{ $gabarit === 'equipe' ? 'equipe' : '' }}">
                        @foreach($cartes as $carte)
                        <article class="carte apparait {{ $gabarit === 'chiffres' ? 'chiffre' : '' }} {{ $gabarit === 'tarifs' ? 'tarif' : '' }}"
                                 data-retard="{{ min(5, $loop->index + 1) }}">

                            @if($gabarit === 'equipe')
                                @if($carte->imageUrl())
                                    <img src="{{ $carte->imageUrl() }}" alt="{{ $carte->titre }}" class="portrait" loading="lazy">
                                @else
                                    {{-- La photo n'est pas encore déposée : deux lettres
                                         valent mieux qu'une case grise. --}}
                                    <div class="portrait-vide" aria-hidden="true">{{ $carte->initiales() }}</div>
                                @endif
                                <h3>{{ $carte->titre }}</h3>
                                @if($carte->role)<div class="role">{{ $carte->role }}</div>@endif
                                @if($carte->texte)<p>{{ $carte->texte }}</p>@endif

                            @elseif($gabarit === 'chiffres')
                                @if($carte->valeur)<div class="valeur">{{ $carte->valeur }}</div>@endif
                                <h3>{{ $carte->titre }}</h3>
                                @if($carte->texte)<p>{{ $carte->texte }}</p>@endif

                            @else
                                @if($gabarit === 'liste' || $gabarit === 'produits' || $gabarit === 'colonnes')
                                    @if($carte->icone)<div class="ico"><i class="{{ $carte->icone }}"></i></div>@endif
                                @endif

                                <div class="corps" style="display:contents;">
                                    @if($carte->role)<span class="etiquette">{{ $carte->role }}</span>@endif
                                    <h3>{{ $carte->titre }}</h3>
                                    @if($carte->valeur)<div class="valeur">{{ $carte->valeur }}</div>@endif
                                    @if($carte->texte)<p>{{ $carte->texte }}</p>@endif
                                    @if($carte->mention)<div class="mention">{{ $carte->mention }}</div>@endif
                                </div>
                            @endif

                            @if($carte->lien_url || $carte->lien_secondaire_url)
                                <div class="liens">
                                    @if($carte->lien_url)
                                        <a href="{{ $carte->lien_url }}" class="lien">
                                            {{ $carte->lien_libelle ?: 'En savoir plus' }} <i class="fas fa-arrow-right"></i>
                                        </a>
                                    @endif
                                    @if($carte->lien_secondaire_url)
                                        <a href="{{ $carte->lien_secondaire_url }}" class="lien">
                                            {{ $carte->lien_secondaire_libelle ?: 'Documentation' }}
                                        </a>
                                    @endif
                                </div>
                            @endif
                        </article>
                        @endforeach
                    </div>
                @endif

                @if($section->action_libelle && $section->action_url)
                    <div class="action-section apparait">
                        <a href="{{ $section->action_url }}" class="btn btn-principal">
                            {{ $section->action_libelle }} <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                @endif
            </div>
        </section>
    @endif

@empty
    {{-- Aucune section publiée. On le dit, plutôt que d'inventer une page. --}}
    <div class="attente">
        <i class="fas fa-pen-ruler"></i>
        <h1>Cette page est en préparation</h1>
        <p>Le contenu de la présentation n'a pas encore été publié.
           En attendant, vous pouvez rejoindre votre espace.</p>
        <div class="actions">
            <a href="{{ route('connexion') }}" class="btn btn-principal">
                <i class="fas fa-arrow-right-to-bracket"></i> Se connecter
            </a>
            <a href="{{ route('inscription') }}" class="btn">
                <i class="fas fa-user-plus"></i> Créer un compte
            </a>
        </div>

        {{-- Le visiteur ordinaire n'a rien à faire de cette information ; celui
             qui peut y remédier, si. Deux environnements affichaient des pages
             différentes sans que personne sache pourquoi : le contenu n'avait
             été posé que sur l'un des deux. --}}
        @auth
            @if(auth()->user()->estSuperAdmin())
                <p style="margin-top:22px;font-size:12.5px;color:#94a3b8;line-height:1.7;">
                    <i class="fas fa-circle-info"></i>
                    Aucune section n'est publiée sur cette installation.
                    <a href="{{ route('superadmin.vitrine.index') }}" style="color:#2563eb;font-weight:600;">
                        Ouvrir la gestion de la page d'accueil
                    </a>
                    — ou relancer <code>php artisan migrate</code>, qui pose le
                    contenu par défaut sans rien écraser.
                </p>
            @endif
        @endauth
    </div>
@endforelse

<footer class="pied">
    <div class="dedans">
        <div>Selflow — une solution <a href="{{ route('vitrine') }}">DC-Knowing</a> · {{ now()->year }}</div>
        <div class="liens-pied">
            <a href="{{ route('connexion') }}">Se connecter</a>
            <a href="{{ route('inscription') }}">Créer un compte</a>
            @foreach($sections->whereIn('cle', ['documentation', 'politique']) as $lien)
                <a href="#{{ $lien->cle }}">{{ $lien->titre }}</a>
            @endforeach
        </div>
    </div>
</footer>

<script>
(function () {
    'use strict';

    // ── L'entrée au défilement ────────────────────────────────────────
    //
    // `IntersectionObserver` plutôt qu'un écouteur de `scroll` : le navigateur
    // fait le calcul lui-même, hors du fil principal, et la page ne saccade
    // pas sur un téléphone d'entrée de gamme.
    //
    // Une seule observation par élément : une section déjà vue reste visible
    // si l'on remonte, plutôt que de disparaître et revenir.
    var aAnimer = document.querySelectorAll('.apparait');
    var repos = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (repos || !('IntersectionObserver' in window)) {
        // Sans observateur — ou si le visiteur a demandé moins de mouvement —
        // tout s'affiche d'emblée. Une page invisible vaut moins qu'une page
        // sans animation.
        aAnimer.forEach(function (el) { el.classList.add('vu'); });
    } else {
        var guetteur = new IntersectionObserver(function (entrees) {
            entrees.forEach(function (entree) {
                if (entree.isIntersecting) {
                    entree.target.classList.add('vu');
                    guetteur.unobserve(entree.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -60px 0px' });

        aAnimer.forEach(function (el) { guetteur.observe(el); });
    }

    // ── La barre du haut se pose quand on quitte le sommet ─────────────
    var entete = document.getElementById('entete');
    var poser = function () {
        entete.classList.toggle('pose', window.scrollY > 8);
    };
    poser();
    window.addEventListener('scroll', poser, { passive: true });

    // ── Le menu se referme derrière soi ────────────────────────────────
    var menu = document.getElementById('menu');
    menu.addEventListener('click', function (e) {
        if (e.target.tagName === 'A') { menu.classList.remove('ouvert'); }
    });
})();
</script>

</body>
</html>

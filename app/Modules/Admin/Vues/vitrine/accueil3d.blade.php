<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Selflow · La facture normalisée, sans y penser</title>
<meta name="description" content="Selflow tient vos ventes, vos achats, votre stock et votre comptabilité, et fait certifier chaque facture par la plateforme FNE de la DGI Côte d'Ivoire.">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/selflow/favicon-32.png') }}">
<link rel="stylesheet" href="{{ asset('vendor/inter/inter.css') }}">
<link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
<style>
    :root {
        --nuit: #062456; --nuit-2: #0a3a85; --bleu: #0b4aa2; --cyan: #7cc4ff; --vert: #16a34a;
        --or: #f08a1c; --texte: #ffffff; --texte-2: #c9dbf5; --carte: #ffffff;
        --bord: rgba(255,255,255,.22); --clair: #f3f5f9; --encre: #0f172a; --encre-2: #475569;
    }
    * { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body { margin: 0; font-family: 'Inter', system-ui, sans-serif; background: var(--clair); color: var(--encre); overflow-x: hidden; }
    a { color: inherit; text-decoration: none; }
    .conteneur { width: min(1180px, 100% - 32px); margin: 0 auto; }

    /* En-tête */
    .entete { position: fixed; inset: 0 0 auto 0; z-index: 30; transition: background .3s, backdrop-filter .3s; }
    .entete { color: #fff; }
    .entete.defile { background: rgba(6,36,86,.94); backdrop-filter: blur(14px); box-shadow: 0 6px 20px -10px rgba(6,36,86,.5); }
    .entete .conteneur { display: flex; align-items: center; justify-content: space-between; height: 70px; gap: 16px; }
    .marque { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 20px; }
    .marque img { height: 34px; }
    .menu { display: flex; gap: 26px; font-size: 14px; color: #d6e4f8; }
    .menu a:hover { color: #fff; }
    .actions-entete { display: flex; gap: 10px; }
    .btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 20px; border-radius: 12px; font-weight: 700; font-size: 14px; border: 1px solid transparent; cursor: pointer; transition: transform .2s, box-shadow .2s, background .2s; font-family: inherit; }
    .btn:hover { transform: translateY(-2px); }
    .btn-plein { background: #ffffff; color: var(--nuit); box-shadow: 0 10px 30px -12px rgba(0,0,0,.35); }
    .clair .btn-plein, .fenetre .btn-plein { background: var(--nuit); color: #fff; }
    .btn-verre { background: rgba(255,255,255,.06); color: #fff; border-color: var(--bord); }
    .btn-vert { background: #ffffff; color: var(--nuit); box-shadow: 0 10px 30px -12px rgba(0,0,0,.35); }
    @media (max-width: 900px) { .menu { display: none; } }
    @media (max-width: 520px) { .actions-entete .btn-verre { display: none; } }

    /* Ouverture 3D */
    .ouverture { position: relative; min-height: 100vh; display: flex; align-items: center; overflow: hidden; color: #fff; background: linear-gradient(135deg, #041b45 0%, #062e6f 45%, #0b4aa2 100%); }
    #scene { position: absolute; inset: 0; width: 100%; height: 100%; }
    .ouverture::after { content: ''; position: absolute; inset: 0; background: radial-gradient(ellipse at 18% 50%, rgba(4,27,69,.85) 0%, rgba(4,27,69,.35) 45%, rgba(4,27,69,0) 70%); pointer-events: none; }
    .ouverture .conteneur { position: relative; z-index: 2; padding: 120px 0 80px; }
    @media (max-width: 900px) { #scene { opacity: .3; } .ouverture::after { background: rgba(4,27,69,.35); } }
    .pastille { display: inline-flex; align-items: center; gap: 8px; padding: 7px 14px; border-radius: 999px; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.3); color: #fff; font-size: 13px; font-weight: 700; }
    .pastille .point { width: 8px; height: 8px; border-radius: 50%; background: var(--vert); box-shadow: 0 0 0 0 rgba(16,185,129,.7); animation: pouls 2s infinite; }
    @keyframes pouls { 70% { box-shadow: 0 0 0 10px rgba(16,185,129,0); } 100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); } }
    h1 { font-size: clamp(38px, 6vw, 72px); line-height: 1.04; margin: 22px 0 18px; letter-spacing: -.02em; max-width: 680px; }
    h1 .degrade { color: #9fd3ff; }
    .chapeau { font-size: clamp(16px, 1.6vw, 19px); color: var(--texte-2); max-width: 560px; line-height: 1.65; }
    .boutons { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 30px; }
    .preuves { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 34px; }
    .preuve { font-size: 12.5px; color: #e2ecfb; padding: 8px 12px; border: 1px solid var(--bord); border-radius: 10px; background: rgba(255,255,255,.08); }
    .preuve i { color: var(--cyan); margin-right: 6px; }
    .defiler { position: absolute; bottom: 26px; left: 50%; transform: translateX(-50%); z-index: 2; color: #c9dbf5; font-size: 12px; text-align: center; }
    .defiler i { display: block; margin-top: 6px; animation: flotte 1.8s ease-in-out infinite; }
    @keyframes flotte { 50% { transform: translateY(6px); } }

    /* Sections */
    section.bloc { padding: 110px 0; position: relative; }
    .clair { background: var(--clair); color: var(--encre); }
    .surtitre { font-size: 13px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: var(--bleu); }
    .clair .surtitre { color: var(--bleu); }
    h2 { font-size: clamp(28px, 4vw, 46px); line-height: 1.12; margin: 12px 0 16px; letter-spacing: -.015em; }
    .intro { font-size: 17px; color: var(--encre-2); max-width: 680px; line-height: 1.65; }
    .clair .intro { color: var(--encre-2); }

    /* Le parcours : cartes en perspective */
    .parcours { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-top: 56px; perspective: 1200px; }
    @media (max-width: 1000px) { .parcours { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 560px) { .parcours { grid-template-columns: 1fr; } }
    .etape { position: relative; padding: 26px 22px; border-radius: 20px; background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 1px 2px rgba(15,23,42,.05); transform-style: preserve-3d; transition: transform .5s cubic-bezier(.2,.8,.2,1), box-shadow .3s; }
    .etape:hover { box-shadow: 0 30px 60px -25px rgba(11,74,162,.35); }
    .etape .num { position: absolute; top: 16px; right: 18px; font-size: 44px; font-weight: 800; color: #e8eef8; transform: translateZ(30px); }
    .etape .icone { width: 52px; height: 52px; border-radius: 14px; display: grid; place-items: center; font-size: 22px; background: #eaf2ff; color: var(--bleu); transform: translateZ(40px); }
    .etape.dgi .icone { background: #fff4e6; color: var(--or); }
    .etape.ok .icone { background: #eaf8ef; color: var(--vert); }
    .etape h3 { font-size: 17px; margin: 18px 0 8px; transform: translateZ(25px); }
    .etape p { font-size: 14px; color: var(--encre-2); line-height: 1.6; margin: 0; transform: translateZ(15px); }
    .fil { height: 3px; margin-top: 26px; background: linear-gradient(90deg, var(--bleu), var(--or), var(--vert)); border-radius: 2px; opacity: .7; }

    /* Cartes de fonctionnalités */
    .grille { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; margin-top: 48px; perspective: 1200px; }
    @media (max-width: 900px) { .grille { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 560px) { .grille { grid-template-columns: 1fr; } }
    .carte { padding: 26px; border-radius: 20px; background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 1px 2px rgba(15,23,42,.04); transform-style: preserve-3d; transition: transform .4s cubic-bezier(.2,.8,.2,1), box-shadow .4s; }
    .carte:hover { box-shadow: 0 30px 60px -25px rgba(11,74,162,.35); }
    .carte .icone { width: 46px; height: 46px; border-radius: 12px; display: grid; place-items: center; background: #eaf2ff; color: var(--bleu); font-size: 19px; transform: translateZ(30px); }
    .carte h3 { font-size: 17px; margin: 16px 0 8px; transform: translateZ(20px); }
    .carte p { font-size: 14.5px; color: var(--encre-2); line-height: 1.6; margin: 0; }
    .sombre { background: #e9f0fb; }

    /* Points de vente : maquette isométrique */
    .deux-colonnes { display: grid; grid-template-columns: 1.05fr 1fr; gap: 60px; align-items: center; }
    @media (max-width: 900px) { .deux-colonnes { grid-template-columns: 1fr; } }
    .iso { height: 380px; position: relative; perspective: 1400px; }
    .iso .plateau { position: absolute; inset: 40px 20px; transform: rotateX(58deg) rotateZ(-38deg); transform-style: preserve-3d; animation: tourne 18s ease-in-out infinite; }
    @keyframes tourne { 50% { transform: rotateX(58deg) rotateZ(-28deg); } }
    .iso .site { position: absolute; width: 120px; height: 120px; border-radius: 18px; background: linear-gradient(135deg, #fff, #dbeafe); border: 1px solid #bfdbfe; transform-style: preserve-3d; box-shadow: 0 40px 40px -20px rgba(37,99,235,.35); display: grid; place-items: center; font-weight: 800; font-size: 13px; color: var(--bleu); }
    .iso .site::before { content: ''; position: absolute; inset: 0; border-radius: 18px; transform: translateZ(34px); background: linear-gradient(135deg, #2563eb, #4f46e5); opacity: .92; }
    .iso .site span { position: relative; transform: translateZ(36px); color: #fff; text-align: center; padding: 0 8px; }
    .iso .s1 { left: 0; top: 0; } .iso .s2 { left: 170px; top: 20px; } .iso .s3 { left: 40px; top: 170px; }
    .iso .s2::before { background: linear-gradient(135deg, #059669, #10b981); }
    .iso .s3::before { background: linear-gradient(135deg, #d97706, #f59e0b); }
    .liste-points { list-style: none; padding: 0; margin: 26px 0 0; display: grid; gap: 14px; }
    .liste-points li { display: flex; gap: 12px; align-items: flex-start; font-size: 15px; color: var(--encre-2); line-height: 1.55; }
    .liste-points i { color: var(--vert); margin-top: 4px; }

    /* Applications */
    .appli .role { font-size: 12px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: var(--bleu); }
    .appli a.lien { display: inline-flex; gap: 6px; align-items: center; margin-top: 14px; font-weight: 700; font-size: 14px; color: var(--bleu); }

    /* Appel final */
    .appel { text-align: center; padding: 90px 24px; border-radius: 30px; color: #fff; background: linear-gradient(135deg, #041b45, #0b4aa2); }
    .appel .surtitre { color: #9fd3ff; } .appel .intro { color: #d6e4f8; }
    .appel .boutons { justify-content: center; }

    footer { padding: 46px 0 60px; color: var(--encre-2); font-size: 14px; border-top: 1px solid #e2e8f0; background: #fff; }
    footer .conteneur { display: flex; flex-wrap: wrap; gap: 20px; justify-content: space-between; align-items: center; }
    footer nav { display: flex; flex-wrap: wrap; gap: 22px; }
    footer a:hover { color: var(--bleu); }

    /* Apparition au défilement */
    .apparait { opacity: 0; transform: translateY(30px); transition: opacity .8s ease, transform .8s cubic-bezier(.2,.8,.2,1); }
    .apparait.vu { opacity: 1; transform: none; }

    /* Fenêtre de demande de démo */
    .voile { position: fixed; inset: 0; background: rgba(2,6,23,.7); backdrop-filter: blur(6px); display: none; align-items: center; justify-content: center; z-index: 60; padding: 16px; }
    .voile.ouvert { display: flex; }
    .fenetre { width: min(460px, 100%); background: #fff; color: var(--encre); border-radius: 22px; padding: 28px; box-shadow: 0 40px 80px -20px rgba(0,0,0,.6); animation: surgit .35s cubic-bezier(.2,.8,.2,1); }
    @keyframes surgit { from { opacity: 0; transform: translateY(20px) scale(.97); } }
    .fenetre h3 { margin: 0 0 6px; font-size: 22px; }
    .fenetre p.aide { margin: 0 0 18px; color: var(--encre-2); font-size: 14px; }
    .fenetre label { display: block; font-size: 13px; font-weight: 700; margin: 14px 0 6px; }
    .fenetre input[type=email], .fenetre input[type=tel], .fenetre input[type=text] { width: 100%; padding: 12px 14px; border-radius: 12px; border: 1px solid #cbd5e1; font: inherit; font-size: 15px; }
    .fenetre input:focus { outline: 2px solid #93c5fd; border-color: var(--bleu); }
    .choix { display: flex; gap: 10px; flex-wrap: wrap; }
    .choix label { flex: 1; min-width: 150px; margin: 0; display: flex; gap: 10px; align-items: center; padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 12px; cursor: pointer; font-weight: 600; }
    .choix input { width: 18px; height: 18px; accent-color: var(--bleu); }
    .fenetre .pied { display: flex; gap: 10px; justify-content: flex-end; margin-top: 22px; }
    .fenetre .btn-verre { color: var(--encre); background: #f1f5f9; border-color: #e2e8f0; }
    .message { margin-top: 14px; font-size: 14px; padding: 10px 12px; border-radius: 10px; display: none; }
    .message.ok { display: block; background: #ecfdf5; color: #065f46; }
    .message.ko { display: block; background: #fef2f2; color: #991b1b; }

    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after { animation: none !important; transition: none !important; }
        .apparait { opacity: 1; transform: none; }
    }
</style>
</head>
<body>

<header class="entete" id="entete">
    <div class="conteneur">
        <a href="{{ url('/') }}" class="marque"><img src="{{ asset('images/selflow/logo-blanc.png') }}" alt="Selflow"></a>
        <nav class="menu">
            <a href="#parcours">La normalisation</a>
            <a href="#conformite">La conformité</a>
            <a href="#gestion">La gestion</a>
            <a href="#points-de-vente">Points de vente</a>
            <a href="{{ route('offres') }}">Offres</a>
        </nav>
        <div class="actions-entete">
            <a href="{{ route('connexion') }}" class="btn btn-verre">Se connecter</a>
            <button type="button" class="btn btn-plein" data-demo>Voir une démo</button>
        </div>
    </div>
</header>

<section class="ouverture">
    <canvas id="scene" aria-hidden="true"></canvas>
    <div class="conteneur">
        <h1>La facture normalisée, <span class="degrade">sans y penser.</span></h1>
        <p class="chapeau">Vous vendez. Selflow transmet la pièce à la plateforme de la Direction Générale des Impôts, la reçoit certifiée avec son numéro et son code QR, sort la marchandise du stock et passe l'écriture comptable. Une seule saisie, et tout suit.</p>
        <div class="boutons">
            <a href="{{ route('inscription') }}" class="btn btn-plein"><i class="fas fa-rocket"></i> Créer un compte</a>
            <button type="button" class="btn btn-verre" data-demo><i class="fas fa-circle-play"></i> Voir une démo</button>
        </div>
        <div class="preuves">
            <span class="preuve"><i class="fas fa-qrcode"></i>Code QR et numéro FNE</span>
            <span class="preuve"><i class="fas fa-stamp"></i>Timbre selon l'article 873 du CGI</span>
            <span class="preuve"><i class="fas fa-book"></i>Plan SYSCOHADA révisé</span>
            <span class="preuve"><i class="fas fa-store"></i>Plusieurs points de vente</span>
        </div>
    </div>
    <div class="defiler">Découvrir le parcours d'une facture<i class="fas fa-chevron-down"></i></div>
</section>

<section class="bloc" id="parcours">
    <div class="conteneur">
        <div class="apparait">
            <div class="surtitre">Le parcours d'une facture</div>
            <h2>De la vente à la pièce certifiée, en quelques secondes</h2>
            <p class="intro">Chaque facture et chaque reçu empruntent le même chemin. Vous choisissez seulement le moment : certification dès l'émission, ou après votre vérification.</p>
        </div>
        <div class="parcours">
            <div class="etape apparait" data-incline><span class="num">1</span><div class="icone"><i class="fas fa-cash-register"></i></div>
                <h3>La vente</h3><p>Saisie au point de vente, en facture A4 ou en reçu de caisse. Le stock et les prix sont ceux du site.</p></div>
            <div class="etape dgi apparait" data-incline><span class="num">2</span><div class="icone"><i class="fas fa-building-columns"></i></div>
                <h3>La transmission</h3><p>La pièce part à la plateforme FNE avec les codes TVA de la DGI, le nom du point de vente et le timbre exact.</p></div>
            <div class="etape ok apparait" data-incline><span class="num">3</span><div class="icone"><i class="fas fa-qrcode"></i></div>
                <h3>La certification</h3><p>Elle revient avec son numéro FNE, son code QR de vérification et le visuel du sticker électronique.</p></div>
            <div class="etape apparait" data-incline><span class="num">4</span><div class="icone"><i class="fas fa-print"></i></div>
                <h3>L'impression</h3><p>Facture A4 ou ticket 80 mm, le même sticker sur les deux. Votre client peut vérifier sa pièce.</p></div>
            <div class="etape ok apparait" data-incline><span class="num">5</span><div class="icone"><i class="fas fa-scale-balanced"></i></div>
                <h3>La comptabilité</h3><p>Écritures au plan SYSCOHADA, trésorerie à jour, et déversement dans Comptaflow avec le bon compte et le bon tiers.</p></div>
        </div>
        <div class="fil"></div>
    </div>
</section>

<section class="bloc clair" id="conformite">
    <div class="conteneur">
        <div class="apparait">
            <div class="surtitre">La conformité</div>
            <h2>Une obligation fixée au caractère près</h2>
            <p class="intro">La certification n'est pas une case de plus à cocher. Selflow suit le référentiel technique de la DGI, et chaque point a été vérifié sur des pièces réellement certifiées.</p>
        </div>
        <div class="grille">
            <div class="carte apparait" data-incline><div class="icone"><i class="fas fa-percent"></i></div><h3>Les codes TVA de la plateforme</h3><p>Chaque taux part sous le code que la DGI lui attribue. Les exonérations légales suivent le régime de l'entreprise.</p></div>
            <div class="carte apparait" data-incline><div class="icone"><i class="fas fa-stamp"></i></div><h3>Le timbre de quittance exact</h3><p>Le barème forfaitaire par tranche de l'article 873 du Code général des impôts, et non un taux approché.</p></div>
            <div class="carte apparait" data-incline><div class="icone"><i class="fas fa-receipt"></i></div><h3>Le reçu normalisé aussi</h3><p>Le reçu de caisse emprunte la même porte que la facture et porte le même sticker sur le rouleau de 80 mm.</p></div>
            <div class="carte apparait" data-incline><div class="icone"><i class="fas fa-rotate-left"></i></div><h3>L'avoir certifié</h3><p>Une pièce certifiée ne se modifie pas : elle se corrige par un avoir, lui aussi transmis à la DGI.</p></div>
            <div class="carte apparait" data-incline><div class="icone"><i class="fas fa-bell"></i></div><h3>Les stickers sous les yeux</h3><p>Le solde de vignettes est suivi, et une alerte prévient avant la rupture. Une pièce non certifiable est une vente perdue.</p></div>
            <div class="carte apparait" data-incline><div class="icone"><i class="fas fa-cloud-arrow-down"></i></div><h3>Le portail FNE relu</h3><p>Les factures émises ou reçues directement sur le portail de la DGI sont relevées, puis reprises dans Selflow comme des ventes et des achats.</p></div>
        </div>
    </div>
</section>

<section class="bloc sombre" id="gestion">
    <div class="conteneur">
        <div class="apparait">
            <div class="surtitre">La gestion commerciale</div>
            <h2>Tout ce que fait l'entreprise, au même endroit</h2>
            <p class="intro">Selflow s'adapte au métier choisi à l'inscription : commerce, production, chantiers, cycles agricoles ou services. Chaque module ne s'ouvre que s'il sert.</p>
        </div>
        <div class="grille">
            <div class="carte apparait" data-incline><div class="icone"><i class="fas fa-file-invoice"></i></div><h3>Ventes</h3><p>Devis, bons de commande, livraisons, factures et reçus, avoirs, acomptes et créances clients.</p></div>
            <div class="carte apparait" data-incline><div class="icone"><i class="fas fa-truck-ramp-box"></i></div><h3>Achats</h3><p>Commandes fournisseurs, réceptions, factures d'achat et bordereau d'achat de produits agricoles certifié.</p></div>
            <div class="carte apparait" data-incline><div class="icone"><i class="fas fa-boxes-stacked"></i></div><h3>Stock</h3><p>Inventaire permanent valorisé au coût unitaire moyen pondéré, transferts entre sites, inventaire physique exporté en PDF et Excel.</p></div>
            <div class="carte apparait" data-incline><div class="icone"><i class="fas fa-wallet"></i></div><h3>Trésorerie</h3><p>Caisse, banque et mobile money, chaque journal à son nom, encaissements et décaissements rapprochés des pièces.</p></div>
            <div class="carte apparait" data-incline><div class="icone"><i class="fas fa-book-open"></i></div><h3>Comptabilité</h3><p>Le plan comptable OHADA complet, des libellés à votre façon, une balance de contrôle et le lien avec Comptaflow.</p></div>
            <div class="carte apparait" data-incline><div class="icone"><i class="fas fa-user-shield"></i></div><h3>Habilitations et traçabilité</h3><p>Chaque employé ne voit que ce qui le concerne, et chaque action garde son auteur, sa date et son origine.</p></div>
        </div>
    </div>
</section>

<section class="bloc clair" id="points-de-vente">
    <div class="conteneur deux-colonnes">
        <div class="apparait">
            <div class="surtitre">Plusieurs points de vente</div>
            <h2>Chaque site a son stock, ses prix et ses numéros</h2>
            <p class="intro">Le nom du point de vente part tel quel à la DGI, qui le vérifie. Selflow n'en invente aucun, et rien ne passe d'un site à l'autre sans un transfert.</p>
            <ul class="liste-points">
                <li><i class="fas fa-circle-check"></i>Un stock et une numérotation des factures propres à chaque site.</li>
                <li><i class="fas fa-circle-check"></i>Un caissier ne voit que les articles et les clients de son point de vente.</li>
                <li><i class="fas fa-circle-check"></i>Le responsable de plusieurs sites passe de l'un à l'autre en un clic.</li>
                <li><i class="fas fa-circle-check"></i>Le résultat se lit par site, comme pour l'ensemble de l'entreprise.</li>
            </ul>
        </div>
        <div class="iso apparait" aria-hidden="true">
            <div class="plateau">
                <div class="site s1"><span>Siège</span></div>
                <div class="site s2"><span>Marcory</span></div>
                <div class="site s3"><span>Plateau</span></div>
            </div>
        </div>
    </div>
</section>

<section class="bloc sombre" id="applications">
    <div class="conteneur">
        <div class="apparait">
            <div class="surtitre">Les applications DC-Knowing</div>
            <h2>Chacune tient son métier, et elles se parlent</h2>
        </div>
        <div class="grille">
            <div class="carte appli apparait" data-incline><div class="icone"><i class="fas fa-cart-shopping"></i></div><div class="role">Gestion commerciale</div><h3>Selflow</h3><p>Ventes, achats, stock, points de vente et facture normalisée transmise à la DGI.</p><a class="lien" href="https://selflow.dc-knowing.com/">Ouvrir Selflow <i class="fas fa-arrow-right"></i></a></div>
            <div class="carte appli apparait" data-incline><div class="icone"><i class="fas fa-book"></i></div><div class="role">Comptabilité</div><h3>Comptaflow</h3><p>Journaux, grand livre, balance et états financiers SYSCOHADA. Il reçoit les écritures de Selflow.</p><a class="lien" href="https://comptaflow.dc-knowing.com/">Ouvrir Comptaflow <i class="fas fa-arrow-right"></i></a></div>
            <div class="carte appli apparait" data-incline><div class="icone"><i class="fas fa-sitemap"></i></div><div class="role">Centres de gestion agréés</div><h3>CGA-Connect</h3><p>Les centres de gestion agréés, leurs clients et leurs adhérents, avec une vue d'ensemble pour la direction.</p></div>
            <div class="carte appli apparait" data-incline><div class="icone"><i class="fas fa-users"></i></div><div class="role">Ressources humaines</div><h3>RHFlow</h3><p>La gestion des ressources humaines.</p></div>
            <div class="carte appli apparait" data-incline><div class="icone"><i class="fas fa-scale-balanced"></i></div><div class="role">Juridique</div><h3>LegalFlow</h3><p>La gestion juridique.</p></div>
            <div class="carte appli apparait" data-incline><div class="icone"><i class="fas fa-robot"></i></div><div class="role">Intelligence artificielle</div><h3>Agent-AI</h3><p>L'assistance par intelligence artificielle.</p></div>
        </div>
    </div>
</section>

<section class="bloc">
    <div class="conteneur">
        <div class="appel apparait">
            <div class="surtitre">Prêt à normaliser</div>
            <h2>Voyez Selflow à l'œuvre sur vos propres pièces</h2>
            <p class="intro" style="margin:0 auto;">Laissez votre adresse et votre numéro WhatsApp : nous vous envoyons la démonstration.</p>
            <div class="boutons">
                <button type="button" class="btn btn-vert" data-demo><i class="fas fa-circle-play"></i> Voir une démo</button>
                <a href="{{ route('offres') }}" class="btn btn-verre"><i class="fas fa-tags"></i> Voir les offres</a>
            </div>
        </div>
    </div>
</section>

<footer>
    <div class="conteneur">
        <span>&copy; {{ date('Y') }} DC-Knowing. Selflow est une application de DC-Knowing.</span>
        <nav>
            <a href="{{ route('vitrine.guide') }}">Guide d'utilisation</a>
            <a href="{{ route('vitrine.politique') }}">Politique d'utilisation</a>
            <a href="{{ route('offres') }}">Offres</a>
            <a href="{{ route('contact.info') }}">Contact</a>
            <a href="{{ route('connexion') }}">Se connecter</a>
            <a href="{{ route('inscription') }}">Créer un compte</a>
        </nav>
    </div>
</footer>

{{-- La demande de démonstration --}}
<div class="voile" id="voile-demo" role="dialog" aria-modal="true" aria-labelledby="titre-demo">
    <form class="fenetre" id="formulaire-demo" novalidate>
        <h3 id="titre-demo">Recevoir une démo</h3>
        <p class="aide">Nous vous l'envoyons par courriel et sur WhatsApp.</p>
        <label for="demo-email">Adresse électronique</label>
        <input type="email" id="demo-email" name="email" required autocomplete="email" placeholder="vous@entreprise.ci">
        <label for="demo-whatsapp">Numéro WhatsApp</label>
        <input type="tel" id="demo-whatsapp" name="whatsapp" required autocomplete="tel" placeholder="+225 07 00 00 00 00">
        <label>Vous êtes</label>
        <div class="choix">
            <label><input type="checkbox" name="profils[]" value="chef_entreprise"> Chef d'entreprise</label>
            <label><input type="checkbox" name="profils[]" value="employe"> Employé</label>
        </div>
        <div class="message" id="demo-message" role="status"></div>
        <div class="pied">
            <button type="button" class="btn btn-verre" data-fermer>Fermer</button>
            <button type="submit" class="btn btn-plein" id="demo-envoyer">Envoyer</button>
        </div>
    </form>
</div>

<script src="{{ asset('vendor/three/three.min.js') }}"></script>
<script>
(function () {
    var mouvementReduit = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // En-tête qui se pose sur le contenu au défilement
    var entete = document.getElementById('entete');
    var surDefilement = function () { entete.classList.toggle('defile', window.scrollY > 30); };
    window.addEventListener('scroll', surDefilement, { passive: true }); surDefilement();

    // Apparition au défilement
    var observateur = new IntersectionObserver(function (entrees) {
        entrees.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('vu'); observateur.unobserve(e.target); } });
    }, { threshold: 0.15 });
    document.querySelectorAll('.apparait').forEach(function (el, i) { el.style.transitionDelay = (i % 6) * 70 + 'ms'; observateur.observe(el); });

    // Cartes qui s'inclinent sous la souris
    if (!mouvementReduit) {
        document.querySelectorAll('[data-incline]').forEach(function (carte) {
            carte.addEventListener('mousemove', function (ev) {
                var r = carte.getBoundingClientRect();
                var x = (ev.clientX - r.left) / r.width - .5, y = (ev.clientY - r.top) / r.height - .5;
                carte.style.transform = 'rotateY(' + (x * 14) + 'deg) rotateX(' + (-y * 14) + 'deg) translateZ(10px)';
            });
            carte.addEventListener('mouseleave', function () { carte.style.transform = ''; });
        });
    }

    // La scène : les factures partent des points de vente, traversent
    // l'anneau de la DGI et en ressortent certifiées, avec leur code QR.
    var canvas = document.getElementById('scene');
    if (!window.THREE || !canvas) return;

    var rendu = new THREE.WebGLRenderer({ canvas: canvas, antialias: true, alpha: true });
    rendu.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    var scene = new THREE.Scene();
    scene.fog = new THREE.FogExp2(0x062e6f, 0.03);
    var camera = new THREE.PerspectiveCamera(45, 1, 0.1, 100);
    camera.position.set(0, 1.2, 13);

    scene.add(new THREE.AmbientLight(0x6b7fb0, 0.9));
    var lumiere = new THREE.PointLight(0x22d3ee, 2.2, 40); lumiere.position.set(4, 3, 6); scene.add(lumiere);
    var lumiere2 = new THREE.PointLight(0x10b981, 1.6, 40); lumiere2.position.set(-6, -2, 4); scene.add(lumiere2);

    var groupe = new THREE.Group(); groupe.position.x = 3.2; scene.add(groupe);

    // L'anneau de la DGI
    var anneau = new THREE.Mesh(new THREE.TorusGeometry(2.2, 0.07, 24, 120), new THREE.MeshStandardMaterial({ color: 0xf08a1c, emissive: 0xf08a1c, emissiveIntensity: 0.8, metalness: .4, roughness: .3 }));
    anneau.rotation.y = Math.PI / 2; groupe.add(anneau);
    var halo = new THREE.Mesh(new THREE.TorusGeometry(2.2, 0.32, 16, 120), new THREE.MeshBasicMaterial({ color: 0xf08a1c, transparent: true, opacity: 0.08 }));
    halo.rotation.y = Math.PI / 2; groupe.add(halo);

    // Textures dessinées : la facture avant et après certification
    // La facture, dessinée d'après une vraie pièce certifiée par la DGI :
    // l'encadré de l'émetteur, le numéro, le code QR et le logo FNE, le
    // tableau des lignes et les totaux.
    function texture(certifiee) {
        var c = document.createElement('canvas'); c.width = 300; c.height = 400;
        var g = c.getContext('2d');
        g.fillStyle = '#ffffff'; g.fillRect(0, 0, 300, 400);
        g.strokeStyle = '#1f2937'; g.lineWidth = 1.5;
        g.strokeRect(14, 14, 150, 58);
        g.fillStyle = '#111827'; g.font = 'bold 12px sans-serif'; g.fillText('DC-KNOWING CGA', 22, 32);
        g.font = '10px sans-serif'; g.fillText('NCC : 1864699A', 22, 47); g.fillText('Régime : TEE', 22, 61);
        g.fillStyle = '#0b4aa2'; g.font = 'bold 13px sans-serif'; g.fillText('Selflow', 214, 34);
        g.fillStyle = '#111827'; g.font = 'bold 10px sans-serif'; g.fillText('Facture de vente N°', 176, 92);
        g.font = '10px sans-serif'; g.fillText(certifiee ? '1864699A26000000122' : 'VTE-01-161026-002', 176, 106);
        if (certifiee) {
            for (var x = 0; x < 11; x++) for (var y = 0; y < 11; y++) {
                var coin = (x < 3 && y < 3) || (x > 7 && y < 3) || (x < 3 && y > 7);
                if (coin || ((x * 5 + y * 3 + x * y) % 3) === 0) { g.fillStyle = '#111827'; g.fillRect(176 + x * 5, 116 + y * 5, 5, 5); }
            }
            g.lineWidth = 4; g.strokeStyle = '#f08a1c'; g.beginPath(); g.arc(262, 143, 21, 0, Math.PI * 2); g.stroke();
            g.fillStyle = '#16a34a'; g.font = 'bold 14px sans-serif'; g.fillText('fe', 253, 148);
            g.fillStyle = '#16a34a'; g.font = 'bold 7px sans-serif'; g.fillText('FACTURE NORMALISÉE', 229, 178);
        } else {
            g.strokeStyle = '#cbd5e1'; g.setLineDash([4, 3]); g.strokeRect(176, 116, 100, 56); g.setLineDash([]);
            g.fillStyle = '#94a3b8'; g.font = '9px sans-serif'; g.fillText('en attente de la DGI', 184, 148);
        }
        g.fillStyle = '#111827'; g.font = '10px sans-serif';
        g.fillText('Nom du PDV : PDV-marcory', 14, 92); g.fillText('Date : 16/10/2026', 14, 106); g.fillText('Client : Client de passage', 14, 120);
        g.strokeStyle = '#1f2937'; g.lineWidth = 1; g.strokeRect(14, 196, 272, 22); g.strokeRect(14, 218, 272, 26);
        g.font = 'bold 9px sans-serif'; g.fillText('Désignation', 20, 211); g.fillText('Qté', 150, 211); g.fillText('P.U HT', 178, 211); g.fillText('Montant HT', 230, 211);
        g.font = '9px sans-serif'; g.fillText('Casier sucrerie 30 cl', 20, 235); g.fillText('1', 154, 235); g.fillText('40 000', 178, 235); g.fillText('40 000', 240, 235);
        var lignes = [['TOTAL HT', '40 000'], ['TVA 18 %', '7 200'], ['TOTAL TTC', '47 200'], ['TOTAL A PAYER', '47 200']];
        lignes.forEach(function (l, k) {
            g.strokeRect(126, 252 + k * 22, 160, 22);
            g.font = (k === 3 ? 'bold ' : '') + '9px sans-serif'; g.fillText(l[0], 132, 267 + k * 22); g.fillText(l[1], 244, 267 + k * 22);
        });
        if (certifiee) {
            g.strokeStyle = '#16a34a'; g.lineWidth = 3; g.strokeRect(14, 352, 150, 32);
            g.fillStyle = '#16a34a'; g.font = 'bold 12px sans-serif'; g.fillText('CERTIFIÉE FNE', 26, 373);
        }
        var t = new THREE.CanvasTexture(c); t.anisotropy = 4; return t;
    }
    var avant = texture(false), apres = texture(true);

    // Les points de vente, d'où partent les factures
    var sites = [];
    [[-9, 2.2, -2], [-10, -1.4, 0], [-8.5, -3.4, -3]].forEach(function (p) {
        var s = new THREE.Mesh(new THREE.BoxGeometry(.8, .8, .8), new THREE.MeshStandardMaterial({ color: 0x2563eb, emissive: 0x1d4ed8, emissiveIntensity: .5, metalness: .3, roughness: .4, transparent: true, opacity: .55 }));
        s.position.set(p[0], p[1], p[2]); groupe.add(s); sites.push(s);
    });

    // La pile comptable, où arrivent les pièces certifiées
    for (var k = 0; k < 6; k++) {
        var feuille = new THREE.Mesh(new THREE.BoxGeometry(1.4, 0.08, 1.8), new THREE.MeshStandardMaterial({ color: 0x10b981, emissive: 0x047857, emissiveIntensity: .35 }));
        feuille.position.set(6, -2.6 + k * 0.12, -1); feuille.rotation.y = .4; groupe.add(feuille);
    }

    // Les factures en vol
    var factures = [];
    for (var n = 0; n < 7; n++) {
        var mat = new THREE.MeshBasicMaterial({ map: avant, side: THREE.DoubleSide, fog: false });
        var f = new THREE.Mesh(new THREE.PlaneGeometry(1.2, 1.6), mat);
        f.userData = { t: n / 7, site: sites[n % sites.length] };
        groupe.add(f); factures.push(f);
    }

    // Poussière d'étoiles
    var etoiles = new THREE.BufferGeometry(), pos = [];
    for (var e = 0; e < 600; e++) { pos.push((Math.random() - .5) * 40, (Math.random() - .5) * 24, (Math.random() - .5) * 30 - 6); }
    etoiles.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
    scene.add(new THREE.Points(etoiles, new THREE.PointsMaterial({ color: 0xffffff, size: 0.05, transparent: true, opacity: .55 })));

    function trajectoire(f, t) {
        var d = f.userData.site.position;
        var fin = new THREE.Vector3(6, -2 + (t * 0), -1);
        if (t < .5) {
            var u = t / .5;
            return new THREE.Vector3(d.x + (0 - d.x) * u, d.y + (0 - d.y) * u + Math.sin(u * Math.PI) * 1.4, d.z + (0 - d.z) * u);
        }
        var v = (t - .5) / .5;
        return new THREE.Vector3(0 + (fin.x) * v, 0 + (fin.y) * v + Math.sin(v * Math.PI) * 1.8, 0 + (fin.z) * v);
    }

    var souris = { x: 0, y: 0 };
    window.addEventListener('mousemove', function (ev) { souris.x = ev.clientX / window.innerWidth - .5; souris.y = ev.clientY / window.innerHeight - .5; });

    function redimensionner() {
        var l = canvas.clientWidth, h = canvas.clientHeight;
        rendu.setSize(l, h, false); camera.aspect = l / h; camera.updateProjectionMatrix();
        groupe.position.x = l < 900 ? 0 : 3.2; groupe.scale.setScalar(l < 900 ? .72 : 1);
    }
    window.addEventListener('resize', redimensionner); redimensionner();

    var horloge = new THREE.Clock();
    function animer() {
        var dt = Math.min(horloge.getDelta(), .05), temps = horloge.elapsedTime;
        factures.forEach(function (f) {
            f.userData.t = (f.userData.t + dt * .09) % 1;
            var t = f.userData.t;
            f.position.copy(trajectoire(f, t));
            f.rotation.y = Math.sin(temps * .9 + t * 6) * .35;
            f.rotation.x = Math.sin(temps * .8 + t * 4) * .15;
            var certifiee = t >= .5;
            if ((f.material.map === apres) !== certifiee) { f.material.map = certifiee ? apres : avant; f.material.needsUpdate = true; }
            var s = t < .06 ? t / .06 : (t > .94 ? (1 - t) / .06 : 1);
            f.scale.setScalar(Math.max(.01, s));
        });
        anneau.rotation.z += dt * .4; halo.rotation.z -= dt * .2;
        halo.material.opacity = .06 + Math.sin(temps * 2) * .03;
        sites.forEach(function (s, i) { s.rotation.y += dt * (.3 + i * .1); s.rotation.x += dt * .2; });
        camera.position.x += (souris.x * 1.6 - camera.position.x) * .04;
        camera.position.y += (1.2 - souris.y * 1.2 - camera.position.y) * .04;
        camera.lookAt(0, 0, 0);
        rendu.render(scene, camera);
        if (!mouvementReduit) requestAnimationFrame(animer);
    }
    animer();
})();

// La demande de démo
(function () {
    var voile = document.getElementById('voile-demo');
    var formulaire = document.getElementById('formulaire-demo');
    var message = document.getElementById('demo-message');
    var ouvrir = function () { voile.classList.add('ouvert'); message.className = 'message'; setTimeout(function () { document.getElementById('demo-email').focus(); }, 50); };
    var fermer = function () { voile.classList.remove('ouvert'); };
    document.querySelectorAll('[data-demo]').forEach(function (b) { b.addEventListener('click', ouvrir); });
    voile.addEventListener('click', function (e) { if (e.target === voile || e.target.hasAttribute('data-fermer')) fermer(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fermer(); });

    formulaire.addEventListener('submit', function (e) {
        e.preventDefault();
        var profils = Array.prototype.map.call(formulaire.querySelectorAll('input[name="profils[]"]:checked'), function (c) { return c.value; });
        var email = document.getElementById('demo-email').value.trim();
        var whatsapp = document.getElementById('demo-whatsapp').value.trim();
        if (!email || !whatsapp || profils.length === 0) {
            message.className = 'message ko'; message.textContent = 'Renseignez votre adresse, votre numéro WhatsApp et cochez au moins une case.'; return;
        }
        var bouton = document.getElementById('demo-envoyer'); bouton.disabled = true;
        fetch(@json(route('vitrine.demo')), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: JSON.stringify({ email: email, whatsapp: whatsapp, profils: profils })
        }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
          .then(function (x) {
              message.className = 'message ' + (x.ok ? 'ok' : 'ko');
              message.textContent = x.d.message || (x.ok ? 'Demande envoyée.' : 'Une erreur s’est produite. Veuillez réessayer plus tard.');
              if (x.ok) formulaire.reset();
          })
          .catch(function () { message.className = 'message ko'; message.textContent = 'Une erreur s’est produite. Veuillez réessayer plus tard.'; })
          .finally(function () { bouton.disabled = false; });
    });
})();
</script>
</body>
</html>

<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Guide d'utilisation · Selflow</title>
<meta name="description" content="Guide d'utilisation de Selflow : compte, paramétrage, points de vente, ventes, FNE, achats, stock, trésorerie, comptabilité et abonnement.">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/selflow/favicon-32.png') }}">
<link rel="stylesheet" href="{{ asset('vendor/inter/inter.css') }}">
<link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
<style>
    :root {
        --marine: #0a2a5e;
        --bleu: #1d4ed8;
        --bleu-pale: #eff6ff;
        --bleu-bord: #bfdbfe;
        --texte: #0f172a;
        --texte-2: #334155;
        --texte-3: #64748b;
        --bord: #e2e8f0;
        --fond-doux: #f8fafc;
        --ambre-fond: #fffbeb;
        --ambre-bord: #fde68a;
        --ambre-texte: #92400e;
    }
    * { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body { margin: 0; font-family: 'Inter', system-ui, sans-serif; background: #fff; color: var(--texte); line-height: 1.7; }
    a { color: var(--bleu); }
    header { background: var(--marine); color: #fff; padding: 18px 0; }
    .conteneur { width: min(960px, 100% - 32px); margin: 0 auto; }
    header .conteneur { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
    header img { height: 32px; display: block; }
    header nav { display: flex; gap: 18px; flex-wrap: wrap; }
    header nav a { color: #bfdbfe; text-decoration: none; font-weight: 700; font-size: 14px; }
    header nav a:hover { color: #fff; }

    .bandeau { background: linear-gradient(180deg, var(--bleu-pale), #fff); border-bottom: 1px solid var(--bord); padding: 44px 0 34px; }
    h1 { font-size: clamp(28px, 4.4vw, 42px); margin: 0 0 8px; color: var(--marine); line-height: 1.2; }
    .chapeau { font-size: 16.5px; color: var(--texte-2); max-width: 720px; margin: 0; }
    .maj { color: var(--texte-3); font-size: 13.5px; margin-top: 12px; }

    main { padding: 34px 0 80px; }

    .sommaire { border: 1px solid var(--bord); border-radius: 16px; padding: 20px 22px; background: var(--fond-doux); }
    .sommaire h2 { margin: 0 0 12px; font-size: 15px; text-transform: uppercase; letter-spacing: .06em; color: var(--texte-3); border: 0; padding: 0; }
    .sommaire ol { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px 18px; counter-reset: som; }
    .sommaire li { counter-increment: som; font-size: 14.5px; }
    .sommaire a { display: flex; gap: 8px; align-items: baseline; text-decoration: none; color: var(--marine); font-weight: 600; padding: 4px 0; }
    .sommaire a::before { content: counter(som); min-width: 22px; height: 22px; border-radius: 50%; background: var(--bleu); color: #fff; font-size: 11.5px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; transform: translateY(-1px); }
    .sommaire a:hover { color: var(--bleu); }

    section { scroll-margin-top: 16px; padding-top: 18px; }
    section + section { border-top: 1px solid var(--bord); margin-top: 34px; }
    h2 { font-size: clamp(21px, 3vw, 26px); margin: 18px 0 10px; color: var(--marine); display: flex; align-items: center; gap: 12px; }
    h2 .num { width: 34px; height: 34px; border-radius: 10px; background: var(--bleu); color: #fff; font-size: 15px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
    h3 { font-size: 17px; margin: 24px 0 8px; color: var(--marine); }
    p, li { font-size: 15.5px; color: var(--texte-2); }
    ul { padding-left: 20px; }

    .chemin { display: inline-block; background: var(--bleu-pale); border: 1px solid var(--bleu-bord); color: var(--marine); border-radius: 8px; padding: 1px 8px; font-size: 14px; font-weight: 600; white-space: normal; }
    .bouton { display: inline-block; background: var(--marine); color: #fff; border-radius: 6px; padding: 0 7px; font-size: 13.5px; font-weight: 600; }

    ol.etapes { list-style: none; padding: 0; margin: 12px 0; counter-reset: etape; }
    ol.etapes > li { counter-increment: etape; position: relative; padding: 2px 0 12px 44px; }
    ol.etapes > li::before { content: counter(etape); position: absolute; left: 0; top: 2px; width: 28px; height: 28px; border-radius: 50%; border: 2px solid var(--bleu); color: var(--bleu); font-weight: 800; font-size: 13px; display: flex; align-items: center; justify-content: center; background: #fff; }
    ol.etapes > li:not(:last-child)::after { content: ''; position: absolute; left: 14px; top: 32px; bottom: 0; width: 2px; background: var(--bleu-bord); }
    ol.etapes ul { margin: 6px 0 0; }

    .encadre { background: var(--bleu-pale); border: 1px solid var(--bleu-bord); border-radius: 14px; padding: 14px 18px; margin: 14px 0; }
    .encadre p { margin: 4px 0; }
    .attention { background: var(--ambre-fond); border: 1px solid var(--ambre-bord); border-radius: 14px; padding: 14px 18px; margin: 14px 0; }
    .attention p, .attention li { color: var(--ambre-texte); }
    .encadre strong, .attention strong { color: inherit; }
    .titre-encadre { font-weight: 700; display: flex; align-items: center; gap: 8px; margin-bottom: 2px; }

    .tableau { width: 100%; overflow-x: auto; margin: 12px 0; }
    table { border-collapse: collapse; width: 100%; font-size: 14.5px; }
    th, td { text-align: left; padding: 9px 12px; border-bottom: 1px solid var(--bord); vertical-align: top; color: var(--texte-2); }
    th { background: var(--fond-doux); color: var(--marine); font-weight: 700; }

    .haut { display: inline-block; margin-top: 10px; font-size: 13px; color: var(--texte-3); text-decoration: none; }
    .haut:hover { color: var(--bleu); }

    footer { padding: 30px 0; color: var(--texte-3); font-size: 14px; border-top: 1px solid var(--bord); background: var(--fond-doux); }
    footer a { color: inherit; }

    @media (max-width: 820px) {
        .sommaire ol { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 540px) {
        .sommaire ol { grid-template-columns: 1fr; }
        ol.etapes > li { padding-left: 38px; }
        header nav { gap: 12px; }
        h2 .num { width: 30px; height: 30px; font-size: 14px; }
    }
    @media print {
        header nav, .haut { display: none; }
        section { break-inside: avoid-page; }
    }
</style>
</head>
<body id="haut">
<header>
    <div class="conteneur">
        <a href="{{ url('/') }}"><img src="{{ asset('images/selflow/logo-blanc.png') }}" alt="Selflow"></a>
        <nav aria-label="Liens du site">
            <a href="{{ url('/connexion') }}">Se connecter</a>
            <a href="{{ url('/offres') }}">Nos offres</a>
            <a href="{{ url('/') }}">Accueil</a>
        </nav>
    </div>
</header>

<div class="bandeau">
    <div class="conteneur">
        <h1>Guide d'utilisation de Selflow</h1>
        <p class="chapeau">
            Ce guide suit l'application écran par écran : où cliquer, quoi remplir, et ce qui se passe ensuite.
            Les noms entre cadres bleus sont ceux du menu de gauche ; les boutons sont écrits en blanc sur fond bleu.
        </p>
        <div class="maj">Mise à jour : {{ \Illuminate\Support\Carbon::create(2026, 11, 23)->translatedFormat('j F Y') }}</div>
    </div>
</div>

<main>
<div class="conteneur">

    <nav class="sommaire" aria-labelledby="titre-sommaire">
        <h2 id="titre-sommaire">Sommaire</h2>
        <ol>
            <li><a href="#compte">Créer son compte et se connecter</a></li>
            <li><a href="#entreprise">Paramétrer l'entreprise</a></li>
            <li><a href="#points-de-vente">Les points de vente</a></li>
            <li><a href="#utilisateurs">Utilisateurs et habilitations</a></li>
            <li><a href="#articles">Les articles</a></li>
            <li><a href="#vendre">Vendre</a></li>
            <li><a href="#fne-a-integrer">Factures FNE à intégrer</a></li>
            <li><a href="#acheter">Acheter</a></li>
            <li><a href="#stock">Le stock</a></li>
            <li><a href="#tresorerie">La trésorerie</a></li>
            <li><a href="#comptabilite">La comptabilité</a></li>
            <li><a href="#fne-dgi">Le menu FNE DGI</a></li>
            <li><a href="#rapports">Les rapports</a></li>
            <li><a href="#abonnement">Abonnement et offres</a></li>
            <li><a href="#assistance">Assistance</a></li>
        </ol>
    </nav>

    <div class="encadre">
        <p class="titre-encadre"><i class="fas fa-circle-info" aria-hidden="true"></i> Ce que vous voyez dépend de votre compte</p>
        <p>Le menu de gauche ne montre que les écrans ouverts par vos habilitations et par les modules de votre entreprise.
            Il est rangé en familles (Pilotage, Commerce, Opérations, Finance et fiscalité, Entreprise) ; cliquez sur le titre
            d'une section pour la déplier ou la replier.</p>
    </div>

    {{-- 1. Compte --}}
    <section id="compte">
        <h2><span class="num">1</span> Créer son compte et se connecter</h2>

        <h3>S'inscrire</h3>
        <p>L'inscription se fait sur la page <a href="{{ url('/inscription') }}">Créer votre compte</a>, en trois étapes. Une jauge montre où vous en êtes, et le bouton <span class="bouton">Retour</span> ramène à l'étape précédente.</p>
        <ol class="etapes">
            <li><strong>L'entreprise.</strong> Saisissez le nom sous lequel vous facturez et choisissez le pack souhaité. Le pack est transmis à l'équipe pour validation. Cliquez sur <span class="bouton">Suivant</span>.</li>
            <li><strong>Le responsable et son accès.</strong> Nom, prénom, fonction, adresse électronique professionnelle, téléphone, mot de passe (au moins 8 caractères) et sa confirmation. Cochez l'acceptation des conditions d'utilisation, puis <span class="bouton">Suivant</span>.</li>
            <li><strong>La facture normalisée (facultative).</strong> Indiquez si vous avez déjà un compte FNE auprès de la DGI :
                <ul>
                    <li><em>J'ai déjà un compte FNE</em> : donnez votre NCC et le mot de passe de votre espace FNE. Selflow relève votre paramétrage ; le mot de passe est chiffré et effacé une fois la configuration faite.</li>
                    <li><em>Je n'en ai pas encore</em> : donnez les informations que la DGI demande pour en ouvrir un (RCCM, NCC, régime d'imposition, centre des impôts…).</li>
                </ul>
                Terminez par <span class="bouton">Créer mon compte</span>, ou par <span class="bouton">Terminer sans remplir la suite</span> pour compléter plus tard depuis vos paramètres.</li>
        </ol>
        <p>Il est aussi possible de s'inscrire avec <strong>S'inscrire avec Google</strong>, proposé pendant les deux premières étapes.</p>
        <div class="attention">
            <p><strong>Votre espace s'ouvre après validation.</strong> À l'envoi, Selflow affiche « Votre demande d'inscription a bien été reçue ». L'espace devient accessible une fois la demande validée par l'équipe DC-Knowing.</p>
        </div>

        <h3>Se connecter</h3>
        <ol class="etapes">
            <li>Ouvrez la page <a href="{{ url('/connexion') }}">de connexion</a>.</li>
            <li>Saisissez votre adresse électronique et votre mot de passe. Cochez <em>Se souvenir de moi</em> sur un poste personnel.</li>
            <li>Cliquez sur <span class="bouton">Se connecter</span>. Le bouton de connexion Google est aussi proposé.</li>
        </ol>
        <p>Après plusieurs essais manqués en peu de temps, la connexion est bloquée quelques instants : le message indique le nombre de secondes à attendre.</p>
        <p>Un compte créé par votre administrateur porte un mot de passe provisoire : Selflow vous demande d'en choisir un nouveau à la première connexion.</p>

        <h3>Mot de passe oublié</h3>
        <ol class="etapes">
            <li>Sur la page de connexion, cliquez sur <strong>Mot de passe oublié ?</strong>.</li>
            <li>Saisissez votre adresse électronique, puis <span class="bouton">Envoyer le lien</span>.</li>
            <li>Ouvrez le courriel reçu et suivez le lien. Il est valable 60 minutes.</li>
            <li>Saisissez le nouveau mot de passe deux fois, puis <span class="bouton">Enregistrer le mot de passe</span>.</li>
        </ol>
        <p>Si le serveur ne peut pas envoyer de courriel, la page le dit : contactez alors l'assistance pour réinitialiser votre mot de passe.</p>
        <a class="haut" href="#haut">Retour en haut</a>
    </section>

    {{-- 2. Entreprise --}}
    <section id="entreprise">
        <h2><span class="num">2</span> Paramétrer l'entreprise</h2>
        <p>Tout se règle dans <span class="chemin">Entreprise › Paramètres &amp; logos</span> (réservé à l'administrateur). Un bandeau en haut de la page donne accès directement à chaque partie : FNE DGI, L'entreprise, Comptabilité, Documents.</p>

        <h3>Les informations de l'entreprise</h3>
        <ol class="etapes">
            <li>Dans <em>Informations générales</em>, renseignez le nom de l'entreprise, le gérant (nom, prénom, fonction, courriel), l'adresse physique, le téléphone, le courriel et la forme juridique.</li>
            <li>Dans l'identité fiscale, saisissez le NCC, le régime d'imposition, le RCCM, le centre des impôts et vos références bancaires. Les régimes proposés sont TEE, TCE, RME, RNE, RSI et RNI.</li>
            <li>Dans <em>DGI &amp; Local professionnel</em>, complétez la commune, le quartier, la référence cadastrale et le propriétaire du local.</li>
            <li>Cliquez sur <span class="bouton">Enregistrer les paramètres</span> en bas de page.</li>
        </ol>
        <p>Si votre espace FNE a déjà été relevé, certains champs sont grisés : ils se remplissent automatiquement avec les informations exactes de votre espace FNE. Remplissez seulement le reste.</p>

        <h3>Le logo</h3>
        <ol class="etapes">
            <li>Dans <em>Logos (affichés sur les factures)</em>, choisissez votre fichier sous <em>Logo principal de l'entreprise</em> (PNG, JPG ou SVG, 2 Mo au plus).</li>
            <li>Enregistrez. Le logo apparaît en haut à gauche des factures. Le logo secondaire, celui de la FNE, est posé par le système et ne se change pas.</li>
        </ol>

        <h3>Le compte FNE</h3>
        <p>La partie <em>Compte sur la plateforme FNE</em> affiche l'état de votre compte. Si vous ne l'avez pas indiqué à l'inscription, choisissez <em>J'ai déjà un compte FNE</em> (NCC et mot de passe de l'espace FNE) ou <em>Je n'en ai pas encore</em> (la page liste alors les informations fiscales qui manquent).</p>
        <div class="encadre">
            <p class="titre-encadre"><i class="fas fa-key" aria-hidden="true"></i> La clé FNE est posée par le superadministrateur</p>
            <p>La partie <em>FNE, Facture Normalisée Électronique</em> montre le statut de la clé et la date de la dernière vérification. Cette clé n'est ni affichée ni modifiable par l'entreprise : seul le support Selflow la configure, à partir de la clé que vous recevez de la DGI. Sans clé, contactez le support. En phase de test DGI, les factures normalisées ne sont pas encore fiscalement définitives. Le bouton <span class="bouton">Tester la connexion</span> vérifie que le serveur de la DGI répond.</p>
        </div>

        <h3>Normalisation automatique ou manuelle</h3>
        <p>Dans <em>Options fiscales</em>, la case <strong>Normaliser automatiquement les factures et les reçus</strong> décide du moment de la certification :</p>
        <ul>
            <li><strong>cochée</strong> : chaque pièce part à la DGI dès son émission ;</li>
            <li><strong>décochée</strong> : la pièce est enregistrée avec la mention <em>En attente</em> dans la liste des ventes. Vous la normalisez vous-même, après vérification, avec le bouton <span class="bouton">Normaliser</span>.</li>
        </ul>
        <p>Le timbre de quittance et l'option BAPA sont repris de votre espace FNE à chaque relevé du portail : ils se règlent sur l'espace FNE, pas dans Selflow.</p>

        <h3>Les autres réglages utiles</h3>
        <ul>
            <li><strong>Ventes</strong> : <em>Masquer les articles en rupture à la vente</em> retire de la caisse les articles stockables sans stock sur le point de vente.</li>
            <li><strong>Impression des factures</strong> : <em>Afficher le solde du client sur les factures</em> ajoute le solde antérieur d'un client connu. Ces montants ne partent pas à la DGI. Vous y réglez aussi le pied de page et les mentions légales.</li>
            <li><strong>Votre configuration</strong> : <span class="bouton">Ajouter une configuration</span> rouvre le parcours (domaine, secteur précis, modules, catégories, prix) sans rien effacer de l'existant.</li>
            <li><strong>Exercices comptables</strong> : l'exercice en cours se choisit en haut du menu de gauche, sous <em>Période en cours</em>.</li>
        </ul>
        <a class="haut" href="#haut">Retour en haut</a>
    </section>

    {{-- 3. Points de vente --}}
    <section id="points-de-vente">
        <h2><span class="num">3</span> Les points de vente</h2>
        <p>Chaque vente, achat, entrée de stock et encaissement est rattaché à un point de vente. Il en faut au moins un.</p>

        <h3>Créer un point de vente</h3>
        <ol class="etapes">
            <li>Ouvrez <span class="chemin">Points de vente › Points de vente</span>.</li>
            <li>Cliquez sur <span class="bouton">Nouveau point de vente</span>. Le compteur en haut indique combien votre offre en autorise.</li>
            <li>Saisissez le nom, la ville, puis si vous le souhaitez la commune, le téléphone et le responsable. Cliquez sur <span class="bouton">Créer</span>.</li>
        </ol>
        <div class="attention">
            <p class="titre-encadre"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> Le nom part à la DGI</p>
            <p>Le nom du point de vente est transmis tel quel à la DGI avec chaque facture. Il doit correspondre exactement à un établissement (point de facturation) déclaré sur votre espace FNE. Une facture émise sous un autre nom est refusée, et Selflow ne peut pas déclarer un établissement à votre place.</p>
        </div>
        <p>La carte <em>Points de facturation déclarés au portail FNE</em> aide à vérifier : elle liste les points relevés sur le portail, marqués <em>Dans Selflow</em> ou <em>À créer</em>, et signale ceux de Selflow inconnus du portail. Vous pouvez :</p>
        <ul>
            <li>cliquer sur <span class="bouton">Relever le portail maintenant</span> après avoir déclaré un nouveau point sur votre espace FNE ;</li>
            <li>cliquer sur <span class="bouton">Reprendre les points manquants</span> pour créer dans Selflow ceux qui n'y sont pas ;</li>
            <li>utiliser <span class="bouton">Charger un relevé</span> avec le fichier exporté de votre espace FNE (.json, .xlsx ou .xls, nommé avec votre NCC et la date).</li>
        </ul>

        <h3>Choisir le point de vente actif</h3>
        <ol class="etapes">
            <li>Le point de vente actif est affiché en haut du menu de gauche. Cliquez dessus (<em>Changer de point de vente</em>).</li>
            <li>Sur la page des points de vente, cliquez sur <span class="bouton">Activer pour cette session</span> sous le point voulu.</li>
            <li>Vos ventes, achats, entrées de stock et articles sont désormais enregistrés sur ce point de vente.</li>
        </ol>
        <p>Si l'entreprise a plusieurs points de vente et qu'aucun n'est actif, Selflow ouvre la fenêtre <em>Sélectionnez un point de vente</em>. Avec un seul point de vente, il est activé d'office.</p>

        <h3>Ce que voit un caissier ou un responsable de point de vente</h3>
        <ul>
            <li>Un caissier ou un responsable affecté à un point de vente travaille sur ce point de vente seulement. Il n'en change pas, et ne voit que les données de son site.</li>
            <li>Le caissier dispose d'un menu réduit : <span class="chemin">Nouvelle vente</span>, <span class="chemin">Mes factures</span>, <span class="chemin">Consulter stock</span>, <span class="chemin">Créances &amp; règlements</span> et <span class="chemin">Mes encaissements</span>, selon ses habilitations.</li>
            <li>L'administrateur peut se mettre à sa place avec <span class="bouton">Apercevoir l'interface caissier</span> : un bandeau rappelle que l'aperçu est en lecture seule.</li>
        </ul>
        <a class="haut" href="#haut">Retour en haut</a>
    </section>

    {{-- 4. Utilisateurs --}}
    <section id="utilisateurs">
        <h2><span class="num">4</span> Les utilisateurs et les habilitations</h2>
        <h3>Créer un accès</h3>
        <ol class="etapes">
            <li>Ouvrez <span class="chemin">Points de vente › Personnels &amp; accès</span> et cliquez sur <span class="bouton">Créer un accès</span>.</li>
            <li>Saisissez nom, prénom, adresse électronique et un mot de passe provisoire.</li>
            <li>Choisissez le rôle :
                <ul>
                    <li><em>Caissier</em> et <em>Responsable Point de Vente</em> : accès restreint, limité aux habilitations cochées ;</li>
                    <li><em>Administrateur secondaire</em> et <em>Administrateur principal</em> : tous les droits.</li>
                </ul></li>
            <li>Choisissez l'affectation : un point de vente, ou <em>Aucun (Siège)</em>. Ajoutez si besoin la fonction, les dates de contrat et des notes internes.</li>
            <li>Cochez les habilitations (pages ouvertes à ce membre), puis <span class="bouton">Enregistrer le personnel</span>.</li>
        </ol>
        <p>Le nombre d'utilisateurs suit votre offre : au-delà, Selflow refuse la création et propose de changer d'offre.</p>

        <h3>Modifier, bloquer, voir les habilitations</h3>
        <ul>
            <li><span class="bouton">Détails</span> sur une ligne ouvre la fiche : rôle, point de vente affecté, habilitations, nouveau mot de passe. Validez par <span class="bouton">Enregistrer les modifications</span>.</li>
            <li><span class="bouton">Bloquer</span> suspend l'accès ; <span class="bouton">Activer</span> le rétablit.</li>
            <li><span class="chemin">Points de vente › Habilitations</span> montre la grille de tous les membres et des pages qui leur sont ouvertes. Seuls les modules de votre entreprise y figurent.</li>
        </ul>
        <a class="haut" href="#haut">Retour en haut</a>
    </section>

    {{-- 5. Articles --}}
    <section id="articles">
        <h2><span class="num">5</span> Les articles</h2>
        <p>Le catalogue est dans <span class="chemin">Produits › Catalogue produits</span>. Il montre les articles du point de vente actif ; un lien permet de voir tout le catalogue de l'entreprise. La recherche porte sur le nom ou la référence, et le filtre sur la catégorie.</p>

        <h3>Ajouter un article</h3>
        <ol class="etapes">
            <li>Cliquez sur <span class="bouton">Ajouter un produit</span>.</li>
            <li>Choisissez le <strong>type d'article</strong>. Il décide si le stock est suivi :
                <ul>
                    <li>stockables : Marchandise, Matière première, Produit fini, Consommable stockable ;</li>
                    <li>non stockables : Consommable non stockable, Service (non physique).</li>
                </ul></li>
            <li>Choisissez la <strong>catégorie</strong> (la famille de l'article) et, si besoin, une sous-catégorie. Vous pouvez en créer une nouvelle depuis la liste, avec son préfixe de 3 ou 4 lettres.</li>
            <li>Saisissez le nom, la référence (laissée vide, elle est générée automatiquement) et l'unité.</li>
            <li>Réglez la <strong>TVA</strong> : le régime est déduit du taux et du régime d'imposition de l'entreprise. Cochez <em>Choisir le régime manuellement</em> pour choisir entre TVA normale 18 %, TVAB 9 %, TVAC (exonération conventionnelle) et TVAD (exonération légale). Ajoutez une remise par défaut ou d'autres taxes si l'article en porte.</li>
            <li>Si la comptabilité est activée, les comptes de vente et d'achat de l'article s'affichent. Laissés tels quels, ils suivent la configuration des comptes.</li>
            <li>Saisissez le prix d'achat, le prix de vente, le stock initial et le stock minimum, puis <span class="bouton">Ajouter au catalogue</span>.</li>
        </ol>
        <p>Le stock initial entre comme un mouvement « Stock d'ouverture » et donne le premier coût moyen. Pour beaucoup d'articles, utilisez <span class="bouton">Importer CSV</span>.</p>
        <p>Les fiches clients et fournisseurs se gèrent dans <span class="chemin">Tiers › Clients</span> et <span class="chemin">Tiers › Fournisseurs</span>. Le NCC d'un client professionnel part sur ses factures.</p>
        <a class="haut" href="#haut">Retour en haut</a>
    </section>

    {{-- 6. Vendre --}}
    <section id="vendre">
        <h2><span class="num">6</span> Vendre</h2>

        <h3>Faire une vente</h3>
        <p>Ouvrez <span class="chemin">Ventes › Nouvelle vente</span>. Deux présentations du même formulaire sont proposées en haut : <em>Caisse</em> (tout sur un écran) et <em>Pas à pas</em> (quatre étapes). Le navigateur retient votre choix.</p>
        <ol class="etapes">
            <li><strong>Articles.</strong> Cliquez sur les articles du catalogue pour les mettre au panier et ajustez les quantités. <em>Saisie libre</em> ajoute une ligne hors catalogue (désignation, prix, quantité, code TVA).</li>
            <li><strong>Client et document.</strong> Choisissez le client (facultatif : sans client, c'est une vente de passage) et la date d'établissement. Choisissez le type de document : <em>Devis</em>, <em>Commande</em> ou <em>Facture + Reçu</em>. Un devis ou une commande porte une date de validité (trente jours par défaut).</li>
            <li><strong>Règlement</strong> (facture seulement). Choisissez Caisse, Banque, Mobile Money ou Crédit, puis le montant reçu. La monnaie rendue est calculée.</li>
            <li><strong>Valider.</strong> Cliquez sur <span class="bouton">Valider et facturer</span> (ou <span class="bouton">Enregistrer le devis</span>, <span class="bouton">Enregistrer &amp; Envoyer le bon de commande</span>). Un second clic n'enregistre rien de plus.</li>
        </ol>

        <h3>Le règlement : comptant, crédit, acompte</h3>
        <ul>
            <li><strong>Montant reçu égal au net à payer</strong> : la facture est payée.</li>
            <li><strong>Mode Crédit, ou rien de reçu</strong> : la facture est à crédit et devient une créance du client.</li>
            <li><strong>Montant partiel</strong> : la facture est marquée <em>Avance</em> ; le reste est dû.</li>
            <li>Les acomptes déjà reçus sur une commande ou son devis sont repris sur la facture qui en découle.</li>
            <li>Le reste se règle plus tard dans <span class="chemin">Créances &amp; règlements</span> (voir <a href="#tresorerie">la trésorerie</a>).</li>
        </ul>

        <h3>Devis, bon de commande, bon de livraison, facture</h3>
        <p><span class="chemin">Ventes › Factures vente</span> ouvre les <em>Cycles de vente</em>, avec quatre onglets : Devis, Bon de commande, Bon de Livraison, Facture.</p>
        <ol class="etapes">
            <li><strong>Devis</strong> : <span class="bouton">Modifier</span>, <span class="bouton">Envoyer</span>, puis <span class="bouton">→ Commande</span> pour le transformer en bon de commande.</li>
            <li><strong>Bon de commande</strong> : <span class="bouton">→ BL</span> crée un bon de livraison pour le reste à livrer. Une commande peut avoir plusieurs bons de livraison.</li>
            <li><strong>Bon de livraison</strong> : <span class="bouton">Livré</span> ouvre le bon pour confirmer l'arrivée ; <span class="bouton">Facturer</span> établit la facture des quantités livrées.</li>
            <li><strong>Facture</strong> : la pièce définitive, avec son reçu.</li>
        </ol>
        <p>Un devis ou un bon de commande qui n'a été ni transformé ni livré peut être annulé avec <span class="bouton">Annuler</span> ; un motif est demandé. Les documents traités passent dans les <em>Archives</em>.</p>

        <h3>La facture et le reçu de caisse</h3>
        <p>Le reçu n'est pas une autre pièce : c'est la facture mise en page pour le ticket. Une seule pièce, un seul envoi à la DGI, un seul code QR, un seul sticker. Dans la liste, <span class="bouton">Facture</span> ouvre le format A4 et <span class="bouton">Reçu</span> le ticket de 80 mm, chacun imprimable ou téléchargeable en PDF.</p>

        <h3>Normaliser une facture (FNE)</h3>
        <ol class="etapes">
            <li>Avec la normalisation automatique, rien à faire : la pièce part à la DGI dès son émission.</li>
            <li>Sinon, dans l'onglet Facture, la pièce porte <em>En attente</em>. Vérifiez-la, puis cliquez sur <span class="bouton">Normaliser</span> : la facture et le reçu sont certifiés ensemble.</li>
            <li>Pour plusieurs pièces, cochez-les (15 au plus) et cliquez sur <span class="bouton">Normaliser la sélection</span>, ou <span class="bouton">Planifier une normalisation automatique</span> sur une période.</li>
            <li>Une fois certifiée, la pièce affiche le document de la DGI et son code QR de vérification. Une pièce refusée par la DGI est signalée, et le détail se trouve dans les paramètres, partie <em>Pièces refusées par la DGI</em>.</li>
        </ol>
        <div class="attention">
            <p class="titre-encadre"><i class="fas fa-lock" aria-hidden="true"></i> Une pièce certifiée ne se modifie plus</p>
            <p>Une facture normalisée ne peut plus être modifiée, ni annulée, ni supprimée. Une erreur se corrige par un <strong>avoir</strong>, lui aussi certifié.</p>
        </div>

        <h3>Faire un avoir</h3>
        <ol class="etapes">
            <li>Ouvrez <span class="chemin">Ventes › Avoirs clients</span> et cliquez sur <span class="bouton">Créer une facture d'avoir</span>.</li>
            <li>Choisissez la facture d'origine et saisissez le motif de l'avoir.</li>
            <li>Ajustez les quantités à créditer article par article, et indiquez pour chacun l'action sur le stock.</li>
            <li>Cliquez sur <span class="bouton">Créer la Facture d'Avoir</span>.</li>
        </ol>

        <h3>Annuler une facture non normalisée</h3>
        <p>Tant qu'une facture n'est pas certifiée, le bouton <span class="bouton">Annuler</span> l'annule après saisie d'un motif (selon vos habilitations). Selflow établit un avoir interne, marqué <em>Annulation interne</em>, qui ne part pas à la DGI.</p>
        <a class="haut" href="#haut">Retour en haut</a>
    </section>

    {{-- 7. Factures FNE à intégrer --}}
    <section id="fne-a-integrer">
        <h2><span class="num">7</span> Les factures FNE émises hors de Selflow</h2>
        <p>Les factures que vous avez émises directement sur le portail de la DGI sont relevées par Selflow. Tant qu'il en reste, un bandeau les signale dans <em>Factures vente</em>. Elles se reprennent dans <span class="chemin">Ventes › Factures FNE à intégrer</span>.</p>
        <ol class="etapes">
            <li><strong>Point de vente.</strong> Selflow propose celui dont le nom correspond au point de vente écrit sur la facture. Sinon, choisissez-le dans la liste.</li>
            <li><strong>Client.</strong> Il est retrouvé par son NCC ou son nom. La page indique <em>Client existant de Selflow</em>, <em>Sera créé à l'intégration</em> ou <em>Vente de passage, sans fiche client</em>.</li>
            <li><strong>Articles.</strong> Chaque ligne est rapprochée d'un article du catalogue par sa référence puis par son libellé. Corrigez le choix si besoin, ou laissez <em>Ligne libre (hors catalogue)</em>.</li>
            <li><strong>Compte.</strong> Pour une ligne hors catalogue, un compte de produit est proposé (la configuration générale, sinon 706000 pour une entreprise de services, 701000 pour les autres). Vous pouvez le modifier.</li>
            <li><strong>Intégrer.</strong> Cliquez sur <span class="bouton">Intégrer comme vente normalisée</span>, ou sur <span class="bouton">Intégrer celles qui sont prêtes</span> pour tout reprendre d'un coup. <span class="bouton">Écarter</span> retire une facture de la liste ; elle ne sera plus proposée.</li>
        </ol>
        <div class="encadre">
            <p>Une fois intégrée, la facture devient une vente normalisée comme les autres : elle compte dans les factures, le solde du client et la comptabilité. Rien n'est renvoyé à la DGI. Une facture payée à crédit sur le portail devient une créance. Les factures déjà normalisées depuis Selflow ne sont jamais reprises en double.</p>
            <p>Chaque ligne stockable d'une facture intégrée crée une sortie à valider dans <a href="#stock">Proposition entrée/sortie</a>.</p>
        </div>
        <a class="haut" href="#haut">Retour en haut</a>
    </section>

    {{-- 8. Acheter --}}
    <section id="acheter">
        <h2><span class="num">8</span> Acheter</h2>

        <h3>Saisir un achat</h3>
        <ol class="etapes">
            <li>Ouvrez <span class="chemin">Achats › Nouvel achat</span> et ajoutez les articles achetés (produit, quantité, prix unitaire, remise).</li>
            <li>Choisissez la nature de l'achat :
                <ul>
                    <li>sans choix, l'achat reste une <em>Demande de prix</em> ou un <em>Bon de commande</em> (type de document à choisir) ;</li>
                    <li><em>Facture physique fournisseur</em> : saisissez le numéro de la facture papier ;</li>
                    <li><em>BAPA (DGI)</em> : voir plus bas.</li>
                </ul></li>
            <li>Choisissez le fournisseur et la date d'achat. Pour une facture, indiquez le mode de paiement (Caisse, Banque, Mobile Money, Crédit) et le montant payé.</li>
            <li>Validez avec le bouton du bas : <span class="bouton">Enregistrer la demande de prix</span>, <span class="bouton">Enregistrer le bon de commande</span> ou <span class="bouton">Enregistrer la facture</span>.</li>
        </ol>

        <h3>De la commande à la facture</h3>
        <p><span class="chemin">Achats › Factures achat</span> ouvre les <em>Cycles d'achat</em> : Demande de prix, Bon de commande, Facture.</p>
        <ol class="etapes">
            <li><strong>Demande de prix</strong> : <span class="bouton">Confirmer la commande</span> la transforme en bon de commande.</li>
            <li><strong>Réception</strong> : quand la marchandise arrive, allez dans <span class="chemin">Stock › Articles &amp; stock</span>, carte <em>Réceptions</em>, puis <span class="bouton">Réceptionner</span>. Saisissez les quantités reçues et cliquez sur <span class="bouton">Enregistrer la réception en stock</span>. Les prestations de service n'entrent pas en stock.</li>
            <li><strong>Facture</strong> : sur le bon de commande, <span class="bouton">Facturer</span> établit la facture d'achat.</li>
        </ol>
        <p>Une demande de prix ou une commande dont rien n'est reçu peut être annulée avec un motif.</p>

        <h3>Le BAPA (bordereau d'achat de produits agricoles)</h3>
        <p>Le BAPA s'établit quand vous achetez à un producteur qui n'émet pas de facture. C'est la seule pièce d'achat que vous normalisez vous-même : une facture fournisseur, elle, est certifiée par son émetteur.</p>
        <ol class="etapes">
            <li>Le bouton <em>BAPA (DGI)</em> n'apparaît que si l'option BAPA est cochée sur votre espace FNE.</li>
            <li>Choisissez <em>BAPA (DGI)</em> et saisissez le nom du vendeur (tiers sans NCC). Il est déclaré comme tiers non identifié.</li>
            <li>Cliquez sur <span class="bouton">Enregistrer le BAPA (DGI)</span>. Le bordereau se retrouve dans la section <em>Factures BAPA</em> et se normalise avec <span class="bouton">Normaliser</span>.</li>
        </ol>

        <h3>Les factures reçues relevées du portail FNE</h3>
        <p>Les factures certifiées que vos fournisseurs ont émises à votre nom sont relevées sur le portail. Elles sont dans <span class="chemin">Achats › Factures achat</span>, onglet Facture, section <em>Factures achat DGI</em> (et <em>Avoirs fournisseurs FNE</em> pour les avoirs).</p>
        <ul>
            <li><span class="bouton">Voir</span> ouvre le document de la DGI.</li>
            <li><span class="bouton">Rattacher</span> la lie à l'achat que Selflow propose ; un écart de montant est signalé.</li>
            <li><span class="bouton">Saisir l'achat</span> si l'achat n'existe pas encore dans Selflow, puis revenez la rattacher.</li>
            <li><span class="bouton">Détacher</span> défait un rapprochement ; <span class="bouton">Écarter</span> la retire de la liste sans la supprimer.</li>
        </ul>
        <a class="haut" href="#haut">Retour en haut</a>
    </section>

    {{-- 9. Stock --}}
    <section id="stock">
        <h2><span class="num">9</span> Le stock</h2>

        <h3>L'écran Stock</h3>
        <p><span class="chemin">Stock › Articles &amp; stock</span> affiche l'inventaire du stock par article, avec un filtre par site et par catégorie. Trois cartes y mènent aux tâches en attente : <em>Réceptions</em>, <em>Transferts internes</em> et <em>Livraisons</em>. La <em>Page Rebut</em> sert à sortir la marchandise perdue ou abîmée.</p>

        <h3>Les mouvements</h3>
        <p><span class="chemin">Stock › Mouvements</span> retrace chaque entrée et sortie : date, article, quantité, stock avant et après, opérateur, point de vente et pièce. Des onglets les trient : réceptions, livraisons, transferts, entrées de stock, rebuts et pertes.</p>

        <h3>Entrée de stock</h3>
        <p>Pour la marchandise arrivée sans achat saisi : achat sans facture, retour, don.</p>
        <ol class="etapes">
            <li>Ouvrez <span class="chemin">Stock › Entrée de stock</span> et choisissez le point de vente (ou tous les points de vente).</li>
            <li>Choisissez la raison de l'entrée (Achat sans facture, Retour de marchandise, Don reçu, Autre) et écrivez un commentaire.</li>
            <li>Ajoutez les articles, la quantité et le coût unitaire réel (pour un don, sa valeur estimée).</li>
            <li>Cliquez sur <span class="bouton">Enregistrer l'entrée</span>. Le coût moyen se recalcule.</li>
        </ol>

        <h3>Transferts entre sites</h3>
        <ol class="etapes">
            <li>Depuis <span class="chemin">Stock › Articles &amp; stock</span>, ouvrez la carte <em>Transferts internes</em>.</li>
            <li>Choisissez le produit, l'entrepôt source, l'entrepôt destination, la quantité et un motif.</li>
            <li>Cliquez sur <span class="bouton">Transférer directement</span>, ou sur <span class="bouton">Demander le transfert</span> : la demande attend alors d'être validée ou rejetée. L'historique des transferts est sous le formulaire.</li>
        </ol>

        <h3>Inventaire physique</h3>
        <ol class="etapes">
            <li>Ouvrez <span class="chemin">Stock › Inventaire physique</span> et choisissez le site à compter.</li>
            <li>Saisissez la quantité comptée en face de chaque article. Un champ vide veut dire « pas compté », pas zéro.</li>
            <li>Cliquez sur <span class="bouton">Enregistrer le comptage</span>. Chaque écart produit un mouvement d'inventaire.</li>
        </ol>
        <p>Les boutons <span class="bouton">PDF</span> et <span class="bouton">Excel</span> exportent l'inventaire du site. <span class="bouton">Importer un stock d'ouverture</span> sert à reprendre un stock existant.</p>

        <h3>Proposition entrée/sortie</h3>
        <p><span class="chemin">Stock › Proposition entrée/sortie</span> liste les mouvements que demandent les factures reprises du portail FNE. Rien ne bouge tant que vous n'avez pas décidé :</p>
        <ul>
            <li><span class="bouton">Valider</span> : la sortie est faite au coût moyen ; elle est refusée si le stock manque ;</li>
            <li><span class="bouton">Refuser</span> : le stock ne bouge pas.</li>
        </ul>
        <a class="haut" href="#haut">Retour en haut</a>
    </section>

    {{-- 10. Trésorerie --}}
    <section id="tresorerie">
        <h2><span class="num">10</span> La trésorerie</h2>
        <p>La section s'appelle <em>Trésorerie</em>, ou <em>Comptabilité</em> quand la comptabilité est activée dans les paramètres.</p>

        <h3>Caisse, banque, mobile money</h3>
        <ol class="etapes">
            <li>Sans comptabilité activée, déclarez vos banques et comptes mobile money dans <span class="chemin">Moyens de paiement</span> : <span class="bouton">Nouveau moyen de paiement</span>, choisissez le type (Banque ou Mobile Money) et l'intitulé.</li>
            <li>Avec la comptabilité activée, ils se gèrent dans <span class="chemin">Codes Journaux</span>, avec leur compte comptable.</li>
            <li>À la vente ou à l'achat, le mode de paiement choisi (Caisse, Banque, Mobile Money) range le règlement dans le bon journal.</li>
        </ol>

        <h3>Le journal, les encaissements et les décaissements</h3>
        <ul>
            <li><span class="chemin">Solde &amp; journal</span> : toutes les opérations, avec le total des encaissements, des décaissements et le solde net. Filtres par point de vente, moyen de paiement et banque.</li>
            <li><span class="chemin">Encaissements</span> et <span class="chemin">Décaissements</span> : les entrées et les sorties d'argent, quand ces écrans sont ouverts à votre entreprise. Le caissier voit les siens dans <span class="chemin">Mes encaissements</span>.</li>
        </ul>

        <h3>Créances et règlements</h3>
        <ol class="etapes">
            <li>Ouvrez <span class="chemin">Créances &amp; règlements</span>. Deux onglets : <em>Créances Clients</em> et <em>Dettes Fournisseurs</em>.</li>
            <li>Sur la ligne du tiers, cliquez sur <span class="bouton">Enregistrer Règlement</span>.</li>
            <li>Choisissez la facture concernée (le reste à payer s'affiche), le montant, le mode de paiement et la date. Validez par <span class="bouton">Enregistrer le règlement</span>.</li>
        </ol>
        <p><span class="bouton">Relevé</span> établit le relevé d'un client ou d'un fournisseur. Les <em>Soldes de départ</em> servent à reprendre ce qui était dû avant Selflow.</p>
        <a class="haut" href="#haut">Retour en haut</a>
    </section>

    {{-- 11. Comptabilité --}}
    <section id="comptabilite">
        <h2><span class="num">11</span> La comptabilité</h2>
        <p>Selflow produit les écritures de chaque vente, achat, règlement et mouvement de stock, même quand la comptabilité n'est pas affichée. Pour voir les écrans comptables, cochez <em>Je tiens ma comptabilité</em> dans <span class="chemin">Paramètres &amp; logos</span>, partie <em>Activer la comptabilité</em>.</p>

        <h3>Les écrans</h3>
        <div class="tableau">
            <table>
                <thead><tr><th>Menu</th><th>À quoi il sert</th></tr></thead>
                <tbody>
                    <tr><td>Plan Comptable</td><td>Le plan SYSCOHADA de l'entreprise. <span class="bouton">Nouveau compte</span> ajoute un compte ; <em>Plan comptable par défaut</em> reprend le plan livré.</td></tr>
                    <tr><td>Configuration des comptes</td><td>Les comptes de vente, d'achat et de variation de stock, réglés une fois. Quatre onglets : Configuration générale, Par catégorie, Par type, Par article. Chaque compte a deux cases : le <em>proposé</em> par Selflow et le <em>personnalisé</em>, qui l'emporte s'il est rempli. Les écritures déjà passées ne sont pas réécrites.</td></tr>
                    <tr><td>Codes Journaux</td><td>Les journaux de vente, d'achat, de caisse et de banque, avec leur compte.</td></tr>
                    <tr><td>Opération &amp; écriture globale</td><td>Les opérations de trésorerie et les écritures, et <span class="bouton">Saisir une écriture manuelle</span>.</td></tr>
                    <tr><td>Balance de contrôle</td><td>Débits, crédits et soldes par compte, par mois ou sur l'exercice, par site. Elle signale un écart ou des écritures tombées sur un compte générique.</td></tr>
                    <tr><td>Grand livre, Lettrage</td><td>Le détail des écritures par compte et le rapprochement des factures et des règlements.</td></tr>
                </tbody>
            </table>
        </div>
        <p>Certains de ces écrans (encaissements, décaissements, écriture globale, balance, grand livre, lettrage) sont ouverts entreprise par entreprise par DC-Knowing.</p>

        <h3>Le lien avec Comptaflow</h3>
        <ol class="etapes">
            <li>Dans <span class="chemin">Paramètres &amp; logos</span>, partie <em>Comptabilité, liaison Comptaflow</em>, faites la demande de liaison. Vous n'avez aucune clé à saisir : elle est délivrée quand la demande est validée.</li>
            <li>Une fois la liaison active, chaque écriture part vers votre dossier Comptaflow au fil de l'eau.</li>
            <li><span class="bouton">Déverser tout l'historique</span> envoie aussi les écritures passées ; <span class="bouton">Relancer les échecs</span> renvoie celles qui n'ont pas abouti.</li>
            <li><span class="bouton">Ouvrir ma comptabilité</span> mène à Comptaflow, avec les mêmes identifiants que Selflow.</li>
        </ol>
        <a class="haut" href="#haut">Retour en haut</a>
    </section>

    {{-- 12. FNE DGI --}}
    <section id="fne-dgi">
        <h2><span class="num">12</span> Le menu FNE DGI</h2>
        <div class="tableau">
            <table>
                <thead><tr><th>Menu</th><th>Ce qu'il montre</th></tr></thead>
                <tbody>
                    <tr><td>Gestion FNE</td><td>Les stickers (disponibles, achetés, consommés), le timbre de quittance collecté, les ventes normalisées (factures, reçus, avoirs), les alertes de stock de stickers, et la comparaison de la TVA et du TTC entre Selflow et la DGI.</td></tr>
                    <tr><td>Situation Générale</td><td>L'activité réelle : chiffre d'affaires, achats, trésorerie encaissée, taux de conformité FNE, et le tableau « Déclaré vs Non déclaré ».</td></tr>
                    <tr><td>Factures &amp; Reçus émis/reçus</td><td>Le registre de ce que la DGI a certifié : factures émises, avoirs clients, factures reçues, BAPA, avoirs fournisseurs. Une pièce non normalisée n'y figure pas.</td></tr>
                </tbody>
            </table>
        </div>

        <h3>Le bouton « Actualiser »</h3>
        <ol class="etapes">
            <li>Sur ces trois écrans, choisissez la période puis cliquez sur <span class="bouton">Actualiser</span>.</li>
            <li>Selflow relève le portail de la DGI : ventes, achats et avoirs. Le bouton affiche « Relevé du portail… » pendant le relevé.</li>
            <li>À la fin, un message indique le nombre de relevés nouveaux intégrés, et l'écran se rafraîchit.</li>
        </ol>
        <div class="encadre">
            <p><strong>Cinq minutes entre deux actualisations.</strong> Si une actualisation vient d'être lancée, Selflow affiche « Veuillez patienter » et le temps restant avant un nouvel essai.</p>
            <p><strong>Relevés automatiques</strong> : le portail est relevé chaque jour à 01:00, 13:00 et 20:00, sans action de votre part.</p>
        </div>

        <h3>Les stickers</h3>
        <p>Les stickers de certification se vendent sur votre espace FNE, auprès de la DGI. Selflow ne les vend pas, mais DC-Knowing peut les acheter pour vous :</p>
        <ol class="etapes">
            <li>Ouvrez <span class="chemin">Ventes › Commander des stickers</span> (ou <span class="bouton">Commander des stickers par DC-Knowing</span> depuis Gestion FNE).</li>
            <li>Saisissez la quantité ; le montant estimé s'affiche.</li>
            <li>Déposez le montant sur le numéro indiqué, puis saisissez l'opérateur et la référence de la transaction reçue par SMS, le point de vente concerné et l'urgence.</li>
        </ol>
        <a class="haut" href="#haut">Retour en haut</a>
    </section>

    {{-- 13. Rapports --}}
    <section id="rapports">
        <h2><span class="num">13</span> Les rapports</h2>
        <ul>
            <li><span class="chemin">Tableau de bord › TDB Personnel</span> et <span class="chemin">TDB Général</span> : la vue d'ensemble de votre activité.</li>
            <li><span class="chemin">Rapports › Analyse d'activité</span> : chiffre d'affaires, dépenses d'achats, marge brute, panier moyen, solde de trésorerie, puis des onglets Produits, Points de vente, Employés et Évolution. Un lien mène à l'analyse des prix par produit et vendeur.</li>
            <li><span class="chemin">Rapports › Résultat par site</span> : produits, charges et résultat de chaque point de vente, par mois ou sur l'exercice. Une charge de siège reste au site où elle a été saisie : elle n'est pas répartie.</li>
        </ul>
        <a class="haut" href="#haut">Retour en haut</a>
    </section>

    {{-- 14. Abonnement --}}
    <section id="abonnement">
        <h2><span class="num">14</span> L'abonnement et les offres</h2>
        <p>Les offres sont présentées sur la page <a href="{{ url('/offres') }}">Nos offres</a>. Chacune fixe le nombre de points de vente et d'utilisateurs.</p>
        @php($offresDuGuide = \App\Modules\Admin\Regles\Abonnements::offres())
        <div class="tableau">
            <table>
                <thead><tr><th>Offre</th><th>Points de vente</th><th>Utilisateurs</th><th>Prix</th></tr></thead>
                <tbody>
                    @foreach($offresDuGuide as $offreGuide)
                        <tr>
                            <td>{{ $offreGuide['nom'] }}</td>
                            <td>{{ \App\Modules\Admin\Regles\Abonnements::enChiffres($offreGuide['points_de_vente']) }}</td>
                            <td>{{ \App\Modules\Admin\Regles\Abonnements::enChiffres($offreGuide['utilisateurs']) }}</td>
                            <td>
                                @if($offreGuide['prix'] === null)
                                    Sur devis
                                @elseif($offreGuide['prix'] === 0)
                                    Gratuit {{ \App\Modules\Admin\Regles\Abonnements::DUREE_PASS_CADEAU_JOURS }} jours
                                @else
                                    {{ number_format($offreGuide['prix'], 0, ',', ' ') }} F par mois
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <h3>Payer, renouveler, changer d'offre</h3>
        <ol class="etapes">
            <li>Ouvrez <span class="chemin">Paramètres &amp; logos</span>, partie <em>Facturation</em> : elle montre votre offre, son échéance et votre dernier paiement.</li>
            <li>Cliquez sur <span class="bouton">Renouveler</span> pour votre offre, ou choisissez une offre supérieure.</li>
            <li>Déposez le montant sur le numéro du cabinet DC-Knowing, choisissez l'opérateur, saisissez la référence de la transaction reçue par SMS, puis <span class="bouton">Valider le paiement</span>.</li>
            <li>DC-Knowing confirme le paiement. L'échéance avance alors d'un mois.</li>
        </ol>
        <div class="attention">
            <p>Si aucune période payée n'est en cours (première offre, pass cadeau, échéance passée), l'espace reste bloqué avec la fenêtre « En attente de confirmation de l'abonnement » jusqu'à la confirmation. Il se débloque seul. Un paiement non confirmé affiche son motif et le bouton <span class="bouton">Saisir une nouvelle référence</span>.</p>
            <p>Si vous renouvelez avant l'échéance, vous continuez à travailler pendant la vérification. À l'échéance sans renouvellement, l'accès est suspendu, mais vos données sont conservées.</p>
        </div>
        <a class="haut" href="#haut">Retour en haut</a>
    </section>

    {{-- 15. Assistance --}}
    <section id="assistance">
        <h2><span class="num">15</span> Assistance</h2>
        <ul>
            <li>Si une opération échoue, Selflow affiche le message « Une erreur s'est produite » : « Veuillez réessayer plus tard ou contacter le service assistance au {{ config('selflow.assistance_telephone') }}. »</li>
            <li>Si une page ne peut pas s'afficher, Selflow affiche « Page en maintenance » avec une <strong>référence</strong>. Le service technique est prévenu automatiquement ; copiez la référence avec <span class="bouton">Copier</span> et donnez-la à l'assistance.</li>
            <li>Le menu de votre profil (en haut à droite) propose <em>Revoir la visite guidée</em> de l'application.</li>
        </ul>
        <div class="encadre">
            <p class="titre-encadre"><i class="fas fa-headset" aria-hidden="true"></i> Contacter l'assistance</p>
            <p>Par téléphone au <strong>{{ config('selflow.assistance_telephone') }}</strong>, ou depuis la <a href="{{ route('contact.info') }}">page de contact</a>.</p>
        </div>
        <a class="haut" href="#haut">Retour en haut</a>
    </section>

</div>
</main>
<footer>
    <div class="conteneur">&copy; {{ date('Y') }} DC-Knowing · <a href="{{ route('contact.info') }}">Contact</a> · <a href="{{ url('/politique-utilisation') }}">Politique d'utilisation</a></div>
</footer>
</body>
</html>

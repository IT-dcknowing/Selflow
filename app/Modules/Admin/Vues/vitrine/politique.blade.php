<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Politique d'utilisation · Selflow</title>
@include('admin::vitrine._seo', ['titre' => "Politique d'utilisation · Selflow", 'description' => "Politique d'utilisation de Selflow, logiciel de facturation FNE de DC-Knowing : compte, données, facture normalisée, conservation des pièces et abonnement.", 'chemin' => '/politique-utilisation'])
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/selflow/favicon-32.png') }}">
<link rel="stylesheet" href="{{ asset('vendor/inter/inter.css') }}">
<style>
    body { margin: 0; font-family: 'Inter', system-ui, sans-serif; background: #f6f8fc; color: #0f172a; line-height: 1.7; }
    header { background: #050b1f; color: #fff; padding: 22px 0; }
    .conteneur { width: min(860px, 100% - 32px); margin: 0 auto; }
    header .conteneur { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
    header img { height: 32px; }
    header a { color: #93c5fd; text-decoration: none; font-weight: 700; font-size: 14px; }
    main { padding: 48px 0 80px; }
    h1 { font-size: clamp(28px, 4vw, 40px); margin: 0 0 6px; }
    .maj { color: #64748b; font-size: 14px; margin-bottom: 34px; }
    h2 { font-size: 20px; margin: 36px 0 10px; color: #1e3a8a; }
    p, li { font-size: 15.5px; color: #334155; }
    ul { padding-left: 20px; }
    .encadre { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 14px; padding: 16px 18px; }
    footer { padding: 30px 0; color: #64748b; font-size: 14px; border-top: 1px solid #e2e8f0; }
</style>
</head>
<body>
<header>
    <div class="conteneur">
        <a href="{{ url('/') }}"><img src="{{ asset('images/selflow/logo-blanc.png') }}" alt="Selflow"></a>
        <a href="{{ url('/') }}">Retour à l'accueil</a>
    </div>
</header>
<main>
    <div class="conteneur">
        <h1>Politique d'utilisation de Selflow</h1>
        <div class="maj">Dernière mise à jour : {{ \Illuminate\Support\Carbon::create(2026, 11, 23)->translatedFormat('j F Y') }}</div>

        <div class="encadre">
            Selflow est une application de gestion commerciale éditée par DC-Knowing. Elle tient les ventes, les achats, le stock, la trésorerie
            et la comptabilité d'une entreprise, et transmet ses factures à la plateforme de facture normalisée électronique (FNE) de la Direction
            Générale des Impôts de Côte d'Ivoire. En créant un compte, l'entreprise et ses utilisateurs acceptent les règles ci-dessous.
        </div>

        <h2>1. Le compte et les accès</h2>
        <ul>
            <li>Le compte est ouvert au nom d'une entreprise, par la personne habilitée à l'engager. Les informations fournies à l'inscription doivent être exactes.</li>
            <li>Le responsable du compte crée les utilisateurs de son entreprise et leur attribue des habilitations. Chaque utilisateur ne voit que ce que ses habilitations et son point de vente lui ouvrent.</li>
            <li>Les identifiants sont personnels. Toute action faite avec un compte est réputée faite par son titulaire. En cas de doute sur la sécurité d'un compte, le mot de passe doit être changé sans délai et l'assistance prévenue.</li>
            <li>Le même compte ouvre Selflow et Comptaflow lorsque l'entreprise a souscrit aux deux.</li>
        </ul>

        <h2>2. La facture normalisée</h2>
        <ul>
            <li>L'entreprise reste seule responsable de ses déclarations et des pièces qu'elle émet. Selflow transmet à la DGI les informations saisies, sans les modifier.</li>
            <li>Une pièce certifiée par la DGI ne peut plus être modifiée ni supprimée. Une erreur se corrige par un avoir, lui aussi certifié.</li>
            <li>L'entreprise choisit le moment de la certification : dès l'émission de la pièce, ou après sa vérification.</li>
            <li>Le nom de chaque point de vente est transmis tel quel à la DGI. Il doit correspondre à un établissement déclaré sur l'espace FNE de l'entreprise.</li>
            <li>La clé d'accès à la plateforme et, si l'entreprise le demande, ses identifiants de l'espace FNE sont conservés chiffrés. Ils servent uniquement à certifier ses pièces et à relever, sur le portail de la DGI, les factures émises et reçues à son nom.</li>
        </ul>

        <h2>3. Les données de l'entreprise</h2>
        <ul>
            <li>Les données saisies appartiennent à l'entreprise. DC-Knowing ne les vend pas et ne les communique à aucun tiers, hormis la DGI pour la certification et Comptaflow lorsque l'entreprise a activé la liaison.</li>
            <li>Chaque entreprise est cloisonnée : ses clients, ses fournisseurs, ses articles, ses pièces et son stock ne sont visibles que par ses propres utilisateurs.</li>
            <li>Les données personnelles des utilisateurs, clients et fournisseurs sont traitées dans le respect de la loi ivoirienne n° 2013-450 du 19 juin 2013 relative à la protection des données à caractère personnel. Toute personne concernée peut demander l'accès à ses données, leur rectification ou leur suppression auprès de l'assistance.</li>
            <li>Les actions importantes sont journalisées (auteur, date, adresse de connexion) pour la sécurité du compte et le contrôle interne de l'entreprise.</li>
        </ul>

        <h2>4. La conservation des pièces</h2>
        <ul>
            <li>Les pièces comptables et les factures certifiées sont conservées pendant toute la durée de l'abonnement.</li>
            <li>L'entreprise reste tenue de respecter la durée légale de conservation de ses documents comptables. Elle peut à tout moment exporter ses inventaires et ses pièces.</li>
            <li>Une pièce supprimée passe d'abord par la corbeille, d'où elle peut être restaurée avant sa suppression définitive. Une pièce certifiée n'est jamais supprimée.</li>
        </ul>

        <h2>5. L'abonnement</h2>
        <ul>
            <li>L'accès dépend d'un abonnement en cours de validité. Un paiement est pris en compte dès sa confirmation.</li>
            <li>À l'échéance sans renouvellement, l'accès est suspendu. Les données sont conservées, et l'accès est rétabli au renouvellement.</li>
            <li>Les offres et leurs tarifs sont présentés sur la page des offres.</li>
        </ul>

        <h2>6. Le bon usage</h2>
        <ul>
            <li>Il est interdit d'utiliser Selflow pour émettre des pièces fictives, de tenter d'accéder aux données d'une autre entreprise, ou de perturber le fonctionnement du service.</li>
            <li>Les tentatives d'accès répétées et suspectes sont bloquées automatiquement.</li>
            <li>DC-Knowing peut suspendre un compte dont l'usage contrevient à ces règles, après en avoir informé son responsable sauf urgence.</li>
        </ul>

        <h2>7. La disponibilité et l'assistance</h2>
        <ul>
            <li>DC-Knowing met tout en œuvre pour que Selflow reste disponible et ses données sauvegardées. Une maintenance peut interrompre brièvement le service ; elle est annoncée lorsque c'est possible.</li>
            <li>La certification dépend de la disponibilité de la plateforme de la DGI. Une pièce que la plateforme n'a pas pu certifier reste à normaliser et peut être renvoyée.</li>
            <li>L'assistance est joignable au {{ config('selflow.assistance_telephone') }} et depuis la page de contact.</li>
        </ul>

        <h2>8. Les évolutions de cette politique</h2>
        <p>Cette politique peut évoluer avec l'application et la réglementation. La date de mise à jour figure en haut de la page. Une modification importante est annoncée aux responsables de compte.</p>
    </div>
</main>
<footer>
    <div class="conteneur">&copy; {{ date('Y') }} DC-Knowing · <a href="{{ route('contact.info') }}" style="color:inherit;">Contact</a></div>
</footer>
</body>
</html>

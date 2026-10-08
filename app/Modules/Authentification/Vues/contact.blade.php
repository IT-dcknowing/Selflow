<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact & Informations — DC-KNOWING</title>
    @include('partials.icones-selflow')
    <link rel="stylesheet" href="{{ asset('vendor/inter/inter.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
    <style>
        :root {
            --navy: #002B5C;
            --primary: #002B5C;
            --primary-d: #001B3A;
            --amber: #FFC107;
            --text-1: #1F2937;
            --text-2: #4B5563;
            --text-3: #9CA3AF;
            --bg-1: #F8FAFC;
            --bg-2: #FFFFFF;
            --border: #E2E8F0;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-1);
            color: var(--text-1);
            line-height: 1.6;
        }

        .header {
            background: var(--navy);
            color: #ffffff;
            padding: 30px 20px;
            text-align: center;
            border-bottom: 4px solid var(--amber);
        }

        .header h1 {
            font-size: 28px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .header p {
            color: rgba(255, 255, 255, 0.8);
            font-size: 14px;
            margin-top: 8px;
        }

        .container {
            max-width: 1000px;
            margin: 40px auto;
            padding: 0 20px;
            display: grid;
            grid-template-columns: 3fr 2fr;
            gap: 30px;
        }

        .card {
            background: var(--bg-2);
            border-radius: 12px;
            border: 1px solid var(--border);
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
            margin-bottom: 30px;
        }

        .card-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
            border-bottom: 1.5px solid var(--border);
            padding-bottom: 10px;
        }

        /* ── Contacts ── */
        .contact-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 20px;
        }

        .contact-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: rgba(0, 43, 92, 0.08);
            color: var(--navy);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .contact-details h4 {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--text-1);
        }

        .contact-details p {
            font-size: 13px;
            color: var(--text-2);
            margin-top: 2px;
        }

        /* ── Boutons premium ── */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s;
            cursor: pointer;
            border: none;
        }

        .btn-whatsapp {
            background: #25D366;
            color: #ffffff;
        }

        .btn-whatsapp:hover {
            background: #20BA5A;
        }

        .btn-mail {
            background: var(--navy);
            color: #ffffff;
            margin-top: 10px;
        }

        .btn-mail:hover {
            background: var(--primary-d);
        }

        .btn-outline {
            background: transparent;
            color: var(--navy);
            border: 1px solid var(--navy);
            margin-top: 15px;
        }

        .btn-outline:hover {
            background: rgba(0, 43, 92, 0.05);
        }

        /* ── Applications DC-KNOWING ── */
        .app-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .app-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #F8FAFC;
        }

        .app-logo {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: var(--navy);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .app-info h4 {
            font-size: 13.5px;
            font-weight: 700;
        }

        .app-info p {
            font-size: 11px;
            color: var(--text-2);
        }

        /* ── Documentation ── */
        .doc-section {
            font-size: 13px;
            color: var(--text-2);
            line-height: 1.7;
        }

        .doc-section h3 {
            font-size: 15px;
            font-weight: 700;
            color: var(--navy);
            margin: 20px 0 10px;
        }

        @media (max-width: 768px) {
            .container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

    <div class="header">
        <h1>DC-KNOWING Technologies</h1>
        <p>Créateur de solutions de gestion d'entreprise intelligentes et sécurisées</p>
    </div>

    <div class="container">

        {{-- Partie gauche : Présentation & Documentation --}}
        <div>
            <div class="card">
                <h2 class="card-title"><i class="fa-solid fa-circle-info"></i> À propos de Selflow</h2>
                <p style="font-size:14px; color:var(--text-2); margin-bottom:15px;">
                    Selflow est un progiciel de gestion intégré (ERP) moderne développé par <strong>DC-KNOWING</strong>,
                    spécialement conçu pour les entreprises de la zone OHADA. Il propose une facturation normalisée
                    directement interfacée avec l'API FNE de la DGI en Côte d'Ivoire, une gestion complète des stocks
                    multi-sites et un module de production industrielle.
                </p>
                <div class="app-list" style="margin-top:25px;">
                    <h3 style="font-size:14px; font-weight:700; color:var(--navy); margin-bottom:10px;">Notre suite
                        logicielle</h3>

                    <div class="app-item">
                        <div class="app-logo"><i class="fa-solid fa-calculator"></i></div>
                        <div class="app-info">
                            <h4>COMPTAFLOW (COMPTABOW)</h4>
                            <p>Application comptable certifiée SYSCOHADA révisé avec déversement en ligne.</p>
                        </div>
                    </div>

                    <div class="app-item">
                        <div class="app-logo"><i class="fa-solid fa-users"></i></div>
                        <div class="app-info">
                            <h4>RH-FLOW</h4>
                            <p>Gestion des ressources humaines, paie et administration du personnel.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card" id="conditions">
                <h2 class="card-title"><i class="fa-solid fa-file-lines"></i> Conditions Générales d'Utilisation</h2>
                <div class="doc-section">
                    <p>
                        L'utilisation des services applicatifs DC-KNOWING implique l'acceptation pleine et entière des
                        conditions d'utilisation décrites ci-après. Les licences d'utilisation sont accordées sous forme
                        d'abonnements mensuels ou annuels.
                    </p>
                    <h3>1. Utilisation du service</h3>
                    <p>
                        L'utilisateur s'engage à utiliser l'application conformément aux réglementations fiscales en
                        vigueur dans son pays d'exploitation (notamment la normalisation de facturation de la DGI).
                    </p>
                    <h3>2. Responsabilité de la normalisation fiscale</h3>
                    <p>
                        Selflow est un transmetteur certifié FNE. Les données transmises restent sous la responsabilité
                        légale exclusive de l'entreprise émettrice.
                    </p>
                </div>
            </div>

            <div class="card" id="politique">
                <h2 class="card-title"><i class="fa-solid fa-shield-halved"></i> Politique de confidentialité</h2>
                <div class="doc-section">
                    <p>
                        DC-KNOWING accorde une importance primordiale à la sécurité et à la confidentialité de vos
                        données financières et commerciales.
                    </p>
                    <h3>1. Hébergement et Sécurité</h3>
                    <p>
                        Vos données d'exploitation sont hébergées sur des serveurs sécurisés bénéficiant de sauvegardes
                        quotidiennes redondantes et d'un chiffrement des flux en SSL/TLS (HTTPS).
                    </p>
                    <h3>2. Accès aux Données</h3>
                    <p>
                        Aucun membre du personnel DC-KNOWING n'accède à vos informations comptables ou fichiers clients
                        sans votre demande d'assistance explicite et temporaire.
                    </p>
                </div>
            </div>
        </div>

        {{-- Partie droite : Formulaire de Contact --}}
        <div>
            <div class="card" style="position: sticky; top: 20px;">
                <h2 class="card-title"><i class="fa-solid fa-headset"></i> Service Client & Ventes</h2>
                <p style="font-size:13px; color:var(--text-2); margin-bottom:20px;">
                    Pour toute demande de souscription, création de compte d'entreprise, assistance ou intégration avec
                    notre suite comptable, contactez notre équipe :
                </p>

                <div class="contact-item">
                    <div class="contact-icon"><i class="fa-solid fa-envelope"></i></div>
                    <div class="contact-details">
                        <h4>Email professionnel</h4>
                        <p><a href="mailto:[it.dcknowing@gmail.com]"
                                style="color:inherit; text-decoration:none;">it.dcknowing@gmail.com</a></p>
                    </div>
                </div>

                <div class="contact-item">
                    <div class="contact-icon"><i class="fa-solid fa-phone"></i></div>
                    <div class="contact-details">
                        <h4>Téléphone Fixe / Ventes</h4>
                        <p>+225 27 22 42 14 43 </p>
                    </div>
                </div>

                <div class="contact-item">
                    <div class="contact-icon"><i class="fa-brands fa-whatsapp"></i></div>
                    <div class="contact-details">
                        <h4>WhatsApp Support</h4>
                        <p>+225 07 67 13 19 93</p>
                    </div>
                </div>

                <div style="margin-top:30px;">
                    <a href="https://wa.me/2250767131993?text=Bonjour%20DC-KNOWING,%20je%20souhaite%20des%20informations%20sur%20Selflow."
                        target="_blank" class="btn btn-whatsapp">
                        <i class="fa-brands fa-whatsapp"></i> Envoyer un message WhatsApp
                    </a>

                    <a href="mailto:[it.dcknowing@gmail.com]?subject=Demande%20d%27informations%20Selflow"
                        class="btn btn-mail">
                        <i class="fa-solid fa-envelope"></i> Nous écrire par Email
                    </a>

                    <a href="{{ route('connexion') }}" class="btn btn-outline">
                        <i class="fa-solid fa-arrow-left"></i> Retourner à la connexion
                    </a>
                </div>
            </div>
        </div>

    </div>

</body>

</html>
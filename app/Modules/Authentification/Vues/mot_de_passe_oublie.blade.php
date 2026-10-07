<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Réinitialiser votre mot de passe — Selflow ERP">
    <title>Mot de passe oublié — Selflow</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    {{-- Font Awesome, épinglé, comme le reste de l'application. Ces pages
         chargeaient Tabler Icons en version flottante (« latest ») : une version qui change sous
         nos pieds, et les boutons de l'inscription montraient un carré vide
         à la place de leur flèche (image 3 du 02/10/2026). --}}
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #F4F6F9;
            min-height: 100vh;
            display: flex;
            align-items: stretch;
            justify-content: stretch;
            margin: 0;
            padding: 0;
        }

        .conteneur {
            display: grid;
            grid-template-columns: 1fr 1fr;
            width: 100%;
            min-height: 100vh;
        }

        /* ── Côté gauche ── */
        .gauche {
            background: #002B5C;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 48px;
            text-align: center;
            color: #ffffff;
        }

        .gauche-interieur {
            max-width: 480px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .marque {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 32px;
        }

        .marque-icone {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .marque-icone i {
            color: #ffffff;
            font-size: 22px;
        }

        .marque-nom {
            font-size: 26px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .slogan-titre {
            font-size: 32px;
            font-weight: 700;
            line-height: 1.35;
            margin-bottom: 16px;
            letter-spacing: -0.5px;
        }

        .slogan-sous {
            font-size: 15px;
            color: rgba(255, 255, 255, 0.7);
            line-height: 1.6;
        }

        /* ── Côté droit ── */
        .droite {
            background: #F8F9FA;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
        }

        .carte-form {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.06);
            padding: 40px;
            width: 100%;
            max-width: 440px;
        }

        .form-titre {
            font-size: 24px;
            font-weight: 700;
            color: #1A1A2E;
            margin-bottom: 8px;
            text-align: center;
        }

        .form-sous {
            font-size: 13.5px;
            color: #8A8FA8;
            margin-bottom: 32px;
            text-align: center;
            line-height: 1.5;
        }

        .alerte-succes {
            background: #D1FAE5;
            border: 1px solid #6EE7B7;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 13px;
            color: #065F46;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alerte-erreur {
            background: #FEE2E2;
            border: 1px solid #FCA5A5;
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 12.5px;
            color: #991B1B;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .champ {
            margin-bottom: 24px;
        }

        .champ label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #4A506B;
            margin-bottom: 8px;
        }

        .champ input {
            width: 100%;
            border: 1px solid #D1DBEC;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 14px;
            background: #EBF2FC;
            color: #1A1A2E;
            outline: none;
            transition: all 0.15s;
            font-family: inherit;
        }

        .champ input:focus {
            border-color: #002B5C;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(0, 43, 92, 0.15);
        }

        .champ-erreur {
            font-size: 12px;
            color: #DC2626;
            margin-top: 6px;
        }

        .btn-soumettre {
            width: 100%;
            padding: 13px;
            border-radius: 10px;
            background: #002B5C;
            color: #ffffff;
            font-size: 14.5px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.15s;
            font-family: inherit;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-soumettre:hover {
            background: #003B73;
        }

        .btn-soumettre:active {
            transform: scale(0.99);
        }

        .retour-lien {
            display: block;
            text-align: center;
            margin-top: 24px;
            font-size: 13.5px;
            color: #002B5C;
            text-decoration: none;
            font-weight: 600;
        }

        .retour-lien:hover {
            text-decoration: underline;
        }

        .demo-box {
            background: #FEF3C7;
            border: 1px solid #FCD34D;
            border-radius: 8px;
            padding: 14px;
            font-size: 12.5px;
            color: #92400E;
            margin-bottom: 20px;
            word-break: break-all;
        }

        @media (max-width: 900px) {
            .conteneur {
                grid-template-columns: 1fr;
            }

            .gauche {
                display: none;
            }
        }
    </style>
</head>

<body>

    <div class="conteneur">

        {{-- ── Côté gauche ── --}}
        <div class="gauche">
            <div class="gauche-interieur">
                <div class="marque">
                    <div class="marque-icone">
                        <i class="fa-solid fa-cloud" aria-hidden="true"></i>
                    </div>
                    <span class="marque-nom">Selflow</span>
                </div>

                <div class="slogan-titre">
                    Sécurisé & Simple
                </div>

                <div class="slogan-sous">
                    Récupérez l'accès à votre espace en quelques secondes grâce à notre procédure sécurisée.
                </div>
            </div>
        </div>

        {{-- ── Côté droit ── --}}
        <div class="droite">
            <div class="carte-form">

                <h1 class="form-titre">Mot de passe oublié</h1>
                <p class="form-sous">Saisissez votre e-mail pour recevoir un lien de réinitialisation</p>

                {{-- Notifications succès/erreur --}}
                @if (session('succes'))
                    <div class="alerte-succes" role="alert">
                        <i class="fa-solid fa-circle-check" style="font-size:16px;"></i>
                        {{ session('succes') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="alerte-erreur" role="alert">
                        <i class="fa-solid fa-circle-exclamation" style="font-size:16px;"></i>
                        {{ $errors->first() }}
                    </div>
                @endif

                {{-- Mode de démo/local : affiche l'URL générée --}}
                @if (session('lien_developpement'))
                    <div class="demo-box">
                        <strong><i class="fa-brands fa-laravel"></i> Mode Développement :</strong><br>
                        Le lien ci-dessous a été généré pour ce test local. Vous pouvez cliquer directement dessus :<br><br>
                        <a href="{{ session('lien_developpement') }}" style="color:#B45309; font-weight:700;">Réinitialiser mon mot de passe →</a>
                    </div>
                @endif

                <form method="POST" action="{{ route('password.email') }}" novalidate>
                    @csrf

                    {{-- Email --}}
                    <div class="champ">
                        <label for="email">Adresse email</label>
                        <input type="email" id="email" name="email" placeholder="vous@entreprise.com"
                            value="{{ old('email') }}" autocomplete="email" required autofocus>
                    </div>

                    <button type="submit" class="btn-soumettre">
                        <i class="fa-solid fa-paper-plane"></i> Envoyer le lien
                    </button>
                </form>

                <a href="{{ route('connexion') }}" class="retour-lien">
                    <i class="fa-solid fa-arrow-left"></i> Retour à la connexion
                </a>

            </div>
        </div>

    </div>

</body>

</html>

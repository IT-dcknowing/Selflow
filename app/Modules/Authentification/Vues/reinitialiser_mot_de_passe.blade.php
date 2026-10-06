<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réinitialiser le mot de passe — Selflow</title>
    <link rel="stylesheet" href="{{ asset('vendor/inter/inter.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
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
            margin-bottom: 20px;
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
                    Nouveau départ
                </div>

                <div class="slogan-sous">
                    Définissez un mot de passe sécurisé contenant au moins 8 caractères pour protéger votre compte.
                </div>
            </div>
        </div>

        {{-- ── Côté droit ── --}}
        <div class="droite">
            <div class="carte-form">

                <h1 class="form-titre">Nouveau mot de passe</h1>
                <p class="form-sous">Veuillez saisir votre nouveau mot de passe</p>

                @if ($errors->any())
                    <div class="alerte-erreur" role="alert">
                        <i class="fa-solid fa-circle-exclamation" style="font-size:16px;"></i>
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('password.update') }}" novalidate>
                    @csrf

                    <input type="hidden" name="token" value="{{ $token }}">
                    <input type="hidden" name="email" value="{{ $email }}">

                    {{-- Mot de passe --}}
                    <div class="champ">
                        <label for="password">Nouveau mot de passe</label>
                        <input type="password" id="password" name="password" placeholder="Minimum 8 caractères"
                            autocomplete="new-password" required autofocus>
                    </div>

                    {{-- Confirmation --}}
                    <div class="champ">
                        <label for="password_confirmation">Confirmer le mot de passe</label>
                        <input type="password" id="password_confirmation" name="password_confirmation"
                            placeholder="Saisissez à nouveau" autocomplete="new-password" required>
                    </div>

                    <button type="submit" class="btn-soumettre">
                        <i class="fa-solid fa-lock"></i> Enregistrer le mot de passe
                    </button>
                </form>

            </div>
        </div>

    </div>

</body>

</html>

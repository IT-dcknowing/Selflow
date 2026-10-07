<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Connexion à Selflow — Plateforme intelligente de gestion des ventes et stocks">
    <title>Connexion — Selflow</title>
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

        .badge-promo {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #FFC107;
            color: #1A1A2E;
            font-size: 12.5px;
            font-weight: 600;
            padding: 6px 16px;
            border-radius: 20px;
            margin-bottom: 32px;
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

        .champ input.erreur {
            border-color: #DC2626;
            background: #FEE2E2;
        }

        .champ-erreur {
            font-size: 12px;
            color: #DC2626;
            margin-top: 6px;
        }

        .options-rangee {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 26px;
        }

        .checkbox-rangee {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .checkbox-rangee input[type="checkbox"] {
            width: 18px;
            height: 18px;
            border-radius: 6px;
            accent-color: #002B5C;
            cursor: pointer;
        }

        .checkbox-label {
            font-size: 13px;
            color: #6B7280;
        }

        .lien-oubli {
            font-size: 13px;
            color: #002B5C;
            text-decoration: none;
            font-weight: 600;
        }

        .lien-oubli:hover {
            text-decoration: underline;
        }

        .btn-connexion {
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
        }

        .btn-connexion:hover {
            background: #003B73;
        }

        .btn-connexion:active {
            transform: scale(0.99);
        }

        .form-pied {
            margin-top: 24px;
            text-align: center;
            font-size: 12px;
            color: #9CA3AF;
            line-height: 1.6;
        }

        .form-pied a {
            color: #6B7280;
            text-decoration: none;
        }

        .form-pied a:hover {
            text-decoration: underline;
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

                <div class="badge-promo">
                    <i class="fa-solid fa-star" aria-hidden="true"></i>
                    La solution pour mieux gérer
                </div>

                <div class="slogan-titre">
                    Pilotez votre entreprise avec intelligence
                </div>

                <div class="slogan-sous">
                    La plateforme intelligente pour vos achats,
                    ventes , facturations et stocks en temps réel.
                </div>
            </div>
        </div>

        {{-- ── Côté droit ── --}}
        <div class="droite">
            <div class="carte-form">

                <h1 class="form-titre">Connexion</h1>
                <p class="form-sous">Connectez-vous pour accéder à votre dashboard</p>

                {{-- Message d'erreur global --}}
                @if ($errors->has('connexion_erreur') || $errors->any())
                    <div class="alerte-erreur" role="alert" style="margin-top:10px;">
                        <i class="fa-solid fa-circle-exclamation" style="font-size:16px;"></i>
                        {{ $errors->first('connexion_erreur') ?: $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('connexion.traitement') }}" id="form-connexion" novalidate style="margin-top:20px;">
                    @csrf

                    {{-- Email --}}
                    <div class="champ">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" placeholder="vous@entreprise.com"
                            value="{{ old('email') }}" autocomplete="email" required autofocus>
                    </div>

                    {{-- Mot de passe ── avec bouton œil --}}
                    <div class="champ">
                        <label for="password">Mot de passe</label>
                        <div style="position: relative; width: 100%;">
                            <input type="password" id="password" name="password" placeholder="••••••••"
                                autocomplete="current-password" required style="padding-right: 44px; width: 100%;">
                            <button type="button" id="toggle-password" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #6B7280; display: flex; align-items: center; justify-content: center; padding: 4px; outline: none;">
                                <svg id="eye-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="pointer-events: none;">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>

                    {{-- Options --}}
                    <div class="options-rangee">
                        <label class="checkbox-rangee">
                            <input type="checkbox" name="se_souvenir" id="se_souvenir" value="1" {{ old('se_souvenir') ? 'checked' : '' }}>
                            <span class="checkbox-label">Se souvenir de moi</span>
                        </label>
                        <a href="{{ route('password.request') }}" class="lien-oubli">Mot de passe oublié ?</a>
                    </div>

                    <button type="submit" class="btn-connexion" id="btn-soumettre">
                        Se connecter
                    </button>
                </form>

                {{-- Séparateur --}}
                <div style="display:flex;align-items:center;gap:12px;margin:22px 0 16px;font-size:12px;color:#9CA3AF;">
                    <div style="flex:1;height:1px;background:#E5E7EB;"></div>
                    ou
                    <div style="flex:1;height:1px;background:#E5E7EB;"></div>
                </div>

                {{-- Bouton Google --}}
                <a href="{{ route('auth.google') }}" id="btn-google-connexion"
                    style="display:flex;align-items:center;justify-content:center;gap:10px;width:100%;padding:11px;
                           border-radius:10px;background:#fff;color:#374151;font-size:14px;font-weight:600;
                           border:1.5px solid #E5E7EB;cursor:pointer;text-decoration:none;
                           transition:all .15s;margin-bottom:18px;"
                    onmouseover="this.style.background='#F9FAFB'" onmouseout="this.style.background='#fff'">
                    <svg width="20" height="20" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                        <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                        <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                        <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                    </svg>
                    Se connecter avec Google
                </a>

                {{-- Lien inscription --}}
                <div style="text-align:center;font-size:13.5px;color:#6B7280;margin-bottom:14px;">
                    Pas encore de compte ?
                    <a href="{{ route('inscription') }}" style="color:#002B5C;font-weight:700;text-decoration:none;margin-left:4px;"
                       id="lien-inscription">S'inscrire gratuitement</a>
                </div>

                <div style="text-align:center; font-size:13px; color:#9CA3AF;">
                    <a href="{{ route('contact.info') }}" style="color:#6B7280; text-decoration:none;">Service client &amp; Contact</a>
                </div>

                <div class="form-pied">
                    En vous connectant, vous acceptez nos
                    <a href="{{ route('contact.info') }}#conditions">Conditions</a> et notre
                    <a href="{{ route('contact.info') }}#politique">Politique de confidentialité</a>
                </div>

            </div>
        </div>

    </div>

    <script>
        document.getElementById('toggle-password').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eye-icon');
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
            } else {
                passwordInput.type = 'password';
                eyeIcon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
            }
        });
    </script>
</body>

</html>
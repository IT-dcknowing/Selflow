<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Connexion à Selflow — Facturez et certifiez en quelques clics. Solution FNE certifiée DGI.">
    <title>Connexion — Selflow</title>
    @include('partials.icones-selflow')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            font-family: 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #ffffff;
            color: #0f172a;
            overflow-x: hidden;
        }

        .login-wrapper {
            min-height: 100vh;
            display: flex;
            width: 100vw;
        }

        /* ═══════════════════════════════════════════
           COLONNE GAUCHE — BLEU ROYAL SELFLOW
        ═══════════════════════════════════════════ */
        .gauche {
            width: 50%;
            min-height: 100vh;
            background: #0647c9;
            background: linear-gradient(135deg, #0238b8 0%, #0647c9 50%, #0a56de 100%);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 70px 70px 60px 80px;
            position: relative;
            overflow: hidden;
            color: #ffffff;
        }

        /* Halo lumineux subtil d'ambiance */
        .gauche::before {
            content: '';
            position: absolute;
            top: -20%;
            left: -10%;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.12) 0%, transparent 65%);
            pointer-events: none;
        }

        /* ── Slogan en haut ── */
        .slogan-block {
            position: relative;
            z-index: 2;
        }

        .slogan-title {
            font-size: 42px;
            font-weight: 800;
            line-height: 1.18;
            color: #ffffff;
            letter-spacing: -0.02em;
        }

        .slogan-title .clics {
            font-style: italic;
            font-weight: 700;
            display: inline-block;
        }

        /* ── Illustration 3D centrale ── */
        .illustration-3d-zone {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 440px;
            height: 280px;
            margin: 20px 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Carte Clavier / Ventes 3D (gauche) */
        .card-pad-3d {
            position: absolute;
            left: 20px;
            bottom: 30px;
            width: 135px;
            height: 135px;
            border-radius: 28px;
            background: linear-gradient(145deg, #1d61e0, #0c43ab);
            box-shadow: -15px 25px 40px rgba(0, 20, 80, 0.4), inset 0 2px 3px rgba(255, 255, 255, 0.4);
            transform: perspective(600px) rotateY(16deg) rotateX(8deg) rotate(-4deg);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            z-index: 2;
        }

        .card-pad-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            width: 100%;
        }

        .pad-key {
            aspect-ratio: 1;
            background: #ffffff;
            border-radius: 7px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.12);
        }

        .pad-key.yellow {
            background: #f59e0b;
        }

        /* Carte Logo Selflow 3D (droite, plus grande et devant) */
        .card-logo-3d {
            position: absolute;
            left: 125px;
            bottom: 15px;
            width: 185px;
            height: 185px;
            border-radius: 40px;
            background: linear-gradient(145deg, #1b5fda, #08389c);
            box-shadow: 18px 30px 50px rgba(0, 15, 60, 0.5), inset 0 2px 4px rgba(255, 255, 255, 0.35);
            transform: perspective(600px) rotateY(-8deg) rotateX(6deg) rotate(2deg);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 3;
        }

        .logo-glyph-3d {
            width: 110px;
            height: 110px;
            filter: drop-shadow(0 6px 12px rgba(0, 20, 70, 0.35));
        }

        /* Flèches en pointillés avec texte */
        .annotation-ventes {
            position: absolute;
            left: -10px;
            bottom: 5px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            z-index: 4;
        }

        .annotation-ventes svg {
            width: 45px;
            height: 45px;
            overflow: visible;
        }

        .annotation-ventes span {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 0.08em;
            color: #ffffff;
            white-space: nowrap;
        }

        .annotation-selflow {
            position: absolute;
            right: 45px;
            top: 25px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            z-index: 4;
        }

        .annotation-selflow span {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 0.12em;
            color: #ffffff;
        }

        .annotation-selflow svg {
            width: 45px;
            height: 45px;
            overflow: visible;
        }

        /* ── Certifié FNE en bas ── */
        .fne-bottom-block {
            position: relative;
            z-index: 2;
        }

        .fne-badge-title {
            font-size: 24px;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: -0.01em;
            margin-bottom: 6px;
        }

        .fne-badge-sub {
            font-size: 13.5px;
            color: rgba(255, 255, 255, 0.82);
            line-height: 1.45;
            max-width: 360px;
        }

        /* ═══════════════════════════════════════════
           COLONNE DROITE — GRILLE TECHNIQUE & LOGIN
        ═══════════════════════════════════════════ */
        .droite {
            width: 50%;
            min-height: 100vh;
            background-color: #fbfcfe;
            background-image: 
                linear-gradient(to right, rgba(200, 215, 235, 0.45) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(200, 215, 235, 0.45) 1px, transparent 1px);
            background-size: 28px 28px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 30px;
            position: relative;
        }

        /* Formulaire directement centré sur le quadrillage */
        .form-container {
            width: 100%;
            max-width: 400px;
            display: flex;
            flex-direction: column;
        }

        /* Logo SELFLOW en tête */
        .brand-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 24px;
        }

        .brand-icon-box {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #0a2558;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 10px rgba(10, 37, 88, 0.2);
        }

        .brand-icon-box svg {
            width: 24px;
            height: 24px;
        }

        .brand-text {
            font-size: 22px;
            font-weight: 800;
            color: #0b1f48;
            letter-spacing: 0.04em;
        }

        /* Titre Heureux de vous revoir ! */
        .welcome-title {
            font-size: 28px;
            font-weight: 800;
            color: #0a1f44;
            text-align: center;
            margin-bottom: 8px;
            letter-spacing: -0.02em;
        }

        .welcome-subtitle {
            font-size: 13.5px;
            color: #64748b;
            text-align: center;
            margin-bottom: 28px;
        }

        /* Erreurs */
        .alerte-erreur {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Formulaire */
        .champ-groupe {
            margin-bottom: 18px;
        }

        .champ-label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 7px;
        }

        .champ-input-wrapper {
            position: relative;
        }

        .champ-input {
            width: 100%;
            height: 44px;
            padding: 10px 14px;
            font-size: 14px;
            font-family: inherit;
            color: #0f172a;
            background: #ffffff;
            border: 1.5px solid #d1d5db;
            border-radius: 8px;
            outline: none;
            transition: all 0.15s ease;
        }

        .champ-input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .champ-input::placeholder {
            color: #9ca3af;
        }

        .btn-toggle-mdp {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            font-size: 15px;
            padding: 4px;
        }

        .btn-toggle-mdp:hover {
            color: #4b5563;
        }

        /* Ligne Se souvenir de moi & Mot de passe oublié */
        .options-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 4px;
            margin-bottom: 22px;
            font-size: 12.5px;
        }

        .remember-label {
            display: flex;
            align-items: center;
            gap: 7px;
            color: #4b5563;
            cursor: pointer;
            user-select: none;
        }

        .remember-checkbox {
            width: 15px;
            height: 15px;
            border-radius: 4px;
            accent-color: #0a2558;
            cursor: pointer;
        }

        .forgot-link {
            color: #1d4ed8;
            font-weight: 600;
            text-decoration: none;
        }

        .forgot-link:hover {
            text-decoration: underline;
        }

        /* Bouton principal Se connecter */
        .btn-submit {
            width: 100%;
            height: 46px;
            background: #0a2356;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            transition: background 0.15s ease, transform 0.1s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-submit:hover {
            background: #0f3073;
        }

        .btn-submit:active {
            transform: scale(0.99);
        }

        /* Séparateur ou */
        .separator-row {
            display: flex;
            align-items: center;
            margin: 20px 0;
            gap: 12px;
        }

        .separator-line {
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }

        .separator-text {
            font-size: 12px;
            color: #94a3b8;
            text-transform: lowercase;
        }

        /* Bouton Google */
        .btn-google {
            width: 100%;
            height: 44px;
            background: #ffffff;
            color: #1e293b;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            font-family: inherit;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            cursor: pointer;
            transition: background 0.15s ease, border-color 0.15s ease;
        }

        .btn-google:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        .google-icon {
            width: 18px;
            height: 18px;
        }

        /* Pas encore de compte ? */
        .signup-row {
            margin-top: 22px;
            text-align: center;
            font-size: 13px;
            color: #4b5563;
        }

        .signup-link {
            color: #1d4ed8;
            font-weight: 700;
            text-decoration: none;
        }

        .signup-link:hover {
            text-decoration: underline;
        }

        /* Conditions légales */
        .legal-footer {
            margin-top: 26px;
            text-align: center;
            font-size: 11px;
            color: #94a3b8;
            line-height: 1.5;
        }

        .legal-footer a {
            color: #64748b;
            text-decoration: none;
        }

        .legal-footer a:hover {
            text-decoration: underline;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .gauche {
                display: none;
            }
            .droite {
                width: 100%;
                padding: 40px 20px;
            }
        }
    </style>
</head>

<body>

<div class="login-wrapper">

    {{-- ── COLONNE GAUCHE ── --}}
    <div class="gauche">
        {{-- Haut : Slogan officiel --}}
        <div class="slogan-block">
            <h1 class="slogan-title">
                Avec Selflow,<br>
                facturez et certifiez<br>
                <span class="clics">en quelques clics</span>
            </h1>
        </div>

        {{-- Milieu : Illustration 3D Selflow & Pad Ventes --}}
        <div class="illustration-3d-zone">
            {{-- Flèche + Annotation SELFLOW en haut à droite --}}
            <div class="annotation-selflow">
                <span>SELFLOW</span>
                <svg viewBox="0 0 50 40" fill="none" stroke="#ffffff" stroke-width="1.6" stroke-dasharray="3 3">
                    <path d="M 40 5 Q 35 30 15 35" stroke-linecap="round"/>
                    <polyline points="20,38 12,35 17,28" stroke-dasharray="none" stroke-width="1.8"/>
                </svg>
            </div>

            {{-- Carte Clavier / Ventes 3D inclinée --}}
            <div class="card-pad-3d">
                <div class="card-pad-grid">
                    <div class="pad-key"></div>
                    <div class="pad-key"></div>
                    <div class="pad-key"></div>
                    <div class="pad-key"></div>
                    <div class="pad-key yellow"></div>
                    <div class="pad-key"></div>
                </div>
            </div>

            {{-- Carte Logo Selflow 3D (au premier plan) --}}
            <div class="card-logo-3d">
                <svg class="logo-glyph-3d" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                    {{-- S Stylisé blanc avec ondes --}}
                    <path d="M 44 26 C 54 26 59 31 56 40 C 53 49 39 52 38 61 C 37 68 43 74 53 73 C 58 72 63 69 66 65" 
                          stroke="#ffffff" stroke-width="12" stroke-linecap="round" stroke-linejoin="round"/>
                    {{-- Ondes radio / wifi --}}
                    <path d="M 66 36 C 71 42 71 52 66 58" 
                          stroke="#ffffff" stroke-width="6" stroke-linecap="round"/>
                    <path d="M 76 28 C 85 38 85 56 76 66" 
                          stroke="#ffffff" stroke-width="6" stroke-linecap="round"/>
                </svg>
            </div>

            {{-- Flèche + Annotation VENTES · STOCK · FNE en bas à gauche --}}
            <div class="annotation-ventes">
                <svg viewBox="0 0 50 40" fill="none" stroke="#ffffff" stroke-width="1.6" stroke-dasharray="3 3">
                    <path d="M 10 35 Q 20 15 38 8" stroke-linecap="round"/>
                    <polyline points="32,6 40,8 36,15" stroke-dasharray="none" stroke-width="1.8"/>
                </svg>
                <span>VENTES · STOCK · FNE</span>
            </div>
        </div>

        {{-- Bas : Certifié FNE --}}
        <div class="fne-bottom-block">
            <h2 class="fne-badge-title">Certifié FNE</h2>
            <p class="fne-badge-sub">Vos factures normalisées par la DGI, sans quitter Selflow.</p>
        </div>
    </div>

    {{-- ── COLONNE DROITE ── --}}
    <div class="droite">
        <div class="form-container">

            {{-- En-tête : Logo SELFLOW --}}
            <div class="brand-header">
                <div class="brand-icon-box">
                    <svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M 42 27 C 52 27 57 32 54 40 C 51 49 38 52 37 61 C 36 68 42 73 52 72" 
                              stroke="#ffffff" stroke-width="12" stroke-linecap="round"/>
                        <path d="M 65 37 C 70 42 70 51 65 56" 
                              stroke="#ffffff" stroke-width="6" stroke-linecap="round"/>
                        <path d="M 75 30 C 83 39 83 55 75 64" 
                              stroke="#ffffff" stroke-width="6" stroke-linecap="round"/>
                    </svg>
                </div>
                <span class="brand-text">SELFLOW</span>
            </div>

            <h2 class="welcome-title">Heureux de vous revoir !</h2>
            <p class="welcome-subtitle">Connectez-vous pour accéder à votre espace de gestion.</p>

            {{-- Message d'erreur --}}
            @if ($errors->has('connexion_erreur') || $errors->any())
                <div class="alerte-erreur" role="alert">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span>{{ $errors->first('connexion_erreur') ?: $errors->first() }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('connexion.traitement') }}" id="form-connexion" novalidate>
                @csrf

                {{-- Adresse e-mail --}}
                <div class="champ-groupe">
                    <label for="email" class="champ-label">Adresse e-mail</label>
                    <div class="champ-input-wrapper">
                        <input type="email" id="email" name="email" class="champ-input"
                               placeholder="vous@entreprise.ci"
                               value="{{ old('email') }}"
                               autocomplete="email" required autofocus>
                    </div>
                </div>

                {{-- Mot de passe --}}
                <div class="champ-groupe">
                    <label for="password" class="champ-label">Mot de passe</label>
                    <div class="champ-input-wrapper">
                        <input type="password" id="password" name="password" class="champ-input"
                               placeholder="••••••••"
                               autocomplete="current-password" required>
                        <button type="button" class="btn-toggle-mdp" id="toggle-password" aria-label="Afficher ou masquer le mot de passe">
                            <i class="fa-regular fa-eye" id="toggle-icon"></i>
                        </button>
                    </div>
                </div>

                {{-- Se souvenir de moi & Mot de passe oublié --}}
                <div class="options-row">
                    <label class="remember-label">
                        <input type="checkbox" name="se_souvenir" class="remember-checkbox" value="1"
                               {{ old('se_souvenir') ? 'checked' : '' }}>
                        <span>Se souvenir de moi</span>
                    </label>
                    <a href="{{ route('password.request') }}" class="forgot-link">Mot de passe oublié ?</a>
                </div>

                {{-- Bouton Se connecter --}}
                <button type="submit" class="btn-submit">
                    Se connecter
                </button>

                {{-- Séparateur --}}
                <div class="separator-row">
                    <div class="separator-line"></div>
                    <span class="separator-text">ou</span>
                    <div class="separator-line"></div>
                </div>

                {{-- Connexion Google --}}
                <a href="{{ route('auth.google') }}" class="btn-google">
                    <svg class="google-icon" viewBox="0 0 24 24">
                        <path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.665-5.17 3.665-9.17z"/>
                        <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.1-6.72-4.93H1.25v3.15C3.26 21.36 7.33 24 12 24z"/>
                        <path fill="#FBBC05" d="M5.28 14.27c-.25-.72-.38-1.49-.38-2.27s.13-1.55.38-2.27V6.58H1.25C.45 8.16 0 9.97 0 12s.45 3.84 1.25 5.42l4.03-3.15z"/>
                        <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.33 0 3.26 2.64 1.25 6.58l4.03 3.15c.95-2.83 3.6-4.98 6.72-4.98z"/>
                    </svg>
                    <span>Se connecter avec Google</span>
                </a>

                {{-- Pas encore de compte ? --}}
                <div class="signup-row">
                    Pas encore de compte ? <a href="{{ route('inscription') }}" class="signup-link">Créez-en un !</a>
                </div>

                {{-- Mentions légales --}}
                <div class="legal-footer">
                    En vous connectant, vous acceptez nos <a href="#">Conditions</a> et notre <a href="#">Politique de confidentialité</a>.
                </div>

            </form>
        </div>
    </div>

</div>

<script>
    // Basculer l'affichage du mot de passe
    const toggleBtn = document.getElementById('toggle-password');
    const pwdInput = document.getElementById('password');
    const toggleIcon = document.getElementById('toggle-icon');

    if (toggleBtn && pwdInput) {
        toggleBtn.addEventListener('click', function () {
            const isPassword = pwdInput.type === 'password';
            pwdInput.type = isPassword ? 'text' : 'password';
            toggleIcon.className = isPassword ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
        });
    }
</script>

</body>
</html>
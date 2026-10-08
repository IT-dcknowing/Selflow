<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Connexion à Selflow — Plateforme intelligente de gestion des ventes et stocks">
    <title>Connexion — Selflow</title>
    @include('partials.icones-selflow')
    {{-- Polices servies par l'application : la politique de sécurité refuse
         les polices venues d'ailleurs (lot 41). --}}
    <link rel="stylesheet" href="{{ asset('vendor/inter/inter.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
    {{-- Page de connexion, proposition 1 choisie par le propriétaire le
         08/10/2026 : à gauche le panneau bleu, le titre, la tuile 3D du logo
         et ses annotations ; à droite le formulaire sur un fond quadrillé. --}}
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            min-height: 100vh;
            background: #ffffff;
            color: #1E293B;
        }

        .page { display: flex; min-height: 100vh; }

        /* ── Panneau de gauche ── */
        .presentation {
            width: 50%; flex-shrink: 0;
            position: relative; overflow: hidden;
            background: linear-gradient(160deg, #0B49B0 0%, #062C73 48%, #011236 100%);
            color: #ffffff;
            padding: 56px 56px 48px;
            display: flex; flex-direction: column;
        }
        .presentation h1 {
            font-size: clamp(32px, 3.4vw, 46px); line-height: 1.08; font-weight: 800;
            letter-spacing: -1px; max-width: 480px;
        }
        .presentation h1 em { font-style: italic; }

        .scene { position: relative; flex-grow: 1; min-height: 380px; }

        .tuile {
            position: absolute; display: flex; align-items: center; justify-content: center;
        }
        .tuile-logo {
            left: 30%; top: 40px; width: 250px; height: 250px; border-radius: 58px;
            background: linear-gradient(145deg, #4C8BEA 0%, #2160C4 40%, #0E3A8A 100%);
            box-shadow: inset 0 4px 0 rgba(255,255,255,0.45), inset 0 -16px 28px rgba(0,0,0,0.35),
                        0 22px 0 #0A2A66, 0 46px 70px rgba(0,0,0,0.5);
            transform: perspective(900px) rotateX(16deg) rotateY(-24deg) rotateZ(-6deg);
        }
        .tuile-logo img {
            width: 168px; height: 168px; object-fit: contain;
            filter: drop-shadow(0 8px 0 rgba(6,30,80,0.55)) drop-shadow(0 14px 18px rgba(0,0,0,0.35));
        }
        .tuile-modules {
            left: 14%; top: 150px; width: 150px; height: 150px; border-radius: 34px;
            background: linear-gradient(145deg, #3A78D8 0%, #1A4FA6 55%, #0C2F6E 100%);
            box-shadow: inset 0 3px 0 rgba(255,255,255,0.35), inset 0 -10px 18px rgba(0,0,0,0.35),
                        0 14px 0 #0A2457, 0 30px 50px rgba(0,0,0,0.45);
            transform: perspective(700px) rotateX(18deg) rotateY(-22deg) rotateZ(-8deg);
        }
        .touches { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; width: 84px; }
        .touches span { height: 22px; border-radius: 7px; background: #E8F0FF; box-shadow: inset 0 -3px 0 #9DB6E3; }
        .touches span.jaune { background: #FFC107; box-shadow: inset 0 -3px 0 #C99400; }

        .annotations { position: absolute; left: 0; top: 0; width: 100%; height: 100%; pointer-events: none; }
        .etiquette {
            position: absolute; font-size: 12px; font-weight: 700; letter-spacing: 1px;
        }
        .etiquette-haut { right: 6%; top: 0; }
        .etiquette-bas { left: 0; top: 338px; }

        .pied-presentation { display: flex; flex-direction: column; gap: 4px; }
        .pied-presentation strong { font-size: 30px; font-weight: 800; }
        .pied-presentation span { font-size: 15px; color: rgba(255,255,255,0.8); }

        /* ── Côté du formulaire ── */
        .connexion {
            flex-grow: 1; display: flex; align-items: center; justify-content: center;
            padding: 40px 24px;
            background-color: #F7F9FD;
            background-image: linear-gradient(#E6ECF6 1px, transparent 1px),
                              linear-gradient(90deg, #E6ECF6 1px, transparent 1px);
            background-size: 44px 44px;
        }
        .formulaire { width: 100%; max-width: 400px; display: flex; flex-direction: column; gap: 14px; }
        .marque { display: flex; align-items: center; gap: 10px; align-self: center; margin-bottom: 10px; }
        .marque img { width: 40px; height: 40px; object-fit: contain; }
        .marque span { font-size: 18px; font-weight: 800; color: #062C73; letter-spacing: 0.5px; }
        .formulaire h2 { font-size: 34px; line-height: 1.1; font-weight: 800; color: #062C73; letter-spacing: -0.5px; }
        .formulaire .sous-titre { font-size: 14px; color: #44516B; margin-bottom: 6px; }

        .alerte-erreur {
            display: flex; gap: 10px; align-items: flex-start;
            background: #FEF2F2; border: 1px solid #FECACA; color: #B91C1C;
            border-radius: 10px; padding: 11px 13px; font-size: 13px; line-height: 1.5;
        }

        .champ { display: flex; flex-direction: column; gap: 6px; font-size: 13px; font-weight: 600; color: #1E293B; }
        .champ input {
            height: 46px; border: 1px solid #1E293B; border-radius: 10px; padding: 0 14px;
            font: inherit; font-weight: 500; font-size: 14px; background: #ffffff; color: #1E293B; width: 100%;
        }
        .champ input:focus { outline: 2px solid #0B49B0; outline-offset: 1px; border-color: #0B49B0; }
        .mot-de-passe { position: relative; }
        .mot-de-passe input { padding-right: 46px; }
        #toggle-password {
            position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
            background: none; border: 0; cursor: pointer; color: #64748B;
            width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;
        }

        .options { display: flex; justify-content: space-between; align-items: center; gap: 10px; font-size: 13px; color: #44516B; }
        .options label { display: flex; align-items: center; gap: 8px; cursor: pointer; }
        .options a, .lien { color: #0A3D8F; text-decoration: none; font-weight: 700; }
        .options a:hover, .lien:hover { color: #002B5C; text-decoration: underline; }

        .btn-connexion {
            height: 50px; border: 0; border-radius: 10px;
            background: linear-gradient(90deg, #0B49B0 0%, #031A47 100%);
            color: #ffffff; font: inherit; font-size: 16px; font-weight: 700; cursor: pointer;
            transition: transform .15s, box-shadow .15s;
        }
        .btn-connexion:hover { transform: translateY(-1px); box-shadow: 0 10px 22px rgba(11,73,176,0.3); }
        .btn-connexion:disabled { opacity: .7; cursor: wait; transform: none; }

        .btn-google {
            height: 48px; border: 1px solid #CBD5E1; border-radius: 10px; background: #ffffff;
            color: #1E293B; font-size: 14px; font-weight: 600; text-decoration: none;
            display: flex; align-items: center; justify-content: center; gap: 10px;
        }
        .btn-google:hover { background: #F8FAFC; }

        .bas { text-align: center; font-size: 14px; color: #44516B; margin-top: 6px; }
        .mentions { text-align: center; font-size: 12px; color: #64748B; line-height: 1.6; }
        .mentions a { color: #44516B; }

        /* ── Écran étroit : le formulaire seul, la marque au-dessus ── */
        @media (max-width: 900px) {
            .presentation { display: none; }
            .connexion { padding: 32px 16px; }
            .formulaire h2 { font-size: 28px; }
        }
    </style>
</head>

<body>
<div class="page">

    {{-- ── Présentation ── --}}
    <section class="presentation" aria-label="Présentation de Selflow">
        <h1>Avec Selflow, facturez et certifiez en <em>quelques clics</em></h1>

        <div class="scene" aria-hidden="true">
            <div class="tuile tuile-modules">
                <div class="touches">
                    <span></span><span></span><span></span>
                    <span></span><span class="jaune"></span><span></span>
                </div>
            </div>
            <div class="tuile tuile-logo">
                <img src="{{ asset('images/selflow/logo-blanc.png') }}" alt="">
            </div>

            <svg class="annotations" viewBox="0 0 560 420" preserveAspectRatio="none" fill="none">
                <path d="M470 70 C 520 70, 540 30, 500 14" stroke="#ffffff" stroke-width="2" stroke-dasharray="6 7" stroke-linecap="round"/>
                <path d="M494 10 l8 4 -4 8" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M80 250 C 30 250, 18 300, 50 330" stroke="#ffffff" stroke-width="2" stroke-dasharray="6 7" stroke-linecap="round"/>
                <path d="M44 324 l8 8 6 -9" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span class="etiquette etiquette-haut">SELFLOW</span>
            <span class="etiquette etiquette-bas">VENTES · STOCK · FNE</span>
        </div>

        <div class="pied-presentation">
            <strong>Certifié FNE</strong>
            <span>Vos factures normalisées par la DGI, sans quitter Selflow.</span>
        </div>
    </section>

    {{-- ── Connexion ── --}}
    <main class="connexion">
        <div class="formulaire">
            <div class="marque">
                <img src="{{ asset('images/selflow/logo-bleu.png') }}" alt="">
                <span>SELFLOW</span>
            </div>

            <h2>Heureux de vous revoir !</h2>
            <p class="sous-titre">Connectez-vous pour accéder à votre espace de gestion.</p>

            @if ($errors->has('connexion_erreur') || $errors->any())
                <div class="alerte-erreur" role="alert">
                    <i class="fa-solid fa-circle-exclamation" style="margin-top:2px;"></i>
                    {{ $errors->first('connexion_erreur') ?: $errors->first() }}
                </div>
            @endif

            @if (session('status'))
                <div class="alerte-erreur" role="status" style="background:#ECFDF5;border-color:#A7F3D0;color:#065F46;">
                    <i class="fa-solid fa-circle-check" style="margin-top:2px;"></i>
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('connexion.traitement') }}" id="form-connexion" novalidate
                  style="display:flex;flex-direction:column;gap:14px;">
                @csrf

                <label class="champ" for="email">Adresse e-mail
                    <input type="email" id="email" name="email" placeholder="vous@entreprise.ci"
                           value="{{ old('email') }}" autocomplete="email" required autofocus>
                </label>

                <div class="champ">
                    <label for="password">Mot de passe</label>
                    <div class="mot-de-passe">
                        <input type="password" id="password" name="password" placeholder="••••••••"
                               autocomplete="current-password" required>
                        <button type="button" id="toggle-password" aria-label="Afficher le mot de passe">
                            <svg id="eye-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="pointer-events:none;">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="options">
                    <label>
                        <input type="checkbox" name="se_souvenir" id="se_souvenir" value="1" {{ old('se_souvenir') ? 'checked' : '' }}>
                        Se souvenir de moi
                    </label>
                    <a href="{{ route('password.request') }}">Mot de passe oublié ?</a>
                </div>

                <button type="submit" class="btn-connexion" id="btn-soumettre">Se connecter</button>
            </form>

            <a href="{{ route('auth.google') }}" id="btn-google-connexion" class="btn-google">
                <svg width="20" height="20" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                    <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                    <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                    <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                </svg>
                Se connecter avec Google
            </a>

            <p class="bas">Pas encore de compte ?
                <a href="{{ route('inscription') }}" class="lien" id="lien-inscription">Créez-en un !</a>
            </p>

            <p class="mentions">
                <a href="{{ route('contact.info') }}">Service client &amp; contact</a><br>
                En vous connectant, vous acceptez nos
                <a href="{{ route('contact.info') }}#conditions">Conditions</a> et notre
                <a href="{{ route('contact.info') }}#politique">Politique de confidentialité</a>.
            </p>
        </div>
    </main>
</div>

<script>
    document.getElementById('toggle-password').addEventListener('click', function () {
        var champ = document.getElementById('password');
        var oeil = document.getElementById('eye-icon');
        var visible = champ.type === 'password';
        champ.type = visible ? 'text' : 'password';
        this.setAttribute('aria-label', visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
        oeil.innerHTML = visible
            ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>'
            : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
    });
    document.getElementById('form-connexion').addEventListener('submit', function () {
        var bouton = document.getElementById('btn-soumettre');
        bouton.disabled = true;
        bouton.textContent = 'Connexion…';
    });
</script>
</body>

</html>

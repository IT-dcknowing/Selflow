<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Changement de mot de passe — Selflow</title>
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
            background: linear-gradient(160deg, #1A1A2E 0%, #002B5C 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 48px;
            text-align: center;
            color: #ffffff;
        }

        .gauche-interieur {
            max-width: 440px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .marque {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 40px;
        }

        .marque-icone {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .marque-icone i { color: #ffffff; font-size: 24px; }
        .marque-nom { font-size: 26px; font-weight: 700; letter-spacing: -0.5px; }

        .shield-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(255, 193, 7, 0.12);
            border: 2px solid rgba(255, 193, 7, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 28px;
            animation: pulse 2.5s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.2); }
            50% { box-shadow: 0 0 0 16px rgba(255, 193, 7, 0); }
        }

        .shield-icon i { font-size: 36px; color: #FFC107; }

        .gauche-titre {
            font-size: 28px;
            font-weight: 700;
            line-height: 1.35;
            margin-bottom: 14px;
            letter-spacing: -0.3px;
        }

        .gauche-sous {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.65);
            line-height: 1.7;
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
            border-radius: 18px;
            box-shadow: 0 12px 48px rgba(0, 0, 0, 0.07);
            padding: 44px 40px;
            width: 100%;
            max-width: 460px;
        }

        .alerte-obligatoire {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #FEF3C7;
            border: 1px solid #FDE68A;
            border-left: 4px solid #F59E0B;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 13px;
            color: #92400E;
            margin-bottom: 28px;
            font-weight: 500;
        }

        .alerte-obligatoire i { font-size: 18px; color: #F59E0B; flex-shrink: 0; }

        .form-titre {
            font-size: 23px;
            font-weight: 700;
            color: #1A1A2E;
            margin-bottom: 6px;
            text-align: center;
        }

        .form-sous {
            font-size: 13px;
            color: #8A8FA8;
            margin-bottom: 28px;
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

        .input-wrapper {
            position: relative;
        }

        .champ input {
            width: 100%;
            border: 1.5px solid #D1DBEC;
            border-radius: 10px;
            padding: 12px 42px 12px 16px;
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
            box-shadow: 0 0 0 3px rgba(0, 43, 92, 0.12);
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #8A8FA8;
            font-size: 16px;
            user-select: none;
            transition: color 0.15s;
        }

        .toggle-password:hover { color: #002B5C; }

        /* Jauge de force du mot de passe */
        .force-mdp {
            margin-top: 8px;
            display: none;
        }

        .force-barre {
            height: 4px;
            border-radius: 2px;
            background: #E5E7EB;
            overflow: hidden;
            margin-bottom: 4px;
        }

        .force-remplissage {
            height: 100%;
            border-radius: 2px;
            width: 0%;
            transition: width 0.3s, background-color 0.3s;
        }

        .force-label {
            font-size: 11.5px;
            font-weight: 500;
            color: #8A8FA8;
        }

        .btn-soumettre {
            width: 100%;
            padding: 14px;
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
            margin-top: 4px;
        }

        .btn-soumettre:hover { background: #003B73; }
        .btn-soumettre:active { transform: scale(0.99); }

        .separateur-deconnexion {
            display: block;
            text-align: center;
            margin-top: 20px;
            font-size: 12.5px;
            color: #9CA3AF;
        }

        .separateur-deconnexion a {
            color: #6B7280;
            text-decoration: none;
            font-weight: 600;
        }

        .separateur-deconnexion a:hover { text-decoration: underline; }

        @media (max-width: 900px) {
            .conteneur { grid-template-columns: 1fr; }
            .gauche { display: none; }
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

                <div class="shield-icon">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>

                <div class="gauche-titre">
                    Sécurisez votre accès
                </div>

                <div class="gauche-sous">
                    Pour votre sécurité, vous devez définir votre propre mot de passe avant d'accéder à la plateforme.<br><br>
                    Choisissez un mot de passe d'au moins 8 caractères que vous n'avez jamais utilisé auparavant.
                </div>
            </div>
        </div>

        {{-- ── Côté droit ── --}}
        <div class="droite">
            <div class="carte-form">

                <h1 class="form-titre">Définir un nouveau mot de passe</h1>
                <p class="form-sous">Connecté en tant que <strong>{{ auth()->user()->prenom }} {{ auth()->user()->nom }}</strong></p>

                <div class="alerte-obligatoire">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>Cette étape est <strong>obligatoire</strong>. Votre compte a été créé avec un mot de passe provisoire.</span>
                </div>

                @if ($errors->any())
                    <div class="alerte-erreur" role="alert">
                        <i class="fa-solid fa-circle-exclamation" style="font-size:16px;"></i>
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('password.changer.traiter') }}" novalidate>
                    @csrf

                    {{-- Nouveau mot de passe --}}
                    <div class="champ">
                        <label for="password">Nouveau mot de passe</label>
                        <div class="input-wrapper">
                            <input type="password" id="password" name="password"
                                placeholder="Minimum 8 caractères" autocomplete="new-password"
                                required autofocus oninput="evaluerForce(this.value)">
                            <span class="toggle-password" onclick="basculerVisibilite('password', this)">
                                <i class="fa-solid fa-eye"></i>
                            </span>
                        </div>
                        <div class="force-mdp" id="force-mdp">
                            <div class="force-barre">
                                <div class="force-remplissage" id="force-remplissage"></div>
                            </div>
                            <span class="force-label" id="force-label">Force du mot de passe</span>
                        </div>
                    </div>

                    {{-- Confirmation --}}
                    <div class="champ">
                        <label for="password_confirmation">Confirmer le mot de passe</label>
                        <div class="input-wrapper">
                            <input type="password" id="password_confirmation" name="password_confirmation"
                                placeholder="Saisir à nouveau" autocomplete="new-password" required>
                            <span class="toggle-password" onclick="basculerVisibilite('password_confirmation', this)">
                                <i class="fa-solid fa-eye"></i>
                            </span>
                        </div>
                    </div>

                    <button type="submit" class="btn-soumettre" id="btn-soumettre">
                        <i class="fa-solid fa-lock"></i> Enregistrer et accéder à la plateforme
                    </button>
                </form>

                <div class="separateur-deconnexion">
                    Ce n'est pas votre compte ?
                    <form method="POST" action="{{ route('deconnexion') }}" style="display:inline;">
                        @csrf
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;" class="separateur-deconnexion">
                            <a href="#">Se déconnecter</a>
                        </button>
                    </form>
                </div>

            </div>
        </div>

    </div>

    <script>
        function basculerVisibilite(champ, bouton) {
            const input = document.getElementById(champ);
            const icone = bouton.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icone.className = 'fa-solid fa-eye-slash';
            } else {
                input.type = 'password';
                icone.className = 'fa-solid fa-eye';
            }
        }

        function evaluerForce(password) {
            const jaugeWrapper = document.getElementById('force-mdp');
            const remplissage = document.getElementById('force-remplissage');
            const label = document.getElementById('force-label');

            if (!password) {
                jaugeWrapper.style.display = 'none';
                return;
            }

            jaugeWrapper.style.display = 'block';

            let score = 0;
            if (password.length >= 8)  score++;
            if (password.length >= 12) score++;
            if (/[A-Z]/.test(password)) score++;
            if (/[0-9]/.test(password)) score++;
            if (/[^A-Za-z0-9]/.test(password)) score++;

            const niveaux = [
                { pct: '20%', couleur: '#EF4444', texte: 'Très faible' },
                { pct: '40%', couleur: '#F97316', texte: 'Faible' },
                { pct: '60%', couleur: '#EAB308', texte: 'Moyen' },
                { pct: '80%', couleur: '#22C55E', texte: 'Fort' },
                { pct: '100%', couleur: '#16A34A', texte: 'Très fort' },
            ];

            const niveau = niveaux[Math.min(score - 1, 4)] || niveaux[0];
            remplissage.style.width = niveau.pct;
            remplissage.style.backgroundColor = niveau.couleur;
            label.textContent = niveau.texte;
            label.style.color = niveau.couleur;
        }
    </script>

</body>

</html>

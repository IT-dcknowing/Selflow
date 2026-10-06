<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Créez votre compte Selflow — Plateforme intelligente de gestion des ventes et stocks">
    <title>Inscription — Selflow</title>
    <link rel="stylesheet" href="{{ asset('vendor/inter/inter.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #F4F6F9; min-height: 100vh; display: flex; align-items: stretch; }

        .conteneur { display: grid; grid-template-columns: 1fr 1.3fr; width: 100%; min-height: 100vh; }

        /* ── Gauche ── */
        .gauche {
            background: linear-gradient(160deg, #002B5C 0%, #00408A 55%, #0056B8 100%);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 48px; text-align: center; color: #fff; position: relative; overflow: hidden;
        }
        .gauche::before { content:''; position:absolute; width:500px; height:500px; border-radius:50%; background:rgba(255,255,255,.04); top:-150px; left:-150px; }
        .gauche::after  { content:''; position:absolute; width:300px; height:300px; border-radius:50%; background:rgba(255,255,255,.04); bottom:-80px; right:-80px; }
        .gauche-interieur { max-width:460px; position:relative; z-index:1; }
        .marque { display:flex; align-items:center; gap:12px; margin-bottom:28px; justify-content:center; }
        .marque-icone { width:44px; height:44px; border-radius:12px; background:rgba(255,255,255,.12); display:flex; align-items:center; justify-content:center; border:1px solid rgba(255,255,255,.2); }
        .marque-icone i { color:#fff; font-size:22px; }
        .marque-nom { font-size:26px; font-weight:800; letter-spacing:-.5px; }
        .badge-promo { display:inline-flex; align-items:center; gap:6px; background:#FFC107; color:#1A1A2E; font-size:12px; font-weight:700; padding:6px 16px; border-radius:20px; margin-bottom:24px; }
        .slogan-titre { font-size:28px; font-weight:800; line-height:1.25; margin-bottom:12px; }
        .slogan-sous { font-size:14px; color:rgba(255,255,255,.75); line-height:1.65; margin-bottom:28px; }
        .etapes { display:flex; flex-direction:column; gap:12px; text-align:left; }
        .etape { display:flex; align-items:flex-start; gap:12px; }
        .etape-num { width:28px; height:28px; border-radius:50%; flex-shrink:0; background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.3); display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; }
        .etape-texte { font-size:13px; color:rgba(255,255,255,.85); line-height:1.5; }
        .etape-texte strong { color:#fff; }

        /* ── Droite ── */
        .droite { display:flex; align-items:flex-start; justify-content:center; padding:32px 24px; overflow-y:auto; }
        .carte-form { background:#fff; border-radius:20px; box-shadow:0 4px 32px rgba(0,0,0,.08); padding:32px 36px; width:100%; max-width:560px; }
        .form-titre { font-size:22px; font-weight:800; color:#111827; margin-bottom:4px; }
        .form-sous { font-size:13px; color:#6B7280; margin-bottom:20px; }

        /* Section labels */
        .section-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:#9CA3AF; margin:18px 0 10px; display:flex; align-items:center; gap:8px; }
        .section-label::after { content:''; flex:1; height:1px; background:#F3F4F6; }

        /* Champs */
        .champ { margin-bottom:12px; }
        .champ label { display:block; font-size:12.5px; font-weight:600; color:#374151; margin-bottom:5px; }
        .champ label span.req { color:#E53E3E; }
        .champ label small { font-size:10px; color:#9CA3AF; font-weight:400; margin-left:4px; }
        input[type=text], input[type=email], input[type=tel], input[type=password], select, textarea {
            width:100%; padding:10px 13px; border:1.5px solid #E5E7EB; border-radius:9px;
            font-size:13.5px; font-family:inherit; color:#111827; background:#fff;
            transition:border-color .15s, box-shadow .15s; outline:none;
        }
        input:focus, select:focus, textarea:focus { border-color:#002B5C; box-shadow:0 0 0 3px rgba(0,43,92,.09); }
        input.erreur, select.erreur { border-color:#EF4444; }
        select { appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%239CA3AF' stroke-width='2'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; background-size:16px; padding-right:36px; }
        .rangee-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .rangee-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; }

        /* Régime tooltip */
        .regime-option-wrap { position:relative; }
        .regime-info { display:none; position:absolute; top:100%; left:0; right:0; z-index:10; background:#1e293b; color:#e2e8f0; font-size:11px; line-height:1.5; padding:10px 12px; border-radius:8px; margin-top:4px; box-shadow:0 8px 24px rgba(0,0,0,.2); }
        select:focus + .regime-info { display:block; }

        /* Force mot de passe */
        .force-mdp { margin-top:5px; }
        .force-barres { display:flex; gap:4px; margin-bottom:3px; }
        .force-barre { height:3px; flex:1; border-radius:2px; background:#E5E7EB; transition:background .2s; }
        .force-label { font-size:10px; color:#9CA3AF; }

        /* Conditions */
        .conditions-rangee { display:flex; align-items:flex-start; gap:10px; margin:14px 0 18px; cursor:pointer; }
        .conditions-rangee input[type=checkbox] { width:17px; height:17px; accent-color:#002B5C; flex-shrink:0; margin-top:2px; }
        .conditions-rangee span { font-size:12.5px; color:#6B7280; line-height:1.5; }
        .conditions-rangee a { color:#002B5C; font-weight:600; text-decoration:none; }

        /* Le parcours en etapes.
           `.etape` est deja pris par le panneau de gauche : les pas du
           formulaire s'appellent `.pas`, sans quoi ils heriteraient d'un
           `display:flex` qui mettrait les champs cote a cote. */
        .pas[hidden] { display:none; }
        .pas-tete { margin-bottom:16px; }
        .pas-rang { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:#9CA3AF; margin-bottom:6px; }
        .pas-tete h2 { font-size:18px; font-weight:800; color:#111827; margin-bottom:4px; }
        .pas-tete p { font-size:12.5px; color:#6B7280; line-height:1.6; }

        .jauge { height:4px; background:#F3F4F6; border-radius:2px; margin:22px 0 16px; overflow:hidden; }
        .jauge-barre { height:100%; background:#002B5C; border-radius:2px; width:25%; transition:width .25s ease; }

        .navigation { display:flex; flex-direction:column; gap:10px; }
        .navigation .btn-inscription { margin:0; }
        .btn-secondaire { width:100%; padding:12px; border-radius:10px; background:#fff; color:#374151;
            font-size:13.5px; font-weight:600; border:1.5px solid #E5E7EB; cursor:pointer;
            font-family:inherit; display:flex; align-items:center; justify-content:center; gap:8px; transition:all .15s; }
        .btn-secondaire:hover { border-color:#002B5C; color:#002B5C; }
        .btn-secondaire[hidden], .btn-inscription[hidden], .btn-passer[hidden] { display:none; }
        /* Il etait un lien souligne, gris clair, de douze pixels, sous le
           bouton principal : personne ne le voyait. C'est pourtant la sortie
           de l'inscription — les etapes qui suivent sont facultatives. */
        .btn-passer { width:100%; padding:12px; border-radius:10px; cursor:pointer;
            font-family:inherit; font-size:13.5px; font-weight:600;
            color:#002B5C; background:#EFF6FF; border:1.5px solid #BFDBFE;
            display:flex; align-items:center; justify-content:center; gap:8px;
            transition:all .15s; }
        .btn-passer:hover { background:#DBEAFE; border-color:#002B5C; }
        .btn-passer small { display:block; font-weight:400; font-size:11px; color:#6B7280; }

        /* Boutons */
        .btn-inscription { width:100%; padding:13px; border-radius:10px; background:#002B5C; color:#fff; font-size:14px; font-weight:700; border:none; cursor:pointer; transition:all .15s; font-family:inherit; display:flex; align-items:center; justify-content:center; gap:8px; }
        .btn-inscription:hover { background:#003B73; }
        .btn-inscription:active { transform:scale(.99); }

        /* Séparateur */
        .separateur { display:flex; align-items:center; gap:12px; margin:18px 0 14px; font-size:11.5px; color:#9CA3AF; }
        .separateur::before, .separateur::after { content:''; flex:1; height:1px; background:#E5E7EB; }

        /* Google */
        .btn-google { width:100%; padding:10px; border-radius:9px; background:#fff; color:#374151; font-size:13.5px; font-weight:600; border:1.5px solid #E5E7EB; cursor:pointer; transition:all .15s; font-family:inherit; display:flex; align-items:center; justify-content:center; gap:10px; text-decoration:none; }
        .btn-google:hover { background:#F9FAFB; border-color:#D1D5DB; }

        .alerte-erreur { display:flex; align-items:center; gap:10px; background:#FEF2F2; border:1px solid #FECACA; border-radius:10px; padding:12px 16px; margin-bottom:16px; color:#B91C1C; font-size:13px; font-weight:600; }
        .alerte-info { display:flex; align-items:center; gap:10px; background:#EFF6FF; border:1px solid #BFDBFE; border-radius:10px; padding:12px 16px; margin-bottom:16px; color:#1D4ED8; font-size:13px; font-weight:600; }

        .lien-connexion { text-align:center; margin-top:16px; font-size:13px; color:#6B7280; }
        .lien-connexion a { color:#002B5C; font-weight:700; text-decoration:none; margin-left:4px; }
        .form-pied { margin-top:14px; text-align:center; font-size:11px; color:#9CA3AF; line-height:1.6; }
        .form-pied a { color:#6B7280; text-decoration:none; }

        @media (max-width:900px) { .conteneur { grid-template-columns:1fr; } .gauche { display:none; } .carte-form { padding:24px 18px; } }
        @keyframes spin { to { transform:rotate(360deg); } }
    </style>
</head>
<body>
<div class="conteneur">

    {{-- ── Côté gauche ── --}}
    <div class="gauche">
        <div class="gauche-interieur">
            <div class="marque">
                <div class="marque-icone"><i class="fa-solid fa-cloud"></i></div>
                <span class="marque-nom">Selflow</span>
            </div>
            <div class="badge-promo"><i class="fa-solid fa-star"></i> Inscription gratuite</div>
            <div class="slogan-titre">Commencez à piloter votre entreprise dès aujourd'hui</div>
            <div class="slogan-sous">Rejoignez des entreprises ivoiriennes qui gèrent leurs ventes, achats, stocks et factures FNE avec Selflow.</div>
            <div class="etapes">
                <div class="etape"><div class="etape-num">1</div><div class="etape-texte"><strong>Créez votre compte</strong> en moins de 3 minutes</div></div>
                <div class="etape"><div class="etape-num">2</div><div class="etape-texte"><strong>Configurez votre entreprise</strong> — infos légales, PDV, équipe</div></div>
                <div class="etape"><div class="etape-num">3</div><div class="etape-texte"><strong>Facturez & déclarez</strong> — factures normalisées DGI / FNE incluses</div></div>
            </div>
        </div>
    </div>

    {{-- ── Côté droit ── --}}
    <div class="droite">
        <div class="carte-form">
            <h1 class="form-titre">Créer votre compte</h1>
            <p class="form-sous">Remplissez ce formulaire pour démarrer gratuitement</p>

            @if(session('info'))
                <div class="alerte-info"><i class="fa-solid fa-circle-info"></i> {{ session('info') }}</div>
            @endif
            @if ($errors->has('inscription_erreur') || $errors->any())
                <div class="alerte-erreur" role="alert">
                    <i class="fa-solid fa-circle-exclamation" style="font-size:16px;flex-shrink:0;"></i>
                    {{ $errors->first('inscription_erreur') ?: $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('inscription.traitement') }}" id="form-inscription" novalidate>
                @csrf


                {{-- ══ Étape 1 — L'entreprise ══

                     Un seul champ. La forme juridique, l'adresse électronique
                     et le téléphone vivaient ici : on demandait quatre choses
                     pour créer une entreprise dont une seule est indispensable,
                     et l'adresse électronique — qui est l'identifiant de
                     connexion — était réclamée une étape avant qu'on sache qui
                     se connecte. Elle a rejoint le responsable ; la forme
                     juridique se renseigne depuis les paramètres. --}}
                <section class="pas" data-pas="1">
                    <div class="pas-tete">
                        <div class="pas-rang">Étape 1 sur 3</div>
                        <h2>L'entreprise</h2>
                        <p>Son nom. C'est tout ce qu'il faut pour commencer.</p>
                    </div>
                <div class="section-label"><i class="fa-solid fa-building"></i> Votre entreprise</div>

                <div class="champ">
                    <label for="nom_entreprise">Nom de l'entreprise <span class="req">*</span></label>
                    <input type="text" id="nom_entreprise" name="nom_entreprise"
                        placeholder="Ex: SARL Mon Commerce CI"
                        value="{{ old('nom_entreprise') }}" required autofocus
                        class="{{ $errors->has('nom_entreprise') ? 'erreur' : '' }}">
                    <small style="display:block;margin-top:6px;font-size:11.5px;color:#9CA3AF;line-height:1.55;">
                        Le nom sous lequel vous facturez. Vous pourrez le corriger
                        depuis vos paramètres.
                    </small>
                </div>

                </section>


                {{-- ══ Étape 2 — Le responsable ══ --}}
                <section class="pas" data-pas="2" hidden>
                    <div class="pas-tete">
                        <div class="pas-rang">Étape 2 sur 3</div>
                        <h2>Le responsable et son accès</h2>
                        <p>Qui administrera l'espace, avec quelle adresse et quel mot de passe.</p>
                    </div>
                {{-- ──── Section IDENTITÉ ──── --}}
                <div class="section-label" style="margin-top:18px;"><i class="fa-solid fa-user"></i> Votre identité (Gérant / Admin)</div>

                <div class="rangee-2">
                    <div class="champ" style="margin-bottom:0">
                        <label for="nom">Nom <span class="req">*</span></label>
                        <input type="text" id="nom" name="nom"
                            placeholder="Agnimel"
                            value="{{ old('nom') }}" required
                            class="{{ $errors->has('nom') ? 'erreur' : '' }}">
                    </div>
                    <div class="champ" style="margin-bottom:0">
                        <label for="prenom">Prénom <span class="req">*</span></label>
                        <input type="text" id="prenom" name="prenom"
                            placeholder="Meledje Abraham"
                            value="{{ old('prenom') }}" required
                            class="{{ $errors->has('prenom') ? 'erreur' : '' }}">
                    </div>
                </div>

                <div class="champ" style="margin-top:12px;">
                    <label for="fonction_gerant">Fonction / Titre</label>
                    <input type="text" id="fonction_gerant" name="fonction_gerant"
                        placeholder="Ex: Gérant, DG, PDG, Directeur"
                        value="{{ old('fonction_gerant') }}">
                </div>

                {{-- L'adresse et le téléphone étaient demandés à l'étape
                     précédente, sous le nom de l'entreprise. Or l'adresse est
                     l'identifiant de connexion : elle appartient à qui ouvre
                     l'accès. Un second numéro, « Téléphone personnel », vivait
                     ici : il était validé et **jamais enregistré**, ni sur
                     l'entreprise ni sur l'utilisateur. Il est retiré plutôt que
                     branché — un seul numéro suffit à joindre le responsable. --}}
                <div class="rangee-2" style="margin-top:12px;">
                    <div class="champ" style="margin-bottom:0">
                        <label for="email">Email professionnel <span class="req">*</span>
                            <small>c'est votre identifiant de connexion</small></label>
                        <input type="email" id="email" name="email"
                            placeholder="vous@entreprise.com"
                            value="{{ old('email') }}" required autocomplete="email"
                            class="{{ $errors->has('email') ? 'erreur' : '' }}">
                    </div>
                    <div class="champ" style="margin-bottom:0">
                        <label for="telephone">Téléphone</label>
                        <input type="tel" id="telephone" name="telephone"
                            placeholder="+225 07 00 00 00"
                            value="{{ old('telephone') }}">
                    </div>
                </div>

                <div class="section-label" style="margin-top:18px;"><i class="fa-solid fa-lock"></i> Sécurité du compte</div>

                <div class="champ">
                    <label for="password">Mot de passe <span class="req">*</span></label>
                    <div style="position:relative;">
                        <input type="password" id="password" name="password"
                            placeholder="Au moins 8 caractères"
                            autocomplete="new-password" required
                            oninput="evaluerForce(this.value)"
                            style="padding-right:44px;"
                            class="{{ $errors->has('password') ? 'erreur' : '' }}">
                        <button type="button" id="toggle-password"
                            style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#6B7280;padding:4px;">
                            <i class="fa-solid fa-eye" id="icone-oeil" style="font-size:18px;"></i>
                        </button>
                    </div>
                    <div class="force-mdp" id="force-mdp" style="display:none;">
                        <div class="force-barres">
                            <div class="force-barre" id="b1"></div>
                            <div class="force-barre" id="b2"></div>
                            <div class="force-barre" id="b3"></div>
                            <div class="force-barre" id="b4"></div>
                        </div>
                        <span class="force-label" id="force-texte">Faible</span>
                    </div>
                </div>
                <div class="champ">
                    <label for="password_confirmation">Confirmer le mot de passe <span class="req">*</span></label>
                    <input type="password" id="password_confirmation" name="password_confirmation"
                        placeholder="Répétez le mot de passe"
                        autocomplete="new-password" required>
                </div>

                {{-- Conditions --}}
                <label class="conditions-rangee">
                    <input type="checkbox" name="conditions" id="conditions" value="1" {{ old('conditions') ? 'checked' : '' }}>
                    <span>J'accepte les <a href="{{ route('contact.info') }}#conditions" target="_blank">Conditions d'utilisation</a> et la <a href="{{ route('contact.info') }}#politique" target="_blank">Politique de confidentialité</a> de Selflow.</span>
                </label>

                </section>


                {{-- ══ Étape 3 — La facture normalisée ══ --}}
                <section class="pas" data-pas="3" hidden>
                    <div class="pas-tete">
                        <div class="pas-rang">Étape 3 sur 3 · facultative</div>
                        <h2>La facture normalisée</h2>
                        <p>Avez-vous déjà un compte auprès de la DGI ? Vous pourrez y revenir plus tard.</p>
                    </div>
                    @include('admin::partiels.compte-fne', ['prefixeId' => 'insc'])
                </section>


                {{-- L'étape 4 posait le domaine d'activité par une grille de
                     cases à cocher. C'était l'ancien mécanisme, retiré des
                     paramètres au lot 13 : le domaine se choisit désormais à la
                     première étape du parcours de configuration, avec les
                     métiers qui en découlent, leurs rayons et leurs articles.
                     Le garder ici faisait poser la même question par deux
                     écrans qui ne se parlaient pas — on pouvait déclarer
                     « Santé » à l'inscription et souscrire « Boulangerie »
                     ensuite, sans que rien ne le signale.

                     Le parcours s'ouvre de lui-même à la première visite. --}}

                {{-- La barre de progression et les deux boutons.

                     Le formulaire faisait vingt champs d'un seul tenant : on
                     le remplit mal, ou pas. Il se parcourt maintenant étape
                     par étape, et **les deux dernières sont facultatives** —
                     l'entreprise a besoin d'un nom et d'un responsable qui
                     puisse se connecter, le reste se complète aussi bien une
                     fois dans l'application. --}}
                <div class="jauge"><div class="jauge-barre" id="jauge-barre"></div></div>

                <div class="navigation">
                    <button type="button" class="btn-secondaire" id="btn-precedent" hidden>
                        <i class="fa-solid fa-arrow-left"></i> Retour
                    </button>

                    <button type="button" class="btn-inscription" id="btn-suivant">
                        Suivant <i class="fa-solid fa-arrow-right"></i>
                    </button>

                    <button type="submit" class="btn-inscription" id="btn-soumettre" hidden>
                        <i class="fa-solid fa-check"></i> Créer mon compte
                    </button>

                    <button type="submit" class="btn-passer" id="btn-passer" hidden
                            title="Les étapes restantes se complètent depuis vos paramètres">
                        <i class="fa-solid fa-forward-step"></i>
                        Terminer sans remplir la suite
                    </button>
                    <p id="note-passer" hidden
                       style="text-align:center;font-size:11.5px;color:#9CA3AF;line-height:1.55;margin-top:-2px;">
                        Votre espace est créé tout de suite. La facture normalisée
                        se règle ensuite depuis vos paramètres.
                    </p>
                </div>

            </form>

            {{-- S'inscrire par Google remplace le formulaire ; passé le
                 responsable, il n'y a plus rien à remplacer — proposer la
                 bascule ferait perdre ce qui vient d'être saisi. Le bloc ne
                 vaut donc que pour les deux premières étapes. --}}
            <div id="bloc-google">
            <div class="separateur">ou</div>

            <a href="{{ route('auth.google') }}" class="btn-google" id="btn-google-inscription">
                <svg width="18" height="18" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                    <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                    <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                    <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                </svg>
                S'inscrire avec Google
            </a>
            </div>

            <div class="lien-connexion">
                Déjà un compte ?
                <a href="{{ route('connexion') }}">Se connecter</a>
            </div>
            <div class="form-pied">© {{ date('Y') }} Selflow — DC KNOWING · Tous droits réservés</div>
        </div>
    </div>

</div>

<script>
// ══════════════════════════════════════════════════════════════════
// Le parcours en étapes
// ══════════════════════════════════════════════════════════════════
//
// Le formulaire faisait vingt champs d'un seul tenant. On le parcourt
// maintenant pas à pas, et **les deux dernières étapes sont facultatives** :
// l'entreprise a besoin d'un nom et d'un responsable qui puisse se connecter,
// le reste se complète aussi bien depuis les paramètres.
//
// La navigation est côté navigateur, et le formulaire part en une seule fois :
// les champs des étapes non visitées voyagent vides, ce qui est exactement ce
// qu'on veut dire — « pas encore renseigné ».
(function () {
    var form      = document.getElementById('form-inscription');
    var pas       = Array.prototype.slice.call(form.querySelectorAll('[data-pas]'));
    var barre     = document.getElementById('jauge-barre');
    var precedent = document.getElementById('btn-precedent');
    var suivant   = document.getElementById('btn-suivant');
    var soumettre = document.getElementById('btn-soumettre');
    var passer    = document.getElementById('btn-passer');
    var notePasser = document.getElementById('note-passer');
    var google    = document.getElementById('bloc-google');

    // Au-delà, s'inscrire par Google effacerait ce qui vient d'être saisi.
    var DERNIERE_AVEC_GOOGLE = 2;

    // Les deux premières étapes ne se sautent pas.
    var DERNIERE_OBLIGATOIRE = 2;

    var courant = 1;

    function afficher(n) {
        courant = n;

        pas.forEach(function (section) {
            section.hidden = Number(section.dataset.pas) !== n;
        });

        precedent.hidden = n === 1;
        suivant.hidden   = n === pas.length;
        soumettre.hidden = n !== pas.length;

        // On ne propose de terminer sans la suite qu'une fois le nécessaire
        // fourni : plus tôt, le bouton créerait un compte inutilisable.
        passer.hidden = n < DERNIERE_OBLIGATOIRE || n === pas.length;
        if (notePasser) notePasser.hidden = passer.hidden;

        if (google) google.hidden = n > DERNIERE_AVEC_GOOGLE;

        barre.style.width = Math.round((n / pas.length) * 100) + '%';

        var premier = pas[n - 1].querySelector('input:not([type=hidden]):not([type=radio]), select');
        if (premier) premier.focus({ preventScroll: true });

        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    /**
     * Les champs obligatoires de l'étape courante, et rien d'autre.
     *
     * `checkValidity()` sur le formulaire entier signalerait des champs
     * invisibles, que le navigateur refuse de pointer : l'utilisateur verrait
     * le formulaire refuser de continuer sans savoir pourquoi.
     */
    function etapeValide() {
        var champs = pas[courant - 1].querySelectorAll('[required]');

        for (var i = 0; i < champs.length; i++) {
            if (!champs[i].checkValidity()) {
                champs[i].reportValidity();
                return false;
            }
        }

        return true;
    }

    suivant.addEventListener('click', function () {
        if (etapeValide()) afficher(courant + 1);
    });

    precedent.addEventListener('click', function () {
        afficher(courant - 1);
    });

    // Entrée valide l'étape plutôt que le formulaire : sans cela, une saisie
    // au clavier créerait le compte depuis l'étape 1, en sautant le
    // responsable — donc sans personne pour se connecter.
    form.addEventListener('keydown', function (evenement) {
        if (evenement.key === 'Enter' && evenement.target.tagName !== 'TEXTAREA'
            && courant < pas.length) {
            evenement.preventDefault();
            suivant.click();
        }
    });

    // Une erreur renvoyée par le serveur ramène sur la première étape qui la
    // porte : sinon le message s'afficherait dans une section masquée.
    var premiereErreur = form.querySelector('.erreur, [class*="erreur"]');
    if (premiereErreur) {
        var section = premiereErreur.closest('[data-pas]');
        afficher(section ? Number(section.dataset.pas) : 1);
    } else {
        afficher(1);
    }
})();

// ── Régimes ivoiriens — définitions ──
// Les définitions des régimes vivaient ici, pour quatre régimes sur six, et
// l'écran du superadministrateur n'en affichait aucune. Elles sont désormais
// sur le modèle, lues par `admin::partiels.compte-fne` — là où le régime est
// demandé.

// ── Afficher/masquer mot de passe ──
document.getElementById('toggle-password').addEventListener('click', function() {
    const inp  = document.getElementById('password');
    const icon = document.getElementById('icone-oeil');
    if (inp.type === 'password') { inp.type = 'text'; icon.className = 'fa-solid fa-eye-slash'; }
    else { inp.type = 'password'; icon.className = 'fa-solid fa-eye'; }
});

// ── Force du mot de passe ──
function evaluerForce(val) {
    const bloc = document.getElementById('force-mdp');
    if (!val) { bloc.style.display = 'none'; return; }
    bloc.style.display = 'block';
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const couleurs = ['#EF4444','#F59E0B','#10B981','#059669'];
    const labels   = ['Faible','Moyen','Fort','Très fort'];
    ['b1','b2','b3','b4'].forEach((id, i) => {
        document.getElementById(id).style.background = i < score ? couleurs[score-1] : '#E5E7EB';
    });
    document.getElementById('force-texte').textContent = labels[score-1] || 'Faible';
    document.getElementById('force-texte').style.color = couleurs[score-1] || '#EF4444';
}

// ── Désactiver bouton soumission ──
document.getElementById('form-inscription').addEventListener('submit', function() {
    const btn = document.getElementById('btn-soumettre');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner" style="animation:spin 1s linear infinite;"></i> Création en cours…';
});
</script>
</body>
</html>

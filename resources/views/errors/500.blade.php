{{--
    La page de panne.

    Laravel affichait sa propre page de trace : sur un écran de caisse, un
    utilisateur voyait le nom des fichiers du serveur, les versions des
    bibliothèques et le contenu des variables. C'est illisible pour lui, et
    trop lisible pour qui passait par là.

    Ici : le message d'attente, la référence à donner au service informatique,
    et le détail technique replié — qu'on ouvre d'un clic quand on sait quoi en
    faire.

    Le fond reprend la palette de l'application — le bleu royal `#002B5C`, le
    gris-bleu très clair `#F4F6F9`, la police Inter — et le motif est dessiné en
    SVG dans la page : aucun fichier à déployer, aucune requête à faire, et rien
    à recharger le jour où le serveur va mal.
--}}
@php
    $detail = $detail ?? null;
    $reference = $reference ?? null;

    // **La suite des appels est visible de tous** — décision du propriétaire du
    // projet. Elle porte l'arborescence du serveur ; les chemins sont ramenés à
    // la racine du projet pour n'en dire que le nécessaire.
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page en maintenance — Selflow</title>
    <link rel="stylesheet" href="{{ asset('vendor/inter/inter.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primary:   #002B5C;
            --primary-d: #001F42;
            --bg:        #F4F6F9;
            --surface:   #ffffff;
            --border:    #E2E8F0;
            --text:      #1E293B;
            --text-2:    #475569;
            --text-3:    #94a3b8;
            --warning:   #f59e0b;
            --radius:    12px;
            --shadow:    0 10px 30px rgba(0, 0, 0, 0.05);
        }

        html, body {
            min-height: 100%;
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            font-size: 14px;
        }

        /* Le fond : le bleu de l'application, un halo, et une trame de points
           très discrète. Tout est dessiné ici — rien à déployer. */
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px 20px;
            position: relative;
            overflow-x: hidden;
            background:
                radial-gradient(circle at 12% 18%, rgba(0, 43, 92, 0.10) 0%, transparent 45%),
                radial-gradient(circle at 88% 82%, rgba(0, 43, 92, 0.07) 0%, transparent 45%),
                url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='36' height='36'%3E%3Ccircle cx='2' cy='2' r='1.1' fill='%23002B5C' fill-opacity='0.07'/%3E%3C/svg%3E"),
                var(--bg);
        }

        /* La bande bleue du haut, comme la barre latérale de l'application. */
        body::before {
            content: '';
            position: fixed;
            inset: 0 0 auto 0;
            height: 6px;
            background: linear-gradient(90deg, var(--primary) 0%, var(--primary-d) 55%, var(--warning) 100%);
            z-index: 2;
        }

        .panne {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 760px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .panne-entete {
            padding: 30px 34px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            gap: 18px;
            align-items: flex-start;
        }

        .marque {
            width: 46px; height: 46px; flex: none;
            border-radius: 11px;
            background: var(--primary);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px;
        }

        .panne-entete h1 {
            font-size: 20px; font-weight: 800; letter-spacing: -0.01em;
            color: var(--primary); margin-bottom: 6px;
        }

        .panne-entete .sous { font-size: 12.5px; color: var(--text-3); }

        .panne-corps { padding: 26px 34px 30px; }

        .message {
            font-size: 15.5px; line-height: 1.65; color: var(--text-2);
            padding: 18px 20px;
            background: #EBF2FC;
            border-left: 4px solid var(--primary);
            border-radius: 10px;
        }

        .reference {
            margin-top: 20px;
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
            font-size: 13px; color: var(--text-2);
        }
        .reference code {
            font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
            font-size: 13.5px; font-weight: 700;
            background: var(--bg); border: 1px solid var(--border);
            border-radius: 7px; padding: 5px 10px; color: var(--primary);
        }

        /* Le détail technique, replié. */
        .repli { margin-top: 22px; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }

        .repli > summary {
            list-style: none; cursor: pointer; user-select: none;
            padding: 13px 16px; background: var(--bg);
            font-size: 13px; font-weight: 600; color: var(--text-2);
            display: flex; align-items: center; gap: 9px;
        }
        .repli > summary::-webkit-details-marker { display: none; }
        .repli > summary:hover { background: #EBF2FC; color: var(--primary); }
        .repli > summary .chevron { transition: transform .18s ease; font-size: 11px; }
        .repli[open] > summary .chevron { transform: rotate(90deg); }
        .repli[open] > summary { border-bottom: 1px solid var(--border); }

        .repli-contenu { padding: 16px; background: var(--surface); }

        .ligne-detail {
            display: grid; grid-template-columns: 130px 1fr; gap: 10px;
            padding: 8px 0; border-bottom: 1px dashed var(--border);
            font-size: 12.5px; align-items: start;
        }
        .ligne-detail:last-child { border-bottom: none; }
        .ligne-detail dt { color: var(--text-3); font-weight: 600; }
        .ligne-detail dd {
            color: var(--text); word-break: break-word;
            font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
        }

        pre.trace {
            margin-top: 12px; padding: 14px;
            background: #0F172A; color: #CBD5E1;
            border-radius: 9px; font-size: 11.5px; line-height: 1.6;
            max-height: 320px; overflow: auto;
            font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
        }

        .actions { margin-top: 26px; display: flex; gap: 10px; flex-wrap: wrap; }

        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 18px; border-radius: 9px; border: 1px solid var(--border);
            background: var(--surface); color: var(--text-2);
            font-size: 13.5px; font-weight: 600; text-decoration: none; cursor: pointer;
            font-family: inherit;
        }
        .btn:hover { background: var(--bg); color: var(--primary); }
        .btn-principal { background: var(--primary); border-color: var(--primary); color: #fff; }
        .btn-principal:hover { background: var(--primary-d); color: #fff; }

        .pied {
            margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--border);
            font-size: 12px; color: var(--text-3); text-align: center;
        }

        @media (max-width: 560px) {
            .panne-entete, .panne-corps { padding-left: 20px; padding-right: 20px; }
            .ligne-detail { grid-template-columns: 1fr; gap: 2px; }
        }
    </style>
</head>
<body>
    <main class="panne">
        <div class="panne-entete">
            <div class="marque"><i class="fas fa-screwdriver-wrench"></i></div>
            <div>
                <h1>Page en maintenance</h1>
                <div class="sous">Selflow — DC-Knowing</div>
            </div>
        </div>

        <div class="panne-corps">
            <p class="message">
                Cette page est en maintenance et notre service informatique s'en charge.
                Veuillez patienter, et désolé pour le désagrément.
            </p>

            @if($reference)
                <div class="reference">
                    <i class="fas fa-hashtag" style="color:var(--text-3);"></i>
                    <span>Référence à donner au service informatique :</span>
                    <code id="reference">{{ $reference }}</code>
                    <button type="button" class="btn" style="padding:4px 10px; font-size:12px;"
                            onclick="copierLaReference()">
                        <i class="fas fa-copy"></i> Copier
                    </button>
                </div>
            @endif

            {{-- Le détail, replié : `<details>` s'ouvre au clic sans une ligne
                 de script, et fonctionne même si le navigateur en refuse. --}}
            @if($detail)
                <details class="repli">
                    <summary>
                        <i class="fas fa-chevron-right chevron"></i>
                        Détail technique
                    </summary>
                    <div class="repli-contenu">
                        <dl>
                            <div class="ligne-detail">
                                <dt>Type</dt>
                                <dd>{{ $detail['type'] }}</dd>
                            </div>
                            <div class="ligne-detail">
                                <dt>Message</dt>
                                <dd>{{ $detail['message'] }}</dd>
                            </div>
                            <div class="ligne-detail">
                                <dt>Fichier</dt>
                                <dd>{{ $detail['fichier'] }}:{{ $detail['ligne'] }}</dd>
                            </div>
                            <div class="ligne-detail">
                                <dt>Adresse</dt>
                                <dd>{{ $detail['url'] }}</dd>
                            </div>
                            <div class="ligne-detail">
                                <dt>Moment</dt>
                                <dd>{{ $detail['moment'] }}</dd>
                            </div>
                        </dl>

                        @if(!empty($detail['trace']))
                            <div style="margin-top:14px; display:flex; align-items:center; justify-content:space-between; gap:10px;">
                                <span style="font-size:12px; font-weight:600; color:var(--text-3);">
                                    Suite des appels
                                </span>
                                <button type="button" class="btn" style="padding:4px 10px; font-size:12px;"
                                        onclick="copierLaTrace()">
                                    <i class="fas fa-copy"></i> Copier le détail
                                </button>
                            </div>
                            <pre class="trace" id="trace">{{ $detail['trace'] }}</pre>
                        @endif
                    </div>
                </details>
            @endif

            <div class="actions">
                <a href="javascript:location.reload()" class="btn btn-principal">
                    <i class="fas fa-rotate-right"></i> Réessayer
                </a>
                <a href="{{ url('/') }}" class="btn">
                    <i class="fas fa-house"></i> Retour à l'accueil
                </a>
            </div>

            <div class="pied">
                Le service informatique a été prévenu automatiquement.
            </div>
        </div>
    </main>

    <script>
        /**
         * Copier tout le detail technique d'un coup : c'est ce qu'on colle dans
         * un billet d'assistance, et le recopier a la main d'un ecran de caisse
         * n'arrive jamais.
         */
        function copierLaTrace() {
            const bloc = document.getElementById('trace');
            const reference = document.getElementById('reference')?.textContent?.trim() ?? '';

            if (!bloc || !navigator.clipboard) return;

            const texte = 'Référence : ' + reference + '\n\n' + bloc.textContent;
            const bouton = event.currentTarget;

            navigator.clipboard.writeText(texte).then(() => {
                const avant = bouton.innerHTML;
                bouton.innerHTML = '<i class="fas fa-check"></i> Copié';
                setTimeout(() => { bouton.innerHTML = avant; }, 1800);
            });
        }

        function copierLaReference() {
            const texte = document.getElementById('reference')?.textContent?.trim();

            if (!texte || !navigator.clipboard) return;

            navigator.clipboard.writeText(texte).then(() => {
                const bouton = event.currentTarget;
                const avant = bouton.innerHTML;
                bouton.innerHTML = '<i class="fas fa-check"></i> Copiée';
                setTimeout(() => { bouton.innerHTML = avant; }, 1800);
            });
        }
    </script>
</body>
</html>

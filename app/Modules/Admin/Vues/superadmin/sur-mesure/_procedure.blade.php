{{--
    La procédure de mise en ligne d'un espace sur mesure (23/11/2026).
    Variables : $racine, $ipServeur, $cibleCname, et facultativement
    $sousDomaine, $domaine (valeurs de l'entreprise) et $compact.
--}}
@php
    $ip = $ipServeur ?: 'IP du serveur';
    $sd = !empty($sousDomaine) ? $sousDomaine : 'nom-entreprise';
    $dom = !empty($domaine) ? $domaine : 'gestion.entreprise.ci';
    $parties = explode('.', $dom);
    $estRacine = count($parties) <= 2;
    $hoteDns = $estRacine ? '@' : implode('.', array_slice($parties, 0, -2));
    $zone = implode('.', array_slice($parties, -2));
    $adresseSd = $sd . '.' . $racine;
    $compact = $compact ?? false;
@endphp
<style>
    .proc-grille { display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:16px; }
    .proc-carte { border:1px solid var(--border); border-radius:14px; padding:18px; background:#fff; }
    .proc-carte h4 { margin:0 0 4px; font-size:16px; display:flex; align-items:center; gap:8px; }
    .proc-carte .chapeau { font-size:12.5px; color:var(--text-2); margin:0 0 12px; line-height:1.6; }
    .proc-etapes { counter-reset:etape; list-style:none; padding:0; margin:0; display:grid; gap:10px; }
    .proc-etapes > li { counter-increment:etape; position:relative; padding-left:36px; font-size:13px; line-height:1.65; color:var(--text); }
    .proc-etapes > li::before { content:counter(etape); position:absolute; left:0; top:0; width:24px; height:24px; border-radius:50%; background:var(--primary); color:#fff; font-size:12px; font-weight:800; display:grid; place-items:center; }
    .proc-etapes .ou { font-size:12px; color:var(--text-3); }
    .proc-code { display:block; margin-top:6px; background:#0f172a; color:#e2e8f0; border-radius:8px; padding:8px 10px; font-family:ui-monospace,monospace; font-size:12px; overflow-x:auto; white-space:nowrap; }
    .proc-tableau { width:100%; border-collapse:collapse; font-size:12.5px; }
    .proc-tableau th, .proc-tableau td { border-bottom:1px solid var(--border); padding:8px 10px; text-align:left; vertical-align:top; }
    .proc-tableau th { background:var(--bg3); font-weight:800; }
    .proc-alerte { background:#fffbeb; border:1px solid #fde68a; color:#92400e; border-radius:10px; padding:10px 12px; font-size:12.5px; line-height:1.6; }
</style>

@unless($compact)
<div class="card" style="padding:18px; margin-bottom:16px;">
    <h3 style="margin-top:0;">Sous-domaine ou domaine propre : ce qui change</h3>
    <div style="overflow-x:auto;">
    <table class="proc-tableau">
        <thead><tr><th></th><th>Sous-domaine Selflow</th><th>Domaine propre</th></tr></thead>
        <tbody>
            <tr><th>Exemple</th><td><code>{{ $adresseSd }}</code></td><td><code>{{ $dom }}</code></td></tr>
            <tr><th>À qui appartient la zone DNS</th><td>À nous : c'est notre domaine <code>{{ $racine }}</code>.</td><td>À l'entreprise : le domaine est à son nom, chez son registraire. Il nous faut l'accès à sa zone DNS (ou qu'elle nous la délègue).</td></tr>
            <tr><th>Ce qu'on inscrit dans les DNS</th><td>Rien de plus si la zone de <code>{{ $racine }}</code> est gérée par notre cPanel : cPanel crée l'enregistrement tout seul.</td><td>Un enregistrement <strong>A</strong> vers {{ $ip }} (ou un <strong>CNAME</strong> vers <code>{{ $cibleCname }}</code> si c'est un sous-domaine comme <code>gestion.</code>).</td></tr>
            <tr><th>Délai</th><td>Quelques minutes.</td><td>De quelques minutes à 48 h (propagation DNS).</td></tr>
            <tr><th>Certificat HTTPS</th><td>AutoSSL, dès que le sous-domaine est créé.</td><td>AutoSSL, <strong>seulement après</strong> la propagation DNS (sinon il échoue).</td></tr>
            <tr><th>Coût</th><td>Aucun.</td><td>Le domaine, payé par l'entreprise à son registraire.</td></tr>
        </tbody>
    </table>
    </div>
</div>
@endunless

<div class="proc-grille">
    <div class="proc-carte">
        <h4><i class="fas fa-sitemap" style="color:var(--primary);"></i> Sous-domaine : <code style="font-size:13px;">{{ $adresseSd }}</code></h4>
        <p class="chapeau">Tout se fait chez nous : cPanel et la fiche Selflow.</p>
        <ol class="proc-etapes">
            <li><strong>Selflow</strong>, fiche de l'entreprise, onglet Domaine : saisir <code>{{ $sd }}</code> dans « Sous-domaine Selflow », puis <strong>Enregistrer</strong>.</li>
            <li><strong>cPanel</strong>, menu <strong>Domaines</strong>, bouton <strong>Créer un domaine</strong> :
                <span class="proc-code">Domaine : {{ $adresseSd }}</span>
                Décocher « Partager la racine du document », puis mettre comme <strong>racine du document</strong> exactement celle de <code>{{ $racine }}</code> (dossier <code>public</code> de Selflow, visible dans la liste des domaines). <strong>Soumettre</strong>.</li>
            <li><strong>DNS</strong> : si la zone de <code>{{ $racine }}</code> est dans ce cPanel, l'enregistrement est créé automatiquement, rien à faire. Sinon, l'ajouter là où la zone est gérée :
                <span class="proc-code">{{ $sd }}.{{ $racine }}.&nbsp;&nbsp;A&nbsp;&nbsp;{{ $ip }}</span></li>
            <li><strong>cPanel</strong>, <strong>SSL/TLS Status</strong> : cocher <code>{{ $adresseSd }}</code> et cliquer <strong>Run AutoSSL</strong>. Attendre le cadenas vert (quelques minutes).</li>
            <li><strong>Selflow</strong>, onglet Domaine : <strong>Vérifier les adresses</strong>. Le message doit dire « pointe sur le serveur ».</li>
            <li>Onglet <strong>Modules</strong> : cocher les modules voulus et <strong>Espace actif</strong>, Enregistrer. Ouvrir <code>https://{{ $adresseSd }}</code> pour contrôler.</li>
        </ol>
        <p class="ou" style="margin:12px 0 0; font-size:12px; color:var(--text-3); line-height:1.6;">Option, une fois pour toutes : créer le domaine joker <code>*.{{ $racine }}</code> avec la même racine. Les nouveaux sous-domaines répondent alors sans passer par l'étape 2. Le certificat joker n'est délivré par AutoSSL que si la zone DNS est gérée par ce cPanel ; sinon, garder l'étape 2 pour chaque entreprise.</p>
    </div>

    <div class="proc-carte">
        <h4><i class="fas fa-globe" style="color:var(--primary);"></i> Domaine propre : <code style="font-size:13px;">{{ $dom }}</code></h4>
        <p class="chapeau">Deux endroits : la zone DNS du domaine de l'entreprise, puis notre cPanel. L'ordre compte.</p>
        <ol class="proc-etapes">
            <li><strong>Obtenir l'accès</strong> à la zone DNS de <code>{{ $zone }}</code> : compte chez son registraire (ou chez son hébergeur actuel si ses DNS y sont), ou délégation de la zone à nous.</li>
            <li><strong>Zone DNS de {{ $zone }}</strong>, ajouter :
                @if($estRacine)
                    <span class="proc-code">@&nbsp;&nbsp;A&nbsp;&nbsp;{{ $ip }}&nbsp;&nbsp;(TTL 3600)</span>
                    <span class="proc-code">www&nbsp;&nbsp;CNAME&nbsp;&nbsp;{{ $dom }}.</span>
                    <span class="ou">Un domaine nu ({{ $dom }}) n'accepte pas de CNAME : enregistrement A obligatoire. Attention, s'il porte déjà le site vitrine de l'entreprise, ce site sera remplacé : préférer un sous-domaine comme <code>gestion.{{ $dom }}</code>.</span>
                @else
                    <span class="proc-code">{{ $hoteDns }}&nbsp;&nbsp;A&nbsp;&nbsp;{{ $ip }}&nbsp;&nbsp;(TTL 3600)</span>
                    <span class="ou">ou, au choix :</span>
                    <span class="proc-code">{{ $hoteDns }}&nbsp;&nbsp;CNAME&nbsp;&nbsp;{{ $cibleCname }}.</span>
                    <span class="ou">Le site existant de l'entreprise ({{ $zone }}) n'est pas touché. Ne pas modifier ses enregistrements MX : ses e-mails en dépendent.</span>
                @endif
            </li>
            <li><strong>Attendre la propagation</strong> : de quelques minutes à 48 h. On peut contrôler sur un poste avec <code>nslookup {{ $dom }}</code> : la réponse doit être {{ $ip }}.</li>
            <li><strong>cPanel</strong>, <strong>Domaines</strong>, <strong>Créer un domaine</strong> :
                <span class="proc-code">Domaine : {{ $dom }}</span>
                Décocher « Partager la racine du document », racine du document = celle de <code>{{ $racine }}</code> (dossier <code>public</code> de Selflow). Soumettre.</li>
            <li><strong>cPanel</strong>, <strong>SSL/TLS Status</strong> : <strong>Run AutoSSL</strong> pour <code>{{ $dom }}</code>{{ $estRacine ? ' et www.' . $dom : '' }}. S'il échoue, c'est que la propagation n'est pas finie : réessayer plus tard.</li>
            <li><strong>Selflow</strong>, onglet Domaine : saisir <code>{{ $dom }}</code> dans « Domaine propre », Enregistrer, puis <strong>Vérifier les adresses</strong> : la fiche affiche « Vérifié le … ».</li>
            <li>Onglet <strong>Modules</strong> : modules voulus et <strong>Espace actif</strong>, Enregistrer. Ouvrir <code>https://{{ $dom }}</code> pour contrôler, puis remettre l'adresse à l'entreprise.</li>
        </ol>
    </div>
</div>

@unless($compact)
<div class="card" style="padding:18px; margin-top:16px;">
    <h3 style="margin-top:0;">À régler une seule fois sur le serveur</h3>
    <ul style="font-size:13px; line-height:1.8; padding-left:18px; margin:0;">
        <li>Dans <code>.env</code> : <code>SELFLOW_DOMAINE_RACINE={{ $racine }}</code> (le domaine sous lequel se créent les sous-domaines) et <code>SELFLOW_IP_SERVEUR=</code>l'adresse IP du serveur (cPanel, panneau « Informations générales », Adresse IP partagée). Puis <code>php artisan optimize:clear &amp;&amp; php artisan optimize</code>.</li>
        <li><code>SESSION_DOMAIN</code> doit rester <strong>vide</strong> : sinon la connexion d'une entreprise serait partagée avec les autres sous-domaines.</li>
        <li><code>APP_URL</code> ne change pas : il reste l'adresse de Selflow.</li>
    </ul>
    <div class="proc-alerte" style="margin-top:12px;"><i class="fas fa-triangle-exclamation"></i> Activer l'espace <strong>après</strong> le certificat HTTPS : une adresse active sans certificat afficherait une alerte de sécurité aux utilisateurs de l'entreprise.</div>
</div>
@endunless

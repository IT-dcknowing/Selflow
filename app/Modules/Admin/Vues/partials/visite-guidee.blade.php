{{--
    La visite guidée de première utilisation.

    Une main désigne l'élément, une bulle explique à quoi il sert. L'utilisateur
    avance, recule, ou passe — et la visite ne revient plus. Elle se relance à
    la demande depuis le menu.

    Les cibles sont des attributs `data-visite` posés exprès dans le gabarit :
    une classe CSS change au gré des retouches, un repère posé pour cela ne
    bouge pas. Une étape dont la cible est absente — un module fermé, par
    exemple — est simplement sautée.
--}}
@php
    $utilisateur = auth()->user();
    $entreprise  = $utilisateur?->entreprise;
    $aConfigurer = $entreprise && !$entreprise->souscription_terminee_le;
    // La visite ne promet pas des livres que l'entreprise ne tient pas.
    $livres      = (bool) $entreprise?->comptabiliteOuverte();

    $etapes = array_values(array_filter([
        [
            'cible'  => null,
            'titre'  => 'Bienvenue dans Selflow',
            'texte'  => 'Prenez une minute : je vous montre les quatre endroits qui comptent. '
                      . 'Vous pourrez revoir cette visite à tout moment depuis votre profil.',
        ],
        $aConfigurer ? [
            'cible'  => '[data-visite-banniere]',
            'titre'  => 'Commencez par configurer votre métier',
            'texte'  => $livres
                ? 'En cinq étapes, Selflow remplit votre catalogue, votre plan comptable et '
                  . 'vos journaux à partir de votre activité. Sans cela, vous partez d\'une page blanche.'
                : 'En cinq étapes, Selflow remplit votre catalogue à partir de votre activité. '
                  . 'Sans cela, vous partez d\'une page blanche.',
        ] : null,
        [
            'cible'  => '[data-visite="catalogue"]',
            'titre'  => 'Votre catalogue',
            'texte'  => 'Vos articles et leurs prix. Après la configuration, il est déjà rempli — '
                      . 'il ne vous reste qu\'à saisir vos montants, que nous ne pouvons pas deviner.',
        ],
        [
            'cible'  => '[data-visite="nouvelle-vente"]',
            'titre'  => 'Vendre',
            'texte'  => 'Le point de départ de toute facture. Devis, bon de commande ou facture : '
                      . ($livres ? 'la pièce se choisit à la saisie, et la comptabilité suit toute seule.'
                                 : 'la pièce se choisit à la saisie.'),
        ],
        [
            'cible'  => '[data-visite="clients"]',
            'titre'  => 'Vos clients',
            'texte'  => 'Un client enregistré permet la vente à crédit et le suivi de ce qu\'il vous doit. '
                      . 'Pour une vente au comptant, vous pouvez vous en passer.',
        ],
        [
            'cible'  => '[data-visite="fne"]',
            'titre'  => 'La facture normalisée',
            'texte'  => 'C\'est ici que vos factures partent à la DGI et reviennent certifiées, '
                      . 'avec leur numéro et leur code QR. Vous y suivez aussi vos stickers.',
        ],
        [
            'cible'  => '[data-visite="parametres"]',
            'titre'  => 'Vos paramètres',
            'texte'  => 'Le logo qui figurera sur vos factures, vos identifiants fiscaux, '
                      . 'et vos clés de connexion à la plateforme FNE.',
        ],
    ]));
@endphp

@if($utilisateur && !$utilisateur->aFaitLaVisite() && !empty($etapes))
<div id="visite-guidee" hidden>
    <div class="vg-voile"></div>
    <div class="vg-halo" hidden></div>

    <div class="vg-bulle" role="dialog" aria-labelledby="vg-titre" aria-describedby="vg-texte">
        <div class="vg-progres"><span class="vg-barre"></span></div>
        <div class="vg-corps">
            <p class="vg-compteur"></p>
            <h3 id="vg-titre"></h3>
            <p id="vg-texte"></p>
        </div>
        <div class="vg-pieds">
            <button type="button" class="vg-passer">Passer la visite</button>
            <div class="vg-nav">
                <button type="button" class="vg-precedent">Retour</button>
                <button type="button" class="vg-suivant">Suivant</button>
            </div>
        </div>
    </div>

    <div class="vg-main" hidden aria-hidden="true">👆</div>
</div>

<style>
    #visite-guidee { position:fixed; inset:0; z-index:9000; }
    .vg-voile { position:absolute; inset:0; background:rgba(15,23,42,.62); backdrop-filter:blur(1px); }

    /* Le halo entoure la cible sans la masquer : l'ombre portee immense
       assombrit tout le reste, ce qui evite de decouper le voile. */
    .vg-halo {
        position:absolute; border-radius:10px; pointer-events:none;
        box-shadow:0 0 0 9999px rgba(15,23,42,.62), 0 0 0 3px #fff;
        transition:top .28s ease, left .28s ease, width .28s ease, height .28s ease;
    }
    .vg-halo:not([hidden]) ~ .vg-voile,
    #visite-guidee:has(.vg-halo:not([hidden])) .vg-voile { opacity:0; }

    .vg-bulle {
        position:absolute; width:min(370px, calc(100vw - 32px));
        background:#fff; border-radius:14px; overflow:hidden;
        box-shadow:0 20px 45px rgba(15,23,42,.32);
        transition:top .28s ease, left .28s ease;
    }
    .vg-progres { height:3px; background:#e2e8f0; }
    .vg-barre { display:block; height:100%; width:0; background:var(--primary, #002B5C); transition:width .28s ease; }

    .vg-corps { padding:20px 22px 6px; }
    .vg-compteur { font-size:11px; font-weight:700; letter-spacing:.6px; text-transform:uppercase;
                   color:#94a3b8; margin:0 0 8px; }
    .vg-bulle h3 { font-size:17px; font-weight:800; margin:0 0 8px; color:#0f172a; line-height:1.25; }
    .vg-bulle p#vg-texte { font-size:13.5px; color:#475569; line-height:1.6; margin:0; }

    .vg-pieds { display:flex; align-items:center; gap:10px; padding:16px 22px 18px; }
    .vg-pieds button { font-family:inherit; font-size:13px; font-weight:600; border-radius:8px;
                       padding:8px 15px; cursor:pointer; border:1px solid transparent; }
    .vg-passer { background:none; color:#94a3b8; padding-left:0; }
    .vg-passer:hover { color:#475569; }
    .vg-nav { margin-left:auto; display:flex; gap:8px; }
    .vg-precedent { background:#fff; border-color:#e2e8f0; color:#475569; }
    .vg-suivant { background:var(--primary, #002B5C); color:#fff; }
    .vg-suivant:hover { filter:brightness(1.12); }
    button:focus-visible { outline:2px solid var(--primary, #002B5C); outline-offset:2px; }

    .vg-main { position:absolute; font-size:30px; pointer-events:none;
               transition:top .28s ease, left .28s ease; animation:vg-tape 1.5s ease-in-out infinite; }
    @keyframes vg-tape { 0%,100% { transform:translate(0,0); } 50% { transform:translate(5px,-5px); } }

    @media (prefers-reduced-motion: reduce) {
        .vg-halo, .vg-bulle, .vg-main, .vg-barre { transition:none; }
        .vg-main { animation:none; }
    }
</style>

<script>
(function () {
    const etapes = @json($etapes);
    const boite  = document.getElementById('visite-guidee');
    if (!boite || !etapes.length) return;

    const halo = boite.querySelector('.vg-halo');
    const main = boite.querySelector('.vg-main');
    const bulle = boite.querySelector('.vg-bulle');
    const barre = boite.querySelector('.vg-barre');
    let rang = 0;

    // ── Ce que la visite peut réellement montrer (chantier 9.4) ──
    //
    // Elle ne vérifiait que l'existence de la cible. Depuis que les sections
    // du menu se replient (lot 37), une cible peut exister et rester
    // invisible : la main désignait un coin vide, « l'étape 3 sur 7 parlait
    // du catalogue alors que la section n'était pas à l'écran ». Une cible
    // dans une section repliée est atteignable — la visite l'ouvre, puis la
    // referme ; une cible masquée autrement (habilitation, module, écran) ne
    // l'est pas, et l'étape est sautée.
    const telephone = () => window.matchMedia('(max-width: 768px)').matches;
    const enteteDe = cible => cible.closest('.nav-groupe')?.previousElementSibling ?? null;
    const repliee = cible => enteteDe(cible)?.getAttribute('aria-expanded') === 'false';

    function atteignable(etape) {
        if (!etape.cible) return true;
        const cible = document.querySelector(etape.cible);
        return !!cible && (repliee(cible) || cible.getClientRects().length > 0);
    }

    let ouvertes = [];
    let barreOuverte = false;

    /** Ouvre ce qui cache la cible ; dit s'il a fallu attendre une animation. */
    function reveler(cible) {
        const entete = enteteDe(cible);
        if (entete && entete.getAttribute('aria-expanded') === 'false') {
            entete.setAttribute('aria-expanded', 'true');
            ouvertes.push(entete);
        }
        if (cible.closest('.sidebar') && telephone() && !document.body.classList.contains('sidebar-open')) {
            document.body.classList.add('sidebar-open');
            barreOuverte = true;
            return true;
        }
        return false;
    }

    /**
     * Remet le menu comme l'utilisateur l'avait laissé. La barre d'un
     * téléphone reste ouverte si l'étape suivante y désigne encore quelque
     * chose : la refermer pour la rouvrir aussitôt ferait clignoter l'écran.
     */
    function ranger(garderLaBarre = false) {
        ouvertes.forEach(e => e.setAttribute('aria-expanded', 'false'));
        ouvertes = [];
        if (barreOuverte && !garderLaBarre) {
            document.body.classList.remove('sidebar-open');
            barreOuverte = false;
        }
    }

    const visibles = etapes.filter(atteignable);
    if (!visibles.length) return;

    function placer() {
        const etape = visibles[rang];
        boite.querySelector('.vg-compteur').textContent = `Étape ${rang + 1} sur ${visibles.length}`;
        boite.querySelector('#vg-titre').textContent = etape.titre;
        boite.querySelector('#vg-texte').textContent = etape.texte;
        barre.style.width = ((rang + 1) / visibles.length * 100) + '%';
        boite.querySelector('.vg-precedent').style.display = rang === 0 ? 'none' : '';
        boite.querySelector('.vg-suivant').textContent = rang === visibles.length - 1 ? 'Terminer' : 'Suivant';

        const cible = etape.cible ? document.querySelector(etape.cible) : null;
        ranger(!!cible?.closest('.sidebar'));

        if (!cible) {
            halo.hidden = true;
            main.hidden = true;
            bulle.style.top  = '50%';
            bulle.style.left = '50%';
            bulle.style.transform = 'translate(-50%, -50%)';
            return;
        }

        // La barre d'un téléphone glisse en place : mesurer pendant le
        // glissement poserait la main là où la cible n'est pas encore.
        if (reveler(cible)) {
            halo.hidden = true;
            main.hidden = true;
            setTimeout(() => entourer(cible), 350);
            return;
        }

        entourer(cible);
    }

    function entourer(cible) {
        // Défilement immédiat, et non animé : la mesure qui suit doit lire
        // la position d'arrivée, pas celle du départ.
        cible.scrollIntoView({ block: 'center', behavior: 'auto' });
        const zone = cible.getBoundingClientRect();

        halo.hidden = false;
        halo.style.top    = (zone.top - 6) + 'px';
        halo.style.left   = (zone.left - 6) + 'px';
        halo.style.width  = (zone.width + 12) + 'px';
        halo.style.height = (zone.height + 12) + 'px';

        main.hidden = false;
        main.style.top  = (zone.top + zone.height / 2 - 14) + 'px';
        main.style.left = (zone.right + 10) + 'px';

        // La bulle se pose à droite de la cible, ou en dessous si l'écran est
        // trop étroit — sur un téléphone, la colonne de gauche prend tout.
        bulle.style.transform = 'none';
        const largeur = bulle.offsetWidth || 370;
        const aDroite = zone.right + 60 + largeur < window.innerWidth;

        bulle.style.left = aDroite
            ? (zone.right + 50) + 'px'
            : Math.max(16, Math.min(window.innerWidth - largeur - 16, zone.left)) + 'px';
        bulle.style.top = aDroite
            ? Math.max(16, Math.min(window.innerHeight - 260, zone.top - 20)) + 'px'
            : Math.min(window.innerHeight - 280, zone.bottom + 18) + 'px';
    }

    function fermer() {
        ranger();
        boite.remove();
        fetch(@js(route('admin.visite.terminer')), {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'Accept': 'application/json',
            },
        }).catch(() => {});
    }

    boite.querySelector('.vg-suivant').addEventListener('click', () => {
        rang < visibles.length - 1 ? (rang++, placer()) : fermer();
    });
    boite.querySelector('.vg-precedent').addEventListener('click', () => {
        if (rang > 0) { rang--; placer(); }
    });
    boite.querySelector('.vg-passer').addEventListener('click', fermer);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') fermer(); });
    window.addEventListener('resize', placer);

    boite.hidden = false;
    placer();
})();
</script>
@endif

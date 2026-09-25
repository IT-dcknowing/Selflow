{{--
    Le barème du timbre de quittance, côté écran.

    À inclure à l'intérieur d'un bloc <script>.

    ## Pourquoi ce fichier existe

    L'écran doit annoncer le net à payer **avant** que la pièce parte à la
    plateforme : le client tend son argent au comptoir, pas après la réponse de
    la DGI. Il lui faut donc le barème.

    Il en existait une copie écrite à la main dans `ventes/nouvelle`, et
    l'écran des achats n'en avait aucune — d'où un net sous-estimé du timbre
    sur un bordereau réglé en espèces, et une monnaie rendue fausse d'autant.

    ## Ce qui empêche la copie de dériver

    **Les valeurs ne sont pas recopiées : elles sont tirées de
    `TimbreQuittanceService`**, seule autorité en la matière et périmètre gelé
    de la conformité FNE. Le service n'est pas modifié ; il est lu. Le jour où
    la DGI publie un nouveau barème, il change à un seul endroit et les deux
    écrans suivent.

    Le journal du projet garde la trace de ce que coûte une seconde copie : le
    timbre a été estimé à 1,5 %, taux qui ne figure dans aucun texte, là où
    l'article 873 du CGI fixe un barème forfaitaire par tranche.

    ## Ce que la vue appelante doit fournir

    Rien. L'entreprise courante est lue ici.
--}}
@php
    $bareme = \App\Modules\Admin\Services\TimbreQuittanceService::BAREME;
    $trancheSuperieure = \App\Modules\Admin\Services\TimbreQuittanceService::DROIT_TRANCHE_SUPERIEURE;
    $timbreActif = (bool) (Auth::user()->entreprise->timbre_quittance ?? false);
@endphp
/** Le bareme de l'article 873 du CGI, tel que `TimbreQuittanceService` le tient. */
const BAREME_TIMBRE = {!! json_encode($bareme) !!};
const TIMBRE_TRANCHE_SUPERIEURE = {{ (int) $trancheSuperieure }};

/**
 * L'option est declaree active dans les parametres de l'entreprise.
 *
 * Elle reflete la case cochee sur la plateforme FNE : c'est la que la DGI
 * decide d'appliquer le timbre, et l'API ne permet pas de lire ce reglage.
 * Sans elle, une piece non encore normalisee annoncerait un timbre que la
 * plateforme ne retiendra pas.
 */
const TIMBRE_DECLARE_ACTIF = {{ $timbreActif ? 'true' : 'false' }};

/**
 * Droit de timbre du sur une somme encaissee.
 *
 * Meme forme que `TimbreQuittanceService::montantDu()`, aux memes conditions
 * que `estApplicable()` : le reglement doit etre en especes -- un virement ou
 * un paiement mobile laisse sa propre trace et ne releve pas de la quittance.
 */
function timbreDeQuittance(sommeEncaissee, modePaiement) {
    if (!TIMBRE_DECLARE_ACTIF || sommeEncaissee <= 0) return 0;

    const especes = ['caisse', 'especes', 'espèces', 'cash'];
    if (!especes.includes(String(modePaiement || '').toLowerCase().trim())) return 0;

    for (const [plafond, droit] of BAREME_TIMBRE) {
        if (sommeEncaissee <= plafond) return droit;
    }
    return TIMBRE_TRANCHE_SUPERIEURE;
}

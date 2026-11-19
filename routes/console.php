<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ProcessUtils;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Sortie brute des commandes FNE.
 *
 * Sur Linux, elle rejoint laravel.log avec les événements généraux. Sous
 * Windows, le service conserve un fichier distinct : appendOutputTo verrouille
 * le fichier pendant que Monolog écrit et ferait échouer la commande.
 */
$sortiesPortail = \App\Modules\Admin\Services\ScraperPortailFneService::sorties();

/*
 * Quelqu'un doit servir la file d'attente.
 *
 * `QUEUE_CONNECTION=database` : la normalisation automatique d'une facture
 * DÉPOSE son travail dans la table `jobs` et rend la main. Sans personne pour
 * l'y prendre, il y reste. C'est ce qui a été constaté le 25/09/2026 — la case
 * « normaliser automatiquement » était cochée, l'écran affichait « En cours »,
 * et rien n'était jamais parti à la DGI. La normalisation manuelle, elle,
 * fonctionnait : elle exécute sur-le-champ.
 *
 * Pas de service permanent : l'hébergement mutualisé n'en admet pas. Le
 * planificateur passe chaque minute, vide ce qui attend, et s'arrête —
 * `--stop-when-empty` pour ne pas tenir la minute à ne rien faire,
 * `--max-time=50` pour laisser la place au passage suivant. Le délai de
 * certification est donc d'une minute au pire, et non de l'infini.
 *
 * Les tentatives et le délai entre elles restent ceux que le travail déclare
 * (`NormaliserFactureFne` : trois tentatives, trente secondes) : les écrire
 * ici les écraserait.
 */
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();

// Re-synchronisation des écritures COMPTAFLOW échouées (toutes les 5 minutes)
Schedule::command('selflow:sync-ecritures')->everyFiveMinutes()->withoutOverlapping();

/*
 * Comptabilisation du stock à la périodicité choisie par chaque entreprise.
 *
 * Désactivée par défaut au niveau de l'entreprise. Activée, elle produit
 * soit une écriture quotidienne, soit une écriture agrégée à chaque période
 * de N mois. Le traitement est exécuté après la journée de mouvements.
 */
Schedule::command('selflow:comptabiliser-stock-periodique')
    ->dailyAt('00:10')
    ->withoutOverlapping();

/*
 * Ramassage des relevés du portail FNE déposés dans le dossier d'import.
 *
 * Toutes les heures, et non toutes les minutes : un relevé se produit au mieux
 * une fois par jour, et relire un dossier soixante fois par heure ne le ferait
 * pas arriver plus tôt. `withoutOverlapping` évite qu'un dépôt volumineux soit
 * relu par le passage suivant pendant qu'il est encore en cours de lecture.
 *
 * Le passage est sans effet quand rien n'a changé : un fichier déjà lu est
 * reconnu à son empreinte. Il n'y a donc pas de « premier passage » à préparer
 * ni de fichier à déplacer après coup.
 *
 * La sortie est écrite dans un journal dédié plutôt que perdue : c'est le seul
 * endroit où l'on verra qu'un fichier est refusé pour cause de nom hors
 * nomenclature, personne n'étant devant l'écran quand la tâche tourne.
 */
Schedule::command('portail-fne:importer')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo($sortiesPortail);

/*
 * Le pas du relevé des factures reçues, en minutes.
 *
 * Il commande deux tâches — celle qui va au portail, plus bas, et celle qui
 * range ce qu'elle dépose, juste ici. Les séparer ferait un relevé toutes les
 * cinq minutes rangé toutes les heures : le portail serait interrogé 288 fois
 * par jour pour un écran qui ne bougerait qu'au ramassage suivant.
 *
 * Borné à 60 : au-delà, le pas ne veut plus rien dire dans une expression cron,
 * dont la minute ne va pas plus loin que 59.
 */
$pasAchats = max(1, min(60, (int) config('selflow.portail_fne.scraper.achats_minutes', 5)));

/*
 * Les factures reçues, relevées par `achats.js` dans un sous-dossier.
 *
 * Une tâche à part, et non un ajout à la précédente : les deux chaînes lisent
 * des dossiers différents et n'ont aucune raison de tomber ensemble le jour où
 * l'une casse.
 *
 * **Décalée de deux minutes sur le relevé**, et c'est tout l'intérêt : le
 * scraper ouvre un navigateur et met des dizaines de secondes à déposer son
 * fichier. Ramasser à la même minute lirait toujours le fichier du passage
 * précédent, et l'écran aurait un tour de retard en permanence.
 */
Schedule::command('portail-fne:importer-achats')
    ->cron("2-59/{$pasAchats} * * * *")
    ->withoutOverlapping()
    ->appendOutputTo($sortiesPortail);

$pasAvoirs = max(1, min(60, (int) config('selflow.portail_fne.scraper.avoirs_minutes', 5)));

/*
 * Les factures d'avoir, relevées par `avoirs.js` dans le sous-dossier avoirs/.
 *
 * Décalée de deux minutes sur le relevé, pour laisser au scraper le temps
 * de déposer son fichier complet.
 */
// Le relevé des avoirs part une demi-période après celui des achats (voir
// plus bas) : les deux ouvraient chacun un navigateur à la même minute. Son
// import suit de deux minutes, comme pour les achats.
$decalageAvoirs = $pasAvoirs >= 2 ? intdiv($pasAvoirs, 2) : 0;
$importAvoirs = ($decalageAvoirs + 2) % max(1, $pasAvoirs);

Schedule::command('portail-fne:importer-avoirs')
    ->cron("{$importAvoirs}-59/{$pasAvoirs} * * * *")
    ->withoutOverlapping()
    ->appendOutputTo($sortiesPortail);

/*
 * Les factures de vente émises : leur import ne dépend pas du navigateur qui
 * a lancé le relevé. Si l'utilisateur ferme la page, le ramassage planifié
 * range quand même le JSON dès qu'il est déposé par ventes.js.
 */
$pasVentes = max(1, min(60, (int) config('selflow.portail_fne.scraper.ventes_minutes', 5)));

Schedule::command('portail-fne:importer-ventes')
    ->cron("*/{$pasVentes} * * * *")
    ->withoutOverlapping()
    ->appendOutputTo($sortiesPortail);

/*
 * Rapprochement des rejets FNE avec les relevés du portail.
 *
 * Juste après le ramassage, et non avant : un rejet ne se diagnostique qu'avec
 * un relevé sous la main, et c'est le passage précédent qui vient de le ranger
 * en base. Un rejet sans relevé reste ouvert et sera repris au passage suivant.
 *
 * La commande ne corrige rien : elle écrit la comparaison à côté du rejet.
 */
Schedule::command('fne:diagnostiquer-rejets')
    ->hourlyAt(10)
    ->withoutOverlapping()
    ->appendOutputTo($sortiesPortail);

/*
 * Ce que le portail a changé depuis le relevé précédent.
 *
 * Juste après le ramassage et le rapprochement : le relevé qui vient d'être
 * rangé est celui qu'on compare. Un timbre de quittance désactivé au portail un
 * mardi soir n'apparaissait jusqu'ici nulle part — il fallait attendre qu'une
 * facture soit refusée pour aller chercher ce qui avait bougé, sans savoir
 * quand.
 *
 * `--silencieux` parce qu'un journal qui répète chaque heure « aucun
 * changement » cesse d'être lu, et c'est le jour où il dit quelque chose qu'on
 * ne le lira pas.
 */
Schedule::command('portail-fne:changements --silencieux')
    ->hourlyAt(15)
    ->withoutOverlapping()
    ->appendOutputTo($sortiesPortail);

/*
 * Le relèvement du portail lui-même.
 *
 * Le scraper reste extérieur à Selflow : l'application ne va chercher les
 * relevés nulle part, elle lit un dossier. Ce qui est planifié ici n'est donc
 * pas une dépendance, c'est une commodité — plutôt qu'une seconde tâche
 * Windows que personne ne pensera à surveiller, le passage se range dans le
 * planificateur déjà en place et écrit dans le même journal que le reste.
 * Débrancher ces deux lignes ne casse rien : les fichiers arriveront autrement,
 * ou n'arriveront pas, et une demande qui traîne le dira.
 *
 * Éteint tant que `PORTAIL_FNE_SCRAPER_ACTIF` n'est pas posé : sans
 * identifiants.json rempli, chaque passage échouerait, et un journal plein
 * d'erreurs sans objet cesse d'être lu.
 */
if (config('selflow.portail_fne.scraper.actif')) {
    $scraperFne = \App\Modules\Admin\Services\ScraperPortailFneService::class;

    // Les callbacks ne lancent plus directement Node avec --tous.
    // Chaque méthode du service filtre maintenant les NCC sur l'autorisation
    // individuelle donnée par le superadministrateur.
    Schedule::call(fn () => $scraperFne::lancerPourTousLesLoginsAutorises())
        ->hourlyAt(config('selflow.portail_fne.scraper.minute_horaire'))
        ->name('selflow-portail-fne-global-horaire')
        ->withoutOverlapping(30);

    Schedule::call(fn () => $scraperFne::lancerPourTousLesLoginsAutorises())
        ->dailyAt(config('selflow.portail_fne.scraper.heure_nocturne'))
        ->name('selflow-portail-fne-global-nocturne')
        ->withoutOverlapping(120);

    if (config('selflow.portail_fne.scraper.achats_actif')) {
        Schedule::call(fn () => $scraperFne::lancerAchats())
            ->cron("*/{$pasAchats} * * * *")
            ->name('selflow-portail-fne-achats')
            ->withoutOverlapping($pasAchats * 2);
    }

    if (config('selflow.portail_fne.scraper.avoirs_actif')) {
        Schedule::call(fn () => $scraperFne::lancerAvoirs())
            ->cron("{$decalageAvoirs}-59/{$pasAvoirs} * * * *")
            ->name('selflow-portail-fne-avoirs')
            ->withoutOverlapping($pasAvoirs * 2);
    }

    if (config('selflow.portail_fne.scraper.ventes_actif', true)) {
        Schedule::call(fn () => $scraperFne::lancerVentes())
            ->cron("*/{$pasVentes} * * * *")
            ->name('selflow-portail-fne-ventes')
            ->withoutOverlapping($pasVentes * 2);
    }
}

// Rotation mensuelle des clés de liaison Comptaflow.
//
// Une clé posée une fois et jamais changée ouvre le dossier comptable d'une
// entreprise aussi longtemps qu'elle existe. La rotation ne rend pas une fuite
// impossible : elle borne sa durée de vie à un mois.
//
// Le premier du mois, à 3 h — heure où aucune caisse n'encaisse, donc où une
// clé qui change ne croise aucun déversement. `withoutOverlapping()` par
// prudence : la commande est déjà bornée à cinquante dossiers, mais un
// Comptaflow lent pourrait la faire durer.
Schedule::command('selflow:renouveler-cles-comptaflow')
    ->monthlyOn(1, '03:00')
    ->withoutOverlapping();

// La corbeille des entreprises : effacement définitif après 30 jours (16/10/2026).
Schedule::command('selflow:purger-corbeille')->dailyAt('03:30')->withoutOverlapping();

// Traçabilité : les visites de plus de 30 jours sont purgées (16/10/2026).
Schedule::command('selflow:purger-visites')->dailyAt('03:40')->withoutOverlapping();

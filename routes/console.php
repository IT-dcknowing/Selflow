<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ProcessUtils;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Ce que les tâches du portail impriment.
 *
 * **Un fichier distinct du canal `portail_fne`**, et l'essai du 08/09/2026 dit
 * pourquoi : `appendOutputTo` redirige la sortie par `>>`, ce qui verrouille le
 * fichier sous Windows. Monolog ne peut alors plus l'ouvrir et lève « Resource
 * temporarily unavailable » — au moment précis où la commande journalisait.
 * `portail-fne:importer-achats` se cassait ainsi lui-même.
 *
 * Les deux fichiers portent le même format de ligne : `sort` les remet dans
 * l'ordre quand il faut lire l'histoire complète.
 */
$sortiesPortail = \App\Modules\Admin\Services\ScraperPortailFneService::sorties();

// Re-synchronisation des écritures COMPTAFLOW échouées (toutes les 5 minutes)
Schedule::command('selflow:sync-ecritures')->everyFiveMinutes()->withoutOverlapping();

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
    $scraper = ProcessUtils::escapeArgument(config('selflow.portail_fne.scraper.node'))
        . ' ' . ProcessUtils::escapeArgument(config('selflow.portail_fne.scraper.script'));

    /*
     * Le passage qui sert la file, à la minute 40 : après le ramassage (:00) et
     * le rapprochement (:10), de sorte que ce qu'il dépose soit rangé à l'heure
     * suivante puis diagnostiqué dix minutes après.
     *
     * Toutes les heures et non toutes les dix minutes : quand la file est vide
     * — le cas ordinaire — le scraper s'arrête sans même ouvrir le navigateur,
     * mais quand elle ne l'est pas, il ouvre une session sur le portail de la
     * DGI. Y retourner six fois par heure avec un mot de passe éventuellement
     * faux est le meilleur moyen de faire bloquer le compte.
     *
     * `runInBackground` parce qu'un relevé prend des dizaines de secondes :
     * sans lui, la minute du planificateur reste occupée et tout le reste
     * attend derrière. Le verrou expire au bout de 30 minutes, sans quoi un
     * navigateur resté planté empêcherait tous les passages suivants.
     */
    Schedule::exec($scraper)
        ->hourlyAt(config('selflow.portail_fne.scraper.minute_horaire'))
        ->withoutOverlapping(30)
        ->runInBackground()
        ->appendOutputTo($sortiesPortail);

    /*
     * Le passage complet, une fois par nuit : tous les logins connus, qu'une
     * pièce ait été refusée ou non. La file dit ce qui est urgent, pas ce qui
     * est permis — et un relevé frais chaque matin fait que le premier rejet de
     * la journée se diagnostique tout de suite, au lieu d'attendre son tour.
     */
    Schedule::exec($scraper . ' --tous')
        ->dailyAt(config('selflow.portail_fne.scraper.heure_nocturne'))
        ->withoutOverlapping(120)
        ->runInBackground()
        ->appendOutputTo($sortiesPortail);

    /*
     * Le relevé des factures REÇUES, à son propre rythme.
     *
     * Demandé par le propriétaire du projet le 08/09/2026 : « que le scrapping
     * se lance chaque 5 min ». Il a eu un rendez-vous quotidien (04:15), retiré
     * au lot 22 parce qu'il rouvrait une seconde session pour une entreprise que
     * `fne.js` venait de relever. Il en retrouve un, beaucoup plus rapproché.
     *
     * **Ce que ça coûte, et qui est assumé** : `fne.js` regarde d'abord la file
     * de Selflow et s'arrête sans ouvrir de navigateur quand elle est vide —
     * le cas ordinaire. `achats.js` n'a rien qui le retienne : chaque passage
     * est une connexion au portail de la DGI avec le mot de passe du client,
     * soit 288 par jour à cinq minutes. C'est le prix d'une facture fournisseur
     * vue dans le quart d'heure au lieu du lendemain.
     *
     * D'où deux interrupteurs plutôt qu'un rythme écrit en dur :
     * `PORTAIL_FNE_SCRAPER_ACHATS_ACTIF` éteint la tâche sans toucher au reste
     * du scraper, `PORTAIL_FNE_SCRAPER_ACHATS_MINUTES` la ralentit. Le jour où
     * un compte se bloque, ils se changent sans livrer une version.
     *
     * `--tous` : tous les logins d'`identifiants.json`, sans regarder la file.
     * La file dit ce qu'un refus rend urgent ; une facture reçue n'y figure
     * jamais, personne ne l'ayant demandée.
     *
     * Le verrou expire au bout de deux pas : un navigateur resté planté ne doit
     * pas bloquer tous les passages suivants, et deux `achats.js` en parallèle
     * sur le même login déposeraient deux fois le même fichier.
     */
    if (config('selflow.portail_fne.scraper.achats_actif')) {
        $scraperAchats = ProcessUtils::escapeArgument(config('selflow.portail_fne.scraper.node'))
            . ' ' . ProcessUtils::escapeArgument(config('selflow.portail_fne.scraper.script_achats'));

        Schedule::exec($scraperAchats . ' --tous')
            ->cron("*/{$pasAchats} * * * *")
            ->withoutOverlapping($pasAchats * 2)
            ->runInBackground()
            ->appendOutputTo($sortiesPortail);
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

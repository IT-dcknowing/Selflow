<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PortailFneImport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Lancer le scraper du portail FNE sans attendre le passage horaire.
 *
 * Le fonctionnement ordinaire reste celui du planificateur : une demande de
 * relevé est mise en file, et le scraper la sert à la minute 40. Mais quand un
 * utilisateur vient de cliquer *Normaliser* et que la DGI refuse le point de
 * vente, le faire patienter jusqu'à l'heure suivante n'aurait aucun sens : on
 * lance la relève tout de suite, pour ce login, en arrière-plan.
 *
 * « En arrière-plan » veut dire **détaché** : la requête web se termine sans
 * l'attendre. Un relevé prend des dizaines de secondes — le scraper ouvre un
 * vrai navigateur sur le portail de la DGI — et bloquer la réponse HTTP là-
 * dessus figerait l'écran de l'utilisateur. Le processus vit donc sa vie ;
 * ce qu'il dépose sera rangé par `portail-fne:importer` puis rapproché par
 * `fne:diagnostiquer-rejets` au passage suivant, comme d'habitude.
 *
 * Selflow ne dépend pas de ce lancement : s'il échoue — Node absent, scraper
 * pas armé — la demande reste en file et le planificateur prendra le relais.
 * Aucune erreur ne doit donc remonter jusqu'à la normalisation qui l'a
 * déclenché.
 */
class ScraperPortailFneService
{
    /**
     * Lancer une relève de ce login, détachée, sans jamais lever d'exception.
     *
     * @return bool  vrai si le lancement a été tenté, faux s'il a été écarté
     *               (login vide, scraper éteint) ou qu'il a échoué.
     */
    public static function lancerPourLogin(?string $login): bool
    {
        $login = trim((string) $login);

        if ($login === '') {
            return false;
        }

        // Le même interrupteur que le planificateur : tant que le scraper n'est
        // pas armé (identifiants.json vide en production, dev éteint), on ne le
        // lance pas — la demande en file suffit, et un lancement voué à échouer
        // ne remplit que le journal.
        if (!config('selflow.portail_fne.scraper.actif')) {
            return false;
        }

        $node = (string) config('selflow.portail_fne.scraper.node');
        $script = (string) config('selflow.portail_fne.scraper.script');
        $journal = storage_path('logs/portail-fne.log');

        try {
            self::detacher([$node, $script, $login], $journal);

            Log::info("ScraperPortailFne : relève lancée en arrière-plan pour le login {$login}.");

            return true;
        } catch (\Throwable $e) {
            Log::error("ScraperPortailFne : lancement impossible pour le login {$login} — " . $e->getMessage());

            return false;
        }
    }

    /**
     * Relever le portail à l'ouverture de Selflow, si le dernier relevé date.
     *
     * Demandé par le propriétaire du projet le 31/08/2026, après avoir créé un
     * point de facturation au portail à 12 h 27 et constaté que Selflow ne le
     * voyait pas : le passage horaire regarde d'abord la file des demandes et
     * s'arrête sans ouvrir de navigateur quand elle est vide — le cas ordinaire
     * —, et le passage complet n'a lieu qu'à 02:30. Une modification faite au
     * portail dans la journée n'était donc visible que le lendemain matin.
     *
     * **Ce n'est pas un relevé par connexion.** Trois garde-fous, dans cet
     * ordre :
     *
     * 1. l'interrupteur `releve_a_la_connexion`, qui éteint tout ;
     * 2. un verrou de cache posé **avant** d'aller voir, et pour la durée de
     *    fraîcheur : dix employés qui se connectent à huit heures ne lancent
     *    qu'un seul relevé, et un relevé qui échoue ne se rejoue pas en boucle.
     *    `Cache::add()` et non `put` : c'est l'écriture atomique qui décide,
     *    pas la lecture qui la précède ;
     * 3. la fraîcheur elle-même : si le portail a été lu il y a moins de
     *    `fraicheur_heures`, on ne le rouvre pas. Une session sur le portail de
     *    la DGI se paie d'une connexion avec le mot de passe du client.
     *
     * Ne lève jamais : une ouverture de session ne doit pas échouer parce que
     * le scraper est mal armé.
     */
    public static function relancerSiLeReleveEstVieux(?Entreprise $entreprise): bool
    {
        if (!$entreprise || !config('selflow.portail_fne.scraper.releve_a_la_connexion')) {
            return false;
        }

        $login = trim((string) $entreprise->ncc);

        if ($login === '' || !config('selflow.portail_fne.scraper.actif')) {
            return false;
        }

        $heures = max(1, (int) config('selflow.portail_fne.scraper.fraicheur_heures', 12));

        // Le verrou d'abord : sans lui, deux connexions simultanées lanceraient
        // deux navigateurs sur le même portail.
        if (!Cache::add("portail_fne_releve_{$login}", true, now()->addHours($heures))) {
            return false;
        }

        try {
            $dernier = PortailFneImport::where('login', $login)->max('updated_at');

            // Le portail a été lu récemment : rien ne justifie d'y retourner.
            if ($dernier !== null && CarbonImmutable::parse($dernier)->gt(now()->subHours($heures))) {
                return false;
            }
        } catch (\Throwable $e) {
            Log::error('ScraperPortailFne : fraîcheur du relevé illisible — ' . $e->getMessage());

            return false;
        }

        Log::info("ScraperPortailFne : relève lancée à l'ouverture de Selflow pour le login {$login}.");

        return self::lancerPourLogin($login);
    }

    /**
     * Relever le portail dès qu'une pièce est refusée par la DGI.
     *
     * @param string|null $login
     * @return bool
     */
    public static function relancerApresRejet(?string $login): bool
    {
        $login = trim((string) $login);

        if ($login === '' || !config('selflow.portail_fne.scraper.actif')) {
            return false;
        }

        $minutes = max(1, (int) config('selflow.portail_fne.scraper.delai_apres_rejet_minutes', 2));

        if (!Cache::add("portail_fne_releve_rejet_{$login}", true, now()->addMinutes($minutes))) {
            return false;
        }

        Log::info("ScraperPortailFne : relève lancée après un refus de la DGI pour le login {$login}.");

        return self::lancerPourLogin($login);
    }

    /**
     * Lancer une commande en processus détaché, sa sortie versée au journal.
     *
     * @param  array<int, string>  $arguments  programme puis arguments
     */
    private static function detacher(array $arguments, string $journal): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {

            $commande = 'start /B "" ' . self::composer($arguments)
                . ' >> ' . self::citer($journal) . ' 2>&1';

            $tube = popen($commande, 'r');
        } else {
            // `nohup … &` détache sous Unix : le processus survit à la fin de
            // la requête, sa sortie va au journal.
            $commande = 'nohup ' . self::composer($arguments)
                . ' >> ' . self::citer($journal) . ' 2>&1 &';

            $tube = popen($commande, 'r');
        }

        if ($tube === false) {
            throw new \RuntimeException('popen a échoué.');
        }

        pclose($tube);
    }

    /**
     * Assembler une ligne de commande, chaque partie citée.
     *
     * @param  array<int, string>  $arguments
     */
    private static function composer(array $arguments): string
    {
        return implode(' ', array_map([self::class, 'citer'], $arguments));
    }

    /**
     * Citer un argument, guillemets internes doublés.
     *
     * Le PATH du poste — « C:/Program Files/nodejs/node.exe », « …/DCK OFFICE
     * MANAGER/… » — est plein d'espaces : sans guillemets, la commande se
     * couperait au premier.
     */
    private static function citer(string $valeur): string
    {
        return '"' . str_replace('"', '""', $valeur) . '"';
    }
}

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
     * Le préfixe du verrou du relevé des achats.
     *
     * Public parce que l'appelant a besoin de distinguer deux « faux » que
     * `lancerAchats()` rend indifféremment : « un relevé est déjà en route » —
     * qui est une réponse — et « le lancement a échoué » — qui est une panne.
     * Une route d'API qui répondrait la même chose aux deux ferait chercher une
     * panne là où il n'y a qu'un verrou qui fait son travail.
     */
    public const VERROU_ACHATS = 'portail_fne_releve_achats_';

    /** La clé du verrou pour ce login, ou pour le passage complet. */
    public static function verrouAchats(?string $login = null): string
    {
        return self::VERROU_ACHATS . (trim((string) $login) ?: 'tous');
    }

    /**
     * Le journal PHP du cycle du portail — service, import, appels d'API.
     *
     * Une seule définition, dans `config/logging.php`.
     */
    public static function journal(): string
    {
        return (string) config(
            'logging.channels.portail_fne.path',
            storage_path('logs/portail-fne.log')
        );
    }

    /**
     * Là où va ce que les **processus** impriment — Node, tâches planifiées.
     *
     * Un fichier à part, et l'essai du 08/09/2026 dit pourquoi : rediriger un
     * processus par `>>` verrouille le fichier sous Windows, et Monolog ne peut
     * plus l'ouvrir. Une commande qui journalise pendant que sa propre sortie
     * est redirigée au même endroit se casse elle-même.
     *
     * Les deux fichiers portent le même format de ligne — `[date] canal.NIVEAU:
     * message` —, de sorte qu'un `sort` les remette dans l'ordre.
     */
    public static function sorties(): string
    {
        return (string) config(
            'selflow.portail_fne.sorties',
            storage_path('logs/portail-fne-sorties.log')
        );
    }

    /**
     * Une ligne de journal qui ne peut pas casser ce qu'elle observe.
     *
     * Ce service est documenté « ne lève jamais » : une normalisation, une
     * ouverture de session ou un appel d'API ne doivent pas échouer parce que le
     * scraper est mal armé. Or le journal lui-même peut refuser d'écrire —
     * disque plein, fichier tenu par un autre processus, droits changés — et
     * Monolog lève alors une `UnexpectedValueException` qui traverse tout,
     * `catch` compris, puisqu'il est appelé **dans** le `catch`.
     *
     * La suite entière l'a montré le 08/09/2026 : neuf épreuves rendaient 500
     * parce que le fichier de journal était momentanément inouvrable. Le geste
     * échouait à cause de sa propre trace.
     *
     * On perd la ligne, jamais l'opération. C'est le bon sens de l'échange : un
     * journal sert à expliquer une panne, il n'a pas à en provoquer une.
     *
     * @param  array<string, mixed>  $contexte
     */
    private static function tracer(string $niveau, string $message, array $contexte = []): void
    {
        try {
            Log::channel('portail_fne')->{$niveau}($message, $contexte);
        } catch (\Throwable) {
            // Volontairement muet : signaler ici demanderait d'écrire quelque
            // part, et c'est précisément ce qui vient d'échouer.
        }
    }

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
            self::tracer('notice', 'fne[?] : rien lancé — aucun login fourni.');

            return false;
        }

        // Le même interrupteur que le planificateur : tant que le scraper n'est
        // pas armé (identifiants.json vide en production, dev éteint), on ne le
        // lance pas — la demande en file suffit, et un lancement voué à échouer
        // ne remplit que le journal.
        if (!config('selflow.portail_fne.scraper.actif')) {
            self::tracer('notice', "fne[{$login}] : rien lancé — le scraper est éteint (PORTAIL_FNE_SCRAPER_ACTIF).");

            return false;
        }

        $node = (string) config('selflow.portail_fne.scraper.node');
        $script = (string) config('selflow.portail_fne.scraper.script');

        try {
            self::detacher([$node, $script, $login], self::sorties());

            self::tracer('info', "fne[{$login}] : relève lancée en arrière-plan.", [
                'node'   => $node,
                'script' => $script,
            ]);

            return true;
        } catch (\Throwable $e) {
            self::tracer('error', "fne[{$login}] : lancement impossible — " . $e->getMessage(), [
                'node'   => $node,
                'script' => $script,
            ]);

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
            self::tracer('error', "fne[{$login}] : fraîcheur du relevé illisible — " . $e->getMessage());

            return false;
        }

        self::tracer('info', "fne[{$login}] : relève déclenchée par l'ouverture de Selflow (relevé de plus de {$heures} h).");

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

        self::tracer('info', "fne[{$login}] : relève déclenchée par un refus de la DGI (verrou de {$minutes} min).");

        return self::lancerPourLogin($login);
    }

    /**
     * Relever les factures REÇUES, à la demande, sans attendre le planificateur.
     *
     * C'est `achats.js` qui part, et non `fne.js` : le second relève la fiche,
     * les points de facturation *et* les factures reçues, ce qui est bien plus
     * de travail que ce qu'on demande ici. Le premier ouvre une session, lit
     * `/ws/invoices?listing=received`, dépose son fichier et rend la main.
     *
     * `$login` nul veut dire **tous les logins d'`identifiants.json`** — c'est
     * ce que le planificateur lance. Un login précis sert au cas où quelqu'un
     * veut le relevé d'une entreprise et de celle-là seule.
     *
     * ## Le verrou
     *
     * Il est posé pour la durée du pas du planificateur, et il est là pour
     * l'appel à la demande : une route d'API qu'on appelle en boucle ouvrirait
     * autant de navigateurs sur le portail de la DGI, chacun avec le mot de
     * passe d'un client. Le passage planifié, lui, n'en a pas besoin —
     * `withoutOverlapping()` le garde déjà, et les deux verrous ne se gênent
     * pas puisque le planificateur ne passe pas par ici.
     *
     * Ne lève jamais : le relevé des achats est un confort, et rien de ce qui
     * l'appelle ne doit échouer parce que Node manque sur le poste.
     *
     * @return bool  vrai si le lancement a été tenté, faux s'il a été écarté
     *               (scraper éteint, relevé déjà en route) ou qu'il a échoué.
     */
    public static function lancerAchats(?string $login = null): bool
    {
        $login = trim((string) $login);
        $cible = $login ?: 'tous les logins';

        // Les renoncements se journalisent au même titre que les lancements.
        // « Rien ne s'est passé » est la panne la plus difficile à diagnostiquer
        // quand rien ne l'explique : le premier réflexe est de chercher un défaut
        // dans le scraper, alors qu'un interrupteur est à zéro.
        if (!config('selflow.portail_fne.scraper.actif')) {
            self::tracer('notice', "achats[{$cible}] : rien lancé — le scraper est éteint (PORTAIL_FNE_SCRAPER_ACTIF).");

            return false;
        }

        if (!config('selflow.portail_fne.scraper.achats_actif')) {
            self::tracer('notice', "achats[{$cible}] : rien lancé — le relevé des achats est éteint (PORTAIL_FNE_SCRAPER_ACHATS_ACTIF).");

            return false;
        }

        $minutes = max(1, (int) config('selflow.portail_fne.scraper.achats_minutes', 5));

        // Le verrou d'abord, et par écriture atomique : deux appels simultanés
        // ne doivent pas ouvrir deux navigateurs sur le même portail.
        if (!Cache::add(self::verrouAchats($login), true, now()->addMinutes($minutes))) {
            self::tracer('info', "achats[{$cible}] : rien lancé — un relevé est déjà en route (verrou de {$minutes} min).");

            return false;
        }

        $node   = (string) config('selflow.portail_fne.scraper.node');
        $script = (string) config('selflow.portail_fne.scraper.script_achats');

        try {
            self::detacher([$node, $script, $login ?: '--tous'], self::sorties());

            // Le chemin de Node et celui du script sont dans le message, et non
            // seulement dans la configuration : les deux pannes les plus
            // fréquentes du poste sont un Node introuvable — la tâche planifiée
            // n'a pas le PATH d'un terminal — et un script déplacé. Le processus
            // est détaché : son échec n'arrive jamais jusqu'ici, et sans ces deux
            // chemins la ligne suivante du journal est un « fichier introuvable »
            // qui ne dit pas lequel.
            self::tracer('info', "achats[{$cible}] : relevé lancé en arrière-plan.", [
                'node'    => $node,
                'script'  => $script,
                'argument' => $login ?: '--tous',
                'verrou_minutes' => $minutes,
            ]);

            return true;
        } catch (\Throwable $e) {
            self::tracer('error', "achats[{$cible}] : lancement impossible — " . $e->getMessage(), [
                'node'   => $node,
                'script' => $script,
            ]);

            return false;
        }
    }

    /**
     * Lancer une commande en processus détaché, sa sortie versée au journal.
     *
     * @param  array<int, string>  $arguments  programme puis arguments
     */
    private static function detacher(array $arguments, string $journal): void
    {
        // Le programme doit exister AVANT d'être lancé. Sous Windows, `start`
        // sur un programme introuvable ouvre une fenêtre d'erreur système qui
        // attend un clic — et `pclose()` attend avec elle : la requête, ou la
        // suite d'épreuves, restait figée sans fin. C'est ce qui a bloqué la
        // suite deux fois le 15/09/2026. L'exception est rattrapée par
        // `lancerPourLogin()`, qui la journalise : la demande reste en file.
        $programme = (string) ($arguments[0] ?? '');
        $trouve = $programme !== ''
            && (is_file($programme) || (new \Symfony\Component\Process\ExecutableFinder())->find($programme) !== null);

        if (!$trouve) {
            throw new \RuntimeException("programme introuvable : « {$programme} ».");
        }

        /*
         * Refermer le journal AVANT d'engendrer l'enfant.
         *
         * Un processus lancé par `popen` hérite des descripteurs ouverts du
         * parent. Sous Windows, celui que Monolog tient sur le fichier de
         * journal part donc avec lui et **reste verrouillé tant que l'enfant
         * vit** — un scraper qui tourne trois minutes rend le journal de toute
         * l'application inouvrable pendant trois minutes, à un processus qui
         * n'écrit même pas dedans.
         *
         * Constaté le 08/09/2026 en jouant la suite entière : neuf appels d'API
         * rendaient 500 sur « Permission denied », puis six épreuves ne
         * trouvaient plus rien dans le journal une fois l'échec rendu muet.
         *
         * `forgetChannel` détruit l'instance, Monolog referme son flux, et le
         * prochain appel le rouvre. L'enfant n'hérite donc de rien.
         */
        Log::forgetChannel('portail_fne');

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

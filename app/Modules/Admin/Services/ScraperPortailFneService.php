<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneCredential;
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

    /**
     * Le préfixe du verrou du relevé des avoirs.
     */
    public const VERROU_AVOIRS = 'portail_fne_releve_avoirs_';
    public const VERROU_VENTES = 'portail_fne_releve_ventes_';

    private const DUREE_MAX_PROCESSUS_MINUTES = 20;


    /**
     * Crée un suivi de processus durable pendant toute la durée du scraper.
     * Le verrou n'expire pas au bout des cinq minutes de cadence du relevé :
     * le script Node dépose un petit fichier d'état à sa sortie, que le statut
     * HTTP peut lire même après un rechargement ou une fermeture de page.
     */
    private static function preparerSuivi(string $verrou, string $type, ?string $login, int $minutes): ?string
    {
        $dossier = storage_path('app/portail-fne/etat-processus');

        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException("Impossible de créer le dossier de suivi FNE : {$dossier}");
        }

        $identifiant = preg_replace('/[^A-Za-z0-9_-]/', '_', trim((string) $login) ?: 'tous');
        $fichierEtat = $dossier . DIRECTORY_SEPARATOR . $type . '_' . $identifiant . '_' . bin2hex(random_bytes(6)) . '.json';
        $ttl = max(self::DUREE_MAX_PROCESSUS_MINUTES, $minutes);

        $expire = now()->addMinutes($ttl);

        return Cache::add($verrou, [
            'fichier_etat' => $fichierEtat,
            'lance_a'      => now()->getTimestamp(),
            'expire_a'     => $expire->getTimestamp(),
        ], $expire)
            ? $fichierEtat
            : null;
    }

    /**
     * Noter dans le suivi le numéro du processus détaché.
     *
     * Sans lui, un scraper qui meurt avant d'écrire son marqueur de fin — un
     * module Node manquant, Chromium tué par l'hébergeur faute de mémoire —
     * restait « en cours » pendant les vingt minutes du verrou, et le journal
     * ne disait que « un relevé est déjà en route » toutes les cinq minutes.
     * Constaté en production le 23/11/2026 : aucun fichier de ventes déposé,
     * et rien dans laravel.log pour dire pourquoi.
     */
    private static function retenirProcessus(string $verrou, ?int $pid): void
    {
        $suivi = Cache::get($verrou);

        if ($pid === null || !is_array($suivi) || empty($suivi['expire_a'])) {
            return;
        }

        $suivi['pid'] = $pid;
        Cache::put($verrou, $suivi, CarbonImmutable::createFromTimestamp((int) $suivi['expire_a']));
    }

    /**
     * Vrai si le processus vit, faux s'il est mort, null si on ne sait pas
     * le dire (Windows, ou ni posix ni /proc).
     */
    private static function processusVivant(int $pid): ?bool
    {
        if ($pid <= 0) {
            return null;
        }

        if (is_dir('/proc/self')) {
            return is_dir('/proc/' . $pid);
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return null;
    }

    /**
     * Libérer le verrou d'un relevé terminé, et dire comment il s'est terminé.
     *
     * Le verrou n'était rendu que par l'écran qui interroge le statut : un
     * relevé lancé par le planificateur gardait donc son verrou jusqu'au bout
     * des vingt minutes, même fini depuis longtemps, et ses échecs n'étaient
     * écrits nulle part ailleurs que dans la sortie brute de Node.
     *
     * - **Réussi** : le verrou est rendu tout de suite, le relevé suivant part
     *   au pas prévu.
     * - **Échoué** : l'échec est écrit une fois, avec le fichier où lire la
     *   sortie du scraper, et le verrou est **gardé jusqu'à son terme**. Relancer
     *   toutes les cinq minutes un relevé qui échoue présenterait le mot de
     *   passe du client au portail de la DGI douze fois par heure.
     */
    private static function libererSiTermine(string $verrou, string $type, string $cible): void
    {
        $suivi = Cache::get($verrou);

        if (!is_array($suivi) || empty($suivi['fichier_etat'])) {
            return;
        }

        $etat = self::etatProcessus($verrou);

        if (!is_array($etat) || !array_key_exists('exit_code', $etat)) {
            return;
        }

        $code = (int) $etat['exit_code'];

        if ($code === 0) {
            Cache::forget($verrou);
            @unlink((string) $suivi['fichier_etat']);
            self::tracer('info', "{$type}[{$cible}] : relevé précédent terminé, verrou rendu.");

            return;
        }

        if (!empty($suivi['echec_signale'])) {
            return;
        }

        $suivi['echec_signale'] = true;
        Cache::put($verrou, $suivi, CarbonImmutable::createFromTimestamp((int) ($suivi['expire_a'] ?? now()->addMinutes(self::DUREE_MAX_PROCESSUS_MINUTES)->getTimestamp())));

        $cause = !empty($etat['interrompu'])
            ? "le processus s'est arrêté sans écrire son marqueur de fin (plantage au démarrage, module Node manquant, ou navigateur tué par l'hébergeur)"
            : "le scraper s'est terminé en erreur (code {$code})";

        self::tracer('error', "{$type}[{$cible}] : relevé échoué — {$cause}. Sortie du scraper : " . self::sorties($type) . '. Nouvel essai après ' . self::heureLibre($suivi) . '.');
    }

    /** L'heure à laquelle le verrou tombe, pour le journal. */
    private static function heureLibre(array $suivi): string
    {
        return empty($suivi['expire_a'])
            ? 'expiration du verrou'
            : CarbonImmutable::createFromTimestamp((int) $suivi['expire_a'])->setTimezone(config('app.timezone'))->format('H:i');
    }

    /** « un relevé est déjà en route », avec depuis quand, ou la pause après échec. */
    private static function motifVerrou(string $verrou): string
    {
        $suivi = Cache::get($verrou);

        if (!is_array($suivi)) {
            return 'un relevé est déjà en route.';
        }

        if (!empty($suivi['echec_signale'])) {
            return 'le relevé précédent a échoué, pause jusqu\'à ' . self::heureLibre($suivi) . '.';
        }

        if (!empty($suivi['import_en_cours'])) {
            return 'un relevé est déjà en route (import en cours).';
        }

        if (empty($suivi['lance_a'])) {
            return 'un relevé est déjà en route.';
        }

        $minutes = intdiv(max(0, now()->getTimestamp() - (int) $suivi['lance_a']), 60);

        return "un relevé est déjà en route (lancé il y a {$minutes} min).";
    }

    /**
     * null : aucun processus suivi ; en_cours : le scraper n'a pas encore
     * déposé son marqueur ; exit_code : le processus est terminé.
     *
     * @return array<string, mixed>|null
     */
    public static function etatProcessus(string $verrou): ?array
    {
        $suivi = Cache::get($verrou);

        if ($suivi === null) {
            return null;
        }

        if (!is_array($suivi) || empty($suivi['fichier_etat'])) {
            return ['en_cours' => true];
        }

        $fichierEtat = (string) $suivi['fichier_etat'];

        if (!is_file($fichierEtat)) {
            // Mort sans marqueur : on relit le fichier après avoir constaté la
            // mort, car le scraper peut l'écrire puis sortir entre les deux.
            if (!empty($suivi['pid']) && self::processusVivant((int) $suivi['pid']) === false) {
                clearstatcache(true, $fichierEtat);

                if (!is_file($fichierEtat)) {
                    return ['exit_code' => -1, 'interrompu' => true];
                }
            } else {
                return ['en_cours' => true];
            }
        }

        $etat = json_decode((string) @file_get_contents($fichierEtat), true);

        return is_array($etat) ? $etat : ['en_cours' => true];
    }

    /** La clé du verrou pour ce login, ou pour le passage complet. */
    public static function verrouAchats(?string $login = null): string
    {
        return self::VERROU_ACHATS . (trim((string) $login) ?: 'tous');
    }

    /** La clé du verrou des avoirs pour ce login, ou pour le passage complet. */
    public static function verrouAvoirs(?string $login = null): string
    {
        return self::VERROU_AVOIRS . (trim((string) $login) ?: 'tous');
    }

    /** Le verrou du relevé des factures de vente. */
    public static function verrouVentes(?string $login = null): string
    {
        return self::VERROU_VENTES . (trim((string) $login) ?: 'tous');
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
    public static function sorties(?string $type = null): string
    {
        $fichiers = [
            'achats' => storage_path('logs/portail-fne-achat.log'),
            'ventes' => storage_path('logs/portail-fne-vente.log'),
            'avoirs' => storage_path('logs/portail-fne-avoir.log'),
        ];

        if ($type !== null) {
            return $fichiers[$type] ?? storage_path('logs/portail-fne-sorties.log');
        }

        // Sur le serveur Linux, les sorties générales rejoignent laravel.log.
        // Sous Windows, garder un fichier distinct évite le verrouillage du
        // fichier par appendOutputTo pendant que Monolog écrit dedans.
        return PHP_OS_FAMILY === 'Windows'
            ? (string) config('selflow.portail_fne.sorties', storage_path('logs/portail-fne-sorties.log'))
            : storage_path('logs/laravel.log');
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
        $canal = match (true) {
            str_starts_with($message, 'achats[') => 'portail_fne_achat',
            str_starts_with($message, 'ventes[') => 'portail_fne_vente',
            str_starts_with($message, 'avoirs[') => 'portail_fne_avoir',
            default => 'single',
        };

        try {
            Log::channel($canal)->{$niveau}($message, $contexte);
        } catch (\Throwable) {
            // Une panne de journal ne doit jamais interrompre le traitement.
        }
    }

    /** NCC autorisés par le superadministrateur et disposant d'un mot de passe. */
    /**
     * La dernière connexion du robot au portail pour ce login (23/11/2026),
     * écrite par fne.js : statut ok | identifiants | portail | mot_de_passe |
     * autre, message, date, adresse du portail.
     */
    public static function etatConnexion(?string $login): ?array
    {
        $login = preg_replace('/[^A-Za-z0-9]/', '', (string) $login);
        if ($login === '') {
            return null;
        }
        $fichier = base_path('SCRAPER-PORTAIL-FNE/etat-connexion/' . $login . '.json');
        if (! is_file($fichier)) {
            return null;
        }
        $etat = json_decode((string) @file_get_contents($fichier), true);

        return is_array($etat) ? $etat + ['login' => $login] : null;
    }

    private static function loginsAutorises(): array
    {
        return FneCredential::query()
            ->where('telechargement_actif', true)
            ->whereNotNull('ncc_associe')
            ->whereNotNull('acces_mot_de_passe')
            ->pluck('ncc_associe')
            ->map(fn ($login) => strtoupper(trim((string) $login)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private static function loginAutorise(?string $login): bool
    {
        $login = strtoupper(trim((string) $login));

        return $login !== '' && FneCredential::query()
            ->where('telechargement_actif', true)
            ->whereRaw('UPPER(ncc_associe) = ?', [$login])
            ->whereNotNull('acces_mot_de_passe')
            ->exists();
    }

    /** Lancer le relevé de la fiche FNE uniquement pour les comptes autorisés. */
    public static function lancerPourTousLesLoginsAutorises(): bool
    {
        $logins = self::loginsAutorises();
        foreach ($logins as $login) {
            self::lancerPourLogin($login);
        }

        return $logins !== [];
    }

    /**
     * Lancer une relève de ce login, détachée, sans jamais lever d'exception.
     *
     * @return bool  vrai si le lancement a été tenté, faux s'il a été écarté
     *               (login vide, scraper éteint) ou qu'il a échoué.
     */
    /**
     * Reconstruire le magasin local du scraper depuis la source chiffrée en base.
     * Le fichier JSON n'est qu'un cache d'exécution : il ne doit jamais être
     * l'unique copie des identifiants. Chaque lancement le réconcilie avec la DB.
     */
    private static function synchroniserIdentifiants(string $login): bool
    {
        $credential = FneCredential::with('entreprise')
            ->where('telechargement_actif', true)
            ->whereRaw('UPPER(ncc_associe) = ?', [strtoupper(trim($login))])
            ->first();

        if (!$credential || blank($credential->acces_mot_de_passe)) {
            self::tracer('error', "fne[{$login}] : accès absent de la base chiffrée.");
            return false;
        }

        $ok = IdentifiantsScraperService::inscrire(
            $login,
            $credential->acces_mot_de_passe,
            $credential->entreprise?->nom
        );

        if (!$ok) {
            self::tracer('error', "fne[{$login}] : impossible de reconstruire identifiants.json depuis la base.");
        }

        return $ok;
    }

    public static function lancerPourLogin(?string $login): bool
    {
        $login = trim((string) $login);

        if ($login === '') {
            self::tracer('notice', 'fne[?] : rien lancé — aucun login fourni.');

            return false;
        }

        if (!self::loginAutorise($login)) {
            self::tracer('notice', "fne[{$login}] : rien lancé — téléchargement non autorisé par le superadministrateur.");
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

        if (!self::synchroniserIdentifiants($login)) {
            return false;
        }

        $node = (string) config('selflow.portail_fne.scraper.node');
        $script = (string) config('selflow.portail_fne.scraper.script');

        try {
            self::detacher([$node, $script, $login], self::sorties(), $login);

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

        if ($login === '' || !self::loginAutorise($login) || !config('selflow.portail_fne.scraper.actif')) {
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

        if ($login === '' || !self::loginAutorise($login) || !config('selflow.portail_fne.scraper.actif')) {
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
        if ($login === '') {
            $logins = self::loginsAutorises();
            foreach ($logins as $loginAutorise) self::lancerAchats($loginAutorise);
            return $logins !== [];
        }
        $cible = $login;
        if (!self::loginAutorise($login)) {
            self::tracer('notice', "achats[{$cible}] : rien lancé — téléchargement non autorisé par le superadministrateur.");
            return false;
        }

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

        if (!self::synchroniserIdentifiants($login)) {
            return false;
        }

        $minutes = max(1, (int) config('selflow.portail_fne.scraper.achats_minutes', 5));

        // Le verrou d'abord, et par écriture atomique : deux appels simultanés
        // ne doivent pas ouvrir deux navigateurs sur le même portail.
        $verrou = self::verrouAchats($login);
        self::libererSiTermine($verrou, 'achats', $cible);
        try {
            $fichierEtat = self::preparerSuivi($verrou, 'achats', $login, $minutes);
        } catch (\Throwable $e) {
            self::tracer('error', "achats[{$cible}] : impossible de préparer le suivi — " . $e->getMessage());
            return false;
        }
        if ($fichierEtat === null) {
            self::tracer('info', "achats[{$cible}] : rien lancé — " . self::motifVerrou($verrou));
            return false;
        }

        $node   = (string) config('selflow.portail_fne.scraper.node');
        $script = (string) config('selflow.portail_fne.scraper.script_achats');

        try {
            self::retenirProcessus($verrou, self::detacher([$node, $script, $login, '--status-file=' . $fichierEtat], self::sorties('achats'), $login));

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
                'verrou_minutes' => max(self::DUREE_MAX_PROCESSUS_MINUTES, $minutes),
            ]);

            return true;
        } catch (\Throwable $e) {
            Cache::forget($verrou);
            self::tracer('error', "achats[{$cible}] : lancement impossible — " . $e->getMessage(), [
                'node'   => $node,
                'script' => $script,
            ]);

            return false;
        }
    }

    /**
     * Relever les factures d'AVOIR (notes de crédit), à la demande, sans attendre le planificateur.
     *
     * C'est `avoirs.js` qui part : il ouvre une session sur le portail FNE,
     * interroge `/ws/invoices` pour les pièces portant `subtype=refund`
     * (avoirs clients émis et avoirs fournisseurs reçus), dépose son fichier
     * dans `storage/app/portail-fne/avoirs/` et rend la main.
     *
     * @return bool vrai si le lancement a été tenté, faux s'il a été écarté ou a échoué.
     */
    public static function lancerAvoirs(?string $login = null, string $listing = 'tous'): bool
    {
        $login = trim((string) $login);
        if ($login === '') {
            $logins = self::loginsAutorises();
            foreach ($logins as $loginAutorise) self::lancerAvoirs($loginAutorise, $listing);
            return $logins !== [];
        }
        $cible = $login;
        if (!self::loginAutorise($login)) {
            self::tracer('notice', "avoirs[{$cible}] : rien lancé — téléchargement non autorisé par le superadministrateur.");
            return false;
        }

        if (!in_array($listing, ['issued', 'received', 'tous'], true)) {
            self::tracer('warning', "avoirs[{$cible}] : listing invalide — {$listing}.");
            return false;
        }

        if (!config('selflow.portail_fne.scraper.actif')) {
            self::tracer('notice', "avoirs[{$cible}] : rien lancé — le scraper est éteint (PORTAIL_FNE_SCRAPER_ACTIF).");

            return false;
        }

        if (!config('selflow.portail_fne.scraper.avoirs_actif', true)) {
            self::tracer('notice', "avoirs[{$cible}] : rien lancé — le relevé des avoirs est éteint (PORTAIL_FNE_SCRAPER_AVOIRS_ACTIF).");

            return false;
        }

        if (!self::synchroniserIdentifiants($login)) {
            return false;
        }

        $minutes = max(1, (int) config('selflow.portail_fne.scraper.avoirs_minutes', 5));

        $verrou = self::verrouAvoirs($login);
        self::libererSiTermine($verrou, 'avoirs', $cible);
        try {
            $fichierEtat = self::preparerSuivi($verrou, 'avoirs', $login, $minutes);
        } catch (\Throwable $e) {
            self::tracer('error', "avoirs[{$cible}] : impossible de préparer le suivi — " . $e->getMessage());
            return false;
        }
        if ($fichierEtat === null) {
            self::tracer('info', "avoirs[{$cible}] : rien lancé — " . self::motifVerrou($verrou));
            return false;
        }

        $node   = (string) config('selflow.portail_fne.scraper.node');
        $script = (string) config('selflow.portail_fne.scraper.script_avoirs', base_path('SCRAPER-PORTAIL-FNE/avoirs.js'));

        try {
            $arguments = [$node, $script, $login ?: '--tous'];
            if ($listing !== 'tous') {
                $arguments[] = '--listing=' . $listing;
            }
            $arguments[] = '--status-file=' . $fichierEtat;
            self::retenirProcessus($verrou, self::detacher($arguments, self::sorties('avoirs'), $login));

            self::tracer('info', "avoirs[{$cible}] : relevé lancé en arrière-plan.", [
                'node'           => $node,
                'script'         => $script,
                'argument'       => $login ?: '--tous',
                'verrou_minutes' => max(self::DUREE_MAX_PROCESSUS_MINUTES, $minutes),
            ]);

            return true;
        } catch (\Throwable $e) {
            Cache::forget($verrou);
            self::tracer('error', "avoirs[{$cible}] : lancement impossible — " . $e->getMessage(), [
                'node'   => $node,
                'script' => $script,
            ]);

            return false;
        }
    }

    /**
     * Relever les factures de vente déjà émises et certifiées par la DGI.
     * Le scraper est distinct de celui des avoirs et ne normalise aucun document.
     */
    public static function lancerVentes(?string $login = null): bool
    {
        $login = trim((string) $login);
        if ($login === '') {
            $logins = self::loginsAutorises();
            foreach ($logins as $loginAutorise) self::lancerVentes($loginAutorise);
            return $logins !== [];
        }
        $cible = $login;
        if (!self::loginAutorise($login)) {
            self::tracer('notice', "ventes[{$cible}] : rien lancé — téléchargement non autorisé par le superadministrateur.");
            return false;
        }

        if (!config('selflow.portail_fne.scraper.actif')) {
            self::tracer('notice', "ventes[{$cible}] : rien lancé — le scraper FNE est éteint.");
            return false;
        }

        if (!config('selflow.portail_fne.scraper.ventes_actif', true)) {
            self::tracer('notice', "ventes[{$cible}] : rien lancé — le relevé des ventes est éteint.");
            return false;
        }

        if (!self::synchroniserIdentifiants($login)) {
            return false;
        }

        $minutes = max(1, (int) config('selflow.portail_fne.scraper.ventes_minutes', 5));
        $verrou = self::verrouVentes($login);
        self::libererSiTermine($verrou, 'ventes', $cible);
        try {
            $fichierEtat = self::preparerSuivi($verrou, 'ventes', $login, $minutes);
        } catch (\Throwable $e) {
            self::tracer('error', "ventes[{$cible}] : impossible de préparer le suivi — " . $e->getMessage());
            return false;
        }
        if ($fichierEtat === null) {
            self::tracer('info', "ventes[{$cible}] : rien lancé — " . self::motifVerrou($verrou));
            return false;
        }

        $node = (string) config('selflow.portail_fne.scraper.node');
        $script = (string) config('selflow.portail_fne.scraper.script_ventes', base_path('SCRAPER-PORTAIL-FNE/ventes.js'));

        try {
            // La fenêtre est imposée ici : une variable d'environnement
            // VENTES_DEPUIS ne doit jamais réduire le relevé demandé à l'année
            // civile en cours.
            $depuis = now()->startOfYear()->toDateString();
            self::retenirProcessus($verrou, self::detacher([$node, $script, $login, '--depuis=' . $depuis, '--status-file=' . $fichierEtat], self::sorties('ventes'), $login));
            self::tracer('info', "ventes[{$cible}] : relevé FNE lancé en arrière-plan.", [
                'node' => $node,
                'script' => $script,
                'argument' => $login ?: '--tous',
                'periode_depuis' => now()->startOfYear()->toDateString(),
            ]);
            return true;
        } catch (\Throwable $e) {
            Cache::forget($verrou);
            self::tracer('error', "ventes[{$cible}] : lancement impossible — " . $e->getMessage(), [
                'node' => $node,
                'script' => $script,
            ]);
            return false;
        }
    }

    /**
     * Lancer une commande en processus détaché, sa sortie versée au journal.
     *
     * @param  array<int, string>  $arguments  programme puis arguments
     * @return int|null  le numéro du processus sous Unix, null sous Windows
     */
    private static function detacher(array $arguments, string $journal, ?string $login = null): ?int
    {
        // L'adresse du portail suit l'accès FNE de l'entreprise : production
        // si validé, test sinon (23/11/2026). Elle passe par l'environnement
        // du processus, que `dotenv` ne réécrit pas.
        if ($login !== null && \PHP_OS_FAMILY !== 'Windows') {
            $adresse = AdressePortailFne::connexion(AdressePortailFne::pourLogin($login));
            if ($adresse !== '') {
                $arguments = array_merge(['env', 'FNE_URL=' . $adresse], $arguments);
            }
        }

        // Le programme doit exister AVANT d'être lancé. Sous Windows, `start`
        // sur un programme introuvable ouvre une fenêtre d'erreur système qui
        // attend un clic — et `pclose()` attend avec elle : la requête, ou la
        // suite d'épreuves, restait figée sans fin. C'est ce qui a bloqué la
        // suite deux fois le 15/09/2026. L'exception est rattrapée par
        // `lancerPourLogin()`, qui la journalise : la demande reste en file.
        // Derrière un éventuel `env FNE_URL=…`, c'est Node qui doit exister.
        $programme = (string) (collect($arguments)
            ->first(fn ($a, $i) => !($i === 0 && $a === 'env') && !preg_match('/^[A-Z_]+=/', (string) $a)) ?? '');
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
        foreach (['portail_fne', 'portail_fne_achat', 'portail_fne_vente', 'portail_fne_avoir', 'single'] as $canal) {
            Log::forgetChannel($canal);
        }

        if (\PHP_OS_FAMILY === 'Windows') {

            $commande = 'start /B "" ' . self::composer($arguments)
                . ' >> ' . self::citer($journal) . ' 2>&1';

            $tube = popen($commande, 'r');
        } else {
            // `nohup … &` détache sous Unix : le processus survit à la fin de
            // la requête, sa sortie va au journal.
            // `echo $!` rend le numéro du processus : c'est lui qui permet de
            // voir qu'un scraper est mort sans écrire son marqueur de fin.
            $commande = 'nohup ' . self::composer($arguments)
                . ' >> ' . self::citer($journal) . ' 2>&1 & echo $!';

            $tube = popen($commande, 'r');
        }

        if ($tube === false) {
            throw new \RuntimeException('popen a échoué.');
        }

        $sortie = \PHP_OS_FAMILY === 'Windows' ? '' : trim((string) fgets($tube));
        pclose($tube);

        return ctype_digit($sortie) ? (int) $sortie : null;
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

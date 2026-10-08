<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Services\ScraperPortailFneService;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Ce qui empêche le scraper du portail FNE de tourner sur ce serveur.
 *
 * Signalé par le propriétaire du projet le 15/09/2026 : en local, un refus
 * `pointOfSale` lance le scraper et le pop-up affiche la liste des points de
 * vente ; en ligne, il reste sur « Récupération des points de vente sur le
 * portail en cours... » et rien n'arrive.
 *
 * Le lancement est **détaché** : son échec ne remonte jamais jusqu'à l'écran,
 * et tout ce qu'il manque au serveur — Node, `node_modules`, Chromium et ses
 * bibliothèques, le `.env` et `identifiants.json` du scraper, qui ne sont pas
 * versionnés — se traduit par le même silence. Cette commande passe chaque
 * condition en revue et dit, pour celles qui manquent, quoi faire.
 *
 * **À lancer sous l'utilisateur du serveur web**, pas sous root :
 * `sudo -u www-data php artisan portail-fne:verifier-scraper`. C'est lui qui
 * lance le scraper au refus, avec son PATH et son dossier personnel — où
 * Playwright range Chromium.
 *
 * `--lancer=LOGIN` lance réellement un relevé, au premier plan, sortie à
 * l'écran. Cela ouvre une session sur le portail de la DGI avec le mot de passe
 * du client : l'option ne part que si on la demande.
 */
class VerifierScraperPortailFne extends Command
{
    protected $signature = 'portail-fne:verifier-scraper
                            {--lancer= : Lancer réellement un relevé pour ce login (NCC), au premier plan}';

    protected $description = 'Dit, point par point, ce qui empêche le scraper du portail FNE de tourner sur ce serveur';

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private array $constats = [];

    /** @var array<int, string> */
    private array $remedes = [];

    public function handle(): int
    {
        $node   = (string) config('selflow.portail_fne.scraper.node');
        $script = (string) config('selflow.portail_fne.scraper.script');
        $dossierScraper = dirname($script);

        $this->line('Utilisateur : <info>' . $this->utilisateur() . '</info>'
            . ' — doit être celui du serveur web (www-data, en général).');
        $this->newLine();

        $this->interrupteur();
        $this->popen();
        $nodeOk = $this->node($node);
        $this->script($script, 'Script FNE (fiches)');
        $this->script((string) config('selflow.portail_fne.scraper.script_achats'), 'Script achats');
        $this->script((string) config('selflow.portail_fne.scraper.script_avoirs'), 'Script avoirs');
        $playwrightOk = $this->playwright($dossierScraper);

        if ($nodeOk && $playwrightOk) {
            $this->chromium($node, $dossierScraper);
        }

        $this->urlDuPortail($dossierScraper);
        $this->identifiants($dossierScraper);
        $this->ecriture('Dossier d\'import', (string) config('selflow.portail_fne.dossier_import'), true);
        $this->ecriture('Journal des sorties', dirname(ScraperPortailFneService::sorties()), true);
        $this->ecriture('Captures d\'erreur', $dossierScraper . DIRECTORY_SEPARATOR . 'erreurs', false);
        $this->dernierReleve();

        $this->table(['Point', 'État', 'Détail'], $this->constats);

        $this->derniereSortie();

        if ($login = trim((string) $this->option('lancer'))) {
            return $this->lancer($node, $script, $login);
        }

        if ($this->remedes === []) {
            $this->info('Rien ne bloque le scraper sur ce serveur.');
            $this->line('Pour l\'éprouver sur le portail : php artisan portail-fne:verifier-scraper --lancer=<NCC>');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error(count($this->remedes) . ' point(s) bloquant(s) :');
        foreach ($this->remedes as $rang => $remede) {
            $this->line(($rang + 1) . '. ' . $remede);
        }
        $this->newLine();
        $this->line('Après toute modification du .env : php artisan config:cache');

        return self::FAILURE;
    }

    private function interrupteur(): void
    {
        if (config('selflow.portail_fne.scraper.actif')) {
            $this->ok('Interrupteur', 'PORTAIL_FNE_SCRAPER_ACTIF=true');

            return;
        }

        // Le cas le plus probable en ligne : `deploy-production.sh` recopie
        // `.env.production` sur `.env` à chaque livraison. Un interrupteur posé
        // à la main dans `.env` disparaît au déploiement suivant.
        $this->bloquant(
            'Interrupteur',
            'éteint — aucun relevé ne part, ni au refus ni au planificateur',
            'Poser PORTAIL_FNE_SCRAPER_ACTIF=true dans .env.production (le déploiement recopie ce fichier sur .env).'
        );
    }

    private function popen(): void
    {
        $interdites = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        if (function_exists('popen') && !in_array('popen', $interdites, true)) {
            // La configuration de PHP-FPM n'est pas celle de la ligne de
            // commande : on ne voit ici que la seconde.
            $this->ok('popen', 'autorisé en ligne de commande — vérifier aussi disable_functions de PHP-FPM');

            return;
        }

        $this->bloquant(
            'popen',
            'désactivé (disable_functions)',
            'Retirer popen de disable_functions dans le php.ini de PHP-FPM : c\'est lui qui détache le scraper.'
        );
    }

    private function node(string $node): bool
    {
        $absolu = str_contains($node, '/') || str_contains($node, '\\');

        try {
            $resultat = Process::timeout(15)->run([$node, '--version']);
            $version = trim($resultat->output());
        } catch (\Throwable) {
            $resultat = null;
            $version = '';
        }

        if (!$resultat || !$resultat->successful() || $version === '') {
            $this->bloquant(
                'Node',
                "« {$node} » introuvable",
                'Installer Node 18 ou plus, puis poser PORTAIL_FNE_NODE=<chemin absolu> (sortie de `which node`) dans .env.production.'
            );

            return false;
        }

        if (!$absolu) {
            // Trouvé ici, mais PHP-FPM n'a pas le PATH d'un terminal — surtout
            // quand Node vient de nvm, installé dans le dossier d'un utilisateur.
            $this->aVoir('Node', "{$version} par le PATH de ce terminal — poser PORTAIL_FNE_NODE=<sortie de `which node`>");

            return true;
        }

        $this->ok('Node', "{$version} ({$node})");

        return true;
    }

    private function script(string $script, string $label = 'Script'): void
    {
        if (is_file($script)) {
            $this->ok($label, $script);

            return;
        }

        $this->bloquant($label, "{$script} absent", "Déployer SCRAPER-PORTAIL-FNE/, ou corriger le chemin dans la configuration.");
    }

    private function playwright(string $dossier): bool
    {
        if (is_dir($dossier . '/node_modules/playwright')) {
            $this->ok('Dépendances', 'node_modules/playwright présent');

            return true;
        }

        // `npm ci` du déploiement tourne à la racine de Selflow : le dossier du
        // scraper a son propre package.json, et n'est jamais installé par lui.
        $this->bloquant(
            'Dépendances',
            'node_modules absent du dossier du scraper',
            "cd {$dossier} && npm ci"
        );

        return false;
    }

    private function chromium(string $node, string $dossier): void
    {
        $code = "const p=require('playwright').chromium.executablePath();"
            . "console.log(p);process.exit(require('fs').existsSync(p)?0:1)";

        try {
            $chemin = Process::path($dossier)->timeout(30)->run([$node, '-e', $code]);
        } catch (\Throwable $e) {
            $this->bloquant('Chromium', 'vérification impossible — ' . $e->getMessage(), 'Relancer la commande sous l\'utilisateur du serveur web.');

            return;
        }

        if (!$chemin->successful()) {
            $this->bloquant(
                'Chromium',
                'non installé pour cet utilisateur (' . trim($chemin->output()) . ')',
                "cd {$dossier} && npx playwright install chromium — sous l'utilisateur du serveur web, "
                    . 'puis, en root : npx playwright install-deps chromium'
            );

            return;
        }

        // Le binaire présent ne suffit pas : sans ses bibliothèques système, il
        // ne démarre pas, et c'est le défaut le plus courant sur un serveur neuf.
        try {
            $demarrage = Process::path($dossier)->timeout(60)->run([
                $node, '-e',
                "require('playwright').chromium.launch({headless:true})"
                    . ".then(b=>b.close()).then(()=>process.exit(0))"
                    . ".catch(e=>{console.error(e.message.split('\\n')[0]);process.exit(1)})",
            ]);
        } catch (\Throwable $e) {
            $this->bloquant('Chromium', 'démarrage impossible — ' . $e->getMessage(), "En root : cd {$dossier} && npx playwright install-deps chromium");

            return;
        }

        if (!$demarrage->successful()) {
            $this->bloquant(
                'Chromium',
                'installé mais ne démarre pas — ' . trim($demarrage->errorOutput() ?: $demarrage->output()),
                "En root : cd {$dossier} && npx playwright install-deps chromium"
            );

            return;
        }

        $this->ok('Chromium', 'démarre (' . trim($chemin->output()) . ')');
    }

    private function urlDuPortail(string $dossier): void
    {
        // Même ordre que `fne.js` : son propre .env d'abord, celui de Selflow ensuite.
        $url = $this->variable($dossier . '/.env', 'FNE_URL')
            ?? $this->variable(base_path('.env'), 'FNE_URL')
            ?? (getenv('FNE_URL') ?: null);

        if ($url) {
            $this->ok('FNE_URL', $url);

            return;
        }

        $this->bloquant(
            'FNE_URL',
            is_file($dossier . '/.env') ? 'absente du .env du scraper' : 'le .env du scraper n\'existe pas (non versionné)',
            "cp {$dossier}/.env.exemple {$dossier}/.env — FNE_URL y est déjà."
        );
    }

    private function identifiants(string $dossier): void
    {
        $fichier = $dossier . '/identifiants.json';
        $loginEnv = $this->variable($dossier . '/.env', 'LOGIN');
        $motDePasseEnv = $this->variable($dossier . '/.env', 'PASSWORD');

        $magasin = [];
        if (is_file($fichier)) {
            $magasin = json_decode((string) file_get_contents($fichier), true);

            if (!is_array($magasin)) {
                $this->bloquant('identifiants.json', 'illisible (JSON invalide)', "Corriger {$fichier}.");

                return;
            }
        } elseif (!$loginEnv) {
            $this->bloquant(
                'identifiants.json',
                'absent (non versionné)',
                "Recopier identifiants.json du poste de développement dans {$dossier}/ — jamais par git."
            );

            return;
        }

        try {
            $logins = Entreprise::whereNotNull('ncc')->where('ncc', '!=', '')->pluck('ncc')->map(fn ($n) => trim($n))->unique();
        } catch (\Throwable $e) {
            $this->aVoir('identifiants.json', 'entreprises illisibles — ' . $e->getMessage());

            return;
        }

        // On dit quel login n'a pas de mot de passe, jamais quel est le mot de passe.
        $sans = $logins->reject(function (string $login) use ($magasin, $loginEnv, $motDePasseEnv) {
            $entree = $magasin[$login] ?? null;

            return (is_string($entree) && $entree !== '')
                || (is_array($entree) && !empty($entree['motDePasse']))
                || ($loginEnv === $login && $motDePasseEnv);
        })->values();

        $avec = $logins->count() - $sans->count();

        if ($logins->isNotEmpty() && $avec === 0) {
            $this->bloquant(
                'identifiants.json',
                'aucune entreprise n\'a de mot de passe : ' . $sans->implode(', '),
                "Renseigner le mot de passe du portail de chaque NCC dans {$fichier}."
            );

            return;
        }

        if ($sans->isNotEmpty()) {
            $this->aVoir('identifiants.json', "{$avec} login(s) prêt(s) ; sans mot de passe : " . $sans->implode(', '));

            return;
        }

        $this->ok('identifiants.json', "{$avec} login(s) prêt(s)");
    }

    private function ecriture(string $point, string $dossier, bool $bloquant): void
    {
        if ($dossier === '') {
            $this->bloquant($point, 'non configuré', 'Renseigner le chemin dans .env.production.');

            return;
        }

        if (is_dir($dossier) ? is_writable($dossier) : is_writable(dirname($dossier))) {
            $this->ok($point, $dossier);

            return;
        }

        $remede = "mkdir -p {$dossier} && chown -R www-data:www-data {$dossier}";

        if ($bloquant) {
            $this->bloquant($point, "{$dossier} — pas d'écriture pour cet utilisateur", $remede);
        } else {
            $this->aVoir($point, "{$dossier} — pas d'écriture : {$remede}");
        }
    }

    private function dernierReleve(): void
    {
        try {
            $dernier = PortailFneImport::latest('updated_at')->first();
        } catch (\Throwable) {
            return;
        }

        $this->constats[] = [
            'Dernier relevé rangé',
            'info',
            $dernier ? $dernier->updated_at . ' — ' . $dernier->login : 'aucun, jamais',
        ];
    }

    /** Les dernières lignes que Node a imprimées : la cause réelle y est. */
    private function derniereSortie(): void
    {
        $fichier = ScraperPortailFneService::sorties();

        if (!is_file($fichier)) {
            $this->line("Aucune sortie du scraper n'a jamais été écrite ({$fichier}).");

            return;
        }

        $taille = filesize($fichier);
        $flux = fopen($fichier, 'r');
        fseek($flux, max(0, $taille - 6000));
        $lignes = array_slice(array_filter(explode("\n", (string) stream_get_contents($flux)), 'trim'), -15);
        fclose($flux);

        $this->line("Dernières sorties du scraper ({$fichier}) :");
        foreach ($lignes as $ligne) {
            $this->line('  ' . rtrim($ligne));
        }
        $this->newLine();
    }

    private function lancer(string $node, string $script, string $login): int
    {
        $this->newLine();
        $this->warn("Relevé réel pour {$login} — une session s'ouvre sur le portail de la DGI.");

        try {
            $resultat = Process::path(base_path())->timeout(300)->run(
                [$node, $script, $login],
                fn (string $type, string $sortie) => $this->output->write($sortie)
            );
        } catch (\Throwable $e) {
            $this->error('Lancement impossible : ' . $e->getMessage());

            return self::FAILURE;
        }

        if (!$resultat->successful()) {
            $this->error("Le scraper s'est arrêté en erreur (code {$resultat->exitCode()}).");

            return self::FAILURE;
        }

        $this->info('Relevé déposé. `php artisan portail-fne:importer` le range.');

        return self::SUCCESS;
    }

    private function variable(string $fichier, string $nom): ?string
    {
        if (!is_file($fichier)) {
            return null;
        }

        try {
            $valeur = Dotenv::parse((string) file_get_contents($fichier))[$nom] ?? null;
        } catch (\Throwable) {
            return null;
        }

        return $valeur !== null && $valeur !== '' ? $valeur : null;
    }

    private function utilisateur(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            return posix_getpwuid(posix_geteuid())['name'] ?? (string) posix_geteuid();
        }

        return get_current_user();
    }

    private function ok(string $point, string $detail): void
    {
        $this->constats[] = [$point, '<info>OK</info>', $detail];
    }

    private function aVoir(string $point, string $detail): void
    {
        $this->constats[] = [$point, '<comment>À VOIR</comment>', $detail];
    }

    private function bloquant(string $point, string $detail, string $remede): void
    {
        $this->constats[] = [$point, '<error>BLOQUANT</error>', $detail];
        $this->remedes[] = "{$point} : {$remede}";
    }
}

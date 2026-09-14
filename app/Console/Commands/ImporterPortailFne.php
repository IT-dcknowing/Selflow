<?php

namespace App\Console\Commands;

use App\Modules\Admin\Services\ImportPortailFneService;
use Illuminate\Console\Command;

/**
 * Recueille les relevés du portail FNE et les range en base.
 *
 * Usage :
 *   php artisan portail-fne:importer                       (le dossier configuré)
 *   php artisan portail-fne:importer --dossier=C:/…/k      (un autre dossier)
 *   php artisan portail-fne:importer --fichier=C:/…/x.json (un seul relevé)
 *
 * La commande est rejouable : un fichier déjà lu est reconnu à son empreinte et
 * passé. Rien n'est déplacé ni supprimé dans le dossier d'origine.
 */
class ImporterPortailFne extends Command
{
    protected $signature = 'portail-fne:importer
                            {--dossier= : Dossier à parcourir (défaut : selflow.portail_fne.dossier_import)}
                            {--fichier= : Un seul fichier à lire, au lieu du dossier}';

    protected $description = "Lit les relevés du portail FNE (<login>_<date>.json et .xlsx) et les enregistre.";

    public function handle(ImportPortailFneService $service): int
    {
        if ($fichier = $this->option('fichier')) {
            $resultat = $service->importerFichier($fichier);
            $this->afficherLignes([$resultat]);

            return $resultat['statut'] === 'erreur' ? self::FAILURE : self::SUCCESS;
        }

        $rapport = $service->importerDossier($this->option('dossier'));

        $this->line("Dossier : {$rapport['dossier']}");

        if ($rapport['details'] === []) {
            $this->warn('Aucun fichier .json ou .xlsx à lire.');

            return self::SUCCESS;
        }

        $this->afficherLignes($rapport['details']);

        $this->newLine();
        $this->line(sprintf(
            '%d importé(s), %d inchangé(s), %d déjà connu(s), %d en erreur.',
            $rapport['importes'],
            $rapport['inchanges'],
            $rapport['ignores'],
            $rapport['erreurs']
        ));

        return $rapport['erreurs'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     */
    private function afficherLignes(array $details): void
    {
        $this->table(
            ['Fichier', 'Statut', 'Lignes', 'Message'],
            array_map(fn (array $d) => [
                $d['fichier'],
                match ($d['statut']) {
                    'importe'  => '<info>importé</info>',
                    'inchange' => '<comment>inchangé</comment>',
                    'ignore'   => '<comment>déjà lu</comment>',
                    default    => '<error>erreur</error>',
                },
                $d['lignes'],
                $d['message'],
            ], $details)
        );
    }
}

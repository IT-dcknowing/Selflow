<?php

namespace App\Console\Commands;

use App\Modules\Admin\Services\ImportAvoirsService;
use Illuminate\Console\Command;

/**
 * Range les factures d'avoir relevées au portail FNE.
 *
 *   php artisan portail-fne:importer-avoirs
 *   php artisan portail-fne:importer-avoirs --dossier=C:/…/avoirs
 *   php artisan portail-fne:importer-avoirs --fichier=C:/…/1864699A_20260827.json
 *
 * Séparée de `portail-fne:importer` et `portail-fne:importer-achats` : les
 * trois chaînes lisent des dossiers différents et n'ont aucune raison de tomber
 * ensemble le jour où l'une d'elles casse.
 */
class ImporterAvoirs extends Command
{
    protected $signature = 'portail-fne:importer-avoirs
                            {--dossier= : Un autre dossier que celui configuré}
                            {--fichier= : Un seul relevé}';

    protected $description = 'Range les factures d\'avoir relevées au portail FNE.';

    public function handle(ImportAvoirsService $service): int
    {
        if ($fichier = $this->option('fichier')) {
            $resultat = $service->importerFichier($fichier);
            $this->afficherLignes([$resultat]);

            return $resultat['statut'] === 'erreur' ? self::FAILURE : self::SUCCESS;
        }

        $rapport = $service->importerDossier($this->option('dossier'));

        $this->line("Dossier : {$rapport['dossier']}");

        if ($rapport['details'] === []) {
            $this->line('Aucun relevé de factures d\'avoir à lire.');

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
            ['Fichier', 'Statut', 'Avoirs', 'Message'],
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

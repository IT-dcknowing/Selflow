<?php

namespace App\Console\Commands;

use App\Modules\Admin\Services\ImportFacturesRecuesService;
use Illuminate\Console\Command;

/**
 * Range les factures reçues relevées au portail FNE.
 *
 *   php artisan portail-fne:importer-achats
 *   php artisan portail-fne:importer-achats --dossier=C:/…/achats
 *   php artisan portail-fne:importer-achats --fichier=C:/…/1864699A_20260827.json
 *
 * Séparée de `portail-fne:importer`, qui range les fiches et les points : les
 * deux chaînes lisent des dossiers différents et n'ont aucune raison de tomber
 * ensemble le jour où l'une d'elles casse.
 */
class ImporterFacturesRecues extends Command
{
    protected $signature = 'portail-fne:importer-achats
                            {--dossier= : Un autre dossier que celui configuré}
                            {--fichier= : Un seul relevé}';

    protected $description = 'Range les factures reçues relevées au portail FNE.';

    public function handle(ImportFacturesRecuesService $service): int
    {
        if ($fichier = $this->option('fichier')) {
            $resultat = $service->importerFichier($fichier);
            $this->afficherLignes([$resultat]);

            return $resultat['statut'] === 'erreur' ? self::FAILURE : self::SUCCESS;
        }

        $rapport = $service->importerDossier($this->option('dossier'));

        $this->line("Dossier : {$rapport['dossier']}");

        if ($rapport['details'] === []) {
            // Silence volontaire plutôt qu'avertissement : tant que le scraper
            // d'achats n'a pas tourné, le dossier est vide et le dire chaque
            // heure ferait un journal qu'on cesse de lire.
            $this->line('Aucun relevé de factures reçues à lire.');

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
            ['Fichier', 'Statut', 'Factures', 'Message'],
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

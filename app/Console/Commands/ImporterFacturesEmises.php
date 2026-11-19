<?php

namespace App\Console\Commands;

use App\Modules\Admin\Services\ImportFacturesEmisesService;
use Illuminate\Console\Command;

/**
 * Range les factures de vente émises relevées au portail FNE.
 *
 *   php artisan portail-fne:importer-ventes
 *   php artisan portail-fne:importer-ventes --dossier=/chemin/vers/ventes
 *   php artisan portail-fne:importer-ventes --fichier=/chemin/vers/releve.json
 *
 * L'import est autonome : il continue même si l'utilisateur ferme la page
 * depuis laquelle le relevé des ventes a été déclenché.
 */
class ImporterFacturesEmises extends Command
{
    protected $signature = 'portail-fne:importer-ventes
                            {--dossier= : Un autre dossier que celui configuré}
                            {--fichier= : Un seul relevé}';

    protected $description = 'Range les factures de vente émises relevées au portail FNE.';

    public function handle(ImportFacturesEmisesService $service): int
    {
        if ($fichier = $this->option('fichier')) {
            $resultat = $service->importerFichier($fichier);
            $this->afficherLignes([$resultat]);

            return $resultat['statut'] === 'erreur' ? self::FAILURE : self::SUCCESS;
        }

        $rapport = $service->importerDossier($this->option('dossier'));

        $this->line("Dossier : {$rapport['dossier']}");

        if ($rapport['details'] === []) {
            $this->line('Aucun relevé de factures de vente à lire.');

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

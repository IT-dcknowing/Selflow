<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Admin\Services\EcritureFactureRecueService;
use Illuminate\Console\Command;

/**
 * Passer en écriture les factures reçues déjà relevées.
 *
 * La règle du 06/10/2026 — une facture fournisseur certifiée par la DGI passe
 * au journal des achats — vaut pour ce qui arrive. Les relevés antérieurs sont
 * en base sans écriture : sans cette commande, ils ne seraient passés qu'au
 * prochain dépôt du scraper, et seulement pour les pièces qu'il redépose.
 *
 * Sans danger à relancer : `operation_id` dit ce qui est déjà passé.
 */
class PasserFacturesRecuesEnEcriture extends Command
{
    protected $signature = 'selflow:ecritures-factures-recues {--entreprise= : Limiter à une entreprise}';

    protected $description = 'Passe en écriture les factures fournisseur reçues de la DGI qui ne le sont pas encore';

    public function handle(): int
    {
        $entreprises = PortailFneFactureRecue::query()
            ->whereNotNull('entreprise_id')
            ->when($this->option('entreprise'), fn ($q, $id) => $q->where('entreprise_id', $id))
            ->distinct()
            ->pluck('entreprise_id');

        foreach ($entreprises as $entrepriseId) {
            $bilan = EcritureFactureRecueService::pourEntreprise((int) $entrepriseId);
            $this->line("Entreprise {$entrepriseId} : {$bilan['passees']} passée(s), {$bilan['contre_passees']} contre-passée(s).");
        }

        return self::SUCCESS;
    }
}

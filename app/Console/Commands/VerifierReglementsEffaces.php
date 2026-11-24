<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\Vente;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repérer les règlements qu'une modification de vente a pu effacer dans une
 * AUTRE entreprise (24/11/2026). Ne modifie rien.
 *
 *     php artisan selflow:verifier-reglements-effaces
 *
 * Jusqu'au lot 170, modifier une vente supprimait les lignes de trésorerie
 * portant son numéro, toutes entreprises confondues. Le risque n'existe que
 * pour un numéro de facture porté par plusieurs entreprises. Pour chacun, la
 * commande montre, entreprise par entreprise : le TTC, ce qui est encaissé
 * chez elle, le statut, et si la vente a été modifiée. Un « payé » sans aucun
 * encaissement, dans un groupe où une vente a été modifiée, est un suspect.
 */
class VerifierReglementsEffaces extends Command
{
    protected $signature = 'selflow:verifier-reglements-effaces {--tout : afficher aussi les groupes sans suspect}';

    protected $description = 'Repère les règlements effacés par une modification de vente d\'une autre entreprise (lecture seule).';

    public function handle(): int
    {
        $partages = Vente::withoutGlobalScopes()
            ->join('points_de_vente', 'points_de_vente.id', '=', 'ventes.point_de_vente_id')
            ->whereNotNull('ventes.numero_facture')->where('ventes.numero_facture', '<>', '')
            ->groupBy('ventes.numero_facture')
            ->havingRaw('COUNT(DISTINCT points_de_vente.entreprise_id) > 1')
            ->pluck('ventes.numero_facture');

        $this->info($partages->count() . ' numéro(s) de facture portés par plusieurs entreprises.');

        $suspects = 0;
        $lignes = [];
        foreach ($partages->chunk(200) as $paquet) {
            $ventes = Vente::withoutGlobalScopes()
                ->join('points_de_vente', 'points_de_vente.id', '=', 'ventes.point_de_vente_id')
                ->join('entreprises', 'entreprises.id', '=', 'points_de_vente.entreprise_id')
                ->whereIn('ventes.numero_facture', $paquet)
                ->get(['ventes.id', 'ventes.numero_facture', 'ventes.statut', 'ventes.etape', 'ventes.mode_paiement',
                    'ventes.montant_ttc', 'ventes.created_at', 'ventes.updated_at',
                    'points_de_vente.entreprise_id', 'entreprises.nom as entreprise_nom'])
                ->groupBy('numero_facture');

            foreach ($ventes as $numero => $groupe) {
                $modifiee = $groupe->contains(fn ($v) => $v->updated_at && $v->created_at && $v->updated_at->gt($v->created_at->copy()->addMinutes(2)));
                $details = $groupe->map(function ($v) {
                    $encaisse = (float) TresorerieJournal::withoutGlobalScopes()->deLEntreprise((int) $v->entreprise_id)
                        ->where('reference_document', $v->numero_facture)->sum('montant_entree');
                    $paye = $v->etape === 'Facture' && in_array($v->statut, ['Payé', 'Payée', 'Avance'], true);

                    return [$v, $encaisse, $paye && $encaisse <= 0 && (float) $v->montant_ttc > 0];
                });
                $suspect = $modifiee && $details->contains(fn ($d) => $d[2]);
                $suspects += $suspect ? 1 : 0;

                if (!$suspect && !$this->option('tout')) {
                    continue;
                }
                foreach ($details as [$v, $encaisse, $manque]) {
                    $lignes[] = [
                        $numero, mb_strimwidth($v->entreprise_nom, 0, 28, '…'), $v->etape . ' / ' . $v->statut,
                        number_format((float) $v->montant_ttc, 0, ',', ' '), number_format($encaisse, 0, ',', ' '),
                        $v->updated_at?->format('d/m/Y H:i') . ($v->updated_at && $v->created_at && $v->updated_at->gt($v->created_at->copy()->addMinutes(2)) ? ' (modifiée)' : ''),
                        $manque ? ($suspect ? 'RÈGLEMENT MANQUANT ?' : 'payé sans encaissement') : '',
                    ];
                }
            }
        }

        if ($lignes) {
            $this->table(['Numéro', 'Entreprise', 'Étape / statut', 'TTC', 'Encaissé chez elle', 'Dernière modification', 'Constat'], $lignes);
        }
        $this->line($suspects === 0
            ? 'Aucun suspect : aucun règlement ne semble avoir été effacé par une modification de vente d\'une autre entreprise.'
            : "{$suspects} groupe(s) suspect(s) : vérifiez ces factures dans la trésorerie de l'entreprise concernée.");

        return self::SUCCESS;
    }
}

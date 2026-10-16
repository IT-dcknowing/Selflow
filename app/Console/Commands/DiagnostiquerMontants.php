<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\Vente;
use Illuminate\Console\Command;

/**
 * D'où viennent des montants qui paraissent trop élevés — retour du 16/10/2026.
 *
 * Lecture seule. Elle confronte, pour une entreprise, ce que disent les
 * factures, la trésorerie, les écritures et ce qui est parti chez Comptaflow,
 * et nomme les pièces qui ne s'accordent pas. Rien n'est modifié.
 *
 *   php artisan selflow:diagnostic-montants "DC-KNOWING CGA"
 */
class DiagnostiquerMontants extends Command
{
    protected $signature = 'selflow:diagnostic-montants {entreprise : identifiant ou nom}';

    protected $description = 'Confronte factures, trésorerie, écritures et Comptaflow, et nomme les écarts (lecture seule).';

    public function handle(): int
    {
        $cle = (string) $this->argument('entreprise');
        $entreprise = ctype_digit($cle)
            ? Entreprise::find((int) $cle)
            : Entreprise::where('nom', $cle)->first();

        if (!$entreprise) {
            $this->error("Entreprise introuvable : {$cle}");

            return self::FAILURE;
        }

        $f = fn ($n) => number_format((float) $n, 0, ',', ' ');
        $sites = $entreprise->pointsDeVente()->pluck('id');

        $pieces = Vente::withoutGlobalScopes()->with('details')
            ->whereIn('point_de_vente_id', $sites)
            ->where('etape', 'Facture')
            ->get();
        // Les avoirs se reconnaissent à `type_facture`, non à `type_piece`.
        $avoirs = $pieces->filter(fn ($v) => $v->type_facture === 'avoir');
        $actives = $pieces->reject(fn ($v) => $v->type_facture === 'avoir' || $v->statut === Vente::STATUT_ANNULEE);
        $du = fn (Vente $v) => (float) $v->montant_ttc + (float) $v->montant_autres_taxes + (float) $v->timbre_quittance;

        $this->info("══ {$entreprise->nom} (n° {$entreprise->id}) ══");

        // 1. Les factures
        $this->line("\n1. FACTURES DE VENTE : {$actives->count()} — TTC total " . $f($actives->sum('montant_ttc')) . ' F');
        $this->line('   Avoirs : ' . $avoirs->count() . ' — TTC total ' . $f($avoirs->sum('montant_ttc')) . ' F'
            . ' ; chiffre d\'affaires net ' . $f($actives->sum('montant_ttc') - $avoirs->sum('montant_ttc')) . ' F');
        $this->table(['Facture', 'Date', 'TTC', 'Lignes', 'Qté max', 'Prix max'],
            $actives->sortByDesc('montant_ttc')->take(10)->map(fn ($v) => [
                $v->numero_facture, optional($v->date_vente)->format('d/m/Y'), $f($v->montant_ttc),
                $v->details->count(), $f($v->details->max('quantite')), $f($v->details->max('prix_unitaire')),
            ])->values()->all());

        // 2. La trésorerie
        $tresorerie = TresorerieJournal::whereIn('point_de_vente_id', $sites)->get();
        $this->line("\n2. TRÉSORERIE : encaissements " . $f($tresorerie->sum('montant_entree'))
            . ' F, décaissements ' . $f($tresorerie->sum('montant_sortie')) . ' F');

        $numerosVentes = Vente::withoutGlobalScopes()->whereIn('point_de_vente_id', $sites)->pluck('numero_facture')->all();
        $numerosAchats = Achat::withoutGlobalScopes()->whereIn('point_de_vente_id', $sites)->pluck('numero_facture')->all();
        $connus = array_flip(array_filter(array_merge($numerosVentes, $numerosAchats)));

        $orphelins = $tresorerie->filter(fn ($t) => !isset($connus[(string) $t->reference_document]));
        $this->line('   Mouvements sans pièce correspondante : ' . $orphelins->count()
            . ' (entrées ' . $f($orphelins->sum('montant_entree')) . ' F, sorties ' . $f($orphelins->sum('montant_sortie')) . ' F)');
        if ($orphelins->isNotEmpty()) {
            $this->table(['Date', 'Référence', 'Libellé', 'Entrée', 'Sortie'],
                $orphelins->sortByDesc('montant_entree')->take(15)->map(fn ($t) => [
                    $t->date_operation, $t->reference_document ?: '—', mb_substr((string) $t->libelle, 0, 50),
                    $f($t->montant_entree), $f($t->montant_sortie),
                ])->values()->all());
        }

        $parPiece = $tresorerie->groupBy('reference_document');
        $tropEncaisse = $actives->map(function ($v) use ($parPiece, $du) {
            $mouvements = $parPiece->get($v->numero_facture, collect());

            return [
                'v' => $v, 'du' => $du($v),
                'encaisse' => (float) $mouvements->sum('montant_entree'),
                'n' => $mouvements->where('montant_entree', '>', 0)->count(),
            ];
        })->filter(fn ($l) => $l['encaisse'] > $l['du'] + 1);

        // Encaissements par pièce, les plus gros : d'où viennent les montants.
        $this->line('   Plus gros encaissements cumulés par pièce :');
        $this->table(['Pièce', 'Encaissé', 'Nb mouvements', 'Libellé du premier'],
            $parPiece->map(fn ($m, $ref) => [
                $ref ?: '—', (float) $m->sum('montant_entree'), $m->where('montant_entree', '>', 0)->count(),
                mb_substr((string) $m->first()->libelle, 0, 45),
            ])->sortByDesc(1)->take(12)->map(fn ($l) => [$l[0], $f($l[1]), $l[2], $l[3]])->values()->all());
        $this->line('   Factures encaissées au-delà de leur montant : ' . $tropEncaisse->count());
        if ($tropEncaisse->isNotEmpty()) {
            $this->table(['Facture', 'Dû', 'Encaissé', 'Nb encaissements'],
                $tropEncaisse->sortByDesc('encaisse')->take(15)->map(fn ($l) => [
                    $l['v']->numero_facture, $f($l['du']), $f($l['encaisse']), $l['n'],
                ])->values()->all());
        }

        // 3. Les écritures
        $ecritures = EcritureComptable::withoutGlobalScopes()->where('entreprise_id', $entreprise->id)->get();
        $this->line("\n3. ÉCRITURES : " . $ecritures->count() . ' lignes — débit ' . $f($ecritures->sum('debit'))
            . ' F, crédit ' . $f($ecritures->sum('credit')) . ' F');
        $this->line('   Opérations déséquilibrées : '
            . Operation::withoutGlobalScopes()->where('entreprise_id', $entreprise->id)->where('est_equilibree', false)->count());

        $parRef = $ecritures->groupBy('reference_document');
        $ecarts = $actives->map(function ($v) use ($parRef) {
            $lignes = $parRef->get($v->numero_facture, collect());
            $produits = $lignes->filter(fn ($e) => str_starts_with((string) $e->compte_credit, '7'))->sum('credit')
                - $lignes->filter(fn ($e) => str_starts_with((string) $e->compte_debit, '7'))->sum('debit');

            return ['v' => $v, 'comptabilise' => round($produits, 2), 'ht' => round((float) $v->montant_ht - (float) $v->remise, 2)];
        })->filter(fn ($l) => abs($l['comptabilise'] - $l['ht']) > 1);
        $this->line('   Factures dont le chiffre d\'affaires comptabilisé (classe 7) diffère de la facture : ' . $ecarts->count());
        if ($ecarts->isNotEmpty()) {
            $this->table(['Facture', 'HT facture', 'Classe 7 en compta'],
                $ecarts->take(20)->map(fn ($l) => [$l['v']->numero_facture, $f($l['ht']), $f($l['comptabilise'])])->values()->all());
        }

        // 4. Comptaflow
        $parStatut = $ecritures->groupBy(fn ($e) => $e->comptaflow_sync_status ?: 'jamais envoyée');
        $this->line("\n4. COMPTAFLOW :");
        foreach ($parStatut as $statut => $lignes) {
            $this->line("   {$statut} : {$lignes->count()} lignes, débit " . $f($lignes->sum('debit')) . ' F');
        }

        $this->line("\nComparez le débit « synced » au « Total débit » de la liste des écritures de Comptaflow :"
            . " égaux, la liaison ne multiplie rien ; c'est alors dans Selflow que les montants sont élevés.");

        return self::SUCCESS;
    }
}

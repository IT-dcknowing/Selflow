<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\AchatDetail;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Modeles\TresorerieJournal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corriger le prix unitaire d'UNE ligne d'un bon de commande fournisseur
 * (24/11/2026, O'SUPER PRIX : 154 100 F saisis au lieu de 1 541 F).
 *
 *     php artisan selflow:corriger-ligne-achat BCF-24-11-2026-0001 --entreprise="SUPER PRIX" --prix-actuel=154100 --nouveau-prix=1541
 *     ... --appliquer
 *
 * Sans `--appliquer`, rien n'est écrit : la commande montre la ligne visée et
 * les totaux avant/après. Seule la ligne visée change ; les totaux de la
 * pièce sont recalculés comme à la saisie (remise de ligne, TVA du produit,
 * remise globale). Une pièce déjà facturée, comptabilisée ou réglée est
 * refusée : elle se corrige par avoir, pas en base.
 */
class CorrigerLigneAchat extends Command
{
    protected $signature = 'selflow:corriger-ligne-achat
        {numero : numéro de la pièce (BCF-…)}
        {--entreprise= : identifiant ou partie du nom de l\'entreprise}
        {--prix-actuel= : prix unitaire erroné de la ligne}
        {--nouveau-prix= : prix unitaire juste}
        {--appliquer : écrire la correction}';

    protected $description = 'Corrige le prix unitaire d\'une ligne d\'un bon de commande fournisseur, et les totaux de la pièce.';

    public function handle(): int
    {
        $critere = (string) $this->option('entreprise');
        if ($critere === '' || $this->option('prix-actuel') === null || $this->option('nouveau-prix') === null) {
            $this->error('Indiquez --entreprise, --prix-actuel et --nouveau-prix.');

            return self::FAILURE;
        }

        $entreprises = ctype_digit($critere)
            ? Entreprise::whereKey((int) $critere)->get()
            : Entreprise::get()->filter(fn ($e) => str_contains(self::plat($e->nom), self::plat($critere)))->values();
        if ($entreprises->count() !== 1) {
            $this->error($entreprises->isEmpty() ? 'Aucune entreprise ne correspond.' : 'Plusieurs entreprises correspondent : ' . $entreprises->map(fn ($e) => "#{$e->id} {$e->nom}")->implode(', ') . '. Donnez l\'identifiant.');

            return self::FAILURE;
        }
        $entreprise = $entreprises->first();

        $achat = Achat::withoutGlobalScopes()->with('pointDeVente')
            ->where('numero_facture', $this->argument('numero'))
            ->whereHas('pointDeVente', fn ($q) => $q->withoutGlobalScopes()->where('entreprise_id', $entreprise->id))
            ->first();
        if (!$achat) {
            $this->error("Aucune pièce {$this->argument('numero')} chez {$entreprise->nom}.");

            return self::FAILURE;
        }

        if ($achat->etape === 'Facture') {
            $this->error("La pièce est facturée : elle se corrige par avoir, pas ici.");

            return self::FAILURE;
        }
        $comptabilisee = Operation::withoutGlobalScopes()->where('reference_document', $achat->numero_facture)
            ->where('entreprise_id', $entreprise->id)->exists();
        $reglee = TresorerieJournal::withoutGlobalScopes()->deLEntreprise($entreprise->id)
            ->where('reference_document', $achat->numero_facture)->exists();
        if ($comptabilisee || $reglee) {
            $this->error('La pièce a déjà une écriture ou un règlement : correction refusée.');

            return self::FAILURE;
        }

        $prixActuel = (float) $this->option('prix-actuel');
        $lignes = AchatDetail::where('achat_id', $achat->id)->with('produit')->get();
        $visees = $lignes->filter(fn ($l) => abs((float) $l->prix_unitaire - $prixActuel) < 0.005);
        if ($visees->count() !== 1) {
            $this->error($visees->isEmpty() ? "Aucune ligne au prix de {$prixActuel} F." : 'Plusieurs lignes à ce prix : correction refusée.');

            return self::FAILURE;
        }
        $ligne = $visees->first();
        $nouveauPrix = (float) $this->option('nouveau-prix');
        $estBapa = $achat->type_facture === 'bapa';

        $calculLigne = function (AchatDetail $l, float $pu) use ($estBapa): array {
            $ht = (float) $l->quantite * $pu * (1 - (float) $l->remise_taux / 100);
            $taux = (!$estBapa && $l->produit) ? (float) ($l->produit->taux_tva ?? 0) : 0.0;
            $tva = $taux > 0 ? round($ht * $taux / 100, 2) : 0.0;

            return [$ht, $tva];
        };

        $totalHt = 0.0;
        $totalTva = 0.0;
        foreach ($lignes as $l) {
            [$ht, $tva] = $calculLigne($l, $l->is($ligne) ? $nouveauPrix : (float) $l->prix_unitaire);
            $totalHt += $ht;
            $totalTva += $tva;
        }
        $remiseTaux = (float) ($achat->remise_taux ?? 0);
        $remise = round($totalHt * $remiseTaux / 100, 2);
        $htNet = max(0, $totalHt - $remise);
        $totalTva = round($totalTva * ($totalHt > 0 ? $htNet / $totalHt : 0), 2);
        $ttc = $htNet + $totalTva;
        [$htLigne, $tvaLigne] = $calculLigne($ligne, $nouveauPrix);

        $nom = $ligne->produit?->nom ?? $ligne->libelle_virtuel;
        $this->info("{$entreprise->nom} · {$achat->numero_facture} · site {$achat->pointDeVente?->nom} · {$achat->etape}");
        $this->table(['', 'Avant', 'Après'], [
            ['Ligne', "{$nom} × {$ligne->quantite} à " . self::f($ligne->prix_unitaire), "{$nom} × {$ligne->quantite} à " . self::f($nouveauPrix)],
            ['Total de la ligne', self::f($ligne->montant_ttc), self::f($htLigne + $tvaLigne)],
            ['HT de la pièce', self::f($achat->montant_ht), self::f($totalHt)],
            ['TVA de la pièce', self::f($achat->montant_tva), self::f($totalTva)],
            ['TTC de la pièce', self::f($achat->montant_ttc), self::f($ttc)],
        ]);

        if (!$this->option('appliquer')) {
            $this->warn('Rien n\'est écrit. Relancez avec --appliquer pour corriger.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($ligne, $nouveauPrix, $htLigne, $tvaLigne, $achat, $totalHt, $totalTva, $ttc, $remise) {
            $ligne->forceFill(['prix_unitaire' => $nouveauPrix, 'montant_tva' => $tvaLigne, 'montant_ttc' => $htLigne + $tvaLigne])->save();
            // Comme à la saisie : le HT avant remise globale, la remise à part.
            $achat->forceFill(['montant_ht' => $totalHt, 'montant_tva' => $totalTva, 'montant_ttc' => $ttc, 'remise' => $remise])->save();
        });

        \Illuminate\Support\Facades\Log::info('[correction] ligne de pièce d\'achat', [
            'achat_id' => $achat->id, 'numero' => $achat->numero_facture, 'ligne_id' => $ligne->id,
            'prix_avant' => $prixActuel, 'prix_apres' => $nouveauPrix,
        ]);
        $this->info('Corrigé.');

        return self::SUCCESS;
    }

    private static function f(mixed $v): string
    {
        return number_format((float) $v, 0, ',', ' ') . ' F';
    }

    private static function plat(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(\Illuminate\Support\Str::ascii($s))) ?? '';
    }
}

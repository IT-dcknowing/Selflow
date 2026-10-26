<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Scopes\PeriodeScope;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Recenser les pièces en double, et les numéros qui sautent (urgence du
 * 26/10/2026, resserrée le jour même après la première exécution en ligne).
 * Ne modifie rien.
 *
 * 1. **Même numéro** sur deux pièces de la même entreprise : la
 *    numérotation comptait les pièces sans verrou, et deux envois simultanés
 *    (un double clic) recevaient le même numéro.
 * 2. **Double envoi** : la même pièce enregistrée deux fois par le même
 *    utilisateur, sur le même site, pour le même tiers, avec **exactement les
 *    mêmes lignes** (article, quantité, prix) et à quelques secondes
 *    d'intervalle (10 s par défaut). La première version comparait le seul
 *    montant sur deux minutes : une poissonnerie vend dix fois l'heure le même
 *    article au même prix, et tout cela sortait en « doublon ».
 * 3. **Numéros manquants** : les trous de chaque séquence (par préfixe, site
 *    et jour — ou par année pour les devis, commandes et BL). Ils venaient du
 *    même défaut : deux pièces sous un numéro, et la suivante sautait d'un cran.
 *
 *   php artisan selflow:doublons-factures
 *   php artisan selflow:doublons-factures "poissonnerie EDEN" --secondes=5
 *
 * Les pièces **annulées** et les **avoirs** sont écartés des doublons : un
 * doublon déjà annulé est réglé, et l'avoir d'une annulation ressemble à la
 * facture qu'il annule. Un bilan final donne, par entreprise, ce qui reste à
 * traiter et ce qui l'est déjà.
 *
 * Une pièce normalisée ne se supprime pas : la DGI l'a certifiée, seul un
 * avoir la corrige. Les autres s'annulent depuis la liste des factures.
 */
class DoublonsFactures extends Command
{
    protected $signature = 'selflow:doublons-factures {entreprise? : identifiant ou nom ; toutes si absent}
                            {--secondes=10 : écart maximal entre deux enregistrements identiques}
                            {--sans-trous : ne pas lister les numéros manquants}';

    protected $description = 'Recense les factures en double (même numéro, double envoi) et les numéros manquants. Ne modifie rien.';

    public function handle(): int
    {
        $entreprises = $this->entreprises();
        $fenetre = max(1, (int) $this->option('secondes'));
        $total = 0;
        $bilan = [];

        foreach ($entreprises as $entreprise) {
            foreach ([Vente::class => 'Ventes', Achat::class => 'Achats'] as $classe => $libelle) {
                $pieces = $classe::withoutGlobalScope(PeriodeScope::class)
                    ->whereHas('pointDeVente', fn ($q) => $q->where('entreprise_id', $entreprise->id))
                    ->with('details')
                    ->orderBy('id')
                    ->get();

                // Une pièce annulée ne compte plus (26/10/2026) : son doublon
                // est déjà réglé. Un avoir non plus : celui d'une annulation
                // ressemble à la facture qu'il annule, et c'est voulu.
                $actives = $pieces->reject(fn ($p) => $p->statut === 'Annulée' || $p->type_facture === 'avoir')->values();

                $memeNumero = $actives->groupBy('numero_facture')->filter(fn (Collection $g) => $g->count() > 1);
                $doubleEnvoi = $this->doublesEnvois($actives, $fenetre, $classe === Vente::class ? 'client_id' : 'fournisseur_id');
                // Les numéros réglés : plusieurs pièces sous un numéro, dont
                // toutes sauf une au plus sont annulées.
                $regles = $pieces->groupBy('numero_facture')
                    ->filter(fn (Collection $g) => $g->count() > 1 && $g->reject(fn ($p) => $p->statut === 'Annulée')->count() <= 1)
                    ->count();
                // Les trous se lisent sur toutes les pièces : une pièce
                // annulée garde son numéro.
                $trous = $this->option('sans-trous') ? [] : $this->numerosManquants($pieces->pluck('numero_facture'));

                if ($memeNumero->isNotEmpty() || $doubleEnvoi !== [] || $regles > 0) {
                    $bilan[] = [$entreprise->nom . ' (#' . $entreprise->id . ')', $libelle, $memeNumero->count(), count($doubleEnvoi), $regles];
                }

                if ($memeNumero->isEmpty() && $doubleEnvoi === [] && $trous === []) {
                    continue;
                }

                $this->newLine();
                $this->info("{$entreprise->nom} (#{$entreprise->id}) — {$libelle}");

                if ($memeNumero->isNotEmpty()) {
                    $this->line('  Même numéro sur plusieurs pièces :');
                    $this->table(['Numéro', 'Pièces (id)', 'Étapes', 'Normalisées'], $memeNumero->map(fn ($g, $numero) => [
                        $numero,
                        $g->pluck('id')->implode(', '),
                        $g->pluck('etape')->unique()->implode(', '),
                        $g->where('normalise', true)->count() . ' / ' . $g->count(),
                    ])->values()->all());
                    $total += $memeNumero->count();
                }

                if ($doubleEnvoi !== []) {
                    $this->line("  Même pièce enregistrée deux fois — même vendeur, mêmes lignes, moins de {$fenetre} s d'écart :");
                    $this->table(['Première', 'Seconde', 'Montant TTC', 'Écart', 'Normalisée(s)'], array_map(fn ($p) => [
                        $p[0]->numero_facture . ' (#' . $p[0]->id . ')',
                        $p[1]->numero_facture . ' (#' . $p[1]->id . ')',
                        number_format((float) $p[1]->montant_ttc, 0, ',', ' ') . ' F',
                        $p[0]->created_at->diffInSeconds($p[1]->created_at) . ' s',
                        collect($p)->where('normalise', true)->pluck('numero_facture')->implode(', ') ?: 'aucune',
                    ], $doubleEnvoi));
                    $total += count($doubleEnvoi);
                }

                if ($trous !== []) {
                    $this->line('  Numéros manquants dans la séquence :');
                    $this->table(['Séquence', 'Manquants'], array_map(fn ($sequence, $manquants) => [
                        $sequence . '…',
                        count($manquants) > 12
                            ? implode(', ', array_slice($manquants, 0, 12)) . ' … (' . count($manquants) . ' au total)'
                            : implode(', ', $manquants),
                    ], array_keys($trous), $trous));
                }
            }
        }

        // Le bilan par entreprise : ce qui reste à traiter, et ce qui l'est déjà.
        if ($bilan !== []) {
            $this->newLine();
            $this->info('Bilan par entreprise');
            $this->table(['Entreprise', 'Pièces', 'Même numéro (à traiter)', 'Double envoi (à traiter)', 'Déjà réglés par annulation'], $bilan);
        }

        $this->newLine();
        $total === 0
            ? $this->info('Aucun doublon à traiter.')
            : $this->warn("{$total} doublon(s) à traiter. Les pièces annulées et les avoirs sont écartés. Rien n'a été modifié.");

        return self::SUCCESS;
    }

    /**
     * Les paires de pièces identiques enregistrées à quelques secondes
     * d'intervalle : même site, même tiers, même utilisateur, même étape, même
     * nature, même montant et **mêmes lignes** (article, libellé, quantité,
     * prix). Deux ventes d'un même article au même prix par deux clients
     * différents, ou à plusieurs minutes d'écart, ne sont pas des doublons.
     *
     * @return array<int, array{0: \Illuminate\Database\Eloquent\Model, 1: \Illuminate\Database\Eloquent\Model}>
     */
    private function doublesEnvois(Collection $pieces, int $fenetre, string $tiers): array
    {
        $paires = [];
        $groupes = $pieces->groupBy(fn ($p) => implode('|', [
            $p->point_de_vente_id, $p->$tiers ?? 0, $p->utilisateur_id ?? 0, $p->etape, $p->type_facture ?? '',
            number_format((float) $p->montant_ttc, 2, '.', ''),
            $p->details
                ->map(fn ($d) => implode(':', [
                    $d->produit_id ?? 0, mb_strtolower(trim((string) $d->libelle_virtuel)),
                    number_format((float) $d->quantite, 3, '.', ''), number_format((float) $d->prix_unitaire, 2, '.', ''),
                ]))
                ->sort()->implode(';'),
        ]));

        foreach ($groupes as $groupe) {
            $groupe = $groupe->sortBy('created_at')->values();
            for ($i = 1; $i < $groupe->count(); $i++) {
                $avant = $groupe[$i - 1];
                $apres = $groupe[$i];
                if ($avant->created_at && $apres->created_at
                    && abs($apres->created_at->getTimestamp() - $avant->created_at->getTimestamp()) <= $fenetre) {
                    $paires[] = [$avant, $apres];
                }
            }
        }

        return $paires;
    }

    /**
     * Les trous de chaque séquence de numéros.
     *
     * - `VTE-01-261026-028` : séquence `VTE-01-261026-`, remise à 1 chaque jour ;
     * - `BC-01-23-10-2026-0007` : séquence `BC-01-` de l'année 2026 — ces
     *   numéros portent la date du jour, mais le compteur court sur l'année.
     *
     * @param  Collection<int, string>  $numeros
     * @return array<string, array<int, string>>  séquence => numéros manquants
     */
    private function numerosManquants(Collection $numeros): array
    {
        $sequences = [];
        foreach ($numeros->filter()->unique() as $numero) {
            if (preg_match('/^(.*-)(\d{2}-\d{2}-(\d{4})-)(\d{4})$/', $numero, $m)) {
                $sequences[$m[1] . '…' . $m[3]][(int) $m[4]] = $numero;
            } elseif (preg_match('/^(.*-\d{6}-)(\d{3,})$/', $numero, $m)) {
                $sequences[$m[1]][(int) $m[2]] = $numero;
            }
        }

        $trous = [];
        foreach ($sequences as $sequence => $presents) {
            $manquants = [];
            for ($n = 1; $n < max(array_keys($presents)); $n++) {
                if (!isset($presents[$n])) {
                    $manquants[] = str_pad((string) $n, str_contains($sequence, '…') ? 4 : 3, '0', STR_PAD_LEFT);
                }
            }
            if ($manquants !== []) {
                $trous[$sequence] = $manquants;
            }
        }
        ksort($trous);

        return $trous;
    }

    /** @return Collection<int, Entreprise> */
    private function entreprises(): Collection
    {
        $cle = $this->argument('entreprise');

        return Entreprise::query()
            ->when($cle !== null, fn ($q) => ctype_digit((string) $cle)
                ? $q->whereKey((int) $cle)
                : $q->where('nom', $cle))
            ->orderBy('id')
            ->get();
    }
}

<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Scopes\PeriodeScope;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Recenser les pièces en double (urgence du 26/10/2026). Ne modifie rien.
 *
 * Deux sortes de doublons :
 *
 * 1. **le même numéro** porté par deux pièces de la même entreprise : la
 *    numérotation comptait les pièces sans verrou ;
 * 2. **la même vente enregistrée deux fois** : même site, même client, même
 *    montant, même nombre de lignes, à quelques secondes d'intervalle — un
 *    double clic ou un renvoi de page.
 *
 *   php artisan selflow:doublons-factures
 *   php artisan selflow:doublons-factures "poissonnerie EDEN" --secondes=180
 *
 * Une pièce normalisée ne se supprime pas : la DGI l'a certifiée, seul un
 * avoir la corrige. Les autres s'annulent depuis la liste des factures.
 */
class DoublonsFactures extends Command
{
    protected $signature = 'selflow:doublons-factures {entreprise? : identifiant ou nom ; toutes si absent}
                            {--secondes=120 : écart maximal entre deux enregistrements identiques}';

    protected $description = 'Recense les factures en double (même numéro, ou même vente enregistrée deux fois). Ne modifie rien.';

    public function handle(): int
    {
        $entreprises = $this->entreprises();
        $fenetre = max(1, (int) $this->option('secondes'));
        $total = 0;

        foreach ($entreprises as $entreprise) {
            foreach ([Vente::class => 'Ventes', Achat::class => 'Achats'] as $classe => $libelle) {
                $pieces = $classe::withoutGlobalScope(PeriodeScope::class)
                    ->whereHas('pointDeVente', fn ($q) => $q->where('entreprise_id', $entreprise->id))
                    ->withCount('details')
                    ->orderBy('id')
                    ->get();

                $memeNumero = $pieces->groupBy('numero_facture')->filter(fn (Collection $g) => $g->count() > 1);
                $doubleEnvoi = $this->doublesEnvois($pieces, $fenetre, $classe === Vente::class ? 'client_id' : 'fournisseur_id');

                if ($memeNumero->isEmpty() && $doubleEnvoi === []) {
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
                    $this->line("  Même pièce enregistrée deux fois (moins de {$fenetre} s d'écart) :");
                    $this->table(['Première', 'Seconde', 'Montant TTC', 'Écart', 'Normalisée(s)'], array_map(fn ($p) => [
                        $p[0]->numero_facture . ' (#' . $p[0]->id . ')',
                        $p[1]->numero_facture . ' (#' . $p[1]->id . ')',
                        number_format((float) $p[1]->montant_ttc, 0, ',', ' ') . ' F',
                        $p[0]->created_at->diffInSeconds($p[1]->created_at) . ' s',
                        collect($p)->where('normalise', true)->pluck('numero_facture')->implode(', ') ?: 'aucune',
                    ], $doubleEnvoi));
                    $total += count($doubleEnvoi);
                }
            }
        }

        $this->newLine();
        $total === 0
            ? $this->info('Aucun doublon trouvé.')
            : $this->warn("{$total} doublon(s) à examiner. Rien n'a été modifié.");

        return self::SUCCESS;
    }

    /**
     * Les paires de pièces identiques enregistrées à quelques secondes
     * d'intervalle : même site, même tiers, même étape, même nature, même
     * montant, même nombre de lignes.
     *
     * @return array<int, array{0: \Illuminate\Database\Eloquent\Model, 1: \Illuminate\Database\Eloquent\Model}>
     */
    private function doublesEnvois(Collection $pieces, int $fenetre, string $tiers): array
    {
        $paires = [];
        $groupes = $pieces->groupBy(fn ($p) => implode('|', [
            $p->point_de_vente_id, $p->$tiers ?? 0, $p->etape, $p->type_facture ?? '',
            number_format((float) $p->montant_ttc, 2, '.', ''), $p->details_count,
        ]));

        foreach ($groupes as $groupe) {
            $groupe = $groupe->sortBy('created_at')->values();
            for ($i = 1; $i < $groupe->count(); $i++) {
                $avant = $groupe[$i - 1];
                $apres = $groupe[$i];
                if ($avant->created_at && $apres->created_at
                    && $avant->created_at->diffInSeconds($apres->created_at) <= $fenetre) {
                    $paires[] = [$avant, $apres];
                }
            }
        }

        return $paires;
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

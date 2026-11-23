<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Console\Command;

/**
 * Où est le stock d'un article, site par site (23/11/2026).
 *
 *     php artisan selflow:diagnostic-stock "GABI KC 22KG"
 *
 * Pour chaque site de l'entreprise : la quantité de la fiche de stock, les
 * dix derniers mouvements, et qui travaille sur ce site.
 */
class DiagnosticStock extends Command
{
    protected $signature = 'selflow:diagnostic-stock {article : nom ou référence (une partie suffit)} {--entreprise= : identifiant de l\'entreprise}';

    protected $description = 'Montre, site par site, le stock d\'un article, ses derniers mouvements et les utilisateurs du site.';

    public function handle(): int
    {
        $produits = Produit::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('nom', 'like', '%' . $this->argument('article') . '%')->orWhere('reference', 'like', '%' . $this->argument('article') . '%'))
            ->when($this->option('entreprise'), fn ($q, $id) => $q->where('entreprise_id', $id))
            ->limit(5)->get();

        if ($produits->isEmpty()) {
            $this->error('Aucun article trouvé.');

            return self::FAILURE;
        }

        foreach ($produits as $produit) {
            $this->newLine();
            $this->info("Article #{$produit->id} « {$produit->nom} » ({$produit->reference}) · entreprise #{$produit->entreprise_id} · type {$produit->type}");

            $sites = PointDeVente::where('entreprise_id', $produit->entreprise_id)->orderBy('nom')->get(['id', 'nom']);
            $lignes = $sites->map(function ($site) use ($produit) {
                $fiche = Stock::where('produit_id', $produit->id)->where('point_de_vente_id', $site->id)->first();
                $somme = (float) MouvementStock::withoutGlobalScopes()->where('produit_id', $produit->id)->where('point_de_vente_id', $site->id)
                    ->selectRaw("SUM(CASE WHEN type_mouvement IN ('entree','ENTREE','Entrée','entrée') THEN quantite ELSE 0 END) - SUM(CASE WHEN type_mouvement IN ('sortie','SORTIE','Sortie') THEN quantite ELSE 0 END) as net")
                    ->value('net');
                $equipe = Utilisateur::where('point_de_vente_id', $site->id)->get(['nom', 'prenom', 'role'])
                    ->map(fn ($u) => trim($u->prenom . ' ' . $u->nom) . ' (' . $u->role . ')')->implode(', ');

                return ["#{$site->id} {$site->nom}", $fiche ? (string) (float) $fiche->quantite_disponible : 'pas de fiche', (string) $somme, $equipe ?: ''];
            });
            $orphelines = Stock::where('produit_id', $produit->id)->whereNotIn('point_de_vente_id', $sites->pluck('id'))->get();
            foreach ($orphelines as $o) {
                $lignes->push(['site inconnu #' . ($o->point_de_vente_id ?? 'NULL'), (string) (float) $o->quantite_disponible, '', '']);
            }
            $this->table(['Site', 'Stock (fiche)', 'Entrées − sorties', 'Utilisateurs affectés'], $lignes->all());

            $this->line('Utilisateurs de l\'entreprise (le site où ils vendent) :');
            $this->table(['Utilisateur', 'Rôle', 'Site affecté', 'Site actif mémorisé'],
                Utilisateur::where('entreprise_id', $produit->entreprise_id)->get()
                    ->map(fn ($u) => [trim($u->prenom . ' ' . $u->nom) . ' <' . $u->email . '>', $u->role,
                        $u->point_de_vente_id ? '#' . $u->point_de_vente_id . ($sites->firstWhere('id', $u->point_de_vente_id) ? '' : ' (pas un site de l\'entreprise !)') : 'aucun',
                        (string) ($u->point_de_vente_actif_id ?? '')])->all());

            $this->line('Dix derniers mouvements :');
            $this->table(['Date', 'Site', 'Type', 'Quantité', 'Avant → après', 'Pièce'],
                MouvementStock::withoutGlobalScopes()->where('produit_id', $produit->id)->latest('id')->limit(10)->get()
                    ->map(fn ($m) => [(string) $m->created_at, (string) $m->point_de_vente_id, $m->type_mouvement . ($m->sous_type ? '/' . $m->sous_type : ''),
                        (string) (float) $m->quantite, (float) $m->stock_avant . ' → ' . (float) $m->stock_apres, (string) $m->reference_document])->all());
        }

        return self::SUCCESS;
    }
}

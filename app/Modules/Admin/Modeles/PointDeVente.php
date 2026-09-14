<?php

namespace App\Modules\Admin\Modeles;

use App\Modules\Admin\Modeles\Concerns\IdentifiantOpaque;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PointDeVente extends Model
{
    use IdentifiantOpaque;

    protected $table = 'points_de_vente';

    protected $fillable = [
        'entreprise_id',
        'nom',
        'ville',
        'commune',
        'responsable',
        'telephone',
        'statut',

        // D'où vient ce point de vente, quand il vient d'un relevé du portail
        // FNE. Ni l'un ni l'autre ne part à la DGI : ils servent à reconnaître
        // le même point au relevé suivant, même renommé.
        //
        // L'établissement seul ne suffit pas — le portail le donne identique à
        // tous les points d'un même établissement (relevé réel du 31/08/2026).
        // C'est la paire qui identifie.
        'etablissement_fne_id',
        'point_fne_cree_a',
    ];

    protected function casts(): array
    {
        return [
            // Lue comme une date, elle se compare à celle du relevé sans passer
            // par le format qu'un tableur a bien voulu écrire.
            'point_fne_cree_a' => 'datetime',
        ];
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_id');
    }

    public function ventes(): HasMany
    {
        return $this->hasMany(Vente::class, 'point_de_vente_id');
    }

    public function achats(): HasMany
    {
        return $this->hasMany(Achat::class, 'point_de_vente_id');
    }

    public function mouvementsStock(): HasMany
    {
        return $this->hasMany(MouvementStock::class, 'point_de_vente_id');
    }

    public function tresorerieJournal(): HasMany
    {
        return $this->hasMany(TresorerieJournal::class, 'point_de_vente_id');
    }

    public function utilisateurs(): HasMany
    {
        return $this->hasMany(\App\Modules\Authentification\Modeles\Utilisateur::class, 'point_de_vente_id');
    }

    /**
     * Ouvre une fiche de stock vide, sur ce site, pour chaque article qui se
     * compte.
     *
     * Vivait dans `PointDeVenteControleur::creer()`. Un second endroit crée
     * désormais des points de vente — l'import des points déclarés au portail
     * FNE —, et un site né sans fiches se serait comporté autrement qu'un site
     * créé à la main, sans que rien ne le dise. Le journal garde le précédent
     * de la liste des modules socle qui vivait en double et avait perdu
     * `points_de_vente` des deux côtés.
     *
     * Un service n'a ni quantité disponible, ni seuil, ni rupture ; un article
     * archivé ne se vendra jamais sur un site qui vient d'ouvrir.
     */
    public function initialiserLesFichesDeStock(): void
    {
        $articles = Produit::where('entreprise_id', $this->entreprise_id)
            ->selectionnables()
            ->get();

        foreach ($articles as $article) {
            if (!$article->estStockable()) {
                continue;
            }

            Stock::firstOrCreate([
                'produit_id'        => $article->id,
                'point_de_vente_id' => $this->id,
            ], [
                'quantite_disponible' => 0,
                'stock_minimum'       => 5,
                'stock_maximum'       => 100,
            ]);
        }
    }
}

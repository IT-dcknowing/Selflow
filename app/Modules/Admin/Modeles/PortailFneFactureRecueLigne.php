<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne d'une facture reçue, telle que le portail la rend.
 *
 * Elle existe pour une raison précise : sans le détail des articles, une facture
 * reçue ne peut pas devenir un achat exploitable — ni stock mouvementé, ni coût
 * de revient. Le portail les rend dans `items[]`, on les range.
 *
 * Les taxes de la ligne sont conservées **brutes**. Le portail les rend dans un
 * tableau dont rien ne garantit qu'il parle le langage de `Produit::CODES_TVA` ;
 * deviner un code de Selflow à partir d'un montant reviendrait à inventer une
 * information fiscale.
 */
class PortailFneFactureRecueLigne extends Model
{
    protected $table = 'portail_fne_facture_recue_lignes';

    protected $fillable = [
        'facture_recue_id',
        'fne_item_id',
        'reference_article',
        'designation',
        'quantite',
        'unite',
        'prix_unitaire',
        'remise',
        'montant_tva',
        'taxes',
        'contenu_brut',
    ];

    protected function casts(): array
    {
        return [
            'quantite'      => 'decimal:3',
            'prix_unitaire' => 'decimal:2',
            'remise'        => 'decimal:2',
            'montant_tva'   => 'decimal:2',
            'taxes'         => 'array',
            'contenu_brut'  => 'array',
        ];
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(PortailFneFactureRecue::class, 'facture_recue_id');
    }

    /**
     * Le montant hors taxes de la ligne, remise déduite.
     *
     * Calculé et non relevé : le portail rend un prix unitaire et une quantité,
     * pas un total de ligne. Le déduire ici évite que chaque écran refasse la
     * multiplication à sa façon.
     */
    public function montantHt(): float
    {
        return round((float) $this->quantite * (float) $this->prix_unitaire - (float) $this->remise, 2);
    }
}

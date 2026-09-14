<?php

namespace App\Modules\Admin\Modeles;

use App\Modules\Admin\Modeles\Concerns\IdentifiantOpaque;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Achat extends Model
{
    use IdentifiantOpaque;

    protected $table = 'achats';

    protected $fillable = [
        'point_de_vente_id',
        'utilisateur_id',
        'fournisseur_id',
        'numero_facture',
        'numero_facture_fournisseur', // N° facture du fournisseur externe (saisie manuelle ou FNE DGI)
        'date_achat',
        'mode_paiement',
        'moyen_bancaire',
        'reference_paiement',
        'montant_ht',
        'montant_tva',
        'montant_ttc',
        // `montant_autres_taxes` a été retiré — décision du propriétaire, 24/08/2026.
        // La colonne existait ici depuis la vente, par symétrie. Mais la symétrie
        // ne tient pas : à la vente, une taxe additionnelle est **collectée pour
        // l'État**, donc une dette au 447000 ; à l'achat, une taxe supportée est
        // une **charge**, et son compte dépend de sa nature — droit
        // d'enregistrement, taxe non récupérable, redevance. Aucun écran ne
        // l'alimentait, aucune écriture ne la lisait : elle promettait ce qu'elle
        // ne faisait pas. Le jour où le besoin se présentera, il faudra d'abord
        // savoir quel compte de charge retenir, et le poser dans la configuration.
        'remise',          // montant de la remise globale, en francs
        'remise_taux',     // taux de la remise globale, en % → champ `discount` FNE
        'est_rne',         // → champ `isRne` FNE
        'numero_rne',      // → champ `rne` FNE
        'pied_de_page',    // → champ `footer` FNE
        'autres_mentions', // → champ `commercialMessage` FNE
        'statut',
        'etape',
        'normalise',
        'numero_fne',
        'signature_dgi',
        'qr_code_data',
        'fichier_fne_pdf_url',
        // Donnees renvoyees par la plateforme FNE lors de la certification
        'fne_alerte_stickers',
        'fne_montant_ttc',
        'fne_montant_tva',
        'fne_timbre_fiscal',
        'fne_certifie_at',
        'type_facture',
        'archived',
        'parent_id',
        'raison_avoir',
        'devise',
        'taux_change',
        'mobile_money_operateur',
    ];



    protected static function booted()
    {
        static::addGlobalScope(new \App\Modules\Admin\Scopes\PeriodeScope('date_achat'));

        static::creating(function ($model) {
            if (auth()->check()) {
                $model->utilisateur_id = auth()->id();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'date_achat'  => 'date',
            'montant_ht'  => 'decimal:2',
            'montant_tva' => 'decimal:2',
            'montant_ttc' => 'decimal:2',
            'remise'      => 'decimal:2',
            'remise_taux' => 'decimal:2',
            'est_rne'     => 'boolean',
            'fne_alerte_stickers' => 'boolean',
            'fne_montant_ttc'     => 'decimal:2',
            'fne_montant_tva'     => 'decimal:2',
            'fne_timbre_fiscal'   => 'decimal:2',
            'fne_certifie_at'     => 'datetime',
            'archived'    => 'boolean',
        ];
    }

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class, 'point_de_vente_id');
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class, 'fournisseur_id');
    }

    protected $appends = ['montant_paye'];
    public function details(): HasMany
    {
        return $this->hasMany(AchatDetail::class, 'achat_id');
    }

    // `taxesPersonnalisees()` vivait ici — retirée le 24/08/2026, décision du
    // propriétaire. Son commentaire annonçait « → `customTaxes` à la racine du
    // payload FNE », et c'était faux : **le payload du bordereau d'achat ne
    // porte aucune taxe**, ce qui est précisément l'un des six écarts corrigés
    // au moment de la conformité, et qui est gelé. Le formulaire d'achat
    // écrivait donc dans `achat_taxes` une valeur que rien ne relisait — ni la
    // plateforme, ni la comptabilité, ni le document imprimé — pendant que
    // l'écran en gonflait le total affiché. La table est supprimée avec elle.

    public function paiements(): HasMany
    {
        return $this->hasMany(TresorerieJournal::class, 'reference_document', 'numero_facture')
            ->where('type_operation', 'Décaissement');
    }

    public function getMontantPayeAttribute()
    {
        return $this->paiements()->sum('montant_sortie');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Achat::class, 'parent_id');
    }

    public function avoirs(): HasMany
    {
        return $this->hasMany(Achat::class, 'parent_id');
    }

    /**
     * Cette pièce est-elle un bordereau d'achat aux producteurs agricoles ?
     *
     * Deux chemins y mènent, et l'un des deux ne se voit pas dans
     * `type_facture` : une facture d'achat ordinaire dont le fournisseur n'a
     * pas de NCC part elle aussi en BAPA — c'est `validerFacture()` qui
     * déclenche `NormaliserAchatBapaJob` sur ce seul critère. Ne regarder que
     * `type_facture` laissait donc passer la moitié des bordereaux.
     *
     * Sert à interdire l'avoir : **la DGI ne normalise pas l'avoir d'un
     * BAPA**. Un avoir établi ici resterait sans contrepartie fiscale, et les
     * livres de Selflow s'écarteraient de ce que la plateforme détient.
     */
    public function estBapa(): bool
    {
        return $this->type_facture === 'bapa'
            || empty($this->fournisseur?->ncc);
    }
}

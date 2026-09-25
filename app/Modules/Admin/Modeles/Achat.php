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
        'montant_recu',    // la somme tendue au fournisseur ; la monnaie rendue s'en déduit
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

        /*
         * La facture du portail que cet achat vient combler.
         *
         * Le relevé arrive souvent **avant** la saisie : le fournisseur
         * certifie sa facture le jour même, le client l'enregistre le
         * lendemain. La facture reçue attendait alors qu'on vienne la
         * rattacher à la main.
         *
         * Elle se rattache désormais d'elle-même dans les deux sens : au
         * relevé quand l'achat est déjà là, et ici quand c'est l'achat qui
         * arrive en second.
         *
         * L'appel est **synchrone et dans la même transaction** : l'achat
         * qu'on vient d'écrire y est visible, et si la transaction est annulée
         * le rattachement l'est avec elle. Une file laisserait au contraire
         * une facture rattachée à un achat qui n'existe plus.
         */
        static::created(function (self $achat) {
            $entrepriseId = $achat->pointDeVente?->entreprise_id;

            if ($entrepriseId) {
                \App\Modules\Admin\Services\RapprochementAutomatiqueService::pourEntreprise($entrepriseId);
            }
        });

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

    /**
     * Le net à payer : ce que la caisse demande.
     *
     * Le TTC et, sur un bordereau, le timbre de quittance — c'est-à-dire ce
     * qu'on remet réellement au vendeur. L'article 875 du CGI met le droit à
     * la charge du débiteur : sur un achat, c'est l'entreprise qui l'acquitte.
     *
     * Sans le timbre, le plafonnement du décaissement le **retirerait** de la
     * caisse, là où la somme remise le couvrait.
     */
    public function netAPayer(): float
    {
        // Pas de taxes additionnelles à l'achat : la colonne a été retirée
        // le 24/08/2026. Le timbre, lui, est dû sur les bordereaux, et c'est
        // l'entreprise qui l'acquitte (article 875 du CGI).
        return (float) $this->montant_ttc
            + \App\Modules\Admin\Services\TimbreQuittanceService::pourAchat($this);
    }

    /**
     * Ce qu'on rend au client.
     *
     * Jamais négatif : une somme tendue insuffisante est une avance, pas une
     * monnaie à rendre — et l'annoncer en négatif ferait croire à une dette de
     * la caisse. `null` quand rien n'a été tendu : on ne rend pas zéro, on ne
     * rend rien, et le document ne doit pas porter une ligne qui n'a pas eu
     * lieu.
     */
    public function monnaieRendue(): ?float
    {
        if ($this->montant_recu === null) {
            return null;
        }

        return max(0.0, (float) $this->montant_recu - $this->netAPayer());
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

    /**
     * Les bordereaux, en base — la même règle que `estBapa()`, en SQL.
     *
     * Elle ne pouvait pas rester dans le seul accesseur : l'écran des factures
     * d'achat range les pièces en trois sections, et il range en base. Filtrer
     * sur le seul `type_facture` y aurait mis toutes les factures d'un vendeur
     * non immatriculé — c'est-à-dire le cas le plus courant — sous
     * « Factures enregistrées », où les colonnes DGI annoncent « Aucune
     * donnée ». Elles sont pourtant normalisées : `validerFacture()` les envoie
     * à `NormaliserAchatBapaJob` sur ce seul critère.
     *
     * Écrire la règle deux fois, c'est la voir diverger. Elle est ici, et
     * `estBapa()` dit la même chose pour une pièce déjà chargée.
     */
    public function scopeBordereaux($requete)
    {
        return $requete->where(function ($q) {
            $q->where('type_facture', 'bapa')
              ->orWhereDoesntHave('fournisseur')
              ->orWhereHas('fournisseur', function ($qf) {
                  $qf->whereNull('ncc')->orWhere('ncc', '');
              });
        });
    }

    /** Tout ce qui n'est pas un bordereau : la contrepartie exacte. */
    public function scopeHorsBordereaux($requete)
    {
        return $requete->where(function ($q) {
            $q->where(function ($qt) {
                $qt->whereNull('type_facture')->orWhere('type_facture', '!=', 'bapa');
            })->whereHas('fournisseur', function ($qf) {
                $qf->whereNotNull('ncc')->where('ncc', '!=', '');
            });
        });
    }

    public function rejets(): HasMany
    {
        return $this->hasMany(FneRejet::class, 'piece_id')->where('piece_type', 'achat');
    }

    /**
     * Vérifie si cet achat a un rejet FNE en cours de relève ou de correction.
     */
    public function aRejetEnCours(): bool
    {
        if ($this->normalise) {
            return false;
        }

        return $this->rejets->contains(fn (FneRejet $r) => in_array($r->statut, [
            FneRejet::STATUT_OUVERT,
            FneRejet::STATUT_DIAGNOSTIQUE,
        ]));
    }
}

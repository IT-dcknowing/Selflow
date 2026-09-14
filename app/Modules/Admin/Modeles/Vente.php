<?php

namespace App\Modules\Admin\Modeles;

use App\Modules\Admin\Modeles\Concerns\IdentifiantOpaque;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vente extends Model
{
    use IdentifiantOpaque;

    protected $table = 'ventes';

    protected $fillable = [
        'point_de_vente_id',
        'utilisateur_id',
        'client_id',
        'numero_facture',
        'numero_fne',
        'date_vente',
        'date_validite',    // terme de l'offre — devis et bons de commande
        'date_acceptation', // quand le client a accepté
        'accepte_par',      // qui, de son côté, a accepté
        'mode_paiement',
        'moyen_bancaire',
        'reference_paiement',
        'montant_ht',
        'montant_tva',
        'montant_ttc',
        'montant_autres_taxes', // taxes parafiscales collectées, hors TVA
        'remise',          // montant de la remise globale, en francs
        'remise_taux',     // taux de la remise globale, en % → champ `discount` FNE
        'statut',
        'type_facture',
        'type_piece',      // 'facture' ou 'recu' — nature du document commercial
        'piece_liee_id',   // reçu <-> facture issue l'un de l'autre
        'converti_en_id',  // la pièce née de celle-ci : devis → BC → facture
        'est_rne',         // → champ `isRne` FNE
        'numero_rne',      // → champ `rne` FNE
        'pied_de_page',    // → champ `footer` FNE
        'autres_mentions', // → champ `commercialMessage` FNE
        'normalise',
        'qr_code_data',
        'fichier_fne_pdf_url',
        'signature_dgi',
        // Donnees renvoyees par la plateforme FNE lors de la certification
        'fne_alerte_stickers',
        'fne_montant_ttc',
        'fne_montant_tva',
        'fne_timbre_fiscal',
        'fne_certifie_at',
        'fne_invoice_id',
        'etape',
        'archived',
        'bon_livraison_id',
        'parent_id',
        'raison_avoir',
        'fne_invoice_id',
        'devise',
        'taux_change',
        'mobile_money_operateur',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Modules\Admin\Scopes\PeriodeScope('date_vente'));

        static::creating(function ($model) {
            if (auth()->check()) {
                $model->utilisateur_id = auth()->id();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'date_vente'       => 'date',
            'date_validite'    => 'date',
            'date_acceptation' => 'date',
            'montant_ht'    => 'decimal:2',
            'montant_tva'   => 'decimal:2',
            'montant_ttc'   => 'decimal:2',
            'montant_autres_taxes' => 'decimal:2',
            'remise'        => 'decimal:2',
            'remise_taux'   => 'decimal:2',
            'est_rne'       => 'boolean',
            'fne_alerte_stickers' => 'boolean',
            'fne_montant_ttc'     => 'decimal:2',
            'fne_montant_tva'     => 'decimal:2',
            'fne_timbre_fiscal'   => 'decimal:2',
            'fne_certifie_at'     => 'datetime',
            'normalise'     => 'boolean',
            'archived'      => 'boolean',
        ];
    }

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class, 'point_de_vente_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Authentification\Modeles\Utilisateur::class, 'utilisateur_id');
    }

    protected $appends = ['montant_paye'];

    public function details(): HasMany
    {
        return $this->hasMany(VenteDetail::class, 'vente_id');
    }

    /**
     * Taxes sur le total TTC (→ `customTaxes` à la racine du payload FNE).
     */
    public function taxesPersonnalisees(): HasMany
    {
        return $this->hasMany(VenteTaxe::class, 'vente_id');
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(TresorerieJournal::class, 'reference_document', 'numero_facture')
            ->where('type_operation', 'Encaissement');
    }

    public function getMontantPayeAttribute()
    {
        return $this->paiements()->sum('montant_entree');
    }

    /**
     * Si ce BC a généré un Bon de Livraison
     */
    public function bonLivraison(): HasOne
    {
        return $this->hasOne(BonLivraison::class, 'vente_id');
    }

    /**
     * Le BL dont cette Facture est issue (via bon_livraison_id)
     */
    public function bonLivraisonSource(): BelongsTo
    {
        return $this->belongsTo(BonLivraison::class, 'bon_livraison_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Vente::class, 'parent_id');
    }

    /**
     * Natures possibles d'une pièce de vente.
     */
    public const TYPE_FACTURE = 'facture';
    public const TYPE_RECU    = 'recu';

    public function estRecu(): bool
    {
        return $this->type_piece === self::TYPE_RECU;
    }

    /**
     * Reçu dont cette facture est issue, ou facture issue de ce reçu.
     * Le lien est symétrique : les deux pièces se pointent mutuellement.
     */
    public function pieceLiee(): BelongsTo
    {
        return $this->belongsTo(Vente::class, 'piece_liee_id');
    }

    public function rejets(): HasMany
    {
        return $this->hasMany(FneRejet::class, 'piece_id')->where('piece_type', 'vente');
    }

    /**
     * Vérifie si cette facture a un rejet FNE en cours de relève ou de correction.
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

    // ─────────────────────────────────────────────────────────────────
    // LE DEVIS OPPOSABLE
    // ─────────────────────────────────────────────────────────────────

    /**
     * Les étapes qui portent une offre : elles ont un terme, elles peuvent être
     * acceptées, et elles se convertissent. Une facture n'en est pas une —
     * elle engage dès son émission et n'expire pas.
     */
    public const ETAPES_OFFRE = ['Devis', 'Bon de commande'];

    /**
     * Durée de validité par défaut d'un devis, en jours.
     *
     * Trente jours est l'usage commercial courant, et c'est le délai que
     * retiennent les tribunaux quand l'offre est muette. Le formulaire permet
     * d'en saisir un autre.
     */
    public const VALIDITE_PAR_DEFAUT = 30;

    public function estUneOffre(): bool
    {
        return in_array($this->etape, self::ETAPES_OFFRE, true);
    }

    /**
     * La pièce née de celle-ci : le bon de commande issu du devis, la facture
     * issue du bon de commande.
     */
    public function convertiEn(): BelongsTo
    {
        return $this->belongsTo(Vente::class, 'converti_en_id');
    }

    /**
     * Cette offre a-t-elle déjà donné une pièce ?
     *
     * `archived` disait qu'une conversion avait eu lieu sans dire en quoi, et
     * rien n'empêchait la seconde : le même devis produisait deux bons de
     * commande, donc deux livraisons et deux factures.
     */
    public function estConverti(): bool
    {
        return $this->converti_en_id !== null;
    }

    /**
     * L'offre a-t-elle passé son terme ?
     *
     * Une offre sans terme n'expire pas — c'est le cas des devis établis avant
     * ce lot, qu'on ne va pas invalider rétroactivement. Le jour du terme est
     * compris : un devis valable jusqu'au 30 peut être accepté le 30.
     */
    public function estExpire(): bool
    {
        return $this->estUneOffre()
            && $this->date_validite !== null
            && $this->date_validite->endOfDay()->isPast();
    }

    /**
     * Le client a-t-il accepté ?
     */
    public function estAccepte(): bool
    {
        return $this->date_acceptation !== null;
    }

    /**
     * L'offre engage-t-elle encore celui qui l'a faite ?
     *
     * C'est la question que pose le mot « opposable » : une offre que son terme
     * a dépassée ne lie plus personne, et une offre déjà convertie a produit
     * son effet.
     */
    public function estOpposable(): bool
    {
        return $this->estUneOffre() && !$this->estExpire() && !$this->estConverti();
    }

    /**
     * Une offre acceptée ou convertie se relit, elle ne se réécrit pas.
     *
     * C'est ce qui fait la différence entre un document opposable et une note :
     * un devis dont les prix changent après l'accord du client ne prouve rien.
     * La correction passe par un nouveau devis.
     */
    public function estFige(): bool
    {
        return $this->estUneOffre() && ($this->estAccepte() || $this->estConverti());
    }

    /**
     * Ce que l'écran affiche du sort d'une offre.
     */
    public function etatDeLOffre(): string
    {
        return match (true) {
            !$this->estUneOffre() => '',
            $this->estConverti()  => 'Converti',
            $this->estAccepte()   => 'Accepté',
            $this->estExpire()    => 'Expiré',
            default               => $this->statut === 'Envoyé' ? 'En attente' : 'Brouillon',
        };
    }

    /**
     * Écarte les pièces qui feraient compter deux fois le même chiffre
     * d'affaires.
     *
     * Un reçu qui a donné lieu à une facture est remplacé par elle : les deux
     * portent les mêmes montants. Le reçu reste consultable et imprimable, et
     * conserve les écritures comptables de son encaissement — la facture, elle,
     * n'en génère aucune —, mais il sort des agrégats pour que le chiffre
     * d'affaires ne soit pas doublé.
     */
    public function scopeSansDoublonRecu($query)
    {
        return $query->where(function ($q) {
            $q->where('type_piece', '!=', self::TYPE_RECU)
              ->orWhereNull('piece_liee_id');
        });
    }

    /**
     * Ce reçu a-t-il été remplacé par la facture qui en découle ?
     */
    public function estRemplaceParUneFacture(): bool
    {
        return $this->estRecu() && $this->piece_liee_id !== null;
    }

    /**
     * Montant réellement réclamé au client : le TTC fiscal augmenté des taxes
     * parafiscales collectées pour l'État.
     *
     * `montant_ttc` reste le TTC au sens fiscal (HT net + TVA) : c'est lui qui
     * sert de base aux déclarations et au payload FNE.
     */
    public function getNetAPayerAttribute(): float
    {
        return (float) $this->montant_ttc
            + (float) ($this->montant_autres_taxes ?? 0)
            + $this->timbre_quittance;
    }

    /**
     * Droit de timbre de quittance dû sur cette vente.
     *
     * Le montant retenu par la plateforme fait foi dès qu'elle l'a renvoyé ;
     * avant certification, il est établi au barème de l'article 873 du CGI.
     * Voir TimbreQuittanceService.
     */
    public function getTimbreQuittanceAttribute(): float
    {
        return \App\Modules\Admin\Services\TimbreQuittanceService::pourVente($this);
    }

    /**
     * Libellé de la pièce, tel qu'affiché dans les registres.
     */
    public function libelleTypeDocument(): string
    {
        if ($this->type_facture === 'avoir') {
            return 'Facture d\'avoir';
        }

        return $this->estRecu() ? 'Reçu' : 'Facture';
    }

    public function avoirs(): HasMany
    {
        return $this->hasMany(Vente::class, 'parent_id');
    }
}

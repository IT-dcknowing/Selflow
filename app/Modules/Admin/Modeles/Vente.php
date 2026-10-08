<?php

namespace App\Modules\Admin\Modeles;

use App\Modules\Admin\Modeles\Concerns\IdentifiantOpaque;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'montant_recu',    // la somme tendue par le client ; la monnaie rendue s'en déduit
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
     * Le net à payer : ce que la caisse demande.
     *
     * Le TTC, les taxes additionnelles collectées, **et le timbre de
     * quittance** — c'est-à-dire exactement ce que le pavé des totaux affiche
     * au caissier, et donc ce que le client tend.
     *
     * Le timbre n'est pas décoratif ici : sans lui, le plafonnement de
     * l'encaissement le **retirerait** de la caisse, là où la somme tendue le
     * couvrait. Son montant vient de `TimbreQuittanceService`, seule autorité
     * en la matière — la plateforme d'abord, le barème de l'article 873 à
     * défaut.
     *
     * Le définir ici plutôt qu'à trois endroits évite qu'un écran annonce un
     * net et qu'un autre en attende un second.
     */
    public function netAPayer(): float
    {
        return (float) $this->montant_ttc
            + (float) ($this->montant_autres_taxes ?? 0)
            + \App\Modules\Admin\Services\TimbreQuittanceService::pourVente($this);
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

    /**
     * Les bons de livraison de ce bon de commande.
     *
     * Plusieurs, et non un seul : une commande se livre souvent en plusieurs
     * fois, et la relation `hasOne` d'origine interdisait le second bon — le
     * solde d'une livraison partielle ne pouvait plus partir que par la file
     * du stock. Chaque bon reste plafonné au reste à livrer.
     */
    public function bonsLivraison(): HasMany
    {
        return $this->hasMany(BonLivraison::class, 'vente_id');
    }

    /**
     * Ce qui reste à livrer sur chaque ligne de la commande, indexé par ligne.
     *
     * Seules les lignes d'article comptent : une ligne libre (prestation
     * saisie à la main) ne sort d'aucun stock et ne se livre pas.
     *
     * @return array<int, float>
     */
    public function resteALivrerParLigne(): array
    {
        $reste = [];
        foreach ($this->details as $detail) {
            if (!$detail->produit_id) {
                continue;
            }
            $reste[$detail->id] = max(0.0, round((float) $detail->quantite - (float) $detail->quantite_livree, 3));
        }

        return $reste;
    }

    /** Toute la commande est-elle partie ? */
    public function estEntierementLivree(): bool
    {
        return array_sum($this->resteALivrerParLigne()) <= 0;
    }

    /**
     * La commande a-t-elle commencé à partir ?
     *
     * Un bon de livraison, ou une quantité sortie par la file du stock : dans
     * les deux cas, de la marchandise a quitté le magasin sur la foi de ce
     * document, qui ne peut plus dire autre chose.
     */
    public function aDesLivraisons(): bool
    {
        if ($this->etape !== 'Bon de commande') {
            return false;
        }

        return $this->bonsLivraison()->exists()
            || $this->details()->where('quantite_livree', '>', 0)->exists();
    }

    /**
     * Un de ses bons de livraison a-t-il déjà été facturé ?
     *
     * La commande se facture alors par ses bons : la facturer en bloc
     * reprendrait ce qui figure déjà sur une facture.
     */
    public function aUnBonDeLivraisonFacture(): bool
    {
        return $this->bonsLivraison()->whereNotNull('facture_vente_id')->exists();
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
        // Une commande livrée — même en partie — est figée elle aussi : la
        // marchandise est partie sur la foi de ses lignes, et la modifier
        // ferait dire au bon de livraison et à la facture autre chose que ce
        // que le client a reçu.
        return $this->estUneOffre()
            && ($this->estAccepte() || $this->estConverti() || $this->aDesLivraisons());
    }

    /**
     * Ce que l'écran affiche du sort d'une offre.
     */
    public function etatDeLOffre(): string
    {
        if (!$this->estUneOffre()) {
            return '';
        }

        if ($this->estConverti()) {
            return $this->etape === 'Bon de commande' ? 'Facturé' : 'Converti';
        }

        // Le sort logistique d'une commande prime sur celui de l'offre : une
        // commande livrée n'est plus un « brouillon ».
        if ($this->aDesLivraisons()) {
            if ($this->aUnBonDeLivraisonFacture()) {
                return 'Facturé en partie';
            }

            return $this->estEntierementLivree() ? 'Livré' : 'Livré en partie';
        }

        return match (true) {
            $this->estAccepte()   => 'Accepté',
            $this->estExpire()    => 'Expiré',
            default               => $this->statut === 'Envoyé' ? 'En attente' : 'Brouillon',
        };
    }

    /**
     * Le nom de la pièce, tel que l'écran et l'onglet du navigateur le disent.
     */
    public function libelleEtape(): string
    {
        if ($this->type_facture === 'avoir') {
            return 'Facture d\'avoir';
        }

        return match ($this->etape) {
            'Devis'           => 'Devis',
            'Bon de commande' => 'Bon de commande',
            default           => $this->estRecu() ? 'Reçu' : 'Facture',
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

    /**
     * Ce qui reste à rendre au client sur cette facture, en TTC.
     *
     * Un avoir ne peut pas dépasser la pièce d'origine : ce serait rendre plus
     * que ce qui a été facturé, et la TVA collectée deviendrait négative sur la
     * pièce (chantier 8.1, validé par le propriétaire le 02/10/2026). Le
     * plafond par quantité ne suffisait pas : le prix unitaire reste modifiable
     * dans la modale, et une ligne ajoutée à l'avoir n'avait aucun plafond.
     */
    public function resteAAvoirer(): float
    {
        // Hors du filtre de période global : un avoir établi le mois dernier
        // a bien crédité cette facture, quelle que soit la période affichée.
        // Lu avec le filtre, il échappait au plafond, et la même somme
        // pouvait être rendue deux fois.
        $dejaAvoire = (float) self::withoutGlobalScopes()
            ->where('parent_id', $this->id)
            ->where('type_facture', 'avoir')
            ->sum('montant_ttc');

        return round(max(0, (float) $this->montant_ttc - $dejaAvoire), 2);
    }

    /** Tolérance d'arrondi sur le plafond : un franc, pas davantage. */
    public const TOLERANCE_AVOIR = 1.0;

    /**
     * Les factures sur lesquelles un avoir peut encore porter.
     *
     * Écrite une fois : la liste déroulante et le champ de recherche de la
     * modale d'avoir portaient chacun leur copie de cette requête. Une facture
     * entièrement avoirée en sort (chantier 8.2) ; `deja_avoire` dit ce qui a
     * déjà été rendu, pour que l'écran annonce le reste (chantier 8.3).
     */
    public function scopeAvoirables($requete)
    {
        return $requete
            ->where('etape', 'Facture')
            ->where(function ($q) {
                // L'ancien préfixe (VT-) et le nouveau (VTE-).
                $q->where('numero_facture', 'LIKE', 'VT-%')
                  ->orWhere('numero_facture', 'LIKE', 'VTE-%');
            })
            ->where(function ($q) {
                $q->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir');
            })
            ->where('archived', false)
            ->withSum(['avoirs as deja_avoire' => fn ($a) => $a->where('type_facture', 'avoir')], 'montant_ttc')
            ->whereRaw(
                'montant_ttc - COALESCE((SELECT SUM(a.montant_ttc) FROM ventes a WHERE a.parent_id = ventes.id AND a.type_facture = ?), 0) > (? + 0)',
                ['avoir', self::TOLERANCE_AVOIR]
            );
    }

    /** Le libellé d'une facture dans la modale d'avoir, reste compris. */
    public function libellePourAvoir(): string
    {
        $client = $this->client?->nom ?? 'Client de passage';
        $deja = (float) ($this->deja_avoire ?? 0);
        $montant = $deja > 0
            ? 'reste ' . number_format(max(0, (float) $this->montant_ttc - $deja), 0, ',', ' ')
                . ' F sur ' . number_format((float) $this->montant_ttc, 0, ',', ' ') . ' F'
            : number_format((float) $this->montant_ttc, 0, ',', ' ') . ' F';

        return "{$this->numero_facture} - {$client} ({$montant})";
    }
}

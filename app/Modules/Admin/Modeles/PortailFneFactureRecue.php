<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une facture que le portail FNE dit avoir reçue pour le compte de l'entreprise.
 *
 * C'est un **constat**, pas un achat. Rien ici ne mouvemente un stock, ne
 * produit d'écriture ni ne déduit de TVA. Le rapprochement avec un achat de
 * Selflow se regarde (`rapprochementPropose()`) avant de s'appliquer, et il
 * s'applique d'un geste d'utilisateur.
 */
class PortailFneFactureRecue extends Model
{
    protected $table = 'portail_fne_factures_recues';

    /** Le portail n'a pas encore été confronté à un achat de Selflow. */
    public const A_RAPPROCHER = 'a_rapprocher';

    /** Un achat de Selflow porte cette pièce. */
    public const RAPPROCHEE = 'rapprochee';

    /** Aucun fournisseur de Selflow ne porte le NCC de l'émetteur. */
    public const ORPHELINE = 'orpheline';

    /** Vue, et volontairement laissée de côté. */
    public const ECARTEE = 'ecartee';

    /**
     * Les sous-types que le portail emploie, et ce qu'ils veulent dire.
     *
     * `purchase_slip` est le bordereau d'achat de produits agricoles : il ne
     * porte aucune TVA. Le confondre avec une facture normale ferait déduire
     * une taxe qui n'a jamais été facturée.
     */
    public const SOUS_TYPES = [
        'normal'        => 'Facture normalisée',
        'purchase_slip' => "Bordereau d'achat",
        'refund'        => 'Avoir',
        'proforma'      => 'Proforma',
    ];

    protected $fillable = [
        'import_id',
        'entreprise_id',
        'login',
        'date_scraping',
        'reference',
        'fne_id',
        'token',
        'type',
        'subtype',
        'est_rne',
        'numero_rne',
        'date_facture',
        'emetteur_ncc',
        'emetteur_nom',
        'emetteur_id',
        'emetteur_rccm',
        'montant_ht',
        'remise',
        'montant_tva',
        'timbre_fiscal',
        'autres_taxes',
        'montant_ttc',
        'net_a_payer',
        'devise',
        'taux_change',
        'statut_portail',
        'moyen_paiement',
        'achat_id',
        'statut_rapprochement',
        'note_rapprochement',
        'contenu_brut',
    ];

    protected function casts(): array
    {
        return [
            'date_scraping' => 'date',
            'date_facture'  => 'datetime',
            'est_rne'       => 'boolean',
            'montant_ht'    => 'decimal:2',
            'remise'        => 'decimal:2',
            'montant_tva'   => 'decimal:2',
            'timbre_fiscal' => 'decimal:2',
            'autres_taxes'  => 'decimal:2',
            'montant_ttc'   => 'decimal:2',
            'net_a_payer'   => 'decimal:2',
            'taux_change'   => 'decimal:6',
            'contenu_brut'  => 'array',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(PortailFneImport::class, 'import_id');
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_id');
    }

    public function achat(): BelongsTo
    {
        return $this->belongsTo(Achat::class, 'achat_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(PortailFneFactureRecueLigne::class, 'facture_recue_id');
    }

    /**
     * La TVA de cette pièce est-elle déductible ?
     *
     * Un reçu normalisé n'en porte pas, un bordereau d'achat non plus — il
     * constate un achat auprès d'un tiers non immatriculé. Répondre « oui »
     * partout ferait déduire une taxe jamais facturée, ce qui est exactement
     * l'erreur que `ventilationAchat` a déjà corrigée du côté des BAPA.
     */
    public function tvaDeductible(): bool
    {
        return !$this->est_rne
            && $this->subtype !== 'purchase_slip'
            && (float) $this->montant_tva > 0;
    }

    public function libelleDuSousType(): string
    {
        return self::SOUS_TYPES[$this->subtype] ?? ($this->subtype ?: '—');
    }

    /**
     * Le fournisseur de Selflow qui porte le NCC de l'émetteur, s'il existe.
     *
     * Par le NCC seul, jamais par le nom : deux raisons sociales se ressemblent,
     * deux NCC non. Le NCC est comparé sans espaces ni ponctuation, parce qu'il
     * s'écrit « 1864699 A » ici et « 1864699A » au portail.
     */
    public function fournisseurProbable(): ?Fournisseur
    {
        $ncc = self::nccComparable($this->emetteur_ncc);

        if ($ncc === '') {
            return null;
        }

        return Fournisseur::query()
            ->when($this->entreprise_id, fn ($q) => $q->where('entreprise_id', $this->entreprise_id))
            ->get()
            ->first(fn (Fournisseur $f) => self::nccComparable($f->ncc) === $ncc);
    }

    /**
     * L'achat de Selflow qui pourrait être cette pièce, et l'écart s'il y en a un.
     *
     * On ne rapproche rien : on montre. Un montant qui diffère de ce que la DGI
     * détient vaut de l'argent, et c'est à un humain de trancher lequel des deux
     * a raison.
     *
     * @return array{fournisseur: Fournisseur|null, achat: Achat|null, ecart_ttc: float|null}
     */
    public function rapprochementPropose(): array
    {
        $fournisseur = $this->fournisseurProbable();

        $achat = $fournisseur
            ? Achat::query()
                ->where('fournisseur_id', $fournisseur->id)
                ->whereDate('date_achat', $this->date_facture?->toDateString() ?? '1970-01-01')
                ->first()
            : null;

        return [
            'fournisseur' => $fournisseur,
            'achat'       => $achat,
            'ecart_ttc'   => $achat ? round((float) $achat->montant_ttc - (float) $this->montant_ttc, 2) : null,
        ];
    }

    /** Le NCC débarrassé de ce qui ne l'identifie pas. */
    public static function nccComparable(?string $ncc): string
    {
        return preg_replace('/[^0-9A-Z]/', '', strtoupper((string) $ncc));
    }
}

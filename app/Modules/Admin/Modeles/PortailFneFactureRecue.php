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
        'point_de_vente_id',
        'login',
        'date_scraping',
        'reference',
        'fne_id',
        'token',
        'fichier_pdf',
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

    /**
     * Le site auquel cette charge a été affectée — par nous, jamais par le portail.
     *
     * Voir la migration `2026_09_07_000001` : le `clientPointOfSale` du relevé
     * décrit l'émetteur, pas nous.
     */
    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class, 'point_de_vente_id');
    }

    /**
     * Le site que Selflow peut affecter seul, sans rien deviner.
     *
     * Deux cas, et deux seulement :
     *
     * 1. la facture est rattachée à un achat — le site est celui de l'achat,
     *    c'est la même pièce ;
     * 2. l'entreprise n'a qu'un point de vente — il n'y a rien à trancher.
     *
     * Hors de là, `null` : le choix revient à un utilisateur. Prendre le site
     * actif de celui qui regarde l'écran affecterait la même facture à des sites
     * différents selon qui la consulte, et une charge rangée sous le mauvais
     * établissement fausse le résultat par site sans que rien ne le signale.
     */
    public function siteEvident(): ?int
    {
        if ($this->achat_id) {
            return $this->achat?->point_de_vente_id;
        }

        if (!$this->entreprise_id) {
            return null;
        }

        $points = PointDeVente::where('entreprise_id', $this->entreprise_id)
            ->limit(2)
            ->pluck('id');

        return $points->count() === 1 ? (int) $points->first() : null;
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

    /**
     * L'adresse où la DGI montre cette pièce.
     *
     * ## Pourquoi elle se construit au lieu de se lire
     *
     * Nos propres pièces certifiées reçoivent une adresse toute faite : la
     * réponse de certification met dans `token` l'URL complète, que
     * `fichier_fne_pdf_url` et `qr_code_data` recopient —
     * `…/fr/verification/01a05e78-6da1-7000-8346-e188ca934174`.
     *
     * Le relevé des factures **reçues** n'en donne pas : `/ws/invoices` rend un
     * `token` nu, l'identifiant seul (`01a06bf8-8e1a-7000-8650-7ae311524dfc`),
     * de même forme. C'est la même clé, sans le chemin. Faute de la reconstruire,
     * les boutons « Voir » et « Télécharger » restaient vides sur ces lignes —
     * alors que la pièce est consultable comme n'importe quelle autre.
     *
     * ## Ce que cela ne touche pas
     *
     * Rien ne part à la DGI : c'est un lien d'affichage, construit après coup à
     * partir de ce que la plateforme a déjà rendu. `FneService`,
     * `QrCodeFneService` et la colonne `qr_code_data` ne sont ni lus ni écrits.
     *
     * L'hôte vient de la même configuration que l'API — la vérification est
     * servie par le portail lui-même, à la racine plutôt que sous `/ws`.
     */
    public function urlDeVerification(): ?string
    {
        $token = trim((string) $this->token);

        if ($token === '') {
            return null;
        }

        // Le token peut déjà être une adresse : le portail n'a pas la même forme
        // partout, et le jour où `/ws/invoices` rendra l'URL complète, la
        // préfixer une seconde fois produirait un lien mort.
        if (str_starts_with($token, 'http://') || str_starts_with($token, 'https://')) {
            return $token;
        }

        $api = (string) (config('selflow.fne_api_url_production')
            ?: config('selflow.fne_api_url_sandbox'));

        if (trim($api) === '') {
            return null;
        }

        // `…/ws` est la racine de l'API ; la page de vérification est servie à
        // côté, pas dessous.
        $hote = rtrim((string) preg_replace('#/ws/?$#', '', trim($api)), '/');

        return $hote === '' ? null : "{$hote}/fr/verification/{$token}";
    }

    /**
     * Le chemin sur disque du PDF que la DGI sert pour cette pièce.
     *
     * `fichier_pdf` porte un nom relatif au dossier d'import, jamais un chemin
     * absolu : le dossier se déplace d'un poste à l'autre — il est réglé par
     * `PORTAIL_FNE_DOSSIER_IMPORT` — et un chemin absolu écrit en base le
     * 8 septembre serait faux le jour du déménagement.
     *
     * Rend `null` dès que le fichier n'est plus là : l'écran doit pouvoir
     * retomber sur la reconstruction plutôt que de servir une page blanche.
     */
    public function cheminDuPdf(): ?string
    {
        $nom = trim((string) $this->fichier_pdf);

        if ($nom === '') {
            return null;
        }

        // `basename` et non le nom tel quel : la colonne est écrite par
        // l'import, mais une valeur portant « ../ » ferait servir n'importe quel
        // fichier du disque par la route qui lit ceci.
        $chemin = rtrim((string) config('selflow.portail_fne.dossier_import'), "/\\")
            . DIRECTORY_SEPARATOR . 'achats'
            . DIRECTORY_SEPARATOR . 'pdf'
            . DIRECTORY_SEPARATOR . basename($nom);

        return is_file($chemin) ? $chemin : null;
    }

    /** Le document de la DGI est-il sur le disque ? */
    public function pdfDisponible(): bool
    {
        return $this->cheminDuPdf() !== null;
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

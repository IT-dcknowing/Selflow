<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le gabarit de libellé d'une entreprise, pour un type d'opération.
 *
 * Deux gabarits par type : celui de l'opération — ce que le journal annonce en
 * tête — et celui de ses lignes. Les deux acceptent les mêmes jetons ; ceux
 * qui n'ont pas de valeur disparaissent, et les séparateurs restés orphelins
 * avec eux.
 */
class ModeleLibelle extends Model
{
    protected $table = 'modeles_libelles';

    protected $fillable = [
        'entreprise_id',
        'type_operation',
        'gabarit_operation',
        'gabarit_ligne',
    ];

    /**
     * Les types d'opération paramétrables, et leur nom à l'écran.
     *
     * Ce sont exactement les six que `ComptabiliteService` écrit depuis une
     * pièce commerciale. Les écritures de stock, d'amortissement et de
     * consignation n'y figurent pas : leur libellé est déjà celui du
     * mouvement, et il n'y a pas d'intitulé de compte à y substituer.
     */
    public const TYPES = [
        'FactureVente'   => 'Facture de vente',
        'ReglementVente' => 'Règlement client',
        'AvoirVente'     => 'Avoir client',
        'FactureAchat'   => "Facture d'achat",
        'ReglementAchat' => 'Règlement fournisseur',
        'AvoirAchat'     => 'Avoir fournisseur',
    ];

    /**
     * Les gabarits par défaut — ils reproduisent **exactement** ce que
     * l'application écrivait avant d'être paramétrable. Une entreprise qui ne
     * touche à rien ne voit donc aucun changement dans son journal.
     *
     * @var array<string, array{operation: string, ligne: string}>
     */
    public const DEFAUTS = [
        // 25/11/2026 (propriétaire) : « Fact n° {numéro FNE, sinon Selflow} /
        // libellé de l'opération », et « Rglt/Fact n° … » pour le règlement.
        'FactureVente'   => ['operation' => 'Fact n° {fact} / {nature}',      'ligne' => 'Fact n° {fact} / {role}'],
        'ReglementVente' => ['operation' => 'Rglt/Fact n° {fact} / {nature}', 'ligne' => 'Rglt/Fact n° {fact} / {nature} / {reference}'],
        'AvoirVente'     => ['operation' => 'Avoir client',          'ligne' => '{piece} / {role}'],
        'FactureAchat'   => ['operation' => '{nature}',              'ligne' => '{piece} / {role}'],
        'ReglementAchat' => ['operation' => 'Règlement fournisseur', 'ligne' => 'Rglt/{piece}/{reference}/Achat {produits}'],
        'AvoirAchat'     => ['operation' => 'Avoir fournisseur',     'ligne' => '{piece} / {role}'],
    ];

    /**
     * Les jetons reconnus, et ce qu'ils portent. Un jeton absent de cette
     * liste est laissé tel quel : mieux vaut un `{clientt}` visible à l'écran
     * qu'un libellé amputé sans explication.
     */
    public const JETONS = [
        '{piece}'          => "Le numéro de la pièce — facture, avoir, opération diverse",
        '{fact}'           => 'Le numéro de la facture : celui de la FNE (sans le NCC) si elle est certifiée, sinon celui de Selflow',
        '{tiers}'          => 'Le nom du client ou du fournisseur',
        '{produits}'       => 'Les articles de la pièce, au plus trois, puis « … »',
        '{point_de_vente}' => 'Le nom du site où la pièce a été établie',
        '{date}'           => 'La date de la pièce, en jj/mm/aaaa',
        '{nature}'         => "L'intitulé SYSCOHADA des comptes mouvementés — « Vente de marchandises »",
        '{journal}'        => 'Le code du journal — VTE, ACH, CAI…',
        '{reference}'      => 'La référence du règlement : n° de chèque, de virement, de transfert',
        '{role}'           => "Le rôle de la ligne — « Facturation Vente », « TVA Collectée »… Vide sur l'opération",
    ];

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_id');
    }

    /**
     * Le gabarit par défaut d'un type, ou celui de la facture de vente si le
     * type est inconnu — un appelant qui se trompe de nom obtient un libellé
     * lisible plutôt qu'une chaîne vide.
     *
     * @return array{operation: string, ligne: string}
     */
    public static function defaut(string $type): array
    {
        return self::DEFAUTS[$type] ?? self::DEFAUTS['FactureVente'];
    }
}

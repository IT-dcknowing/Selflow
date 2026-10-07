<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Consignation;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Modeles\Produit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Consigner, reprendre, constater le non-retour.
 *
 * **La consignation reçue est une dette, non un produit.** Une caisse consignée
 * 2 000 francs gonflait le chiffre d'affaires de 2 000 francs que l'entreprise
 * devra rendre. Elle vit au passif jusqu'au retour de l'emballage.
 *
 * ## Les trois moments
 *
 * | Moment | Débit | Crédit |
 * |---|---|---|
 * | **Consignation au client** | 411 — le client doit la somme | 419400 — et l'entreprise la lui devra |
 * | **Reprise, au même prix** | 419400 — la dette s'éteint | 411 — et le client est crédité |
 * | **Reprise à prix réduit** | 419400 — pour la consignation | 411 pour ce qu'on rend, `707400` pour le boni |
 * | **Non-retour** | 419400 — la dette s'éteint | `707400` — le boni |
 *
 * Chez le fournisseur, tout s'inverse : `409400` est une créance, et ce qu'on
 * ne rend pas devient un **mali**, charge du compte `622400`.
 *
 * ## Ce que ce service ne fait pas, et pourquoi
 *
 * **Il n'établit aucune facture.** Le non-retour est une vente, soumise à la
 * TVA et à la certification de la plateforme : elle passe par l'écran de vente
 * ordinaire, dont la conformité est acquise et gelée. Fabriquer ici une seconde
 * route vers la FNE remettrait cette conformité en jeu pour un gain nul. Le
 * service constate le boni en comptabilité, et l'écran renvoie l'utilisateur
 * vers la facture pour la part fiscale.
 */
class ConsignationService
{
    /**
     * Consigner un emballage.
     *
     * Le prix de consignation vient de la fiche article s'il n'est pas donné :
     * un dépôt de boissons consigne toujours le casier au même prix, et le
     * ressaisir à chaque vente serait une source d'écart.
     *
     * @param  array{piece?: Model, reference?: string, date?: string, delai_jours?: int|null, designation?: string}  $contexte
     */
    public static function consigner(
        int $entrepriseId,
        int $pointDeVenteId,
        string $sens,
        ?int $tiersId,
        ?Produit $emballage,
        float $quantite,
        ?float $prixConsigne = null,
        array $contexte = []
    ): ?Consignation {
        if (!in_array($sens, [Consignation::AU_CLIENT, Consignation::DU_FOURNISSEUR], true)) {
            throw new \InvalidArgumentException(
                "Sens de consignation inconnu : « {$sens} ». Une consignation va au client ou vient du fournisseur."
            );
        }

        $quantite = round($quantite, Consignation::DECIMALES);
        $prix     = round((float) ($prixConsigne ?? $emballage?->prix_consignation ?? 0), 2);

        // Une consignation à zéro n'est pas une consignation : elle
        // encombrerait l'écran de ce qui est dehors sans rien y apporter.
        if ($quantite <= 0 || $prix <= 0) {
            return null;
        }

        return DB::transaction(function () use (
            $entrepriseId, $pointDeVenteId, $sens, $tiersId, $emballage,
            $quantite, $prix, $contexte
        ) {
            $date  = $contexte['date'] ?? now()->toDateString();
            $piece = $contexte['piece'] ?? null;

            $delai = $contexte['delai_jours'] ?? $emballage?->delai_retour_jours;

            $consignation = Consignation::create([
                'entreprise_id'      => $entrepriseId,
                'point_de_vente_id'  => $pointDeVenteId,
                'sens'               => $sens,
                'client_id'          => $sens === Consignation::AU_CLIENT ? $tiersId : null,
                'fournisseur_id'     => $sens === Consignation::DU_FOURNISSEUR ? $tiersId : null,
                'produit_id'         => $emballage?->id,
                'designation'        => $contexte['designation'] ?? ($emballage?->nom ?? 'Emballage consigné'),
                'piece_type'         => $piece?->getMorphClass(),
                'piece_id'           => $piece?->getKey(),
                'reference_document' => $contexte['reference'] ?? null,
                'quantite'           => $quantite,
                'prix_consigne'      => $prix,
                'montant'            => round($quantite * $prix, 2),
                'date_consignation'  => $date,
                'date_limite_retour' => $delai
                    ? \Illuminate\Support\Carbon::parse($date)->addDays((int) $delai)->toDateString()
                    : null,
                'statut'             => Consignation::EN_COURS,
                'utilisateur_id'     => Auth::id(),
            ]);

            self::ecrireLaConsignation($consignation);

            return $consignation->fresh();
        });
    }

    /**
     * Reprendre tout ou partie d'un emballage consigné.
     *
     * Les retours partiels sont la règle : un client rend huit casiers sur dix
     * et garde les deux autres pour la semaine suivante.
     *
     * **Le prix de reprise peut être inférieur au prix consigné.** L'écart est
     * un *boni* — compte `707400` — et c'est ce qui se pratique quand
     * l'emballage revient abîmé. Il ne peut pas lui être supérieur : rendre
     * plus qu'on n'a reçu serait un cadeau que rien ne justifie, et
     * l'entreprise perdrait de l'argent sans qu'aucune ligne ne le dise.
     */
    public static function rendre(
        Consignation $consignation,
        float $quantiteRendue,
        ?float $prixDeReprise = null,
        ?string $date = null
    ): Consignation {
        if ($consignation->estClose()) {
            throw new \LogicException(
                'Cette consignation est close : ' . ($consignation->statut === Consignation::RENDUE
                    ? 'l\'emballage est déjà revenu.'
                    : 'le non-retour a déjà été constaté.')
            );
        }

        $quantiteRendue = round($quantiteRendue, Consignation::DECIMALES);
        $dehors = $consignation->quantiteDehors();

        if ($quantiteRendue <= 0) {
            throw new \InvalidArgumentException('Une reprise porte sur une quantité strictement positive.');
        }

        if ($quantiteRendue > $dehors) {
            throw new \InvalidArgumentException(
                "Il n'y a que {$dehors} unité(s) dehors sur cette consignation : "
                . "en reprendre {$quantiteRendue} rembourserait ce qui n'a jamais été consigné."
            );
        }

        $prix = round((float) ($prixDeReprise ?? $consignation->prix_consigne), 2);

        if ($prix > $consignation->prix_consigne) {
            throw new \InvalidArgumentException(
                'Le prix de reprise ne peut pas dépasser le prix de consignation : '
                . 'l\'entreprise rendrait plus qu\'elle n\'a reçu.'
            );
        }

        return DB::transaction(function () use ($consignation, $quantiteRendue, $prix, $date) {
            $rembourse = round($quantiteRendue * $prix, 2);
            $consigne  = round($quantiteRendue * $consignation->prix_consigne, 2);
            $boni      = round($consigne - $rembourse, 2);

            $consignation->update([
                'quantite_rendue'   => round($consignation->quantite_rendue + $quantiteRendue, Consignation::DECIMALES),
                'montant_rembourse' => round($consignation->montant_rembourse + $rembourse, 2),
                'boni'              => round($consignation->boni + $boni, 2),
            ]);

            $consignation->refresh();

            // Tout est revenu : la consignation est close, et sa dette éteinte.
            if ($consignation->quantiteDehors() <= 0) {
                $consignation->update([
                    'statut'       => Consignation::RENDUE,
                    'date_cloture' => $date ?? now()->toDateString(),
                ]);
            }

            self::ecrireLaReprise($consignation, $consigne, $rembourse, $boni, $date);

            return $consignation->fresh();
        });
    }

    /**
     * Constater qu'un emballage ne reviendra pas.
     *
     * La consignation gardée cesse d'être une dette : elle devient un produit,
     * et le bilan cesse de porter une dette que personne ne réclamera.
     *
     * **Aucune facture n'est établie ici.** Le non-retour est une vente, soumise
     * à la TVA et à la certification de la plateforme : elle passe par l'écran
     * de vente ordinaire, dont la conformité est acquise et gelée.
     */
    public static function constaterLeNonRetour(Consignation $consignation, ?string $date = null): Consignation
    {
        if ($consignation->estClose()) {
            throw new \LogicException('Cette consignation est déjà close.');
        }

        return DB::transaction(function () use ($consignation, $date) {
            $perdu   = $consignation->quantiteDehors();
            $montant = round($perdu * $consignation->prix_consigne, 2);

            $consignation->update([
                'boni'         => round($consignation->boni + $montant, 2),
                'statut'       => Consignation::NON_RENDUE,
                'date_cloture' => $date ?? now()->toDateString(),
            ]);

            self::ecrireLeNonRetour($consignation->fresh(), $montant, $date);

            return $consignation->fresh();
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // LES ÉCRITURES
    // ─────────────────────────────────────────────────────────────────

    /**
     * La consignation : le tiers doit la somme, et l'entreprise la lui devra.
     *
     *     Client :      D 411xxx / C 419400
     *     Fournisseur : D 409400 / C 401xxx
     */
    private static function ecrireLaConsignation(Consignation $consignation): void
    {
        $compteTiers = self::compteTiers($consignation);

        if (!$compteTiers) {
            return;
        }

        $operation = self::operation($consignation, $consignation->date_consignation->toDateString(),
            'consignation', 'Consignation — ' . $consignation->designation);

        $libelle = 'Consignation — ' . $consignation->designation;

        if ($consignation->estAuClient()) {
            self::paire($operation, $consignation, $consignation->date_consignation->toDateString(),
                $libelle, $compteTiers, Consignation::COMPTE_DETTE, $consignation->montant);
        } else {
            self::paire($operation, $consignation, $consignation->date_consignation->toDateString(),
                $libelle, Consignation::COMPTE_CREANCE, $compteTiers, $consignation->montant);
        }

        $operation->cloturerEquilibre();
    }

    /**
     * La reprise : la dette s'éteint, et l'écart de prix reste à l'entreprise.
     *
     *     Client, au même prix  : D 419400 / C 411xxx
     *     Client, à prix réduit : D 419400 / C 411xxx + C 707400
     */
    private static function ecrireLaReprise(
        Consignation $consignation, float $consigne, float $rembourse, float $boni, ?string $date
    ): void {
        $compteTiers = self::compteTiers($consignation);

        if (!$compteTiers || $consigne <= 0) {
            return;
        }

        $date = $date ?? now()->toDateString();
        $libelle = 'Reprise d\'emballages — ' . $consignation->designation;

        $operation = self::operation($consignation, $date, 'reprise_consignation', $libelle);

        if ($consignation->estAuClient()) {
            self::ligne($operation, $consignation, $date, $libelle,
                Consignation::COMPTE_DETTE, null, $consigne, 0);

            if ($rembourse > 0) {
                self::ligne($operation, $consignation, $date, $libelle, null, $compteTiers, 0, $rembourse);
            }

            if ($boni > 0) {
                self::ligne($operation, $consignation, $date, $libelle . ' / boni',
                    null, Consignation::COMPTE_BONI, 0, $boni);
            }
        } else {
            // Chez le fournisseur, tout s'inverse : la créance s'éteint, et
            // l'écart est un **mali**, une charge.
            self::ligne($operation, $consignation, $date, $libelle,
                null, Consignation::COMPTE_CREANCE, 0, $consigne);

            if ($rembourse > 0) {
                self::ligne($operation, $consignation, $date, $libelle, $compteTiers, null, $rembourse, 0);
            }

            if ($boni > 0) {
                self::ligne($operation, $consignation, $date, $libelle . ' / mali',
                    Consignation::COMPTE_MALI, null, $boni, 0);
            }
        }

        $operation->cloturerEquilibre();
    }

    /**
     * Le non-retour : la dette devient un produit, ou la créance une charge.
     *
     *     Client :      D 419400 / C 707400 — un boni
     *     Fournisseur : D 622400 / C 409400 — un mali
     */
    private static function ecrireLeNonRetour(Consignation $consignation, float $montant, ?string $date): void
    {
        if ($montant <= 0) {
            return;
        }

        $date = $date ?? now()->toDateString();
        $libelle = 'Emballages non rendus — ' . $consignation->designation;

        $operation = self::operation($consignation, $date, 'non_retour_consignation', $libelle);

        if ($consignation->estAuClient()) {
            self::paire($operation, $consignation, $date, $libelle,
                Consignation::COMPTE_DETTE, Consignation::COMPTE_BONI, $montant);
        } else {
            self::paire($operation, $consignation, $date, $libelle,
                Consignation::COMPTE_MALI, Consignation::COMPTE_CREANCE, $montant);
        }

        $operation->cloturerEquilibre();
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * Le compte du tiers : celui de sa fiche, ou le compte collectif.
     */
    private static function compteTiers(Consignation $consignation): ?string
    {
        if ($consignation->estAuClient()) {
            return ImputationService::compteClient($consignation->client);
        }

        return ImputationService::compteFournisseur($consignation->fournisseur);
    }

    private static function operation(
        Consignation $consignation, string $date, string $type, string $libelle
    ): Operation {
        return Operation::creer(
            $consignation->entreprise_id,
            $consignation->point_de_vente_id,
            $date,
            $type,
            self::journal($consignation->entreprise_id),
            $consignation->reference_document ?: 'CONS-' . $consignation->id,
            mb_substr($libelle, 0, 190)
        );
    }

    /**
     * Une paire équilibrée, **deux lignes et non une**.
     *
     * C'est la convention du projet et celle de Comptaflow, dont le point
     * d'entrée retient `compte_debit` s'il est présent et ignore
     * `compte_credit`.
     */
    private static function paire(
        Operation $operation, Consignation $consignation, string $date,
        string $libelle, string $compteDebit, string $compteCredit, float $montant
    ): void {
        self::ligne($operation, $consignation, $date, $libelle, $compteDebit, null, $montant, 0);
        self::ligne($operation, $consignation, $date, $libelle, null, $compteCredit, 0, $montant);
    }

    private static function ligne(
        Operation $operation, Consignation $consignation, string $date, string $libelle,
        ?string $compteDebit, ?string $compteCredit, float $debit, float $credit
    ): void {
        EcritureComptable::create([
            'operation_id'       => $operation->id,
            'entreprise_id'      => $consignation->entreprise_id,
            'point_de_vente_id'  => $consignation->point_de_vente_id,
            'date_ecriture'      => $date,
            'libelle'            => mb_substr($libelle, 0, 190),
            'reference_document' => $consignation->reference_document ?: 'CONS-' . $consignation->id,
            'code_journal'       => self::journal($consignation->entreprise_id),
            'compte_debit'       => $compteDebit,
            'compte_credit'      => $compteCredit,
            'compte_tiers'       => $consignation->client?->numero_tiers
                ?? $consignation->fournisseur?->numero_tiers,
            'debit'              => $debit,
            'credit'             => $credit,
        ]);
    }

    private static function journal(int $entrepriseId): string
    {
        return \App\Modules\Admin\Modeles\CodeJournal::where('entreprise_id', $entrepriseId)
            ->where('type', 'OD')
            ->value('code') ?? 'OD';
    }

    // ─────────────────────────────────────────────────────────────────
    // CE QUE LES ÉCRANS DEMANDENT
    // ─────────────────────────────────────────────────────────────────

    /**
     * Ce qui dort chez les tiers : le nombre d'emballages et la somme en jeu.
     *
     * Un dépôt ne savait pas combien de casiers sont dehors, ni depuis quand,
     * ni chez qui.
     *
     * @return array{quantite: float, montant: float, en_retard: int}
     */
    public static function ceQuiEstDehors(int $entrepriseId, string $sens, ?int $pointDeVenteId = null): array
    {
        $query = Consignation::where('entreprise_id', $entrepriseId)
            ->where('sens', $sens)
            ->enCours()
            ->when($pointDeVenteId, fn ($q) => $q->where('point_de_vente_id', $pointDeVenteId));

        $lignes = (clone $query)->get();

        return [
            'quantite'  => round($lignes->sum(fn (Consignation $c) => $c->quantiteDehors()), Consignation::DECIMALES),
            'montant'   => round($lignes->sum(fn (Consignation $c) => $c->resteDu()), 2),
            'en_retard' => (clone $query)->enRetard()->count(),
        ];
    }
}

<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\ConfigurationCompte;
use App\Modules\Admin\Modeles\Produit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Garde les comptes des fiches produits et des écritures historiques alignés
 * sur la configuration de l'entreprise.
 *
 * Les montants débit/crédit ne sont jamais recalculés ni modifiés. Seuls les
 * numéros de comptes sont remplacés, dans la transaction de sauvegarde.
 */
class PropagationComptesService
{
    /**
     * Photographier les valeurs réellement utilisées avant une modification.
     */
    public static function capturer(int $entrepriseId): array
    {
        return [
            'configurations' => DB::table('configurations_comptes')
                ->where('entreprise_id', $entrepriseId)
                ->get()->keyBy(fn ($l) => $l->portee . ':' . $l->cle)->all(),
            'categories' => DB::table('categories')
                ->where('entreprise_id', $entrepriseId)
                ->get()->keyBy('id')->all(),
            'produits' => DB::table('produits')
                ->where('entreprise_id', $entrepriseId)
                ->get()->keyBy('id')->all(),
        ];
    }

    /**
     * Recopier les comptes hérités sur les fiches produits.
     *
     * Les champs de produit restent présents pour compatibilité avec les
     * écrans et les anciennes opérations, mais ne doivent plus conserver
     * indéfiniment une ancienne valeur après une modification de configuration.
     */
    public static function synchroniserProduits(int $entrepriseId): int
    {
        ConfigurationCompte::oublier();
        $nombre = 0;

        Produit::with('categorieRelation')
            ->where('entreprise_id', $entrepriseId)
            ->orderBy('id')
            ->chunkById(100, function ($produits) use (&$nombre) {
                foreach ($produits as $produit) {
                    // Neutraliser les anciennes valeurs article pendant le calcul :
                    // la sauvegarde de configuration doit recalculer l'héritage,
                    // puis le contrôleur réappliquera les exceptions explicitement
                    // saisies dans le formulaire article de cette même sauvegarde.
                    $anciensProduit = [
                        'compte_vente' => $produit->compte_vente,
                        'compte_achat' => $produit->compte_achat,
                        'compte_variation' => $produit->compte_variation,
                        'compte_stock' => $produit->compte_stock,
                    ];
                    $produit->compte_vente = null;
                    $produit->compte_achat = null;
                    $produit->compte_variation = null;
                    $produit->compte_stock = null;

                    $nouveaux = [
                        'compte_vente' => ImputationService::compteHerite($produit, 'compte_vente') ?: null,
                        'compte_achat' => ImputationService::compteHerite($produit, 'compte_achat') ?: null,
                        'compte_variation' => ImputationService::variationHeritee($produit),
                        'compte_stock' => $produit->estStockable()
                            ? (trim((string) ($produit->categorieRelation?->compte_stock ?? '')) ?: null)
                            : null,
                    ];

                    $aChanger = false;
                    foreach ($nouveaux as $champ => $valeur) {
                        if (trim((string) ($anciensProduit[$champ] ?? '')) !== trim((string) ($valeur ?? ''))) {
                            $aChanger = true;
                        }
                        $produit->$champ = $valeur;
                    }

                    if ($aChanger) {
                        $produit->save();
                        $nombre++;
                    }
                }
            });

        return $nombre;
    }

    /**
     * Mettre à jour les comptes historiques après synchronisation des produits.
     *
     * Si un ancien compte correspond désormais à plusieurs nouveaux comptes,
     * l'opération est refusée : les anciennes lignes ne portent pas le produit
     * source et les répartir serait une supposition. Le propriétaire doit alors
     * lever l'ambiguïté dans les configurations de type/catégorie/article.
     *
     * @return int Nombre de lignes historiques mises à jour.
     */
    public static function propagerEcritures(int $entrepriseId, array $avant): int
    {
        $apres = self::capturer($entrepriseId);
        $correspondances = [];
        $produitsApres = Produit::with('categorieRelation')
            ->where('entreprise_id', $entrepriseId)
            ->get()
            ->keyBy('id');

        foreach ($avant['produits'] as $id => $ancienProduit) {
            $nouveauProduit = $produitsApres->get($id);
            if (!$nouveauProduit) {
                continue;
            }

            $ancienneCategorie = $avant['categories'][$ancienProduit->categorie_id ?? 0] ?? null;
            foreach (['compte_vente', 'compte_achat', 'compte_variation', 'compte_stock'] as $champ) {
                $ancien = self::compteEffectifAvant($ancienProduit, $ancienneCategorie, $avant['configurations'], $champ);
                $nouveau = match ($champ) {
                    'compte_vente' => ImputationService::compteVente($nouveauProduit, $entrepriseId),
                    'compte_achat' => ImputationService::compteAchat($nouveauProduit, $entrepriseId),
                    'compte_variation' => ImputationService::compteVariation($nouveauProduit),
                    'compte_stock' => ImputationService::compteStock($nouveauProduit),
                    default => null,
                };
                self::ajouterCorrespondance($correspondances, $ancien, $nouveau);
            }
        }

        // Les comptes de configuration qui n'étaient portés par aucune fiche
        // produit restent également des comptes de référence à migrer.
        foreach ($avant['configurations'] as $cle => $ancienneLigne) {
            $nouvelleLigne = $apres['configurations'][$cle] ?? null;
            foreach (['compte_vente', 'compte_achat', 'compte_variation'] as $champ) {
                $nouveauCompte = $nouvelleLigne->$champ ?? null;
                if ($nouveauCompte !== null && trim((string) $nouveauCompte) !== '') {
                    self::ajouterCorrespondance(
                        $correspondances,
                        $ancienneLigne->$champ ?? null,
                        $nouveauCompte
                    );
                }
            }
        }

        foreach ($avant['categories'] as $id => $ancienneCategorie) {
            $nouvelleCategorie = $apres['categories'][$id] ?? null;
            foreach (['compte_stock', 'compte_variation'] as $champ) {
                $nouveauCompte = $nouvelleCategorie->$champ ?? null;
                if ($nouveauCompte !== null && trim((string) $nouveauCompte) !== '') {
                    self::ajouterCorrespondance(
                        $correspondances,
                        $ancienneCategorie->$champ ?? null,
                        $nouveauCompte
                    );
                }
            }
        }

        $total = 0;
        $remplacements = [];

        foreach ($correspondances as $ancien => $nouveaux) {
            if (count($nouveaux) > 1) {
                $destinations = implode(', ', array_keys($nouveaux));
                throw ValidationException::withMessages([
                    'config' => "Le compte {$ancien} mène à plusieurs nouveaux comptes ({$destinations}). "
                        . 'La modification est annulée pour éviter de réaffecter les anciennes écritures au mauvais compte.',
                ]);
            }

            $nouveau = array_key_first($nouveaux);
            $nombreAnciennes = DB::table('ecritures_comptables')
                ->where('entreprise_id', $entrepriseId)
                ->where(function ($q) use ($ancien) {
                    $q->where('compte_debit', $ancien)->orWhere('compte_credit', $ancien);
                })
                ->count();

            if ($nouveau === '__SANS_COMPTE__') {
                if ($nombreAnciennes > 0) {
                    throw ValidationException::withMessages([
                        'config' => "Le compte {$ancien} est utilisé par des écritures historiques. "
                            . 'Attribuez un compte de remplacement avant de supprimer cette configuration.',
                    ]);
                }
                continue;
            }

            if ($nouveau === $ancien) {
                continue;
            }

            $remplacements[] = [
                'ancien_numero' => $ancien,
                'nouveau_numero' => $nouveau,
            ];

            if ($nombreAnciennes === 0) {
                continue;
            }

            $debits = DB::table('ecritures_comptables')
                ->where('entreprise_id', $entrepriseId)
                ->where('compte_debit', $ancien)
                ->update(['compte_debit' => $nouveau]);

            $credits = DB::table('ecritures_comptables')
                ->where('entreprise_id', $entrepriseId)
                ->where('compte_credit', $ancien)
                ->update(['compte_credit' => $nouveau]);

            $total += $debits + $credits;
        }

        if ($total > 0) {
            Log::info('Propagation des comptes dans les écritures historiques', [
                'entreprise_id' => $entrepriseId,
                'lignes_mises_a_jour' => $total,
                'montants_modifies' => false,
            ]);
        }

        return ['lignes' => $total, 'remplacements' => $remplacements];
    }

    private static function ajouterCorrespondance(array &$map, $ancien, $nouveau): void
    {
        $ancien = trim((string) ($ancien ?? ''));
        if ($ancien === '') {
            return;
        }

        $nouveau = trim((string) ($nouveau ?? ''));
        $destination = $nouveau === '' ? '__SANS_COMPTE__' : $nouveau;
        $map[$ancien][$destination] = true;
    }

    /**
     * Résoudre le compte qui était effectivement appliqué avant la sauvegarde.
     */
    private static function compteEffectifAvant(object $produit, ?object $categorie, array $configurations, string $champ): ?string
    {
        $propre = trim((string) ($produit->$champ ?? ''));
        $famille = trim((string) ($categorie?->$champ ?? ''));

        if ($champ === 'compte_stock') {
            return $propre !== '' ? $propre : ($famille !== '' ? $famille : null);
        }

        if ($champ === 'compte_variation') {
            if ($propre !== '' && $propre !== $famille) {
                return $propre;
            }
        } else {
            $cleDefaut = $champ === 'compte_vente' ? 'vente_defaut' : 'achat_defaut';
            $defaut = trim((string) config("selflow.plan_comptable_defaut.{$cleDefaut}"));
            if ($propre !== '' && $propre !== $defaut && $propre !== $famille) {
                return $propre;
            }
        }

        $cles = [];
        if (!empty($produit->type)) {
            $cles[] = ConfigurationCompte::TYPE . ':' . $produit->type;
        }
        if (!empty($produit->categorie_id)) {
            $cles[] = ConfigurationCompte::CATEGORIE . ':' . $produit->categorie_id;
        }
        if ($champ !== 'compte_variation') {
            $cles[] = ConfigurationCompte::GENERAL . ':';
        }

        foreach ($cles as $cle) {
            $compte = trim((string) ($configurations[$cle]->$champ ?? ''));
            if ($compte !== '') {
                return $compte;
            }
        }

        if ($famille !== '') {
            return $famille;
        }

        if ($champ === 'compte_vente') {
            return trim((string) config('selflow.plan_comptable_defaut.vente_defaut')) ?: null;
        }
        if ($champ === 'compte_achat') {
            return trim((string) config('selflow.plan_comptable_defaut.achat_defaut')) ?: null;
        }

        return null;
    }
}

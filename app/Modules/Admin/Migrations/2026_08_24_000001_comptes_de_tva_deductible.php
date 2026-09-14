<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les comptes de TVA déductible par nature de charge.
 *
 * Symétrique de la migration des taxes collectées, et pour la même raison :
 * toute la TVA déductible partait en `445200`, « TVA récupérable sur achats »,
 * y compris celle d'un loyer, d'honoraires ou d'un billet de transport.
 * SYSCOHADA distingue quatre comptes, et l'état de TVA déductible reprend cette
 * distinction. Une entreprise qui n'achète que des marchandises ne voyait pas
 * la différence ; un cabinet, dont l'essentiel des charges est en 62 et 63, la
 * voyait entièrement.
 *
 * Ils sont posés dans le plan de chaque entreprise existante. Une entreprise
 * créée après cette migration les reçoit par le trousseau, qui les lit
 * désormais dans le référentiel.
 *
 * Rien n'est écrasé : un compte déjà présent — parce que l'entreprise l'a créé
 * elle-même, ou l'a renommé — est laissé tel quel.
 */
return new class extends Migration
{
    private const COMPTES = [
        '445100' => 'État, TVA récupérable sur immobilisations',
        '445300' => 'État, TVA récupérable sur transports',
        '445400' => 'État, TVA récupérable sur services extérieurs et autres charges',
    ];

    public function up(): void
    {
        $maintenant = now();

        foreach (DB::table('entreprises')->pluck('id') as $entrepriseId) {
            $existants = DB::table('plan_comptable')
                ->where('entreprise_id', $entrepriseId)
                ->whereIn('numero', array_keys(self::COMPTES))
                ->pluck('numero')
                ->all();

            $aPoser = [];
            foreach (self::COMPTES as $numero => $libelle) {
                // Clés numériques transtypées en entier par PHP : comparer en
                // chaîne, sans quoi la garde n'écarte jamais un compte présent.
                if (in_array((string) $numero, $existants, true)) {
                    continue;
                }

                $aPoser[] = [
                    'entreprise_id' => $entrepriseId,
                    'numero'        => $numero,
                    'libelle'       => $libelle,
                    'created_at'    => $maintenant,
                    'updated_at'    => $maintenant,
                ];
            }

            if ($aPoser !== []) {
                DB::table('plan_comptable')->insert($aPoser);
            }
        }
    }

    /**
     * Le retour ne supprime rien : ces comptes portent des écritures dès le
     * premier achat de service. Voir la migration des taxes collectées.
     */
    public function down(): void
    {
    }
};

<?php

use App\Modules\Authentification\Regles\Habilitations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les habilitations mises à jour — 15/10/2026.
 *
 * Plusieurs écrans prennent leur propre droit (bons de livraison, B2B,
 * Fiscalité & DGI, stickers, consignations, immobilisations, paramètres).
 * Chacun est accordé à qui détenait le droit qui l'ouvrait jusque-là : personne
 * ne perd un écran. Rejouable : un droit déjà présent n'est pas ajouté deux fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('utilisateurs')->whereNotNull('habilitations')->orderBy('id')
            ->each(function ($utilisateur) {
                $actuelles = json_decode((string) $utilisateur->habilitations, true);
                if (! is_array($actuelles) || $actuelles === []) {
                    return;
                }

                $nouvelles = $actuelles;
                foreach (Habilitations::HERITES_DE as $droit => $anciens) {
                    if (array_intersect($anciens, $actuelles) && ! in_array($droit, $nouvelles, true)) {
                        $nouvelles[] = $droit;
                    }
                }

                if ($nouvelles !== $actuelles) {
                    DB::table('utilisateurs')->where('id', $utilisateur->id)
                        ->update(['habilitations' => json_encode(array_values($nouvelles))]);
                }
            });
    }

    public function down(): void
    {
        // Les droits ajoutés ne se retirent pas : ils ne donnent rien de plus
        // que ce que l'ancien droit ouvrait déjà.
    }
};

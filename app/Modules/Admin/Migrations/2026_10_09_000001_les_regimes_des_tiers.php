<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les régimes des tiers rejoignent ceux de l'entreprise (recette du 08/10/2026).
 *
 * Les formulaires clients et fournisseurs proposaient leur propre liste :
 * « TEE (Taxe sur l'Entreprise Employeuse) », « RS (Régime Simplifié) »,
 * « RSI », « RNI », « Exonéré ». Ils lisent désormais
 * `Entreprise::REGIMES_IMPOSITION`. Ce qui a été enregistré sous l'ancienne
 * liste se range sous le code qu'il désignait :
 *
 * | Ancien | Nouveau |
 * |---|---|
 * | `RS`, « Réel simplifié » | `RSI` |
 * | « Réel normal » | `RNI` |
 * | « Micro-entreprise » | `RME` |
 * | un code en minuscules (`rsi`) | le même en majuscules |
 * | une chaîne vide | rien |
 *
 * « Exonéré » n'a pas d'équivalent : la fiche le garde, et le formulaire
 * l'accepte tant qu'on n'y touche pas (`Entreprise::regimesAcceptesPourTiers()`).
 * Rien n'est défait au retour arrière : on ne sait plus ce qui était « RS ».
 */
return new class extends Migration
{
    private const CODES = ['TEE', 'TCE', 'RME', 'RNE', 'RSI', 'RNI'];

    private const ANCIENS = [
        'RS'               => 'RSI',
        'RÉEL SIMPLIFIÉ'   => 'RSI',
        'REEL SIMPLIFIE'   => 'RSI',
        'RÉEL NORMAL'      => 'RNI',
        'REEL NORMAL'      => 'RNI',
        'MICRO-ENTREPRISE' => 'RME',
        'MICROENTREPRISE'  => 'RME',
    ];

    public function up(): void
    {
        foreach (['clients', 'fournisseurs'] as $table) {
            $valeurs = DB::table($table)->whereNotNull('regime_imposition')->distinct()->pluck('regime_imposition');

            foreach ($valeurs as $valeur) {
                $majuscules = mb_strtoupper(trim((string) $valeur));

                $nouveau = match (true) {
                    $majuscules === ''                      => null,
                    in_array($majuscules, self::CODES, true) => $majuscules,
                    isset(self::ANCIENS[$majuscules])        => self::ANCIENS[$majuscules],
                    default                                  => $valeur,
                };

                if ($nouveau !== $valeur) {
                    DB::table($table)->where('regime_imposition', $valeur)->update(['regime_imposition' => $nouveau]);
                }
            }
        }
    }

    public function down(): void
    {
    }
};

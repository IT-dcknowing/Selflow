<?php

namespace App\Modules\Admin\Services;

use App\Jobs\MettreEnFileDeversementComptaflow;
use App\Modules\Admin\Modeles\DemandeExercicesAnterieurs;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Operation;

/**
 * Envoyer chez Comptaflow les écritures comptables déjà produites par Selflow.
 *
 * Le plan comptable, les journaux et les tiers sont détenus par Comptaflow et
 * sont reçus dans Selflow par RecevoirReferentielComptaflowService. Ce service
 * ne transmet aucun référentiel : il ne remet en file que les opérations
 * comptables non encore déversées.
 *
 * Une opération est un ensemble équilibré ; elle part d'un bloc, par
 * DeverserOperationComptaflow. Les opérations déjà reçues restent protégées
 * par leur clé d'idempotence.
 */
class DeversementHistoriqueService
{
    /**
     * @return array{success: bool, message: string, operations: int}
     */
    public static function lancer(Entreprise $entreprise): array
    {
        if ($entreprise->comptaflow_sync_status !== 'active' || !$entreprise->comptaflow_sync_key) {
            return [
                'success'    => false,
                'message'    => "La liaison Comptaflow n'est pas active. Demandez-la d'abord ; le déversement suivra.",
                'operations' => 0,
            ];
        }

        // Le référentiel appartient à Comptaflow et est reçu dans
        // Selflow par RecevoirReferentielComptaflowService. Cette commande
        // n'envoie que les opérations comptables.
        // ── Les opérations qui n'ont pas encore abouti ──

        // L'exercice en cours part sans rien demander ; un exercice antérieur
        // seulement s'il a été accordé (propriétaire, 08/10/2026).
        $aEnvoyer = self::nonDeversees($entreprise)
            ->where(fn ($q) => self::exercicesPermis($q, $entreprise));
        $nombreAEnvoyer = (clone $aEnvoyer)->count();

        // Un seul travail part de la requête HTTP. La préparation est elle-même
        // asynchrone et met les opérations en file par lots de 100.
        if ($nombreAEnvoyer > 0) {
            MettreEnFileDeversementComptaflow::dispatch($entreprise->id);
            self::noterLeDebut($entreprise);
        }

        // Ce qui ne partira pas, et pourquoi : une opération déséquilibrée
        // n'est pas un oubli, c'est une erreur de Selflow qu'il ne faut pas
        // exporter. La taire ferait chercher longtemps.
        $desequilibrees = Operation::where('entreprise_id', $entreprise->id)
            ->where('est_equilibree', false)
            ->count();

        $message = $nombreAEnvoyer === 0
            ? "Aucune nouvelle opération à mettre en file."
            : sprintf(
                "%d opération(s) sont à transférer. Leur mise en file et leur transfert se font en arrière-plan ; vous pouvez poursuivre votre travail.",
                $nombreAEnvoyer
            );

        $retenues = self::anneesAnterieuresNonDeversees($entreprise);
        $nonAccordees = array_values(array_diff($retenues, DemandeExercicesAnterieurs::anneesAccordees($entreprise->id)));
        if ($nonAccordees !== []) {
            $message .= ' Les exercices ' . implode(', ', $nonAccordees)
                . " ne partent pas : la comptabilité d'un exercice antérieur se demande, et l'administrateur de la plateforme l'accorde.";
        }

        if ($desequilibrees > 0) {
            $message .= sprintf(
                " %d opération(s) déséquilibrée(s) ne sont pas envoyées : les déverser porterait le déséquilibre chez Comptaflow.",
                $desequilibrees
            );
        }

        return [
            'success'    => true,
            'message'    => $message,
            'operations' => $nombreAEnvoyer,
            'etat' => self::etat($entreprise),
        ];
    }

    /**
     * Relance les opérations en échec. Les clés SELFLOW-* restent inchangées :
     * une écriture déjà reçue est reconnue par Comptaflow et n'est pas dupliquée.
     */
    public static function relancerEchecs(Entreprise $entreprise): array
    {
        if ($entreprise->comptaflow_sync_status !== 'active' || !$entreprise->comptaflow_sync_key) {
            return [
                'success' => false,
                'message' => "La liaison Comptaflow n'est pas active. Réactivez-la avant de relancer les opérations.",
                'operations' => 0,
            ];
        }

        $echecs = Operation::where('entreprise_id', $entreprise->id)
            ->where('est_equilibree', true)
            ->where(fn ($q) => self::exercicesPermis($q, $entreprise))
            ->whereHas('ecritures', fn ($q) => $q->withoutGlobalScopes()
                ->where('comptaflow_sync_status', 'failed'));
        $nombreEchecs = (clone $echecs)->count();

        if ($nombreEchecs > 0) {
            MettreEnFileDeversementComptaflow::dispatch($entreprise->id, 0, true);
            self::noterLeDebut($entreprise);
        }

        return [
            'success' => true,
            'message' => $nombreEchecs === 0
                ? 'Aucune opération en échec à relancer.'
                : $nombreEchecs . ' opération(s) en échec identifiées. Leur relance est mise en file en arrière-plan.',
            'operations' => $nombreEchecs,
            'etat' => self::etat($entreprise),
        ];
    }

    /** Les opérations équilibrées dont une ligne n'est pas encore passée. */
    private static function nonDeversees(Entreprise $entreprise)
    {
        return Operation::where('entreprise_id', $entreprise->id)
            ->where('est_equilibree', true)
            // Sans la période active : les écritures portent un filtre par
            // période, et « tout l'historique » se réduisait à la période
            // affichée à l'écran.
            ->whereHas('ecritures', fn ($q) => $q->withoutGlobalScopes()->where(function ($qs) {
                $qs->whereNull('comptaflow_sync_status')
                   ->orWhere('comptaflow_sync_status', '!=', 'synced');
            }));
    }

    /** L'exercice en cours, plus les exercices antérieurs accordés. */
    private static function exercicesPermis($requete, Entreprise $entreprise): void
    {
        $requete->where('date_operation', '>=', now()->startOfYear()->toDateString());

        foreach (DemandeExercicesAnterieurs::anneesAccordees($entreprise->id) as $annee) {
            // Borne haute exclusive : `date_operation` porte une heure, et
            // « entre le 1er janvier et le 31 décembre » laissait dehors tout le
            // 31 décembre passé minuit.
            $requete->orWhere(fn ($q) => $q
                ->where('date_operation', '>=', "{$annee}-01-01")
                ->where('date_operation', '<', ($annee + 1) . '-01-01'));
        }
    }

    /**
     * Les exercices antérieurs à l'exercice en cours qui portent encore des
     * opérations à déverser — ceux qu'une entreprise peut demander.
     *
     * @return array<int, int>
     */
    public static function anneesAnterieuresNonDeversees(Entreprise $entreprise): array
    {
        return self::nonDeversees($entreprise)
            ->where('date_operation', '<', now()->startOfYear()->toDateString())
            ->distinct()->pluck('date_operation')
            ->map(fn ($d) => (int) \Illuminate\Support\Carbon::parse($d)->year)
            ->unique()->sort()->values()->all();
    }

    /**
     * État réel du transfert, calculé depuis les statuts des écritures.
     * Une opération est reçue uniquement lorsque toutes ses lignes sont synced.
     *
     * @return array{total:int, recues:int, restantes:int, en_cours:int, en_echec:int, progression:int, erreurs:array}
     */
    public static function etat(Entreprise $entreprise): array
    {
        $operations = Operation::where('entreprise_id', $entreprise->id)
            ->where('est_equilibree', true)
            ->where(fn ($q) => self::exercicesPermis($q, $entreprise))
            ->whereHas('ecritures', fn ($q) => $q->withoutGlobalScopes());

        $total = (clone $operations)->count();

        $recues = (clone $operations)->whereDoesntHave('ecritures', function ($q) {
            $q->withoutGlobalScopes()->where(function ($qs) {
                $qs->whereNull('comptaflow_sync_status')
                   ->orWhere('comptaflow_sync_status', '!=', 'synced');
            });
        })->count();

        $enEchec = (clone $operations)->whereHas('ecritures', fn ($q) =>
            $q->withoutGlobalScopes()->where('comptaflow_sync_status', 'failed')
        )->count();

        $enCours = (clone $operations)->whereHas('ecritures', fn ($q) =>
            $q->withoutGlobalScopes()->whereIn('comptaflow_sync_status', ['pending', 'sending'])
        )->count();

        $restantes = max(0, $total - $recues);
        $erreurs = EcritureComptable::withoutGlobalScopes()
            ->where('entreprise_id', $entreprise->id)
            ->where('comptaflow_sync_status', 'failed')
            ->whereIn('operation_id', (clone $operations)->select('id'))
            ->orderByDesc('updated_at')
            ->get(['operation_id', 'comptaflow_sync_error'])
            ->groupBy('operation_id')
            ->take(10)
            ->map(fn ($lignes, $operationId) => [
                'operation_id' => (int) $operationId,
                'motif' => $lignes->pluck('comptaflow_sync_error')->filter()->unique()->implode(' ; ')
                    // Pas de motif : un échec de l'ancien envoi ligne à ligne, qui
                    // n'en notait aucun. Ce n'est pas Comptaflow qui s'est tu.
                    ?: 'Ancien échec, sans motif enregistré. « Relancer les échecs » le renverra et notera la vraie raison.',
            ])->values()->all();

        // La file d'attente du serveur avance-t-elle ? Des envois en attente
        // depuis plus de 5 minutes : le planificateur (cron) ne tourne pas.
        $file = self::etatDeLaFile();

        return [
            'total' => $total,
            'recues' => $recues,
            'restantes' => $restantes,
            'en_cours' => $enCours,
            'en_echec' => $enEchec,
            'progression' => $total > 0 ? (int) floor(($recues / $total) * 100) : 100,
            'erreurs' => $erreurs,
            'file' => $file,
            'chrono' => self::chrono($entreprise, $recues, $restantes, $enCours),
            'reprise_auto' => self::reprendreLesOrphelines($entreprise, $enCours, $file),
        ];
    }

    /**
     * Des lignes « en cours » sans aucun travail dans la file (24/11/2026) :
     * leur envoi a été perdu (verrou resté, file vidée, processus tué). Rien
     * ne les aurait jamais reprises. Elles sont remises en file d'elles-mêmes,
     * au plus une fois toutes les 3 minutes.
     */
    private static function reprendreLesOrphelines(Entreprise $entreprise, int $enCours, array $file): bool
    {
        if ($enCours === 0 || ($file['en_attente'] ?? 0) > 0 || config('queue.default') !== 'database'
            || $entreprise->comptaflow_sync_status !== 'active' || !$entreprise->comptaflow_sync_key) {
            return false;
        }
        if (! \Illuminate\Support\Facades\Cache::add('deversement_reprise_' . $entreprise->id, now()->timestamp, 180)) {
            return false;
        }

        MettreEnFileDeversementComptaflow::dispatch($entreprise->id);
        self::noterLeDebut($entreprise, false);
        \App\Support\Journaux::deversement($entreprise)->info('Opérations « en cours » sans travail en file : remises en file', ['entreprise_id' => $entreprise->id, 'en_cours' => $enCours]);

        return true;
    }

    /** Le départ du transfert, pour le minuteur (24/11/2026). */
    private static function noterLeDebut(Entreprise $entreprise, bool $forcer = true): void
    {
        $cle = 'deversement_debut_' . $entreprise->id;
        if ($forcer || ! \Illuminate\Support\Facades\Cache::has($cle)) {
            \Illuminate\Support\Facades\Cache::put($cle, [
                'debut' => now()->timestamp,
                'recues' => (clone self::requeteRecues($entreprise))->count(),
            ], now()->addDay());
        }
    }

    /**
     * Temps écoulé depuis le départ, cadence mesurée et temps restant estimé.
     * Sans mesure encore (rien reçu depuis le départ), 4 s par opération :
     * l'envoi et son justificatif PDF, d'après le journal du 24/11/2026.
     */
    private static function chrono(Entreprise $entreprise, int $recues, int $restantes, int $enCours): ?array
    {
        $depart = \Illuminate\Support\Facades\Cache::get('deversement_debut_' . $entreprise->id);
        // Plus rien en cours (tout reçu, ou le reste en échec) : le minuteur
        // s'arrête (24/11/2026 : il comptait alors que tout avait échoué).
        if (! is_array($depart) || $enCours === 0) {
            if ($enCours === 0) {
                \Illuminate\Support\Facades\Cache::forget('deversement_debut_' . $entreprise->id);
            }

            return null;
        }
        $ecoule = max(1, now()->timestamp - (int) $depart['debut']);
        $faites = max(0, $recues - (int) $depart['recues']);
        $parOperation = $faites > 0 ? $ecoule / $faites : 4.0;

        return [
            'debut' => (int) $depart['debut'],
            'ecoule' => $ecoule,
            'faites' => $faites,
            'secondes_par_operation' => round($parOperation, 1),
            'reste_estime' => (int) ceil($restantes * $parOperation),
        ];
    }

    private static function requeteRecues(Entreprise $entreprise)
    {
        return Operation::where('entreprise_id', $entreprise->id)
            ->where('est_equilibree', true)
            ->whereHas('ecritures', fn ($q) => $q->withoutGlobalScopes())
            ->whereDoesntHave('ecritures', function ($q) {
                $q->withoutGlobalScopes()->where(fn ($qs) => $qs->whereNull('comptaflow_sync_status')->orWhere('comptaflow_sync_status', '!=', 'synced'));
            });
    }

    /** @return array{en_attente:int, plus_ancienne_minutes:?int, bloquee:bool} */
    public static function etatDeLaFile(): array
    {
        try {
            if (config('queue.default') !== 'database' || ! \App\Support\Structure::aTable('jobs')) {
                return ['en_attente' => 0, 'plus_ancienne_minutes' => null, 'bloquee' => false];
            }
            $nombre = \Illuminate\Support\Facades\DB::table('jobs')->count();
            $plusAncienne = \Illuminate\Support\Facades\DB::table('jobs')->min('created_at');
            $minutes = $plusAncienne ? (int) floor((time() - (int) $plusAncienne) / 60) : null;

            return ['en_attente' => $nombre, 'plus_ancienne_minutes' => $minutes, 'bloquee' => $nombre > 0 && $minutes !== null && $minutes >= 5];
        } catch (\Throwable) {
            return ['en_attente' => 0, 'plus_ancienne_minutes' => null, 'bloquee' => false];
        }
    }

    /**
     * Ce qui reste à déverser, pour l'écran.
     *
     * @return array{operations: int, lignes: int, en_echec: int}
     */
    public static function reste(Entreprise $entreprise): array
    {
        $lignes = EcritureComptable::withoutGlobalScopes()->where('entreprise_id', $entreprise->id)
            ->where(function ($q) {
                $q->whereNull('comptaflow_sync_status')
                  ->orWhere('comptaflow_sync_status', '!=', 'synced');
            });

        $etat = self::etat($entreprise);

        return [
            'operations' => $etat['restantes'],
            'lignes'     => (clone $lignes)->count(),
            'en_echec'   => $etat['en_echec'],
        ];
    }
}

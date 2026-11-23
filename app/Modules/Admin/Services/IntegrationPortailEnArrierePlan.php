<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Entreprise;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * « Tout intégrer » sans tenir la page ouverte (23/11/2026).
 *
 * La boucle tournait dans le navigateur : quitter la page, changer
 * d'entreprise ou revenir au superadmin l'arrêtait, et la page restait
 * occupée tant qu'elle tournait. La demande est maintenant notée ici ; le
 * planificateur du serveur (chaque minute) l'exécute par lots, et la page,
 * quand on y revient, affiche où en est le travail.
 */
class IntegrationPortailEnArrierePlan
{
    private const CLE = 'integration_portail_';
    private const DEMANDES = 'integration_portail_demandes';

    public function __construct(private IntegrationFacturePortailService $service)
    {
    }

    public static function etat(int $entrepriseId): ?array
    {
        return Cache::get(self::CLE . $entrepriseId);
    }

    public function demander(Entreprise $entreprise, int $utilisateurId, bool $reconnus): array
    {
        $etat = self::etat($entreprise->id);
        if ($etat && in_array($etat['etat'], ['en_attente', 'en_cours'], true)) {
            return $etat;
        }

        $etat = [
            'etat' => 'en_attente', 'reconnus' => $reconnus, 'utilisateur' => $utilisateurId,
            'faites' => 0, 'a_la_main' => [], 'restantes' => $this->candidates($entreprise, [], $reconnus)->count(),
            'demande_le' => now()->toIso8601String(), 'maj_le' => now()->toIso8601String(),
        ];
        Cache::put(self::CLE . $entreprise->id, $etat, now()->addDay());
        Cache::forever(self::DEMANDES, array_values(array_unique(array_merge((array) Cache::get(self::DEMANDES, []), [$entreprise->id]))));

        return $etat;
    }

    /** Un lot de 20 pour une entreprise ; rend l'état à jour. */
    public function lot(Entreprise $entreprise, int $taille = 20): ?array
    {
        $etat = self::etat($entreprise->id);
        if (! $etat || $etat['etat'] === 'termine') {
            return $etat;
        }

        $etat['etat'] = 'en_cours';
        foreach ($this->candidates($entreprise, $etat['a_la_main'], $etat['reconnus'])->take($taille) as $facture) {
            try {
                $this->service->integrer($facture, $entreprise, (int) $etat['utilisateur']);
                $etat['faites']++;
            } catch (\DomainException) {
                $etat['a_la_main'][] = $facture->id;
            } catch (\Throwable $e) {
                Log::error('[portail FNE] intégration impossible de ' . $facture->reference . ' : ' . $e->getMessage());
                $etat['a_la_main'][] = $facture->id;
            }
        }

        $etat['restantes'] = $this->candidates($entreprise, $etat['a_la_main'], $etat['reconnus'])->count();
        $etat['maj_le'] = now()->toIso8601String();
        if ($etat['restantes'] === 0) {
            $etat['etat'] = 'termine';
            Cache::forever(self::DEMANDES, array_values(array_diff((array) Cache::get(self::DEMANDES, []), [$entreprise->id])));
        }
        Cache::put(self::CLE . $entreprise->id, $etat, now()->addDay());

        return $etat;
    }

    /** Le passage du planificateur : toutes les demandes, pendant au plus ~25 s (le serveur reste disponible pour les pages). */
    public function traiterLesDemandes(int $secondes = 25): void
    {
        $fin = microtime(true) + $secondes;
        foreach ((array) Cache::get(self::DEMANDES, []) as $id) {
            $entreprise = Entreprise::find($id);
            if (! $entreprise) {
                continue;
            }
            do {
                $etat = $this->lot($entreprise);
            } while ($etat && $etat['etat'] !== 'termine' && microtime(true) < $fin);
            if (microtime(true) >= $fin) {
                return;
            }
        }
    }

    public static function oublier(int $entrepriseId): void
    {
        Cache::forget(self::CLE . $entrepriseId);
    }

    private function candidates(Entreprise $entreprise, array $ecartees, bool $reconnus)
    {
        return $this->service->enAttente($entreprise)
            ->reject(fn ($f) => in_array($f->id, $ecartees, true))
            ->when($reconnus, fn ($c) => $c->filter(fn ($f) => $this->service->tiersReconnuParNcc($entreprise, $f)))
            ->values();
    }
}

<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Authentification\Modeles\Utilisateur;
use App\Notifications\PortailFneRecuperationNotification;

class PortailFneNotificationService
{
    /**
     * Notifie les administrateurs de chaque entreprise réellement concernée
     * par un nouveau relevé, une mise à jour ou une erreur d'import.
     */
    public static function notifier(array $rapport, string $type): void
    {
        $details = collect($rapport['details'] ?? []);
        if ($details->isEmpty()) return;

        $ids = $details->pluck('import_id')->filter()->unique()->values();
        if ($ids->isEmpty()) return;

        $imports = PortailFneImport::query()
            ->whereIn('id', $ids)
            ->whereNotNull('entreprise_id')
            ->get(['id', 'entreprise_id'])
            ->keyBy('id');

        foreach ($imports->groupBy('entreprise_id') as $entrepriseId => $importsEntreprise) {
            $idsEntreprise = $importsEntreprise->keys();
            $detailsEntreprise = $details->filter(fn ($ligne) => in_array($ligne['import_id'] ?? null, $idsEntreprise->all(), true));

            // Les fichiers déjà connus ne génèrent pas de notification répétitive
            // à chaque passage du planificateur.
            if (!$detailsEntreprise->contains(fn ($ligne) => in_array($ligne['statut'] ?? '', ['importe', 'inchange', 'erreur'], true))) {
                continue;
            }

            $rapportNotification = [
                'type_recuperation' => $type,
                'fichiers' => $detailsEntreprise->count(),
                'documents' => (int) $detailsEntreprise->sum(fn ($ligne) => (int) ($ligne['lignes'] ?? 0)),
                'importes' => $detailsEntreprise->where('statut', 'importe')->count(),
                'inchanges' => $detailsEntreprise->where('statut', 'inchange')->count(),
                'ignores' => $detailsEntreprise->where('statut', 'ignore')->count(),
                'erreurs' => $detailsEntreprise->where('statut', 'erreur')->count(),
                'recuperee_le' => now()->toIso8601String(),
            ];

            Utilisateur::query()
                ->where('entreprise_id', $entrepriseId)
                ->where('role', 'admin')
                ->get()
                ->each(fn ($admin) => $admin->notify(new PortailFneRecuperationNotification($rapportNotification)));
        }
    }
}

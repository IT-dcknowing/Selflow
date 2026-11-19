<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Modeles\Entreprise;
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
            if (!$detailsEntreprise->contains(fn ($ligne) => in_array($ligne['statut'] ?? '', ['importe', 'erreur'], true))) {
                continue;
            }

            $nomEntreprise = Entreprise::whereKey($entrepriseId)->value('nom') ?? ('Entreprise #' . $entrepriseId);
            $rapportNotification = [
                'entreprise' => $nomEntreprise,
                'type_recuperation' => $type,
                'fichiers' => $detailsEntreprise->count(),
                'documents' => (int) $detailsEntreprise->sum(fn ($ligne) => (int) ($ligne['lignes'] ?? 0)),
                'importes' => (int) $detailsEntreprise->where('statut', 'importe')->sum(fn ($ligne) => (int) ($ligne['lignes'] ?? 0)),
                'inchanges' => (int) $detailsEntreprise->where('statut', 'inchange')->sum(fn ($ligne) => (int) ($ligne['lignes'] ?? 0)),
                'ignores' => (int) $detailsEntreprise->where('statut', 'ignore')->sum(fn ($ligne) => (int) ($ligne['lignes'] ?? 0)),
                'erreurs' => $detailsEntreprise->where('statut', 'erreur')->count(),
                'recuperee_le' => now()->toIso8601String(),
            ];

            if (str_starts_with($type, 'Avoirs')) {
                $avoirs = \App\Modules\Admin\Modeles\PortailFneFactureRecue::query()
                    ->whereIn('import_id', $idsEntreprise)
                    ->selectRaw('liste_portail, COUNT(*) as total')
                    ->groupBy('liste_portail')
                    ->pluck('total', 'liste_portail');
                $rapportNotification['avoirs_clients'] = (int) ($avoirs['issued'] ?? 0);
                $rapportNotification['avoirs_fournisseurs'] = (int) ($avoirs['received'] ?? 0);
            }

            Utilisateur::query()
                ->where(function ($q) use ($entrepriseId) {
                    $q->where(fn ($admin) => $admin->where('entreprise_id', $entrepriseId)->where('role', 'admin'))
                        ->orWhere('role', 'superadmin');
                })
                ->get()
                ->each(fn ($destinataire) => $destinataire->notify(new PortailFneRecuperationNotification($rapportNotification)));
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\Entreprise;
use Illuminate\Console\Command;

/**
 * Ramener des pièces d'achat rangées en BAPA vers les factures enregistrées
 * (20/10/2026). Une pièce déjà normalisée ne bouge pas : elle est certifiée.
 *
 *   php artisan selflow:requalifier-achats "poissonnerie LA GRACE" ACH-GUE-201026-001 ACH-191026-002 ACH-191026-001
 *   php artisan selflow:requalifier-achats "poissonnerie LA GRACE" --tous      # toutes ses pièces BAPA non normalisées
 *   … --appliquer pour écrire ; sans lui, on montre seulement.
 */
class RequalifierAchats extends Command
{
    protected $signature = 'selflow:requalifier-achats {entreprise} {numeros?*} {--tous} {--appliquer}';

    protected $description = 'Passe des achats BAPA non normalisés en factures d\'achat enregistrées.';

    public function handle(): int
    {
        $cle = (string) $this->argument('entreprise');
        $entreprise = ctype_digit($cle) ? Entreprise::find((int) $cle) : Entreprise::where('nom', $cle)->first();
        if (!$entreprise) {
            $this->error("Entreprise introuvable : {$cle}");

            return self::FAILURE;
        }

        $numeros = (array) $this->argument('numeros');
        if ($numeros === [] && !$this->option('tous')) {
            $this->error('Donnez des numéros de pièce, ou --tous.');

            return self::FAILURE;
        }

        $achats = Achat::withoutGlobalScopes()
            ->whereHas('pointDeVente', fn ($q) => $q->where('entreprise_id', $entreprise->id))
            ->when($numeros !== [], fn ($q) => $q->whereIn('numero_facture', $numeros))
            ->when($numeros === [], fn ($q) => $q->where('type_facture', 'bapa'))
            ->get();

        $lignes = [];
        foreach ($achats as $a) {
            $action = $a->normalise ? 'normalisée : ne bouge pas'
                : ($a->type_facture === 'bapa' ? 'BAPA → facture enregistrée' : 'déjà facture enregistrée');
            $lignes[] = [$a->numero_facture, (string) $a->type_facture ?: '—', $a->normalise ? 'oui' : 'non', $action];

            if ($this->option('appliquer') && !$a->normalise && $a->type_facture === 'bapa') {
                $a->forceFill(['type_facture' => 'normale'])->saveQuietly();
            }
        }
        $this->table(['Pièce', 'Type', 'Normalisée', 'Action'], $lignes);

        foreach (array_diff($numeros, $achats->pluck('numero_facture')->all()) as $absent) {
            $this->warn("Introuvable : {$absent}");
        }
        if (!$this->option('appliquer')) {
            $this->line('Rien n\'est écrit : relancez avec --appliquer.');
        }

        return self::SUCCESS;
    }
}

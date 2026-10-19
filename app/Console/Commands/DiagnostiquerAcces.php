<?php

namespace App\Console\Commands;

use App\Modules\Authentification\Modeles\Utilisateur;
use App\Modules\Authentification\Regles\Habilitations;
use Illuminate\Console\Command;

/**
 * Pourquoi un volet n'apparaît pas dans le menu d'un utilisateur — retour du
 * 19/10/2026 (« le volet Stock ne s'affiche pas »).
 *
 * Lecture seule. Le menu demande deux choses : que le module soit ouvert pour
 * l'entreprise, et que l'utilisateur en ait le droit. La commande dit
 * laquelle manque, volet par volet.
 *
 *   php artisan selflow:diagnostic-acces vendeur@exemple.ci
 */
class DiagnostiquerAcces extends Command
{
    protected $signature = 'selflow:diagnostic-acces {email : courriel de l\'utilisateur}';

    protected $description = 'Dit, volet par volet, si le module est ouvert et si l\'utilisateur en a le droit (lecture seule).';

    /** Le volet du menu, son module, et les droits qui l'affichent. */
    private const VOLETS = [
        'Ventes'       => ['ventes', ['nouvelle_vente', 'factures_vente']],
        'Achats'       => ['achats', ['nouvel_achat', 'factures_achat']],
        'Stock'        => ['stock', ['stock_articles', 'stock_mouvements']],
        'Production'   => ['production', ['production_recettes', 'production_ordres']],
    ];

    public function handle(): int
    {
        $utilisateur = Utilisateur::where('email', (string) $this->argument('email'))->first();
        if (!$utilisateur) {
            $this->error('Utilisateur introuvable.');

            return self::FAILURE;
        }

        $entreprise = $utilisateur->entreprise;
        $this->info("{$utilisateur->email} — rôle {$utilisateur->role} — entreprise " . ($entreprise?->nom ?? '—'));
        if (!$entreprise) {
            return self::SUCCESS;
        }

        $this->line('Modules actifs    : ' . implode(', ', (array) ($entreprise->modules_actifs ?? [])));
        $this->line('Modules autorisés : ' . implode(', ', $entreprise->modulesAutorises()));
        $this->line('Habilitations     : ' . ($utilisateur->estAdmin() ? 'toutes (administrateur)' : implode(', ', (array) ($utilisateur->habilitations ?? []))));

        $lignes = [];
        foreach (self::VOLETS as $volet => [$module, $droits]) {
            $ouvert = $entreprise->moduleEstActif($module);
            $aLeDroit = collect($droits)->contains(fn ($d) => $utilisateur->aHabilitation($d));
            $lignes[] = [
                $volet,
                $ouvert ? 'ouvert' : 'FERMÉ',
                $aLeDroit ? 'oui' : 'NON',
                $ouvert && $aLeDroit ? 'visible' : ($ouvert ? 'cocher un droit : ' . implode(' / ', $droits) : 'ouvrir le module « ' . $module . ' »'),
            ];
        }
        $this->table(['Volet', 'Module', 'Droit', 'Résultat'], $lignes);

        // Les droits cochés qui n'ouvrent rien, faute de module.
        $offerts = collect(Habilitations::pourLEntreprise($entreprise))->flatMap(fn ($d) => array_keys($d))->all();
        $orphelins = array_diff((array) ($utilisateur->habilitations ?? []), $offerts);
        if ($orphelins && !$utilisateur->estAdmin()) {
            $this->warn('Droits cochés sans effet (module fermé ou droit retiré) : ' . implode(', ', $orphelins));
        }

        return self::SUCCESS;
    }
}

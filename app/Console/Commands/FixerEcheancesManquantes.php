<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Regles\Abonnements;
use Illuminate\Console\Command;

/**
 * Poser l'échéance des entreprises qui n'en ont pas (25/11/2026, propriétaire).
 *
 *     php artisan selflow:fixer-echeances
 *     php artisan selflow:fixer-echeances --exclure=12,40 --appliquer
 *
 * La validation des inscriptions ne posait ni l'offre ni l'échéance. Règle
 * retenue : un mois à compter de la création de l'entreprise.
 * - Si ce mois n'est pas écoulé : échéance = création + 1 mois.
 * - S'il est écoulé : l'entreprise est à terme dès maintenant (bloquée).
 * Une offre « à choisir » devient Business (25 000 F/mois), sans toucher aux
 * quotas. Les inscriptions non validées sont laissées : leur échéance se pose
 * à la validation. Sans --appliquer, rien n'est écrit.
 */
class FixerEcheancesManquantes extends Command
{
    protected $signature = 'selflow:fixer-echeances
        {--exclure= : identifiants d\'entreprises à laisser de côté, séparés par des virgules}
        {--appliquer : écrire les échéances}';

    protected $description = 'Pose l\'échéance (création + 1 mois, ou à terme maintenant) des entreprises qui n\'en ont pas.';

    public function handle(): int
    {
        $exclues = array_filter(array_map('intval', explode(',', (string) $this->option('exclure'))));

        $entreprises = Entreprise::query()->horsCorbeille()
            ->whereNull('abonnement_expire_le')
            ->where('statut', '!=', 'en_attente')
            ->when($exclues, fn ($q) => $q->whereNotIn('id', $exclues))
            ->orderBy('created_at')
            ->get();

        $lignes = [];
        $plan = [];
        foreach ($entreprises as $e) {
            $finDuMois = ($e->created_at ?? now())->copy()->addMonthNoOverflow()->endOfDay();
            $aTerme = $finDuMois->isPast();
            $offre = in_array($e->plan_abonnement, [null, '', Abonnements::A_CHOISIR], true) ? Abonnements::BUSINESS : $e->plan_abonnement;
            $echeance = $aTerme ? now()->subMinute() : $finDuMois;
            $plan[] = [$e, $offre, $echeance, $aTerme];
            $lignes[] = [
                $e->id, mb_strimwidth($e->nom, 0, 32, '…'), $e->created_at?->format('d/m/Y'),
                Abonnements::libelle($e->plan_abonnement) . ($offre !== $e->plan_abonnement ? ' → ' . Abonnements::libelle($offre) : ''),
                $aTerme ? 'À TERME maintenant (bloquée)' : 'échéance le ' . $echeance->format('d/m/Y'),
            ];
        }

        $this->table(['#', 'Entreprise', 'Créée le', 'Offre', 'Effet'], $lignes);
        $nbTerme = collect($plan)->where(3, true)->count();
        $this->line(count($plan) . " entreprise(s) : " . (count($plan) - $nbTerme) . " avec une échéance à venir, {$nbTerme} à terme maintenant.");
        $enAttente = Entreprise::query()->horsCorbeille()->whereNull('abonnement_expire_le')->where('statut', 'en_attente')->count();
        if ($enAttente) {
            $this->line("{$enAttente} inscription(s) non validée(s) laissée(s) : leur échéance se pose à la validation.");
        }

        if (!$this->option('appliquer')) {
            $this->warn('Rien n\'est écrit. Relancez avec --appliquer (et --exclure=… pour en écarter).');

            return self::SUCCESS;
        }

        foreach ($plan as [$e, $offre, $echeance, $aTerme]) {
            $e->forceFill([
                'plan_abonnement'      => $offre,
                'abonnement_choisi_le' => $e->abonnement_choisi_le ?? now(),
                'abonnement_expire_le' => $echeance,
                'abonnement_statut'    => $aTerme ? Abonnements::STATUT_A_REGLER : Abonnements::STATUT_ACTIF,
            ])->save();
        }
        \Illuminate\Support\Facades\Log::info('[abonnements] échéances posées', ['entreprises' => collect($plan)->map(fn ($p) => $p[0]->id)->all()]);
        $this->info('Échéances posées.');

        return self::SUCCESS;
    }
}

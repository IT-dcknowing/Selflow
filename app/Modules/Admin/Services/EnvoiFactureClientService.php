<?php

namespace App\Modules\Admin\Services;

use App\Mail\FactureAuClient;
use App\Modules\Admin\Modeles\EspaceSurMesure;
use App\Modules\Admin\Modeles\Vente;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * La facture envoyée au client par e-mail (23/11/2026, Selflow sur mesure).
 *
 * Automatique quand le module « facture_email » de l'espace est actif : dès
 * l'émission, ou après la certification par la DGI, au choix du
 * superadministrateur. À la main, depuis la liste des factures, pour toute
 * entreprise. Ne touche à rien de la FNE : elle lit la pièce, elle ne
 * l'écrit pas (seule la date d'envoi est notée).
 */
class EnvoiFactureClientService
{
    /** La facture part-elle d'elle-même à ce moment-là ? */
    public static function automatique(Vente $vente, string $moment): bool
    {
        if ($vente->etape !== 'Facture' || ! $vente->client?->email) {
            return false;
        }
        $entrepriseId = (int) $vente->pointDeVente?->entreprise_id;
        $espace = $entrepriseId ? EspaceSurMesure::pourEntreprise($entrepriseId) : null;

        return $espace?->moduleActif('facture_email')
            && ($espace->facture_email_moment ?: 'emission') === $moment;
    }

    /**
     * Envoyer la facture. Rend [réussi, message pour l'écran].
     */
    public function envoyer(Vente $vente): array
    {
        $vente->loadMissing(['client', 'pointDeVente.entreprise']);
        $email = trim((string) $vente->client?->email);

        if ($vente->etape !== 'Facture') {
            return [false, 'Seule une facture s\'envoie au client.'];
        }
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [false, 'Le client n\'a pas d\'adresse e-mail valide sur sa fiche.'];
        }

        $entreprise = $vente->pointDeVente?->entreprise;
        $espace = $entreprise ? EspaceSurMesure::pourEntreprise((int) $entreprise->id) : null;

        try {
            $pdf = app(DocumentPdfService::class)->vente($vente);
            Mail::to($email)->send(new FactureAuClient(
                $vente,
                $pdf,
                (string) ($entreprise?->nom ?? 'Selflow'),
                $espace?->couleur_principale ?: '#002B5C',
            ));
        } catch (\Throwable $e) {
            Log::warning('Facture au client non envoyée', ['vente' => $vente->id, 'erreur' => $e->getMessage()]);

            return [false, 'L\'e-mail n\'a pas pu partir. Réessayez plus tard.'];
        }

        if (Schema::hasColumn('ventes', 'envoyee_au_client_le')) {
            // Écrit sans événement : la date d'envoi ne relance rien.
            Vente::withoutEvents(fn () => $vente->forceFill(['envoyee_au_client_le' => now()])->saveQuietly());
        }

        return [true, 'Facture envoyée à ' . $email . '.'];
    }

    /** L'envoi automatique, après la réponse : l'écran n'attend pas le courriel. */
    public static function programmer(Vente $vente, string $moment): void
    {
        if (Schema::hasColumn('ventes', 'envoyee_au_client_le') && $vente->envoyee_au_client_le) {
            return;
        }
        if (! self::automatique($vente, $moment)) {
            return;
        }

        $id = $vente->id;
        dispatch(function () use ($id) {
            $vente = Vente::withoutGlobalScopes()->find($id);
            if ($vente) {
                app(self::class)->envoyer($vente);
            }
        })->afterResponse();
    }
}

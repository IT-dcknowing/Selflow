<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * La seule porte d'envoi des courriels de compte.
 *
 * ── Pourquoi elle existe ──
 *
 * En production, le lien de réinitialisation n'arrivait jamais, et rien ne le
 * disait. Deux causes s'additionnaient : le transport par défaut de
 * `config/mail.php` est `log` — sans `MAIL_MAILER` dans le `.env`, le message
 * s'écrit dans un fichier et l'écran annonce « envoyé » ; et l'échec SMTP
 * était avalé par un `catch` qui affichait le lien à l'écran à la place, à
 * qui le demandait. N'importe qui pouvait ainsi changer le mot de passe de
 * n'importe quel compte en tapant son adresse.
 *
 * Ici, un courriel qui ne part pas **le dit** : `envoyer()` rend faux, et
 * l'appelant l'annonce à l'utilisateur au lieu de prétendre le contraire.
 */
class EnvoiCourriel
{
    /** Transports qui n'atteignent aucune boîte : le message reste sur le serveur. */
    public const TRANSPORTS_SANS_DESTINATAIRE = ['log'];

    /**
     * Le transport atteint-il réellement une boîte ? Faux quand il écrit dans
     * un journal. Le poste local compte comme configuré : le lien s'y affiche
     * à l'écran (et là seulement). `array` est celui des épreuves.
     */
    public static function transportReel(): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        $transport = config('mail.mailers.' . config('mail.default') . '.transport', config('mail.default'));

        return ! in_array($transport, self::TRANSPORTS_SANS_DESTINATAIRE, true);
    }

    /**
     * Envoie tout de suite, sans file : un lien de réinitialisation qui attend
     * qu'un `queue:work` tourne n'arrive pas, et l'écran ne peut rien en dire.
     */
    public static function envoyer(string $adresse, Mailable $message): bool
    {
        if (! self::transportReel()) {
            Log::error('Courriel non parti : aucun transport réel configuré (MAIL_MAILER = '
                . config('mail.default') . ').', ['destinataire' => $adresse, 'message' => $message::class]);

            return false;
        }

        try {
            Mail::to($adresse)->send($message);

            return true;
        } catch (\Throwable $e) {
            Log::error('Courriel non parti : ' . $e->getMessage(), [
                'destinataire' => $adresse,
                'message'      => $message::class,
            ]);

            return false;
        }
    }

    /**
     * Annonce l'ouverture d'un compte à son titulaire. `$creeParUnTiers` :
     * le mot de passe a été choisi par quelqu'un d'autre (responsable,
     * superadministrateur) — le message le dit, sans jamais le contenir.
     */
    public static function annoncerCompte(\App\Modules\Authentification\Modeles\Utilisateur $utilisateur, bool $creeParUnTiers): bool
    {
        return self::envoyer($utilisateur->email, new CompteSelflowOuvert($utilisateur, $creeParUnTiers));
    }

    /** Phrase à ajouter au message de l'écran quand l'avis n'est pas parti. */
    public const AVIS_NON_PARTI = ' Le courriel d\'avis n\'a pas pu partir : transmettez l\'identifiant vous-même.';
}

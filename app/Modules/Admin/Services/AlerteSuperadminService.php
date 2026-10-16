<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\ParametrePlateforme;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Les alertes de la plateforme vers DC-Knowing (retour du 16/10/2026).
 *
 * Un seul point d'envoi pour tout ce que le superadministrateur doit savoir
 * sans ouvrir l'application : une entreprise créée, une demande de stickers, un
 * paiement reçu, une panne, une attaque. Les destinataires (courriels et
 * numéros WhatsApp) se règlent dans Pannes & assistance ; ils ne sont plus en
 * dur dans le code.
 *
 * ── Courriel ──
 * Part à tous les courriels configurés, en file d'attente : un SMTP lent ne
 * ralentit pas l'action qui a déclenché l'alerte.
 *
 * ── WhatsApp ──
 * WhatsApp n'a pas d'envoi direct depuis un serveur : il faut un relais (API
 * WhatsApp Business de Meta, Twilio, ou un pont auto-hébergé). Le service POSTe
 * donc le message et les numéros à l'URL de relais configurée ; à défaut d'URL,
 * il journalise l'alerte plutôt que de la perdre. Brancher un relais est une
 * ligne de configuration, sans toucher au code.
 */
class AlerteSuperadminService
{
    /** Types d'alerte, pour le sujet et le filtrage éventuel. */
    public const CREATION_ENTREPRISE = 'creation_entreprise';
    public const DEMANDE_STICKERS    = 'demande_stickers';
    public const PAIEMENT            = 'paiement';
    public const PANNE               = 'panne';
    public const ATTAQUE             = 'attaque';

    /**
     * Les courriels qui reçoivent les alertes. À défaut de configuration, les
     * adresses du cabinet, pour ne jamais rester sans destinataire.
     *
     * @return array<int, string>
     */
    public static function courriels(): array
    {
        $saisis = self::lignes(ParametrePlateforme::lire(ParametrePlateforme::ALERTES_EMAILS));

        $defaut = ['meledjeagnimel17@gmail.com', 'it.dcknowing@gmail.com'];

        $courriels = array_values(array_unique(array_filter(
            $saisis !== [] ? $saisis : $defaut,
            fn ($c) => filter_var($c, FILTER_VALIDATE_EMAIL)
        )));

        return $courriels;
    }

    /** @return array<int, string> les numéros WhatsApp configurés. */
    public static function numerosWhatsapp(): array
    {
        return self::lignes(ParametrePlateforme::lire(ParametrePlateforme::ALERTES_WHATSAPP));
    }

    /**
     * Envoyer une alerte sur tous les canaux. Ne lève jamais : une alerte qui
     * échoue ne doit pas faire échouer l'action qui l'a déclenchée.
     */
    public static function alerter(string $type, string $sujet, string $corps): void
    {
        self::parCourriel($sujet, $corps);
        self::parWhatsapp($type, $sujet . "\n" . $corps);
    }

    private static function parCourriel(string $sujet, string $corps): void
    {
        $courriels = self::courriels();
        if ($courriels === []) {
            return;
        }

        try {
            Mail::raw($corps, function ($mail) use ($courriels, $sujet) {
                $mail->to($courriels)->subject('[Selflow] ' . $sujet);
            });
        } catch (\Throwable $e) {
            Log::warning('AlerteSuperadmin : courriel non envoyé — ' . $e->getMessage());
        }
    }

    private static function parWhatsapp(string $type, string $message): void
    {
        $numeros = self::numerosWhatsapp();
        if ($numeros === []) {
            return;
        }

        $webhook = ParametrePlateforme::lire(ParametrePlateforme::ALERTES_WHATSAPP_WEBHOOK);

        if (blank($webhook)) {
            // Pas de relais configuré : on garde trace plutôt que de perdre
            // l'alerte. Le message est lisible dans les journaux du serveur.
            Log::info('AlerteSuperadmin WhatsApp (aucun relais configuré)', [
                'type' => $type, 'numeros' => $numeros, 'message' => $message,
            ]);

            return;
        }

        try {
            Http::timeout(5)->post($webhook, [
                'type'    => $type,
                'numeros' => $numeros,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AlerteSuperadmin : relais WhatsApp injoignable — ' . $e->getMessage());
        }
    }

    /**
     * Découpe un champ multi-lignes en valeurs nettoyées.
     *
     * @return array<int, string>
     */
    private static function lignes(?string $valeur): array
    {
        if (blank($valeur)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $valeur) ?: [])));
    }
}

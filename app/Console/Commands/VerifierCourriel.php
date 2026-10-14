<?php

namespace App\Console\Commands;

use App\Mail\EnvoiCourriel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Dit si le serveur envoie réellement des courriels, et en envoie un d'essai.
 *
 * Le lien de réinitialisation n'arrivait pas en production : sans
 * `MAIL_MAILER` dans le `.env`, Laravel écrit les messages dans un journal.
 * Cette commande le montre en une ligne, avant qu'un utilisateur ne le
 * découvre.
 */
class VerifierCourriel extends Command
{
    protected $signature = 'selflow:verifier-courriel {adresse? : adresse qui recevra un message d\'essai}';

    protected $description = 'Affiche la configuration d\'envoi des courriels et envoie un message d\'essai';

    public function handle(): int
    {
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.$mailer.transport", $mailer);

        $this->table(['Réglage', 'Valeur'], [
            ['MAIL_MAILER', $mailer . ($transport !== $mailer ? " ($transport)" : '')],
            ['MAIL_HOST', (string) config("mail.mailers.$mailer.host", '—')],
            ['MAIL_PORT', (string) config("mail.mailers.$mailer.port", '—')],
            ['MAIL_USERNAME', config("mail.mailers.$mailer.username") ? 'renseigné' : 'vide'],
            ['MAIL_PASSWORD', config("mail.mailers.$mailer.password") ? 'renseigné' : 'vide'],
            ['MAIL_FROM_ADDRESS', (string) config('mail.from.address')],
            ['APP_URL (liens des courriels)', (string) config('app.url')],
        ]);

        $problemes = [];
        if (in_array($transport, EnvoiCourriel::TRANSPORTS_SANS_DESTINATAIRE, true)) {
            $problemes[] = "Le transport « $transport » n'envoie rien : renseignez MAIL_MAILER=smtp et les MAIL_* dans le .env.";
        }
        if (str_ends_with((string) config('mail.from.address'), '@example.com')) {
            $problemes[] = 'MAIL_FROM_ADDRESS vaut l\'adresse d\'exemple : les messages seront rejetés ou classés indésirables.';
        }
        if (str_contains((string) config('app.url'), 'localhost')) {
            $problemes[] = 'APP_URL pointe sur localhost : les liens des courriels ne s\'ouvriront pas.';
        }

        foreach ($problemes as $probleme) {
            $this->error($probleme);
        }

        $adresse = $this->argument('adresse');
        if (! $adresse) {
            $this->line('Ajoutez une adresse pour envoyer un message d\'essai : php artisan selflow:verifier-courriel vous@exemple.com');

            return $problemes ? self::FAILURE : self::SUCCESS;
        }

        try {
            Mail::raw('Message d\'essai envoyé par Selflow (' . config('app.url') . ') le ' . now()->format('d/m/Y à H:i') . '.', function ($m) use ($adresse) {
                $m->to($adresse)->subject('Selflow — essai d\'envoi');
            });
        } catch (\Throwable $e) {
            $this->error('Échec de l\'envoi : ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("Message remis au serveur d'envoi pour $adresse. Vérifiez la boîte (et les indésirables).");

        return $problemes ? self::FAILURE : self::SUCCESS;
    }
}

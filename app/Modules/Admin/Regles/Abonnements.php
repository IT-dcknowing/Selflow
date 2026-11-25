<?php

namespace App\Modules\Admin\Regles;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PaiementAbonnement;

/**
 * Les offres d'abonnement de Selflow — décision du propriétaire du 15/10/2026.
 *
 * Chaque offre vient avec ses contraintes par défaut. Elles évoluent en
 * changeant d'offre, ou parce que le superadministrateur les relève en
 * gardant la même offre.
 */
final class Abonnements
{
    /** Ce qui s'écrit « illimité » : un plafond qu'aucune entreprise n'atteint. */
    public const ILLIMITE = 9999;

    public const SOLO = 'SOLO';
    public const BUSINESS = 'BUSINESS';
    public const PREMIUM = 'PREMIUM';
    public const SUR_MESURE = 'SUR_MESURE';
    public const PASS_CADEAU = 'PASS_CADEAU';

    /** En attendant le choix : ce que reçoit une entreprise qui vient de naître. */
    public const A_CHOISIR = 'A_CHOISIR';

    /** Points de vente par défaut d'une entreprise créée sans offre (15/10/2026). */
    public const QUOTA_PAR_DEFAUT = 3;

    public const STATUT_ESSAI = 'essai';
    public const STATUT_A_REGLER = 'a_regler';
    public const STATUT_ACTIF = 'actif';
    public const STATUT_SUR_DEVIS = 'sur_devis';

    public const DUREE_PASS_CADEAU_JOURS = 7;

    /** Les durées proposées au choix d'une offre, en mois (25/11/2026). */
    public const DUREES_MOIS = [1, 2, 3, 6, 12];

    /**
     * Les messages proposés au superadministrateur (25/11/2026), à reprendre
     * tels quels ou à reformuler. {entreprise}, {echeance} et {jours} se
     * remplacent à l'affichage.
     *
     * @var array<string, string>
     */
    public const MESSAGES = [
        'expire_longtemps' => 'Votre abonnement Selflow est arrivé à échéance le {echeance}, il y a {jours} jours. Pour reprendre votre activité, renouvelez-le depuis Paramètres › Facturation, ou contactez l\'assistance DC-Knowing.',
        'expire'           => 'Votre abonnement Selflow est arrivé à échéance le {echeance}. Renouvelez-le depuis Paramètres › Facturation pour continuer à travailler. Vos données sont conservées.',
        'bientot'          => 'Votre abonnement Selflow arrive à échéance le {echeance}. Pensez à le renouveler dès maintenant pour éviter toute interruption.',
        'reglement'        => 'Nous n\'avons pas encore reçu le règlement de votre abonnement. Merci de régulariser depuis Paramètres › Facturation, ou de contacter l\'assistance DC-Knowing.',
        'essai'            => 'Votre période d\'essai est terminée. Choisissez votre offre depuis Paramètres › Facturation pour continuer à utiliser Selflow.',
        'bienvenue'        => 'Bienvenue sur Selflow ! Votre abonnement est actif jusqu\'au {echeance}.',
    ];

    public const LIBELLES_MESSAGES = [
        'expire_longtemps' => 'Abonnement expiré depuis un bon moment',
        'expire'           => 'Abonnement expiré',
        'bientot'          => 'Échéance proche',
        'reglement'        => 'Règlement non reçu',
        'essai'            => 'Fin de l\'essai',
        'bienvenue'        => 'Bienvenue',
    ];

    /** Le message du superadministrateur, prêt à afficher, ou null. */
    public static function messageDe(?Entreprise $entreprise): ?string
    {
        $texte = trim((string) ($entreprise?->message_abonnement ?? ''));

        return $texte === '' ? null : self::remplir($entreprise, $texte);
    }

    /**
     * L'inverse de remplir() (25/11/2026) : la date d'échéance et « il y a
     * N jours » redeviennent des variables, pour que le message reste juste
     * les jours suivants. Le superadministrateur voit les dates ; on garde
     * le modèle.
     */
    public static function versModele(Entreprise $entreprise, string $texte): string
    {
        $echeance = $entreprise->abonnement_expire_le;
        if ($echeance) {
            $texte = str_replace($echeance->format('d/m/Y'), '{echeance}', $texte);
        }
        $texte = preg_replace('/\bil y a \d+ jours?\b/u', 'il y a {jours} jours', $texte) ?? $texte;

        return str_replace((string) $entreprise->nom, '{entreprise}', $texte);
    }

    /**
     * Remplace {entreprise}, {echeance} et {jours} par les valeurs de
     * l'entreprise (25/11/2026) : le superadministrateur voit et enregistre
     * le message tel que l'entreprise le lira, dates comprises.
     */
    public static function remplir(Entreprise $entreprise, string $texte): string
    {
        $echeance = $entreprise->abonnement_expire_le;

        return strtr($texte, [
            '{entreprise}' => (string) $entreprise->nom,
            '{echeance}'   => $echeance ? $echeance->format('d/m/Y') : 'non fixée',
            '{jours}'      => $echeance ? (string) max(0, (int) $echeance->copy()->startOfDay()->diffInDays(now()->startOfDay(), false)) : '0',
        ]);
    }

    /**
     * À la validation d'une inscription (25/11/2026) : l'offre demandée
     * s'applique et l'échéance se calcule sur le nombre de mois choisi. Elle
     * n'était jamais posée : 61 entreprises restaient sans échéance.
     *
     * @return int le nombre de mois accordés (0 si rien n'est payant)
     */
    public static function activerALaValidation(Entreprise $entreprise): int
    {
        $offre = (string) ($entreprise->abonnement_demande ?: $entreprise->plan_abonnement);
        if (!array_key_exists($offre, self::offres()) || $entreprise->abonnement_expire_le !== null) {
            return 0;
        }

        if (!in_array($offre, [self::SOLO, self::BUSINESS, self::PREMIUM], true)) {
            // Pass cadeau : ses 7 jours ; sur mesure : sur devis.
            self::appliquer($entreprise, $offre);

            return 0;
        }

        $mois = max(1, min(24, (int) ($entreprise->abonnement_demande_mois ?? 1)));
        self::appliquer($entreprise, $offre);
        $entreprise->refresh()->forceFill([
            'abonnement_statut'     => self::STATUT_ACTIF,
            'abonnement_expire_le'  => now()->addMonthsNoOverflow($mois)->endOfDay(),
            'abonnement_demande'    => null,
            'abonnement_demande_le' => null,
        ])->save();

        PaiementAbonnement::create([
            'entreprise_id'      => $entreprise->id,
            'utilisateur_id'     => null,
            'offre'              => $offre,
            'nature'             => 'souscription',
            'mois'               => $mois,
            'montant'            => (int) self::offres()[$offre]['prix'] * $mois,
            'operateur_paiement' => 'superadmin',
            'reference_paiement' => 'INSCRIPTION-' . $entreprise->id . '-' . now()->format('YmdHis'),
            'statut'             => 'active',
            'active_le'          => now(),
            'traite_par'         => auth()->id(),
        ] + (PaiementAbonnement::colonnesEnPlace() ? ['bloquant' => false, 'confirmation_vue_le' => now()] : []));

        return $mois;
    }

    /**
     * @return array<string, array{nom: string, points_de_vente: ?int, utilisateurs: ?int, prix: ?int, accroche: string, details: array<int, string>}>
     */
    public static function offres(): array
    {
        return [
            self::SOLO => [
                'nom' => 'Solo', 'points_de_vente' => 1, 'utilisateurs' => 1, 'prix' => 10000,
                'accroche' => 'Pour celui qui tient seul son commerce.',
                'details' => ['1 point de vente', '1 utilisateur : le gérant', 'Ventes, achats, stock, facturation', 'Normalisation DGI'],
            ],
            self::BUSINESS => [
                'nom' => 'Business', 'points_de_vente' => 3, 'utilisateurs' => 3, 'prix' => 25000,
                'accroche' => 'Pour une équipe et plusieurs boutiques.',
                'details' => ['3 points de vente', '3 utilisateurs', 'Ventes, achats, stock, facturation', 'Normalisation DGI, B2B'],
            ],
            self::PREMIUM => [
                'nom' => 'Premium', 'points_de_vente' => self::ILLIMITE, 'utilisateurs' => self::ILLIMITE, 'prix' => 45000,
                'accroche' => 'Sans limite de sites ni d\'utilisateurs.',
                'details' => ['Points de vente illimités', 'Utilisateurs illimités', 'Tous les modules', 'Normalisation DGI, B2B'],
            ],
            self::SUR_MESURE => [
                'nom' => 'Sur mesure', 'points_de_vente' => null, 'utilisateurs' => null, 'prix' => null,
                'accroche' => 'Un besoin particulier ? Parlons-en.',
                'details' => ['Volumes et modules à la demande', 'Accompagnement dédié', 'Contactez le service commercial'],
            ],
            self::PASS_CADEAU => [
                'nom' => 'Pass cadeau', 'points_de_vente' => 5, 'utilisateurs' => 5, 'prix' => 0,
                'accroche' => self::DUREE_PASS_CADEAU_JOURS . ' jours gratuits pour tout essayer.',
                'details' => ['5 points de vente', '5 utilisateurs', 'Tout est activé', 'Gratuit pendant ' . self::DUREE_PASS_CADEAU_JOURS . ' jours, une seule fois'],
            ],
        ];
    }

    public static function existe(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::offres());
    }

    public static function libelle(?string $code): string
    {
        return self::offres()[$code]['nom'] ?? ($code === self::A_CHOISIR ? 'À choisir' : (string) $code);
    }

    public static function enChiffres(?int $valeur): string
    {
        if ($valeur === null) {
            return 'Sans limite';
        }

        return $valeur >= self::ILLIMITE ? 'Illimité' : (string) $valeur;
    }

    /**
     * Appliquer une offre à une entreprise : son nom, ses contraintes, son
     * statut. Le règlement n'est pas encaissé par Selflow : une offre payante
     * reste « à régler » jusqu'à ce que DC-Knowing la confirme.
     */
    public static function appliquer(Entreprise $entreprise, string $code): void
    {
        $offre = self::offres()[$code];

        $valeurs = [
            'plan_abonnement'       => $code,
            'abonnement_choisi_le'  => now(),
            'abonnement_demande'    => $code === self::PASS_CADEAU ? null : $code,
            'abonnement_demande_le' => $code === self::PASS_CADEAU ? null : now(),
        ];

        if ($code === self::SUR_MESURE) {
            // Le volume se fixe avec le service commercial ; d'ici là,
            // l'entreprise garde ce qu'elle a.
            $valeurs['abonnement_statut'] = self::STATUT_SUR_DEVIS;
        } else {
            $valeurs['quota_points_de_vente'] = $offre['points_de_vente'];
            $valeurs['quota_utilisateurs'] = $offre['utilisateurs'];
        }

        if ($code === self::PASS_CADEAU) {
            $valeurs['abonnement_statut'] = self::STATUT_ESSAI;
            $valeurs['abonnement_expire_le'] = now()->addDays(self::DUREE_PASS_CADEAU_JOURS);
            $valeurs['pass_cadeau_utilise_le'] = now();
        } elseif (in_array($code, [self::SOLO, self::BUSINESS, self::PREMIUM], true)) {
            // L'échéance ne bouge pas : seul le règlement la repousse
            // (DC-Knowing aujourd'hui, le paiement en ligne bientôt).
            $valeurs['abonnement_statut'] = self::STATUT_A_REGLER;
        }

        $entreprise->forceFill($valeurs)->save();

        // « Tout activé » : le pass ouvre chaque module.
        if ($code === self::PASS_CADEAU) {
            $entreprise->forceFill([
                'modules_actifs' => array_values(array_unique(array_merge(
                    (array) ($entreprise->modules_actifs ?? []),
                    ['principal', 'ventes', 'achats', 'stock', 'production', 'comptabilite', 'points_de_vente', 'personnel', 'b2b', 'fne']
                ))),
            ])->save();
        }
    }

    /** Rang des offres, pour savoir laquelle est « supérieure ». */
    public const RANG = [
        self::A_CHOISIR => 0, self::PASS_CADEAU => 0, self::SOLO => 1,
        self::BUSINESS => 2, self::PREMIUM => 3, self::SUR_MESURE => 4,
    ];

    /**
     * Ce que chaque offre apporte, carte par carte (15/10/2026).
     *
     * @return array<string, array<int, string>>
     */
    public static function avantages(): array
    {
        return [
            self::PASS_CADEAU => ['Tout Selflow pendant ' . self::DUREE_PASS_CADEAU_JOURS . ' jours', 'Sans engagement, sans carte', 'Vos données sont gardées à la fin de l\'essai'],
            self::SOLO => ['Caisse, factures et reçus', 'Stock et achats', 'Normalisation DGI (FNE)', 'Assistance par courriel'],
            self::BUSINESS => ['Tout Solo, pour une équipe', 'Habilitations par employé', 'Plusieurs boutiques, stock par site', 'B2B entre entreprises Selflow', 'Assistance prioritaire'],
            self::PREMIUM => ['Tout Business, sans limite', 'Sites et utilisateurs illimités', 'Production, consignations, immobilisations', 'Accompagnement à la mise en route'],
            self::SUR_MESURE => ['Volumes et modules à la demande', 'Intégrations spécifiques', 'Interlocuteur dédié'],
        ];
    }

    public static function estSuperieure(string $offre, ?string $actuelle): bool
    {
        return (self::RANG[$offre] ?? 0) > (self::RANG[$actuelle] ?? 0);
    }

    /**
     * L'abonnement est-il arrivé à échéance ? L'entreprise voit alors son
     * espace sans pouvoir rien y faire, jusqu'au renouvellement (15/10/2026).
     */
    public static function estATerme(?Entreprise $entreprise): bool
    {
        return $entreprise !== null
            && $entreprise->abonnement_expire_le !== null
            && now()->greaterThan($entreprise->abonnement_expire_le);
    }

    /**
     * Le paiement qui tient l'entreprise en attente — décision du propriétaire
     * du 22/10/2026, qui remplace l'accès immédiat du lot 102.
     *
     * Une offre choisie et payée par référence n'ouvre rien tant que
     * DC-Knowing n'a pas confirmé le règlement : l'entreprise voit son espace,
     * bloqué, avec « En attente de confirmation de l'abonnement ». Un paiement
     * en échec la laisse bloquée, avec le motif. Seul le dernier paiement
     * compte, et seulement s'il a été déclaré sans période payée en cours
     * (`bloquant`) : celle qui renouvelle avant l'échéance continue de
     * travailler.
     */
    public static function paiementEnSuspens(?Entreprise $entreprise): ?PaiementAbonnement
    {
        if ($entreprise === null || ! PaiementAbonnement::colonnesEnPlace()) {
            return null;
        }

        $dernier = PaiementAbonnement::where('entreprise_id', $entreprise->id)->latest('id')->first();

        return $dernier && $dernier->bloquant && in_array($dernier->statut, PaiementAbonnement::SUSPENDENT, true)
            ? $dernier
            : null;
    }

    /**
     * Une période payée court-elle ? Une offre payante (ou sur mesure) dont
     * l'échéance n'est pas passée — ou qui n'en a pas, comme les entreprises
     * d'avant les offres. Ni l'offre « à choisir » ni le pass cadeau n'en sont
     * une. Se lit avant d'appliquer la nouvelle offre.
     */
    public static function periodePayeeEnCours(Entreprise $entreprise): bool
    {
        return in_array($entreprise->plan_abonnement, [self::SOLO, self::BUSINESS, self::PREMIUM, self::SUR_MESURE], true)
            && ($entreprise->abonnement_expire_le === null || $entreprise->abonnement_expire_le->isFuture());
    }

    /** Le paiement confirmé que l'entreprise n'a pas encore vu annoncé. */
    public static function confirmationAAnnoncer(?Entreprise $entreprise): ?PaiementAbonnement
    {
        if ($entreprise === null || ! PaiementAbonnement::colonnesEnPlace()) {
            return null;
        }

        return PaiementAbonnement::where('entreprise_id', $entreprise->id)
            ->where('statut', 'active')->whereNull('confirmation_vue_le')
            ->latest('active_le')->first();
    }

    /** Jours avant l'échéance ; `null` sans échéance. */
    public static function joursRestants(Entreprise $entreprise): ?int
    {
        if ($entreprise->abonnement_expire_le === null) {
            return null;
        }

        return max(0, (int) ceil(now()->diffInHours($entreprise->abonnement_expire_le, false) / 24));
    }

    /** L'entreprise doit-elle encore choisir son offre ? */
    public static function aChoisir(Entreprise $entreprise): bool
    {
        return $entreprise->plan_abonnement === self::A_CHOISIR;
    }

    public static function passExpire(Entreprise $entreprise): bool
    {
        return $entreprise->plan_abonnement === self::PASS_CADEAU
            && $entreprise->abonnement_expire_le !== null
            && now()->greaterThan($entreprise->abonnement_expire_le);
    }

    /** Peut-on encore ajouter un utilisateur ? `null` : aucune limite. */
    public static function placesUtilisateursRestantes(Entreprise $entreprise): ?int
    {
        if ($entreprise->quota_utilisateurs === null) {
            return null;
        }

        return max(0, (int) $entreprise->quota_utilisateurs - $entreprise->utilisateurs()->count());
    }
}

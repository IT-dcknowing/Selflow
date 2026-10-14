<?php

namespace Database\Seeders;

use App\Modules\Admin\Modeles\VitrineCarte;
use App\Modules\Admin\Modeles\VitrineSection;
use Illuminate\Database\Seeder;

/**
 * La structure de la page d'accueil, et les textes dictés par le propriétaire.
 *
 * ## Ce que ce semeur pose, et ce qu'il ne pose pas
 *
 * Il pose **la charpente** : les sections, leur ordre, leur disposition, et
 * les textes que le propriétaire du projet a donnés — le nom des applications,
 * ce que fait chacune, le nom du développeur, celui du cabinet.
 *
 * Il ne pose **ni photo, ni vidéo, ni nom qu'on ne m'a pas donné**. Ces
 * champs restent vides et se remplissent depuis l'écran superadmin :
 * inventer le visage d'une personne réelle ou l'historique d'une entreprise
 * réelle mettrait en production une affirmation que personne n'a vérifiée.
 *
 * Certaines descriptions d'applications se réduisent à leur domaine — RHFlow
 * gère les ressources humaines — parce que c'est tout ce que le nom permet
 * d'affirmer. **Elles sont à relire et à compléter.**
 *
 * ## Il ne réécrit jamais
 *
 * Chaque bloc passe par `firstOrCreate` sur sa clé : relancer le semeur après
 * une saisie ne l'efface pas. C'est ce qui permet de le laisser dans
 * `DatabaseSeeder` sans risquer d'effacer le travail du superadministrateur.
 */
class VitrineSeeder extends Seeder
{
    public function run(): void
    {
        $this->ouverture();
        $this->laConformite();
        $this->leParcours();
        $this->lesProduits();
        $this->lEquipe();
        $this->leCabinet();
        $this->lesRessources();
    }

    /**
     * Poser une section si elle n'existe pas, et ses cartes de même.
     *
     * @param  array<int, array<string, mixed>>  $cartes
     */
    private function poser(array $section, array $cartes = []): void
    {
        $bloc = VitrineSection::firstOrCreate(['cle' => $section['cle']], $section);

        foreach ($cartes as $rang => $carte) {
            VitrineCarte::firstOrCreate(
                ['section_id' => $bloc->id, 'titre' => $carte['titre']],
                $carte + ['ordre' => ($rang + 1) * 10, 'publiee' => true]
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────

    private function ouverture(): void
    {
        $this->poser([
            'cle'        => 'accueil',
            'titre'      => 'La facture normalisée, sans y penser',
            'sous_titre' => 'Conforme à la FNE — DGI Côte d\'Ivoire',
            'texte'      => "Selflow tient vos ventes, vos achats, votre stock et votre comptabilité, "
                . "et transmet chaque pièce à la plateforme de la Direction Générale des Impôts. "
                . "Le code QR, le visuel FNE et la numérotation reviennent certifiés : vous imprimez, "
                . "c'est tout.",
            'gabarit'    => 'bandeau',
            'fond'       => 'sombre',
            'ordre'      => 10,
            'publiee'    => true,
            'action_libelle' => 'Créer un compte',
            'action_url'     => '/inscription',
        ], [
            [
                'titre'        => 'Voir la documentation',
                'lien_libelle' => 'Voir la documentation',
                'lien_url'     => '#documentation',
                'icone'        => 'fas fa-book-open',
            ],
        ]);
    }

    private function laConformite(): void
    {
        $this->poser([
            'cle'        => 'conformite',
            'titre'      => 'Ce que la normalisation change pour vous',
            'sous_titre' => 'La conformité',
            'texte'      => "La certification n'est pas une case à cocher de plus : c'est une "
                . "obligation dont la forme est fixée au caractère près. Selflow s'en charge.",
            'gabarit'    => 'colonnes',
            'fond'       => 'blanc',
            'ordre'      => 20,
            'publiee'    => true,
        ], [
            [
                'titre' => 'Le sticker électronique, en entier',
                'texte' => "Les trois éléments exigés par la DGI — le code QR de vérification, le "
                    . "visuel FNE et le format de la numérotation — sont portés par la facture comme "
                    . "par le reçu de caisse.",
                'icone' => 'fas fa-qrcode',
            ],
            [
                'titre' => 'Automatique ou à la main',
                'texte' => "Vos factures partent à la certification dès leur émission, ou attendent "
                    . "votre vérification. Deux réglages séparés : un pour les factures, un pour les "
                    . "reçus, parce que les deux usages le sont.",
                'icone' => 'fas fa-toggle-on',
            ],
            [
                'titre' => 'Le barème du timbre, tel qu\'il est écrit',
                'texte' => "Le timbre de quittance suit le barème forfaitaire par tranche de "
                    . "l'article 873 du Code général des impôts. Pas un taux approché.",
                'icone' => 'fas fa-stamp',
            ],
            [
                'titre' => 'Les codes de TVA de la plateforme',
                'texte' => "Chaque taux part sous le code que la DGI lui attribue. Une seconde liste "
                    . "recopiée aurait dérivé de la première, et la pièce certifiée aurait affiché un "
                    . "montant différent du vôtre.",
                'icone' => 'fas fa-percent',
            ],
            [
                'titre' => 'Le reçu de caisse aussi',
                'texte' => "Le reçu emprunte la même porte que la facture. Ce qui les distingue est "
                    . "le format d'impression : le rouleau de 80 mm porte le même sticker.",
                'icone' => 'fas fa-receipt',
            ],
            [
                'titre' => 'Le solde de vignettes sous les yeux',
                'texte' => "Le nombre de stickers restants est suivi, et une alerte prévient avant la "
                    . "rupture — une pièce qu'on ne peut plus certifier est une vente qu'on ne peut "
                    . "plus faire.",
                'icone' => 'fas fa-bell',
            ],
        ]);
    }

    private function leParcours(): void
    {
        $this->poser([
            'cle'        => 'parcours',
            'titre'      => 'De la vente au bilan, sans ressaisie',
            'sous_titre' => 'Le parcours',
            'texte'      => "Une vente enregistrée sort la marchandise du stock, écrit ses écritures "
                . "au plan SYSCOHADA révisé, alimente la trésorerie et part à la DGI. Une seule "
                . "saisie, et tout suit.",
            'gabarit'    => 'media',
            'fond'       => 'clair',
            'ordre'      => 30,
            'publiee'    => true,
            'media_type' => 'video',
            'media_legende' => 'Déposez ici la vidéo de démonstration depuis l\'écran superadmin.',
            'action_libelle' => 'Créer un compte',
            'action_url'     => '/inscription',
        ]);
    }

    private function lesProduits(): void
    {
        $this->poser([
            'cle'        => 'produits',
            'titre'      => 'Les applications DC-Knowing',
            'sous_titre' => 'Nos produits',
            'texte'      => "Chacune tient son métier, et elles se parlent entre elles.",
            'gabarit'    => 'produits',
            'fond'       => 'blanc',
            'ordre'      => 40,
            'publiee'    => true,
        ], [
            [
                'titre' => 'Selflow',
                'role'  => 'Gestion commerciale',
                'texte' => "Ventes, achats, stock, points de vente, immobilisations, et la facture "
                    . "normalisée transmise à la DGI. Selflow déverse ses écritures dans Comptaflow : "
                    . "une vente saisie ici arrive là-bas, avec son compte, son journal et son tiers.",
                'icone' => 'fas fa-cart-shopping',
                'lien_libelle' => 'Ouvrir Selflow',
                'lien_url'     => 'https://selflow.dc-knowing.com/',
            ],
            [
                'titre' => 'Comptaflow',
                'role'  => 'Comptabilité',
                'texte' => "Le plan comptable SYSCOHADA, les journaux, le grand livre, la balance et "
                    . "les états financiers. Il reçoit les écritures de Selflow et garde la main sur "
                    . "sa propre configuration — c'est lui qui fait foi en comptabilité.",
                'icone' => 'fas fa-book',
                'lien_libelle' => 'Ouvrir Comptaflow',
                'lien_url'     => 'https://comptaflow.dc-knowing.com/',
            ],
            [
                'titre' => 'RHFlow',
                'role'  => 'Ressources humaines',
                'texte' => "La gestion des ressources humaines.",
                'icone' => 'fas fa-users',
            ],
            [
                'titre' => 'LegalFlow',
                'role'  => 'Juridique',
                'texte' => "La gestion juridique.",
                'icone' => 'fas fa-scale-balanced',
            ],
            [
                'titre' => 'Agent-AI',
                'role'  => 'Intelligence artificielle',
                'texte' => "L'assistance par intelligence artificielle.",
                'icone' => 'fas fa-robot',
            ],
            [
                'titre' => 'CGA-Connect',
                'role'  => 'Centres de gestion agréés',
                'texte' => "Une application multitenant à plusieurs niveaux, qui réunit les Centres de "
                    . "Gestion Agréés. Chaque CGA y enregistre ses clients et ses adhérents ; la "
                    . "Direction dispose, au-dessus, de la vue d'ensemble sur tous les CGA de Côte "
                    . "d'Ivoire.",
                'icone' => 'fas fa-sitemap',
            ],
        ]);
    }

    private function lEquipe(): void
    {
        $this->poser([
            'cle'        => 'equipe',
            'titre'      => 'Ceux qui construisent',
            'sous_titre' => 'L\'équipe',
            'texte'      => "Les autres membres seront présentés ici.",
            'gabarit'    => 'equipe',
            'fond'       => 'clair',
            'ordre'      => 50,
            'publiee'    => true,
        ], [
            [
                'titre' => 'Agnimel Meledje Abraham',
                'role'  => 'Informaticien développeur',
                'texte' => "Conçoit Selflow et Comptaflow, et conduit CGA-Connect. Maîtrise plusieurs "
                    . "outils d'intelligence artificielle.",
            ],
        ]);
    }

    private function leCabinet(): void
    {
        // Le texte de présentation du cabinet n'est pas écrit ici : il engage
        // une entreprise réelle et une personne réelle. Le titre et la fiche du
        // dirigeant viennent du propriétaire du projet ; le reste se saisit
        // depuis l'écran superadmin.
        $this->poser([
            'cle'        => 'cabinet',
            'titre'      => 'DC-Knowing',
            'sous_titre' => 'Le cabinet',
            'texte'      => "Cabinet comptable.",
            'gabarit'    => 'equipe',
            'fond'       => 'sombre',
            'ordre'      => 60,
            'publiee'    => true,
        ], [
            [
                'titre' => 'M. Keyman Constant',
                'role'  => 'Directeur général',
            ],
        ]);
    }

    private function lesRessources(): void
    {
        $this->poser([
            'cle'        => 'documentation',
            'titre'      => 'Documentation et politique',
            'sous_titre' => 'Pour aller plus loin',
            'texte'      => "Comment l'application s'utilise, et ce que nous faisons de vos données.",
            'gabarit'    => 'liste',
            'fond'       => 'blanc',
            'ordre'      => 70,
            'publiee'    => true,
        ], [
            [
                'titre'        => 'Documentation',
                'texte'        => "Le guide d'utilisation, écran par écran.",
                'icone'        => 'fas fa-book-open',
                'lien_libelle' => 'Ouvrir la documentation',
                // L'adresse reste à poser : elle sera renseignée depuis l'écran
                // superadmin le jour où la documentation sera en ligne.
                'lien_url'     => null,
            ],
            [
                'titre'        => 'Politique de Selflow',
                'texte'        => "Conditions d'utilisation, traitement des données, conservation des "
                    . "pièces certifiées.",
                'icone'        => 'fas fa-shield-halved',
                'lien_libelle' => 'Lire la politique',
                'lien_url'     => null,
            ],
        ]);
    }
}

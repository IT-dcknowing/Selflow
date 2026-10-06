<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Pagination\Paginator::useBootstrapFive();

        // Une relation lue sur un modèle d'une collection se charge pour toute
        // la collection, en une requête. Mesuré le 06/10/2026 (lot 43) : la
        // caisse lisait la catégorie et le stock de chaque carte un par un —
        // 20 020 requêtes et 14 secondes pour ouvrir « Nouvelle vente ». Les
        // écrans en cartes et en tableaux en sont pleins, et un `with()` oublié
        // sur le prochain suffirait à recommencer : la règle est posée une fois.
        \Illuminate\Database\Eloquent\Model::automaticallyEagerLoadRelationships();

        $this->declarerLAffichageDesQuantites();
    }

    /**
     * `@qte(...)` — l'affichage d'une quantité physique.
     *
     * Depuis que les quantités sont décimales, `{{ $m->quantite }}` rend
     * « 12.5 » : un point décimal là où l'on écrit une virgule, et « 3 » qui
     * devient « 3.0 » dès qu'un autre article du tableau porte une fraction.
     * La directive coupe les zéros inutiles et pose la virgule : « 12,5 »,
     * « 3 », « 0,125 ».
     *
     * Une directive plutôt qu'une fonction d'aide : elle n'a de sens que dans
     * une vue, et le projet n'a pas de fichier de fonctions globales à charger
     * pour une seule.
     */
    private function declarerLAffichageDesQuantites(): void
    {
        \Illuminate\Support\Facades\Blade::directive('qte', function (string $expression) {
            return "<?php echo \\App\\Providers\\AppServiceProvider::quantite({$expression}); ?>";
        });
    }

    /**
     * Formatage d'une quantité : trois décimales au plus, zéros de fin coupés,
     * virgule décimale, espace insécable comme séparateur de milliers.
     */
    public static function quantite($valeur): string
    {
        $decimales = \App\Modules\Admin\Modeles\Stock::DECIMALES;
        $texte = number_format(round((float) $valeur, $decimales), $decimales, ',', ' ');

        return str_contains($texte, ',') ? rtrim(rtrim($texte, '0'), ',') : $texte;
    }
}

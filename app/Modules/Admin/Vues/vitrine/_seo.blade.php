{{--
    Référencement des pages publiques (23/11/2026).
    Variables : $titre, $description, $chemin (ex. '/guide'), $jsonld (bool, page d'accueil).
    L'adresse canonique est celle de Selflow (APP_URL), jamais celle d'un espace sur mesure.
--}}
@php
    $base = rtrim((string) config('app.url'), '/') ?: 'https://selflow.dc-knowing.com';
    $canonique = $base . ($chemin ?? '/');
    $image = $base . '/images/selflow/favicon-192.png';
@endphp
<meta name="description" content="{{ $description }}">
<meta name="keywords" content="logiciel de facturation Côte d'Ivoire, facture normalisée électronique, FNE DGI, facture normalisée Côte d'Ivoire, logiciel de gestion commerciale Abidjan, logiciel de caisse Côte d'Ivoire, gestion de stock, timbre de quittance, reçu normalisé, logiciel comptable SYSCOHADA, Selflow, DC-Knowing">
<meta name="robots" content="index, follow, max-image-preview:large">
<meta name="author" content="DC-Knowing">
<meta name="geo.region" content="CI">
<meta name="geo.placename" content="Abidjan">
<meta name="theme-color" content="#062456">
<link rel="canonical" href="{{ $canonique }}">
<link rel="alternate" hreflang="fr-CI" href="{{ $canonique }}">
<link rel="apple-touch-icon" href="{{ asset('images/selflow/apple-touch-icon.png') }}">
<meta property="og:type" content="website">
<meta property="og:locale" content="fr_CI">
<meta property="og:site_name" content="Selflow">
<meta property="og:title" content="{{ $titre }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ $canonique }}">
<meta property="og:image" content="{{ $image }}">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="{{ $titre }}">
<meta name="twitter:description" content="{{ $description }}">
<meta name="twitter:image" content="{{ $image }}">
@if(!empty($jsonld))
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'Organization',
            '@id' => $base . '/#organisation',
            'name' => 'DC-Knowing',
            'url' => 'https://dc-knowing.com',
            'logo' => $image,
            'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Abidjan', 'addressCountry' => 'CI'],
            'contactPoint' => ['@type' => 'ContactPoint', 'telephone' => '+225 ' . config('selflow.assistance_telephone'), 'contactType' => 'customer support', 'areaServed' => 'CI', 'availableLanguage' => 'French'],
        ],
        [
            '@type' => 'SoftwareApplication',
            'name' => 'Selflow',
            'url' => $base . '/',
            'applicationCategory' => 'BusinessApplication',
            'applicationSubCategory' => 'Logiciel de facturation et de gestion commerciale',
            'operatingSystem' => 'Web, Android, iOS',
            'inLanguage' => 'fr',
            'areaServed' => ['@type' => 'Country', 'name' => "Côte d'Ivoire"],
            'description' => $description,
            'publisher' => ['@id' => $base . '/#organisation'],
            'offers' => collect(\App\Modules\Admin\Regles\Abonnements::offres())
                ->filter(fn ($o) => !empty($o['prix']))
                ->map(fn ($o) => ['@type' => 'Offer', 'name' => $o['nom'], 'price' => (string) $o['prix'], 'priceCurrency' => 'XOF'])
                ->values()->all(),
            'featureList' => [
                'Facture normalisée électronique (FNE) certifiée par la DGI de Côte d\'Ivoire',
                'Code QR et numéro FNE sur chaque facture',
                'Timbre de quittance selon l\'article 873 du CGI',
                'Ventes, devis, bons de commande, avoirs',
                'Achats et factures fournisseurs',
                'Stock par point de vente et inventaires',
                'Trésorerie et comptabilité SYSCOHADA',
            ],
        ],
        [
            '@type' => 'FAQPage',
            'mainEntity' => [
                ['@type' => 'Question', 'name' => "Qu'est-ce que la facture normalisée électronique (FNE) ?",
                 'acceptedAnswer' => ['@type' => 'Answer', 'text' => "La FNE est la facture certifiée par la plateforme de la Direction Générale des Impôts de Côte d'Ivoire. Elle porte un numéro et un code QR de vérification. Selflow transmet chaque facture à la plateforme et la reçoit certifiée."]],
                ['@type' => 'Question', 'name' => 'Selflow convient-il à une boutique, un salon ou un cabinet ?',
                 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Oui. Selflow gère les ventes de marchandises comme les prestations de services, sur un ou plusieurs points de vente, avec leur stock, leur caisse et leur comptabilité.']],
                ['@type' => 'Question', 'name' => 'Faut-il installer un logiciel ?',
                 'acceptedAnswer' => ['@type' => 'Answer', 'text' => "Non. Selflow s'utilise dans le navigateur, sur ordinateur, tablette ou téléphone."]],
            ],
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
</script>
@endif

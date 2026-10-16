{{--
    Les offres d'abonnement, en cartes — décision du propriétaire du 15/10/2026.

    Une seule page pour deux moments : le visiteur qui va s'inscrire (les cartes
    mènent à l'inscription), et l'entreprise qui vient de s'inscrire (les cartes
    retiennent son offre).
--}}
@php
    use App\Modules\Admin\Regles\Abonnements;
    $passDejaPris = $entreprise?->pass_cadeau_utilise_le !== null;
    $actuelle = $entreprise?->plan_abonnement;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nos offres — Selflow</title>
    @include('partials.icones-selflow')
    <link rel="stylesheet" href="{{ asset('vendor/inter/inter.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #F4F6F9; color: #1E293B; min-height: 100vh; }
        .entete { background: linear-gradient(160deg, #002B5C 0%, #00408A 55%, #0056B8 100%); color: #fff; padding: 42px 20px 96px; text-align: center; }
        .entete h1 { font-size: 30px; font-weight: 800; letter-spacing: -.01em; }
        .entete p { margin-top: 10px; color: rgba(255,255,255,.82); font-size: 15px; }
        .marque { display: inline-flex; align-items: center; gap: 10px; font-weight: 800; font-size: 18px; margin-bottom: 18px; }
        .cartes { max-width: 1240px; margin: -64px auto 0; padding: 0 16px 40px; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .carte { background: #fff; border: 1px solid #E2E8F0; border-radius: 16px; padding: 24px 20px; display: flex; flex-direction: column; box-shadow: 0 10px 30px rgba(0,0,0,.06); position: relative; }
        .carte.vedette { border: 2px solid #002B5C; }
        .carte.cadeau { border: 2px dashed #f59e0b; background: #FFFBEB; }
        .carte.actuelle { box-shadow: 0 0 0 3px #22c55e inset, 0 10px 30px rgba(0,0,0,.06); }
        .ruban { position: absolute; top: -11px; left: 50%; transform: translateX(-50%); background: #002B5C; color: #fff; font-size: 11px; font-weight: 700; padding: 3px 12px; border-radius: 20px; white-space: nowrap; }
        .cadeau .ruban { background: #f59e0b; }
        .carte h2 { font-size: 13px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: #002B5C; }
        .prix { margin: 14px 0 4px; font-size: 30px; font-weight: 800; }
        .prix small { font-size: 13px; font-weight: 600; color: #64748B; }
        .accroche { font-size: 13px; color: #64748B; min-height: 38px; }
        .limites { display: flex; gap: 8px; margin: 14px 0; }
        .limites span { flex: 1; text-align: center; background: #F1F5F9; border-radius: 10px; padding: 8px 4px; font-size: 11px; color: #475569; }
        .limites strong { display: block; font-size: 16px; color: #1E293B; }
        .details { list-style: none; margin: 4px 0 18px; font-size: 13px; line-height: 1.9; color: #334155; flex: 1; }
        .details i { color: #22c55e; margin-right: 6px; }
        .bouton { display: flex; justify-content: center; align-items: center; gap: 8px; padding: 12px; border-radius: 10px; font-weight: 700; font-size: 14px; text-decoration: none; cursor: pointer; border: 1px solid #002B5C; background: #002B5C; color: #fff; width: 100%; font-family: inherit; }
        .bouton.secondaire { background: #fff; color: #002B5C; }
        .bouton:disabled { opacity: .5; cursor: not-allowed; }
        .contact { font-size: 12px; color: #475569; margin-top: 10px; line-height: 1.7; }
        .contact a { color: #002B5C; font-weight: 600; }
        .pied { text-align: center; padding: 0 16px 40px; font-size: 13px; color: #64748B; }
        .pied a { color: #002B5C; font-weight: 600; }
        .alerte { max-width: 1240px; margin: 0 auto 16px; padding: 12px 16px; border-radius: 10px; background: #FEE2E2; color: #991B1B; font-size: 13px; }
    </style>
</head>
<body>
    <header class="entete">
        <div class="marque"><i class="fas fa-store"></i> Selflow</div>
        <h1>{{ $entreprise ? 'Choisissez votre offre' : 'Nos offres' }}</h1>
        <p>{{ $entreprise ? 'Votre espace est prêt. Retenez l\'offre qui vous convient — elle évolue avec vous.' : 'Ventes, achats, stock, facturation et normalisation DGI. Commencez gratuitement.' }}</p>
    </header>

    @if(session('erreur') || $errors->any())
        <div class="alerte" style="margin-top:-50px; position:relative;">{{ session('erreur') ?? $errors->first() }}</div>
    @endif

    <main class="cartes">
        @foreach([Abonnements::PASS_CADEAU, Abonnements::SOLO, Abonnements::BUSINESS, Abonnements::PREMIUM, Abonnements::SUR_MESURE] as $code)
            @php $offre = $offres[$code]; @endphp
            <section class="carte {{ $code === Abonnements::BUSINESS ? 'vedette' : '' }} {{ $code === Abonnements::PASS_CADEAU ? 'cadeau' : '' }} {{ $actuelle === $code ? 'actuelle' : '' }}" data-offre="{{ $code }}">
                @if($code === Abonnements::BUSINESS)<span class="ruban">Le plus choisi</span>@endif
                @if($code === Abonnements::PASS_CADEAU)<span class="ruban">{{ Abonnements::DUREE_PASS_CADEAU_JOURS }} jours offerts</span>@endif
                <h2>{{ $offre['nom'] }}</h2>
                <div class="prix">
                    @if($offre['prix'] === null)
                        Sur devis
                    @elseif($offre['prix'] === 0)
                        Gratuit
                    @else
                        {{ number_format($offre['prix'], 0, ',', ' ') }} F <small>/ mois</small>
                    @endif
                </div>
                <p class="accroche">{{ $offre['accroche'] }}</p>
                @if($offre['points_de_vente'] !== null)
                    <div class="limites">
                        <span><strong>{{ Abonnements::enChiffres($offre['points_de_vente']) }}</strong>point{{ $offre['points_de_vente'] > 1 ? 's' : '' }} de vente</span>
                        <span><strong>{{ Abonnements::enChiffres($offre['utilisateurs']) }}</strong>utilisateur{{ $offre['utilisateurs'] > 1 ? 's' : '' }}</span>
                    </div>
                @endif
                <ul class="details">
                    @foreach($offre['details'] as $detail)
                        <li><i class="fas fa-check"></i>{{ $detail }}</li>
                    @endforeach
                </ul>

                @if($entreprise)
                    <form method="POST" action="{{ route('admin.abonnement.enregistrer') }}">
                        @csrf
                        <input type="hidden" name="offre" value="{{ $code }}">
                        @if($actuelle === $code)
                            <button type="button" class="bouton secondaire" disabled><i class="fas fa-circle-check"></i> Votre offre actuelle</button>
                        @elseif($code === Abonnements::PASS_CADEAU && $passDejaPris)
                            <button type="button" class="bouton secondaire" disabled>Déjà utilisé</button>
                        @elseif(($offre['prix'] ?? 0) > 0)
                            {{-- Offre payante : le paiement par référence s'ouvre sous la carte. --}}
                            <button type="button" class="bouton" onclick="var c = this.nextElementSibling; c.style.display = c.style.display === 'none' ? 'block' : 'none';">
                                Choisir {{ $offre['nom'] }}
                            </button>
                            @include('admin::abonnements.partials.champs-paiement', ['montant' => $offre['prix']])
                        @else
                            <button type="submit" class="bouton {{ $code === Abonnements::SUR_MESURE ? 'secondaire' : '' }}">
                                {{ $code === Abonnements::SUR_MESURE ? 'Être contacté' : 'Activer mon pass' }}
                            </button>
                        @endif
                    </form>
                @else
                    <a href="{{ route('inscription') }}" class="bouton {{ $code === Abonnements::SUR_MESURE ? 'secondaire' : '' }}">
                        {{ $code === Abonnements::PASS_CADEAU ? 'Commencer gratuitement' : 'Commencer' }}
                    </a>
                @endif

                @if($code === Abonnements::SUR_MESURE && ($contact['email'] || $contact['telephone']))
                    <p class="contact">
                        @if($contact['email'])<i class="fas fa-envelope"></i> <a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a><br>@endif
                        @if($contact['telephone'])<i class="fas fa-phone"></i> <a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact['telephone']) }}">{{ $contact['telephone'] }}</a>@endif
                    </p>
                @endif
            </section>
        @endforeach
    </main>

    <p class="pied">
        @if($entreprise)
            <a href="{{ route('admin.tableau_de_bord') }}">Décider plus tard</a> — votre espace reste ouvert ; l'offre se choisit aussi depuis le tableau de bord.
        @else
            Déjà un compte ? <a href="{{ route('connexion') }}">Se connecter</a>
        @endif
    </p>
</body>
</html>
